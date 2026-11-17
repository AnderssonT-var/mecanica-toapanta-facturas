<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('18ec5de87c02d95a29a53aa91749ead66c90b304e88572958af16bd01d975ece', hash('sha256', (string)($_GET['t'] ?? '')))) { http_response_code(404); exit('{}'); }
$raiz = dirname(__DIR__);
$archivos = [
    'pantalla.php' => '3c6a2ab2c6421d8ba41b3a7507f8e9259b6c55c68e245bdf9051417cf9d9e934',
    'lib/app.php' => '160dec4834690ce01c7ef17591d83e32717721b6691e53586d18174452ee3fda',
    'admin/pantalla.php' => '7e75827fb3e71cb6015195f2f3bf9472012311064a7d69d89c170b399dbeed5b',
];
// versión que debe haber hoy en el sitio: si alguien la cambió, no se pisa
$antes = [
    'pantalla.php' => '2cdd278192d81e78d0a6baac60e1c0ee5c5ad6f861a472bf5e32317f75bc97eb',
    'lib/app.php' => '9208bd9dcd90c9f69c6b2bb44ec46a4cfcd6b85a643e72fd7bd7f97b4c57ca8a',
    'admin/pantalla.php' => '013b13017adcce43269c48568577c8be88f3b567a53be2172353c50c25acc6b2',
];
$respaldo = dirname($raiz) . '/respaldo_web_' . date('Ymd_His');
$res = ['ok' => true, 'archivos' => []];
foreach ($archivos as $rel => $sha) {
    $origen = __DIR__ . '/archivos/' . $rel;
    if (!is_file($origen) || hash_file('sha256', $origen) !== $sha) { $res['ok'] = false; $res['archivos'][$rel] = 'origen dañado'; continue; }
    $actual = $raiz . '/' . $rel;
    if (is_file($actual) && !in_array(hash_file('sha256', $actual), [$antes[$rel], $sha], true)) {
        $res['ok'] = false; $res['archivos'][$rel] = 'el archivo del sitio cambió desde la última vez: no se actualiza nada';
    }
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
