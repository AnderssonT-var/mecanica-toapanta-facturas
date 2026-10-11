<?php
// Base del panel de administración: acceso con contraseña, plantilla y utilidades.
$config = require dirname(__DIR__) . '/config.php';
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/app.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

const ADMIN_INACTIVIDAD = 7200;     // 2 horas sin uso cierra la sesión
const ADMIN_MAX_INTENTOS = 5;       // intentos fallidos antes de bloquear
const ADMIN_BLOQUEO_MIN = 15;       // minutos de bloqueo

$pdo = db($config);
app_schema($pdo);

function admin_hash_actual(PDO $pdo): string {
    $st = $pdo->query("SELECT svalue FROM site_settings WHERE skey = 'admin_password_hash'");
    $guardado = $st ? $st->fetchColumn() : false;
    if ($guardado) return (string)$guardado;
    $inicial = require dirname(__DIR__) . '/lib/admin_secret.php';
    return (string)($inicial['password_hash'] ?? '');
}

function admin_bloqueado(PDO $pdo): int {
    // El tiempo restante se calcula en la base de datos para no depender de zonas horarias.
    $st = $pdo->prepare('SELECT attempts, TIMESTAMPDIFF(SECOND, NOW(), last_at + INTERVAL '
        . ADMIN_BLOQUEO_MIN . ' MINUTE) AS restante FROM admin_login_attempts WHERE ip_hash = :ip');
    $st->execute(['ip' => ip_hash()]);
    $fila = $st->fetch();
    if (!$fila || (int)$fila['attempts'] < ADMIN_MAX_INTENTOS) return 0;
    return max(0, (int)ceil((int)$fila['restante'] / 60));
}

function admin_intento(PDO $pdo, bool $exito): void {
    if ($exito) {
        $pdo->prepare('DELETE FROM admin_login_attempts WHERE ip_hash = :ip')->execute(['ip' => ip_hash()]);
        return;
    }
    $st = $pdo->prepare('INSERT INTO admin_login_attempts (ip_hash, attempts, last_at) VALUES (:ip, 1, NOW())
        ON DUPLICATE KEY UPDATE attempts = IF(last_at < NOW() - INTERVAL ' . ADMIN_BLOQUEO_MIN . ' MINUTE, 1, attempts + 1), last_at = NOW()');
    $st->execute(['ip' => ip_hash()]);
}

function admin_logueado(): bool {
    if (empty($_SESSION['admin_desde'])) return false;
    if (time() - (int)($_SESSION['admin_uso'] ?? 0) > ADMIN_INACTIVIDAD) {
        unset($_SESSION['admin_desde'], $_SESSION['admin_uso']);
        return false;
    }
    $_SESSION['admin_uso'] = time();
    return true;
}

function requiere_admin(): void {
    if (!admin_logueado()) {
        header('Location: index.php');
        exit;
    }
}

function requiere_post_valido(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valido()) {
        http_response_code(400);
        exit('Solicitud no válida. Vuelve atrás y recarga la página.');
    }
}

function flash(string $texto, string $tipo = 'ok'): void {
    $_SESSION['admin_flash'] = [$texto, $tipo];
}

function sugerencias_sin_leer(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM feedback WHERE is_read = 0')->fetchColumn();
}

function admin_inicio(PDO $pdo, string $titulo, string $actual): void {
    $sinLeer = sugerencias_sin_leer($pdo);
    $menu = [
        'index' => ['Resumen', 'index.php'],
        'clientes' => ['Clientes y facturas', 'clientes.php'],
        'sugerencias' => ['Reseñas y sugerencias', 'sugerencias.php'],
        'configuracion' => ['Configuración', 'configuracion.php'],
        'limpiar' => ['Limpiar base de datos', 'limpiar.php'],
    ];
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($titulo) ?> | Admin</title>
<link rel="icon" href="../assets/icono.png">
<link rel="stylesheet" href="../assets/app.css?v=2">
</head>
<body class="admin">
<header class="admin-barra">
<div class="admin-barra-dentro">
<div class="admin-marca"><span class="brand-mark">MTV</span> Panel del taller</div>
<nav class="admin-nav" aria-label="Secciones">
<?php foreach ($menu as $clave => [$texto, $url]): ?>
<a href="<?= $url ?>"<?= $clave === $actual ? ' aria-current="page"' : '' ?>><?= e($texto) ?><?php if ($clave === 'sugerencias' && $sinLeer): ?> <span class="contador"><?= $sinLeer ?></span><?php endif; ?></a>
<?php endforeach; ?>
<a href="../" target="_blank" rel="noopener">Ver sitio</a>
<a href="salir.php">Salir</a>
</nav>
</div>
</header>
<main class="admin-main">
<?php
    if (!empty($_SESSION['admin_flash'])) {
        [$texto, $tipo] = $_SESSION['admin_flash'];
        unset($_SESSION['admin_flash']);
        echo '<p class="alerta alerta-' . ($tipo === 'error' ? 'error' : 'ok') . '" role="status">' . e($texto) . '</p>';
    }
}

function admin_fin(): void {
    echo "</main>\n</body>\n</html>\n";
}

/**
 * Borra facturas (archivos y registros). $filtro es SQL fijo del código, nunca del usuario.
 * Devuelve cuántas facturas se borraron.
 */
function borrar_facturas(PDO $pdo, array $config, string $filtro, array $parametros): int {
    $st = $pdo->prepare('SELECT id, storage_name FROM invoices WHERE ' . $filtro);
    $st->execute($parametros);
    $facturas = $st->fetchAll();
    if (!$facturas) return 0;
    $ids = array_map(fn($f) => (int)$f['id'], $facturas);
    $pdo->exec('DELETE FROM invoices WHERE id IN (' . implode(',', $ids) . ')');
    $almacen = rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach ($facturas as $f) {
        if (preg_match('/^[a-f0-9]{64}$/', $f['storage_name']) && is_file($almacen . $f['storage_name'])) {
            @unlink($almacen . $f['storage_name']);
        }
    }
    return count($facturas);
}

/** Quita clientes sin facturas y sus enlaces. */
function limpiar_clientes_huerfanos(PDO $pdo): void {
    $pdo->exec('DELETE l FROM portal_access_links l LEFT JOIN invoices i ON i.client_id = l.client_id WHERE i.id IS NULL');
    $pdo->exec('DELETE c FROM clients c LEFT JOIN invoices i ON i.client_id = c.id WHERE i.id IS NULL');
}
