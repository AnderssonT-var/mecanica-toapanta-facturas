<?php
// Botón «Descargar para Windows» de facmit.com. Primero busca en descargas/ el instalador más nuevo (se sube con cada
// cambio, para que quien instala por primera vez tenga siempre lo último); si no hay, usa el publicado en Versiones.
// Así la página siempre ofrece lo último sin forzar la actualización automática de los clientes.
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';

// ?app=android: la app para celulares Android (APK firmado, el más nuevo de descargas/).
$android = ($_GET['app'] ?? '') === 'android';
$patron = $android ? '/Facmit-Android-(\d+\.\d+\.\d+)\.apk$/' : '/Facmit-Setup-(\d+\.\d+\.\d+)\.exe$/';
$mejor = '';
foreach (glob(__DIR__ . ($android ? '/descargas/Facmit-Android-*.apk' : '/descargas/Facmit-Setup-*.exe')) ?: [] as $f) {
  if (!preg_match($patron, $f, $mv)) continue;
  if ($mejor === '' || version_compare($mv[1], $mejor[1], '>')) $mejor = [basename($f), $mv[1]];
}
if ($mejor) {
  header('Cache-Control: no-store');
  header('Location: descargas/' . rawurlencode($mejor[0]), true, 302);
  exit;
}
if ($android) { http_response_code(503); header('Content-Type: text/plain; charset=utf-8'); exit('La app para Android estará disponible en unos minutos.'); }
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
