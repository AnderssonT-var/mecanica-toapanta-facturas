<?php
// Términos de uso y política de privacidad (páginas públicas; el programa y la app web enlazan aquí).
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
cabeceras_seguridad();
$doc = ($_GET['doc'] ?? '') === 'privacidad' ? 'privacidad' : 'terminos';
$html = (string)file_get_contents(__DIR__ . '/legal/' . $doc . '.html');
$contacto = 'Tovel';
try {
  $email = ajuste('soporte_email', '');
  $tel = ajuste('soporte_telefono', '');
  $contacto = 'Tovel' . ($email ? ' · <a href="mailto:' . e($email) . '">' . e($email) . '</a>' : '') . ($tel ? ' · ' . e($tel) : '');
} catch (Throwable $ex) { /* portal sin instalar: se muestra el nombre */ }
$html = str_replace('{{CONTACTO}}', $contacto, $html);
$marca = e(fx_marca());
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $doc === 'privacidad' ? 'Política de privacidad' : 'Términos de uso' ?> · <?= $marca ?></title>
<link rel="icon" href="app/assets/icono.svg"><link rel="stylesheet" href="assets/estilos.css?v=<?= FX_VERSION ?>"></head><body>
<header class="top"><div class="wrap top-in"><span class="marca"><span class="logo"><?= fx_logo(20) ?></span><span><b><?= $marca ?></b></span></span>
<nav><a href="?doc=terminos"<?= $doc === 'terminos' ? ' class="on"' : '' ?>>Términos de uso</a><a href="?doc=privacidad"<?= $doc === 'privacidad' ? ' class="on"' : '' ?>>Privacidad</a></nav></div></header>
<main class="wrap"><article class="card legal"><?= $html ?></article></main>
<footer class="wrap pie"><?= $marca ?> · Tovel</footer></body></html>
