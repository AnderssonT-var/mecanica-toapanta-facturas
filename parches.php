<?php
// Parches y antifallos: la constancia de todo lo que se corrigió o se protegió en el programa y el portal.
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/parches.php';
$admin = exigir_admin();
par_esquema();

$aviso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verificar_csrf();
  $accion = (string)($_POST['accion'] ?? '');
  $id = (int)($_POST['id'] ?? 0);
  try {
    if ($accion === 'guardar') {
      $id = par_guardar($_POST, (string)($admin['nombre'] ?? $admin['usuario'] ?? 'Admin'));
      registrar_evento(null, 'PARCHE', 'Registro ' . (par_obtener($id)['codigo'] ?? '') . ' guardado');
      header('Location: parches.php?ok=1#p' . $id);
      exit;
    }
    if ($accion === 'estado') {
      par_cambiar_estado($id, (string)($_POST['estado'] ?? ''));
      registrar_evento(null, 'PARCHE', 'Registro ' . (par_obtener($id)['codigo'] ?? '') . ' marcado ' . (string)$_POST['estado']);
      header('Location: parches.php#p' . $id);
      exit;
    }
    if ($accion === 'eliminar') {
      par_eliminar($id);
      header('Location: parches.php');
      exit;
    }
  } catch (Throwable $ex) { $aviso = $ex->getMessage(); }
}

$f = [
  'tipo' => array_key_exists($_GET['tipo'] ?? '', PAR_TIPOS) ? $_GET['tipo'] : '',
  'estado' => array_key_exists($_GET['estado'] ?? '', PAR_ESTADOS) ? $_GET['estado'] : '',
  'area' => array_key_exists($_GET['area'] ?? '', PAR_AREAS) ? $_GET['area'] : '',
  'riesgo' => array_key_exists($_GET['riesgo'] ?? '', PAR_RIESGOS) ? $_GET['riesgo'] : '',
  'q' => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80),
];
$lista = par_lista($f);
$todos = par_lista();
$cuenta = fn($k, $v) => count(array_filter($todos, fn($x) => $x[$k] === $v));
$editar = isset($_GET['editar']) ? par_obtener((int)$_GET['editar']) : null;
$nuevo = isset($_GET['nuevo']) || $editar || $aviso;
$v = $editar ?: ($aviso ? $_POST : []);
$tonoRiesgo = ['ALTO' => 't-r', 'MEDIO' => 't-a', 'BAJO' => 't-n'];
$tonoEstado = ['PLANEADO' => 't-a', 'APLICADO' => 't-b', 'PUBLICADO' => 't-g'];
$sel = function (string $name, array $ops, string $actual, string $vacio = '') {
  $h = '<select name="' . $name . '" aria-label="' . e($name) . '">' . ($vacio !== '' ? '<option value="">' . e($vacio) . '</option>' : '');
  foreach ($ops as $k => $t) $h .= '<option value="' . e($k) . '"' . ($actual === $k ? ' selected' : '') . '>' . e($t) . '</option>';
  return $h . '</select>';
};

cabecera_html('Parches', $admin, 'parches');
?>
<div class="head"><div><span class="crumb">Soporte</span><h1>Parches y antifallos</h1><p class="sub">Constancia de cada corrección y cada protección aplicada al programa y al portal.</p></div>
  <a class="btn btn-accent" href="parches.php?nuevo=1#form">+ Registrar</a></div>
<?php if ($aviso): ?><div class="aviso t-r"><?= e($aviso) ?></div><?php elseif (isset($_GET['ok'])): ?><div class="aviso t-g">Registro guardado.</div><?php endif; ?>

<section class="kpis">
  <div class="kpi"><span>Antifallos</span><b><?= $cuenta('tipo', 'ANTIFALLO') ?></b><small>protecciones para que no falle</small></div>
  <div class="kpi"><span>Parches</span><b><?= $cuenta('tipo', 'PARCHE') ?></b><small>correcciones de algo que falló</small></div>
  <div class="kpi"><span>Planeados</span><b class="<?= $cuenta('estado', 'PLANEADO') ? 'c-a' : '' ?>"><?= $cuenta('estado', 'PLANEADO') ?></b><small>por hacer</small></div>
  <div class="kpi"><span>Aplicados sin publicar</span><b class="<?= $cuenta('estado', 'APLICADO') ? 'c-a' : '' ?>"><?= $cuenta('estado', 'APLICADO') ?></b><small>listos, falta que lleguen a los clientes</small></div>
  <div class="kpi"><span>Publicados</span><b><?= $cuenta('estado', 'PUBLICADO') ?></b><small>ya en manos de los clientes</small></div>
</section>

<?php if ($nuevo): ?>
<section class="card" id="form">
  <div class="card-head"><h2><?= $editar ? 'Editar ' . e($editar['codigo']) : 'Registrar parche o antifallo' ?></h2><a class="btn btn-ghost" href="parches.php">Cancelar</a></div>
  <form method="post" class="form-parche"><?= campo_csrf() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= (int)($v['id'] ?? 0) ?>">
    <div class="grid-parche">
      <label>Tipo<?= $sel('tipo', PAR_TIPOS, (string)($v['tipo'] ?? 'ANTIFALLO')) ?></label>
      <label>Área<?= $sel('area', PAR_AREAS, (string)($v['area'] ?? 'SRI')) ?></label>
      <label>Riesgo<?= $sel('riesgo', PAR_RIESGOS, (string)($v['riesgo'] ?? 'MEDIO')) ?></label>
      <label>Estado<?= $sel('estado', PAR_ESTADOS, (string)($v['estado'] ?? 'PLANEADO')) ?></label>
      <label>Versión del programa<input name="version_programa" value="<?= e((string)($v['version_programa'] ?? '')) ?>" placeholder="0.15.1" inputmode="decimal"></label>
      <label>Versión del portal<input name="version_portal" value="<?= e((string)($v['version_portal'] ?? '')) ?>" placeholder="1.8.1" inputmode="decimal"></label>
      <label>Fecha<input type="date" name="fecha" value="<?= e((string)($v['fecha'] ?? date('Y-m-d'))) ?>"></label>
    </div>
    <label>Título<input name="titulo" maxlength="200" required value="<?= e((string)($v['titulo'] ?? '')) ?>" placeholder="Ej.: Montos con coma en facturación masiva"></label>
    <label>Qué falla o qué podría fallar<textarea name="problema" rows="3" required placeholder="Lo que le pasa al cliente, en sus palabras"><?= e((string)($v['problema'] ?? '')) ?></textarea></label>
    <label>Solución (o plan, si está planeado)<textarea name="solucion" rows="3" required><?= e((string)($v['solucion'] ?? '')) ?></textarea></label>
    <div><button class="btn btn-accent">Guardar</button></div>
  </form>
</section>
<?php endif; ?>

<section class="card">
  <form class="filtros" method="get">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Buscar por código, título o descripción">
    <?= $sel('tipo', PAR_TIPOS, $f['tipo'], 'Todos los tipos') ?><?= $sel('estado', PAR_ESTADOS, $f['estado'], 'Todos los estados') ?><?= $sel('area', PAR_AREAS, $f['area'], 'Todas las áreas') ?><?= $sel('riesgo', PAR_RIESGOS, $f['riesgo'], 'Todo riesgo') ?>
    <button class="btn">Filtrar</button></form>
  <?php if (!$lista): ?><div class="empty">Nada con ese filtro.</div><?php endif; ?>
  <div class="parches">
  <?php foreach ($lista as $p): ?>
    <article class="parche" id="p<?= (int)$p['id'] ?>">
      <header>
        <span class="mono parche-cod"><?= e($p['codigo']) ?></span>
        <span class="badge <?= $p['tipo'] === 'PARCHE' ? 't-r' : ($p['tipo'] === 'ANTIFALLO' ? 't-b' : 't-n') ?>"><?= e(PAR_TIPOS[$p['tipo']] ?? $p['tipo']) ?></span>
        <span class="badge t-n"><?= e(PAR_AREAS[$p['area']] ?? $p['area']) ?></span>
        <span class="badge <?= $tonoRiesgo[$p['riesgo']] ?? 't-n' ?>">Riesgo <?= e(mb_strtolower(PAR_RIESGOS[$p['riesgo']] ?? $p['riesgo'])) ?></span>
        <span class="badge <?= $tonoEstado[$p['estado']] ?? 't-n' ?>"><?= e(PAR_ESTADOS[$p['estado']] ?? $p['estado']) ?></span>
        <span class="sub parche-ver"><?= $p['version_programa'] ? 'Programa ' . e($p['version_programa']) : '' ?><?= $p['version_programa'] && $p['version_portal'] ? ' · ' : '' ?><?= $p['version_portal'] ? 'Portal ' . e($p['version_portal']) : '' ?><?= ($p['version_programa'] || $p['version_portal']) ? ' · ' : '' ?><?= fecha_ec($p['fecha']) ?></span>
      </header>
      <h3><?= e($p['titulo']) ?></h3>
      <div class="parche-cuerpo">
        <div><span class="crumb">Qué fallaba</span><p><?= nl2br(e($p['problema'])) ?></p></div>
        <div><span class="crumb"><?= $p['estado'] === 'PLANEADO' ? 'Plan' : 'Cómo se cubrió' ?></span><p><?= nl2br(e($p['solucion'])) ?></p></div>
      </div>
      <footer>
        <?php foreach (PAR_ESTADOS as $k => $t): if ($k === $p['estado']) continue; ?>
          <form method="post" class="en-linea"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="estado" value="<?= $k ?>"><button class="btn btn-sm btn-ghost">Marcar <?= e(mb_strtolower(explode(' ', $t)[0])) ?></button></form>
        <?php endforeach; ?>
        <a class="btn btn-sm btn-ghost" href="parches.php?editar=<?= (int)$p['id'] ?>#form">Editar</a>
        <?php if ($p['autor'] !== 'Sistema'): ?><form method="post" class="en-linea" data-confirmar="¿Eliminar <?= e($p['codigo']) ?>?"><?= campo_csrf() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-ghost">Eliminar</button></form><?php endif; ?>
        <span class="sub parche-autor"><?= e($p['autor'] === 'Sistema' ? 'Registrado con la versión' : 'Por ' . $p['autor']) ?></span>
      </footer>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php pie_html();
