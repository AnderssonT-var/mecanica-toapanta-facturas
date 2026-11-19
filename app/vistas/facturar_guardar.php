<?php
// Emite una factura desde la web (ver web_facturar en lib/acciones.php y lib/emision.php).
if (!defined('FX_PORTAL')) exit;
$in = json_decode((string)($_POST['pedido'] ?? ''), true);
if (!is_array($in)) throw new WebError('No se recibió la factura. Intenta de nuevo.');
// La factura se emite aquí mismo en la nube: número, clave de acceso, XML y RIDE quedan listos aunque la
// computadora esté apagada. El programa la firma y la envía al SRI en cuanto se enciende (debe ser el mismo día).
$c = web_facturar($ctx, $in);
web_aviso('Factura ' . $c['numero'] . ' emitida. El RIDE y el XML ya están listos.');
web_ir('v=comprobante&id=' . urlencode($c['id']));
