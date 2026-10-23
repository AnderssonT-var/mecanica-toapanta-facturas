<?php
if (!defined('FX_PORTAL')) exit;
$q = trim((string)($_GET['q'] ?? ''));
$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 50;
[$filas, $total] = web_lista($ctx, 'clientes', ['q' => $q], $porPagina, ($pagina - 1) * $porPagina);
$editar = (string)($_GET['editar'] ?? '');
$actual = $editar === 'nuevo' ? ['tipo' => 'CEDULA'] : ($editar !== '' ? web_registro($ctx, 'clientes', $editar) : null);
$puedeEditar = web_puede($ctx, 'editar');
web_cabecera($ctx, 'Clientes', 'clientes');
echo web_titulo('Directorio', 'Clientes', $puedeEditar ? '<a class="btn btn-primario" href="./?v=clientes&editar=nuevo">' . icono(ICO['facturar'], 18) . 'Nuevo cliente</a>' : '');
if ($actual !== null && $puedeEditar): ?>
<section class="tarjeta editor">
  <div class="tarjeta-cab"><h2><?= isset($actual['id']) ? 'Editar cliente' : 'Nuevo cliente' ?></h2><a class="btn btn-sm" href="./?v=clientes">Cerrar</a></div>
  <form method="post" action="./?v=clientes" class="form rejilla">
    <?= web_campo_csrf() ?><input type="hidden" name="id" value="<?= e($actual['id'] ?? '') ?>"><input type="hidden" name="volver" value="v=clientes&editar=<?= e($actual['id'] ?? 'nuevo') ?>">
    <label>Tipo<select name="tipo"><?php foreach (['CEDULA' => 'Cédula', 'RUC' => 'RUC', 'PASAPORTE' => 'Pasaporte / exterior'] as $k => $t) echo '<option value="' . $k . '"' . (($actual['tipo'] ?? '') === $k ? ' selected' : '') . '>' . $t . '</option>'; ?></select></label>
    <label>Identificación<input name="identificacion" class="mono" required maxlength="20" value="<?= e($actual['identificacion'] ?? '') ?>" inputmode="numeric"></label>
    <label class="ancho">Apellidos y nombres / razón social<input name="nombre" required maxlength="300" value="<?= e($actual['nombre'] ?? '') ?>"></label>
    <label class="ancho">Dirección<input name="direccion" maxlength="300" value="<?= e($actual['direccion'] ?? '') ?>"></label>
    <label>Teléfono<input name="telefono" maxlength="40" value="<?= e($actual['telefono'] ?? '') ?>" inputmode="tel"></label>
    <label>Correo<input name="email" type="email" maxlength="160" value="<?= e($actual['email'] ?? '') ?>"></label>
    <div class="fila ancho"><button class="btn btn-primario">Guardar</button><?php if (isset($actual['id'])): ?><button class="btn btn-peligro" name="accion" value="eliminar" data-confirmar="¿Eliminar este cliente? Sus comprobantes no se borran.">Eliminar</button><?php endif; ?></div>
  </form>
</section>
<?php endif; ?>
<form class="filtros" method="get" action="./"><input type="hidden" name="v" value="clientes">
  <label class="busca"><?= icono(ICO['buscar'], 18) ?><input name="q" value="<?= e($q) ?>" placeholder="Nombre, cédula, RUC o correo" aria-label="Buscar"></label><button class="btn">Buscar</button></form>
<section class="tarjeta">
<?php if (!$filas): ?><div class="vacio"><?= $q ? 'No hay clientes con ese texto.' : 'Aún no hay clientes en la nube.' ?></div><?php else: ?>
  <div class="lista-simple">
  <?php foreach ($filas as $c): ?>
    <a class="fila-item" href="<?= $puedeEditar ? './?v=clientes&editar=' . e(urlencode($c['id'])) . ($q ? '&q=' . e(urlencode($q)) : '') : './?v=comprobantes&q=' . e(urlencode($c['identificacion'])) ?>">
      <span class="avatar pequeno"><?= e(mb_strtoupper(mb_substr($c['nombre'], 0, 1))) ?></span>
      <span class="fi-info"><b><?= e($c['nombre']) ?></b><small><span class="mono"><?= e($c['identificacion']) ?></span><?= $c['email'] ? ' · ' . e($c['email']) : '' ?><?= $c['telefono'] ? ' · ' . e($c['telefono']) : '' ?></small></span>
      <span class="fi-der muted"><?= e(['CEDULA' => 'Cédula', 'RUC' => 'RUC', 'PASAPORTE' => 'Pasaporte'][$c['tipo']] ?? $c['tipo']) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
  <?= web_paginacion($total, $pagina, $porPagina, array_filter(['v' => 'clientes', 'q' => $q])) ?>
<?php endif; ?>
</section>
<?php web_pie();
