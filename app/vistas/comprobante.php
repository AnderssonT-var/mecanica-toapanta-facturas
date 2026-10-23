<?php
if (!defined('FX_PORTAL')) exit;
$c = web_registro($ctx, 'comprobantes', (string)($_GET['id'] ?? ''));
if (!$c) throw new WebError('No se encontró el comprobante.');
$d = web_json($c['data'] ?? []);
$cli = $d['cliente'] ?? ['nombre' => $c['cliente_nombre'], 'identificacion' => $c['cliente_identificacion']];
$tot = $d['totales'] ?? [];
$items = $d['items'] ?? [];
$mensajes = web_json($c['mensajes'] ?? []);
$autorizado = ($c['estado'] ?? '') === 'AUTORIZADO';
$tipo = WEB_DOCS[$c['cod_doc']] ?? 'Comprobante';
web_cabecera($ctx, $tipo . ' ' . $c['numero'], 'comprobantes');
$wa = 'https://wa.me/?text=' . rawurlencode($tipo . ' ' . $c['numero'] . ' de ' . ($ctx['empresa']['razonSocial'] ?? '') . ' por ' . dinero($c['total']) . '. Clave de acceso: ' . $c['clave_acceso']);
echo web_titulo($tipo, $c['numero'], '<a class="btn" href="./?v=ride&id=' . e(urlencode($c['id'])) . '" target="_blank" rel="noopener">Ver RIDE</a><a class="btn" href="./?v=xml&id=' . e(urlencode($c['id'])) . '">Descargar XML</a><a class="btn btn-icono-txt" href="' . e($wa) . '" target="_blank" rel="noopener">WhatsApp</a>');
?>
<div class="columnas">
  <section class="tarjeta col-ancha">
    <div class="tarjeta-cab"><h2><?= e($cli['nombre'] ?? '') ?></h2><?= web_estado($c) ?></div>
    <div class="datos-grid">
      <div><span>Identificación</span><b class="mono"><?= e($cli['identificacion'] ?? '') ?></b></div>
      <div><span>Fecha de emisión</span><b><?= e(web_fecha($c['fecha'])) ?></b></div>
      <?php if (!empty($cli['email'])): ?><div><span>Correo</span><b><?= e($cli['email']) ?></b></div><?php endif; ?>
      <?php if (!empty($cli['direccion'])): ?><div><span>Dirección</span><b><?= e($cli['direccion']) ?></b></div><?php endif; ?>
      <?php if (!empty($d['formaPago'])): ?><div><span>Forma de pago</span><b><?= e(WEB_FORMAS_PAGO[$d['formaPago']] ?? $d['formaPago']) ?></b></div><?php endif; ?>
      <?php if (isset($d['diasCredito'])): ?><div><span>Condición</span><b><?= (int)$d['diasCredito'] > 0 ? 'Crédito ' . (int)$d['diasCredito'] . ' días' : 'Contado' ?></b></div><?php endif; ?>
    </div>
    <?php if ($items): ?>
    <div class="tabla-items">
      <div class="ti-cab"><span>Descripción</span><span class="der">Cant.</span><span class="der">P. unit.</span><span class="der">Subtotal</span></div>
      <?php foreach ($items as $it): ?>
        <div class="ti-fila"><span><b><?= e($it['descripcion'] ?? '') ?></b><small class="mono"><?= e($it['codigo'] ?? '') ?></small></span><span class="der"><?= e(rtrim(rtrim(number_format((float)($it['cantidad'] ?? 0), 2, '.', ''), '0'), '.')) ?></span><span class="der mono"><?= number_format((float)($it['precioUnitario'] ?? 0), 2) ?></span><span class="der mono"><b><?= dinero($it['base'] ?? 0) ?></b></span></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($tot): ?>
    <div class="totales">
      <?php foreach (($tot['impuestos'] ?? []) as $imp): ?><div><span>Subtotal <?= e((string)$imp['tarifa']) ?>%</span><span class="mono"><?= dinero($imp['base']) ?></span></div><?php endforeach; ?>
      <?php if ((float)($tot['totalDescuento'] ?? 0) > 0): ?><div><span>Descuento</span><span class="mono"><?= dinero($tot['totalDescuento']) ?></span></div><?php endif; ?>
      <?php foreach (($tot['impuestos'] ?? []) as $imp): if ((float)$imp['valor'] > 0): ?><div><span>IVA <?= e((string)$imp['tarifa']) ?>%</span><span class="mono"><?= dinero($imp['valor']) ?></span></div><?php endif; endforeach; ?>
      <div class="total"><span>Total</span><b class="mono"><?= dinero($c['total']) ?></b></div>
    </div>
    <?php else: ?><div class="totales"><div class="total"><span>Total</span><b class="mono"><?= dinero($c['total']) ?></b></div></div><?php endif; ?>
  </section>
  <section class="tarjeta col-angosta">
    <h2>SRI</h2>
    <div class="kv col"><span>Clave de acceso</span><b class="mono partir"><?= e($c['clave_acceso']) ?></b></div>
    <?php if ($autorizado): ?>
      <div class="kv col"><span>Número de autorización</span><b class="mono partir"><?= e($c['numero_autorizacion'] ?: $c['clave_acceso']) ?></b></div>
      <div class="kv"><span>Autorizado</span><b><?= e(web_fecha($c['fecha_autorizacion'] ?? '')) ?></b></div>
    <?php else: ?>
      <p class="muted pequeno">Tu programa se encarga de enviarlo y consultar la autorización. Si fue devuelto o no autorizado, corrígelo desde el programa.</p>
    <?php endif; ?>
    <?php if ($mensajes): ?><div class="mensajes"><?php foreach ($mensajes as $m): ?><div class="aviso t-<?= $autorizado ? 'a' : 'r' ?> pequeno"><?= e(is_array($m) ? trim(($m['identificador'] ?? '') . ' ' . ($m['mensaje'] ?? '') . ' ' . ($m['informacionAdicional'] ?? '')) : (string)$m) ?></div><?php endforeach; ?></div><?php endif; ?>
    <?php if (!empty($c['anulado'])): ?><div class="aviso t-r pequeno">Anulada<?= !empty($c['anulacion']) ? ': ' . e(is_string($c['anulacion']) ? $c['anulacion'] : '') : '' ?></div><?php endif; ?>
  </section>
</div>
<?php web_pie();
