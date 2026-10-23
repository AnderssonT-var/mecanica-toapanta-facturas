<?php
// RIDE para ver, imprimir o guardar como PDF. Se dibuja en el navegador con el mismo código que usa el programa.
if (!defined('FX_PORTAL')) exit;
$c = web_registro($ctx, 'comprobantes', (string)($_GET['id'] ?? ''));
if (!$c) { http_response_code(404); exit('No encontrado'); }
$c['data'] = web_json($c['data'] ?? []);
$emp = $ctx['empresa'];
$logo = (string)($emp['logo'] ?? '');
unset($emp['logo'], $emp['_ultima_sync'], $emp['tarifas']);
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; img-src 'self' data:; frame-src 'self'; form-action 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
$datos = ['c' => $c, 'empresa' => $emp, 'logo' => str_starts_with($logo, 'data:image/') ? $logo : '', 'programa' => 'Facturador'];
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>RIDE <?= e($c['numero']) ?></title><link rel="stylesheet" href="assets/app.css?v=<?= FX_VERSION ?>"></head>
<body class="pantalla-ride">
<div class="ride-barra"><a class="btn" href="./?v=comprobante&id=<?= e(urlencode($c['id'])) ?>">Volver</a><b><?= e($c['numero']) ?></b><button class="btn btn-primario" id="imprimir">Imprimir o guardar PDF</button></div>
<iframe id="ride" title="RIDE" class="ride-marco"></iframe>
<script type="application/json" id="ride-datos"><?= json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<script src="assets/ride.js?v=<?= FX_VERSION ?>"></script>
<script src="assets/ride-ver.js?v=<?= FX_VERSION ?>"></script>
</body></html>
