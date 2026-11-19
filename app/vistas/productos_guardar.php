<?php
if (!defined('FX_PORTAL')) exit;
if (($_POST['accion'] ?? '') === 'eliminar' && (string)($_POST['id'] ?? '') !== '') {
  web_producto_eliminar($ctx, (string)$_POST['id']);
  web_aviso('Producto eliminado.');
  web_ir('v=productos');
}
$r = web_producto_guardar($ctx, $_POST);
web_aviso('Producto guardado. Llegará a tu programa en la próxima sincronización.');
web_ir('v=productos&q=' . urlencode($r['codigo']));
