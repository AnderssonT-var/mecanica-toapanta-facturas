<?php
// Instalador del portal de licencias. Se ejecuta una sola vez: crea las tablas, el administrador
// y el archivo config.php. Si config.php ya existe, no hace nada.
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/esquema.php';
date_default_timezone_set('America/Guayaquil');

const FX_CONFIG = __DIR__ . '/config.php';
// Código de instalación de un solo uso (solo se guarda su SHA-256). Vacío = sin código.
const FX_CODIGO_INSTALACION = '';
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$errores = [];
$listo = null;
$v = fn($k, $d = '') => trim((string)($_POST[$k] ?? $d));

if (is_file(FX_CONFIG)) {
  $listo = 'ya';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $driver = $v('driver', 'mysql') === 'sqlite' && getenv('FX_PERMITIR_SQLITE') ? 'sqlite' : 'mysql';
  $host = $v('db_host', 'localhost');
  if ($driver === 'mysql' && !in_array($host, ['localhost', '127.0.0.1'], true)) $errores[] = 'Por seguridad, el servidor de base de datos debe ser localhost (así lo usa Hostinger).';
  if ($driver === 'mysql' && ($v('db_nombre') === '' || $v('db_usuario') === '')) $errores[] = 'Completa el nombre y el usuario de la base de datos.';
  if (!preg_match('/^[a-z0-9_.-]{3,40}$/i', $v('usuario'))) $errores[] = 'El usuario administrador debe tener entre 3 y 40 letras, números, puntos o guiones.';
  if (strlen((string)($_POST['clave'] ?? '')) < 10) $errores[] = 'La contraseña del administrador debe tener al menos 10 caracteres.';
  if (($_POST['clave'] ?? '') !== ($_POST['clave2'] ?? '')) $errores[] = 'Las contraseñas no coinciden.';
  if (FX_CODIGO_INSTALACION !== '' && !hash_equals(FX_CODIGO_INSTALACION, hash('sha256', strtoupper($v('codigo'))))) $errores[] = 'El código de instalación no es correcto.';
  if (!function_exists('sodium_crypto_sign_seed_keypair')) $errores[] = 'Tu PHP no tiene la extensión sodium. En Hostinger: hPanel › Avanzado › Configuración de PHP › activa "sodium".';

  if (!$errores) {
    $db = $driver === 'sqlite'
      ? ['driver' => 'sqlite', 'ruta' => $v('db_ruta', __DIR__ . '/datos.sqlite')]
      : ['driver' => 'mysql', 'host' => $host, 'puerto' => 3306, 'nombre' => $v('db_nombre'), 'usuario' => $v('db_usuario'), 'clave' => (string)($_POST['db_clave'] ?? '')];
    try {
      $dsn = $driver === 'sqlite' ? 'sqlite:' . $db['ruta'] : 'mysql:host=' . $db['host'] . ';dbname=' . $db['nombre'] . ';charset=utf8mb4';
      $pdo = new PDO($dsn, $db['usuario'] ?? null, $db['clave'] ?? null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
      crear_esquema($pdo, $driver);
      $existe = (int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
      if ($existe) throw new RuntimeException('La base de datos ya tiene administradores. Usa una base de datos vacía o restaura config.php.');

      // Llave de firma: la incluida en el paquete (si existe) o una nueva.
      $incluida = __DIR__ . '/llave-incluida.php';
      $semilla = is_file($incluida) ? base64_decode((string)require $incluida, true) : random_bytes(SODIUM_CRYPTO_SIGN_SEEDBYTES);
      if ($semilla === false || strlen($semilla) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) throw new RuntimeException('La llave incluida está dañada.');

      $ahora = date('Y-m-d H:i:s');
      $pdo->prepare('INSERT INTO admins (usuario, nombre, email, hash, activo, creado) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([$v('usuario'), $v('nombre'), $v('email'), password_hash((string)$_POST['clave'], PASSWORD_DEFAULT), $ahora]);
      foreach (['dias_sin_conexion' => '15', 'equipos_defecto' => '1', 'gracia_defecto' => '3', 'whatsapp' => $v('whatsapp')] as $k => $val) {
        $pdo->prepare('INSERT INTO ajustes (k, v) VALUES (?, ?)')->execute([$k, $val]);
      }

      $config = [
        'db' => $db,
        'llave_privada' => base64_encode($semilla),
        'marca' => $v('marca', 'Facturador') ?: 'Facturador',
        'instalado' => $ahora,
      ];
      $php = "<?php\n// Configuración del portal de licencias. NO compartas este archivo: contiene la llave privada de firma.\nif (!defined('FX_PORTAL')) { http_response_code(403); exit; }\nreturn " . var_export($config, true) . ";\n";
      if (file_put_contents(FX_CONFIG, $php, LOCK_EX) === false) throw new RuntimeException('No se pudo escribir config.php. Revisa los permisos de la carpeta.');
      @chmod(FX_CONFIG, 0600);
      if (is_file($incluida)) @unlink($incluida);
      $pk = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($semilla));
      $listo = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin('302a300506032b6570032100') . $pk), 64, "\n") . "-----END PUBLIC KEY-----";
    } catch (Throwable $ex) {
      $errores[] = $ex instanceof PDOException ? 'No se pudo conectar o crear las tablas: ' . $ex->getMessage() : $ex->getMessage();
    }
  }
}
?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Instalar portal de licencias</title><link rel="stylesheet" href="assets/estilos.css"></head>
<body class="centro"><main class="caja">
<div class="marca-grande"><span class="logo">FX</span><div><h1>Portal de licencias</h1><p class="muted">Instalación inicial</p></div></div>
<?php if ($listo === 'ya'): ?>
  <div class="aviso t-a">El portal ya está instalado. Por seguridad, elimina el archivo <b>instalar.php</b> de tu hosting.</div>
  <a class="btn btn-dark" href="login.php">Ir al inicio de sesión</a>
<?php elseif ($listo): ?>
  <div class="aviso t-g">¡Listo! El portal quedó instalado.</div>
  <p><b>1.</b> Elimina el archivo <b>instalar.php</b> desde el Administrador de archivos de Hostinger.</p>
  <p><b>2.</b> Esta es la llave pública que debe tener el programa de escritorio (también la ves en Ajustes):</p>
  <pre class="codigo"><?= h($listo) ?></pre>
  <a class="btn btn-dark" href="login.php">Ir al inicio de sesión</a>
<?php else: ?>
  <?php foreach ($errores as $er): ?><div class="aviso t-r"><?= h($er) ?></div><?php endforeach; ?>
  <form method="post" class="form" autocomplete="off">
    <h2>Base de datos MySQL</h2>
    <p class="muted">Créala en hPanel › Bases de datos › Administración. Usa una base de datos nueva y vacía.</p>
    <input type="hidden" name="driver" value="<?= getenv('FX_PERMITIR_SQLITE') ? h($v('driver', 'mysql')) : 'mysql' ?>">
    <div class="g2">
      <?php if (FX_CODIGO_INSTALACION !== ''): ?><label>Código de instalación<input name="codigo" required autocomplete="off"></label><?php endif; ?>
      <label>Servidor<input name="db_host" value="<?= h($v('db_host', 'localhost')) ?>"></label>
      <label>Nombre de la base de datos<input name="db_nombre" value="<?= h($v('db_nombre')) ?>" placeholder="u123456789_licencias"></label>
      <label>Usuario<input name="db_usuario" value="<?= h($v('db_usuario')) ?>" placeholder="u123456789_admin"></label>
      <label>Contraseña<input type="password" name="db_clave"></label>
    </div>
    <h2>Administrador</h2>
    <div class="g2">
      <label>Usuario<input name="usuario" value="<?= h($v('usuario', 'admin')) ?>" required></label>
      <label>Nombre<input name="nombre" value="<?= h($v('nombre')) ?>"></label>
      <label>Email<input type="email" name="email" value="<?= h($v('email')) ?>"></label>
      <label>WhatsApp de soporte<input name="whatsapp" value="<?= h($v('whatsapp')) ?>" placeholder="+593 99 999 9999"></label>
      <label>Contraseña (mín. 10)<input type="password" name="clave" required minlength="10"></label>
      <label>Repetir contraseña<input type="password" name="clave2" required minlength="10"></label>
      <label>Nombre del producto<input name="marca" value="<?= h($v('marca', 'Facturador')) ?>"></label>
    </div>
    <button class="btn btn-dark btn-lg">Instalar</button>
  </form>
<?php endif; ?>
</main></body></html>
