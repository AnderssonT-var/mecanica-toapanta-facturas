<?php
// Inventario completo: existencias por bodega, stock bajo, valorizado, kardex y movimientos (ingreso, ajuste,
// transferencia) que el programa aplica al sincronizar.
if (!defined('FX_PORTAL')) exit;
web_inv_exigir($ctx);
$bodegas = web_bodegas($ctx);
$q = trim((string)($_GET['q'] ?? ''));
$bodega = isset($_GET['b']) && $_GET['b'] !== '*' ? (string)$_GET['b'] : null;
if ($bodega !== null && !in_array($bodega, array_column($bodegas, 'id'), true)) $bodega = null;
$soloBajo = !empty($_GET['bajo']);
$hacer = (string)($_GET['hacer'] ?? '');
if (!isset(INV_OPERACIONES[$hacer])) $hacer = '';
$puede = web_puede($ctx, 'editar');
$kardexId = (string)($_GET['kardex'] ?? '');
$todos = web_inv_existencias($ctx, '', $bodega);
$res = web_inv_resumen($todos);
$lista = web_inv_existencias($ctx, $q, $bodega, $soloBajo);
$pedidos = web_inv_pedidos($ctx, 12);
$pendientes = array_values(array_filter($pedidos, fn($p) => in_array($p['estado'], ['PENDIENTE', 'EN PROCESO', 'ERROR'], true)));
$opBodegas = fn(string $sel = '', bool $todas = false) => ($todas ? '<option value="*">Todas las bodegas</option>' : '') . implode('', array_map(fn($b) => '<option value="' . e($b['id']) . '"' . ($sel === $b['id'] ? ' selected' : '') . '>' . e($b['nombre']) . '</option>', $bodegas));
$sync = $ctx['empresa']['_ultima_sync'] ?? null;

web_cabecera($ctx, 'Inventario', 'inventario');
$acciones = $puede ? implode('', array_map(fn($k, $t) => '<a class="btn' . ($k === 'INGRESO' ? ' btn-primario' : '') . '" href="./?v=inventario&hacer=' . $k . '">' . e(['INGRESO' => 'Ingreso', 'AJUSTE' => 'Ajuste', 'TRANSFERENCIA' => 'Transferencia'][$k]) . '</a>', array_keys(INV_OPERACIONES), INV_OPERACIONES)) : '';
echo web_titulo('Bodegas y stock', 'Inventario', $acciones);

if ($hacer !== '' && $puede):
  [$catalogo] = web_lista($ctx, 'productos', [], 3000);
?>
<datalist id="inv-productos"><?php foreach ($catalogo as $p) if (empty($p['deleted'])) echo '<option value="' . e($p['codigo']) . '">' . e($p['descripcion'] ?? '') . '</option>'; ?></datalist>
<section class="tarjeta editor">
  <div class="tarjeta-cab"><h2><?= e(INV_OPERACIONES[$hacer]) ?></h2><a class="btn btn-sm" href="./?v=inventario">Cerrar</a></div>
  <p class="muted pequeno">Tu programa lo aplica en el kardex en cuanto sincroniza<?= $sync ? ' (último contacto ' . e(web_hace($sync)) . ')' : '' ?>. Necesita Facmit 0.25 o más reciente en la computadora.</p>
  <form method="post" action="./?v=inventario" class="form rejilla inv-form">
    <?= web_campo_csrf() ?><input type="hidden" name="operacion" value="<?= e($hacer) ?>"><input type="hidden" name="volver" value="v=inventario&hacer=<?= e($hacer) ?>">
    <?php if ($hacer === 'AJUSTE'): ?>
      <label class="ancho">Producto (código)<input name="codigo" list="inv-productos" class="mono" required autocomplete="off"></label>
      <label>Bodega<select name="bodegaId"><?= $opBodegas() ?></select></label>
      <label>Cantidad contada (stock real)<input name="contado" type="number" min="0" step="any" required inputmode="decimal"></label>
      <label>Lote (si se controla por lote)<input name="lote" maxlength="30"></label>
      <label class="ancho">Motivo<input name="motivo" required minlength="3" maxlength="200" placeholder="Conteo físico, producto dañado, vencido…"></label>
    <?php else: ?>
      <?php if ($hacer === 'INGRESO'): ?>
        <label>Bodega de destino<select name="bodegaId"><?= $opBodegas() ?></select></label>
        <label>Documento (opcional)<input name="documento" maxlength="40" placeholder="Guía, nota de entrega…"></label>
        <label class="ancho">Proveedor (opcional)<input name="proveedor" maxlength="120"></label>
      <?php else: ?>
        <label>Desde la bodega<select name="origen"><?= $opBodegas() ?></select></label>
        <label>Hacia la bodega<select name="destino"><?= $opBodegas($bodegas[1]['id'] ?? '') ?></select></label>
      <?php endif; ?>
      <div class="ancho inv-lineas">
        <div class="inv-linea inv-cab"><span>Producto (código)</span><span>Cantidad</span><?= $hacer === 'INGRESO' ? '<span>Costo unitario</span><span>Lote</span><span>Vence</span>' : '<span>Lote</span>' ?></div>
        <?php for ($i = 0; $i < 6; $i++): ?>
          <div class="inv-linea">
            <input name="lineas[<?= $i ?>][codigo]" list="inv-productos" class="mono" autocomplete="off" aria-label="Producto" placeholder="Código del producto" <?= $i === 0 ? 'required' : '' ?>>
            <input name="lineas[<?= $i ?>][cantidad]" type="number" min="0" step="any" inputmode="decimal" aria-label="Cantidad" placeholder="Cantidad">
            <?php if ($hacer === 'INGRESO'): ?>
              <input name="lineas[<?= $i ?>][costo]" type="number" min="0" step="any" inputmode="decimal" placeholder="Costo (último)" aria-label="Costo">
              <input name="lineas[<?= $i ?>][lote]" maxlength="30" aria-label="Lote" placeholder="Lote (opcional)">
              <input name="lineas[<?= $i ?>][vence]" type="date" aria-label="Vence">
            <?php else: ?>
              <input name="lineas[<?= $i ?>][lote]" maxlength="30" aria-label="Lote" placeholder="Lote (opcional)">
            <?php endif; ?>
          </div>
        <?php endfor; ?>
        <small class="muted">Deja vacías las filas que no uses. ¿Más de 6 productos? Guarda y registra otro movimiento.</small>
      </div>
    <?php endif; ?>
    <label class="ancho">Nota (opcional)<input name="nota" maxlength="150"></label>
    <div class="fila ancho"><button class="btn btn-primario">Registrar <?= e(mb_strtolower(INV_OPERACIONES[$hacer])) ?></button></div>
  </form>
</section>
<?php endif; ?>

<section class="kpis">
  <div class="kpi oscuro"><span>Valor del inventario</span><b><?= dinero($res['valor']) ?></b><small>al costo promedio</small></div>
  <div class="kpi"><span>Productos con stock</span><b><?= $res['productos'] ?></b><small><?= e(web_cant($res['unidades'])) ?> unidades</small></div>
  <a class="kpi" href="./?v=inventario&bajo=1<?= $bodega !== null ? '&b=' . e(urlencode($bodega)) : '' ?>"><span>Bajo el mínimo</span><b class="<?= $res['bajo'] ? 'c-a' : '' ?>"><?= $res['bajo'] ?></b><small>reponer pronto</small></a>
  <div class="kpi"><span>Agotados</span><b class="<?= $res['agotados'] ? 'c-r' : '' ?>"><?= $res['agotados'] ?></b><small>sin stock</small></div>
</section>

<?php if ($pendientes): ?>
<section class="tarjeta cola">
  <div class="tarjeta-cab"><h2>Movimientos por aplicar</h2><span class="muted pequeno">Tu programa los registra al sincronizar</span></div>
  <?php foreach ($pendientes as $p): ?>
    <div class="doc">
      <span class="doc-tipo t-cola"><?= icono(ICO['inventario'], 18) ?></span>
      <span class="doc-info"><b><?= e($p['nombre']) ?></b><small><?= e($p['resumen']) ?> · <?= e(web_hace($p['creado'])) ?> · por <?= e($p['usuario']) ?><?= $p['estado'] === 'ERROR' ? ' · ' . e($p['mensaje']) : '' ?></small></span>
      <span class="doc-der"><?= $p['estado'] === 'ERROR' ? '<span class="badge t-r">No se aplicó</span>' : '<span class="badge t-a">' . ($p['estado'] === 'EN PROCESO' ? 'Aplicando' : 'En cola') . '</span>' ?>
        <?php if ($p['estado'] === 'PENDIENTE' && $puede): ?><form method="post" action="./?v=inventario"><?= web_campo_csrf() ?><input type="hidden" name="cancelar" value="<?= e($p['id']) ?>"><button class="btn btn-sm" data-confirmar="¿Cancelar este movimiento?">Cancelar</button></form><?php endif; ?></span>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($kardexId !== ''):
  $prod = null; foreach ($todos as $p) if ($p['id'] === $kardexId) $prod = $p;
  $kx = web_inv_kardex($ctx, $kardexId, $bodega);
?>
<section class="tarjeta">
  <div class="tarjeta-cab"><h2>Kardex · <?= e($prod['descripcion'] ?? 'Producto') ?></h2><a class="btn btn-sm" href="./?v=inventario<?= $bodega !== null ? '&b=' . e(urlencode($bodega)) : '' ?>">Cerrar</a></div>
  <?php if ($prod): ?><p class="muted pequeno"><span class="mono"><?= e($prod['codigo']) ?></span> · Stock <?= e(web_cant($prod['stock'])) ?> · Costo promedio <?= dinero($prod['costo']) ?> · <?= implode(' · ', array_map(fn($b) => e($b['nombre']) . ': ' . e(web_cant($b['stock'])), $prod['bodegas'])) ?></p><?php endif; ?>
  <?php if (!$kx['disponible']): ?>
    <div class="vacio">El detalle de movimientos llega con la nube de respaldo. Míralo en el programa (Inventario › Kardex).</div>
  <?php elseif (!$kx['filas']): ?>
    <div class="vacio">Este producto todavía no tiene movimientos.</div>
  <?php else: ?>
    <div class="tabla-items kardex">
      <div class="ti-cab"><span>Fecha · movimiento</span><span class="der">Entrada</span><span class="der">Salida</span><span class="der">Saldo</span></div>
      <?php foreach ($kx['filas'] as $m): ?>
        <div class="ti-fila"><span><b><?= e($m['tipo']) ?><?= $m['documento'] ? ' · ' . e($m['documento']) : '' ?></b><small><?= e(web_fecha(substr($m['fecha'], 0, 10))) ?> · <?= e($m['bodega']) ?><?= $m['lote'] ? ' · lote ' . e($m['lote']) : '' ?><?= $m['nota'] ? ' · ' . e($m['nota']) : '' ?></small></span>
          <span class="der mono"><?= $m['entrada'] ? e(web_cant($m['entrada'])) : '' ?></span><span class="der mono"><?= $m['salida'] ? e(web_cant($m['salida'])) : '' ?></span><span class="der mono"><b><?= e(web_cant($m['saldo'])) ?></b></span></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<form class="filtros" method="get" action="./"><input type="hidden" name="v" value="inventario">
  <label class="busca"><?= icono(ICO['buscar'], 18) ?><input name="q" value="<?= e($q) ?>" placeholder="Código, descripción o línea" aria-label="Buscar"></label>
  <?php if (count($bodegas) > 1): ?><select name="b" aria-label="Bodega"><?= $opBodegas($bodega ?? '*', true) ?></select><?php endif; ?>
  <label class="check"><input type="checkbox" name="bajo" value="1"<?= $soloBajo ? ' checked' : '' ?>> Solo bajo el mínimo</label>
  <button class="btn">Ver</button></form>
<section class="tarjeta">
<?php if (!$lista): ?><div class="vacio"><?= $q || $soloBajo ? 'Ningún producto coincide.' : 'Todavía no llegan existencias del programa. Activa «Controlar stock» en tus productos y sincroniza.' ?></div><?php else: ?>
  <div class="lista-simple">
  <?php foreach ($lista as $p): ?>
    <a href="./?v=inventario&kardex=<?= e(urlencode($p['id'])) ?><?= $bodega !== null ? '&b=' . e(urlencode($bodega)) : '' ?>" class="fila-item">
      <span class="fi-info"><b><?= e($p['descripcion']) ?></b><small><span class="mono"><?= e($p['codigo']) ?></span><?= $p['linea'] ? ' · ' . e($p['linea']) : '' ?><?= count($p['bodegas']) > 1 ? ' · ' . implode(' · ', array_map(fn($b) => e($b['nombre']) . ' ' . e(web_cant($b['stock'])), $p['bodegas'])) : '' ?></small></span>
      <span class="fi-der"><b class="mono <?= $p['agotado'] ? 'c-r' : ($p['bajo'] ? 'c-a' : '') ?>"><?= e(web_cant($p['stock'])) ?><?= $p['unidad'] ? ' ' . e($p['unidad']) : '' ?></b><?= $p['bajo'] ? '<span class="badge ' . ($p['agotado'] ? 't-r">Agotado' : 't-a">Bajo el mínimo') . '</span>' : '' ?><small class="muted"><?= dinero($p['valor']) ?></small></span>
    </a>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</section>
<?php web_pie();
