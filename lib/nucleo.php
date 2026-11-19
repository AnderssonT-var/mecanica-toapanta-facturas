<?php
// Núcleo del portal de licencias: configuración, base de datos, sesión, seguridad y utilidades.
declare(strict_types=1);

if (!defined('FX_PORTAL')) { http_response_code(403); exit; }
date_default_timezone_set('America/Guayaquil');

const FX_VERSION = '1.20.1';
const FX_MARCA = 'Facmit';

// Nombre del producto. Los portales instalados antes del cambio de marca guardaron el nombre anterior en su
// configuración: se muestra siempre el actual.
function fx_marca(): string {
  $m = trim((string)(fx_config()['marca'] ?? ''));
  return ($m === '' || strcasecmp($m, 'Facturador') === 0) ? FX_MARCA : $m;
}

// Logotipo (F con la flecha de emitir) para la baldosa lima del portal y de la app web.
function fx_logo(int $t = 20): string {
  return '<svg width="' . $t . '" height="' . $t . '" viewBox="8 8 48 48" fill="none" aria-hidden="true"><path d="M20 14v36M20 14h20M20 31h13" stroke="#0F1B2D" stroke-width="5.5" stroke-linecap="round"/><path d="M36 48l12-12M39 35.5h9.5V45" stroke="#0F1B2D" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/></svg>';
}
// Códigos internos que viajan en la licencia (los programas antiguos solo conocen estos tres).
const FX_PLANES = [
  'LOCAL' => ['label' => 'Local', 'desc' => 'Programa de escritorio con datos solo en el equipo'],
  'WEB'   => ['label' => 'Web',   'desc' => 'Solo navegador, datos en la nube'],
  'FULL'  => ['label' => 'Full',  'desc' => 'Escritorio + nube + web'],
];

// ---------- Funciones que se venden por licencia ----------
// Lo básico (facturar, clientes, productos, proformas, comprobantes, RIDE, autorizaciones, anulaciones, correo,
// reportes de ventas e IVA, importar) va siempre. Estas se marcan en cada licencia y el programa solo muestra
// lo marcado. El nombre del plan sale solo de lo que se marque.
const FX_BASICO = 'Facturas, clientes, inventario simple (código, nombre y precio), proformas, comprobantes y RIDE, autorización SRI, anulaciones, envío por correo, reportes de ventas e IVA, ATS, importación desde Excel';
const FX_FUNCIONES = [
  'documentos'  => ['label' => 'Devoluciones, notas de débito y guías', 'desc' => 'Notas de crédito por devolución parcial, notas de débito, guías de remisión y transportistas', 'grupo' => 'pro'],
  'retenciones' => ['label' => 'Retenciones', 'desc' => 'Comprobantes de retención 2.0, proveedores y sustento tributario', 'grupo' => 'pro'],
  'inventario'  => ['label' => 'Inventario completo', 'desc' => 'Stock por producto y por bodega, fotos, código de barras, ingresos, transferencias, ajustes y kardex', 'grupo' => 'pro'],
  'compras'     => ['label' => 'Compras y liquidaciones', 'desc' => 'Registro de compras desde el XML o el SRI, liquidaciones de compra y libro de compras', 'grupo' => 'pro'],
  'cartera'     => ['label' => 'Cuentas por cobrar', 'desc' => 'Ventas a crédito, abonos, retenciones recibidas, cartera vencida y estados de cuenta', 'grupo' => 'pro'],
  'pos'         => ['label' => 'Punto de venta y caja', 'desc' => 'Venta rápida con lector, apertura y cierre de caja con arqueo y tickets de 58/80 mm', 'grupo' => 'pro'],
  'nube'        => ['label' => 'Respaldo en la nube y varios equipos', 'desc' => 'Copia diaria completa en la nube, restaurar en otra PC y la misma información en varias computadoras', 'grupo' => 'extra'],
  'contabilidad'=> ['label' => 'Contabilidad y bancos', 'desc' => 'Plan de cuentas, asientos automáticos, libro diario y mayor, balances y estados financieros; cuentas bancarias, cheques, conciliación y flujo de caja', 'grupo' => 'extra'],
  'movil'       => ['label' => 'Portal web (facturar desde el celular)', 'desc' => 'Facturar y consultar desde el celular o el navegador con los clientes y productos de la PC; la PC firma y autoriza', 'grupo' => 'extra'],
];
const FX_PRO = ['documentos', 'retenciones', 'inventario', 'compras', 'cartera', 'pos'];
// Versión de las funciones: los programas tratan las licencias sin ella como emitidas antes de compras/cobros/caja.
const FX_FUNCIONES_VERSION = 2;

// Funciones de una licencia. Las creadas antes de este cambio no las tienen guardadas: se deducen de su plan
// para no quitarle nada a ningún cliente.
function funciones_licencia(array $l): array {
  $f = json_decode((string)($l['funciones'] ?? ''), true);
  if (!is_array($f)) {
    $f = match ($l['plan'] ?? '') { 'FULL' => array_merge(FX_PRO, ['nube', 'movil']), 'WEB' => ['nube', 'movil'], default => FX_PRO };
  }
  return normalizar_funciones($f);
}

function normalizar_funciones(array $f): array {
  $f = array_values(array_intersect(array_keys(FX_FUNCIONES), array_map('strval', $f)));
  // Desde noviembre de 2026 el portal web y la nube de respaldo son funciones separadas (se cobran por separado).
  return array_values(array_intersect(array_keys(FX_FUNCIONES), $f));
}

// ---------- Funciones sueltas dentro de cada categoría ----------
// Catálogo compartido con el programa (lib/funciones-detalle.json = desktop/src/funciones-detalle.json). Si la
// categoría está en la licencia, sus funciones vienen encendidas salvo las de «funciones_sin».
function fx_catalogo(): array {
  static $c = null;
  if ($c === null) $c = json_decode((string)file_get_contents(__DIR__ . '/funciones-detalle.json'), true) ?: ['categorias' => []];
  return $c;
}
function fx_detalles(): array {
  static $d = null;
  if ($d === null) { $d = []; foreach (fx_catalogo()['categorias'] as $cat) foreach ($cat['funciones'] as $f) $d[$f['id']] = $f + ['categoria' => $cat['id'], 'modulo' => $cat['modulo'], 'tambienCon' => $f['tambienCon'] ?? []]; }
  return $d;
}
function normalizar_sin(array $sin): array {
  $s = array_values(array_unique(array_intersect(array_map('strval', $sin), array_keys(fx_detalles()))));
  sort($s);
  return $s;
}
function sin_licencia(array $l): array {
  $s = json_decode((string)($l['funciones_sin'] ?? ''), true);
  return normalizar_sin(is_array($s) ? $s : []);
}
function detalle_activo(array $l, string $clave): bool {
  $d = fx_detalles()[$clave] ?? null;
  if (!$d) return true;
  if (in_array($clave, sin_licencia($l), true)) return false;
  $f = funciones_licencia($l);
  if ($d['modulo'] === '' || in_array($d['modulo'], $f, true)) return true;
  foreach ($d['tambienCon'] as $m) if (in_array($m, $f, true)) return true;
  return false;
}

function tiene_funcion(array $l, string $f): bool {
  return in_array($f, funciones_licencia($l), true);
}

// Básico, Profesional o Completo, más los extras que se le sumen.
function nombre_plan(array $f): string {
  $f = array_values(array_intersect(array_keys(FX_FUNCIONES), $f));
  $pro = !array_diff(FX_PRO, $f);
  $cortos = ['documentos' => 'Documentos', 'retenciones' => 'Retenciones', 'inventario' => 'Inventario', 'compras' => 'Compras', 'cartera' => 'Cobros', 'pos' => 'Punto de venta', 'nube' => 'Nube', 'contabilidad' => 'Contabilidad', 'movil' => 'Portal'];
  if ($pro && in_array('nube', $f, true) && in_array('movil', $f, true)) {
    $extras = array_diff($f, FX_PRO, ['nube', 'movil']);
    return 'Completo' . ($extras ? ' + ' . implode(' + ', array_map(fn($x) => $cortos[$x], $extras)) : '');
  }
  $base = $pro ? 'Profesional' : 'Básico';
  $extras = array_diff($f, $pro ? FX_PRO : []);
  return $base . ($extras ? ' + ' . implode(' + ', array_map(fn($x) => $cortos[$x], $extras)) : '');
}

function categoria_plan(array $f): string {
  $n = nombre_plan($f);
  return str_starts_with($n, 'Completo') ? 'Completo' : (str_starts_with($n, 'Profesional') ? 'Profesional' : 'Básico');
}

// Código que viaja en la licencia para los programas antiguos (que solo entienden LOCAL/WEB/FULL).
function plan_legado(array $f): string {
  return in_array('nube', $f, true) ? 'FULL' : 'LOCAL';
}

function plan_de(array $l): string { return nombre_plan(funciones_licencia($l)); }

// ---------- Precios: una sola fuente ----------
// Ajustes › Planes y precios guarda el precio MENSUAL de cada plan y de cada función extra. De aquí salen el precio
// sugerido de cada licencia, la página facmit.com (lee esta función) y api.php?a=precios: si cambias un precio en
// el portal, cambia en todas partes. Lo que nunca se configuró usa estos valores de referencia.
const FX_PRECIOS_BASE = [
  'basico' => 9, 'profesional' => 19.9, 'completo' => 24.9,
  'extra' => ['documentos' => 2, 'retenciones' => 2, 'inventario' => 4, 'compras' => 3, 'cartera' => 3, 'pos' => 5, 'nube' => 5, 'contabilidad' => 5, 'movil' => 3],
];
// En el pago anual se cobran estos meses (por defecto 10: usas 12 y pagas 10).
function meses_pago_anual(): int { return max(1, min(12, (int)ajuste('meses_pago_anual', '10'))); }

function precio_ref(string $k, float $base): float {
  $v = trim((string)ajuste('precio_' . $k, ''));
  return $v === '' || !is_numeric($v) ? $base : round(max(0, (float)$v), 2);
}

// Precios mensuales vigentes, sin IVA, en dólares.
function precios_mensuales(): array {
  $extra = [];
  foreach (array_keys(FX_FUNCIONES) as $k) $extra[$k] = precio_ref('extra_' . $k, (float)(FX_PRECIOS_BASE['extra'][$k] ?? 0));
  return ['basico' => precio_ref('basico', FX_PRECIOS_BASE['basico']), 'profesional' => precio_ref('profesional', FX_PRECIOS_BASE['profesional']), 'completo' => precio_ref('completo', FX_PRECIOS_BASE['completo']), 'extra' => $extra];
}

// Lo que se publica: precios, meses del pago anual, funciones (nombre y descripción) y cuáles forman el Profesional.
function precios_publicos(): array {
  $f = [];
  foreach (FX_FUNCIONES as $k => $x) $f[$k] = ['nombre' => $x['label'], 'desc' => $x['desc'], 'grupo' => $x['grupo']];
  return ['moneda' => 'USD', 'iva' => 'no incluido', 'mensual' => precios_mensuales(), 'mesesAnual' => meses_pago_anual(), 'pro' => FX_PRO, 'completo' => array_merge(FX_PRO, ['nube', 'movil']), 'funciones' => $f, 'basico' => FX_BASICO];
}

// Precio sugerido por mes según las funciones marcadas (la misma regla que la página).
function precio_sugerido(array $f): float {
  $m = precios_mensuales();
  $f = normalizar_funciones($f);
  $cat = categoria_plan($f);
  if ($cat === 'Completo') { $t = $m['completo']; foreach (array_diff($f, FX_PRO, ['nube', 'movil']) as $x) $t += $m['extra'][$x]; return round($t, 2); }
  $total = $cat === 'Profesional' ? $m['profesional'] : $m['basico'];
  foreach (array_diff($f, $cat === 'Profesional' ? FX_PRO : []) as $x) $total += $m['extra'][$x];
  return round($total, 2);
}

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

// La base no aceptó la conexión tras los reintentos: se responde 503 (el programa reintenta solo), no 500.
class ErrorBaseOcupada extends RuntimeException {}

function db(): PDO {
  static $pdo = null;
  if ($pdo === null) {
    $c = fx_config()['db'];
    $dsn = $c['driver'] === 'sqlite'
      ? 'sqlite:' . $c['ruta']
      : 'mysql:host=' . $c['host'] . ';port=' . ($c['puerto'] ?? 3306) . ';dbname=' . $c['nombre'] . ';charset=utf8mb4';
    $opc = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => 5,
    ];
    // Con muchos programas conectándose a la vez, el hosting a veces rechaza una conexión nueva por un instante
    // ([2002] Operation not permitted, 1040 demasiadas conexiones). Se reintenta con una pausa corta y, si estaba
    // por TCP (127.0.0.1), se prueba por el socket local (localhost), que el hosting limita menos.
    $intentos = $c['driver'] === 'sqlite' ? [$dsn] : array_values(array_unique([$dsn, $dsn, str_replace('host=127.0.0.1', 'host=localhost', $dsn), $dsn]));
    $ultimo = null;
    foreach ($intentos as $n => $d) {
      try { $pdo = new PDO($d, $c['usuario'] ?? null, $c['clave'] ?? null, $opc); break; }
      catch (PDOException $ex) {
        $ultimo = $ex;
        if (!preg_match('/\[(2002|2006|1040|1203|2013)\]|Operation not permitted|Too many connections/i', $ex->getMessage())) throw $ex;
        usleep([150000, 300000, 600000, 0][$n] ?? 0);
      }
    }
    if ($pdo === null) throw new ErrorBaseOcupada('La base de datos está ocupada; intenta de nuevo en unos segundos.', 0, $ultimo);
    if ($c['driver'] !== 'sqlite') $pdo->exec("SET time_zone = '-05:00'");
    // Portales instalados antes de las funciones por licencia: se agrega la columna una sola vez.
    try { $pdo->query('SELECT funciones FROM licencias LIMIT 0'); }
    catch (PDOException $ex) { try { $pdo->exec('ALTER TABLE licencias ADD COLUMN funciones TEXT NULL'); } catch (PDOException $ex2) { /* sin tabla aún (instalación) */ } }
    try { $pdo->query('SELECT funciones_sin FROM licencias LIMIT 0'); }
    catch (PDOException $ex) { try { $pdo->exec('ALTER TABLE licencias ADD COLUMN funciones_sin TEXT NULL'); } catch (PDOException $ex2) { /* sin tabla aún (instalación) */ } }
    migrar_funciones_v2($pdo);
  }
  return $pdo;
}

// Licencias Profesional creadas antes de compras, cobros y punto de venta: reciben esas tres funciones una sola vez.
function migrar_funciones_v2(PDO $pdo): void {
  try {
    $r = $pdo->query("SELECT v FROM ajustes WHERE k = 'funciones_v2'");
    if ($r && $r->fetchColumn() !== false) return;
    $filas = $pdo->query('SELECT id, funciones FROM licencias WHERE funciones IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
    $up = $pdo->prepare('UPDATE licencias SET funciones = ? WHERE id = ?');
    foreach ($filas as $f) {
      $lista = json_decode((string)$f['funciones'], true);
      if (!is_array($lista) || array_diff(['documentos', 'retenciones', 'inventario'], $lista) || array_intersect(['compras', 'cartera', 'pos'], $lista)) continue;
      $up->execute([json_encode(array_values(array_intersect(array_keys(FX_FUNCIONES), array_merge($lista, ['compras', 'cartera', 'pos'])))), $f['id']]);
    }
    $pdo->prepare('INSERT INTO ajustes (k, v) VALUES (?, ?)')->execute(['funciones_v2', date('Y-m-d H:i:s')]);
  } catch (Throwable $e) { /* tablas aún sin crear (instalación): se intenta en la próxima conexión */ }
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
    'funciones' => funciones_licencia($l),
    'sin' => sin_licencia($l),
    'planNombre' => plan_de($l),
    'fv' => FX_FUNCIONES_VERSION,
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
  $marca = e(fx_marca());
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
  echo '<title>' . e($titulo) . ' · Licencias ' . $marca . '</title><link rel="icon" href="app/assets/icono.svg"><link rel="stylesheet" href="assets/estilos.css?v=' . FX_VERSION . '"></head><body>';
  if ($admin) {
    $item = fn($k, $url, $t) => '<a href="' . $url . '"' . ($activo === $k ? ' class="on"' : '') . '>' . $t . '</a>';
    echo '<header class="top"><div class="wrap top-in"><a class="marca" href="panel.php"><span class="logo">' . fx_logo(20) . '</span><span>Licencias <b>' . $marca . '</b></span></a>';
    echo '<nav>' . $item('panel', 'panel.php', 'Panel') . $item('licencias', 'licencias.php', 'Licencias') . $item('pagos', 'pagos.php', 'Pagos') . $item('monitor', 'monitor.php', 'Monitor') . $item('parches', 'parches.php', 'Parches') . $item('eventos', 'eventos.php', 'Actividad') . $item('versiones', 'versiones.php', 'Versiones') . $item('ajustes', 'ajustes.php', 'Ajustes') . '</nav>';
    echo '<form method="post" action="logout.php" class="salir">' . campo_csrf() . '<span>' . e($admin['nombre'] ?: $admin['usuario']) . '</span><button class="btn btn-sm">Salir</button></form></div></header>';
  }
  echo '<main class="wrap">';
  if ($aviso) echo '<div class="aviso t-' . e($aviso[1]) . '" role="status">' . e($aviso[0]) . '</div>';
}

function pie_html(): void {
  echo '</main><footer class="wrap pie">Portal de licencias v' . FX_VERSION . '</footer><script src="assets/app.js?v=' . FX_VERSION . '"></script></body></html>';
}

function insignia(array $estado): string {
  return '<span class="badge t-' . e($estado['tono']) . '">' . e($estado['label']) . '</span>';
}
