<?php
// App web del plan Full: sesión, permisos, consultas sobre la nube y diseño común.
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }
require_once dirname(__DIR__, 2) . '/lib/nucleo.php';
require_once dirname(__DIR__, 2) . '/lib/nube.php';

const WEB_DOCS = ['01' => 'Factura', '03' => 'Liquidación de compra', '04' => 'Nota de crédito', '05' => 'Nota de débito', '06' => 'Guía de remisión', '07' => 'Retención'];
const WEB_ESTADOS = [
  'GENERADO' => ['Generado', 'n'], 'FIRMADO' => ['Firmado', 'b'], 'PENDIENTE' => ['Pendiente de envío', 'a'], 'RECIBIDA' => ['Recibida', 'b'],
  'INVALIDO' => ['Error de estructura', 'r'], 'EN PROCESO' => ['En proceso', 'a'], 'AUTORIZADO' => ['Autorizado', 'g'], 'DEVUELTA' => ['Devuelta', 'r'],
  'NO AUTORIZADO' => ['No autorizado', 'r'],
];
const WEB_FORMAS_PAGO = ['01' => 'Sin utilización del sistema financiero', '16' => 'Tarjeta de débito', '19' => 'Tarjeta de crédito', '20' => 'Otros con utilización del sistema financiero'];
const WEB_TARIFAS = ['0' => ['pct' => 0, 'label' => '0%'], '2' => ['pct' => 12, 'label' => '12%'], '3' => ['pct' => 14, 'label' => '14%'], '4' => ['pct' => 15, 'label' => '15%'],
  '5' => ['pct' => 5, 'label' => '5%'], '6' => ['pct' => 0, 'label' => 'No objeto'], '7' => ['pct' => 0, 'label' => 'Exento'], '8' => ['pct' => 8, 'label' => '8%'], '10' => ['pct' => 13, 'label' => '13%']];
const WEB_PERMISOS = ['ADMIN' => ['ver', 'editar', 'facturar'], 'VENDEDOR' => ['ver', 'editar', 'facturar'], 'CONSULTA' => ['ver']];

class WebError extends RuntimeException {}

// ---------- Sesión ----------
function web_sesion(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_name('fxweb');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
}

function web_cabeceras(): void {
  header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; font-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: same-origin');
  header('X-Frame-Options: DENY');
}

function web_csrf(): string {
  web_sesion();
  if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
  return $_SESSION['csrf'];
}
function web_campo_csrf(): string { return '<input type="hidden" name="csrf" value="' . e(web_csrf()) . '">'; }
function web_verificar_csrf(): void {
  if (!hash_equals(web_csrf(), (string)($_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? ''))) throw new WebError('La sesión expiró. Recarga la página e intenta de nuevo.');
}

function web_aviso(string $msg, string $tono = 'g'): void { web_sesion(); $_SESSION['aviso'] = [$msg, $tono]; }
// Carpeta pública de la app (…/licencias/app/), para redirigir sin depender de rutas relativas.
function web_base(): string {
  $p = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
  if (str_ends_with($p, '.php')) $p = dirname($p);
  return rtrim($p, '/') . '/';
}
function web_ir(string $q): void { header('Location: ' . web_base() . ($q === '' ? '' : '?' . $q)); exit; }

// Intentos fallidos por IP (comparte la tabla del portal).
function web_bloqueado(): bool {
  $r = db()->prepare("SELECT COUNT(*) FROM intentos WHERE ip = ? AND tipo = 'web' AND creado > ?");
  $r->execute([ip_cliente(), date('Y-m-d H:i:s', time() - 900)]);
  return (int)$r->fetchColumn() >= 8;
}

function web_login(string $usuario, string $clave): void {
  if (web_bloqueado()) throw new WebError('Demasiados intentos. Espera 15 minutos.');
  $u = nube_usuario_por_nombre($usuario);
  if (!$u || !(int)$u['activo'] || !password_verify($clave, $u['hash'])) {
    db()->prepare("INSERT INTO intentos (ip, tipo, creado) VALUES (?, 'web', ?)")->execute([ip_cliente(), date('Y-m-d H:i:s')]);
    throw new WebError('Usuario o contraseña incorrectos.');
  }
  $l = licencia_por_id((int)$u['licencia_id']);
  if (!$l) throw new WebError('La licencia de este usuario ya no existe.');
  session_regenerate_id(true);
  $_SESSION['uid'] = (int)$u['id'];
  $_SESSION['perfil'] = '';
  db()->prepare('UPDATE nube_usuarios SET ultimo_acceso = ? WHERE id = ?')->execute([ahora(), $u['id']]);
  if (password_needs_rehash($u['hash'], PASSWORD_DEFAULT)) db()->prepare('UPDATE nube_usuarios SET hash = ? WHERE id = ?')->execute([password_hash($clave, PASSWORD_DEFAULT), $u['id']]);
}

function web_salir(): void {
  web_sesion();
  $_SESSION = [];
  session_destroy();
}

// Usuario con sesión + su licencia + el perfil (RUC) elegido. null si no hay sesión válida.
function web_contexto(): ?array {
  web_sesion();
  if (empty($_SESSION['uid'])) return null;
  nube_esquema();
  $r = db()->prepare('SELECT * FROM nube_usuarios WHERE id = ? AND activo = 1');
  $r->execute([(int)$_SESSION['uid']]);
  $u = $r->fetch();
  if (!$u) { web_salir(); return null; }
  $l = licencia_por_id((int)$u['licencia_id']);
  if (!$l) { web_salir(); return null; }
  $perfiles = nube_perfiles_licencia($l);
  $perfil = in_array($_SESSION['perfil'] ?? '', $perfiles, true) ? $_SESSION['perfil'] : web_perfil_por_defecto((int)$l['id'], $perfiles);
  $_SESSION['perfil'] = $perfil;
  $est = estado_licencia($l);
  $bloqueo = '';
  if ($est['code'] === 'SUSP') $bloqueo = 'Tu licencia está suspendida' . ($l['motivo'] ? ': ' . $l['motivo'] : '') . '. Contacta a soporte.';
  elseif ($est['code'] === 'VENC') $bloqueo = 'Tu licencia venció el ' . fecha_ec($l['vence']) . '. Renueva para seguir usando la app web.';
  elseif (!in_array($l['plan'], ['FULL', 'WEB'], true)) $bloqueo = 'Tu plan no incluye la app web.';
  return ['usuario' => $u, 'licencia' => $l, 'lic' => (int)$l['id'], 'perfil' => $perfil, 'perfiles' => $perfiles, 'estado' => $est, 'bloqueo' => $bloqueo,
    'empresa' => nube_perfil((int)$l['id'], $perfil) ?? ['ruc' => $perfil, 'razonSocial' => $l['cliente']]];
}

// Primer perfil con datos en la nube (o el RUC principal).
function web_perfil_por_defecto(int $lic, array $perfiles): string {
  $r = db()->prepare('SELECT perfil FROM nube_perfiles WHERE licencia_id = ? ORDER BY ultima_sync DESC');
  $r->execute([$lic]);
  foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $p) if (in_array($p, $perfiles, true)) return $p;
  return $perfiles[0];
}

function web_puede(array $ctx, string $permiso): bool {
  return in_array($permiso, WEB_PERMISOS[$ctx['usuario']['rol']] ?? [], true) && $ctx['bloqueo'] === '';
}
function web_exigir(array $ctx, string $permiso): void {
  if (!web_puede($ctx, $permiso)) throw new WebError($ctx['bloqueo'] ?: 'Tu usuario no tiene permiso para esta acción.');
}

// ---------- Datos ----------
function web_uuid(): string {
  $b = random_bytes(16);
  $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
  $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function web_ahora_iso(): string {
  $t = microtime(true);
  return gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000));
}

function web_registro(array $ctx, string $tabla, string $id): ?array {
  $r = db()->prepare('SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = ? AND id = ?');
  $r->execute([$ctx['lic'], $ctx['perfil'], $tabla, $id]);
  $d = $r->fetchColumn();
  return $d === false ? null : json_decode($d, true);
}

// Lista con búsqueda y filtros. $f: q, tipo, estado, desde, hasta. Devuelve [filas, total].
function web_lista(array $ctx, string $tabla, array $f = [], int $limite = 50, int $desplazar = 0, string $orden = ''): array {
  $w = ['licencia_id = ?', 'perfil = ?', 'tabla = ?', 'borrado = 0'];
  $p = [$ctx['lic'], $ctx['perfil'], $tabla];
  $q = trim((string)($f['q'] ?? ''));
  if ($q !== '') foreach (preg_split('/\s+/', mb_strtolower($q)) as $pal) { $w[] = 'texto LIKE ?'; $p[] = '%' . $pal . '%'; }
  if (($f['tipo'] ?? '') !== '') { $w[] = 'tipo = ?'; $p[] = $f['tipo']; }
  if (($f['estado'] ?? '') === 'PENDIENTES') { $w[] = "estado NOT IN ('AUTORIZADO', 'DEVUELTA', 'NO AUTORIZADO', 'INVALIDO')"; }
  elseif (($f['estado'] ?? '') === 'RECHAZADOS') { $w[] = "estado IN ('DEVUELTA', 'NO AUTORIZADO', 'INVALIDO')"; }
  elseif (($f['estado'] ?? '') !== '') { $w[] = 'estado = ?'; $p[] = $f['estado']; }
  if (($f['desde'] ?? '') !== '') { $w[] = 'fecha >= ?'; $p[] = $f['desde']; }
  if (($f['hasta'] ?? '') !== '') { $w[] = 'fecha <= ?'; $p[] = $f['hasta']; }
  $donde = implode(' AND ', $w);
  $t = db()->prepare("SELECT COUNT(*) FROM nube_registros WHERE $donde");
  $t->execute($p);
  $orden = $orden ?: (in_array($tabla, ['comprobantes', 'proformas', 'pedidos'], true) ? 'fecha DESC, numero DESC' : 'texto');
  $r = db()->prepare("SELECT datos FROM nube_registros WHERE $donde ORDER BY $orden LIMIT " . max(1, $limite) . ' OFFSET ' . max(0, $desplazar));
  $r->execute($p);
  return [array_map(fn($d) => json_decode($d, true), $r->fetchAll(PDO::FETCH_COLUMN)), (int)$t->fetchColumn()];
}

// Guarda un cambio hecho en la web: queda en la nube con una revisión nueva y el programa lo descarga.
function web_guardar(array $ctx, string $tabla, array $r): array {
  $r['id'] = $r['id'] ?? web_uuid();
  $r['updated_at'] = web_ahora_iso();
  db()->beginTransaction();
  try {
    nube_guardar($ctx['lic'], $ctx['perfil'], $tabla, $r);
    db()->commit();
  } catch (Throwable $e) {
    db()->rollBack();
    throw $e;
  }
  return $r;
}

function web_resumen(array $ctx): array {
  $mesIni = date('Y-m-01');
  $q = fn(string $sql, array $p = []) => (function () use ($sql, $p, $ctx) {
    $r = db()->prepare($sql);
    $r->execute(array_merge([$ctx['lic'], $ctx['perfil']], $p));
    return $r->fetch();
  })();
  $base = 'FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND borrado = 0';
  $ventas = $q("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS t $base AND tabla = 'comprobantes' AND tipo = '01' AND estado = 'AUTORIZADO' AND fecha >= ?", [$mesIni]);
  $hoy = $q("SELECT COUNT(*) AS n, COALESCE(SUM(total), 0) AS t $base AND tabla = 'comprobantes' AND tipo = '01' AND estado = 'AUTORIZADO' AND fecha = ?", [date('Y-m-d')]);
  $pend = $q("SELECT COUNT(*) AS n $base AND tabla = 'comprobantes' AND estado NOT IN ('AUTORIZADO', 'DEVUELTA', 'NO AUTORIZADO', 'INVALIDO')");
  $rech = $q("SELECT COUNT(*) AS n $base AND tabla = 'comprobantes' AND estado IN ('DEVUELTA', 'NO AUTORIZADO', 'INVALIDO') AND fecha >= ?", [date('Y-m-d', strtotime('-30 days'))]);
  $cli = $q("SELECT COUNT(*) AS n $base AND tabla = 'clientes'");
  $pedidos = $q("SELECT COUNT(*) AS n $base AND tabla = 'pedidos' AND estado IN ('PENDIENTE', 'EN PROCESO')");
  return ['mes' => $ventas, 'hoy' => $hoy, 'pendientes' => (int)$pend['n'], 'rechazados' => (int)$rech['n'], 'clientes' => (int)$cli['n'], 'pedidos' => (int)$pedidos['n']];
}

// Ventas autorizadas por día del mes actual (para el gráfico del inicio).
function web_ventas_dias(array $ctx, int $dias = 30): array {
  $desde = date('Y-m-d', strtotime('-' . ($dias - 1) . ' days'));
  $r = db()->prepare("SELECT fecha, SUM(total) AS t FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'comprobantes' AND tipo = '01' AND estado = 'AUTORIZADO' AND borrado = 0 AND fecha >= ? GROUP BY fecha");
  $r->execute([$ctx['lic'], $ctx['perfil'], $desde]);
  $por = [];
  foreach ($r->fetchAll() as $f) $por[$f['fecha']] = (float)$f['t'];
  $salida = [];
  for ($i = $dias - 1; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $salida[$d] = $por[$d] ?? 0.0; }
  return $salida;
}

// Tarifas de IVA habilitadas en el programa (las envía al sincronizar); si aún no llegan, las habituales.
function web_tarifas(array $ctx): array {
  $t = $ctx['empresa']['tarifas'] ?? null;
  if (is_array($t) && $t) {
    $salida = [];
    foreach ($t as $k => $v) if (is_array($v) && isset($v['pct'])) $salida[(string)$k] = ['pct' => (float)$v['pct'], 'label' => (string)($v['label'] ?? ($v['pct'] . '%'))];
    if ($salida) return $salida;
  }
  return array_intersect_key(WEB_TARIFAS, array_flip(['4', '0', '5', '6', '7']));
}

// Mismo cálculo que el programa (sri.calcularTotales).
function web_totales(array $items, array $tarifas): array {
  $r2 = fn($v) => round((float)$v + 1e-9, 2);
  $lineas = [];
  $porTarifa = [];
  foreach ($items as $it) {
    $cant = (float)$it['cantidad']; $precio = (float)$it['precioUnitario']; $desc = (float)($it['descuentoPct'] ?? 0);
    $pct = $tarifas[$it['tarifa']]['pct'] ?? 0;
    $bruto = $cant * $precio;
    $descuento = $r2($bruto * $desc / 100);
    $base = $r2($bruto - $descuento);
    $lineas[] = $it + ['descuento' => $descuento, 'base' => $base, 'iva' => $r2($base * $pct / 100)];
    $porTarifa[$it['tarifa']] = $r2(($porTarifa[$it['tarifa']] ?? 0) + $base);
  }
  $iva = 0;
  foreach ($porTarifa as $k => $b) $iva += $r2($b * ($tarifas[$k]['pct'] ?? 0) / 100);
  $sin = $r2(array_sum(array_column($lineas, 'base')));
  return ['lineas' => $lineas, 'porTarifa' => $porTarifa, 'totalSinImpuestos' => $sin, 'totalDescuento' => $r2(array_sum(array_column($lineas, 'descuento'))), 'totalIva' => $r2($iva), 'importeTotal' => $r2($sin + $iva)];
}

// ---------- Formato ----------
function dinero($v): string { return '$' . number_format((float)$v, 2, '.', ','); }
function web_fecha(?string $iso): string {
  if (!$iso) return '—';
  $t = strtotime($iso);
  return $t ? date('d/m/Y', $t) : '—';
}
function web_hace(?string $f): string {
  if (!$f) return 'nunca';
  $s = time() - strtotime($f);
  if ($s < 90) return 'hace un momento';
  if ($s < 3600) return 'hace ' . round($s / 60) . ' min';
  if ($s < 86400) return 'hace ' . round($s / 3600) . ' h';
  return 'hace ' . round($s / 86400) . ' días';
}
function web_estado(array $c): string {
  $e = WEB_ESTADOS[$c['estado'] ?? ''] ?? [$c['estado'] ?? '—', 'n'];
  return '<span class="badge t-' . $e[1] . '">' . e($e[0]) . '</span>' . (!empty($c['anulado']) ? ' <span class="badge t-r">Anulada</span>' : '');
}
function web_json($v): array { return is_array($v) ? $v : (json_decode((string)$v, true) ?: []); }

function icono(string $d, int $t = 20): string {
  return '<svg width="' . $t . '" height="' . $t . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' . $d . '"/></svg>';
}
const ICO = [
  'inicio' => 'M3 11l9-7 9 7v9a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z',
  'facturar' => 'M12 5v14M5 12h14',
  'comprobantes' => 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
  'clientes' => 'M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 10a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7zM21 19v-1a4 4 0 0 0-3-3.9M15 3.1a3.5 3.5 0 0 1 0 6.8',
  'productos' => 'M3 7l9-4 9 4-9 4-9-4zM3 7v10l9 4 9-4V7M12 11v10',
  'pedidos' => 'M12 8v4l3 2M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z',
  'salir' => 'M15 17l5-5-5-5M20 12H9M12 21H5a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h7',
  'buscar' => 'M11 4a7 7 0 1 0 0 14 7 7 0 0 0 0-14zM21 21l-4.3-4.3',
];

// ---------- Diseño ----------
function web_cabecera(array $ctx, string $titulo, string $activo, string $grupo = ''): void {
  web_cabeceras();
  $aviso = $_SESSION['aviso'] ?? null;
  unset($_SESSION['aviso']);
  $emp = $ctx['empresa'];
  $nombre = $emp['nombreComercial'] ?? '' ?: ($emp['razonSocial'] ?? $ctx['licencia']['cliente']);
  $menu = [['inicio', 'Inicio'], ['facturar', 'Facturar'], ['comprobantes', 'Comprobantes'], ['clientes', 'Clientes'], ['productos', 'Productos']];
  if (!web_puede($ctx, 'facturar')) $menu = array_values(array_filter($menu, fn($m) => $m[0] !== 'facturar'));
  $item = fn($m) => '<a href="./?v=' . $m[0] . '" class="' . ($activo === $m[0] ? 'on' : '') . '">' . icono(ICO[$m[0]]) . '<span>' . e($m[1]) . '</span></a>';
  $iniciales = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\s]/u', '', $nombre), 0, 1) . mb_substr((string)(explode(' ', trim($nombre))[1] ?? ''), 0, 1));
  echo '<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">';
  echo '<meta name="theme-color" content="#0F1B2D"><title>' . e($titulo) . ' · ' . e($nombre) . '</title><link rel="stylesheet" href="assets/app.css?v=' . FX_VERSION . '"><link rel="icon" href="assets/icono.svg"></head><body>';
  echo '<div class="app"><aside class="lado"><a class="marca" href="./"><span class="logo">' . icono('M7 3h7l5 5v13H7zM14 3v5h5M10 13l2 2 4-4', 18) . '</span><span>Facturador<small>en la nube</small></span></a>';
  echo '<div class="perfil-sel"><span class="avatar">' . e($iniciales ?: 'FX') . '</span><span class="perfil-txt">';
  if (count($ctx['perfiles']) > 1) {
    // Varias empresas en la licencia: el nombre es un selector.
    $q = db()->prepare('SELECT perfil, datos FROM nube_perfiles WHERE licencia_id = ?');
    $q->execute([$ctx['lic']]);
    $nombres = [];
    foreach ($q->fetchAll() as $f) { $dd = json_decode($f['datos'], true) ?: []; $nombres[$f['perfil']] = ($dd['nombreComercial'] ?? '') ?: ($dd['razonSocial'] ?? ''); }
    echo '<form method="post" action="./?v=perfil" class="perfil-cambiar">' . web_campo_csrf() . '<select name="perfil" aria-label="Cambiar empresa" data-auto-submit>';
    foreach ($ctx['perfiles'] as $p) echo '<option value="' . e($p) . '"' . ($p === $ctx['perfil'] ? ' selected' : '') . '>' . e(($nombres[$p] ?? '') ?: 'Empresa ' . $p) . '</option>';
    echo '</select></form>';
  } else {
    echo '<b>' . e($nombre) . '</b>';
  }
  echo '<small class="mono">' . e($ctx['perfil']) . '</small></span>';
  echo '</div><nav class="menu">' . implode('', array_map($item, $menu)) . '</nav>';
  $sync = $emp['_ultima_sync'] ?? null;
  $tono = $sync && (time() - strtotime($sync)) < 1800 ? 'g' : ($sync ? 'a' : 'r');
  echo '<div class="lado-pie"><div class="sync s-' . $tono . '"><span class="punto"></span><span>Programa ' . ($sync ? 'conectado ' . e(web_hace($sync)) : 'aún no se conecta') . '</span></div>';
  echo '<div class="usuario"><span>' . e($ctx['usuario']['nombre'] ?: $ctx['usuario']['usuario']) . '<small>' . e(['ADMIN' => 'Administrador', 'VENDEDOR' => 'Vendedor', 'CONSULTA' => 'Solo consulta'][$ctx['usuario']['rol']] ?? '') . '</small></span>';
  echo '<form method="post" action="./?v=salir">' . web_campo_csrf() . '<button class="btn-icono" title="Salir" aria-label="Salir">' . icono(ICO['salir'], 18) . '</button></form></div></div></aside>';
  echo '<main class="contenido">';
  if ($ctx['bloqueo']) echo '<div class="aviso t-r" role="alert">' . e($ctx['bloqueo']) . '</div>';
  if ($aviso) echo '<div class="aviso t-' . e($aviso[1]) . '" role="status">' . e($aviso[0]) . '</div>';
  // Barra inferior en celular
  $mov = [['inicio', 'Inicio'], ['comprobantes', 'Docs'], ['facturar', 'Facturar'], ['clientes', 'Clientes'], ['productos', 'Productos']];
  if (!web_puede($ctx, 'facturar')) $mov = array_values(array_filter($mov, fn($m) => $m[0] !== 'facturar'));
  $GLOBALS['__web_movil'] = '<nav class="tabbar">' . implode('', array_map(fn($m) => '<a href="./?v=' . $m[0] . '" class="' . ($activo === $m[0] ? 'on' : '') . ($m[0] === 'facturar' ? ' destacado' : '') . '">' . icono(ICO[$m[0]], 22) . '<span>' . e($m[1]) . '</span></a>', $mov)) . '</nav>';
}

function web_pie(): void {
  echo '</main></div>' . ($GLOBALS['__web_movil'] ?? '') . '<script src="assets/app.js?v=' . FX_VERSION . '"></script></body></html>';
}

function web_titulo(string $crumb, string $titulo, string $acciones = ''): string {
  return '<div class="titulo"><div><span class="crumb">' . e($crumb) . '</span><h1>' . e($titulo) . '</h1></div>' . ($acciones ? '<div class="acciones">' . $acciones . '</div>' : '') . '</div>';
}

function web_paginacion(int $total, int $pagina, int $porPagina, array $q): string {
  $paginas = (int)ceil($total / $porPagina);
  if ($paginas <= 1) return '';
  $url = fn($p) => './?' . http_build_query(array_merge($q, ['p' => $p]));
  return '<div class="paginas"><span class="muted">' . number_format($total) . ' en total · página ' . $pagina . ' de ' . $paginas . '</span><span class="fila">'
    . ($pagina > 1 ? '<a class="btn btn-sm" href="' . e($url($pagina - 1)) . '">Anterior</a>' : '')
    . ($pagina < $paginas ? '<a class="btn btn-sm" href="' . e($url($pagina + 1)) . '">Siguiente</a>' : '') . '</span></div>';
}
