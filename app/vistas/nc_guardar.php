<?php
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$f = web_registro($ctx, 'comprobantes', (string)($_POST['factura'] ?? ''));
if (!$f || ($f['cod_doc'] ?? '') !== '01' || ($f['estado'] ?? '') !== 'AUTORIZADO' || !empty($f['anulado'])) throw new WebError('La factura no admite nota de crédito.');
$motivo = trim(emi_txt($_POST['motivo'] ?? ''));
if (mb_strlen($motivo) < 3) throw new WebError('Escribe el motivo de la nota de crédito.');
$tipo = ($_POST['tipo'] ?? '') === 'ANULACION' ? 'ANULACION' : 'DEVOLUCION';
if (!detalle_activo($ctx['licencia'], 'movil_nc')) throw new WebError('Tu plan no incluye notas de crédito desde el celular.');
if ($tipo === 'DEVOLUCION' && !detalle_activo($ctx['licencia'], 'nc')) throw new WebError('Tu plan no incluye devoluciones parciales: solo puedes anular toda la factura.');
$saldos = web_nc_saldos($ctx, $f);
$items = [];
$total = 0.0;
$d = web_json($f['data'] ?? []);
$lineas = $d['items'] ?? [];
if ($tipo === 'ANULACION') {
  foreach ($saldos as $l) if ($l['devuelto'] > 0) throw new WebError('La factura ya tiene devoluciones: haz una devolución por lo que queda.');
  $total = (float)$f['total'];
} else {
  $cant = is_array($_POST['cant'] ?? null) ? $_POST['cant'] : [];
  foreach ($cant as $i => $v) {
    $i = (int)$i; $q = emi_num_ok($v);
    if (is_nan($q) || $q <= 0) continue;
    if (!isset($saldos[$i])) throw new WebError('Línea de factura inválida.');
    if ($q > $saldos[$i]['saldo'] + 1e-9) throw new WebError('"' . $saldos[$i]['descripcion'] . '": solo quedan ' . web_cant($saldos[$i]['saldo']) . ' por devolver.');
    $items[] = ['indice' => $i, 'cantidad' => round($q, 6), 'codigo' => $saldos[$i]['codigo'], 'descripcion' => $saldos[$i]['descripcion'], 'precioUnitario' => (float)($lineas[$i]['precioUnitario'] ?? 0), 'descuentoPct' => (float)($lineas[$i]['descuentoPct'] ?? 0)];
    $l = $lineas[$i] ?? [];
    $base = (float)($l['base'] ?? 0); $cv = (float)($l['cantidad'] ?? 0);
    $total += $cv > 0 ? ($base / $cv) * $q * (1 + (float)($l['pct'] ?? 0) / 100) : 0;
  }
  if (!$items) throw new WebError('Escribe la cantidad a devolver en al menos un producto.');
}
$pedido = web_guardar($ctx, 'pedidos', [
  'tipo' => 'NC', 'estado' => 'PENDIENTE', 'creado' => web_ahora_iso(), 'facturaId' => $f['id'], 'facturaNumero' => $f['numero'], 'tipoNc' => $tipo, 'motivo' => mb_substr($motivo, 0, 200),
  'items' => $items, 'cliente' => ['nombre' => $d['cliente']['nombre'] ?? ($f['cliente_nombre'] ?? ''), 'identificacion' => $d['cliente']['identificacion'] ?? ($f['cliente_identificacion'] ?? '')],
  'total' => round($total, 2), 'usuario' => $ctx['usuario']['usuario'] ?? '', 'usuarioNombre' => $ctx['usuario']['nombre'] ?? '',
]);
web_aviso('Nota de crédito enviada. Tu programa la emitirá y la enviará al SRI.');
web_ir('v=pedido&id=' . urlencode($pedido['id']));
