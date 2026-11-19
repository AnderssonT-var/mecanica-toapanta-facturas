<?php
if (!defined('FX_PORTAL')) exit;
if (($_POST['accion'] ?? '') === 'eliminar' && (string)($_POST['id'] ?? '') !== '') {
  web_cliente_eliminar($ctx, (string)$_POST['id']);
  web_aviso('Cliente eliminado.');
  web_ir('v=clientes');
}
$r = web_cliente_guardar($ctx, $_POST);
web_aviso('Cliente guardado. Llegará a tu programa en la próxima sincronización.');
web_ir('v=clientes&q=' . urlencode($r['identificacion']));
