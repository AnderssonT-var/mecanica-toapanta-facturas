<?php
require __DIR__ . '/_lib.php';
requiere_admin();

$ver = ($_GET['ver'] ?? '') === 'nuevas' ? 'nuevas' : 'todas';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_post_valido();
    $id = (int)($_POST['id'] ?? 0);
    switch ((string)($_POST['accion'] ?? '')) {
        case 'leida':
            $pdo->prepare('UPDATE feedback SET is_read = 1 WHERE id = :id')->execute(['id' => $id]);
            break;
        case 'no_leida':
            $pdo->prepare('UPDATE feedback SET is_read = 0 WHERE id = :id')->execute(['id' => $id]);
            break;
        case 'todas_leidas':
            $pdo->exec('UPDATE feedback SET is_read = 1');
            flash('Todas marcadas como leídas.');
            break;
        case 'borrar':
            $pdo->prepare('DELETE FROM feedback WHERE id = :id')->execute(['id' => $id]);
            flash('Mensaje eliminado.');
            break;
    }
    header('Location: sugerencias.php' . ($ver === 'nuevas' ? '?ver=nuevas' : ''));
    exit;
}

$filas = $pdo->query('SELECT * FROM feedback' . ($ver === 'nuevas' ? ' WHERE is_read = 0' : '')
    . ' ORDER BY created_at DESC, id DESC LIMIT 300')->fetchAll();
$mapsGoogle = '';
foreach (sucursales($config) as $s) { if ($mapsGoogle = url_segura($s['maps_url'])) break; }

admin_inicio($pdo, 'Reseñas y sugerencias', 'sugerencias');
?>
<div class="fila-entre" style="flex-wrap:wrap">
<h1>Reseñas y sugerencias</h1>
<div class="acciones-fila">
<a class="boton <?= $ver === 'todas' ? 'boton-principal' : 'boton-claro' ?> boton-chico" href="sugerencias.php">Todas</a>
<a class="boton <?= $ver === 'nuevas' ? 'boton-principal' : 'boton-claro' ?> boton-chico" href="sugerencias.php?ver=nuevas">Sin leer</a>
<form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="todas_leidas"><button class="boton boton-claro boton-chico" type="submit">Marcar todas como leídas</button></form>
</div>
</div>
<p class="muted small">Mensajes que los clientes dejan en el formulario de la página. Las reseñas públicas de Google se ven en Google<?php if ($mapsGoogle): ?> (<a href="<?= e($mapsGoogle) ?>" target="_blank" rel="noopener">abrir en Google Maps</a>)<?php endif; ?>.</p>

<?php if (!$filas): ?>
<p class="caja muted"><?= $ver === 'nuevas' ? 'No hay mensajes sin leer.' : 'Aún no hay mensajes.' ?></p>
<?php else: ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Fecha</th><th>Cliente</th><th>Mensaje</th><th></th></tr></thead>
<tbody>
<?php foreach ($filas as $s): $wa = enlace_whatsapp($s['phone'], 'Hola ' . $s['name'] . ', gracias por tu mensaje a ' . (ajustes($config)['negocio'] ?: APP_NOMBRE_POR_DEFECTO) . '. '); ?>
<tr class="<?= $s['is_read'] ? '' : 'no-leida' ?>">
<td class="small"><?= e(fecha_larga($s['created_at'])) ?><br><span class="muted"><?= e(substr($s['created_at'], 11, 5)) ?></span><?= $s['is_read'] ? '' : '<br><span class="estado estado-nueva">Nueva</span>' ?></td>
<td><strong><?= e($s['name']) ?></strong><br><a href="clientes.php?q=<?= e($s['phone']) ?>" class="small"><?= e(telefono_local($s['phone'])) ?></a></td>
<td><div class="mensaje-sugerencia"><?= e($s['message']) ?></div></td>
<td class="acciones">
<?php if ($wa): ?><a class="boton boton-claro boton-chico" href="<?= e($wa) ?>" target="_blank" rel="noopener">Responder</a><?php endif; ?>
<form method="post"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
<input type="hidden" name="accion" value="<?= $s['is_read'] ? 'no_leida' : 'leida' ?>">
<button class="boton boton-claro boton-chico" type="submit"><?= $s['is_read'] ? 'No leída' : 'Leída' ?></button></form>
<form method="post" onsubmit="return confirm('¿Eliminar este mensaje?');"><?= csrf_campo() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
<input type="hidden" name="accion" value="borrar"><button class="boton boton-claro boton-chico" type="submit">Eliminar</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php admin_fin();
