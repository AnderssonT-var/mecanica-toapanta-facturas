<?php
if (!defined('FX_PORTAL')) exit;
$p = web_registro($ctx, 'pedidos', (string)($_GET['id'] ?? ''));
if (!$p) throw new WebError('No se encontró la factura.');
$comp = !empty($p['comprobanteId']) ? web_registro($ctx, 'comprobantes', $p['comprobanteId']) : null;
$estado = $p['estado'] ?? 'PENDIENTE';
$esperando = in_array($estado, ['PENDIENTE', 'EN PROCESO'], true) || ($comp && !in_array($comp['estado'], ['AUTORIZADO', 'DEVUELTA', 'NO AUTORIZADO', 'INVALIDO'], true));
$sync = $ctx['empresa']['_ultima_sync'] ?? null;
$programaActivo = $sync && time() - strtotime($sync) < 900;
web_cabecera($ctx, 'Factura web', 'comprobantes');
$pasos = [
  ['Factura enviada a la nube', true, web_hace($p['creado'] ?? '')],
  ['Tu programa la tomó y la firmó', in_array($estado, ['EN PROCESO', 'EMITIDA'], true), $estado === 'EN PROCESO' ? 'procesando…' : ''],
  ['Enviada al SRI', $estado === 'EMITIDA', $p['numero'] ?? ''],
  ['Autorizada por el SRI', $comp && $comp['estado'] === 'AUTORIZADO', $comp ? (WEB_ESTADOS[$comp['estado']][0] ?? $comp['estado']) : ''],
];
echo web_titulo('Factura hecha en la web', $estado === 'EMITIDA' && $comp ? 'Factura ' . $comp['numero'] : ($estado === 'ERROR' ? 'No se pudo emitir' : ($estado === 'CANCELADO' ? 'Cancelada' : 'Emitiendo factura…')));
?>
<div class="columnas">
  <section class="tarjeta col-ancha">
    <div class="pasos<?= $esperando ? ' activo' : '' ?>"<?= $esperando ? ' data-recargar="5"' : '' ?>>
      <?php foreach ($pasos as $i => [$t, $ok, $det]):
        $actual = !$ok && ($i === 0 || $pasos[$i - 1][1]) && !in_array($estado, ['ERROR', 'CANCELADO'], true); ?>
        <div class="paso <?= $ok ? 'ok' : ($actual ? 'actual' : '') ?>"><span class="paso-p"><?= $ok ? icono('M5 12l5 5L20 7', 16) : ($i + 1) ?></span><span><b><?= e($t) ?></b><?php if ($det): ?><small><?= e($det) ?></small><?php endif; ?></span></div>
      <?php endforeach; ?>
    </div>
    <?php if ($estado === 'ERROR'): ?><div class="aviso t-r"><?= e($p['mensaje'] ?: 'El programa no pudo emitir la factura.') ?> Revisa los datos y vuelve a intentarlo, o hazla desde el programa.</div>
    <?php elseif ($estado === 'CANCELADO'): ?><div class="aviso t-n">Cancelaste esta factura antes de que se emitiera.</div>
    <?php elseif ($estado === 'PENDIENTE' && !$programaActivo): ?><div class="aviso t-a">Tu programa no está conectado ahora. La factura se emitirá en cuanto lo abras en tu computadora.</div><?php endif; ?>
    <div class="kv"><span>Cliente</span><b><?= e($p['cliente']['nombre'] ?? '') ?></b></div>
    <div class="kv"><span>Identificación</span><b class="mono"><?= e($p['cliente']['identificacion'] ?? '') ?></b></div>
    <div class="kv"><span>Total</span><b class="mono"><?= dinero($p['total'] ?? 0) ?></b></div>
    <div class="kv"><span>Hecha por</span><b><?= e(($p['usuarioNombre'] ?? '') ?: ($p['usuario'] ?? '')) ?></b></div>
    <div class="fila">
      <?php if ($comp): ?><a class="btn btn-primario" href="./?v=comprobante&id=<?= e(urlencode($comp['id'])) ?>">Ver factura</a><a class="btn" href="./?v=ride&id=<?= e(urlencode($comp['id'])) ?>" target="_blank" rel="noopener">RIDE</a><?php endif; ?>
      <?php if ($estado === 'PENDIENTE' && web_puede($ctx, 'facturar')): ?>
        <form method="post" action="./?v=pedido_cancelar"><?= web_campo_csrf() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>"><input type="hidden" name="volver" value="v=pedido&id=<?= e($p['id']) ?>"><button class="btn btn-peligro" data-confirmar="¿Cancelar esta factura? Todavía no se ha emitido.">Cancelar</button></form>
      <?php endif; ?>
      <a class="btn btn-fantasma" href="./?v=facturar">Nueva factura</a>
    </div>
  </section>
  <section class="tarjeta col-angosta">
    <h2>Detalle</h2>
    <?php foreach (($p['items'] ?? []) as $it): ?><div class="kv"><span><?= e(rtrim(rtrim(number_format((float)$it['cantidad'], 2, '.', ''), '0'), '.')) ?> × <?= e($it['descripcion']) ?></span><b class="mono"><?= dinero((float)$it['cantidad'] * (float)$it['precioUnitario'] * (1 - (float)($it['descuentoPct'] ?? 0) / 100)) ?></b></div><?php endforeach; ?>
    <p class="muted pequeno">Valores sin IVA. El total incluye impuestos.</p>
  </section>
</div>
<?php web_pie();
