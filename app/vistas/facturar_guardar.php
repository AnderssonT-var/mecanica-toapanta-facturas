<?php
// Crea un pedido de factura en la nube. El programa de escritorio lo toma, lo firma, lo envía al SRI y devuelve el resultado.
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$in = json_decode((string)($_POST['pedido'] ?? ''), true);
if (!is_array($in)) throw new WebError('No se recibió la factura. Intenta de nuevo.');
$tarifas = web_tarifas($ctx);
$c = is_array($in['cliente'] ?? null) ? $in['cliente'] : [];
$tipo = (string)($c['tipo'] ?? '');
if ($tipo === 'CF') {
  $cli = ['tipo' => 'CF', 'identificacion' => '9999999999999', 'nombre' => 'CONSUMIDOR FINAL', 'direccion' => '', 'telefono' => '', 'email' => ''];
} else {
  if (!in_array($tipo, ['CEDULA', 'RUC', 'PASAPORTE'], true)) throw new WebError('Elige el tipo de identificación del cliente.');
  $id = $tipo === 'PASAPORTE' ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($c['identificacion'] ?? ''))) : preg_replace('/\D/', '', (string)($c['identificacion'] ?? ''));
  if ($tipo === 'CEDULA' && !validar_cedula($id)) throw new WebError('La cédula del cliente no es válida.');
  if ($tipo === 'RUC' && !validar_ruc($id)) throw new WebError('El RUC del cliente no es válido.');
  if ($tipo === 'PASAPORTE' && strlen($id) < 3) throw new WebError('La identificación del cliente no es válida.');
  $nombre = mb_strtoupper(trim((string)($c['nombre'] ?? '')));
  if ($nombre === '') throw new WebError('Falta el nombre del cliente.');
  $email = trim((string)($c['email'] ?? ''));
  if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new WebError('El correo del cliente no es válido.');
  $cli = ['tipo' => $tipo, 'identificacion' => $id, 'nombre' => mb_substr($nombre, 0, 300), 'direccion' => mb_substr(trim((string)($c['direccion'] ?? '')), 0, 300),
    'telefono' => mb_substr(trim((string)($c['telefono'] ?? '')), 0, 40), 'email' => mb_substr($email, 0, 160)];
}
$items = [];
foreach ((array)($in['items'] ?? []) as $it) {
  if (!is_array($it)) continue;
  $desc = trim((string)($it['descripcion'] ?? ''));
  $cant = (float)($it['cantidad'] ?? 0);
  $precio = (float)($it['precioUnitario'] ?? 0);
  $descPct = min(100, max(0, (float)($it['descuentoPct'] ?? 0)));
  $tar = (string)($it['tarifa'] ?? '');
  if ($desc === '' || $cant <= 0) continue;
  if ($precio < 0) throw new WebError('Hay un precio negativo en "' . $desc . '".');
  if (!isset($tarifas[$tar])) throw new WebError('La tarifa de IVA de "' . $desc . '" no está habilitada.');
  $items[] = ['codigo' => mb_substr(trim((string)($it['codigo'] ?? '')), 0, 25), 'codigoAux' => mb_substr(trim((string)($it['codigoAux'] ?? '')), 0, 25), 'descripcion' => mb_substr($desc, 0, 300),
    'cantidad' => $cant, 'precioUnitario' => $precio, 'descuentoPct' => $descPct, 'tarifa' => $tar];
}
if (!$items) throw new WebError('Agrega al menos un producto con cantidad.');
if (count($items) > 200) throw new WebError('Máximo 200 líneas por factura.');
$tot = web_totales($items, $tarifas);
if ($tot['importeTotal'] <= 0) throw new WebError('El total debe ser mayor a cero.');
if ($cli['tipo'] === 'CF' && $tot['importeTotal'] > 50) throw new WebError('Las ventas a consumidor final no pueden pasar de $50. Identifica al cliente.');
$forma = array_key_exists((string)($in['formaPago'] ?? ''), WEB_FORMAS_PAGO) ? (string)$in['formaPago'] : '20';
$p = web_guardar($ctx, 'pedidos', [
  'tipo' => 'FACTURA', 'estado' => 'PENDIENTE', 'creado' => web_ahora_iso(), 'usuario' => $ctx['usuario']['usuario'], 'usuarioNombre' => $ctx['usuario']['nombre'],
  'cliente' => $cli, 'items' => $items, 'formaPago' => $forma, 'diasCredito' => max(0, (int)($in['diasCredito'] ?? 0)),
  'observacion' => mb_substr(trim((string)($in['observacion'] ?? '')), 0, 300), 'total' => $tot['importeTotal'], 'mensaje' => '', 'comprobanteId' => '', 'numero' => '',
]);
registrar_evento($ctx['lic'], 'FACTURA_WEB', $cli['nombre'] . ' · ' . dinero($tot['importeTotal']) . ' · ' . $ctx['usuario']['usuario']);
web_ir('v=pedido&id=' . urlencode($p['id']));
