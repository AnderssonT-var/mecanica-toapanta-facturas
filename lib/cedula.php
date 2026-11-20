<?php
// Nombre por cédula: el SRI ya no entrega el nombre de cédulas sin RUC a otros programas, así que se consulta al
// Registro Civil por un proveedor de pago (EcuadorAPI o Datacil). La clave del proveedor vive solo en el portal
// (Ajustes); los programas, la app y la web le piden el nombre al portal y nunca ven la clave.
//
// - Solo licencias vigentes y con un límite diario por licencia (para cuidar el saldo del proveedor).
// - Se guarda solo el nombre (no fecha de nacimiento ni otros datos) durante 90 días, para no pagar dos veces la
//   misma cédula.
if (!defined('FX_PORTAL')) exit;

const CEDULA_PROVEEDORES = ['' => 'Desactivado', 'ecuadorapi' => 'EcuadorAPI (ecuadorapi.com)', 'datacil' => 'Datacil (datacil.com)'];
const CEDULA_CACHE_DIAS = 90;

function cedula_esquema(): void {
  static $listo = false;
  if ($listo) return;
  if (fx_config()['db']['driver'] === 'sqlite') {
    db()->exec("CREATE TABLE IF NOT EXISTS cedula_nombres (cedula TEXT PRIMARY KEY, nombre TEXT NOT NULL, fuente TEXT NOT NULL DEFAULT '', creado TEXT NOT NULL)");
    db()->exec("CREATE TABLE IF NOT EXISTS cedula_consultas (id INTEGER PRIMARY KEY AUTOINCREMENT, licencia_id INTEGER NOT NULL, pagada INTEGER NOT NULL DEFAULT 0, creado TEXT NOT NULL)");
  } else {
    db()->exec("CREATE TABLE IF NOT EXISTS cedula_nombres (cedula CHAR(10) NOT NULL PRIMARY KEY, nombre VARCHAR(300) NOT NULL, fuente VARCHAR(20) NOT NULL DEFAULT '', creado DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    db()->exec("CREATE TABLE IF NOT EXISTS cedula_consultas (id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, licencia_id INT UNSIGNED NOT NULL, pagada TINYINT NOT NULL DEFAULT 0, creado DATETIME NOT NULL, KEY (licencia_id, creado)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  }
  $listo = true;
}

// Cédula ecuatoriana válida: 10 dígitos, provincia 01-24 o 30, tercer dígito < 6 y dígito verificador (módulo 10).
function cedula_valida(string $c): bool {
  if (!preg_match('/^\d{10}$/', $c)) return false;
  $prov = (int)substr($c, 0, 2);
  if (!(($prov >= 1 && $prov <= 24) || $prov === 30) || (int)$c[2] >= 6) return false;
  $suma = 0;
  for ($i = 0; $i < 9; $i++) { $v = (int)$c[$i] * ($i % 2 === 0 ? 2 : 1); $suma += $v > 9 ? $v - 9 : $v; }
  return ((10 - $suma % 10) % 10) === (int)$c[9];
}

function cedula_activo(): bool { return (string)ajuste('cedula_proveedor', '') !== '' && (string)ajuste('cedula_clave', '') !== ''; }

// Lo que respondió el proveedor → "APELLIDOS NOMBRES" en mayúsculas (como el SRI y el Registro Civil).
function cedula_nombre_de(array $j): string {
  $d = $j['data'] ?? $j['name'] ?? $j['result'] ?? $j;
  if (isset($d['name']) && is_array($d['name'])) $d = $d['name'];
  if (isset($d['data']['name']) && is_array($d['data']['name'])) $d = $d['data']['name'];
  $ap = trim((string)($d['last_name'] ?? $d['last'] ?? $d['apellidos'] ?? ''));
  $no = trim((string)($d['first_name'] ?? $d['first'] ?? $d['nombres'] ?? ''));
  $n = ($ap !== '' && $no !== '') ? $ap . ' ' . $no : trim((string)($d['full_name'] ?? $d['full'] ?? $d['nombre'] ?? $d['nombreCompleto'] ?? ''));
  $n = preg_replace('/\s+/u', ' ', $n);
  return mb_strlen($n) >= 3 ? mb_strtoupper($n, 'UTF-8') : '';
}

function cedula_http(string $metodo, string $url, array $cabeceras, ?string $cuerpo): array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6, CURLOPT_HTTPHEADER => $cabeceras, CURLOPT_CUSTOMREQUEST => $metodo]);
    if ($cuerpo !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $cuerpo);
    $r = curl_exec($ch);
    $codigo = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$codigo, is_string($r) ? $r : ''];
  }
  $ctx = stream_context_create(['http' => ['method' => $metodo, 'header' => implode("\r\n", $cabeceras), 'content' => $cuerpo ?? '', 'timeout' => 12, 'ignore_errors' => true]]);
  $r = @file_get_contents($url, false, $ctx);
  $codigo = 0;
  foreach ($http_response_header ?? [] as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $codigo = (int)$m[1];
  return [$codigo, is_string($r) ? $r : ''];
}

// Pregunta al proveedor configurado. Devuelve el nombre, '' si la cédula no existe, o lanza si el servicio falló.
function cedula_proveedor(string $c): string {
  $prov = (string)ajuste('cedula_proveedor', '');
  $clave = (string)ajuste('cedula_clave', '');
  $base = getenv('FX_CEDULA_BASE') ?: ''; // solo pruebas automáticas (servidor que imita al proveedor)
  $cab = ['Authorization: Bearer ' . $clave, 'Accept: application/json'];
  if ($prov === 'ecuadorapi') {
    [$codigo, $txt] = cedula_http('GET', ($base ?: 'https://api.ecuadorapi.com') . '/api/v1/cedulas/' . $c . '/nombres', $cab, null);
  } elseif ($prov === 'datacil') {
    [$codigo, $txt] = cedula_http('POST', ($base ?: 'https://api.datacil.com') . '/v2/ecuador/query', array_merge($cab, ['Content-Type: application/json']), json_encode(['search' => ['type' => 'id', 'value' => $c], 'fields' => ['name']]));
  } else {
    throw new RuntimeException('Consulta de cédulas no configurada.');
  }
  if ($codigo === 404) return '';
  if ($codigo === 401 || $codigo === 403) throw new RuntimeException('El proveedor de cédulas rechazó la clave (revisa Ajustes del portal).');
  if ($codigo === 402) throw new RuntimeException('El proveedor de cédulas se quedó sin saldo.');
  if ($codigo === 429) throw new RuntimeException('El proveedor de cédulas está limitando las consultas; intenta en un minuto.');
  if ($codigo < 200 || $codigo >= 300) throw new RuntimeException('El proveedor de cédulas no respondió (' . ($codigo ?: 'sin conexión') . ').');
  $j = json_decode($txt, true);
  return is_array($j) ? cedula_nombre_de($j) : '';
}

// Nombre de una cédula para una licencia. ['nombre' => string, 'fuente' => 'cache'|proveedor] o null si no hay datos.
function cedula_consultar(int $licencia, string $c): ?array {
  $c = preg_replace('/\D/', '', $c);
  if (!cedula_valida($c)) return null;
  cedula_esquema();
  $r = db()->prepare('SELECT nombre, creado FROM cedula_nombres WHERE cedula = ?');
  $r->execute([$c]);
  $g = $r->fetch();
  if ($g && strtotime((string)$g['creado']) > time() - CEDULA_CACHE_DIAS * 86400) {
    db()->prepare('INSERT INTO cedula_consultas (licencia_id, pagada, creado) VALUES (?, 0, ?)')->execute([$licencia, ahora()]);
    return ['nombre' => (string)$g['nombre'], 'fuente' => 'cache'];
  }
  if (!cedula_activo()) return null;
  $limite = max(1, (int)ajuste('cedula_limite_dia', '150'));
  $r = db()->prepare('SELECT COUNT(*) FROM cedula_consultas WHERE licencia_id = ? AND pagada = 1 AND creado >= ?');
  $r->execute([$licencia, date('Y-m-d') . ' 00:00:00']);
  if ((int)$r->fetchColumn() >= $limite) throw new RuntimeException('Llegaste al límite de ' . $limite . ' consultas de cédula por hoy. Escribe el nombre a mano.');
  $nombre = cedula_proveedor($c);
  db()->prepare('INSERT INTO cedula_consultas (licencia_id, pagada, creado) VALUES (?, 1, ?)')->execute([$licencia, ahora()]);
  if ($nombre === '') return null;
  $prov = (string)ajuste('cedula_proveedor', '');
  db()->prepare('DELETE FROM cedula_nombres WHERE cedula = ?')->execute([$c]);
  db()->prepare('INSERT INTO cedula_nombres (cedula, nombre, fuente, creado) VALUES (?, ?, ?, ?)')->execute([$c, $nombre, $prov, ahora()]);
  return ['nombre' => $nombre, 'fuente' => $prov];
}

// Consultas de hoy y del mes (para Ajustes).
function cedula_uso(): array {
  cedula_esquema();
  $q = fn(string $desde) => (int)db()->query("SELECT COUNT(*) FROM cedula_consultas WHERE pagada = 1 AND creado >= '" . $desde . "'")->fetchColumn();
  return ['hoy' => $q(date('Y-m-d') . ' 00:00:00'), 'mes' => $q(date('Y-m-01') . ' 00:00:00')];
}
