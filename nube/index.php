<?php
// API de la nube (plan Full) que usa el programa de escritorio:
//   GET  api/health                    estado del servicio
//   POST api/sync/push  {cambios, empresa?}   sube cambios del programa
//   GET  api/sync/pull?desde=N         baja cambios (de la web u otros equipos) desde la revisión N
//   PUT  api/backup                    sube el respaldo completo (.db) del perfil
//   GET  api/backup/latest             descarga el último respaldo
//   POST api/pedidos/tomar {id}        reserva una factura hecha en la web para emitirla en este equipo
//   GET  api/usuarios · POST api/usuarios · DELETE api/usuarios?id=N   usuarios de la app web
// Autenticación: Authorization: Bearer <licencia FX1>, X-Ruc: <RUC del perfil>.
declare(strict_types=1);
define('FX_PORTAL', true);
require dirname(__DIR__) . '/lib/nucleo.php';
require dirname(__DIR__) . '/lib/nube.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function salir(int $status, array $cuerpo): void {
  http_response_code($status);
  echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function cuerpo_json(int $limite): array {
  $raw = (string)file_get_contents('php://input', false, null, 0, $limite + 1);
  if (strlen($raw) > $limite) throw new ErrorNube(413, 'Envío demasiado grande.');
  $in = json_decode($raw === '' ? '{}' : $raw, true);
  if (!is_array($in)) throw new ErrorNube(400, 'JSON inválido.');
  return $in;
}

$ruta = trim((string)($_GET['r'] ?? ''), '/');
$metodo = $_SERVER['REQUEST_METHOD'];
try {
  if (!is_file(dirname(__DIR__) . '/config.php')) throw new ErrorNube(503, 'El servicio no está instalado.');
  if ($ruta === 'health') salir(200, ['ok' => true, 'fechaServidor' => hoy(), 'version' => FX_VERSION]);

  $a = nube_autenticar_programa();
  $lic = (int)$a['licencia']['id'];
  $perfil = $a['perfil'];

  if ($ruta === 'sync/push' && $metodo === 'POST') {
    $in = cuerpo_json(64 * 1024 * 1024);
    $aceptados = nube_push($lic, $perfil, is_array($in['cambios'] ?? null) ? $in['cambios'] : []);
    if (is_array($in['empresa'] ?? null)) nube_guardar_perfil($lic, $perfil, $in['empresa'], $a['equipo']);
    else nube_marcar_contacto($lic, $perfil, $a['equipo']);
    salir(200, ['ok' => true, 'aceptados' => $aceptados, 'revision' => nube_revision_actual($lic, $perfil)]);
  }
  if ($ruta === 'sync/pull' && $metodo === 'GET') {
    $desde = max(0, (int)($_GET['desde'] ?? 0));
    nube_marcar_contacto($lic, $perfil, $a['equipo']);
    salir(200, ['ok' => true] + nube_pull($lic, $perfil, $desde));
  }
  if ($ruta === 'pedidos/tomar' && $metodo === 'POST') {
    $in = cuerpo_json(4096);
    $p = nube_cambiar_pedido($lic, $perfil, (string)($in['id'] ?? ''), 'PENDIENTE', 'EN PROCESO', ['equipo' => $a['equipo'], 'tomado' => gmdate('Y-m-d\TH:i:s\Z')]);
    if (!$p) throw new ErrorNube(409, 'Otro equipo ya tomó esta factura o fue cancelada.');
    salir(200, ['ok' => true, 'pedido' => $p]);
  }
  if ($ruta === 'backup' && $metodo === 'PUT') {
    $tmp = tempnam(sys_get_temp_dir(), 'fxr');
    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $bytes = stream_copy_to_stream($in, $out, 400 * 1024 * 1024 + 1);
    fclose($in); fclose($out);
    try {
      if ($bytes > 400 * 1024 * 1024) throw new ErrorNube(413, 'El respaldo es demasiado grande.');
      salir(200, ['ok' => true] + nube_guardar_respaldo($lic, $perfil, $tmp));
    } finally { @unlink($tmp); }
  }
  if ($ruta === 'backup/latest' && $metodo === 'GET') {
    $f = nube_ultimo_respaldo($lic, $perfil);
    if (!$f) throw new ErrorNube(404, 'No hay respaldos en la nube.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
  }
  if ($ruta === 'usuarios' && $metodo === 'GET') salir(200, ['ok' => true, 'usuarios' => nube_usuarios_licencia($lic)]);
  if ($ruta === 'usuarios' && $metodo === 'POST') {
    $in = cuerpo_json(16384);
    $u = nube_guardar_usuario($lic, (string)($in['usuario'] ?? ''), (string)($in['nombre'] ?? ''), (string)($in['clave'] ?? ''), (string)($in['rol'] ?? 'ADMIN'), ($in['activo'] ?? true) !== false);
    salir(200, ['ok' => true, 'usuarios' => nube_usuarios_licencia($lic), 'id' => (int)$u['id']]);
  }
  if ($ruta === 'usuarios' && $metodo === 'DELETE') {
    nube_eliminar_usuario($lic, (int)($_GET['id'] ?? 0));
    salir(200, ['ok' => true, 'usuarios' => nube_usuarios_licencia($lic)]);
  }
  throw new ErrorNube(404, 'Ruta no encontrada.');
} catch (ErrorNube $ex) {
  salir($ex->status, ['ok' => false, 'error' => $ex->getMessage()]);
} catch (Throwable $ex) {
  error_log('[nube] ' . $ex->getMessage() . ' @' . $ex->getFile() . ':' . $ex->getLine());
  salir(500, ['ok' => false, 'error' => 'Error interno de la nube.']);
}
