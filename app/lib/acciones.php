<?php
// Acciones de la app web que comparten el navegador (vistas/*_guardar.php) y la app de Android (api.php).
// Reciben los datos ya leídos (de $_POST o del JSON) y lanzan WebError con un mensaje para el usuario.
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

function web_txt_in(array $in, string $k, int $max = 300): string {
  $v = $in[$k] ?? '';
  return mb_substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
}

// Crea o actualiza un cliente. Si ya existe otro con esa identificación, se actualiza ese (no se duplica).
function web_cliente_guardar(array $ctx, array $in): array {
  web_exigir($ctx, 'editar');
  $id = web_txt_in($in, 'id', 64);
  $previo = $id !== '' ? web_registro($ctx, 'clientes', $id) : null;
  if ($id !== '' && !$previo) throw new WebError('El cliente ya no existe.');
  $tipo = in_array($in['tipo'] ?? '', ['CEDULA', 'RUC', 'PASAPORTE'], true) ? (string)$in['tipo'] : 'CEDULA';
  $crudo = web_txt_in($in, 'identificacion', 40);
  $ident = $tipo === 'PASAPORTE' ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $crudo)) : preg_replace('/\D/', '', $crudo);
  if ($tipo === 'CEDULA' && !validar_cedula($ident)) throw new WebError('La cédula no es válida.');
  if ($tipo === 'RUC' && !validar_ruc($ident)) throw new WebError('El RUC no es válido.');
  if ($tipo === 'PASAPORTE' && (strlen($ident) < 3 || strlen($ident) > 20)) throw new WebError('La identificación del exterior debe tener entre 3 y 20 caracteres.');
  $nombre = mb_strtoupper(web_txt_in($in, 'nombre'));
  if ($nombre === '') throw new WebError('Falta el nombre.');
  $email = web_txt_in($in, 'email', 160);
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new WebError('El correo no es válido.');
  if (!$previo) {
    [$mismos] = web_lista($ctx, 'clientes', ['q' => $ident], 5);
    foreach ($mismos as $m) if (($m['identificacion'] ?? '') === $ident) { $previo = $m; break; }
  }
  return web_guardar($ctx, 'clientes', [
    'id' => $previo['id'] ?? null, 'tipo' => $tipo, 'identificacion' => $ident, 'nombre' => $nombre,
    'direccion' => web_txt_in($in, 'direccion'), 'telefono' => web_txt_in($in, 'telefono', 40), 'email' => $email, 'deleted' => 0,
  ]);
}

function web_cliente_eliminar(array $ctx, string $id): void {
  web_exigir($ctx, 'editar');
  $previo = $id !== '' ? web_registro($ctx, 'clientes', $id) : null;
  if (!$previo) throw new WebError('El cliente ya no existe.');
  web_guardar($ctx, 'clientes', array_merge($previo, ['deleted' => 1]));
}

// Crea o actualiza un producto. Si ya existe otro con ese código, se actualiza ese.
function web_producto_guardar(array $ctx, array $in): array {
  web_exigir($ctx, 'editar');
  $id = web_txt_in($in, 'id', 64);
  $previo = $id !== '' ? web_registro($ctx, 'productos', $id) : null;
  if ($id !== '' && !$previo) throw new WebError('El producto ya no existe.');
  $codigo = web_txt_in($in, 'codigo', 25);
  $desc = web_txt_in($in, 'descripcion');
  if ($codigo === '' || $desc === '') throw new WebError('El código y la descripción son obligatorios.');
  $precio = (float)str_replace(',', '.', web_txt_in($in, 'precio', 30) ?: '0');
  if ($precio < 0) throw new WebError('El precio no puede ser negativo.');
  $tarifa = web_txt_in($in, 'tarifa', 4) ?: '4';
  if (!isset(web_tarifas($ctx)[$tarifa])) throw new WebError('Tarifa de IVA no válida.');
  if (!$previo) {
    [$mismos] = web_lista($ctx, 'productos', ['q' => $codigo], 10);
    foreach ($mismos as $m) if (mb_strtolower((string)($m['codigo'] ?? '')) === mb_strtolower($codigo)) { $previo = $m; break; }
  }
  // Se conservan los campos que solo maneja el programa (costo, stock, bodegas…).
  return web_guardar($ctx, 'productos', array_merge($previo ?? [], [
    'id' => $previo['id'] ?? null, 'codigo' => $codigo, 'descripcion' => $desc, 'precio' => round($precio, 6), 'tarifa' => $tarifa,
    'codigo_aux' => web_txt_in($in, 'codigo_aux', 25), 'linea' => web_txt_in($in, 'linea', 60), 'deleted' => 0,
  ]));
}

function web_producto_eliminar(array $ctx, string $id): void {
  web_exigir($ctx, 'editar');
  $previo = $id !== '' ? web_registro($ctx, 'productos', $id) : null;
  if (!$previo) throw new WebError('El producto ya no existe.');
  web_guardar($ctx, 'productos', array_merge($previo, ['deleted' => 1]));
}

// Emite una factura en la nube (número, clave, XML y RIDE listos); el programa la firma y la envía al SRI.
function web_facturar(array $ctx, array $in, string $canal = 'Web'): array {
  web_exigir($ctx, 'facturar');
  $tarifas = web_tarifas($ctx);
  $cli = emi_validar_cliente(is_array($in['cliente'] ?? null) ? $in['cliente'] : []);
  $items = emi_validar_items(is_array($in['items'] ?? null) ? $in['items'] : [], $tarifas);
  web_exigir_stock($ctx, $items);
  $forma = array_key_exists((string)($in['formaPago'] ?? ''), WEB_FORMAS_PAGO) ? (string)$in['formaPago'] : '20';
  $dias = min(3650, max(0, (int)($in['diasCredito'] ?? 0)));
  $c = emi_emitir_factura($ctx, $cli, $items, $forma, $dias, web_txt_in($in, 'observacion'), $canal);
  web_avisar_dueno($ctx); // si la PC de la tienda está apagada, el dueño se entera por correo (máx. 2 al día)
  return $c;
}

// Productos para tocar y agregar: los más facturados en 180 días y, si faltan, los primeros del catálogo.
function web_rapidos(array $ctx, int $max = 12): array {
  $r = db()->prepare("SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'comprobantes' AND tipo = '01' AND borrado = 0 AND fecha >= ? ORDER BY fecha DESC LIMIT 400");
  $r->execute([$ctx['lic'], $ctx['perfil'], date('Y-m-d', strtotime('-180 days'))]);
  $cuenta = [];
  foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $j) {
    $d = web_json((json_decode($j, true) ?: [])['data'] ?? []);
    foreach (($d['items'] ?? []) as $it) if (!empty($it['codigo'])) $cuenta[$it['codigo']] = ($cuenta[$it['codigo']] ?? 0) + 1;
  }
  arsort($cuenta);
  [$catalogo] = web_lista($ctx, 'productos', [], 1000);
  $porCodigo = [];
  foreach ($catalogo as $p) $porCodigo[$p['codigo']] = $p;
  $rapidos = [];
  foreach (array_keys($cuenta) as $cod) { if (isset($porCodigo[$cod])) $rapidos[] = $porCodigo[$cod]; if (count($rapidos) >= $max) break; }
  foreach ($catalogo as $p) { if (count($rapidos) >= $max) break; if (!in_array($p, $rapidos, true)) $rapidos[] = $p; }
  return web_productos_salida($ctx, $rapidos);
}

// Forma pública de clientes y productos (la que usan la búsqueda del formulario y la app).
function web_cliente_salida(array $r): array {
  return ['id' => $r['id'], 'tipo' => $r['tipo'] ?? 'CEDULA', 'identificacion' => $r['identificacion'] ?? '', 'nombre' => $r['nombre'] ?? '', 'direccion' => $r['direccion'] ?? '', 'telefono' => $r['telefono'] ?? '', 'email' => $r['email'] ?? ''];
}
function web_productos_salida(array $ctx, array $filas): array {
  $stock = web_stock($ctx, array_map(fn($r) => (string)$r['codigo'], $filas));
  return array_map(fn($r) => ['id' => $r['id'], 'codigo' => (string)$r['codigo'], 'codigo_aux' => $r['codigo_aux'] ?? '', 'descripcion' => $r['descripcion'] ?? '', 'precio' => (float)($r['precio'] ?? 0), 'tarifa' => (string)($r['tarifa'] ?? '4'), 'linea' => $r['linea'] ?? '', 'stock' => $stock[mb_strtolower((string)$r['codigo'])] ?? null], $filas);
}
