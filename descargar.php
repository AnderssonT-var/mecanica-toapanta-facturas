<?php
// Botón «Descargar para Windows» de facmit.com: lleva siempre al instalador de la versión publicada en Versiones
// (el mismo que reciben los programas como actualización). Si aún no hay ninguno, lo explica en vez de dar un error.
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';

$yml = __DIR__ . '/actualizaciones/latest.yml';
$texto = is_file($yml) ? (string)file_get_contents($yml) : '';
$archivo = preg_match('/^path:\s*[\'"]?([A-Za-z0-9._-]+\.exe)/m', $texto, $m) ? $m[1] : '';
if ($archivo && is_file(__DIR__ . '/actualizaciones/' . $archivo)) {
  header('Cache-Control: no-store');
  header('Location: actualizaciones/' . rawurlencode($archivo), true, 302);
  exit;
}
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
$wa = preg_replace('/\D/', '', (string)ajuste('whatsapp', ''));
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Descargar Facmit</title><link rel="stylesheet" href="assets/estilos.css"></head>
<body><main class="wrap"><section class="card"><h1>La descarga estará disponible en breve</h1><p class="sub">Estamos publicando la versión más reciente de Facmit para Windows.<?= $wa ? ' Escríbenos y te enviamos el instalador.' : ' Vuelve a intentarlo en unos minutos.' ?></p>
<?php if ($wa): ?><p><a class="btn btn-accent" href="https://wa.me/<?= e($wa) ?>">Escribir por WhatsApp</a></p><?php endif; ?>
<p><a class="btn btn-ghost" href="../">Volver a facmit.com</a></p></section></main></body></html>
