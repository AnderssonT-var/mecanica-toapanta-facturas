<?php
// Facturar por WhatsApp: el usuario registra su número y lo confirma enviando un código desde ese WhatsApp.
if (!defined('FX_PORTAL')) exit;
$reg = wa_registro_usuario((int)$ctx['usuario']['id']);
$cfg = wa_cfg();
$numero = wa_tel($cfg['numero']);
$wa = fn(string $t) => 'https://wa.me/' . $numero . '?text=' . rawurlencode($t);
$ejemplo = "Factura\nRUC: 0990000000001\nNombre: Taller Ejemplo S.A.\nCorreo: cliente@ejemplo.com\nTeléfono: 0990000000\nProducto: Cambio de aceite, 1, 38.00\nProducto: 2 x Filtro de aceite 9.50\nPago: transferencia";
web_cabecera($ctx, 'WhatsApp', 'whatsapp');
echo web_titulo('Facturar por WhatsApp', 'WhatsApp');
if (!wa_activo() || $numero === ''): ?>
<section class="tarjeta"><div class="vacio">Facturar por WhatsApp todavía no está activado en Facmit. Pregunta a soporte.</div></section>
<?php else: ?>
<div class="columnas">
  <section class="tarjeta col-ancha">
    <div class="tarjeta-cab"><h2>1. Tu número de WhatsApp</h2></div>
    <?php if ($reg && (int)$reg['verificado']): ?>
      <p><span class="badge t-g">Confirmado</span> <b class="mono">+<?= e($reg['telefono']) ?></b> factura en <b><?= e(($ctx['empresa']['nombreComercial'] ?? '') ?: ($ctx['empresa']['razonSocial'] ?? $reg['perfil'])) ?></b> (RUC <?= e($reg['perfil']) ?>).</p>
      <form method="post" action="./?v=whatsapp" class="fila"><?= web_campo_csrf() ?><input type="hidden" name="accion" value="quitar"><button class="btn btn-peligro" data-confirmar="¿Quitar este número? Ya no podrá facturar por WhatsApp.">Quitar número</button></form>
    <?php elseif ($reg): ?>
      <p>Para confirmar que <b class="mono">+<?= e($reg['telefono']) ?></b> es tuyo, envía este mensaje <b>desde ese WhatsApp</b> al número de Facmit <b class="mono">+<?= e($numero) ?></b>:</p>
      <p class="codigo-wa mono">CODIGO <?= e($reg['codigo']) ?></p>
      <p><a class="btn btn-primario" href="<?= e($wa('CODIGO ' . $reg['codigo'])) ?>" target="_blank" rel="noopener">Abrir WhatsApp y enviar el código</a></p>
      <p class="muted pequeno">El código vence en 30 minutos. Cuando lo envíes, esta página mostrará «Confirmado».</p>
    <?php endif; ?>
    <?php if (!$reg || !(int)$reg['verificado']): ?>
    <form method="post" action="./?v=whatsapp" class="form rejilla"><?= web_campo_csrf() ?><input type="hidden" name="accion" value="registrar">
      <label class="ancho">Tu número de WhatsApp<input name="telefono" inputmode="tel" required placeholder="0991234567" value="<?= e($reg ? '+' . $reg['telefono'] : '') ?>"></label>
      <p class="muted pequeno ancho">Las facturas que envíes se emitirán en la empresa que tienes abierta ahora: <b><?= e(($ctx['empresa']['nombreComercial'] ?? '') ?: ($ctx['empresa']['razonSocial'] ?? $ctx['perfil'])) ?></b>.</p>
      <div class="fila ancho"><button class="btn btn-primario"><?= $reg ? 'Generar otro código' : 'Registrar número' ?></button></div>
    </form>
    <?php endif; ?>
  </section>
  <section class="tarjeta">
    <div class="tarjeta-cab"><h2>2. Cómo facturar</h2></div>
    <p>Escribe al número de Facmit <b class="mono">+<?= e($numero) ?></b> con los datos de la venta:</p>
    <pre class="ejemplo-wa"><?= e($ejemplo) ?></pre>
    <ul class="pequeno">
      <li>Los precios se entienden <b>con IVA incluido</b>. Si no lo incluyen, escribe «+iva».</li>
      <li>Si el producto está en tu catálogo, basta el código: <span class="mono">Producto: 00038 x 2</span>.</li>
      <li>Si el cliente ya está guardado, basta el RUC o la cédula.</li>
      <li>Facmit te responde con el resumen y solo emite cuando respondes <b>SI</b>. Luego te envía el número de factura y el enlace del RIDE.</li>
    </ul>
    <?php if ($reg && (int)$reg['verificado']): ?><a class="btn" href="<?= e($wa($ejemplo)) ?>" target="_blank" rel="noopener">Abrir WhatsApp con este ejemplo</a><?php endif; ?>
  </section>
</div>
<?php endif;
web_pie();
