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
    // Hora de Ecuador también en MySQL (sin horario de verano), para que NOW() coincida con PHP.
    $pdo->exec("SET time_zone = '-05:00'");
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
    // Sesiones recordadas del panel (para usar "Facturar" desde el celular sin entrar cada vez)
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_tokens (
        token_hash CHAR(64) NOT NULL PRIMARY KEY,
        expires_at DATETIME NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) $opciones");
    // Copia de los clientes de FactuSYS (la envía el programa de la PC) para autocompletar
    $pdo->exec("CREATE TABLE IF NOT EXISTS fs_clientes (
        perfil TINYINT UNSIGNED NOT NULL,
        ruc VARCHAR(20) NOT NULL,
        codigo VARCHAR(20) NOT NULL DEFAULT '',
        nombre VARCHAR(200) NOT NULL DEFAULT '',
        direccion VARCHAR(255) NOT NULL DEFAULT '',
        telefono VARCHAR(40) NOT NULL DEFAULT '',
        email VARCHAR(160) NOT NULL DEFAULT '',
        PRIMARY KEY (perfil, ruc)
    ) $opciones");
    // Facturas pedidas desde el celular, en espera de que la PC las haga en FactuSYS
    $pdo->exec("CREATE TABLE IF NOT EXISTS fs_cola (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        perfil TINYINT UNSIGNED NOT NULL,
        ruc VARCHAR(20) NOT NULL DEFAULT '',
        nombre VARCHAR(200) NOT NULL,
        direccion VARCHAR(255) NOT NULL DEFAULT '',
        telefono VARCHAR(40) NOT NULL DEFAULT '',
        email VARCHAR(160) NOT NULL DEFAULT '',
        cliente_nuevo TINYINT(1) NOT NULL DEFAULT 0,
        detalle VARCHAR(300) NOT NULL,
        total DECIMAL(12,2) NOT NULL,
        base DECIMAL(12,2) NOT NULL,
        iva DECIMAL(12,2) NOT NULL,
        con_iva TINYINT(1) NOT NULL DEFAULT 0,
        origen VARCHAR(10) NOT NULL DEFAULT 'nuevo',
        estado VARCHAR(20) NOT NULL DEFAULT 'cola',
        numero VARCHAR(20) NOT NULL DEFAULT '',
        autorizacion VARCHAR(60) NOT NULL DEFAULT '',
        mensaje VARCHAR(400) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        tomada_at DATETIME NULL,
        KEY idx_cola_estado (estado, id)
    ) $opciones");
    // tablas creadas antes de que existiera la opción con/sin IVA
    if (!$pdo->query("SHOW COLUMNS FROM fs_cola LIKE 'con_iva'")->fetch()) {
        $pdo->exec("ALTER TABLE fs_cola ADD con_iva TINYINT(1) NOT NULL DEFAULT 0 AFTER iva");
    }
    // de dónde salieron los datos del cliente: factusys (registrado), sri (consulta) o nuevo (escritos a mano)
    if (!$pdo->query("SHOW COLUMNS FROM fs_cola LIKE 'origen'")->fetch()) {
        $pdo->exec("ALTER TABLE fs_cola ADD origen VARCHAR(10) NOT NULL DEFAULT 'nuevo' AFTER con_iva");
    }
    // Teléfonos autorizados como «pantalla del cliente» (se emparejan con un código de 6 dígitos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS pantalla_disp (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        token_hash CHAR(64) NOT NULL,
        codigo CHAR(6) NOT NULL,
        nombre VARCHAR(60) NOT NULL DEFAULT '',
        aprobado TINYINT(1) NOT NULL DEFAULT 0,
        creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        visto DATETIME NULL,
        UNIQUE KEY uq_pantalla_token (token_hash),
        KEY idx_pantalla_codigo (codigo)
    ) $opciones");
}

// ---------------------------------------------------------------------------
// Pantalla del cliente: lo que se está facturando, para que el cliente lo revise
// ---------------------------------------------------------------------------

const PANTALLA_CADUCA_SEG = 900;    // datos de un cliente sin cambios por 15 min: la pantalla se limpia
const PANTALLA_FASES = ['vacio', 'llenando', 'revisar', 'gracias'];

function pantalla_leer(PDO $pdo): array {
    $st = $pdo->query("SELECT svalue FROM site_settings WHERE skey = 'pantalla_estado'");
    $e = $st ? json_decode((string)$st->fetchColumn(), true) : null;
    if (!is_array($e)) $e = [];
    $e += ['v' => 0, 'fase' => 'vacio', 'datos' => [], 'resp' => '', 't' => 0];
    if ($e['fase'] !== 'vacio' && time() - (int)$e['t'] > PANTALLA_CADUCA_SEG) {
        $e['fase'] = 'vacio';
        $e['datos'] = [];
    }
    return $e;
}

function pantalla_guardar(PDO $pdo, array $e): void {
    $e['t'] = time();
    guardar_ajuste($pdo, 'pantalla_estado', json_encode($e, JSON_UNESCAPED_UNICODE));
}

/** Pone en la pantalla del cliente una fase y sus datos. Si los datos o la fase cambian,
 *  la respuesta anterior del cliente se borra (debe revisar otra vez). Devuelve el estado. */
function pantalla_poner(PDO $pdo, string $fase, array $entrada): array {
    if (!in_array($fase, PANTALLA_FASES, true)) throw new InvalidArgumentException('fase');
    $limites = ['nombre' => 200, 'ruc' => 13, 'direccion' => 255, 'telefono' => 10, 'email' => 160,
        'detalle' => 300, 'base' => 14, 'iva' => 14, 'iva_txt' => 20, 'total' => 14];
    $datos = [];
    if ($fase !== 'vacio') {
        foreach ($limites as $k => $max) {
            $datos[$k] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($entrada[$k] ?? ''))), 0, $max);
        }
    }
    $e = pantalla_leer($pdo);
    if (!($e['fase'] === $fase && $e['datos'] == $datos)) {
        $e = ['v' => (int)$e['v'] + 1, 'fase' => $fase, 'datos' => $datos, 'resp' => ''];
    }
    pantalla_guardar($pdo, $e);
    return $e;
}

// Cartel de la pantalla del cliente (cuando no hay una factura en curso): la hora y los
// mensajes se turnan, cada uno con su estilo (neón o LED) y su color.
const PANTALLA_CARTEL_ESTILOS = ['neon' => 'Neón', 'led' => 'Letrero LED'];
const PANTALLA_CARTEL_VELOCIDADES = ['lenta' => 'Lenta', 'normal' => 'Normal', 'rapida' => 'Rápida'];
const PANTALLA_COLORES_VIEJOS = ['rosa' => '#ff2bd6', 'azul' => '#29d9ff', 'verde' => '#39ff7a', 'ambar' => '#ffb020',
    'rojo' => '#ff3b3b', 'blanco' => '#f4f4f4'];
const PANTALLA_CARTEL_DEFECTO = [
    'hora' => ['activa' => true, 'estilo' => 'led', 'color' => '#29d9ff', 'segundos' => 10, 'formato' => '12', 'fecha' => true],
    'mensajes' => [
        ['texto' => 'BIENVENIDOS', 'color' => '#ff2bd6'],
        ['texto' => 'MECÁNICA TOAPANTA VÉLEZ', 'color' => '#ffb020'],
        ['texto' => 'MANTENIMIENTO Y REPUESTOS', 'color' => '#39ff7a'],
        ['texto' => 'GRACIAS POR SU PREFERENCIA', 'color' => '#29d9ff'],
    ],
    'estilo' => 'neon', 'segundos_mensaje' => 5, 'velocidad' => 'normal', 'fondo' => '#050506',
];

function pantalla_color(string $c, string $defecto): string {
    $c = strtolower(trim($c));
    if (isset(PANTALLA_COLORES_VIEJOS[$c])) return PANTALLA_COLORES_VIEJOS[$c];
    return preg_match('/^#[0-9a-f]{6}$/', $c) ? $c : $defecto;
}

/** Configuración del cartel, siempre completa y válida (acepta el formato anterior). */
function pantalla_cartel(PDO $pdo): array {
    $st = $pdo->query("SELECT svalue FROM site_settings WHERE skey = 'pantalla_cartel'");
    $c = $st ? json_decode((string)$st->fetchColumn(), true) : null;
    return pantalla_cartel_normalizar(is_array($c) ? $c : []);
}

function pantalla_cartel_normalizar(array $c): array {
    $d = PANTALLA_CARTEL_DEFECTO;
    $colorViejo = pantalla_color((string)($c['color'] ?? ''), '#ff2bd6');      // formato anterior: un color para todo
    $h = is_array($c['hora'] ?? null) ? $c['hora'] + $d['hora'] : $d['hora'];
    $hora = [
        'activa' => (bool)$h['activa'],
        'estilo' => isset(PANTALLA_CARTEL_ESTILOS[$h['estilo']]) ? $h['estilo'] : 'led',
        'color' => pantalla_color((string)$h['color'], $d['hora']['color']),
        'segundos' => max(3, min(120, (int)$h['segundos'])),
        'formato' => (string)$h['formato'] === '24' ? '24' : '12',
        'fecha' => (bool)$h['fecha'],
    ];
    $mensajes = [];
    $lista = array_key_exists('mensajes', $c) ? (array)$c['mensajes'] : $d['mensajes'];
    foreach ($lista as $m) {
        $texto = is_array($m) ? (string)($m['texto'] ?? '') : (string)$m;
        $texto = mb_substr(trim(preg_replace('/\s+/u', ' ', $texto)), 0, 60);
        if ($texto === '') continue;
        $mensajes[] = ['texto' => $texto, 'color' => pantalla_color(is_array($m) ? (string)($m['color'] ?? '') : '', $colorViejo)];
        if (count($mensajes) >= 12) break;
    }
    return [
        'hora' => $hora,
        'mensajes' => $mensajes,
        'estilo' => isset(PANTALLA_CARTEL_ESTILOS[$c['estilo'] ?? '']) ? $c['estilo'] : 'neon',
        'segundos_mensaje' => max(2, min(60, (int)($c['segundos_mensaje'] ?? 5))),
        'velocidad' => isset(PANTALLA_CARTEL_VELOCIDADES[$c['velocidad'] ?? '']) ? $c['velocidad'] : 'normal',
        'fondo' => pantalla_color((string)($c['fondo'] ?? ''), '#050506'),
    ];
}

function pantalla_activa(PDO $pdo): bool {
    return (bool)$pdo->query('SELECT 1 FROM pantalla_disp WHERE aprobado = 1 AND visto > NOW() - INTERVAL 1 MINUTE LIMIT 1')->fetchColumn();
}

/** El teléfono-pantalla que hace la consulta (por su llave), o null. */
function pantalla_dispositivo(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM pantalla_disp WHERE token_hash = :h');
    $st->execute(['h' => hash('sha256', $token)]);
    return $st->fetch() ?: null;
}

function pantallas_aprobadas(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM pantalla_disp WHERE aprobado = 1')->fetchColumn();
}

// ---------------------------------------------------------------------------
// Facturación desde el celular
// ---------------------------------------------------------------------------

const FS_PERFILES = [
    1 => ['corto' => 'Nancy Vélez', 'largo' => 'VELEZ MOGROVEJO NANCY PREDILECTA'],
    2 => ['corto' => 'Nelson Toapanta', 'largo' => 'TOAPANTA CENTENO NELSON EFRAIN'],
];
const FS_DETALLE_POR_DEFECTO = 'VENTA DE REPUESTOS Y MANTENIMIENTO';
const FS_IVA = 0.15;

const FS_ESTADOS = [
    'cola' => 'En espera de la PC',
    'procesando' => 'La PC está facturando en FactuSYS',
    'registrada' => 'Registrada · pendiente de autorización',
    'autorizada' => 'Autorizada por el SRI',
    'enviada' => 'Enviada por WhatsApp',
    'enviada_pend' => 'Enviada por WhatsApp · autorización del SRI pendiente',
    'sin_envio' => 'Autorizada · sin celular para enviar',
    'error' => 'No se pudo facturar',
    'revisar' => 'Revisar en FactuSYS',
    'cancelada' => 'Cancelada',
];

/** Cédula (10), RUC (13) o pasaporte simple. */
function fs_identificacion_valida(string $id): bool {
    return (bool)preg_match('/^(\d{10}|\d{13})$/', $id);
}

/** Desglose a partir del valor con IVA incluido, igual que "Extraer IVA" de FactuSYS. Sin IVA: todo es base. */
function fs_desglose(float $total, bool $conIva = true): array {
    if (!$conIva) {
        $total = round($total, 2);
        return ['total' => $total, 'base' => $total, 'iva' => 0.0];
    }
    // FactuSYS redondea la base a 2 decimales y luego calcula el IVA; se busca la base
    // cuyo total final coincida exactamente con lo que se quiere cobrar.
    $total = round($total, 2);
    $aprox = round($total / (1 + FS_IVA), 2);
    $mejor = null;
    foreach ([0, -0.01, 0.01, -0.02, 0.02] as $ajuste) {
        $base = round($aprox + $ajuste, 2);
        $iva = round($base * FS_IVA, 2);
        $dif = abs(round($base + $iva, 2) - $total);
        if ($mejor === null || $dif < $mejor['dif'] - 0.0001) {
            $mejor = ['base' => $base, 'iva' => $iva, 'dif' => $dif];
        }
        if ($dif < 0.0001) break;
    }
    return ['total' => round($mejor['base'] + $mejor['iva'], 2), 'base' => $mejor['base'], 'iva' => $mejor['iva']];
}

/**
 * Consulta pública del SRI (catastro de RUC) para traer nombre y dirección de un cliente nuevo.
 * Solo funciona con RUC (13 dígitos) o cédulas que tienen RUC (se prueba cédula + 001).
 * Si el SRI no responde, devuelve null y se escribe a mano.
 */
function fs_consulta_sri(string $id): ?array {
    if (!function_exists('curl_init')) return null;
    $rucs = strlen($id) === 13 ? [$id] : (strlen($id) === 10 ? [$id . '001'] : []);
    $base = 'https://srienlinea.sri.gob.ec/sri-catastro-sujeto-servicio-internet/rest/';
    $pedir = function (string $url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER => ['Accept: application/json'], CURLOPT_USERAGENT => 'Mozilla/5.0']);
        $r = curl_exec($ch);
        $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($r !== false && $codigo === 200) ? json_decode((string)$r, true) : null;
    };
    foreach ($rucs as $ruc) {
        $datos = $pedir($base . 'ConsolidadoContribuyente/obtenerPorNumerosRuc?&ruc=' . rawurlencode($ruc));
        $fila = is_array($datos) ? ($datos[0] ?? $datos) : null;
        $nombre = is_array($fila) ? trim((string)($fila['razonSocial'] ?? '')) : '';
        if ($nombre === '') continue;
        $direccion = '';
        $est = $pedir($base . 'Establecimiento/consultarPorNumeroRuc?numeroRuc=' . rawurlencode($ruc));
        if (is_array($est)) {
            foreach ($est as $e) {
                if (!is_array($e)) continue;
                if ($direccion === '' || ($e['matriz'] ?? '') === 'SI') $direccion = trim((string)($e['direccionCompleta'] ?? ''));
            }
        }
        return ['nombre' => mb_substr($nombre, 0, 200), 'direccion' => mb_substr($direccion, 0, 255)];
    }
    return null;
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
<link rel="stylesheet" href="assets/app.css?v=3">
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
<span class="credito">Desarrollado por <a href="https://anderssont-var.github.io/AnderssonT-var.github/#inicio" target="_blank" rel="noopener">A.T.</a></span>
</footer>
</main>
</body>
</html>
<?php
}
