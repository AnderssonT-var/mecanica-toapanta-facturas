<?php
// Webhook de WhatsApp Business (Meta Cloud API) para facturar por WhatsApp. Ver app/lib/whatsapp.php.
// En Meta: URL de devolución de llamada = https://facmit.com/licencias/whatsapp.php, campo «messages».
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/app/lib/web.php';
header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$cfg = wa_cfg();
// Verificación de Meta al configurar el webhook.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $ok = ($_GET['hub_mode'] ?? '') === 'subscribe' && $cfg['verificar'] !== '' && hash_equals($cfg['verificar'], (string)($_GET['hub_verify_token'] ?? ''));
  if (!$ok) { http_response_code(403); exit('No autorizado'); }
  exit(preg_replace('/[^0-9A-Za-z_-]/', '', (string)($_GET['hub_challenge'] ?? '')));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
if (!WA_FACTURAR_CHAT) { http_response_code(200); exit('pausado'); } // facturar por chat en pausa: no se procesa nada

$cuerpo = (string)file_get_contents('php://input', false, null, 0, 512 * 1024);
if (!wa_firma_ok($cuerpo, (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''), $cfg['secreto'])) { http_response_code(401); exit('Firma no válida'); }
$datos = json_decode($cuerpo, true);
$mensajes = [];
foreach ((array)($datos['entry'] ?? []) as $entrada) {
  foreach ((array)($entrada['changes'] ?? []) as $cambio) {
    $v = $cambio['value'] ?? [];
    if (($v['metadata']['phone_number_id'] ?? '') !== $cfg['phone_id']) continue; // otro número de la misma app
    foreach ((array)($v['messages'] ?? []) as $m) $mensajes[] = $m;
  }
}
// Meta solo necesita el 200: se responde ya y se procesa después (si el servidor lo permite).
http_response_code(200);
echo 'ok';
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

foreach ($mensajes as $m) {
  $id = (string)($m['id'] ?? '');
  $de = preg_replace('/\D/', '', (string)($m['from'] ?? ''));
  if ($id === '' || $de === '' || !wa_mensaje_nuevo($id)) continue;
  if (($m['type'] ?? '') !== 'text') { wa_enviar($de, 'Por ahora solo leo mensajes de texto. ' . "Escribe *ayuda* para ver el formato."); continue; }
  $texto = mb_substr((string)($m['text']['body'] ?? ''), 0, 4000);
  try {
    $respuesta = wa_procesar($de, $texto);
  } catch (WebError $e) {
    $respuesta = '⚠️ ' . $e->getMessage();
  } catch (Throwable $e) {
    error_log('[whatsapp] ' . $e->getMessage());
    $respuesta = '⚠️ No pude procesar ese mensaje. Intenta de nuevo en un momento.';
  }
  if ($respuesta !== '') wa_enviar($de, $respuesta);
}
