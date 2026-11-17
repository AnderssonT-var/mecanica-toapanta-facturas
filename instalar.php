<?php
// Instalador de un solo uso: copia la actualización a su lugar y se borra a sí mismo.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!hash_equals('f44c1a434046dfdb60acbf1bd43d5f34440fb267a0f90600191e52c380a068d9', hash('sha256', (string)($_GET['t'] ?? '')))) { http_response_code(404); exit('{}'); }
$raiz = dirname(__DIR__);
$archivos = [
    'pantalla.php' => '2cdd278192d81e78d0a6baac60e1c0ee5c5ad6f861a472bf5e32317f75bc97eb',
    'lib/app.php' => '9208bd9dcd90c9f69c6b2bb44ec46a4cfcd6b85a643e72fd7bd7f97b4c57ca8a',
    'admin/pantalla.php' => '013b13017adcce43269c48568577c8be88f3b567a53be2172353c50c25acc6b2',
    'api/agente.php' => 'f637b6ea9cbe4dbdea76803ddd44c79aa3f610c8998d96d8d7cece1a4284c0f7',
];
// versión que debe haber hoy en el sitio: si alguien la cambió, no se pisa
$antes = [
    'pantalla.php' => '4c5b20a373556333fd523e84b4d4d7fd6b9da09cf994bf241922eecbb65bd069',
    'lib/app.php' => '12dfaf2cfa5b0437d04b8f7ed9f29b2691d63722c1483714f845b18237a8f583',
    'admin/pantalla.php' => '6ad2d12f9612768e958883b8198371697709ef6c81ca0f6864e3182b3e37c9c8',
    'api/agente.php' => 'c37b10f28cc0d86a251c455eded466c012383a0bd07fef891961fccdc5d143aa',
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
