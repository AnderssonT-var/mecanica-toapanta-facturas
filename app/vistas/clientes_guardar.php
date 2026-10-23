<?php
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'editar');
$id = (string)($_POST['id'] ?? '');
$previo = $id !== '' ? web_registro($ctx, 'clientes', $id) : null;
if ($id !== '' && !$previo) throw new WebError('El cliente ya no existe.');
if (($_POST['accion'] ?? '') === 'eliminar' && $previo) {
  web_guardar($ctx, 'clientes', array_merge($previo, ['deleted' => 1]));
  web_aviso('Cliente eliminado.');
  web_ir('v=clientes');
}
$tipo = in_array($_POST['tipo'] ?? '', ['CEDULA', 'RUC', 'PASAPORTE'], true) ? $_POST['tipo'] : 'CEDULA';
$ident = $tipo === 'PASAPORTE' ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$_POST['identificacion'])) : preg_replace('/\D/', '', (string)$_POST['identificacion']);
if ($tipo === 'CEDULA' && !validar_cedula($ident)) throw new WebError('La cédula no es válida.');
if ($tipo === 'RUC' && !validar_ruc($ident)) throw new WebError('El RUC no es válido.');
if ($tipo === 'PASAPORTE' && (strlen($ident) < 3 || strlen($ident) > 20)) throw new WebError('La identificación del exterior debe tener entre 3 y 20 caracteres.');
$nombre = mb_strtoupper(trim((string)$_POST['nombre']));
if ($nombre === '') throw new WebError('Falta el nombre.');
$email = trim((string)($_POST['email'] ?? ''));
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new WebError('El correo no es válido.');
// Si ya existe otro cliente con esa identificación, se actualiza ese (no se duplica).
if (!$previo) {
  [$mismos] = web_lista($ctx, 'clientes', ['q' => $ident], 5);
  foreach ($mismos as $m) if ($m['identificacion'] === $ident) { $previo = $m; break; }
}
$r = web_guardar($ctx, 'clientes', [
  'id' => $previo['id'] ?? null, 'tipo' => $tipo, 'identificacion' => $ident, 'nombre' => mb_substr($nombre, 0, 300),
  'direccion' => mb_substr(trim((string)($_POST['direccion'] ?? '')), 0, 300), 'telefono' => mb_substr(trim((string)($_POST['telefono'] ?? '')), 0, 40),
  'email' => mb_substr($email, 0, 160), 'deleted' => 0,
]);
web_aviso('Cliente guardado. Llegará a tu programa en la próxima sincronización.');
web_ir('v=clientes&q=' . urlencode($ident));
