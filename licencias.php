<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';
$admin = exigir_admin();
$hoy = hoy();
$en7 = (new DateTimeImmutable($hoy))->modify('+7 day')->format('Y-m-d');
$buscar = trim((string)($_GET['q'] ?? ''));
$filtro = (string)($_GET['f'] ?? 'todas');
$plan = (string)($_GET['plan'] ?? '');

$where = ['1 = 1'];
$p = [];
if ($buscar !== '') {
  $where[] = '(cliente LIKE ? OR ruc LIKE ? OR clave LIKE ? OR email LIKE ? OR rucs LIKE ?)';
  array_push($p, "%$buscar%", "%$buscar%", '%' . strtoupper($buscar) . '%', "%$buscar%", "%$buscar%");
}
if (isset(FX_PLANES[$plan])) { $where[] = 'plan = ?'; $p[] = $plan; }
switch ($filtro) {
  case 'activas': $where[] = "estado = 'ACTIVA' AND vence >= ?"; $p[] = $hoy; break;
  case 'porvencer': $where[] = "estado = 'ACTIVA' AND vence BETWEEN ? AND ?"; array_push($p, $hoy, $en7); break;
  case 'vencidas': $where[] = "estado = 'ACTIVA' AND vence < ?"; $p[] = $hoy; break;
  case 'suspendidas': $where[] = "estado = 'SUSPENDIDA'"; break;
}
$r = db()->prepare('SELECT l.*, (SELECT COUNT(*) FROM equipos q WHERE q.licencia_id = l.id AND q.activo = 1) AS equipos_activos
  FROM licencias l WHERE ' . implode(' AND ', $where) . ' ORDER BY l.vence ASC LIMIT 500');
$r->execute($p);
$filas = $r->fetchAll();

cabecera_html('Licencias', $admin, 'licencias');
$opt = fn($v, $t, $act) => '<option value="' . e($v) . '"' . ($act === $v ? ' selected' : '') . '>' . e($t) . '</option>';
?>
<div class="head"><div><span class="crumb">Clientes</span><h1>Licencias</h1></div><a class="btn btn-accent" href="licencia.php">+ Nueva licencia</a></div>
<section class="card">
  <form class="filtros" method="get">
    <input name="q" value="<?= e($buscar) ?>" placeholder="Cliente, RUC, clave o email" aria-label="Buscar">
    <select name="f" aria-label="Estado"><?= $opt('todas', 'Todas', $filtro) . $opt('activas', 'Activas', $filtro) . $opt('porvencer', 'Por vencer (7 días)', $filtro) . $opt('vencidas', 'Vencidas', $filtro) . $opt('suspendidas', 'Suspendidas', $filtro) ?></select>
    <select name="plan" aria-label="Plan"><option value="">Todos los planes</option><?php foreach (FX_PLANES as $k => $pl) echo $opt($k, $pl['label'], $plan); ?></select>
    <button class="btn">Filtrar</button>
  </form>
  <?php if (!$filas): ?><div class="empty">No hay licencias con esos filtros.</div><?php else: ?>
  <div class="tabla"><table><thead><tr><th>Código</th><th>Cliente</th><th>Clave</th><th>Plan</th><th>Vence</th><th class="right">Equipos</th><th>Estado</th></tr></thead><tbody>
  <?php foreach ($filas as $l): $est = estado_licencia($l); ?>
    <tr class="click"><td class="mono"><a href="licencia.php?id=<?= (int)$l['id'] ?>"><?= e(codigo_licencia($l)) ?></a></td>
    <td><a href="licencia.php?id=<?= (int)$l['id'] ?>"><b><?= e($l['cliente']) ?></b></a><span class="sub mono"><?= e($l['ruc']) ?></span></td>
    <td class="mono"><?= e($l['clave']) ?></td><td><?= e(FX_PLANES[$l['plan']]['label']) ?></td><td><?= fecha_ec($l['vence']) ?></td>
    <td class="right"><?= (int)$l['equipos_activos'] ?> / <?= (int)$l['equipos_max'] ?></td><td><?= insignia($est) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
  <p class="muted small"><?= count($filas) ?> licencia(s)</p>
  <?php endif; ?>
</section>
<?php pie_html();
