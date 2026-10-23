<?php
if (!defined('FX_PORTAL')) exit;
$f = ['q' => trim((string)($_GET['q'] ?? '')), 'tipo' => (string)($_GET['tipo'] ?? ''), 'estado' => (string)($_GET['estado'] ?? ''), 'desde' => (string)($_GET['desde'] ?? ''), 'hasta' => (string)($_GET['hasta'] ?? '')];
foreach (['desde', 'hasta'] as $k) if ($f[$k] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k])) $f[$k] = '';
if (!isset(WEB_DOCS[$f['tipo']])) $f['tipo'] = '';
$pagina = max(1, (int)($_GET['p'] ?? 1));
$porPagina = 40;
[$docs, $total] = web_lista($ctx, 'comprobantes', $f, $porPagina, ($pagina - 1) * $porPagina);
[$cola] = web_lista($ctx, 'pedidos', ['estado' => ''], 20, 0, 'fecha DESC');
$cola = array_values(array_filter($cola, fn($p) => in_array($p['estado'] ?? '', ['PENDIENTE', 'EN PROCESO', 'ERROR'], true)));
web_cabecera($ctx, 'Comprobantes', 'comprobantes');
echo web_titulo('Ventas y documentos', 'Comprobantes', web_puede($ctx, 'facturar') ? '<a class="btn btn-primario" href="./?v=facturar">' . icono(ICO['facturar'], 18) . 'Nueva factura</a>' : '');
if ($cola):
?>
<section class="tarjeta cola">
  <div class="tarjeta-cab"><h2>Facturas hechas en la web</h2><span class="muted pequeno">Tu programa las firma y envía al SRI cuando está abierto</span></div>
  <?php foreach ($cola as $p): ?>
    <a class="doc" href="./?v=pedido&id=<?= e(urlencode($p['id'])) ?>">
      <span class="doc-tipo t-cola"><?= icono(ICO['pedidos'], 18) ?></span>
      <span class="doc-info"><b><?= e($p['cliente']['nombre'] ?? '') ?></b><small><?= e(web_hace($p['creado'] ?? '')) ?> · por <?= e($p['usuario'] ?? '') ?></small></span>
      <span class="doc-der"><b class="mono"><?= dinero($p['total'] ?? 0) ?></b><?= ($p['estado'] ?? '') === 'ERROR' ? '<span class="badge t-r">Con error</span>' : '<span class="badge t-a">' . (($p['estado'] ?? '') === 'EN PROCESO' ? 'Procesando' : 'En cola') . '</span>' ?></span>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<form class="filtros" method="get" action="./">
  <input type="hidden" name="v" value="comprobantes">
  <label class="busca"><?= icono(ICO['buscar'], 18) ?><input name="q" value="<?= e($f['q']) ?>" placeholder="Cliente, cédula, RUC o número" aria-label="Buscar"></label>
  <select name="tipo" aria-label="Tipo"><option value="">Todos los tipos</option><?php foreach (WEB_DOCS as $k => $t) echo '<option value="' . $k . '"' . ($f['tipo'] === (string)$k ? ' selected' : '') . '>' . e($t) . '</option>'; ?></select>
  <select name="estado" aria-label="Estado"><?php foreach (['' => 'Todos los estados', 'AUTORIZADO' => 'Autorizados', 'PENDIENTES' => 'Pendientes', 'RECHAZADOS' => 'Rechazados'] as $k => $t) echo '<option value="' . $k . '"' . ($f['estado'] === $k ? ' selected' : '') . '>' . $t . '</option>'; ?></select>
  <label class="fecha"><span>Desde</span><input type="date" name="desde" value="<?= e($f['desde']) ?>"></label>
  <label class="fecha"><span>Hasta</span><input type="date" name="hasta" value="<?= e($f['hasta']) ?>"></label>
  <button class="btn">Filtrar</button>
</form>
<section class="tarjeta">
<?php if (!$docs): ?><div class="vacio">No hay comprobantes con esos filtros.</div><?php else: ?>
  <div class="lista-docs">
  <?php foreach ($docs as $c): ?>
    <a class="doc" href="./?v=comprobante&id=<?= e(urlencode($c['id'])) ?>">
      <span class="doc-tipo t-<?= e($c['cod_doc']) ?>"><?= e(['01' => 'FE', '03' => 'LC', '04' => 'NC', '05' => 'ND', '06' => 'GR', '07' => 'RT'][$c['cod_doc']] ?? '·') ?></span>
      <span class="doc-info"><b><?= e($c['cliente_nombre']) ?></b><small><span class="mono"><?= e($c['numero']) ?></span> · <?= e(WEB_DOCS[$c['cod_doc']] ?? '') ?> · <?= e(web_fecha($c['fecha'])) ?></small></span>
      <span class="doc-der"><b class="mono"><?= dinero($c['total']) ?></b><?= web_estado($c) ?></span>
    </a>
  <?php endforeach; ?>
  </div>
  <?= web_paginacion($total, $pagina, $porPagina, array_filter(['v' => 'comprobantes'] + $f)) ?>
<?php endif; ?>
</section>
<?php web_pie();
