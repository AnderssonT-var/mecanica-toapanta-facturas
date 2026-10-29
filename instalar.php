<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('e46fac13356b244edfe77f3f49044e592cd670b9b9aff63160e73420c2286bb5', hash('sha256', (string)($_GET['t'] ?? '')))) { http_response_code(404); exit('{}'); }
$raiz = dirname(__DIR__);
$archivos = [
    'lib/app.php' => '2e2a1914b12247fdf50eff55a513e62f3c7b02db7cdede4196ab1a3cc7c0b476',
    'admin/facturar.php' => 'f5c2fc0a9fff1fc2cab5130f01030b3c056c711de1945ed102951b43b06a497b',
    'admin/configuracion.php' => '4c61a46274c310d8976d937a7340a5790c005b73f63b5982713314724d27cac0',
    'pantalla.php' => '4224a75bd2411fab32096821b8023024da70fb3d44b31de791323e9ebfc09850',
    'api/agente.php' => '47bc87641e11dbf25ef25c7222a2cd57b9b2e45fccdb413634dcd9b1fc26d33c',
];
$respaldo = dirname($raiz) . '/respaldo_web_' . date('Ymd_His');
$res = ['ok' => true, 'archivos' => []];
foreach ($archivos as $rel => $sha) {
    $origen = __DIR__ . '/archivos/' . $rel;
    if (!is_file($origen) || hash_file('sha256', $origen) !== $sha) { $res['ok'] = false; $res['archivos'][$rel] = 'origen dañado'; continue; }
}
if ($res['ok']) {
    foreach ($archivos as $rel => $sha) {
        $destino = $raiz . '/' . $rel;
        if (is_file($destino)) {
            @mkdir(dirname($respaldo . '/' . $rel), 0750, true);
            @copy($destino, $respaldo . '/' . $rel);
        }
        $tmp = $destino . '.nuevo';
        $ok = copy(__DIR__ . '/archivos/' . $rel, $tmp) && rename($tmp, $destino);
        $res['archivos'][$rel] = $ok && hash_file('sha256', $destino) === $sha ? 'actualizado ' . filesize($destino) . ' bytes' : 'ERROR';
        if (!$ok || hash_file('sha256', $destino) !== $sha) $res['ok'] = false;
    }
}
$res['respaldo'] = is_dir($respaldo) ? basename($respaldo) : 'sin respaldo';
// borrar esta carpeta (incluido .git)
$borrar = function ($dir) use (&$borrar) {
    foreach (scandir($dir) as $n) {
        if ($n === '.' || $n === '..') continue;
        $p = $dir . '/' . $n;
        is_dir($p) && !is_link($p) ? $borrar($p) : @unlink($p);
    }
    @rmdir($dir);
};
$borrar(__DIR__);
$res['carpeta_borrada'] = !is_dir(__DIR__);
echo json_encode($res, JSON_UNESCAPED_UNICODE);
