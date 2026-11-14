<?php
// Monitor de usuarios y de errores: cada programa instalado envía un «latido» cada pocos minutos con su estado
// (versión, ambiente, facturas pendientes en el SRI, nube, firma, disco) y los errores técnicos que tuvo, agrupados.
// Nunca llegan datos de los clientes ni de las ventas: el programa los limpia antes de enviarlos.
declare(strict_types=1);
if (!defined('FX_PORTAL')) { http_response_code(403); exit; }

const MON_TIPOS = ['PROGRAMA' => 'Programa', 'PANTALLA' => 'Pantalla', 'CIERRE' => 'Cierre inesperado', 'SRI' => 'Rechazo del SRI', 'XSD' => 'XML inválido', 'NUBE' => 'Nube', 'CORREO' => 'Correo', 'FIRMA' => 'Firma', 'BASE' => 'Base de datos'];
const MON_EN_LINEA_MIN = 12;   // con latidos cada 5 minutos, más de 12 sin noticias = desconectado

function mon_esquema(): void {
  static $listo = false;
  if ($listo) return;
  $pdo = db();
  $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
  $ai = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY';
  $ref = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
  $fin = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
  $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_equipos (
    equipo_id $ref NOT NULL PRIMARY KEY, licencia_id $ref NOT NULL, actualizado DATETIME NOT NULL, version VARCHAR(20) NOT NULL DEFAULT '',
    ambiente CHAR(1) NOT NULL DEFAULT '', en_uso TINYINT NOT NULL DEFAULT 0, usuario VARCHAR(80) NOT NULL DEFAULT '',
    perfil_ruc VARCHAR(13) NOT NULL DEFAULT '', perfil_nombre VARCHAR(200) NOT NULL DEFAULT '', pendientes INT NOT NULL DEFAULT 0,
    rechazados INT NOT NULL DEFAULT 0, emitidos_hoy INT NOT NULL DEFAULT 0, nube_error VARCHAR(255) NOT NULL DEFAULT '',
    firma_vence DATE NULL, disco_gb DECIMAL(8,1) NULL, errores_cola INT NOT NULL DEFAULT 0, datos TEXT NULL)$fin");
  $pdo->exec("CREATE TABLE IF NOT EXISTS monitor_errores (
    id $ai, huella CHAR(40) NOT NULL, licencia_id $ref NOT NULL, equipo_id $ref NOT NULL, tipo VARCHAR(20) NOT NULL,
    mensaje VARCHAR(500) NOT NULL, detalle TEXT NULL, version VARCHAR(20) NOT NULL DEFAULT '', contexto TEXT NULL,
    veces INT NOT NULL DEFAULT 1, primero DATETIME NOT NULL, ultimo DATETIME NOT NULL, estado VARCHAR(12) NOT NULL DEFAULT 'NUEVO',
    UNIQUE (huella, equipo_id))$fin");
  foreach (['CREATE INDEX ix_monerr_ultimo ON monitor_errores (ultimo)', 'CREATE INDEX ix_monerr_estado ON monitor_errores (estado, huella)', 'CREATE INDEX ix_moneq_lic ON monitor_equipos (licencia_id)'] as $s) {
    try { $pdo->exec($s); } catch (PDOException $ex) { /* ya existe */ }
  }
  $listo = true;
}

function mon_txt($v, int $n): string { return mb_substr(trim((string)($v ?? '')), 0, $n); }
function mon_int($v, int $max = 1000000): int { return max(0, min($max, (int)$v)); }

// POST api.php?a=latido {clave, equipo, estado:{...}, errores:[...]}
function api_latido(string $clave, array $equipo, array $in): array {
  $l = licencia_por_clave($clave);
  $eq = validar_equipo($equipo);
  $r = db()->prepare('SELECT * FROM equipos WHERE licencia_id = ? AND equipo = ? AND activo = 1');
  $r->execute([$l['id'], $eq['id']]);
  $e = $r->fetch();
  if (!$e) throw new ErrorLicencia('EQUIPO_LIBERADO', 'Este equipo ya no está autorizado para esta licencia.');
  mon_esquema();
  $ahora = ahora();
  $s = is_array($in['estado'] ?? null) ? $in['estado'] : [];
  db()->prepare('UPDATE equipos SET ultimo_contacto = ?, version = ?, so = ?, ip = ? WHERE id = ?')->execute([$ahora, $eq['version'] ?: $e['version'], $eq['so'] ?: $e['so'], ip_cliente(), $e['id']]);
  $firma = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($s['firmaVence'] ?? '')) ? $s['firmaVence'] : null;
  $disco = isset($s['discoLibreGB']) && is_numeric($s['discoLibreGB']) ? round((float)$s['discoLibreGB'], 1) : null;
  $extra = array_intersect_key($s, array_flip(['so', 'arquitectura', 'ramGB', 'perfiles', 'modo', 'licencia', 'nubeActiva', 'nubeUltimoOk', 'nubePendientes', 'ultimoComprobante', 'minutosInactivo', 'idioma', 'pantalla', 'diagnosticos', 'correoFallidos7d', 'relojDesfaseMin', 'zonaHoraria', 'zonaAplicada']));
  $series = mon_series($s['series'] ?? []);
  if ($series) $extra['series'] = $series;
  $fila = [
    (int)$l['id'], $ahora, mon_txt($eq['version'], 20), in_array((string)($s['ambiente'] ?? ''), ['1', '2'], true) ? $s['ambiente'] : '',
    empty($s['enUso']) ? 0 : 1, mon_txt($s['usuario'] ?? '', 80), preg_replace('/\D/', '', mon_txt($s['perfilRuc'] ?? '', 13)), mon_txt($s['perfilNombre'] ?? '', 200),
    mon_int($s['pendientesSri'] ?? 0), mon_int($s['rechazados7d'] ?? 0), mon_int($s['emitidosHoy'] ?? 0), mon_txt($s['nubeError'] ?? '', 255),
    $firma, $disco, mon_int($s['erroresCola'] ?? 0), json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
  ];
  $existe = db()->prepare('SELECT 1 FROM monitor_equipos WHERE equipo_id = ?');
  $existe->execute([$e['id']]);
  if ($existe->fetchColumn()) {
    db()->prepare('UPDATE monitor_equipos SET licencia_id = ?, actualizado = ?, version = ?, ambiente = ?, en_uso = ?, usuario = ?, perfil_ruc = ?, perfil_nombre = ?, pendientes = ?, rechazados = ?, emitidos_hoy = ?, nube_error = ?, firma_vence = ?, disco_gb = ?, errores_cola = ?, datos = ? WHERE equipo_id = ?')
      ->execute([...$fila, $e['id']]);
  } else {
    db()->prepare('INSERT INTO monitor_equipos (licencia_id, actualizado, version, ambiente, en_uso, usuario, perfil_ruc, perfil_nombre, pendientes, rechazados, emitidos_hoy, nube_error, firma_vence, disco_gb, errores_cola, datos, equipo_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
      ->execute([...$fila, $e['id']]);
  }
  $recibidos = 0;
  foreach (array_slice(is_array($in['errores'] ?? null) ? $in['errores'] : [], 0, 40) as $x) {
    if (!is_array($x)) continue;
    $huella = preg_replace('/[^a-f0-9]/', '', strtolower((string)($x['huella'] ?? '')));
    $tipo = strtoupper(mon_txt($x['tipo'] ?? '', 20));
    $msg = mon_txt($x['mensaje'] ?? '', 500);
    if (strlen($huella) !== 40 || !isset(MON_TIPOS[$tipo]) || $msg === '') continue;
    $veces = max(1, mon_int($x['veces'] ?? 1, 100000));
    $ultimo = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', (string)($x['ultimo'] ?? '')) ? date('Y-m-d H:i:s', strtotime((string)$x['ultimo'])) : $ahora;
    if ($ultimo > $ahora) $ultimo = $ahora;
    $detalle = mon_txt($x['detalle'] ?? '', 4000);
    $contexto = json_encode(is_array($x['contexto'] ?? null) ? array_slice($x['contexto'], 0, 12) : [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $v = mon_txt($x['version'] ?? $eq['version'], 20);
    $q = db()->prepare('SELECT id, estado FROM monitor_errores WHERE huella = ? AND equipo_id = ?');
    $q->execute([$huella, $e['id']]);
    $prev = $q->fetch();
    if ($prev) {
      // Un error ya resuelto que vuelve a pasar se marca como «reapareció».
      $estado = $prev['estado'] === 'RESUELTO' ? 'REAPARECIO' : $prev['estado'];
      db()->prepare('UPDATE monitor_errores SET veces = veces + ?, ultimo = ?, mensaje = ?, detalle = ?, contexto = ?, version = ?, estado = ? WHERE id = ?')
        ->execute([$veces, $ultimo, $msg, $detalle, $contexto, $v, $estado, $prev['id']]);
    } else {
      db()->prepare('INSERT INTO monitor_errores (huella, licencia_id, equipo_id, tipo, mensaje, detalle, version, contexto, veces, primero, ultimo, estado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$huella, $l['id'], $e['id'], $tipo, $msg, $detalle, $v, $contexto, $veces, $ultimo, $ultimo, 'NUEVO']);
    }
    $recibidos++;
  }
  // Limpieza ocasional: errores resueltos o ignorados con más de 120 días.
  if (random_int(1, 200) === 1) db()->prepare("DELETE FROM monitor_errores WHERE estado IN ('RESUELTO', 'IGNORADO') AND ultimo < ?")->execute([date('Y-m-d H:i:s', time() - 120 * 86400)]);
  $avisos = $series ? mon_conflictos_serie((int)$e['id'], (string)($s['perfilRuc'] ?? ''), $series) : [];
  return ['ok' => true, 'recibidos' => $recibidos, 'intervaloMin' => (int)ajuste('monitor_intervalo', 5) ?: 5, 'avisos' => $avisos];
}

// ---------- Consultas para el panel ----------
function mon_version_publicada(): string {
  $f = __DIR__ . '/../actualizaciones/latest.yml';
  if (!is_file($f)) return '';
  return preg_match('/^version:\s*[\'"]?([0-9]+\.[0-9]+\.[0-9]+)/m', (string)file_get_contents($f), $m) ? $m[1] : '';
}

function mon_equipos(): array {
  mon_esquema();
  return db()->query("SELECT e.id, e.licencia_id, e.nombre, e.so, e.version AS version_equipo, e.ultimo_contacto, e.activado, e.ip, l.cliente, l.ruc, l.estado AS lic_estado, l.vence,
      m.actualizado, m.version, m.ambiente, m.en_uso, m.usuario, m.perfil_ruc, m.perfil_nombre, m.pendientes, m.rechazados, m.emitidos_hoy, m.nube_error, m.firma_vence, m.disco_gb, m.errores_cola, m.datos
    FROM equipos e JOIN licencias l ON l.id = e.licencia_id LEFT JOIN monitor_equipos m ON m.equipo_id = e.id
    WHERE e.activo = 1 ORDER BY COALESCE(m.actualizado, e.ultimo_contacto) DESC")->fetchAll();
}

function mon_minutos(?string $fecha): ?int {
  if (!$fecha) return null;
  $t = strtotime($fecha);
  return $t ? max(0, (int)floor((time() - $t) / 60)) : null;
}

function mon_hace(?int $min): string {
  if ($min === null) return 'nunca';
  if ($min < 1) return 'ahora';
  if ($min < 60) return 'hace ' . $min . ' min';
  if ($min < 1440) return 'hace ' . floor($min / 60) . ' h';
  return 'hace ' . floor($min / 1440) . ' días';
}

function mon_errores_grupos(string $estado = 'abiertos', string $tipo = '', int $dias = 30): array {
  mon_esquema();
  $w = 'ultimo >= ?';
  $p = [date('Y-m-d H:i:s', time() - $dias * 86400)];
  if ($estado === 'abiertos') $w .= " AND estado IN ('NUEVO', 'VISTO', 'REAPARECIO')";
  elseif (in_array($estado, ['NUEVO', 'VISTO', 'REAPARECIO', 'RESUELTO', 'IGNORADO'], true)) { $w .= ' AND estado = ?'; $p[] = $estado; }
  if ($tipo !== '' && isset(MON_TIPOS[$tipo])) { $w .= ' AND tipo = ?'; $p[] = $tipo; }
  $r = db()->prepare("SELECT huella, MAX(tipo) AS tipo, MAX(mensaje) AS mensaje, SUM(veces) AS veces, COUNT(DISTINCT licencia_id) AS clientes, COUNT(*) AS equipos,
      MIN(primero) AS primero, MAX(ultimo) AS ultimo, MIN(version) AS version_min, MAX(version) AS version_max,
      SUM(CASE WHEN estado = 'NUEVO' THEN 1 ELSE 0 END) AS nuevos, SUM(CASE WHEN estado = 'REAPARECIO' THEN 1 ELSE 0 END) AS reaparecidos, MIN(estado) AS estado
    FROM monitor_errores WHERE $w GROUP BY huella ORDER BY MAX(ultimo) DESC LIMIT 300");
  $r->execute($p);
  return $r->fetchAll();
}

function mon_error_detalle(string $huella): array {
  mon_esquema();
  $r = db()->prepare('SELECT x.*, l.cliente, l.ruc, e.nombre AS equipo_nombre, e.so FROM monitor_errores x JOIN licencias l ON l.id = x.licencia_id LEFT JOIN equipos e ON e.id = x.equipo_id WHERE x.huella = ? ORDER BY x.ultimo DESC LIMIT 200');
  $r->execute([$huella]);
  return $r->fetchAll();
}

function mon_cambiar_estado(string $huella, string $estado): void {
  if (!in_array($estado, ['VISTO', 'RESUELTO', 'IGNORADO', 'NUEVO'], true)) throw new RuntimeException('Estado no válido.');
  mon_esquema();
  db()->prepare('UPDATE monitor_errores SET estado = ? WHERE huella = ?')->execute([$estado, $huella]);
}

function mon_resumen(): array {
  mon_esquema();
  $desde = date('Y-m-d H:i:s', time() - MON_EN_LINEA_MIN * 60);
  $enLinea = (int)db()->query("SELECT COUNT(*) FROM monitor_equipos m JOIN equipos e ON e.id = m.equipo_id AND e.activo = 1 WHERE m.actualizado >= '" . $desde . "'")->fetchColumn();
  $r = db()->prepare("SELECT COUNT(DISTINCT huella) FROM monitor_errores WHERE estado IN ('NUEVO', 'REAPARECIO')");
  $r->execute();
  return ['enLinea' => $enLinea, 'erroresNuevos' => (int)$r->fetchColumn()];
}

// ---------- Antifallo: misma serie en dos equipos ----------
// Cada equipo cuenta qué puntos de emisión usa («01:001-002»). Si otro equipo con el mismo RUC usa el mismo punto
// para el mismo documento, los dos sacarán los mismos números y el SRI rechazará uno («secuencial registrado»).
function mon_series($lista): array {
  $r = [];
  foreach ((array)$lista as $x) if (is_string($x) && preg_match('/^\d{2}:\d{3}-\d{3}$/', $x)) $r[$x] = true;
  return array_slice(array_keys($r), 0, 60);
}

function mon_conflictos_serie(int $equipoId, string $ruc, array $series): array {
  if (!preg_match('/^\d{13}$/', $ruc) || !$series) return [];
  $q = db()->prepare("SELECT m.equipo_id, m.datos, e.nombre FROM monitor_equipos m LEFT JOIN equipos e ON e.id = m.equipo_id
    WHERE m.perfil_ruc = ? AND m.equipo_id <> ? AND m.actualizado >= ? AND (e.activo = 1 OR e.activo IS NULL)");
  $q->execute([$ruc, $equipoId, date('Y-m-d H:i:s', time() - 30 * 86400)]);
  $nombres = ['01' => 'Facturas', '03' => 'Liquidaciones de compra', '04' => 'Notas de crédito', '05' => 'Notas de débito', '06' => 'Guías de remisión', '07' => 'Retenciones'];
  $avisos = [];
  foreach ($q->fetchAll() as $o) {
    $otras = (json_decode((string)$o['datos'], true) ?: [])['series'] ?? [];
    foreach (array_intersect($series, (array)$otras) as $x) {
      [$cod, $serie] = explode(':', $x);
      $avisos[] = ['tipo' => 'SERIE_COMPARTIDA', 'codDoc' => $cod, 'serie' => $serie, 'equipo' => mb_substr((string)($o['nombre'] ?: 'otro equipo'), 0, 60),
        'mensaje' => ($nombres[$cod] ?? 'Documentos') . ' ' . $serie . ' también se emiten en «' . ($o['nombre'] ?: 'otro equipo') . '». Dos equipos con el mismo punto de emisión repiten números y el SRI rechaza uno. Asigna a este equipo otro punto de emisión (Configuración › Documentos y secuencias).'];
      if (count($avisos) >= 10) return $avisos;
    }
  }
  return $avisos;
}
