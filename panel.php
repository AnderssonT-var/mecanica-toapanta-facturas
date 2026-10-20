<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';
$admin = exigir_admin();
$d = db();
$hoy = hoy();
$en7 = (new DateTimeImmutable($hoy))->modify('+7 day')->format('Y-m-d');
$mesIni = substr($hoy, 0, 8) . '01';

$q = fn($sql, $p = []) => (function () use ($d, $sql, $p) { $r = $d->prepare($sql); $r->execute($p); return $r; })();
$total = (int)$q('SELECT COUNT(*) FROM licencias')->fetchColumn();
$activas = (int)$q("SELECT COUNT(*) FROM licencias WHERE estado = 'ACTIVA' AND vence >= ?", [$hoy])->fetchColumn();
$porVencer = (int)$q("SELECT COUNT(*) FROM licencias WHERE estado = 'ACTIVA' AND vence BETWEEN ? AND ?", [$hoy, $en7])->fetchColumn();
$vencidas = (int)$q("SELECT COUNT(*) FROM licencias WHERE estado = 'ACTIVA' AND vence < ?", [$hoy])->fetchColumn();
$suspendidas = (int)$q("SELECT COUNT(*) FROM licencias WHERE estado = 'SUSPENDIDA'")->fetchColumn();
$equipos = (int)$q('SELECT COUNT(*) FROM equipos WHERE activo = 1')->fetchColumn();
$ingresosMes = (float)$q('SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE fecha >= ?', [$mesIni])->fetchColumn();
$mrr = (float)$q("SELECT COALESCE(SUM(CASE periodo WHEN 'ANUAL' THEN precio / 12 WHEN 'SEMESTRAL' THEN precio / 6 WHEN 'TRIMESTRAL' THEN precio / 3 ELSE precio END), 0) FROM licencias WHERE estado = 'ACTIVA' AND vence >= ?", [$hoy])->fetchColumn();
$porPlan = $q("SELECT plan, COUNT(*) n FROM licencias WHERE estado = 'ACTIVA' AND vence >= ? GROUP BY plan", [$hoy])->fetchAll();
$proximas = $q("SELECT * FROM licencias WHERE estado = 'ACTIVA' AND vence <= ? ORDER BY vence LIMIT 12", [$en7])->fetchAll();
$actividad = $q('SELECT e.*, l.cliente FROM eventos e LEFT JOIN licencias l ON l.id = e.licencia_id ORDER BY e.id DESC LIMIT 12')->fetchAll();

cabecera_html('Panel', $admin, 'panel');
?>
<div class="head"><div><span class="crumb">Resumen</span><h1>Panel</h1></div><a class="btn btn-accent" href="licencia.php">+ Nueva licencia</a></div>
<section class="kpis">
  <div class="kpi"><span>Licencias activas</span><b><?= $activas ?></b><small>de <?= $total ?> registradas</small></div>
  <div class="kpi"><span>Por vencer (7 días)</span><b class="<?= $porVencer ? 'c-a' : '' ?>"><?= $porVencer ?></b><small>renovar pronto</small></div>
  <div class="kpi"><span>Vencidas / suspendidas</span><b class="<?= ($vencidas + $suspendidas) ? 'c-r' : '' ?>"><?= $vencidas ?> / <?= $suspendidas ?></b><small>sin poder facturar</small></div>
  <div class="kpi"><span>Equipos activados</span><b><?= $equipos ?></b><small>programas instalados</small></div>
  <div class="kpi dark"><span>Cobrado este mes</span><b>$<?= number_format($ingresosMes, 2) ?></b><small>Ingreso mensual recurrente $<?= number_format($mrr, 2) ?></small></div>
</section>
<div class="cols">
  <section class="card col-2">
    <div class="card-head"><h2>Vencen pronto o ya vencieron</h2><a href="licencias.php?f=porvencer" class="link">Ver todas</a></div>
    <?php if (!$proximas): ?><div class="empty">Nada pendiente. Todas las licencias están al día.</div><?php else: ?>
    <table><thead><tr><th>Cliente</th><th>Plan</th><th>Vence</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php foreach ($proximas as $l): $est = estado_licencia($l); ?>
      <tr><td><b><?= e($l['cliente']) ?></b><span class="sub mono"><?= e($l['ruc']) ?></span></td><td><?= e(FX_PLANES[$l['plan']]['label']) ?></td>
      <td><?= fecha_ec($l['vence']) ?></td><td><?= insignia($est) ?></td><td class="right"><a class="btn btn-sm" href="licencia.php?id=<?= (int)$l['id'] ?>#renovar">Renovar</a></td></tr>
    <?php endforeach; ?></tbody></table><?php endif; ?>
  </section>
  <section class="card col-1">
    <h2>Por plan</h2>
    <?php foreach (FX_PLANES as $k => $p): $n = 0; foreach ($porPlan as $x) if ($x['plan'] === $k) $n = (int)$x['n']; ?>
      <div class="kv"><span><?= e($p['label']) ?></span><b><?= $n ?></b></div>
    <?php endforeach; ?>
    <h2 class="sep">Actividad reciente</h2>
    <?php foreach ($actividad as $ev): ?>
      <div class="ev"><span class="badge t-n"><?= e($ev['tipo']) ?></span> <span><?= e($ev['cliente'] ?? '') ?></span><span class="sub"><?= e($ev['detalle']) ?> · <?= e(substr($ev['creado'], 0, 16)) ?></span></div>
    <?php endforeach; ?>
    <?php if (!$actividad): ?><div class="empty">Sin actividad todavía.</div><?php endif; ?>
  </section>
</div>
<?php pie_html();
