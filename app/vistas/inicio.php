<?php
if (!defined('FX_PORTAL')) exit;
$res = web_resumen($ctx);
$dias = web_ventas_dias($ctx, 30);
[$ultimos] = web_lista($ctx, 'comprobantes', [], 8);
$sync = $ctx['empresa']['_ultima_sync'] ?? null;
$hora = (int)date('G');
$saludo = $hora < 12 ? 'Buenos días' : ($hora < 19 ? 'Buenas tardes' : 'Buenas noches');
$nombre = trim(explode(' ', (string)($ctx['usuario']['nombre'] ?: ''))[0]);
web_cabecera($ctx, 'Inicio', 'inicio');
echo web_titulo(fecha_ec(hoy()), $saludo . ($nombre ? ', ' . $nombre : ''), web_puede($ctx, 'facturar') ? '<a class="btn btn-primario" href="./?v=facturar">' . icono(ICO['facturar'], 18) . 'Nueva factura</a>' : '');

if (!$sync) {
  echo '<section class="tarjeta destacada"><h2>Conecta tu programa</h2><p class="muted">Todavía no llegan datos de tu programa de escritorio. Ábrelo en tu computadora y entra a <b>Nube y respaldos › Sincronizar ahora</b>. Desde ese momento, todo lo que hagas allá aparece aquí.</p></section>';
}
?>
<section class="kpis">
  <div class="kpi oscuro"><span>Ventas del mes</span><b><?= dinero($res['mes']['t']) ?></b><small><?= (int)$res['mes']['n'] ?> facturas autorizadas</small></div>
  <div class="kpi"><span>Hoy</span><b><?= dinero($res['hoy']['t']) ?></b><small><?= (int)$res['hoy']['n'] ?> facturas</small></div>
  <a class="kpi" href="./?v=comprobantes&estado=PENDIENTES"><span>Pendientes en el SRI</span><b class="<?= $res['pendientes'] ? 'c-a' : '' ?>"><?= $res['pendientes'] ?></b><small><?= $res['pedidos'] ? $res['pedidos'] . ' facturas web en cola' : 'se autorizan solos' ?></small></a>
  <a class="kpi" href="./?v=comprobantes&estado=RECHAZADOS"><span>Rechazados (30 días)</span><b class="<?= $res['rechazados'] ? 'c-r' : '' ?>"><?= $res['rechazados'] ?></b><small>revisar en el programa</small></a>
</section>
<div class="columnas">
  <section class="tarjeta col-ancha">
    <div class="tarjeta-cab"><h2>Ventas de los últimos 30 días</h2><span class="muted"><?= dinero(array_sum($dias)) ?></span></div>
    <?php
    $max = max(1, max($dias));
    $n = count($dias);
    $w = 100 / $n;
    echo '<svg class="grafico" viewBox="0 0 100 40" preserveAspectRatio="none" role="img" aria-label="Ventas por día">';
    $i = 0;
    foreach ($dias as $d => $t) {
      $h = $t > 0 ? max(1.2, $t / $max * 36) : 0.6;
      echo '<rect x="' . round($i * $w + $w * 0.15, 3) . '" y="' . round(40 - $h, 3) . '" width="' . round($w * 0.7, 3) . '" height="' . round($h, 3) . '" rx="0.6" class="' . ($t > 0 ? 'barra' : 'barra vacia') . ($d === hoy() ? ' hoy' : '') . '"><title>' . e(web_fecha($d)) . ': ' . e(dinero($t)) . '</title></rect>';
      $i++;
    }
    echo '</svg><div class="grafico-ejes"><span>' . e(web_fecha(array_key_first($dias))) . '</span><span>Hoy</span></div>';
    ?>
  </section>
  <section class="tarjeta col-angosta">
    <h2>Tu programa</h2>
    <div class="kv"><span>Última conexión</span><b><?= e(web_hace($sync)) ?></b></div>
    <div class="kv"><span>Siguiente factura</span><b class="mono"><?= e($ctx['empresa']['siguienteFactura'] ?? '—') ?></b></div>
    <div class="kv"><span>Ambiente SRI</span><b><?= ($ctx['empresa']['ambiente'] ?? '') === '2' ? 'Producción' : 'Pruebas' ?></b></div>
    <div class="kv"><span>Clientes</span><b><?= number_format($res['clientes']) ?></b></div>
    <p class="muted pequeno">Las facturas que hagas aquí las firma y envía tu programa, así tu firma electrónica nunca sale de tu computadora.</p>
  </section>
</div>
<section class="tarjeta">
  <div class="tarjeta-cab"><h2>Últimos comprobantes</h2><a class="enlace" href="./?v=comprobantes">Ver todos</a></div>
  <?php if (!$ultimos): ?><div class="vacio">Aún no hay comprobantes en la nube.</div><?php else: ?>
  <div class="lista-docs">
  <?php foreach ($ultimos as $c): ?>
    <a class="doc" href="./?v=comprobante&id=<?= e(urlencode($c['id'])) ?>">
      <span class="doc-tipo t-<?= e($c['cod_doc']) ?>"><?= e(['01' => 'FE', '03' => 'LC', '04' => 'NC', '05' => 'ND', '06' => 'GR', '07' => 'RT'][$c['cod_doc']] ?? '·') ?></span>
      <span class="doc-info"><b><?= e($c['cliente_nombre']) ?></b><small class="mono"><?= e($c['numero']) ?> · <?= e(web_fecha($c['fecha'])) ?></small></span>
      <span class="doc-der"><b class="mono"><?= dinero($c['total']) ?></b><?= web_estado($c) ?></span>
    </a>
  <?php endforeach; ?>
  </div><?php endif; ?>
</section>
<?php web_pie();
