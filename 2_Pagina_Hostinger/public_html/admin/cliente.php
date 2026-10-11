<?php
require __DIR__ . '/_lib.php';
requiere_admin();

$tel = preg_replace('/\D/', '', (string)($_GET['tel'] ?? $_POST['tel'] ?? ''));
$st = $pdo->prepare('SELECT id, phone FROM clients WHERE phone = :p LIMIT 1');
$st->execute(['p' => $tel]);
$cliente = $st->fetch();
if (!$cliente) {
    flash('Ese cliente ya no existe.', 'error');
    header('Location: clientes.php');
    exit;
}
$volver = 'cliente.php?tel=' . rawurlencode($cliente['phone']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_post_valido();
    $accion = (string)($_POST['accion'] ?? '');
    $pdo->beginTransaction();
    try {
        if ($accion === 'borrar_factura') {
            $n = borrar_facturas($pdo, $config, 'id = :id AND client_id = :c',
                ['id' => (int)($_POST['id'] ?? 0), 'c' => (int)$cliente['id']]);
            $pdo->commit();
            flash($n ? 'Factura eliminada.' : 'La factura ya no existía.');
            header('Location: ' . $volver);
            exit;
        }
        if ($accion === 'borrar_cliente') {
            $n = borrar_facturas($pdo, $config, 'client_id = :c', ['c' => (int)$cliente['id']]);
            limpiar_clientes_huerfanos($pdo);
            $pdo->commit();
            flash("Se eliminaron $n factura(s) de " . telefono_local($cliente['phone']) . '.');
            header('Location: clientes.php');
            exit;
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('No se pudo eliminar. Intenta de nuevo.', 'error');
        header('Location: ' . $volver);
        exit;
    }
}

$st = $pdo->prepare('SELECT id, original_name, storage_name, invoice_date, expires_at FROM invoices
    WHERE client_id = :c ORDER BY invoice_date DESC, id DESC');
$st->execute(['c' => (int)$cliente['id']]);
$facturas = $st->fetchAll();
$almacen = rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
$wa = enlace_whatsapp($cliente['phone']);
$hoy = date('Y-m-d');

admin_inicio($pdo, 'Cliente ' . telefono_local($cliente['phone']), 'clientes');
?>
<p><a href="clientes.php">← Clientes</a></p>
<div class="fila-entre" style="flex-wrap:wrap">
<div>
<h1><?= e(telefono_local($cliente['phone'])) ?></h1>
<p class="muted small">ID: <?= e($cliente['phone']) ?> · <?= count($facturas) ?> factura(s)</p>
</div>
<div class="acciones-fila">
<?php if ($wa): ?><a class="boton boton-whatsapp boton-chico" style="width:auto" href="<?= e($wa) ?>" target="_blank" rel="noopener"><?= icono('whatsapp', 18) ?>Escribirle</a><?php endif; ?>
</div>
</div>

<?php if (!$facturas): ?>
<p class="caja muted">Este cliente no tiene facturas guardadas.</p>
<?php else: ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Factura</th><th>Archivo original</th><th>Disponible hasta</th><th>Estado</th><th></th></tr></thead>
<tbody>
<?php foreach ($facturas as $i => $f): $vig = $f['expires_at'] >= $hoy; $existe = is_file($almacen . $f['storage_name']); ?>
<tr>
<td><strong><?= e(fecha_larga($f['invoice_date'])) ?></strong><?= $i === 0 ? ' <span class="estado estado-nueva">Más reciente</span>' : '' ?></td>
<td class="muted small"><?= e($f['original_name']) ?><?= $existe ? '<br>' . e(tamano_legible((int)filesize($almacen . $f['storage_name']))) : '<br><span style="color:var(--error)">Archivo no encontrado</span>' ?></td>
<td><?= e(fecha_larga($f['expires_at'])) ?></td>
<td><span class="estado <?= $vig ? 'estado-ok' : 'estado-vencida' ?>"><?= $vig ? 'Vigente' : 'Vencida' ?></span></td>
<td class="acciones">
<?php if ($existe): ?><a class="boton boton-claro boton-chico" href="factura.php?id=<?= (int)$f['id'] ?>" target="_blank" rel="noopener">Ver</a>
<a class="boton boton-claro boton-chico" href="factura.php?id=<?= (int)$f['id'] ?>&amp;dl=1">Descargar</a><?php endif; ?>
<form method="post" onsubmit="return confirm('¿Eliminar esta factura? No se puede deshacer.');">
<?= csrf_campo() ?><input type="hidden" name="tel" value="<?= e($cliente['phone']) ?>">
<input type="hidden" name="accion" value="borrar_factura"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
<button class="boton boton-claro boton-chico" type="submit">Eliminar</button>
</form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>

<section class="caja caja-peligro">
<h2>Eliminar este cliente</h2>
<p class="texto">Borra todas sus facturas y desactiva los enlaces que se le enviaron. No se puede deshacer.</p>
<form method="post" onsubmit="return confirm('¿Eliminar al cliente y TODAS sus facturas?');">
<?= csrf_campo() ?><input type="hidden" name="tel" value="<?= e($cliente['phone']) ?>">
<input type="hidden" name="accion" value="borrar_cliente">
<button class="boton boton-peligro" type="submit">Eliminar cliente y facturas</button>
</form>
</section>
<?php admin_fin();
