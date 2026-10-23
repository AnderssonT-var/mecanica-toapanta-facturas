<?php
if (!defined('FX_PORTAL')) exit;
$c = web_registro($ctx, 'comprobantes', (string)($_GET['id'] ?? ''));
if (!$c) { http_response_code(404); exit('No encontrado'); }
$xml = (string)(($c['estado'] ?? '') === 'AUTORIZADO' && !empty($c['xml_autorizado']) ? $c['xml_autorizado'] : ($c['xml'] ?? ''));
header('Content-Type: application/xml; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/\D/', '', (string)$c['clave_acceso']) . '.xml"');
header('X-Content-Type-Options: nosniff');
echo $xml;
