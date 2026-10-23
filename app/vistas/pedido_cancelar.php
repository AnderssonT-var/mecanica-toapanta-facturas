<?php
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$p = web_registro($ctx, 'pedidos', (string)($_POST['id'] ?? ''));
if (!$p) throw new WebError('No se encontró la factura.');
if (!nube_cambiar_pedido($ctx['lic'], $ctx['perfil'], $p['id'], 'PENDIENTE', 'CANCELADO', ['mensaje' => 'Cancelada por ' . $ctx['usuario']['usuario']])) {
  throw new WebError('Tu programa ya está emitiendo esta factura; no se puede cancelar.');
}
web_aviso('Factura cancelada.');
web_ir('v=pedido&id=' . urlencode($p['id']));
