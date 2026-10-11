<?php
// Funciones compartidas del portal: tablas nuevas, ajustes, fechas en español,
// protección CSRF y la plantilla común (cabecera y pie) de las páginas públicas.
// Se carga después de config.php y lib/bootstrap.php.

const APP_NOMBRE_POR_DEFECTO = 'Mecánica Toapanta Vélez';

// Fechas de la página en hora de Ecuador ("Nueva", "Vigente", año actual).
date_default_timezone_set('America/Guayaquil');

/** Crea las tablas nuevas si no existen (sucursales, sugerencias, ajustes, intentos de acceso). */
function app_schema(PDO $pdo): void {
    static $hecho = false;
    if ($hecho) return;
    $hecho = true;
    $opciones = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_settings (
        skey VARCHAR(64) NOT NULL PRIMARY KEY,
        svalue TEXT NOT NULL
    ) $opciones");
    $pdo->exec("CREATE TABLE IF NOT EXISTS branches (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        address VARCHAR(255) NOT NULL DEFAULT '',
        maps_url VARCHAR(500) NOT NULL DEFAULT '',
        phone VARCHAR(20) NOT NULL DEFAULT '',
        hours VARCHAR(160) NOT NULL DEFAULT '',
        sort_order INT NOT NULL DEFAULT 0
    ) $opciones");
    $pdo->exec("CREATE TABLE IF NOT EXISTS feedback (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(20) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        ip_hash CHAR(64) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_feedback_created (created_at)
    ) $opciones");
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        ip_hash CHAR(64) NOT NULL PRIMARY KEY,
        attempts INT NOT NULL DEFAULT 0,
        last_at DATETIME NOT NULL
    ) $opciones");
}

/** Ajustes del sitio (WhatsApp, enlace de reseñas, nombre). */
function ajustes(array $config, bool $recargar = false): array {
    static $cache = null;
    if ($cache !== null && !$recargar) return $cache;
    $base = [
        'negocio' => APP_NOMBRE_POR_DEFECTO,
        'whatsapp' => '',
        'google_review_url' => '',
    ];
    try {
        $pdo = db($config);
        app_schema($pdo);
        foreach ($pdo->query('SELECT skey, svalue FROM site_settings') as $fila) {
            $base[$fila['skey']] = $fila['svalue'];
        }
    } catch (Throwable $e) {
        // Si la base no responde, la página igual se muestra con los valores por defecto.
    }
    return $cache = $base;
}

function guardar_ajuste(PDO $pdo, string $clave, string $valor): void {
    $st = $pdo->prepare('INSERT INTO site_settings (skey, svalue) VALUES (:k, :v)
                         ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)');
    $st->execute(['k' => $clave, 'v' => $valor]);
}

function sucursales(array $config): array {
    try {
        $pdo = db($config);
        app_schema($pdo);
        return $pdo->query('SELECT * FROM branches ORDER BY sort_order, id')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// ---------------------------------------------------------------------------
// Formatos
// ---------------------------------------------------------------------------

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio',
               'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

/** '2026-10-08' -> '8 de octubre de 2026' */
function fecha_larga(string $ymd): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    if (!$d) return $ymd;
    return (int)$d->format('j') . ' de ' . MESES[(int)$d->format('n') - 1] . ' de ' . $d->format('Y');
}

/** '2026-11-07' -> '7 de noviembre' (agrega el año si no es el actual) */
function fecha_corta(string $ymd): string {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($ymd, 0, 10));
    if (!$d) return $ymd;
    $texto = (int)$d->format('j') . ' de ' . MESES[(int)$d->format('n') - 1];
    return $d->format('Y') === date('Y') ? $texto : $texto . ' de ' . $d->format('Y');
}

/** '593959133066' -> '0959 133 066' */
function telefono_local(string $telefono): string {
    if (preg_match('/^593(9\d{2})(\d{3})(\d{3})$/', $telefono, $m)) {
        return '0' . $m[1] . ' ' . $m[2] . ' ' . $m[3];
    }
    return $telefono;
}

/** Enlace wa.me a partir de un número escrito de cualquier forma; '' si no es válido. */
function enlace_whatsapp(string $numero, string $texto = ''): string {
    $tel = normalize_phone($numero);
    if (!preg_match('/^593\d{8,9}$/', $tel)) return '';
    return 'https://wa.me/' . $tel . ($texto !== '' ? '?text=' . rawurlencode($texto) : '');
}

/** Solo acepta enlaces https (evita javascript: y similares). */
function url_segura(string $url): string {
    $url = trim($url);
    return (filter_var($url, FILTER_VALIDATE_URL) && str_starts_with(strtolower($url), 'https://')) ? $url : '';
}

function tamano_legible(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    return max(1, (int)round($bytes / 1024)) . ' KB';
}

function ip_hash(): string {
    return hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|toapanta');
}

// ---------------------------------------------------------------------------
// CSRF
// ---------------------------------------------------------------------------

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_campo(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_valido(): bool {
    $enviado = (string)($_POST['csrf'] ?? '');
    return $enviado !== '' && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $enviado);
}

// ---------------------------------------------------------------------------
// Plantilla pública
// ---------------------------------------------------------------------------

function icono(string $nombre, int $tam = 20): string {
    $trazos = [
        'whatsapp' => '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 20.5l1.6-5.2A8.5 8.5 0 1 1 21 11.5z"/>',
        'ver' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
        'descargar' => '<path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/>',
        'compartir' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4"/><path d="M15.4 6.5l-6.8 4"/>',
        'reloj' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'candado' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'mapa' => '<path d="M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/>',
        'mensaje' => '<path d="M4 5h16v11H8l-4 4z"/>',
        'estrella' => '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z"/>',
    ];
    return '<svg class="icono" width="' . $tam . '" height="' . $tam . '" viewBox="0 0 24 24" fill="none" '
        . 'stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($trazos[$nombre] ?? '') . '</svg>';
}

function pagina_inicio(array $config, string $titulo, string $subtitulo): void {
    $a = ajustes($config);
    $negocio = $a['negocio'] ?: APP_NOMBRE_POR_DEFECTO;
    $base = rtrim((string)($config['portal_url'] ?? ''), '/');
    $og = $base !== '' ? $base . '/assets/og.png' : 'assets/og.png';
    $iniciales = implode('', array_map(fn($p) => mb_substr($p, 0, 1),
        array_slice(array_filter(preg_split('/\s+/', preg_replace('/^Mec[aá]nica\s+/iu', 'M ', $negocio))), 0, 3)));
    ?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulo) ?> | <?= e($negocio) ?></title>
<meta name="description" content="Mira, descarga o comparte tu factura de forma segura.">
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($negocio) ?>">
<meta property="og:title" content="Tu factura de <?= e($negocio) ?>">
<meta property="og:description" content="Mírala, descárgala o compártela de forma segura.">
<meta property="og:image" content="<?= e($og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="theme-color" content="#0d5c63">
<link rel="icon" href="assets/icono.png">
<link rel="stylesheet" href="assets/app.css?v=2">
</head>
<body>
<main class="shell">
<header class="brand">
<div class="brand-mark" aria-hidden="true"><?= e(mb_strtoupper($iniciales ?: 'MTV')) ?></div>
<div><div class="brand-name"><?= e($negocio) ?></div><div class="muted small"><?= e($subtitulo) ?></div></div>
</header>
<?php
}

function bloque_contacto(array $config, string $titulo = '¿Dudas? Escríbenos por WhatsApp'): void {
    $a = ajustes($config);
    $wa = enlace_whatsapp($a['whatsapp'], 'Hola, tengo una consulta sobre mi factura.');
    if ($wa === '') return;
    ?>
<a class="boton boton-whatsapp" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icono('whatsapp', 22) ?><?= e($titulo) ?></a>
<?php
}

function pagina_fin(array $config): void {
    $lista = sucursales($config);
    ?>
<?php if ($lista): ?>
<section class="sucursales" aria-label="Dónde estamos">
<h2 class="titulo-seccion"><?= count($lista) > 1 ? 'Nuestras sucursales' : 'Dónde estamos' ?></h2>
<?php foreach ($lista as $s): $mapa = url_segura($s['maps_url']); $tel = enlace_whatsapp($s['phone']); ?>
<article class="sucursal">
<div class="sucursal-datos">
<strong><?= e($s['name']) ?></strong>
<?php if ($s['address'] !== ''): ?><span><?= e($s['address']) ?></span><?php endif; ?>
<?php if ($s['hours'] !== ''): ?><span class="muted small"><?= e($s['hours']) ?></span><?php endif; ?>
</div>
<div class="sucursal-acciones">
<?php if ($mapa): ?><a class="boton boton-claro" href="<?= e($mapa) ?>" target="_blank" rel="noopener"><?= icono('mapa') ?>Cómo llegar</a><?php endif; ?>
<?php if ($tel): ?><a class="boton boton-claro" href="<?= e($tel) ?>" target="_blank" rel="noopener"><?= icono('whatsapp') ?><?= e(telefono_local(normalize_phone($s['phone']))) ?></a><?php endif; ?>
</div>
</article>
<?php endforeach; ?>
</section>
<?php endif; ?>
<footer class="pie">
<a href="sugerencias.php"><?= icono('mensaje', 16) ?> Déjanos una sugerencia</a>
<span>Por privacidad, cada factura se retira 30 días después de emitida.</span>
</footer>
</main>
</body>
</html>
<?php
}
