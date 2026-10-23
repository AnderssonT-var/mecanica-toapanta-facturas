<?php
// App web del plan Full (Facturador en la nube). Una sola entrada: ./?v=<vista>
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/web.php';

$v = preg_replace('/[^a-z_]/', '', (string)($_GET['v'] ?? 'inicio')) ?: 'inicio';
try {
  if (!is_file(dirname(__DIR__) . '/config.php')) { http_response_code(503); exit('Servicio no instalado.'); }
  web_sesion();
  nube_esquema();

  if ($v === 'login') {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      try {
        web_verificar_csrf();
        web_login((string)($_POST['usuario'] ?? ''), (string)($_POST['clave'] ?? ''));
        web_ir('');
      } catch (WebError $ex) { $error = $ex->getMessage(); }
    }
    require __DIR__ . '/vistas/login.php';
    exit;
  }

  $ctx = web_contexto();
  if (!$ctx) web_ir('v=login');

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
      web_verificar_csrf();
      switch ($v) {
        case 'salir': web_salir(); web_ir('v=login');
        case 'perfil':
          $p = (string)($_POST['perfil'] ?? '');
          if (in_array($p, $ctx['perfiles'], true)) $_SESSION['perfil'] = $p;
          web_ir('');
        case 'clientes': require __DIR__ . '/vistas/clientes_guardar.php'; exit;
        case 'productos': require __DIR__ . '/vistas/productos_guardar.php'; exit;
        case 'facturar': require __DIR__ . '/vistas/facturar_guardar.php'; exit;
        case 'pedido_cancelar': require __DIR__ . '/vistas/pedido_cancelar.php'; exit;
      }
      throw new WebError('Acción no válida.');
    } catch (WebError $ex) {
      web_aviso($ex->getMessage(), 'r');
      $vuelta = preg_replace('/[^a-z_=&0-9-]/i', '', (string)($_POST['volver'] ?? 'v=' . $v));
      web_ir($vuelta);
    }
  }

  // Descargas y páginas sin el diseño general.
  if ($v === 'xml') { require __DIR__ . '/vistas/xml.php'; exit; }
  if ($v === 'ride') { require __DIR__ . '/vistas/ride.php'; exit; }
  if ($v === 'buscar') { require __DIR__ . '/vistas/buscar.php'; exit; }

  $vistas = ['inicio', 'comprobantes', 'comprobante', 'clientes', 'productos', 'facturar', 'pedido'];
  if (!in_array($v, $vistas, true)) $v = 'inicio';
  require __DIR__ . '/vistas/' . $v . '.php';
} catch (WebError $ex) {
  web_aviso($ex->getMessage(), 'r');
  web_ir('');
} catch (Throwable $ex) {
  error_log('[app] ' . $ex->getMessage() . ' @' . $ex->getFile() . ':' . $ex->getLine());
  http_response_code(500);
  echo '<!doctype html><meta charset="utf-8"><title>Error</title><p style="font-family:sans-serif;padding:30px">Ocurrió un error. Intenta de nuevo en unos minutos.</p>';
}
