<?php
// Núcleo del portal de licencias: configuración, base de datos, sesión, seguridad y utilidades.
declare(strict_types=1);

if (!defined('FX_PORTAL')) { http_response_code(403); exit; }
date_default_timezone_set('America/Guayaquil');

const FX_VERSION = '1.0.8';
const FX_PLANES = [
  'LOCAL' => ['label' => 'Local', 'desc' => 'Programa de escritorio con datos solo en el equipo'],
  'WEB'   => ['label' => 'Web',   'desc' => 'Solo navegador, datos en la nube'],
  'FULL'  => ['label' => 'Full',  'desc' => 'Escritorio + nube + web'],
];

function fx_config(): array {
  static $cfg = null;
  if ($cfg === null) {
    $ruta = __DIR__ . '/../config.php';
    if (!is_file($ruta)) {
      header('Location: instalar.php');
      exit;
    }
    $cfg = require $ruta;
  }
  return $cfg;
}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $c = fx_config()['db'];
    $dsn = $c['driver'] === 'sqlite'
      ? 'sqlite:' . $c['ruta']
      : 'mysql:host=' . $c['host'] . ';port=' . ($c['puerto'] ?? 3306) . ';dbname=' . $c['nombre'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['usuario'] ?? null, $c['clave'] ?? null, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($c['driver'] !== 'sqlite') $pdo->exec("SET time_zone = '-05:00'");
  }
  return $pdo;
}

function ahora(): string {
  return (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d H:i:s');
}

function hoy(): string {
  return (new DateTimeImmutable('now', new DateTimeZone('America/Guayaquil')))->format('Y-m-d');
}

function ip_cliente(): string {
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function e($v): string {
  return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fecha_ec(?string $iso): string {
  if (!$iso) return '—';
  $p = explode('-', substr($iso, 0, 10));
  return count($p) === 3 ? $p[2] . '/' . $p[1] . '/' . $p[0] : $iso;
}

function dias_entre(string $desde, string $hasta): int {
  $a = new DateTimeImmutable($desde);
  $b = new DateTimeImmutable($hasta);
  return (int)$a->diff($b)->format('%r%a');
}

function sumar_meses(string $iso, int $meses): string {
  $d = new DateTimeImmutable($iso);
  $dia = (int)$d->format('d');
  $r = $d->modify('first day of this month')->modify(($meses >= 0 ? '+' : '') . $meses . ' month');
  $ultimo = (int)$r->format('t');
  return $r->setDate((int)$r->format('Y'), (int)$r->format('m'), min($dia, $ultimo))->format('Y-m-d');
}

function ajuste(string $k, $defecto = null) {
  $r = db()->prepare('SELECT v FROM ajustes WHERE k = ?');
  $r->execute([$k]);
  $v = $r->fetchColumn();
  return $v === false ? $defecto : $v;
}

function fijar_ajuste(string $k, string $v): void {
  $d = db();
  $d->prepare('DELETE FROM ajustes WHERE k = ?')->execute([$k]);
  $d->prepare('INSERT INTO ajustes (k, v) VALUES (?, ?)')->execute([$k, $v]);
}

function registrar_evento(?int $licencia, string $tipo, string $detalle = ''): void {
  db()->prepare('INSERT INTO eventos (licencia_id, tipo, detalle, ip, creado) VALUES (?, ?, ?, ?, ?)')
    ->execute([$licencia, $tipo, mb_substr($detalle, 0, 500), ip_cliente(), ahora()]);
}

// ---------- Validaciones Ecuador ----------
function validar_cedula(string $id): bool {
  if (!preg_match('/^\d{10}$/', $id)) return false;
  $p = (int)substr($id, 0, 2);
  if (!(($p >= 1 && $p <= 24) || $p === 30) || (int)$id[2] >= 6) return false;
  $s = 0;
  for ($i = 0; $i < 9; $i++) { $v = (int)$id[$i] * ($i % 2 === 0 ? 2 : 1); if ($v > 9) $v -= 9; $s += $v; }
  return (10 - ($s % 10)) % 10 === (int)$id[9];
}

function mod11(string $id, array $c, int $pos): bool {
  $s = 0;
  foreach ($c as $i => $x) $s += (int)$id[$i] * $x;
  $dv = 11 - ($s % 11);
  if ($dv === 11) $dv = 0;
  return $dv !== 10 && $dv === (int)$id[$pos];
}

// El SRI no valida dígito verificador del RUC (hay RUC válidos de sociedades y entidades públicas que no cumplen
// el módulo 11): se aceptan 13 dígitos con establecimiento distinto de 000. Igual que en el programa de escritorio.
function validar_ruc(string $id): bool {
  return (bool)preg_match('/^\d{13}$/', $id) && substr($id, 10) !== '000' && !preg_match('/^0+$/', substr($id, 0, 10));
}

// ---------- Firma de licencias (Ed25519, compatible con el programa de escritorio) ----------
function b64url(string $b): string {
  return rtrim(strtr(base64_encode($b), '+/', '-_'), '=');
}

function llave_secreta(): string {
  $semilla = base64_decode(fx_config()['llave_privada'] ?? '', true);
  if ($semilla === false || strlen($semilla) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) throw new RuntimeException('Llave de firma no configurada.');
  return sodium_crypto_sign_secretkey(sodium_crypto_sign_seed_keypair($semilla));
}

function llave_publica_pem(): string {
  $semilla = base64_decode(fx_config()['llave_privada'] ?? '', true);
  $pk = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair($semilla));
  $der = hex2bin('302a300506032b6570032100') . $pk;
  return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function firmar_token(array $payload): string {
  $cuerpo = b64url(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  return 'FX1.' . $cuerpo . '.' . b64url(sodium_crypto_sign_detached($cuerpo, llave_secreta()));
}

// Clave corta que se entrega al cliente: FX-XXXX-XXXX-XXXX (sin caracteres ambiguos).
function nueva_clave(): string {
  $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  $g = [];
  for ($i = 0; $i < 3; $i++) {
    $s = '';
    for ($j = 0; $j < 4; $j++) $s .= $alf[random_int(0, strlen($alf) - 1)];
    $g[] = $s;
  }
  return 'FX-' . implode('-', $g);
}

function normalizar_clave(string $c): string {
  $c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $c));
  if (strlen($c) !== 14 || substr($c, 0, 2) !== 'FX') return '';
  return 'FX-' . substr($c, 2, 4) . '-' . substr($c, 6, 4) . '-' . substr($c, 10, 4);
}

// ---------- Estado de una licencia ----------
function estado_licencia(array $l): array {
  if ($l['estado'] === 'SUSPENDIDA') return ['code' => 'SUSP', 'label' => 'Suspendida', 'tono' => 'r'];
  $dias = dias_entre(hoy(), $l['vence']);
  if ($dias < 0 && -$dias > (int)$l['gracia']) return ['code' => 'VENC', 'label' => 'Vencida', 'tono' => 'r', 'dias' => $dias];
  if ($dias < 0) return ['code' => 'GRACIA', 'label' => 'En gracia', 'tono' => 'a', 'dias' => $dias];
  if ($dias <= 7) return ['code' => 'PV', 'label' => 'Por vencer', 'tono' => 'a', 'dias' => $dias];
  return ['code' => 'OK', 'label' => 'Activa', 'tono' => 'g', 'dias' => $dias];
}

function licencia_por_id(int $id): ?array {
  $r = db()->prepare('SELECT * FROM licencias WHERE id = ?');
  $r->execute([$id]);
  $l = $r->fetch();
  return $l ?: null;
}

function codigo_licencia(array $l): string {
  return 'LIC-' . str_pad((string)$l['id'], 6, '0', STR_PAD_LEFT);
}

// Datos que viajan firmados al programa de escritorio.
function payload_licencia(array $l, string $equipo): array {
  $rucs = json_decode((string)$l['rucs'], true) ?: [];
  return [
    'id' => codigo_licencia($l),
    'ruc' => $l['ruc'],
    'rucs' => array_values(array_map('strval', $rucs)),
    'cliente' => $l['cliente'],
    'plan' => $l['plan'],
    'inicio' => $l['inicio'],
    'vence' => $l['vence'],
    'gracia' => (int)$l['gracia'],
    'empresas' => (int)$l['empresas'],
    'equipos' => (int)$l['equipos_max'],
    'usuarios' => (int)$l['usuarios'],
    'puntos' => (int)$l['puntos'],
    'equipo' => $equipo,
    'emitida' => gmdate('Y-m-d\TH:i:s\Z'),
    'fechaServidor' => hoy(),
    'diasSinConexion' => (int)ajuste('dias_sin_conexion', '15'),
  ];
}

// ---------- Sesión y seguridad del panel ----------
function iniciar_sesion(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_name('fxlic');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
}

function cabeceras_seguridad(): void {
  header('X-Frame-Options: DENY');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'");
}

function admin_actual(): ?array {
  iniciar_sesion();
  if (empty($_SESSION['admin_id'])) return null;
  if (time() - ($_SESSION['ultimo'] ?? 0) > 2 * 3600) { $_SESSION = []; session_destroy(); return null; }
  $_SESSION['ultimo'] = time();
  $r = db()->prepare('SELECT id, usuario, nombre, email FROM admins WHERE id = ? AND activo = 1');
  $r->execute([$_SESSION['admin_id']]);
  return $r->fetch() ?: null;
}

function exigir_admin(): array {
  cabeceras_seguridad();
  $a = admin_actual();
  if (!$a) { header('Location: login.php'); exit; }
  return $a;
}

function token_csrf(): string {
  iniciar_sesion();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
  return $_SESSION['csrf'];
}

function campo_csrf(): string {
  return '<input type="hidden" name="csrf" value="' . e(token_csrf()) . '">';
}

function verificar_csrf(): void {
  iniciar_sesion();
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Solicitud no válida. Vuelve a intentarlo.');
  }
}

function aviso(string $msg, string $tono = 'g'): void {
  iniciar_sesion();
  $_SESSION['aviso'] = [$msg, $tono];
}

function redirigir(string $url): void {
  header('Location: ' . $url);
  exit;
}

// ---------- Diseño ----------
function cabecera_html(string $titulo, ?array $admin, string $activo = ''): void {
  iniciar_sesion();
  $aviso = $_SESSION['aviso'] ?? null;
  unset($_SESSION['aviso']);
  $marca = e(fx_config()['marca'] ?? 'Facturador');
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>' . e($titulo) . ' · Licencias ' . $marca . '</title><link rel="stylesheet" href="assets/estilos.css?v=' . FX_VERSION . '"></head><body>';
  if ($admin) {
    $item = fn($k, $url, $t) => '<a href="' . $url . '"' . ($activo === $k ? ' class="on"' : '') . '>' . $t . '</a>';
    echo '<header class="top"><div class="wrap top-in"><a class="marca" href="panel.php"><span class="logo">FX</span><span>Licencias <b>' . $marca . '</b></span></a>';
    echo '<nav>' . $item('panel', 'panel.php', 'Panel') . $item('licencias', 'licencias.php', 'Licencias') . $item('pagos', 'pagos.php', 'Pagos') . $item('eventos', 'eventos.php', 'Actividad') . $item('versiones', 'versiones.php', 'Versiones') . $item('ajustes', 'ajustes.php', 'Ajustes') . '</nav>';
    echo '<form method="post" action="logout.php" class="salir">' . campo_csrf() . '<span>' . e($admin['nombre'] ?: $admin['usuario']) . '</span><button class="btn btn-sm">Salir</button></form></div></header>';
  }
  echo '<main class="wrap">';
  if ($aviso) echo '<div class="aviso t-' . e($aviso[1]) . '" role="status">' . e($aviso[0]) . '</div>';
}

function pie_html(): void {
  echo '</main><footer class="wrap pie">Portal de licencias v' . FX_VERSION . '</footer></body></html>';
}

function insignia(array $estado): string {
  return '<span class="badge t-' . e($estado['tono']) . '">' . e($estado['label']) . '</span>';
}
