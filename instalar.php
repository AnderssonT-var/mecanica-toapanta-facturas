<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('7049b899649eb10c418ddf9241025f3b042a90aff1951eec212d6bd93498b889', hash('sha256', (string)($_GET['t'] ?? '')))) { exit('instalador listo (token requerido)'); }
$raiz = dirname(__DIR__);
$archivos = [
    'lib/app.php' => 'd7f9936b6c1a8628a3721f3451264f4d1d256c155f4ff98b9066dcc01ecb1ab5',
    'admin/facturar.php' => '72461879a2578fa79dca9e89971fd978e6aebc60009accfc89b5d5458612b586',
    'api/agente.php' => '7fc1255551ae86072c6427e2aa6f8959ac8a976331455fb5cb740e5f308fb11a',
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
