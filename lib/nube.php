<?php
// Nube del plan Full: sincronización entre el programa de escritorio y la app web.
// Cada licencia y cada perfil (RUC) tienen su propio espacio de datos; nada se mezcla entre clientes.
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

const NUBE_TABLAS = ['clientes', 'productos', 'comprobantes', 'catalogo', 'proformas', 'pedidos'];
const NUBE_TABLAS_DESDE_PROGRAMA = ['clientes', 'productos', 'comprobantes', 'catalogo', 'proformas', 'pedidos'];
const NUBE_LOTE_PULL = 1000;
const NUBE_RESPALDOS = 10;

class ErrorNube extends RuntimeException {
  public int $status;
  public function __construct(int $status, string $msg) { parent::__construct($msg); $this->status = $status; }
}

// ---------- Esquema (se crea solo la primera vez que se usa la nube) ----------
function nube_esquema(): void {
  static $listo = false;
  if ($listo) return;
  $d = db();
  if (fx_config()['db']['driver'] === 'sqlite') {
    $d->exec("CREATE TABLE IF NOT EXISTS nube_registros (licencia_id INTEGER NOT NULL, perfil TEXT NOT NULL, tabla TEXT NOT NULL, id TEXT NOT NULL,
      datos TEXT NOT NULL, updated_at TEXT NOT NULL, revision INTEGER NOT NULL, borrado INTEGER NOT NULL DEFAULT 0,
      fecha TEXT NULL, tipo TEXT NOT NULL DEFAULT '', numero TEXT NOT NULL DEFAULT '', estado TEXT NOT NULL DEFAULT '', total REAL NOT NULL DEFAULT 0, texto TEXT NOT NULL DEFAULT '',
      PRIMARY KEY (licencia_id, perfil, tabla, id))");
    $d->exec('CREATE INDEX IF NOT EXISTS ix_nube_rev ON nube_registros (licencia_id, perfil, revision)');
    $d->exec('CREATE TABLE IF NOT EXISTS nube_revision (licencia_id INTEGER NOT NULL, perfil TEXT NOT NULL, rev INTEGER NOT NULL, PRIMARY KEY (licencia_id, perfil))');
    $d->exec("CREATE TABLE IF NOT EXISTS nube_usuarios (id INTEGER PRIMARY KEY AUTOINCREMENT, licencia_id INTEGER NOT NULL, usuario TEXT NOT NULL UNIQUE, nombre TEXT NOT NULL DEFAULT '',
      hash TEXT NOT NULL, rol TEXT NOT NULL DEFAULT 'ADMIN', activo INTEGER NOT NULL DEFAULT 1, creado TEXT NOT NULL, ultimo_acceso TEXT NULL)");
    $d->exec("CREATE TABLE IF NOT EXISTS nube_perfiles (licencia_id INTEGER NOT NULL, perfil TEXT NOT NULL, datos TEXT NOT NULL, ultima_sync TEXT NOT NULL, equipo TEXT NOT NULL DEFAULT '', PRIMARY KEY (licencia_id, perfil))");
    $d->exec("CREATE TABLE IF NOT EXISTS nube_series (licencia_id INTEGER NOT NULL, perfil TEXT NOT NULL, serie TEXT NOT NULL, equipo TEXT NOT NULL, nombre TEXT NOT NULL DEFAULT '', desde TEXT NOT NULL, ultimo_uso TEXT NOT NULL, PRIMARY KEY (licencia_id, perfil, serie))");
  } else {
    $d->exec("CREATE TABLE IF NOT EXISTS nube_registros (
      licencia_id INT UNSIGNED NOT NULL, perfil CHAR(13) NOT NULL, tabla VARCHAR(20) NOT NULL, id VARCHAR(64) NOT NULL,
      datos LONGTEXT NOT NULL, updated_at VARCHAR(32) NOT NULL, revision BIGINT UNSIGNED NOT NULL, borrado TINYINT NOT NULL DEFAULT 0,
      fecha DATE NULL, tipo VARCHAR(20) NOT NULL DEFAULT '', numero VARCHAR(20) NOT NULL DEFAULT '', estado VARCHAR(24) NOT NULL DEFAULT '',
      total DECIMAL(14,2) NOT NULL DEFAULT 0, texto VARCHAR(400) NOT NULL DEFAULT '',
      PRIMARY KEY (licencia_id, perfil, tabla, id), KEY ix_nube_rev (licencia_id, perfil, revision), KEY ix_nube_doc (licencia_id, perfil, tabla, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $d->exec('CREATE TABLE IF NOT EXISTS nube_revision (licencia_id INT UNSIGNED NOT NULL, perfil CHAR(13) NOT NULL, rev BIGINT UNSIGNED NOT NULL, PRIMARY KEY (licencia_id, perfil)) ENGINE=InnoDB');
    $d->exec("CREATE TABLE IF NOT EXISTS nube_usuarios (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, licencia_id INT UNSIGNED NOT NULL, usuario VARCHAR(160) NOT NULL UNIQUE, nombre VARCHAR(120) NOT NULL DEFAULT '',
      hash VARCHAR(255) NOT NULL, rol VARCHAR(12) NOT NULL DEFAULT 'ADMIN', activo TINYINT NOT NULL DEFAULT 1, creado DATETIME NOT NULL, ultimo_acceso DATETIME NULL, KEY (licencia_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $d->exec("CREATE TABLE IF NOT EXISTS nube_perfiles (licencia_id INT UNSIGNED NOT NULL, perfil CHAR(13) NOT NULL, datos MEDIUMTEXT NOT NULL, ultima_sync DATETIME NOT NULL,
      equipo VARCHAR(120) NOT NULL DEFAULT '', PRIMARY KEY (licencia_id, perfil)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $d->exec("CREATE TABLE IF NOT EXISTS nube_series (licencia_id INT UNSIGNED NOT NULL, perfil CHAR(13) NOT NULL, serie CHAR(7) NOT NULL, equipo VARCHAR(64) NOT NULL,
      nombre VARCHAR(120) NOT NULL DEFAULT '', desde DATETIME NOT NULL, ultimo_uso DATETIME NOT NULL, PRIMARY KEY (licencia_id, perfil, serie)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
  $listo = true;
}

// ---------- Autenticación del programa: token FX1 firmado por este portal + estado real de la licencia ----------
function nube_verificar_token(string $token): array {
  $partes = explode('.', preg_replace('/\s+/', '', $token));
  if (count($partes) !== 3 || $partes[0] !== 'FX1') throw new ErrorNube(401, 'Licencia con formato incorrecto.');
  $firma = base64_decode(strtr($partes[2], '-_', '+/'), true);
  $pk = sodium_crypto_sign_publickey(sodium_crypto_sign_seed_keypair(base64_decode(fx_config()['llave_privada'], true)));
  if ($firma === false || strlen($firma) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($firma, $partes[1], $pk)) {
    throw new ErrorNube(401, 'La licencia no es válida.');
  }
  $p = json_decode((string)base64_decode(strtr($partes[1], '-_', '+/')), true);
  if (!is_array($p) || !preg_match('/^LIC-(\d+)$/', (string)($p['id'] ?? ''), $m)) throw new ErrorNube(401, 'La licencia está dañada.');
  $p['_licencia_id'] = (int)$m[1];
  return $p;
}

// Licencia vigente, con nube incluida y RUC de perfil permitido. Devuelve la fila de la licencia.
function nube_licencia_vigente(int $id, string $perfil): array {
  $l = licencia_por_id($id);
  if (!$l) throw new ErrorNube(401, 'La licencia ya no existe.');
  $est = estado_licencia($l);
  if ($est['code'] === 'SUSP') throw new ErrorNube(403, 'Tu licencia está suspendida' . ($l['motivo'] ? ': ' . $l['motivo'] : '') . '. Contacta a soporte.');
  if ($est['code'] === 'VENC') throw new ErrorNube(403, 'Tu licencia está vencida. Renueva para seguir usando la nube.');
  if (!in_array($l['plan'], ['FULL', 'WEB'], true)) throw new ErrorNube(403, 'Tu plan no incluye la nube. Cambia al plan Full.');
  if (!nube_perfil_permitido($l, $perfil)) throw new ErrorNube(403, 'Este RUC no está incluido en tu licencia.');
  return $l;
}

function nube_perfiles_licencia(array $l): array {
  $rucs = json_decode((string)$l['rucs'], true) ?: [];
  return array_values(array_unique(array_merge([$l['ruc']], array_map('strval', $rucs))));
}

function nube_perfil_permitido(array $l, string $perfil): bool {
  return preg_match('/^\d{13}$/', $perfil) === 1 && in_array($perfil, nube_perfiles_licencia($l), true);
}

function nube_autenticar_programa(): array {
  $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
  if (!preg_match('/^Bearer\s+(.+)$/', $h, $m)) throw new ErrorNube(401, 'Falta la licencia.');
  $p = nube_verificar_token($m[1]);
  $perfil = preg_replace('/\D/', '', (string)($_SERVER['HTTP_X_RUC'] ?? $p['ruc'] ?? ''));
  $l = nube_licencia_vigente($p['_licencia_id'], $perfil);
  // El equipo del token debe seguir autorizado (si se liberó en el portal, deja de sincronizar).
  if (!empty($p['equipo'])) {
    $r = db()->prepare('SELECT 1 FROM equipos WHERE licencia_id = ? AND equipo = ? AND activo = 1');
    $r->execute([$l['id'], $p['equipo']]);
    if (!$r->fetchColumn()) throw new ErrorNube(403, 'Este equipo ya no está autorizado para la licencia. Vuelve a activarla.');
  }
  nube_esquema();
  return ['licencia' => $l, 'perfil' => $perfil, 'equipo' => (string)($p['equipo'] ?? '')];
}

// ---------- Revisión por espacio (licencia + perfil): cada cambio guardado recibe un número creciente ----------
function nube_siguiente_revision(int $lic, string $perfil): int {
  $d = db();
  if (fx_config()['db']['driver'] === 'sqlite') {
    $d->prepare('INSERT INTO nube_revision (licencia_id, perfil, rev) VALUES (?, ?, 1) ON CONFLICT(licencia_id, perfil) DO UPDATE SET rev = rev + 1')->execute([$lic, $perfil]);
    $r = $d->prepare('SELECT rev FROM nube_revision WHERE licencia_id = ? AND perfil = ?');
    $r->execute([$lic, $perfil]);
    return (int)$r->fetchColumn();
  }
  $d->prepare('INSERT INTO nube_revision (licencia_id, perfil, rev) VALUES (?, ?, LAST_INSERT_ID(1)) ON DUPLICATE KEY UPDATE rev = LAST_INSERT_ID(rev + 1)')->execute([$lic, $perfil]);
  return (int)$d->query('SELECT LAST_INSERT_ID()')->fetchColumn();
}

function nube_revision_actual(int $lic, string $perfil): int {
  $r = db()->prepare('SELECT rev FROM nube_revision WHERE licencia_id = ? AND perfil = ?');
  $r->execute([$lic, $perfil]);
  return (int)$r->fetchColumn();
}

// Columnas de búsqueda que la web necesita sin abrir el JSON.
function nube_indices(string $tabla, array $r): array {
  $txt = fn(...$v) => mb_substr(mb_strtolower(trim(implode(' ', array_map('strval', $v)))), 0, 400);
  $fecha = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}/', (string)$v, $m) ? $m[0] : null;
  switch ($tabla) {
    case 'clientes': return [null, (string)($r['tipo'] ?? ''), '', '', 0, $txt($r['identificacion'] ?? '', $r['nombre'] ?? '', $r['email'] ?? '')];
    case 'productos': return [null, '', '', '', (float)($r['precio'] ?? 0), $txt($r['codigo'] ?? '', $r['descripcion'] ?? '', $r['linea'] ?? '')];
    case 'comprobantes': return [$fecha($r['fecha'] ?? ''), (string)($r['cod_doc'] ?? ''), (string)($r['numero'] ?? ''), (string)($r['estado'] ?? ''), (float)($r['total'] ?? 0),
      $txt($r['numero'] ?? '', $r['cliente_nombre'] ?? '', $r['cliente_identificacion'] ?? '', $r['clave_acceso'] ?? '')];
    case 'proformas': $c = is_string($r['cliente'] ?? null) ? json_decode($r['cliente'], true) : ($r['cliente'] ?? []);
      return [$fecha($r['fecha'] ?? ''), 'PR', (string)($r['numero'] ?? ''), (string)($r['estado'] ?? ''), (float)((is_string($r['totales'] ?? null) ? json_decode($r['totales'], true) : ($r['totales'] ?? []))['importeTotal'] ?? 0),
        $txt($r['numero'] ?? '', $c['nombre'] ?? '', $c['identificacion'] ?? '')];
    case 'pedidos': return [$fecha($r['creado'] ?? ''), 'PED', '', (string)($r['estado'] ?? ''), (float)($r['total'] ?? 0), $txt($r['cliente']['nombre'] ?? '', $r['cliente']['identificacion'] ?? '')];
    default: return [null, (string)($r['tipo'] ?? ''), '', '', 0, $txt($r['clave'] ?? '')];
  }
}

// Guarda un registro si es más nuevo que el de la nube (gana la última modificación).
function nube_guardar(int $lic, string $perfil, string $tabla, array $r): bool {
  if (!in_array($tabla, NUBE_TABLAS, true)) return false;
  $id = (string)($r['id'] ?? '');
  $upd = (string)($r['updated_at'] ?? '');
  if ($id === '' || strlen($id) > 64 || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $upd)) return false;
  $d = db();
  $q = $d->prepare('SELECT updated_at FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = ? AND id = ?');
  $q->execute([$lic, $perfil, $tabla, $id]);
  $actual = $q->fetchColumn();
  if ($actual !== false && strcmp((string)$actual, $upd) >= 0) return false;
  [$fecha, $tipo, $numero, $estado, $total, $texto] = nube_indices($tabla, $r);
  $rev = nube_siguiente_revision($lic, $perfil);
  $json = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  $borrado = !empty($r['deleted']) ? 1 : 0;
  $vals = [$lic, $perfil, $tabla, $id, $json, $upd, $rev, $borrado, $fecha, $tipo, $numero, $estado, $total, $texto];
  if (fx_config()['db']['driver'] === 'sqlite') {
    $d->prepare('INSERT INTO nube_registros (licencia_id, perfil, tabla, id, datos, updated_at, revision, borrado, fecha, tipo, numero, estado, total, texto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON CONFLICT(licencia_id, perfil, tabla, id) DO UPDATE SET datos = excluded.datos, updated_at = excluded.updated_at, revision = excluded.revision, borrado = excluded.borrado,
      fecha = excluded.fecha, tipo = excluded.tipo, numero = excluded.numero, estado = excluded.estado, total = excluded.total, texto = excluded.texto')->execute($vals);
  } else {
    $d->prepare('INSERT INTO nube_registros (licencia_id, perfil, tabla, id, datos, updated_at, revision, borrado, fecha, tipo, numero, estado, total, texto) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
      ON DUPLICATE KEY UPDATE datos = VALUES(datos), updated_at = VALUES(updated_at), revision = VALUES(revision), borrado = VALUES(borrado),
      fecha = VALUES(fecha), tipo = VALUES(tipo), numero = VALUES(numero), estado = VALUES(estado), total = VALUES(total), texto = VALUES(texto)')->execute($vals);
  }
  return true;
}

function nube_push(int $lic, string $perfil, array $cambios): int {
  $d = db();
  $aceptados = 0;
  $d->beginTransaction();
  try {
    foreach (NUBE_TABLAS_DESDE_PROGRAMA as $tabla) {
      foreach (($cambios[$tabla] ?? []) as $r) {
        if (is_array($r) && nube_guardar($lic, $perfil, $tabla, $r)) $aceptados++;
      }
    }
    $d->commit();
  } catch (Throwable $e) {
    $d->rollBack();
    throw $e;
  }
  return $aceptados;
}

function nube_pull(int $lic, string $perfil, int $desde): array {
  $r = db()->prepare('SELECT tabla, datos, revision FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND revision > ? ORDER BY revision LIMIT ' . (NUBE_LOTE_PULL + 1));
  $r->execute([$lic, $perfil, $desde]);
  $filas = $r->fetchAll();
  $mas = count($filas) > NUBE_LOTE_PULL;
  if ($mas) array_pop($filas);
  $registros = array_fill_keys(NUBE_TABLAS, []);
  $revision = $desde;
  foreach ($filas as $f) {
    $registros[$f['tabla']][] = json_decode($f['datos'], true);
    $revision = max($revision, (int)$f['revision']);
  }
  return ['registros' => $registros, 'revision' => $revision, 'mas' => $mas];
}

// Datos del emisor (empresa) que el programa comparte para que la web muestre RIDE y totales.
function nube_guardar_perfil(int $lic, string $perfil, array $datos, string $equipo): void {
  $json = json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if (fx_config()['db']['driver'] === 'sqlite') {
    db()->prepare('INSERT INTO nube_perfiles (licencia_id, perfil, datos, ultima_sync, equipo) VALUES (?, ?, ?, ?, ?) ON CONFLICT(licencia_id, perfil) DO UPDATE SET datos = excluded.datos, ultima_sync = excluded.ultima_sync, equipo = excluded.equipo')
      ->execute([$lic, $perfil, $json, ahora(), $equipo]);
  } else {
    db()->prepare('INSERT INTO nube_perfiles (licencia_id, perfil, datos, ultima_sync, equipo) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE datos = VALUES(datos), ultima_sync = VALUES(ultima_sync), equipo = VALUES(equipo)')
      ->execute([$lic, $perfil, $json, ahora(), $equipo]);
  }
}

function nube_marcar_contacto(int $lic, string $perfil, string $equipo): void {
  db()->prepare('UPDATE nube_perfiles SET ultima_sync = ?, equipo = ? WHERE licencia_id = ? AND perfil = ?')->execute([ahora(), $equipo, $lic, $perfil]);
}

function nube_perfil(int $lic, string $perfil): ?array {
  $r = db()->prepare('SELECT datos, ultima_sync FROM nube_perfiles WHERE licencia_id = ? AND perfil = ?');
  $r->execute([$lic, $perfil]);
  $f = $r->fetch();
  if (!$f) return null;
  return (json_decode($f['datos'], true) ?: []) + ['_ultima_sync' => $f['ultima_sync']];
}

// Cambia el estado de un pedido de factura solo si sigue en el estado esperado (bloqueo de fila):
// así dos equipos nunca emiten la misma factura y la web no cancela una que ya se está emitiendo.
function nube_cambiar_pedido(int $lic, string $perfil, string $id, string $de, string $a, array $extra = []): ?array {
  $d = db();
  $mysql = fx_config()['db']['driver'] !== 'sqlite';
  $d->beginTransaction();
  try {
    $q = $d->prepare("SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'pedidos' AND id = ?" . ($mysql ? ' FOR UPDATE' : ''));
    $q->execute([$lic, $perfil, $id]);
    $json = $q->fetchColumn();
    $p = $json === false ? null : json_decode($json, true);
    if (!$p || ($p['estado'] ?? '') !== $de) { $d->rollBack(); return null; }
    $t = microtime(true);
    $p = array_merge($p, $extra, ['estado' => $a, 'updated_at' => gmdate('Y-m-d\TH:i:s', (int)$t) . sprintf('.%03dZ', (int)(($t - floor($t)) * 1000))]);
    nube_guardar($lic, $perfil, 'pedidos', $p);
    $d->commit();
    return $p;
  } catch (Throwable $e) {
    $d->rollBack();
    throw $e;
  }
}

// ---------- Puntos de emisión por equipo ----------
// Cada establecimiento-punto (001-001) de un RUC pertenece a un solo equipo activo de la licencia: así dos computadoras
// nunca generan el mismo número de comprobante, aunque trabajen sin internet. Si el dueño libera el equipo en el portal,
// su punto queda libre para otro.
function nube_reservar_series(int $lic, string $perfil, string $equipo, array $series): array {
  $d = db();
  $mysql = fx_config()['db']['driver'] !== 'sqlite';
  $nombreEq = '';
  $q = $d->prepare('SELECT nombre FROM equipos WHERE licencia_id = ? AND equipo = ?');
  $q->execute([$lic, $equipo]);
  $nombreEq = (string)($q->fetchColumn() ?: 'Equipo');
  $res = [];
  $d->beginTransaction();
  try {
    foreach (array_unique($series) as $serie) {
      $serie = (string)$serie;
      if (!preg_match('/^\d{3}-\d{3}$/', $serie)) continue;
      $q = $d->prepare('SELECT s.equipo, s.nombre, e.activo FROM nube_series s LEFT JOIN equipos e ON e.licencia_id = s.licencia_id AND e.equipo = s.equipo WHERE s.licencia_id = ? AND s.perfil = ? AND s.serie = ?' . ($mysql ? ' FOR UPDATE' : ''));
      $q->execute([$lic, $perfil, $serie]);
      $f = $q->fetch();
      if ($f && $f['equipo'] !== $equipo && (int)$f['activo'] === 1) {
        $res[$serie] = ['propia' => false, 'equipo' => $f['nombre'] ?: 'otro equipo'];
        continue;
      }
      if ($f) $d->prepare('UPDATE nube_series SET equipo = ?, nombre = ?, ultimo_uso = ?' . ($f['equipo'] !== $equipo ? ', desde = ?' : '') . ' WHERE licencia_id = ? AND perfil = ? AND serie = ?')
        ->execute(array_merge([$equipo, $nombreEq, ahora()], $f['equipo'] !== $equipo ? [ahora()] : [], [$lic, $perfil, $serie]));
      else $d->prepare('INSERT INTO nube_series (licencia_id, perfil, serie, equipo, nombre, desde, ultimo_uso) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$lic, $perfil, $serie, $equipo, $nombreEq, ahora(), ahora()]);
      $res[$serie] = ['propia' => true, 'equipo' => $nombreEq];
    }
    $d->commit();
  } catch (Throwable $e) {
    $d->rollBack();
    throw $e;
  }
  return ['series' => $res, 'libre' => nube_punto_libre($lic, $perfil, $equipo, $series)];
}

// Primer punto de emisión libre del mismo establecimiento (para proponerlo al equipo que choca).
function nube_punto_libre(int $lic, string $perfil, string $equipo, array $series): string {
  $estab = '001';
  foreach ($series as $s) if (preg_match('/^(\d{3})-\d{3}$/', (string)$s, $m)) { $estab = $m[1]; break; }
  $q = db()->prepare('SELECT s.serie FROM nube_series s JOIN equipos e ON e.licencia_id = s.licencia_id AND e.equipo = s.equipo AND e.activo = 1 WHERE s.licencia_id = ? AND s.perfil = ? AND s.equipo <> ?');
  $q->execute([$lic, $perfil, $equipo]);
  $ocupadas = array_flip($q->fetchAll(PDO::FETCH_COLUMN));
  for ($i = 1; $i < 1000; $i++) { $s = $estab . '-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT); if (!isset($ocupadas[$s])) return $s; }
  return '';
}

function nube_series_licencia(int $lic): array {
  nube_esquema();
  $q = db()->prepare('SELECT s.perfil, s.serie, s.nombre, s.ultimo_uso, e.activo FROM nube_series s LEFT JOIN equipos e ON e.licencia_id = s.licencia_id AND e.equipo = s.equipo WHERE s.licencia_id = ? ORDER BY s.perfil, s.serie');
  $q->execute([$lic]);
  return $q->fetchAll();
}

// ---------- Respaldos (archivo .db completo del perfil) ----------
function nube_dir_respaldos(int $lic, string $perfil): string {
  $dir = dirname(__DIR__) . '/nube/datos/' . $lic . '_' . $perfil;
  if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new ErrorNube(500, 'No se pudo crear la carpeta de respaldos.');
  return $dir;
}

function nube_guardar_respaldo(int $lic, string $perfil, string $origen): array {
  $cab = (string)file_get_contents($origen, false, null, 0, 16);
  if (filesize($origen) < 100 || substr($cab, 0, 15) !== 'SQLite format 3') throw new ErrorNube(400, 'El respaldo no es una base de datos válida.');
  $dir = nube_dir_respaldos($lic, $perfil);
  $archivo = gmdate('Y-m-d\TH-i-s') . '.db';
  if (!copy($origen, $dir . '/' . $archivo)) throw new ErrorNube(500, 'No se pudo guardar el respaldo.');
  $todos = glob($dir . '/*.db') ?: [];
  sort($todos);
  foreach (array_slice($todos, 0, max(0, count($todos) - NUBE_RESPALDOS)) as $viejo) @unlink($viejo);
  return ['archivo' => $archivo, 'bytes' => filesize($dir . '/' . $archivo)];
}

function nube_ultimo_respaldo(int $lic, string $perfil): ?string {
  $todos = glob(nube_dir_respaldos($lic, $perfil) . '/*.db') ?: [];
  sort($todos);
  return $todos ? end($todos) : null;
}

// ---------- Usuarios de la app web ----------
function nube_usuario_por_nombre(string $usuario): ?array {
  nube_esquema();
  $r = db()->prepare('SELECT * FROM nube_usuarios WHERE usuario = ?');
  $r->execute([mb_strtolower(trim($usuario))]);
  $u = $r->fetch();
  return $u ?: null;
}

function nube_usuarios_licencia(int $lic): array {
  nube_esquema();
  $r = db()->prepare('SELECT id, usuario, nombre, rol, activo, creado, ultimo_acceso FROM nube_usuarios WHERE licencia_id = ? ORDER BY id');
  $r->execute([$lic]);
  return $r->fetchAll();
}

// Crea o actualiza un usuario web de la licencia (desde el programa o desde el portal).
function nube_guardar_usuario(int $lic, string $usuario, string $nombre, string $clave, string $rol = 'ADMIN', bool $activo = true): array {
  nube_esquema();
  $usuario = mb_strtolower(trim($usuario));
  if (!filter_var($usuario, FILTER_VALIDATE_EMAIL) && !preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) throw new ErrorNube(400, 'El usuario debe ser un correo o tener entre 3 y 40 letras, números, punto o guion.');
  if (!in_array($rol, ['ADMIN', 'VENDEDOR', 'CONSULTA'], true)) $rol = 'CONSULTA';
  $u = nube_usuario_por_nombre($usuario);
  if ($u && (int)$u['licencia_id'] !== $lic) throw new ErrorNube(409, 'Ese usuario ya está en uso. Elige otro.');
  if ($clave !== '' && strlen($clave) < 8) throw new ErrorNube(400, 'La contraseña debe tener al menos 8 caracteres.');
  if (!$u && $clave === '') throw new ErrorNube(400, 'Escribe una contraseña para el usuario nuevo.');
  $nombre = mb_substr(trim($nombre), 0, 120);
  if ($u) {
    $sets = 'nombre = ?, rol = ?, activo = ?';
    $vals = [$nombre, $rol, $activo ? 1 : 0];
    if ($clave !== '') { $sets .= ', hash = ?'; $vals[] = password_hash($clave, PASSWORD_DEFAULT); }
    $vals[] = $u['id'];
    db()->prepare('UPDATE nube_usuarios SET ' . $sets . ' WHERE id = ?')->execute($vals);
  } else {
    db()->prepare('INSERT INTO nube_usuarios (licencia_id, usuario, nombre, hash, rol, activo, creado) VALUES (?, ?, ?, ?, ?, ?, ?)')
      ->execute([$lic, $usuario, $nombre, password_hash($clave, PASSWORD_DEFAULT), $rol, $activo ? 1 : 0, ahora()]);
  }
  registrar_evento($lic, 'USUARIO_WEB', ($u ? 'Actualizado ' : 'Creado ') . $usuario);
  return nube_usuario_por_nombre($usuario);
}

function nube_eliminar_usuario(int $lic, int $id): void {
  nube_esquema();
  db()->prepare('DELETE FROM nube_usuarios WHERE id = ? AND licencia_id = ?')->execute([$id, $lic]);
}

// Resumen para el portal de administración.
function nube_resumen_licencia(int $lic): array {
  nube_esquema();
  $r = db()->prepare("SELECT perfil, COUNT(*) AS n, SUM(CASE WHEN tabla = 'comprobantes' THEN 1 ELSE 0 END) AS docs FROM nube_registros WHERE licencia_id = ? AND borrado = 0 GROUP BY perfil");
  $r->execute([$lic]);
  $p = db()->prepare('SELECT perfil, ultima_sync, equipo FROM nube_perfiles WHERE licencia_id = ?');
  $p->execute([$lic]);
  $ult = [];
  foreach ($p->fetchAll() as $f) $ult[$f['perfil']] = $f;
  return array_map(fn($f) => $f + ['ultima_sync' => $ult[$f['perfil']]['ultima_sync'] ?? null], $r->fetchAll());
}
