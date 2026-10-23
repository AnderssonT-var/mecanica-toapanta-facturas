<?php
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$tarifas = web_tarifas($ctx);
// Accesos rápidos: productos más facturados en los últimos 180 días (o los primeros del catálogo).
$r = db()->prepare("SELECT datos FROM nube_registros WHERE licencia_id = ? AND perfil = ? AND tabla = 'comprobantes' AND tipo = '01' AND borrado = 0 AND fecha >= ? ORDER BY fecha DESC LIMIT 400");
$r->execute([$ctx['lic'], $ctx['perfil'], date('Y-m-d', strtotime('-180 days'))]);
$cuenta = [];
foreach ($r->fetchAll(PDO::FETCH_COLUMN) as $j) {
  $d = web_json((json_decode($j, true) ?: [])['data'] ?? []);
  foreach (($d['items'] ?? []) as $it) if (!empty($it['codigo'])) $cuenta[$it['codigo']] = ($cuenta[$it['codigo']] ?? 0) + 1;
}
arsort($cuenta);
[$catalogo] = web_lista($ctx, 'productos', [], 1000);
$porCodigo = [];
foreach ($catalogo as $p) $porCodigo[$p['codigo']] = $p;
$rapidos = [];
foreach (array_keys($cuenta) as $cod) { if (isset($porCodigo[$cod])) $rapidos[] = $porCodigo[$cod]; if (count($rapidos) >= 12) break; }
foreach ($catalogo as $p) { if (count($rapidos) >= 12) break; if (!in_array($p, $rapidos, true)) $rapidos[] = $p; }
$rapidos = array_map(fn($p) => ['id' => $p['id'], 'codigo' => $p['codigo'], 'codigo_aux' => $p['codigo_aux'] ?? '', 'descripcion' => $p['descripcion'], 'precio' => (float)$p['precio'], 'tarifa' => (string)$p['tarifa']], $rapidos);
$emp = $ctx['empresa'];
$config = ['tarifas' => $tarifas, 'rapidos' => $rapidos, 'formaPago' => $emp['formaPagoDefecto'] ?? '20', 'limiteCF' => 50, 'tarifaDefecto' => isset($tarifas['4']) ? '4' : (string)array_key_first($tarifas)];
$sync = $emp['_ultima_sync'] ?? null;
web_cabecera($ctx, 'Nueva factura', 'facturar');
echo web_titulo('Ventas', 'Nueva factura');
if (!$sync || time() - strtotime($sync) > 1800) {
  echo '<div class="aviso t-a">Tu programa no está conectado ' . ($sync ? 'desde ' . e(web_hace($sync)) : 'todavía') . '. Puedes preparar la factura: quedará en cola y se emitirá en cuanto abras el programa en tu computadora.</div>';
}
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
          <div class="rejilla" id="cli-datos">
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
