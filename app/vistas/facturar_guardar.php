<?php
// Emite una factura desde la web (ver lib/emision.php). Valida los datos que llegan del formulario.
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
$forma = array_key_exists((string)($in['formaPago'] ?? ''), WEB_FORMAS_PAGO) ? (string)$in['formaPago'] : '20';
$dias = min(3650, max(0, (int)($in['diasCredito'] ?? 0)));
// La factura se emite aquí mismo en la nube: número, clave de acceso, XML y RIDE quedan listos aunque la
// computadora esté apagada. El programa la firma y la envía al SRI en cuanto se enciende.
$c = emi_emitir_factura($ctx, $cli, $items, $forma, $dias, mb_substr(trim((string)($in['observacion'] ?? '')), 0, 300));
web_aviso('Factura ' . $c['numero'] . ' emitida. El RIDE y el XML ya están listos.');
web_ir('v=comprobante&id=' . urlencode($c['id']));
