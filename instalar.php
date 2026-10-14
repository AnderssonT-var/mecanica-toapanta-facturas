<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
ini_set('display_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) echo "\nFATAL: {$e['message']} linea {$e['line']}"; });
// Solo instala los 3 archivos de esta carpeta (verificados por su huella) y se borra.
if (($_GET['accion'] ?? '') !== 'instalar' && !isset($_GET['probar'])) { exit('instalador listo'); }
$raiz = dirname(__DIR__);
$archivos = [
    'lib/app.php' => 'd7f9936b6c1a8628a3721f3451264f4d1d256c155f4ff98b9066dcc01ecb1ab5',
    'admin/facturar.php' => '72461879a2578fa79dca9e89971fd978e6aebc60009accfc89b5d5458612b586',
    'api/agente.php' => '7fc1255551ae86072c6427e2aa6f8959ac8a976331455fb5cb740e5f308fb11a',
];
$respaldo = $raiz . '/../respaldo_web_' . gmdate('Ymd_His');
if (isset($_GET['probar'])) { echo json_encode(['raiz' => $raiz, 'php' => PHP_VERSION, 'escribible' => is_writable($raiz . '/lib/app.php'), 'archivos' => array_map(fn($r) => is_file(__DIR__ . '/archivos/' . $r), array_keys($archivos))]); exit; }
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
