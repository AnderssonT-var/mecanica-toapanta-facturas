<?php
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'editar');
$id = (string)($_POST['id'] ?? '');
$previo = $id !== '' ? web_registro($ctx, 'productos', $id) : null;
if ($id !== '' && !$previo) throw new WebError('El producto ya no existe.');
if (($_POST['accion'] ?? '') === 'eliminar' && $previo) {
  web_guardar($ctx, 'productos', array_merge($previo, ['deleted' => 1]));
  web_aviso('Producto eliminado.');
  web_ir('v=productos');
}
$codigo = trim((string)($_POST['codigo'] ?? ''));
$desc = trim((string)($_POST['descripcion'] ?? ''));
if ($codigo === '' || $desc === '') throw new WebError('El código y la descripción son obligatorios.');
$precio = (float)str_replace(',', '.', (string)($_POST['precio'] ?? '0'));
if ($precio < 0) throw new WebError('El precio no puede ser negativo.');
$tarifa = (string)($_POST['tarifa'] ?? '4');
if (!isset(web_tarifas($ctx)[$tarifa])) throw new WebError('Tarifa de IVA no válida.');
if (!$previo) {
  [$mismos] = web_lista($ctx, 'productos', ['q' => $codigo], 10);
  foreach ($mismos as $m) if (mb_strtolower($m['codigo']) === mb_strtolower($codigo)) { $previo = $m; break; }
}
web_guardar($ctx, 'productos', [
  'id' => $previo['id'] ?? null, 'codigo' => mb_substr($codigo, 0, 25), 'descripcion' => mb_substr($desc, 0, 300), 'precio' => round($precio, 6), 'tarifa' => $tarifa,
  'codigo_aux' => mb_substr(trim((string)($_POST['codigo_aux'] ?? '')), 0, 25), 'linea' => mb_substr(trim((string)($_POST['linea'] ?? '')), 0, 60), 'deleted' => 0,
]);
web_aviso('Producto guardado. Llegará a tu programa en la próxima sincronización.');
web_ir('v=productos&q=' . urlencode($codigo));
