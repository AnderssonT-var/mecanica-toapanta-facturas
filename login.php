<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
fx_config();
cabeceras_seguridad();
iniciar_sesion();
if (admin_actual()) redirigir('panel.php');

const MAX_INTENTOS = 8;
$error = '';
$ip = ip_cliente();

function intentos_recientes(string $ip): int {
  $r = db()->prepare("SELECT COUNT(*) FROM intentos WHERE ip = ? AND tipo = 'login' AND creado > ?");
  $r->execute([$ip, date('Y-m-d H:i:s', time() - 900)]);
  return (int)$r->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verificar_csrf();
  if (intentos_recientes($ip) >= MAX_INTENTOS) {
    $error = 'Demasiados intentos fallidos. Espera 15 minutos.';
  } else {
    $r = db()->prepare('SELECT * FROM admins WHERE usuario = ? AND activo = 1');
    $r->execute([trim((string)($_POST['usuario'] ?? ''))]);
    $a = $r->fetch();
    if ($a && password_verify((string)($_POST['clave'] ?? ''), $a['hash'])) {
      session_regenerate_id(true);
      $_SESSION['admin_id'] = (int)$a['id'];
      $_SESSION['ultimo'] = time();
      if (password_needs_rehash($a['hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['clave'], PASSWORD_DEFAULT), $a['id']]);
      }
      db()->prepare('UPDATE admins SET ultimo_acceso = ? WHERE id = ?')->execute([ahora(), $a['id']]);
      db()->prepare("DELETE FROM intentos WHERE ip = ? AND tipo = 'login'")->execute([$ip]);
      registrar_evento(null, 'LOGIN', 'Ingreso de ' . $a['usuario']);
      redirigir('panel.php');
    }
    db()->prepare("INSERT INTO intentos (ip, tipo, creado) VALUES (?, 'login', ?)")->execute([$ip, date('Y-m-d H:i:s')]);
    $error = 'Usuario o contraseña incorrectos.';
  }
}
$marca = fx_config()['marca'] ?? 'Facturador';
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ingresar · Licencias <?= e($marca) ?></title><link rel="stylesheet" href="assets/estilos.css?v=<?= FX_VERSION ?>"></head>
<body class="centro"><main class="caja angosta">
<div class="marca-grande"><span class="logo">FX</span><div><h1>Licencias</h1><p class="muted"><?= e($marca) ?> · panel de administración</p></div></div>
<?php if ($error): ?><div class="aviso t-r" role="alert"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="form">
  <?= campo_csrf() ?>
  <label>Usuario<input name="usuario" autocomplete="username" required autofocus></label>
  <label>Contraseña<input type="password" name="clave" autocomplete="current-password" required></label>
  <button class="btn btn-dark btn-lg">Ingresar</button>
</form>
</main></body></html>
