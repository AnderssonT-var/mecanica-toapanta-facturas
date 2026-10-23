<?php
if (!defined('FX_PORTAL')) exit;
web_cabeceras();
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0F1B2D"><title>Entrar · Facturador en la nube</title><link rel="stylesheet" href="assets/app.css?v=<?= FX_VERSION ?>"><link rel="icon" href="assets/icono.svg"></head>
<body class="pantalla-login">
<main class="login">
  <div class="login-marca"><span class="logo grande"><?= icono('M7 3h7l5 5v13H7zM14 3v5h5M10 13l2 2 4-4', 26) ?></span><div><b>Facturador</b><span>en la nube</span></div></div>
  <h1>Entra a tu negocio</h1>
  <p class="muted">Tus ventas, comprobantes y clientes desde cualquier lugar. Usa el usuario web que creaste en el programa (Nube y respaldos › Acceso web).</p>
  <?php if ($error): ?><div class="aviso t-r" role="alert"><?= e($error) ?></div><?php endif; ?>
  <form method="post" action="./?v=login" class="form">
    <?= web_campo_csrf() ?>
    <label>Usuario o correo<input name="usuario" autocomplete="username" required autofocus value="<?= e($_POST['usuario'] ?? '') ?>"></label>
    <label>Contraseña<input name="clave" type="password" autocomplete="current-password" required></label>
    <button class="btn btn-primario btn-lg">Entrar</button>
  </form>
  <p class="login-pie muted">¿Olvidaste tu contraseña? Cámbiala desde el programa de escritorio o pide ayuda a soporte.</p>
</main>
</body></html>
