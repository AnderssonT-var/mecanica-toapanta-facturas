<?php
// Búsqueda para el formulario de factura (JSON).
if (!defined('FX_PORTAL')) exit;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$t = (string)($_GET['t'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
if (!in_array($t, ['clientes', 'productos'], true)) { http_response_code(400); echo '[]'; exit; }
[$filas] = web_lista($ctx, $t, ['q' => $q], 12);
$salida = $t === 'clientes' ? array_map('web_cliente_salida', $filas) : web_productos_salida($ctx, $filas);
echo json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
