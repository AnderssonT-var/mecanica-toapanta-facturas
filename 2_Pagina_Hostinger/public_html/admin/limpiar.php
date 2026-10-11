<?php
require __DIR__ . '/_lib.php';
requiere_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_post_valido();
    if (trim((string)($_POST['confirmar'] ?? '')) !== 'BORRAR') {
        flash('No se borró nada: escribe BORRAR en mayúsculas para confirmar.', 'error');
        header('Location: limpiar.php');
        exit;
    }
    $pdo->beginTransaction();
    try {
        $n = borrar_facturas($pdo, $config, '1 = 1', []);
        $pdo->exec('DELETE FROM portal_access_links');
        $pdo->exec('DELETE FROM clients');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('No se pudo limpiar la base de datos. No se borró nada.', 'error');
        header('Location: limpiar.php');
        exit;
    }
    // Archivos sueltos que hubieran quedado sin registro
    $almacen = rtrim($config['private_storage'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    foreach (glob($almacen . '*') ?: [] as $archivo) {
        if (is_file($archivo) && preg_match('/^[a-f0-9]{64}$/', basename($archivo))) @unlink($archivo);
    }
    flash("Listo: se eliminaron $n factura(s) y todos los clientes.");
    header('Location: index.php');
    exit;
}

$facturas = (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn();
$clientes = (int)$pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();

admin_inicio($pdo, 'Limpiar base de datos', 'limpiar');
?>
<h1>Limpiar base de datos</h1>
<section class="caja caja-peligro">
<h2>Eliminar todas las facturas</h2>
<p class="texto">Se borrarán <strong><?= $facturas ?> factura(s)</strong> de <strong><?= $clientes ?> cliente(s)</strong>, con sus archivos PDF. Los enlaces que ya se enviaron por WhatsApp dejarán de funcionar.</p>
<p class="texto">Se conservan las sugerencias, las sucursales y la configuración.</p>
<p class="alerta alerta-error">Esto no se puede deshacer.</p>
<form method="post" class="formulario" style="max-width:360px" onsubmit="return confirm('Última confirmación: ¿borrar TODAS las facturas?');">
<?= csrf_campo() ?>
<label for="confirmar">Escribe BORRAR para confirmar</label>
<input id="confirmar" name="confirmar" autocomplete="off" required pattern="BORRAR">
<button class="boton boton-peligro" type="submit"<?= $facturas || $clientes ? '' : ' disabled' ?>>Eliminar todas las facturas</button>
</form>
</section>
<?php admin_fin();
