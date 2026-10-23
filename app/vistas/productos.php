<?php
if (!defined('FX_PORTAL')) exit;
$q = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 60;
[$filas, $total] = web_lista($ctx, 'productos', ['q' => $q], $porPagina, ($pagina - 1) * $porPagina);
$editar = (string)($_GET['editar'] ?? '');
$actual = $editar === 'nuevo' ? ['tarifa' => '4', 'precio' => ''] : ($editar !== '' ? web_registro($ctx, 'productos', $editar) : null);
$puedeEditar = web_puede($ctx, 'editar');
$tarifas = web_tarifas($ctx);
web_cabecera($ctx, 'Productos', 'productos');
echo web_titulo('Catálogo', 'Productos y servicios', $puedeEditar ? '<a class="btn btn-primario" href="./?v=productos&editar=nuevo">' . icono(ICO['facturar'], 18) . 'Nuevo producto</a>' : '');
if ($actual !== null && $puedeEditar): ?>
<section class="tarjeta editor">
  <div class="tarjeta-cab"><h2><?= isset($actual['id']) ? 'Editar producto' : 'Nuevo producto' ?></h2><a class="btn btn-sm" href="./?v=productos">Cerrar</a></div>
  <form method="post" action="./?v=productos" class="form rejilla">
    <?= web_campo_csrf() ?><input type="hidden" name="id" value="<?= e($actual['id'] ?? '') ?>"><input type="hidden" name="volver" value="v=productos&editar=<?= e($actual['id'] ?? 'nuevo') ?>">
    <label>Código<input name="codigo" class="mono" required maxlength="25" value="<?= e($actual['codigo'] ?? '') ?>"></label>
    <label>Código auxiliar<input name="codigo_aux" class="mono" maxlength="25" value="<?= e($actual['codigo_aux'] ?? '') ?>"></label>
    <label class="ancho">Descripción<input name="descripcion" required maxlength="300" value="<?= e($actual['descripcion'] ?? '') ?>"></label>
    <label>Precio sin IVA<input name="precio" type="number" min="0" step="0.000001" required value="<?= e((string)($actual['precio'] ?? '')) ?>" inputmode="decimal"></label>
    <label>IVA<select name="tarifa"><?php foreach ($tarifas as $k => $t) echo '<option value="' . e((string)$k) . '"' . ((string)($actual['tarifa'] ?? '4') === (string)$k ? ' selected' : '') . '>' . e($t['label']) . '</option>'; ?></select></label>
    <label class="ancho">Línea o categoría<input name="linea" maxlength="60" value="<?= e($actual['linea'] ?? '') ?>"></label>
    <div class="fila ancho"><button class="btn btn-primario">Guardar</button><?php if (isset($actual['id'])): ?><button class="btn btn-peligro" name="accion" value="eliminar" data-confirmar="¿Eliminar este producto?">Eliminar</button><?php endif; ?></div>
  </form>
</section>
<?php endif; ?>
<form class="filtros" method="get" action="./"><input type="hidden" name="v" value="productos">
  <label class="busca"><?= icono(ICO['buscar'], 18) ?><input name="q" value="<?= e($q) ?>" placeholder="Código o descripción" aria-label="Buscar"></label><button class="btn">Buscar</button></form>
<section class="tarjeta">
<?php if (!$filas): ?><div class="vacio"><?= $q ? 'No hay productos con ese texto.' : 'Aún no hay productos en la nube.' ?></div><?php else: ?>
  <div class="lista-simple">
  <?php foreach ($filas as $p): ?>
    <<?= $puedeEditar ? 'a href="./?v=productos&editar=' . e(urlencode($p['id'])) . ($q ? '&q=' . e(urlencode($q)) : '') . '"' : 'div' ?> class="fila-item">
      <span class="fi-info"><b><?= e($p['descripcion']) ?></b><small><span class="mono"><?= e($p['codigo']) ?></span><?= $p['linea'] ? ' · ' . e($p['linea']) : '' ?></small></span>
      <span class="fi-der"><b class="mono"><?= (float)$p['precio'] > 0 ? dinero($p['precio']) : '<span class="muted">sin precio</span>' ?></b><span class="badge t-n">IVA <?= e($tarifas[$p['tarifa']]['label'] ?? $p['tarifa']) ?></span></span>
    </<?= $puedeEditar ? 'a' : 'div' ?>>
  <?php endforeach; ?>
  </div>
  <?= web_paginacion($total, $pagina, $porPagina, array_filter(['v' => 'productos', 'q' => $q])) ?>
<?php endif; ?>
</section>
<?php web_pie();
