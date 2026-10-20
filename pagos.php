<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';
$admin = exigir_admin();
$desde = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['desde'] ?? '')) ? $_GET['desde'] : substr(hoy(), 0, 8) . '01';
$hasta = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['hasta'] ?? '')) ? $_GET['hasta'] : hoy();
$r = db()->prepare('SELECT p.*, l.cliente, l.ruc, l.plan FROM pagos p JOIN licencias l ON l.id = p.licencia_id WHERE p.fecha BETWEEN ? AND ? ORDER BY p.fecha DESC, p.id DESC');
$r->execute([$desde, $hasta]);
$filas = $r->fetchAll();
$total = array_sum(array_map(fn($x) => (float)$x['monto'], $filas));

if (($_GET['exportar'] ?? '') === 'csv') {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="pagos-' . $desde . '-a-' . $hasta . '.csv"');
  $o = fopen('php://output', 'w');
  fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['Fecha', 'Cliente', 'RUC', 'Plan', 'Meses', 'Monto', 'Método', 'Referencia', 'Vence nuevo']);
  foreach ($filas as $x) fputcsv($o, [$x['fecha'], $x['cliente'], $x['ruc'], $x['plan'], $x['meses'], number_format((float)$x['monto'], 2, '.', ''), $x['metodo'], $x['referencia'], $x['vence_nuevo']]);
  exit;
}

cabecera_html('Pagos', $admin, 'pagos');
?>
<div class="head"><div><span class="crumb">Cobros</span><h1>Pagos</h1></div></div>
<section class="card">
  <form class="filtros" method="get">
    <label>Desde<input type="date" name="desde" value="<?= e($desde) ?>"></label>
    <label>Hasta<input type="date" name="hasta" value="<?= e($hasta) ?>"></label>
    <button class="btn">Filtrar</button>
    <a class="btn btn-ghost" href="?desde=<?= e($desde) ?>&amp;hasta=<?= e($hasta) ?>&amp;exportar=csv">Exportar a Excel (CSV)</a>
  </form>
  <?php if (!$filas): ?><div class="empty">No hay pagos en ese rango.</div><?php else: ?>
  <div class="tabla"><table><thead><tr><th>Fecha</th><th>Cliente</th><th>Plan</th><th class="right">Meses</th><th>Método</th><th>Referencia</th><th>Nuevo vencimiento</th><th class="right">Monto</th></tr></thead><tbody>
  <?php foreach ($filas as $x): ?>
    <tr><td><?= fecha_ec($x['fecha']) ?></td><td><a href="licencia.php?id=<?= (int)$x['licencia_id'] ?>"><b><?= e($x['cliente']) ?></b></a><span class="sub mono"><?= e($x['ruc']) ?></span></td>
    <td><?= e(FX_PLANES[$x['plan']]['label'] ?? $x['plan']) ?></td><td class="right"><?= (int)$x['meses'] ?></td><td><?= e($x['metodo']) ?></td><td><?= e($x['referencia']) ?></td><td><?= fecha_ec($x['vence_nuevo']) ?></td><td class="right mono">$<?= number_format((float)$x['monto'], 2) ?></td></tr>
  <?php endforeach; ?></tbody><tfoot><tr><td colspan="7"><b>Total</b></td><td class="right mono"><b>$<?= number_format($total, 2) ?></b></td></tr></tfoot></table></div>
  <?php endif; ?>
</section>
<?php pie_html();
