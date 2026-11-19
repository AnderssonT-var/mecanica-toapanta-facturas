<?php
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$tarifas = web_tarifas($ctx);
$rapidos = web_rapidos($ctx); // los más facturados en 180 días (o los primeros del catálogo)
$emp = $ctx['empresa'];
$config = ['tarifas' => $tarifas, 'rapidos' => $rapidos, 'bloquearSinStock' => !empty(web_cfg_inventario($ctx)['bloquearSinStock']), 'formaPago' => $emp['formaPagoDefecto'] ?? '20', 'limiteCF' => 50, 'tarifaDefecto' => isset($tarifas['4']) ? '4' : (string)array_key_first($tarifas)];
$sync = $emp['_ultima_sync'] ?? null;
web_cabecera($ctx, 'Nueva factura', 'facturar');
echo web_titulo('Ventas', 'Nueva factura');
echo web_aviso_dia($ctx, true);
?>
<form id="facturar" class="facturar" method="post" action="./?v=facturar">
  <?= web_campo_csrf() ?><input type="hidden" name="pedido" id="pedido-json"><input type="hidden" name="volver" value="v=facturar">
  <script type="application/json" id="facturar-config"><?= json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <div class="columnas">
    <div class="col-ancha pila">
      <section class="tarjeta">
        <div class="tarjeta-cab"><h2>Cliente</h2><div class="segmentos" role="group" aria-label="Tipo de cliente"><button type="button" class="on" data-tipo-cli="ID">Con identificación</button><button type="button" data-tipo-cli="CF">Consumidor final</button></div></div>
        <div id="cli-con-id">
          <div class="busca-sug"><label class="busca"><?= icono(ICO['buscar'], 18) ?><input id="cli-buscar" placeholder="Cédula, RUC o nombre del cliente" autocomplete="off" aria-label="Buscar cliente"></label><div class="sugerencias" id="cli-sug" hidden></div></div>
          <button type="button" class="btn btn-fantasma cli-manual" id="cli-manual">Escribir los datos del cliente a mano</button>
          <div class="rejilla" id="cli-datos" hidden>
            <label>Tipo<select id="cli-tipo"><option value="CEDULA">Cédula</option><option value="RUC">RUC</option><option value="PASAPORTE">Pasaporte / exterior</option></select></label>
            <label>Identificación<input id="cli-id" class="mono" inputmode="numeric" maxlength="20"></label>
            <label class="ancho">Apellidos y nombres / razón social<input id="cli-nombre" maxlength="300"></label>
            <label class="ancho">Dirección<input id="cli-direccion" maxlength="300"></label>
            <label>Correo para enviar la factura<input id="cli-email" type="email" maxlength="160"></label>
            <label>Teléfono<input id="cli-telefono" maxlength="40" inputmode="tel"></label>
          </div>
        </div>
        <div id="cli-cf" class="nota" hidden>Factura a <b>CONSUMIDOR FINAL</b> (9999999999999). Solo para ventas de hasta $50.</div>
      </section>
      <section class="tarjeta">
        <div class="tarjeta-cab"><h2>Detalle</h2></div>
        <div class="rapidos" id="rapidos" aria-label="Más vendidos"></div>
        <div class="busca-sug"><label class="busca"><?= icono(ICO['buscar'], 18) ?><input id="prod-buscar" placeholder="Buscar producto por código o descripción" autocomplete="off" aria-label="Buscar producto"></label><div class="sugerencias" id="prod-sug" hidden></div></div>
        <div id="lineas" class="lineas"></div>
        <button type="button" class="btn btn-fantasma" id="linea-libre">+ Línea libre</button>
      </section>
      <details class="tarjeta plegable">
        <summary><h2>Datos de la venta</h2><span class="muted pequeno" id="pago-res"></span></summary>
        <div class="rejilla">
          <label class="ancho">Forma de pago<select id="forma-pago"><?php foreach (WEB_FORMAS_PAGO as $k => $t) echo '<option value="' . $k . '">' . $k . ' · ' . e($t) . '</option>'; ?></select></label>
          <label>Días de crédito<input id="dias-credito" type="number" min="0" value="0"></label>
          <label class="ancho">Observación<input id="observacion" maxlength="300" placeholder="Aparece en la información adicional"></label>
        </div>
      </details>
    </div>
    <aside class="col-angosta">
      <section class="tarjeta resumen-fac" id="resumen"></section>
    </aside>
  </div>
  <div class="barra-total"><span><small>Total</small><b class="mono" id="total-movil">$0.00</b></span><button class="btn btn-primario btn-lg" id="enviar-movil" type="submit">Emitir factura</button></div>
</form>
<?php web_pie();
