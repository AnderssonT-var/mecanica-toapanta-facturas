<?php
// API que usa el programa de escritorio:
//   POST api.php?a=activar     {clave, equipo:{id,nombre,so,version}}  → token firmado
//   POST api.php?a=verificar   {clave, equipo}                          → token renovado
//   POST api.php?a=desactivar  {clave, equipo}                          → libera el equipo
//   POST api.php?a=latido      {clave, equipo, estado, errores}         → monitor de usuarios y errores
//   GET  api.php?a=salud                                                 → estado del servicio
//   GET  api.php?a=precios                                               → precios públicos (los de Ajustes)
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';
require __DIR__ . '/lib/monitor.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function responder(int $status, array $cuerpo): void {
  http_response_code($status);
  // Hora exacta del servidor (milisegundos UTC): el programa la usa para revisar el reloj del equipo.
  $cuerpo['ahoraMs'] = (int)round(microtime(true) * 1000);
  echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

if (!is_file(__DIR__ . '/config.php')) responder(503, ['ok' => false, 'codigo' => 'NO_INSTALADO', 'error' => 'El servicio de licencias no está instalado.']);

$accion = (string)($_GET['a'] ?? '');
try {
  if ($accion === 'salud') responder(200, ['ok' => true, 'fechaServidor' => hoy(), 'version' => FX_VERSION, 'llavePublica' => trim(llave_publica_pem())]);
  if ($accion === 'precios') {
    header('Cache-Control: public, max-age=300');
    header('Access-Control-Allow-Origin: *');
    responder(200, ['ok' => true] + precios_publicos());
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(405, ['ok' => false, 'codigo' => 'METODO', 'error' => 'Método no permitido.']);

  $ip = ip_cliente();
  $r = db()->prepare("SELECT COUNT(*) FROM intentos WHERE ip = ? AND tipo = 'api' AND creado > ?");
  $r->execute([$ip, date('Y-m-d H:i:s', time() - 600)]);
  if ((int)$r->fetchColumn() >= 20) responder(429, ['ok' => false, 'codigo' => 'LIMITE', 'error' => 'Demasiados intentos. Espera unos minutos.']);

  $raw = file_get_contents('php://input', false, null, 0, $accion === 'latido' ? 262144 : 16384);
  $in = json_decode((string)$raw, true);
  if (!is_array($in)) responder(400, ['ok' => false, 'codigo' => 'JSON', 'error' => 'Solicitud inválida.']);
  $clave = (string)($in['clave'] ?? '');
  $equipo = is_array($in['equipo'] ?? null) ? $in['equipo'] : [];

  switch ($accion) {
    case 'activar': responder(200, api_activar($clave, $equipo));
    case 'verificar': responder(200, api_verificar($clave, $equipo));
    case 'desactivar': responder(200, api_desactivar($clave, $equipo));
    // Datos del cliente OAuth de Google (para «Conectar con Google» del correo). Solo para equipos con licencia vigente.
    case 'oauth': {
      api_verificar($clave, $equipo);
      $id = (string)ajuste('google_client_id', ''); $sec = (string)ajuste('google_client_secret', '');
      if ($id === '' || $sec === '') responder(200, ['ok' => true, 'google' => null]);
      responder(200, ['ok' => true, 'google' => ['clientId' => $id, 'clientSecret' => $sec]]);
    }
    case 'latido': responder(200, api_latido($clave, $equipo, $in));
    default: responder(404, ['ok' => false, 'codigo' => 'ACCION', 'error' => 'Acción desconocida.']);
  }
} catch (ErrorLicencia $ex) {
  if (in_array($ex->codigo, ['NO_EXISTE', 'FORMATO'], true)) {
    db()->prepare("INSERT INTO intentos (ip, tipo, creado) VALUES (?, 'api', ?)")->execute([ip_cliente(), date('Y-m-d H:i:s')]);
  }
  $status = ['NO_EXISTE' => 404, 'FORMATO' => 400, 'EQUIPO' => 400, 'SUSPENDIDA' => 403, 'LIMITE_EQUIPOS' => 409, 'EQUIPO_LIBERADO' => 409][$ex->codigo] ?? 400;
  responder($status, ['ok' => false, 'codigo' => $ex->codigo, 'error' => $ex->getMessage()]);
} catch (ErrorBaseOcupada $ex) {
  header('Retry-After: 5');
  responder(503, ['ok' => false, 'codigo' => 'OCUPADO', 'error' => 'El servicio está muy ocupado. Se reintenta en unos segundos.']);
} catch (Throwable $ex) {
  error_log('[licencias] ' . $ex->getMessage());
  responder(500, ['ok' => false, 'codigo' => 'INTERNO', 'error' => 'Error interno del servicio de licencias.']);
}
