<?php
// Nota de crédito pedida desde el teléfono: devolución de productos o anulación de una factura autorizada. La emite
// el programa (con su firma y su secuencia) en cuanto la recoge; el stock vuelve a la bodega y lote de donde salió.
if (!defined('FX_PORTAL')) exit;
web_exigir($ctx, 'facturar');
$f = web_registro($ctx, 'comprobantes', (string)($_GET['id'] ?? ''));
if (!$f || ($f['cod_doc'] ?? '') !== '01') throw new WebError('Elige una factura.');
if (($f['estado'] ?? '') !== 'AUTORIZADO') throw new WebError('Solo se hace nota de crédito de una factura autorizada por el SRI.');
if (!empty($f['anulado'])) throw new WebError('La factura está anulada.');
$saldos = web_nc_saldos($ctx, $f);
$d = web_json($f['data'] ?? []);
web_cabecera($ctx, 'Nota de crédito', 'comprobantes');
echo web_titulo('Factura ' . $f['numero'], 'Nota de crédito');
$hayDevuelto = array_sum(array_map(fn($l) => $l['devuelto'], $saldos)) > 0;
?>
<form method="post" action="./?v=nc" class="columnas">
  <?= web_campo_csrf() ?><input type="hidden" name="factura" value="<?= e($f['id']) ?>"><input type="hidden" name="volver" value="v=nc&id=<?= e(urlencode($f['id'])) ?>">
  <section class="tarjeta col-ancha">
    <div class="tarjeta-cab"><h2><?= e($d['cliente']['nombre'] ?? $f['cliente_nombre'] ?? '') ?></h2><span class="badge t-n"><?= dinero($f['total']) ?></span></div>
    <div class="segmentos" role="group" aria-label="Tipo de nota de crédito" style="margin-bottom:10px">
      <label class="seg"><input type="radio" name="tipo" value="DEVOLUCION" checked> Devolución de productos</label>
      <?php if (!$hayDevuelto): ?><label class="seg"><input type="radio" name="tipo" value="ANULACION"> Anular toda la factura</label><?php endif; ?>
    </div>
    <div class="tabla-items">
      <div class="ti-cab"><span>Producto</span><span class="der">Vendido</span><span class="der">Ya devuelto</span><span class="der">Devolver</span></div>
      <?php foreach ($saldos as $i => $l): ?>
        <div class="ti-fila"><span><b><?= e($l['descripcion']) ?></b><small class="mono"><?= e($l['codigo']) ?></small></span><span class="der"><?= e(web_cant($l['cantidad'])) ?></span><span class="der"><?= e(web_cant($l['devuelto'])) ?></span>
          <span class="der"><?php if ($l['saldo'] > 0): ?><input name="cant[<?= (int)$i ?>]" type="number" min="0" max="<?= e((string)$l['saldo']) ?>" step="any" inputmode="decimal" placeholder="0" style="width:90px;text-align:right"><?php else: ?><span class="muted">—</span><?php endif; ?></span></div>
      <?php endforeach; ?>
    </div>
    <label class="ancho" style="display:block;margin-top:10px">Motivo<input name="motivo" required minlength="3" maxlength="200" placeholder="Producto fallado, cambio, error en la factura…"></label>
    <div class="fila"><button class="btn btn-primario" data-confirmar="¿Enviar la nota de crédito? Tu programa la emitirá y la enviará al SRI.">Emitir nota de crédito</button><a class="btn btn-fantasma" href="./?v=comprobante&id=<?= e(urlencode($f['id'])) ?>">Volver</a></div>
  </section>
  <section class="tarjeta col-angosta">
    <h2>Cómo funciona</h2>
    <p class="muted pequeno">Tu programa emite la nota con su firma, la envía al SRI y devuelve los productos al inventario (a la misma bodega y lote de donde salieron). Si la computadora está apagada, se emite en cuanto la enciendas.</p>
    <p class="muted pequeno">«Anular toda la factura» devuelve todo. Para devolver solo una parte, escribe la cantidad en cada producto.</p>
  </section>
</form>
<?php web_pie();
