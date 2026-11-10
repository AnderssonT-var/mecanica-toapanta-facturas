<?php
// Facturar por WhatsApp. El usuario de la app web registra su número (lo confirma enviando un código desde ese
// WhatsApp) y luego escribe al número de Facmit los datos de la venta. Facmit arma la factura, muestra el resumen
// y solo la emite cuando el usuario responde «SI». La factura se emite igual que en la app web (lib/emision.php):
// número, RIDE y XML al instante; el programa la firma y la autoriza en el SRI.
// Usa la API oficial de WhatsApp Business (Meta Cloud API): el token y la clave secreta se guardan en Ajustes del portal.
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

const WA_GRAPH = 'https://graph.facebook.com/v21.0';
const WA_MINUTOS_CONFIRMAR = 15;

function wa_cfg(): array {
  return ['token' => (string)ajuste('wa_token', ''), 'phone_id' => (string)ajuste('wa_phone_id', ''), 'secreto' => (string)ajuste('wa_app_secret', ''),
    'verificar' => (string)ajuste('wa_verify', ''), 'numero' => (string)ajuste('wa_numero', ''), 'graph' => (string)(fx_config()['wa_graph'] ?? WA_GRAPH)];
}
function wa_activo(): bool { $c = wa_cfg(); return $c['token'] !== '' && $c['phone_id'] !== '' && $c['secreto'] !== ''; }

function wa_esquema(): void {
  static $listo = false;
  if ($listo) return;
  if (fx_config()['db']['driver'] === 'sqlite') {
    db()->exec("CREATE TABLE IF NOT EXISTS nube_wa_numeros (telefono TEXT PRIMARY KEY, usuario_id INTEGER NOT NULL, licencia_id INTEGER NOT NULL, verificado INTEGER NOT NULL DEFAULT 0, codigo TEXT NOT NULL DEFAULT '', codigo_vence TEXT NULL, perfil TEXT NOT NULL DEFAULT '', creado TEXT NOT NULL)");
    db()->exec("CREATE TABLE IF NOT EXISTS nube_wa_pendientes (telefono TEXT PRIMARY KEY, datos TEXT NOT NULL, creado TEXT NOT NULL)");
    db()->exec("CREATE TABLE IF NOT EXISTS nube_wa_mensajes (id TEXT PRIMARY KEY, creado TEXT NOT NULL)");
  } else {
    db()->exec("CREATE TABLE IF NOT EXISTS nube_wa_numeros (telefono VARCHAR(20) NOT NULL PRIMARY KEY, usuario_id INT UNSIGNED NOT NULL, licencia_id INT UNSIGNED NOT NULL, verificado TINYINT NOT NULL DEFAULT 0, codigo VARCHAR(8) NOT NULL DEFAULT '', codigo_vence DATETIME NULL, perfil CHAR(13) NOT NULL DEFAULT '', creado DATETIME NOT NULL, KEY (usuario_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS nube_wa_pendientes (telefono VARCHAR(20) NOT NULL PRIMARY KEY, datos MEDIUMTEXT NOT NULL, creado DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS nube_wa_mensajes (id VARCHAR(128) NOT NULL PRIMARY KEY, creado DATETIME NOT NULL) ENGINE=InnoDB");
  }
  $listo = true;
}

// Número en formato internacional sin «+» (Ecuador por defecto: 0991234567 → 593991234567).
function wa_tel(string $t): string {
  $d = preg_replace('/\D/', '', $t);
  if (preg_match('/^0(9\d{8})$/', $d, $m)) return '593' . $m[1];
  if (preg_match('/^9\d{8}$/', $d)) return '593' . $d;
  return $d;
}

function wa_firma_ok(string $cuerpo, string $cabecera, string $secreto): bool {
  if ($secreto === '' || !str_starts_with($cabecera, 'sha256=')) return false;
  return hash_equals('sha256=' . hash_hmac('sha256', $cuerpo, $secreto), $cabecera);
}

function wa_enviar(string $para, string $texto): bool {
  $c = wa_cfg();
  if ($c['token'] === '' || $c['phone_id'] === '') return false;
  $ch = curl_init($c['graph'] . '/' . rawurlencode($c['phone_id']) . '/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $c['token'], 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode(['messaging_product' => 'whatsapp', 'to' => $para, 'type' => 'text', 'text' => ['body' => mb_substr($texto, 0, 4000), 'preview_url' => true]], JSON_UNESCAPED_UNICODE)]);
  $r = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  if ($code < 200 || $code >= 300) { registrar_evento(null, 'WA_ERROR', 'Envío a ' . substr($para, 0, 6) . '… falló (' . $code . '): ' . mb_substr((string)$r, 0, 200)); return false; }
  return true;
}

// ---------- Lectura del mensaje ----------
function wa_plano(string $s): string {
  $s = mb_strtolower(trim($s));
  return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
}

function wa_numero(string $s): ?float {
  $s = trim(str_replace(['$', ' '], '', $s));
  if ($s === '') return null;
  // 1.234,56 → 1234.56 ; 12,50 → 12.50
  if (preg_match('/^\d{1,3}(\.\d{3})+,\d+$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
  elseif (preg_match('/^\d+,\d+$/', $s)) $s = str_replace(',', '.', $s);
  return is_numeric($s) ? (float)$s : null;
}

// Una línea de producto: «Cambio de aceite, 1, 38.00», «2 x Filtro 9.50», «00038 x2», «Mano de obra 15 sin iva», «Servicio 20 iva 0».
function wa_linea_producto(string $t): ?array {
  $t = trim($t);
  if ($t === '') return null;
  $p = wa_plano($t);
  $sinIva = (bool)preg_match('/(?<!\w)(\+\s*iva|mas iva|sin iva)(?!\w)/', $p);
  $tar0 = (bool)preg_match('/(?<!\w)(iva\s*0\s*%?|tarifa\s*0|exento)(?!\w)|(?<![\w.,])0\s*%/', $p);
  $t = trim(preg_replace('/(?<!\w)(\+\s*iva|m[aá]s iva|sin iva|con iva|iva\s*incluido|iva\s*0\s*%?|tarifa\s*0|exento)(?!\w)|(?<![\w.,])0\s*%/iu', '', $t));
  $cant = 1.0; $precio = null; $desc = $t;
  // Separadores: «;», «|» o una coma que no esté entre dos dígitos (9,50 es un precio con coma decimal).
  $partes = array_map('trim', preg_split('/\s*(?:[;|]|(?<!\d),|,(?!\d)|,\s+)\s*/', $t));
  $partes = array_values(array_filter($partes, fn($x) => $x !== ''));
  if (count($partes) >= 3 && wa_numero($partes[1]) !== null && wa_numero($partes[2]) !== null) {
    [$desc, $cant, $precio] = [$partes[0], wa_numero($partes[1]), wa_numero($partes[2])];
  } elseif (count($partes) === 2 && wa_numero($partes[1]) !== null) {
    [$desc, $precio] = [$partes[0], wa_numero($partes[1])];
  } else {
    if (preg_match('/^(\d+(?:[.,]\d+)?)\s*(?:x|\*|unid(?:ades)?\.?|u)\s+(.+)$/iu', $t, $m) && !preg_match('/^\d+(?:[.,]\d+)?$/', trim($m[2]))) { $cant = (float)str_replace(',', '.', $m[1]); $desc = $m[2]; }
    elseif (preg_match('/^(.+?)\s*(?:x|\*)\s*(\d+(?:[.,]\d+)?)$/iu', $t, $m)) { $desc = $m[1]; $cant = (float)str_replace(',', '.', $m[2]); }
    if (preg_match('/^(.+?)\s+\$?\s*(\d{1,3}(?:\.\d{3})*,\d{1,6}|\d+(?:[.,]\d{1,6})?)\s*$/u', $desc, $m) && !preg_match('/^\d+$/', trim($m[1]))) { $desc = trim($m[1]); $precio = wa_numero($m[2]); }
    elseif (preg_match('/^(.+?)\s+\$?\s*(\d+(?:[.,]\d{1,6})?)\s*(?:x|\*)\s*(\d+(?:[.,]\d+)?)$/iu', $desc, $m)) { $desc = trim($m[1]); $precio = wa_numero($m[2]); $cant = (float)str_replace(',', '.', $m[3]); }
  }
  $desc = trim($desc, " \t-•*:");
  if ($desc === '' || $cant <= 0) return null;
  return ['texto' => $desc, 'cantidad' => $cant, 'precio' => $precio, 'conIva' => !$sinIva, 'tarifa0' => $tar0];
}

const WA_CLAVES = [
  'id' => ['ruc', 'cedula', 'ci', 'identificacion', 'id', 'pasaporte', 'cedula/ruc', 'ruc/cedula', 'documento'],
  'nombre' => ['nombre', 'nombres', 'razon social', 'cliente', 'nombre o razon social'],
  'telefono' => ['telefono', 'tel', 'cel', 'celular', 'whatsapp', 'movil'],
  'email' => ['correo', 'email', 'mail', 'e-mail', 'correo electronico'],
  'direccion' => ['direccion', 'dir'],
  'producto' => ['producto', 'productos', 'item', 'servicio', 'p', 'detalle', 'articulo'],
  'pago' => ['pago', 'forma de pago', 'forma pago', 'metodo de pago'],
  'dias' => ['credito', 'plazo', 'dias', 'dias de credito'],
  'obs' => ['observacion', 'obs', 'nota', 'comentario'],
];

// Convierte el texto en datos de factura. No consulta nada: es una función pura (tiene pruebas).
function wa_parsear(string $texto): array {
  $r = ['cliente' => ['tipo' => '', 'identificacion' => '', 'nombre' => '', 'telefono' => '', 'email' => '', 'direccion' => ''], 'items' => [], 'formaPago' => '', 'dias' => 0, 'observacion' => '', 'cf' => false];
  $mapa = [];
  foreach (WA_CLAVES as $campo => $alias) foreach ($alias as $a) $mapa[$a] = $campo;
  foreach (preg_split('/\r\n|\r|\n/', $texto) as $linea) {
    $linea = trim($linea);
    if ($linea === '') continue;
    $plano = wa_plano($linea);
    if (preg_match('/^(factura|facturar|nueva factura)\b[\s:.!]*$/', $plano)) continue;
    if (preg_match('/^(consumidor final|cf)$/', $plano)) { $r['cf'] = true; continue; }
    $campo = null; $valor = '';
    if (preg_match('/^([^:=]{1,30})\s*[:=]\s*(.*)$/u', $linea, $m)) {
      $clave = wa_plano(trim($m[1], " \t*-•"));
      if (isset($mapa[$clave])) { $campo = $mapa[$clave]; $valor = trim($m[2]); }
      if ($campo === 'id' && $clave === 'pasaporte') $r['cliente']['tipo'] = 'PASAPORTE';
    }
    if ($campo === null && preg_match('/^[-•*]\s*(.+)$/u', $linea, $m)) { $campo = 'producto'; $valor = $m[1]; }
    switch ($campo) {
      case 'id':
        if (preg_match('/consumidor|^cf$|^9{10,13}$/', wa_plano($valor))) { $r['cf'] = true; break; }
        $r['cliente']['identificacion'] = $r['cliente']['tipo'] === 'PASAPORTE' ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $valor)) : preg_replace('/\D/', '', $valor);
        break;
      case 'nombre': $r['cliente']['nombre'] = $valor; break;
      case 'telefono': $r['cliente']['telefono'] = $valor; break;
      case 'email': $r['cliente']['email'] = strtolower($valor); break;
      case 'direccion': $r['cliente']['direccion'] = $valor; break;
      case 'producto': if ($p = wa_linea_producto($valor)) $r['items'][] = $p; break;
      case 'pago':
        $v = wa_plano($valor);
        $r['formaPago'] = str_contains($v, 'efectivo') ? '01' : (str_contains($v, 'debito') ? '16' : (str_contains($v, 'credito') || str_contains($v, 'tarjeta') ? '19' : '20'));
        break;
      case 'dias': $r['dias'] = max(0, min(3650, (int)preg_replace('/\D/', '', $valor))); break;
      case 'obs': $r['observacion'] = mb_substr($valor, 0, 300); break;
    }
  }
  if ($r['cf']) $r['cliente']['tipo'] = 'CF';
  elseif ($r['cliente']['tipo'] === '') {
    $id = $r['cliente']['identificacion'];
    $r['cliente']['tipo'] = strlen($id) === 13 ? 'RUC' : (strlen($id) === 10 ? 'CEDULA' : ($id !== '' ? 'PASAPORTE' : ''));
  }
  return $r;
}

// Busca el producto del catálogo por código o por descripción exacta.
function wa_buscar_producto(array $ctx, string $texto): ?array {
  [$filas] = web_lista($ctx, 'productos', ['q' => $texto], 20);
  $t = wa_plano($texto);
  foreach ($filas as $p) if (wa_plano((string)($p['codigo'] ?? '')) === $t || wa_plano((string)($p['codigo_aux'] ?? '')) === $t) return $p;
  foreach ($filas as $p) if (wa_plano((string)($p['descripcion'] ?? '')) === $t) return $p;
  return null;
}

// Arma la factura con los datos de la nube: cliente guardado, productos del catálogo, tarifas de IVA.
function wa_armar(array $ctx, array $p): array {
  $tarifas = web_tarifas($ctx);
  $def = isset($tarifas['4']) ? '4' : (string)array_key_first(array_filter($tarifas, fn($t) => $t['pct'] > 0) ?: $tarifas);
  $cero = isset($tarifas['0']) ? '0' : $def;
  $c = $p['cliente'];
  if ($c['tipo'] === '') throw new WebError('Falta la identificación del cliente. Escribe *RUC:*, *Cédula:* o *Consumidor final*.');
  if ($c['tipo'] !== 'CF') {
    [$mismos] = web_lista($ctx, 'clientes', ['q' => $c['identificacion']], 5);
    foreach ($mismos as $g) if (($g['identificacion'] ?? '') === $c['identificacion']) {
      foreach (['nombre', 'telefono', 'email', 'direccion'] as $k) if (trim((string)$c[$k]) === '') $c[$k] = (string)($g[$k] ?? '');
      break;
    }
    if (trim($c['nombre']) === '') throw new WebError('No tengo guardado al cliente ' . $c['identificacion'] . '. Agrega una línea *Nombre:*.');
  }
  $cli = emi_validar_cliente($c);
  if (!$p['items']) throw new WebError('No encontré productos. Agrega líneas como *Producto: Cambio de aceite, 1, 38.00*.');
  $items = []; $notas = [];
  foreach ($p['items'] as $it) {
    $prod = wa_buscar_producto($ctx, $it['texto']);
    $tar = $it['tarifa0'] ? $cero : ($prod && isset($tarifas[(string)$prod['tarifa']]) ? (string)$prod['tarifa'] : $def);
    $pct = (float)$tarifas[$tar]['pct'];
    if ($it['precio'] === null) {
      if (!$prod || (float)$prod['precio'] <= 0) throw new WebError('Falta el precio de «' . $it['texto'] . '». Escríbelo así: *Producto: ' . $it['texto'] . ', 1, 10.00*');
      $unit = (float)$prod['precio'];
    } elseif ($it['conIva'] && $pct > 0) {
      $x = emi_extraer_iva((float)$it['precio'], $pct, (float)$it['cantidad']);
      $unit = $x['unit'];
      if (!$x['exacto']) $notas[] = '«' . ($prod['descripcion'] ?? $it['texto']) . '» queda en $' . number_format($x['total'], 2) . ' por el redondeo del SRI.';
    } else $unit = (float)$it['precio'];
    $items[] = ['codigo' => (string)($prod['codigo'] ?? ''), 'codigoAux' => (string)($prod['codigo_aux'] ?? ''), 'descripcion' => (string)($prod['descripcion'] ?? $it['texto']),
      'cantidad' => (float)$it['cantidad'], 'precioUnitario' => $unit, 'descuentoPct' => 0, 'tarifa' => $tar];
  }
  $items = emi_validar_items($items, $tarifas);
  $tot = emi_totales($items, $tarifas);
  if ($cli['tipo'] === 'CF' && $tot['importeTotal'] > 50) throw new WebError('Las ventas a consumidor final no pueden pasar de $50. Agrega el RUC o la cédula del cliente.');
  $forma = $p['formaPago'] !== '' ? $p['formaPago'] : '20';
  return ['cliente' => $cli, 'items' => $items, 'formaPago' => $forma, 'dias' => (int)$p['dias'], 'observacion' => (string)$p['observacion'], 'totales' => $tot, 'notas' => $notas];
}

function wa_resumen(array $f, array $ctx): string {
  $d = fn($v) => '$' . number_format((float)$v, 2);
  $cli = $f['cliente'];
  $L = ['🧾 *Factura por emitir* · ' . (($ctx['empresa']['nombreComercial'] ?? '') ?: ($ctx['empresa']['razonSocial'] ?? $ctx['perfil'])), ''];
  $L[] = '*Cliente:* ' . $cli['nombre'] . ($cli['tipo'] !== 'CF' ? ' (' . ['RUC' => 'RUC', 'CEDULA' => 'Cédula', 'PASAPORTE' => 'Pasaporte'][$cli['tipo']] . ' ' . $cli['identificacion'] . ')' : '');
  if ($cli['email']) $L[] = '*Correo:* ' . $cli['email'];
  $L[] = '';
  foreach ($f['totales']['lineas'] as $l) $L[] = '• ' . rtrim(rtrim(number_format($l['cantidad'], 2, '.', ''), '0'), '.') . ' × ' . $l['descripcion'] . ' — ' . $d($l['base'] + $l['iva']);
  $L[] = '';
  $L[] = 'Subtotal sin IVA: ' . $d($f['totales']['totalSinImpuestos']) . ' · IVA: ' . $d($f['totales']['totalIva']);
  $L[] = '*Total: ' . $d($f['totales']['importeTotal']) . '*';
  $L[] = 'Pago: ' . (WEB_FORMAS_PAGO[$f['formaPago']] ?? $f['formaPago']) . ($f['dias'] > 0 ? ' · crédito ' . $f['dias'] . ' días' : '');
  foreach ($f['notas'] as $n) $L[] = '⚠️ ' . $n;
  $L[] = '';
  $L[] = 'Responde *SI* para emitirla o *NO* para cancelar.';
  return implode("\n", $L);
}

function wa_ayuda(): string {
  return "👋 Soy Facmit. Para facturar, envíame los datos así:\n\n"
    . "Factura\nRUC: 0990000000001\nNombre: Taller Ejemplo S.A.\nCorreo: cliente@ejemplo.com\nTeléfono: 0990000000\nProducto: Cambio de aceite, 1, 38.00\nProducto: 2 x Filtro de aceite 9.50\nPago: transferencia\n\n"
    . "• Los precios se entienden *con IVA incluido* (escribe «+iva» si no lo incluyen).\n"
    . "• Si el producto está en tu catálogo, basta su código: *Producto: 00038 x 2*.\n"
    . "• Para consumidor final escribe *Consumidor final* (hasta $50).\n"
    . "• Antes de emitir te muestro el resumen y espero tu *SI*.";
}

// Enlace firmado para ver el RIDE sin iniciar sesión (vence en 30 días).
function wa_enlace_ride(int $lic, string $perfil, string $id): string {
  $vence = time() + 30 * 86400;
  $firma = rtrim(strtr(base64_encode(hash_hmac('sha256', $lic . '|' . $perfil . '|' . $id . '|' . $vence, wa_secreto_enlaces(), true)), '+/', '-_'), '=');
  $base = (string)(fx_config()['url_publica'] ?? 'https://facmit.com/licencias');
  return rtrim($base, '/') . '/app/?v=ride_publico&l=' . $lic . '&p=' . $perfil . '&id=' . rawurlencode($id) . '&e=' . $vence . '&t=' . $firma;
}
function wa_secreto_enlaces(): string { return hash('sha256', 'facmit-ride-publico|' . (string)(fx_config()['llave_privada'] ?? ''), true); }
function wa_enlace_valido(int $lic, string $perfil, string $id, int $vence, string $firma): bool {
  if ($vence < time()) return false;
  $esperada = rtrim(strtr(base64_encode(hash_hmac('sha256', $lic . '|' . $perfil . '|' . $id . '|' . $vence, wa_secreto_enlaces(), true)), '+/', '-_'), '=');
  return hash_equals($esperada, $firma);
}

// ---------- Registro del número (desde la app web) ----------
function wa_registro_usuario(int $uid): ?array {
  wa_esquema();
  $q = db()->prepare('SELECT * FROM nube_wa_numeros WHERE usuario_id = ?');
  $q->execute([$uid]);
  return $q->fetch() ?: null;
}

function wa_registrar(array $ctx, string $telefono): array {
  wa_esquema();
  $tel = wa_tel($telefono);
  if (!preg_match('/^\d{10,15}$/', $tel)) throw new WebError('Escribe tu número de WhatsApp con el código de país, por ejemplo 0991234567 o +593 99 123 4567.');
  $q = db()->prepare('SELECT usuario_id, verificado FROM nube_wa_numeros WHERE telefono = ?');
  $q->execute([$tel]);
  $otro = $q->fetch();
  if ($otro && (int)$otro['usuario_id'] !== (int)$ctx['usuario']['id'] && (int)$otro['verificado']) throw new WebError('Ese número ya está registrado en otra cuenta de Facmit.');
  db()->prepare('DELETE FROM nube_wa_numeros WHERE usuario_id = ? OR (telefono = ? AND verificado = 0)')->execute([$ctx['usuario']['id'], $tel]);
  $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  db()->prepare('INSERT INTO nube_wa_numeros (telefono, usuario_id, licencia_id, verificado, codigo, codigo_vence, perfil, creado) VALUES (?, ?, ?, 0, ?, ?, ?, ?)')
    ->execute([$tel, $ctx['usuario']['id'], $ctx['lic'], $codigo, date('Y-m-d H:i:s', time() + 1800), $ctx['perfil'], ahora()]);
  registrar_evento($ctx['lic'], 'WA_REGISTRO', $ctx['usuario']['usuario'] . ' registró el WhatsApp …' . substr($tel, -4));
  return wa_registro_usuario((int)$ctx['usuario']['id']);
}

// ---------- Mensajes que llegan ----------
// Devuelve el texto de respuesta (o '' si no hay que responder).
function wa_procesar(string $de, string $texto): string {
  wa_esquema();
  $plano = wa_plano($texto);
  $q = db()->prepare('SELECT * FROM nube_wa_numeros WHERE telefono = ?');
  $q->execute([$de]);
  $reg = $q->fetch();

  // Confirmación del número: «CODIGO 123456»
  if (preg_match('/^(codigo|code)\s*:?\s*(\d{6})$/', $plano, $m)) {
    if (!$reg || (int)$reg['verificado'] || !hash_equals((string)$reg['codigo'], $m[2]) || strtotime((string)$reg['codigo_vence']) < time()) {
      return 'Ese código no es válido o ya venció. Genera uno nuevo en la app web de Facmit › WhatsApp.';
    }
    db()->prepare("UPDATE nube_wa_numeros SET verificado = 1, codigo = '' WHERE telefono = ?")->execute([$de]);
    registrar_evento((int)$reg['licencia_id'], 'WA_VERIFICADO', 'WhatsApp …' . substr($de, -4) . ' confirmado');
    return "✅ Listo, este WhatsApp ya puede facturar en Facmit.\n\n" . wa_ayuda();
  }
  if (!$reg || !(int)$reg['verificado']) {
    return 'Hola 👋 Este número aún no está registrado para facturar. Entra a la app web de Facmit › WhatsApp, escribe tu número y envía aquí el código que te aparece.';
  }
  $ctx = web_contexto_usuario((int)$reg['usuario_id'], (string)$reg['perfil']);
  if (!$ctx) return 'Tu usuario de Facmit ya no está activo. Pide al administrador que lo revise.';
  if (!web_puede($ctx, 'facturar')) return '⚠️ ' . ($ctx['bloqueo'] ?: 'Tu usuario no tiene permiso para facturar.');

  if (preg_match('/^(hola|ayuda|menu|help|\?|info|formato)\b/', $plano)) return wa_ayuda();

  $q = db()->prepare('SELECT datos, creado FROM nube_wa_pendientes WHERE telefono = ?');
  $q->execute([$de]);
  $pend = $q->fetch();
  if ($pend && strtotime((string)$pend['creado']) < time() - WA_MINUTOS_CONFIRMAR * 60) {
    db()->prepare('DELETE FROM nube_wa_pendientes WHERE telefono = ?')->execute([$de]);
    $pend = null;
  }
  if (preg_match('/^(si|sí|s|emitir|emite|ok|dale|confirmo|confirmar)[.!]*$/u', $plano)) {
    if (!$pend) return 'No tengo ninguna factura esperando confirmación. Envíame los datos de la venta.';
    db()->prepare('DELETE FROM nube_wa_pendientes WHERE telefono = ?')->execute([$de]);
    $f = json_decode((string)$pend['datos'], true);
    $c = emi_emitir_factura($ctx, $f['cliente'], $f['items'], $f['formaPago'], (int)$f['dias'], (string)$f['observacion'], 'WhatsApp');
    $sync = $ctx['empresa']['_ultima_sync'] ?? null;
    $pcActiva = $sync && time() - strtotime((string)$sync) < 900;
    return '✅ *Factura ' . $c['numero'] . '* emitida por $' . number_format((float)$c['total'], 2) . ".\n\n"
      . 'RIDE: ' . wa_enlace_ride($ctx['lic'], $ctx['perfil'], $c['id']) . "\n\n"
      . ($pcActiva ? 'Tu programa la está firmando y enviando al SRI.' : 'Se firmará y se autorizará en el SRI en cuanto enciendas tu computadora.')
      . ($c['data']['cliente']['email'] ? ' Al autorizarse, el cliente la recibe en ' . $c['data']['cliente']['email'] . '.' : '');
  }
  if (preg_match('/^(no|cancelar|cancela|anular)[.!]*$/', $plano)) {
    if (!$pend) return 'No hay nada pendiente.';
    db()->prepare('DELETE FROM nube_wa_pendientes WHERE telefono = ?')->execute([$de]);
    return 'Listo, cancelé esa factura. No se emitió nada.';
  }

  $p = wa_parsear($texto);
  if (!$p['items'] && $p['cliente']['tipo'] === '') return wa_ayuda();
  $f = wa_armar($ctx, $p);
  $guardar = $f;
  unset($guardar['totales'], $guardar['notas']);
  $mysql = fx_config()['db']['driver'] !== 'sqlite';
  db()->prepare($mysql ? 'REPLACE INTO nube_wa_pendientes (telefono, datos, creado) VALUES (?, ?, ?)' : 'INSERT OR REPLACE INTO nube_wa_pendientes (telefono, datos, creado) VALUES (?, ?, ?)')
    ->execute([$de, json_encode($guardar, JSON_UNESCAPED_UNICODE), ahora()]);
  return wa_resumen($f, $ctx);
}

// Evita procesar dos veces el mismo mensaje (Meta reintenta si no respondemos a tiempo).
function wa_mensaje_nuevo(string $id): bool {
  wa_esquema();
  try {
    db()->prepare('INSERT INTO nube_wa_mensajes (id, creado) VALUES (?, ?)')->execute([$id, ahora()]);
    return true;
  } catch (PDOException $e) {
    return false;
  }
}
