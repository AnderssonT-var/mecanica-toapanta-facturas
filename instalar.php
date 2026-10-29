<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('cca2f07c2568b9f0a42ff37690028c68846e8df56db6d0aa73b201936e237777', hash('sha256', (string)($_GET['t'] ?? '')))) { http_response_code(404); exit('{}'); }
$raiz = dirname(__DIR__);
$archivos = [
    'lib/app.php' => '098ec13dcdec0ca66f7e8caaec98e81973522e1b9a852032745b94a427174c8e',
    'admin/facturar.php' => 'c1746038c4ca7b72670aa5e6a67c12da8deef161a7affaa3ee60fa1eca39e9a0',
    'admin/configuracion.php' => '03b170eb8752575c2c10e3a61cdcf2fbd254af945617d9b0a2708e9d83b1e6ae',
    'pantalla.php' => 'bcbaa18d791d4e316e351da8d046287c2cecfa7ccf5bd29b01a7077d73840bf1',
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
