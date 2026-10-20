<?php
// API que usa el programa de escritorio:
//   POST api.php?a=activar     {clave, equipo:{id,nombre,so,version}}  → token firmado
//   POST api.php?a=verificar   {clave, equipo}                          → token renovado
//   POST api.php?a=desactivar  {clave, equipo}                          → libera el equipo
//   GET  api.php?a=salud                                                 → estado del servicio
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $cuerpo): void {
  http_response_code($status);
  echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (!is_file(__DIR__ . '/config.php')) responder(503, ['ok' => false, 'codigo' => 'NO_INSTALADO', 'error' => 'El servicio de licencias no está instalado.']);

$accion = (string)($_GET['a'] ?? '');
try {
  if ($accion === 'salud') responder(200, ['ok' => true, 'fechaServidor' => hoy(), 'version' => FX_VERSION, 'llavePublica' => trim(llave_publica_pem())]);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'codigo' => 'METODO', 'error' => 'Método no permitido.']);

  $ip = ip_cliente();
  $r = db()->prepare("SELECT COUNT(*) FROM intentos WHERE ip = ? AND tipo = 'api' AND creado > ?");
  $r->execute([$ip, date('Y-m-d H:i:s', time() - 600)]);
  if ((int)$r->fetchColumn() >= 20) responder(429, ['ok' => false, 'codigo' => 'LIMITE', 'error' => 'Demasiados intentos. Espera unos minutos.']);

  $raw = file_get_contents('php://input', false, null, 0, 16384);
  $in = json_decode((string)$raw, true);
  if (!is_array($in)) responder(400, ['ok' => false, 'codigo' => 'JSON', 'error' => 'Solicitud inválida.']);
  $clave = (string)($in['clave'] ?? '');
  $equipo = is_array($in['equipo'] ?? null) ? $in['equipo'] : [];

  switch ($accion) {
    case 'activar': responder(200, api_activar($clave, $equipo));
    case 'verificar': responder(200, api_verificar($clave, $equipo));
    case 'desactivar': responder(200, api_desactivar($clave, $equipo));
    default: responder(404, ['ok' => false, 'codigo' => 'ACCION', 'error' => 'Acción desconocida.']);
  }
} catch (ErrorLicencia $ex) {
  if (in_array($ex->codigo, ['NO_EXISTE', 'FORMATO'], true)) {
    db()->prepare("INSERT INTO intentos (ip, tipo, creado) VALUES (?, 'api', ?)")->execute([ip_cliente(), date('Y-m-d H:i:s')]);
  }
  $status = ['NO_EXISTE' => 404, 'FORMATO' => 400, 'EQUIPO' => 400, 'SUSPENDIDA' => 403, 'LIMITE_EQUIPOS' => 409, 'EQUIPO_LIBERADO' => 409][$ex->codigo] ?? 400;
  responder($status, ['ok' => false, 'codigo' => $ex->codigo, 'error' => $ex->getMessage()]);
} catch (Throwable $ex) {
  error_log('[licencias] ' . $ex->getMessage());
  responder(500, ['ok' => false, 'codigo' => 'INTERNO', 'error' => 'Error interno del servicio de licencias.']);
}
