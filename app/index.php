<?php
// App web del plan Full (Facmit en la nube). Una sola entrada: ./?v=<vista>
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

  // RIDE con enlace firmado (el que envía WhatsApp): se abre sin iniciar sesión y vence a los 30 días.
  if ($v === 'ride_publico') {
    $lic = (int)($_GET['l'] ?? 0); $perfil = preg_replace('/\D/', '', (string)($_GET['p'] ?? '')); $idDoc = (string)($_GET['id'] ?? '');
    if (!wa_enlace_valido($lic, $perfil, $idDoc, (int)($_GET['e'] ?? 0), (string)($_GET['t'] ?? ''))) { http_response_code(403); exit('Este enlace no es válido o ya venció.'); }
    $ctx = ['lic' => $lic, 'perfil' => $perfil, 'empresa' => nube_perfil($lic, $perfil) ?? ['ruc' => $perfil]];
    $publico = true;
    $_GET['id'] = $idDoc;
    require __DIR__ . '/vistas/ride.php';
    exit;
  }
  if ($v === 'xml_publico') {
    $lic = (int)($_GET['l'] ?? 0); $perfil = preg_replace('/\D/', '', (string)($_GET['p'] ?? '')); $idDoc = (string)($_GET['id'] ?? '');
    if (!wa_enlace_valido($lic, $perfil, $idDoc, (int)($_GET['e'] ?? 0), (string)($_GET['t'] ?? ''))) { http_response_code(403); exit('Este enlace no es válido o ya venció.'); }
    $ctx = ['lic' => $lic, 'perfil' => $perfil];
    $_GET['id'] = $idDoc;
    require __DIR__ . '/vistas/xml.php';
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
        case 'nc': require __DIR__ . '/vistas/nc_guardar.php'; exit;
        case 'inventario':
          if (!empty($_POST['cancelar'])) { web_inv_cancelar($ctx, (string)$_POST['cancelar']); web_aviso('Movimiento cancelado.'); web_ir('v=inventario'); }
          $p = web_inv_registrar($ctx, $_POST);
          web_aviso(INV_OPERACIONES[$p['operacion']] . ' registrado. Tu programa lo aplica en el inventario al sincronizar.');
          web_ir('v=inventario');
        case 'whatsapp':
          web_exigir($ctx, 'facturar');
          if (($_POST['accion'] ?? '') === 'quitar') {
            wa_esquema();
            db()->prepare('DELETE FROM nube_wa_numeros WHERE usuario_id = ?')->execute([$ctx['usuario']['id']]);
            web_aviso('Número quitado. Ya no puede facturar por WhatsApp.');
          } else {
            wa_registrar($ctx, (string)($_POST['telefono'] ?? ''));
            web_aviso('Número registrado. Envía el código desde ese WhatsApp para confirmarlo.');
          }
          web_ir('v=whatsapp');
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
  // Botón WhatsApp del portal: anota el envío en el historial y abre WhatsApp con el chat y el mensaje listos.
  if ($v === 'wa') {
    $c = web_registro($ctx, 'comprobantes', (string)($_GET['id'] ?? ''));
    if (!$c) { http_response_code(404); exit('No encontrado'); }
    $w = wa_enlace_comprobante($ctx, $c);
    wa_hist_registrar($ctx, (string)$c['id'], $w['telefono']);
    header('Location: ' . $w['url'], true, 302);
    exit;
  }
  if ($v === 'buscar') { require __DIR__ . '/vistas/buscar.php'; exit; }

  $vistas = ['inicio', 'comprobantes', 'comprobante', 'clientes', 'productos', 'facturar', 'pedido', 'whatsapp', 'nc', 'inventario'];
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
