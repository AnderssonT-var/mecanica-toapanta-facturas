<?php
require __DIR__ . '/_lib.php';
requiere_admin();

$buscar = preg_replace('/\D/', '', (string)($_GET['q'] ?? ''));
// "0959..." se busca como "593959..." porque así se guarda
if (str_starts_with($buscar, '0')) $buscar = '593' . substr($buscar, 1);
$porPagina = 50;
$pagina = max(1, (int)($_GET['p'] ?? 1));

$where = $buscar !== '' ? 'WHERE c.phone LIKE :q' : '';
$params = $buscar !== '' ? ['q' => '%' . $buscar . '%'] : [];

$total = $pdo->prepare("SELECT COUNT(*) FROM clients c $where");
$total->execute($params);
$total = (int)$total->fetchColumn();
$paginas = max(1, (int)ceil($total / $porPagina));
$pagina = min($pagina, $paginas);

$st = $pdo->prepare("SELECT c.phone,
        COUNT(i.id) AS total,
        SUM(i.expires_at >= CURDATE()) AS vigentes,
        MAX(i.invoice_date) AS ultima
    FROM clients c LEFT JOIN invoices i ON i.client_id = c.id
    $where
    GROUP BY c.id, c.phone
    ORDER BY MAX(i.invoice_date) IS NULL, MAX(i.invoice_date) DESC, c.id DESC
    LIMIT $porPagina OFFSET " . (($pagina - 1) * $porPagina));
$st->execute($params);
$filas = $st->fetchAll();

admin_inicio($pdo, 'Clientes y facturas', 'clientes');
?>
<h1>Clientes y facturas</h1>
<form class="buscador" method="get" role="search">
<input name="q" inputmode="tel" placeholder="Buscar por celular, ej. 0959133066" value="<?= e((string)($_GET['q'] ?? '')) ?>" aria-label="Buscar por celular">
<button class="boton boton-principal" type="submit">Buscar</button>
<?php if ($buscar !== ''): ?><a class="boton boton-claro" href="clientes.php">Ver todos</a><?php endif; ?>
</form>
<p class="muted small"><?= $total ?> cliente(s). El celular es el identificador de cada cliente.</p>

<?php if (!$filas): ?>
<p class="caja muted"><?= $buscar !== '' ? 'No hay clientes con ese número.' : 'Todavía no hay clientes.' ?></p>
<?php else: ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Celular (ID)</th><th>Última factura</th><th>Vigentes</th><th>Total</th><th></th></tr></thead>
<tbody>
<?php foreach ($filas as $c): ?>
<tr>
<td><strong><?= e(telefono_local($c['phone'])) ?></strong><br><span class="muted small"><?= e($c['phone']) ?></span></td>
<td><?= $c['ultima'] ? e(fecha_larga($c['ultima'])) : '<span class="muted">—</span>' ?></td>
<td><?= (int)$c['vigentes'] ?></td>
<td><?= (int)$c['total'] ?></td>
<td class="acciones"><a class="boton boton-borde boton-chico" href="cliente.php?tel=<?= e($c['phone']) ?>">Ver facturas</a></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($paginas > 1): ?>
<nav class="paginacion" aria-label="Páginas">
<?php if ($pagina > 1): ?><a class="boton boton-claro boton-chico" href="?q=<?= e(urlencode((string)($_GET['q'] ?? ''))) ?>&amp;p=<?= $pagina - 1 ?>">Anterior</a><?php endif; ?>
<span class="muted small" style="align-self:center">Página <?= $pagina ?> de <?= $paginas ?></span>
<?php if ($pagina < $paginas): ?><a class="boton boton-claro boton-chico" href="?q=<?= e(urlencode((string)($_GET['q'] ?? ''))) ?>&amp;p=<?= $pagina + 1 ?>">Siguiente</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<?php admin_fin();
