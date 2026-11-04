<?php
// Facturas hechas en la app web: se emiten en la nube aunque la computadora esté apagada.
// La nube asigna el número (con un punto de emisión propio de la web), la clave de acceso, el XML y el RIDE.
// Lo único que no hace es firmar: la firma electrónica nunca sale del equipo. Cuando el programa se enciende,
// toma la factura, la vuelve a armar con sus propios datos, la firma, la envía al SRI y devuelve la autorización.
// Los cálculos y el XML son una copia exacta de desktop/src/sri.js (las pruebas comparan ambos resultados).
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

const EMI_EQUIPO_WEB = 'WEB';
const EMI_TIPO_ID = ['RUC' => '04', 'CEDULA' => '05', 'PASAPORTE' => '06', 'CF' => '07', 'EXTERIOR' => '08'];
const EMI_RIMPE = ['RIMPE EMPRENDEDOR' => 'CONTRIBUYENTE RÉGIMEN RIMPE', 'RIMPE NEGOCIO POPULAR' => 'CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE'];
const EMI_LIMITE_HORA = 300; // facturas web por hora y licencia (freno ante abusos o errores en bucle)

// ---------- Redondeo idéntico a JavaScript (Math.round con EPSILON) ----------
function emi_r2(float $n): float { return floor(($n + PHP_FLOAT_EPSILON) * 100 + 0.5) / 100; }
function emi_r6(float $n): float { return floor(($n + PHP_FLOAT_EPSILON) * 1e6 + 0.5) / 1e6; }
function emi_f2($n): string { return number_format(emi_r2((float)$n), 2, '.', ''); }
function emi_f6($n): string { return number_format(emi_r6((float)$n), 6, '.', ''); }
function emi_num($n): string { // String(número) de JavaScript para porcentajes (15, 12.5)
  $v = (float)$n;
  return $v == floor($v) ? (string)(int)$v : rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
}

// ---------- Totales (copia de sri.calcularTotales) ----------
function emi_totales(array $items, array $tarifas): array {
  $lineas = [];
  foreach ($items as $it) {
    $cantidad = emi_r6((float)($it['cantidad'] ?? 0));
    $precio = emi_r6((float)($it['precioUnitario'] ?? 0));
    $descPct = (float)($it['descuentoPct'] ?? 0);
    $tar = (string)($it['tarifa'] ?? '');
    if (!isset($tarifas[$tar])) throw new WebError('La tarifa de IVA "' . $tar . '" no está habilitada.');
    $bruto = $cantidad * $precio;
    $descuento = emi_r2($bruto * $descPct / 100);
    $base = emi_r2($bruto - $descuento);
    $pct = (float)$tarifas[$tar]['pct'];
    $lineas[] = array_merge($it, ['cantidad' => $cantidad, 'precioUnitario' => $precio, 'descuentoPct' => $descPct, 'tarifa' => $tar, 'pct' => $pct, 'descuento' => $descuento, 'base' => $base, 'iva' => emi_r2($base * $pct / 100)]);
  }
  $porTarifa = [];
  foreach ($lineas as $l) $porTarifa[$l['tarifa']] = emi_r2(($porTarifa[$l['tarifa']] ?? 0) + $l['base']);
  $codigos = array_map('strval', array_keys($porTarifa));
  usort($codigos, fn($a, $b) => (float)$a <=> (float)$b);
  $impuestos = array_map(fn($c) => ['codigoPorcentaje' => $c, 'tarifa' => (float)$tarifas[$c]['pct'], 'base' => $porTarifa[$c], 'valor' => emi_r2($porTarifa[$c] * (float)$tarifas[$c]['pct'] / 100)], $codigos);
  $sin = emi_r2(array_sum(array_column($lineas, 'base')));
  $desc = emi_r2(array_sum(array_column($lineas, 'descuento')));
  $iva = emi_r2(array_sum(array_column($impuestos, 'valor')));
  return ['lineas' => $lineas, 'impuestos' => $impuestos, 'porTarifa' => $porTarifa, 'totalSinImpuestos' => $sin, 'totalDescuento' => $desc, 'totalIva' => $iva, 'importeTotal' => emi_r2($sin + $iva)];
}

// ---------- Clave de acceso de 49 dígitos ----------
function emi_digito_clave(string $base48): int {
  $suma = 0; $peso = 2;
  for ($i = strlen($base48) - 1; $i >= 0; $i--) { $suma += (int)$base48[$i] * $peso; $peso = $peso === 7 ? 2 : $peso + 1; }
  $dv = 11 - ($suma % 11);
  return $dv === 11 ? 0 : ($dv === 10 ? 1 : $dv);
}
function emi_clave(string $fechaIso, string $codDoc, string $ruc, string $ambiente, string $estab, string $pto, int $secuencial, string $codigoNumerico): string {
  [$y, $m, $d] = explode('-', $fechaIso);
  $base = $d . $m . $y . $codDoc . $ruc . $ambiente . $estab . $pto . str_pad((string)$secuencial, 9, '0', STR_PAD_LEFT) . $codigoNumerico . '1';
  if (!preg_match('/^\d{48}$/', $base)) throw new WebError('No se pudo formar la clave de acceso: revisa el RUC y el punto de emisión de la empresa.');
  return $base . emi_digito_clave($base);
}

// ---------- XML de factura (copia de sri.construirFacturaXml, versión 1.1.0) ----------
function emi_limpiar($s): string {
  $s = preg_replace('/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}]/u', '', (string)($s ?? ''));
  return trim(preg_replace('/\r\n?|\n|\t/', ' ', (string)$s));
}
function emi_esc($s): string { return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], emi_limpiar($s)); }
function emi_esc_attr($s): string { return str_replace(['&', '<', '"'], ['&amp;', '&lt;', '&quot;'], emi_limpiar($s)); }

function emi_factura_xml(array $e, string $fechaIso, int $secuencial, string $clave, array $cli, array $tot, array $pago, array $campos): string {
  $L = [];
  $tag = function (string $n, $v, string $ind = '    ') use (&$L) { $L[] = $ind . '<' . $n . '>' . emi_esc($v) . '</' . $n . '>'; };
  [$y, $m, $d] = explode('-', $fechaIso);
  $L[] = '<?xml version="1.0" encoding="UTF-8"?>';
  $L[] = '<factura id="comprobante" version="1.1.0">';
  $L[] = '  <infoTributaria>';
  $tag('ambiente', $e['ambiente']);
  $tag('tipoEmision', '1');
  $tag('razonSocial', $e['razonSocial']);
  if (!empty($e['nombreComercial'])) $tag('nombreComercial', $e['nombreComercial']);
  $tag('ruc', $e['ruc']);
  $tag('claveAcceso', $clave);
  $tag('codDoc', '01');
  $tag('estab', $e['estab']);
  $tag('ptoEmi', $e['ptoEmi']);
  $tag('secuencial', str_pad((string)$secuencial, 9, '0', STR_PAD_LEFT));
  $tag('dirMatriz', $e['dirMatriz']);
  if (!empty($e['agenteRetencion'])) $tag('agenteRetencion', $e['agenteRetencion']);
  if (isset(EMI_RIMPE[$e['regimen'] ?? ''])) $tag('contribuyenteRimpe', EMI_RIMPE[$e['regimen']]);
  $L[] = '  </infoTributaria>';
  $L[] = '  <infoFactura>';
  $tag('fechaEmision', $d . '/' . $m . '/' . $y);
  $tag('dirEstablecimiento', ($e['dirEstablecimiento'] ?? '') ?: $e['dirMatriz']);
  if (!empty($e['contribuyenteEspecial'])) $tag('contribuyenteEspecial', $e['contribuyenteEspecial']);
  $tag('obligadoContabilidad', ($e['obligadoContabilidad'] ?? '') === 'SI' ? 'SI' : 'NO');
  $tag('tipoIdentificacionComprador', EMI_TIPO_ID[$cli['tipo']]);
  $tag('razonSocialComprador', $cli['nombre']);
  $tag('identificacionComprador', $cli['identificacion']);
  if ($cli['direccion'] !== '') $tag('direccionComprador', $cli['direccion']);
  $tag('totalSinImpuestos', emi_f2($tot['totalSinImpuestos']));
  $tag('totalDescuento', emi_f2($tot['totalDescuento']));
  $L[] = '    <totalConImpuestos>';
  foreach ($tot['impuestos'] as $i) {
    $L[] = '      <totalImpuesto>';
    $tag('codigo', '2', '        ');
    $tag('codigoPorcentaje', $i['codigoPorcentaje'], '        ');
    $tag('baseImponible', emi_f2($i['base']), '        ');
    $tag('valor', emi_f2($i['valor']), '        ');
    $L[] = '      </totalImpuesto>';
  }
  $L[] = '    </totalConImpuestos>';
  $tag('propina', '0.00');
  $tag('importeTotal', emi_f2($tot['importeTotal']));
  $tag('moneda', 'DOLAR');
  $L[] = '    <pagos>';
  $L[] = '      <pago>';
  $tag('formaPago', $pago['formaPago'], '        ');
  $tag('total', emi_f2($tot['importeTotal']), '        ');
  if ((int)$pago['plazo'] > 0) { $tag('plazo', (string)(int)$pago['plazo'], '        '); $tag('unidadTiempo', 'dias', '        '); }
  $L[] = '      </pago>';
  $L[] = '    </pagos>';
  $L[] = '  </infoFactura>';
  $L[] = '  <detalles>';
  foreach ($tot['lineas'] as $l) {
    $L[] = '    <detalle>';
    $tag('codigoPrincipal', ($l['codigo'] ?? '') !== '' ? $l['codigo'] : 'S/C', '      ');
    $tag('descripcion', $l['descripcion'], '      ');
    $tag('cantidad', emi_f6($l['cantidad']), '      ');
    $tag('precioUnitario', emi_f6($l['precioUnitario']), '      ');
    $tag('descuento', emi_f2($l['descuento']), '      ');
    $tag('precioTotalSinImpuesto', emi_f2($l['base']), '      ');
    $L[] = '      <impuestos>';
    $L[] = '        <impuesto>';
    $tag('codigo', '2', '          ');
    $tag('codigoPorcentaje', $l['tarifa'], '          ');
    $tag('tarifa', emi_num($l['pct']), '          ');
    $tag('baseImponible', emi_f2($l['base']), '          ');
    $tag('valor', emi_f2($l['iva']), '          ');
    $L[] = '        </impuesto>';
    $L[] = '      </impuestos>';
    $L[] = '    </detalle>';
  }
  $L[] = '  </detalles>';
  $campos = array_values(array_filter($campos, fn($c) => trim((string)($c['valor'] ?? '')) !== ''));
  if ($campos) {
    $L[] = '  <infoAdicional>';
    foreach ($campos as $c) $L[] = '    <campoAdicional nombre="' . emi_esc_attr($c['nombre']) . '">' . emi_esc(mb_substr((string)$c['valor'], 0, 300)) . '</campoAdicional>';
    $L[] = '  </infoAdicional>';
  }
  $L[] = '</factura>';
  return implode("\n", $L);
}

// ---------- Punto de emisión de la web ----------
// La web tiene su propio establecimiento-punto (por ejemplo 001-002), apartado en nube_series como si fuera un equipo
// más. Ningún programa puede usarlo, así los números de la web nunca chocan con los de las computadoras.
function emi_serie_web(array $ctx): string {
  $lic = $ctx['lic']; $perfil = $ctx['perfil'];
  $q = db()->prepare('SELECT serie FROM nube_series WHERE licencia_id = ? AND perfil = ? AND equipo = ?');
  $q->execute([$lic, $perfil, EMI_EQUIPO_WEB]);
  $s = $q->fetchColumn();
  if ($s) return (string)$s;
  $estab = preg_match('/^\d{3}$/', (string)($ctx['empresa']['estab'] ?? '')) ? $ctx['empresa']['estab'] : '001';
  for ($intento = 0; $intento < 3; $intento++) {
    $ocupadas = [];
    $q = db()->prepare('SELECT serie FROM nube_series WHERE licencia_id = ? AND perfil = ?');
    $q->execute([$lic, $perfil]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $ocupadas[$x] = true;
    // También las series que ya aparecen en comprobantes guardados (de equipos liberados o de antes de la nube).
    $q = db()->prepare("SELECT DISTINCT SUBSTR(numero, 1, 7) FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'comprobantes'");
    $q->execute([$lic, $perfil]);
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $x) $ocupadas[$x] = true;
    if (!empty($ctx['empresa']['siguienteFactura'])) $ocupadas[substr((string)$ctx['empresa']['siguienteFactura'], 0, 7)] = true;
    $ocupadas[$estab . '-' . ($ctx['empresa']['ptoEmi'] ?? '001')] = true;
    $serie = '';
    for ($i = 2; $i < 1000; $i++) { $c = $estab . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT); if (!isset($ocupadas[$c])) { $serie = $c; break; } }
    if ($serie === '') throw new WebError('No hay un punto de emisión libre para la app web.');
    try {
      db()->prepare('INSERT INTO nube_series (licencia_id, perfil, serie, equipo, nombre, desde, ultimo_uso) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$lic, $perfil, $serie, EMI_EQUIPO_WEB, 'App web', ahora(), ahora()]);
      registrar_evento($lic, 'SERIE_WEB', 'La app web usa el punto de emisión ' . $serie . ' del RUC ' . $perfil);
      return $serie;
    } catch (PDOException $e) { /* otra petición la tomó al mismo tiempo: se vuelve a leer */ }
    $q = db()->prepare('SELECT serie FROM nube_series WHERE licencia_id = ? AND perfil = ? AND equipo = ?');
    $q->execute([$lic, $perfil, EMI_EQUIPO_WEB]);
    if ($s = $q->fetchColumn()) return (string)$s;
  }
  throw new WebError('No se pudo apartar el punto de emisión de la web. Intenta de nuevo.');
}

function emi_esquema(): void {
  static $listo = false;
  if ($listo) return;
  if (fx_config()['db']['driver'] === 'sqlite') {
    db()->exec('CREATE TABLE IF NOT EXISTS nube_secuencias (licencia_id INTEGER NOT NULL, perfil TEXT NOT NULL, serie TEXT NOT NULL, cod_doc TEXT NOT NULL, ultimo INTEGER NOT NULL, PRIMARY KEY (licencia_id, perfil, serie, cod_doc))');
  } else {
    db()->exec('CREATE TABLE IF NOT EXISTS nube_secuencias (licencia_id INT UNSIGNED NOT NULL, perfil CHAR(13) NOT NULL, serie CHAR(7) NOT NULL, cod_doc CHAR(2) NOT NULL, ultimo INT UNSIGNED NOT NULL, PRIMARY KEY (licencia_id, perfil, serie, cod_doc)) ENGINE=InnoDB');
  }
  $listo = true;
}

// Siguiente secuencial de la serie web. Debe llamarse dentro de una transacción: la fila queda bloqueada
// hasta guardar la factura, así dos ventas simultáneas nunca reciben el mismo número.
function emi_tomar_secuencial(array $ctx, string $serie, string $codDoc): int {
  emi_esquema();
  $mysql = fx_config()['db']['driver'] !== 'sqlite';
  $d = db();
  if (!$mysql) $d->prepare('INSERT OR IGNORE INTO nube_secuencias (licencia_id, perfil, serie, cod_doc, ultimo) VALUES (?, ?, ?, ?, 0)')->execute([$ctx['lic'], $ctx['perfil'], $serie, $codDoc]);
  else $d->prepare('INSERT IGNORE INTO nube_secuencias (licencia_id, perfil, serie, cod_doc, ultimo) VALUES (?, ?, ?, ?, 0)')->execute([$ctx['lic'], $ctx['perfil'], $serie, $codDoc]);
  $q = $d->prepare('SELECT ultimo FROM nube_secuencias WHERE licencia_id = ? AND perfil = ? AND serie = ? AND cod_doc = ?' . ($mysql ? ' FOR UPDATE' : ''));
  $q->execute([$ctx['lic'], $ctx['perfil'], $serie, $codDoc]);
  $ultimo = (int)$q->fetchColumn();
  // Por si ya hay comprobantes con esa serie en la nube (nunca se repite un número existente).
  $q = $d->prepare("SELECT MAX(CAST(SUBSTR(numero, 9) AS UNSIGNED)) FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'comprobantes' AND tipo = ? AND SUBSTR(numero, 1, 7) = ?");
  if (!$mysql) $q = $d->prepare("SELECT MAX(CAST(SUBSTR(numero, 9) AS INTEGER)) FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'comprobantes' AND tipo = ? AND SUBSTR(numero, 1, 7) = ?");
  $q->execute([$ctx['lic'], $ctx['perfil'], $codDoc, $serie]);
  $siguiente = max($ultimo, (int)$q->fetchColumn()) + 1;
  if ($siguiente > 999999999) throw new WebError('Se agotaron los números del punto de emisión ' . $serie . '.');
  $d->prepare('UPDATE nube_secuencias SET ultimo = ? WHERE licencia_id = ? AND perfil = ? AND serie = ? AND cod_doc = ?')->execute([$siguiente, $ctx['lic'], $ctx['perfil'], $serie, $codDoc]);
  return $siguiente;
}

// Datos del emisor que el programa compartió. Sin ellos la web no puede armar la factura.
function emi_empresa(array $ctx): array {
  $e = $ctx['empresa'];
  $faltan = [];
  foreach (['razonSocial', 'dirMatriz', 'ambiente', 'regimen'] as $k) if (trim((string)($e[$k] ?? '')) === '') $faltan[] = $k;
  if ($faltan || empty($e['_ultima_sync'])) throw new WebError('Aún no llegan los datos de tu empresa a la nube. Abre el programa en tu computadora una vez y pulsa «Sincronizar ahora».');
  if (!in_array((string)$e['ambiente'], ['1', '2'], true)) throw new WebError('El ambiente del SRI de tu empresa no es válido. Revísalo en el programa.');
  return $e + ['ruc' => $ctx['perfil']];
}

function emi_frenar(array $ctx): void {
  $q = db()->prepare("SELECT COUNT(*) FROM eventos WHERE licencia_id = ? AND tipo = 'FACTURA_WEB' AND creado > ?");
  $q->execute([$ctx['lic'], date('Y-m-d H:i:s', time() - 3600)]);
  if ((int)$q->fetchColumn() >= EMI_LIMITE_HORA) throw new WebError('Se alcanzó el máximo de ' . EMI_LIMITE_HORA . ' facturas por hora desde la web. Espera unos minutos.');
}

// Emite la factura en la nube. $cli e $items ya vienen validados por facturar_guardar.php.
function emi_emitir_factura(array $ctx, array $cli, array $items, string $formaPago, int $diasCredito, string $observacion): array {
  emi_esquema(); // antes de la transacción: en MySQL un CREATE TABLE confirma la transacción abierta
  emi_frenar($ctx);
  $e = emi_empresa($ctx);
  $tarifas = web_tarifas($ctx);
  $tot = emi_totales($items, $tarifas);
  if ($tot['importeTotal'] <= 0) throw new WebError('El total debe ser mayor a cero.');
  if ($cli['tipo'] === 'CF' && $tot['importeTotal'] > 50) throw new WebError('Las ventas a consumidor final no pueden pasar de $50. Identifica al cliente.');
  $serie = emi_serie_web($ctx);
  [$estab, $pto] = explode('-', $serie);
  $fecha = date('Y-m-d');
  $campos = [
    ['nombre' => 'Dirección', 'valor' => $cli['direccion']], ['nombre' => 'Teléfono', 'valor' => $cli['telefono']], ['nombre' => 'Email', 'valor' => $cli['email']],
    ['nombre' => 'Condición', 'valor' => $diasCredito > 0 ? 'Crédito ' . $diasCredito . ' días' : 'Contado'],
    ['nombre' => 'Vendedor', 'valor' => ''], ['nombre' => 'Proforma', 'valor' => ''], ['nombre' => 'Observación', 'valor' => $observacion],
  ];
  if (!empty($e['proveedor']['nombre']) && !empty($e['proveedor']['valor'])) $campos[] = ['nombre' => (string)$e['proveedor']['nombre'], 'valor' => (string)$e['proveedor']['valor']];
  $quien = 'Web · ' . (($ctx['usuario']['nombre'] ?? '') ?: $ctx['usuario']['usuario']);
  $d = db();
  $d->beginTransaction();
  try {
    $sec = emi_tomar_secuencial($ctx, $serie, '01');
    $clave = emi_clave($fecha, '01', $ctx['perfil'], (string)$e['ambiente'], $estab, $pto, $sec, str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT));
    $numero = $serie . '-' . str_pad((string)$sec, 9, '0', STR_PAD_LEFT);
    $xml = emi_factura_xml(['estab' => $estab, 'ptoEmi' => $pto] + $e, $fecha, $sec, $clave, $cli, $tot, ['formaPago' => $formaPago, 'plazo' => $diasCredito], $campos);
    $ahora = web_ahora_iso();
    $c = [
      'id' => web_uuid(), 'cod_doc' => '01', 'numero' => $numero, 'fecha' => $fecha, 'cliente_nombre' => $cli['nombre'], 'cliente_identificacion' => $cli['identificacion'],
      'total' => $tot['importeTotal'], 'clave_acceso' => $clave, 'estado' => 'GENERADO', 'xml' => $xml, 'xml_autorizado' => '', 'numero_autorizacion' => '', 'fecha_autorizacion' => '',
      'mensajes' => '[]', 'anulado' => 0, 'anulacion' => '', 'referencia' => '', 'vendedor' => '', 'created_at' => $ahora, 'updated_at' => $ahora,
      'data' => [
        'cliente' => $cli, 'items' => $tot['lineas'], 'totales' => $tot, 'formaPago' => $formaPago, 'diasCredito' => $diasCredito, 'observacion' => $observacion,
        'guiaRemision' => '', 'camposAdicionales' => $campos, 'vendedor' => '', 'vendedorNombre' => '', 'proformaId' => '', 'emitidoPor' => $quien,
        'origen' => 'WEB', 'usuarioWeb' => $ctx['usuario']['usuario'], 'adoptadoPor' => '',
      ],
    ];
    nube_guardar($ctx['lic'], $ctx['perfil'], 'comprobantes', $c);
    // El cliente queda en la lista de la nube para la próxima venta (también llega al programa).
    if ($cli['tipo'] !== 'CF') {
      [$mismos] = web_lista($ctx, 'clientes', ['q' => $cli['identificacion']], 5);
      $previo = null;
      foreach ($mismos as $m) if (($m['identificacion'] ?? '') === $cli['identificacion']) { $previo = $m; break; }
      nube_guardar($ctx['lic'], $ctx['perfil'], 'clientes', ['id' => $previo['id'] ?? web_uuid(), 'tipo' => $cli['tipo'], 'identificacion' => $cli['identificacion'], 'nombre' => $cli['nombre'],
        'direccion' => $cli['direccion'], 'telefono' => $cli['telefono'], 'email' => $cli['email'], 'deleted' => 0, 'updated_at' => web_ahora_iso()]);
    }
    $d->commit();
  } catch (Throwable $ex) {
    if ($d->inTransaction()) $d->rollBack();
    throw $ex;
  }
  registrar_evento($ctx['lic'], 'FACTURA_WEB', $numero . ' · ' . $cli['nombre'] . ' · ' . dinero($tot['importeTotal']) . ' · ' . $ctx['usuario']['usuario']);
  return $c;
}

