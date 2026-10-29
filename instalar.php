<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('5a194a57b3aaf4e6c31c3d5448ef386988886a9570bc7c4dc6330a9a9e2adc0d', hash('sha256', (string)($_GET['t'] ?? '')))) { http_response_code(404); exit('{}'); }
$raiz = dirname(__DIR__);
$archivos = [
    'lib/app.php' => '59bb491b93c146d8d510a6ddd30a9faf447f201d98589eae282c9e33235c9a31',
    'admin/_lib.php' => 'f21be59ee21c7a9cb31b59b9331c2cc364deebe75f0cc571b16d8d85a541b51f',
    'admin/pantalla.php' => 'e7bd0362f083af6261d2bb78039c9a63cd4ccc59de39be2321bd6146d8d84317',
    'admin/configuracion.php' => 'b0e44699121e3e08931cf1630f756ebca0a39ee43b4bdb88487e54f30de1b1ea',
    'pantalla.php' => '369283472894134d65797d0b73b0fa79e1784bf596294cd01b836d7502e259c7',
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
