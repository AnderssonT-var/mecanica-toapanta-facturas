<?php
// Búsqueda para el formulario de factura (JSON).
if (!defined('FX_PORTAL')) exit;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$t = (string)($_GET['t'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));
if (!in_array($t, ['clientes', 'productos'], true)) { http_response_code(400); echo '[]'; exit; }
[$filas] = web_lista($ctx, $t, ['q' => $q], 12);
$salida = array_map(fn($r) => $t === 'clientes'
  ? ['id' => $r['id'], 'tipo' => $r['tipo'], 'identificacion' => $r['identificacion'], 'nombre' => $r['nombre'], 'direccion' => $r['direccion'] ?? '', 'telefono' => $r['telefono'] ?? '', 'email' => $r['email'] ?? '']
  : ['id' => $r['id'], 'codigo' => $r['codigo'], 'codigo_aux' => $r['codigo_aux'] ?? '', 'descripcion' => $r['descripcion'], 'precio' => (float)$r['precio'], 'tarifa' => (string)$r['tarifa']], $filas);
echo json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
