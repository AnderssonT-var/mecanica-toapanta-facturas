<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
$admin = exigir_admin();
$tipo = preg_replace('/[^A-Z_]/', '', strtoupper((string)($_GET['tipo'] ?? '')));
$sql = 'SELECT e.*, l.cliente FROM eventos e LEFT JOIN licencias l ON l.id = e.licencia_id' . ($tipo ? ' WHERE e.tipo = ?' : '') . ' ORDER BY e.id DESC LIMIT 300';
$r = db()->prepare($sql);
$r->execute($tipo ? [$tipo] : []);
$filas = $r->fetchAll();
$tipos = db()->query('SELECT DISTINCT tipo FROM eventos ORDER BY tipo')->fetchAll(PDO::FETCH_COLUMN);
cabecera_html('Actividad', $admin, 'eventos');
?>
<div class="head"><div><span class="crumb">Auditoría</span><h1>Actividad</h1></div></div>
<section class="card">
  <form class="filtros" method="get"><select name="tipo" aria-label="Tipo"><option value="">Todos los eventos</option>
  <?php foreach ($tipos as $t): ?><option<?= $t === $tipo ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select><button class="btn">Filtrar</button></form>
  <?php if (!$filas): ?><div class="empty">Sin actividad.</div><?php else: ?>
  <div class="tabla"><table><thead><tr><th>Fecha</th><th>Evento</th><th>Licencia</th><th>Detalle</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($filas as $x): ?>
    <tr><td class="mono"><?= e(substr($x['creado'], 0, 16)) ?></td><td><span class="badge t-n"><?= e($x['tipo']) ?></span></td>
    <td><?= $x['licencia_id'] ? '<a href="licencia.php?id=' . (int)$x['licencia_id'] . '">' . e($x['cliente']) . '</a>' : '—' ?></td><td><?= e($x['detalle']) ?></td><td class="mono"><?= e($x['ip']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php pie_html();
