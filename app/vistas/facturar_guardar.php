<?php
// Emite una factura desde la web (ver lib/emision.php). Valida los datos que llegan del formulario.
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$in = json_decode((string)($_POST['pedido'] ?? ''), true);
if (!is_array($in)) throw new WebError('No se recibió la factura. Intenta de nuevo.');
$tarifas = web_tarifas($ctx);
$cli = emi_validar_cliente(is_array($in['cliente'] ?? null) ? $in['cliente'] : []);
$items = emi_validar_items((array)($in['items'] ?? []), $tarifas);
web_exigir_stock($ctx, $items);
$forma = array_key_exists((string)($in['formaPago'] ?? ''), WEB_FORMAS_PAGO) ? (string)$in['formaPago'] : '20';
$dias = min(3650, max(0, (int)($in['diasCredito'] ?? 0)));
// La factura se emite aquí mismo en la nube: número, clave de acceso, XML y RIDE quedan listos aunque la
// computadora esté apagada. El programa la firma y la envía al SRI en cuanto se enciende.
$c = emi_emitir_factura($ctx, $cli, $items, $forma, $dias, mb_substr(trim((string)($in['observacion'] ?? '')), 0, 300));
web_aviso('Factura ' . $c['numero'] . ' emitida. El RIDE y el XML ya están listos.');
web_ir('v=comprobante&id=' . urlencode($c['id']));
