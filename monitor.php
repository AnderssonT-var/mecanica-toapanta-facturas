<?php
// Monitor de usuarios (quién usa Facmit ahora y con qué estado) y monitor de errores (fallas técnicas agrupadas).
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';
require __DIR__ . '/lib/monitor.php';
$admin = exigir_admin();
mon_esquema();

$tab = ($_GET['t'] ?? 'usuarios') === 'errores' ? 'errores' : 'usuarios';
$huella = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_GET['h'] ?? '')));
$aviso = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verificar_csrf();
  $h = preg_replace('/[^a-f0-9]/', '', strtolower((string)($_POST['h'] ?? '')));
  try {
    mon_cambiar_estado($h, (string)($_POST['estado'] ?? ''));
    registrar_evento(null, 'MONITOR', 'Error ' . substr($h, 0, 8) . ' marcado como ' . (string)$_POST['estado']);
  } catch (Throwable $ex) { $aviso = $ex->getMessage(); }
  header('Location: monitor.php?t=errores' . (!empty($_POST['volver']) ? '&h=' . $h : ''));
  exit;
}

$etqEstado = ['NUEVO' => ['Nuevo', 't-r'], 'REAPARECIO' => ['Reapareció', 't-r'], 'VISTO' => ['Visto', 't-a'], 'RESUELTO' => ['Resuelto', 't-g'], 'IGNORADO' => ['Ignorado', 't-n']];
$badgeEstado = fn(string $s) => '<span class="badge ' . ($etqEstado[$s][1] ?? 't-n') . '">' . e($etqEstado[$s][0] ?? $s) . '</span>';
$formEstado = fn(string $h, string $estado, string $texto, bool $volver = false, string $cls = 'btn btn-sm') => '<form method="post" class="en-linea">' . campo_csrf()
  . '<input type="hidden" name="h" value="' . e($h) . '"><input type="hidden" name="estado" value="' . e($estado) . '">' . ($volver ? '<input type="hidden" name="volver" value="1">' : '') . '<button class="' . $cls . '">' . e($texto) . '</button></form>';

cabecera_html('Monitor', $admin, 'monitor');
?>
<div class="head"><div><span class="crumb">Soporte</span><h1>Monitor</h1></div>
  <nav class="tabs-m"><a href="monitor.php" class="<?= $tab === 'usuarios' ? 'on' : '' ?>">Usuarios</a><a href="monitor.php?t=errores" class="<?= $tab === 'errores' ? 'on' : '' ?>">Errores</a></nav></div>
<?php if ($aviso): ?><div class="aviso t-r"><?= e($aviso) ?></div><?php endif; ?>

<?php if ($tab === 'usuarios'):
  $pub = mon_version_publicada();
  $filas = mon_equipos();
  $q = mb_strtolower(trim((string)($_GET['q'] ?? '')));
  $f = (string)($_GET['f'] ?? '');
  $k = ['enLinea' => 0, 'usando' => 0, 'hoy' => 0, 'alertas' => 0, 'viejos' => 0, 'pruebas' => 0, 'sinConexion' => 0];
  $vistas = [];
  // Antifallo: equipos con el mismo RUC usando el mismo punto de emisión (repetirán números).
  $porRuc = [];
  foreach ($filas as $x) { $sr = (json_decode((string)$x['datos'], true) ?: [])['series'] ?? []; if ($x['perfil_ruc'] && $sr && mon_minutos($x['actualizado']) < 30 * 1440) $porRuc[$x['perfil_ruc']][] = [$x['id'], $sr, $x['nombre'] ?: 'Equipo']; }
  foreach ($filas as $x) {
    // El contacto más reciente: el latido del monitor o la verificación de licencia (que sigue aunque apaguen el diagnóstico).
    $min = mon_minutos(max((string)$x['actualizado'], (string)$x['ultimo_contacto']) ?: null);
    $enLinea = $x['actualizado'] && mon_minutos($x['actualizado']) <= MON_EN_LINEA_MIN;
    $version = $x['version'] ?: $x['version_equipo'];
    $al = [];
    if (!$x['actualizado']) $al[] = ['Sin monitor (versión antigua)', 't-n'];
    elseif ($x['ultimo_contacto'] && strtotime((string)$x['ultimo_contacto']) > strtotime((string)$x['actualizado']) + 3600) $al[] = ['Diagnóstico apagado por el cliente', 't-n'];
    if ($x['ambiente'] === '1') $al[] = ['En ambiente de PRUEBAS', 't-a'];
    if ((int)$x['rechazados'] > 0) $al[] = [(int)$x['rechazados'] . ' rechazado(s) en 7 días', 't-r'];
    if ((int)$x['pendientes'] > 0) $al[] = [(int)$x['pendientes'] . ' pendiente(s) en el SRI', (int)$x['pendientes'] > 10 ? 't-r' : 't-a'];
    if ($x['nube_error'] !== null && $x['nube_error'] !== '') $al[] = ['Nube: ' . mb_substr($x['nube_error'], 0, 60), 't-a'];
    if ($x['firma_vence']) { $d = (int)floor((strtotime($x['firma_vence']) - time()) / 86400); if ($d < 0) $al[] = ['Firma vencida', 't-r']; elseif ($d <= 30) $al[] = ['Firma vence en ' . $d . ' días', 't-a']; }
    if ($x['disco_gb'] !== null && (float)$x['disco_gb'] < 2) $al[] = ['Disco casi lleno (' . $x['disco_gb'] . ' GB)', 't-r'];
    if ($pub && $version && version_compare($version, $pub, '<')) { $al[] = ['Versión ' . $version . ' (hay ' . $pub . ')', 't-a']; $k['viejos']++; }
    if ($min !== null && $min >= 10 * 1440) { $al[] = ['Sin conectarse ' . floor($min / 1440) . ' días: a los 15 se bloquea', $min >= 15 * 1440 ? 't-r' : 't-a']; $k['sinConexion']++; }
    foreach ($porRuc[$x['perfil_ruc']] ?? [] as [$oid, $osr, $onom]) {
      if ($oid === $x['id']) continue;
      $mias = (json_decode((string)$x['datos'], true) ?: [])['series'] ?? [];
      foreach (array_slice(array_intersect($mias, $osr), 0, 2) as $c) $al[] = ['Misma serie ' . substr($c, 3) . ' que ' . $onom, 't-r'];
    }
    $dx = json_decode((string)$x['datos'], true) ?: [];
    if (isset($dx['relojDesfaseMin']) && abs((int)$dx['relojDesfaseMin']) > 10) $al[] = ['Reloj desfasado ' . (abs((int)$dx['relojDesfaseMin']) >= 120 ? round(abs((int)$dx['relojDesfaseMin']) / 60) . ' h' : abs((int)$dx['relojDesfaseMin']) . ' min'), 't-a'];
    if (isset($dx['zonaAplicada']) && !$dx['zonaAplicada']) $al[] = ['Zona horaria de Ecuador no aplicada', 't-r'];
    if ((int)($dx['correoFallidos7d'] ?? 0) > 0) $al[] = [(int)$dx['correoFallidos7d'] . ' correo(s) sin enviar en 7 días: revisar su configuración de correo', 't-a'];
    if ((int)$x['errores_cola'] > 0) $al[] = [(int)$x['errores_cola'] . ' error(es) sin enviar', 't-n'];
    if ($enLinea) $k['enLinea']++;
    if ($enLinea && (int)$x['en_uso']) $k['usando']++;
    if ($min !== null && $min < 1440) $k['hoy']++;
    if ($x['ambiente'] === '1') $k['pruebas']++;
    $graves = array_filter($al, fn($a) => $a[1] !== 't-n');
    if ($graves) $k['alertas']++;
    $texto = mb_strtolower($x['cliente'] . ' ' . $x['ruc'] . ' ' . $x['nombre'] . ' ' . $x['perfil_nombre'] . ' ' . $x['usuario']);
    if ($q !== '' && !str_contains($texto, $q)) continue;
    if ($f === 'enlinea' && !$enLinea) continue;
    if ($f === 'alertas' && !$graves) continue;
    $vistas[] = ['x' => $x, 'min' => $min, 'enLinea' => $enLinea, 'al' => $al, 'graves' => (bool)$graves, 'version' => $version];
  }
  $kpi = fn($t, $v, $s, $cls = '') => '<div class="kpi"><span>' . $t . '</span><b class="' . $cls . '">' . $v . '</b><small>' . $s . '</small></div>';
?>
<section class="kpis">
  <?= $kpi('En línea ahora', $k['enLinea'], $k['usando'] . ' usando el programa en este momento') ?>
  <?= $kpi('Activos hoy', $k['hoy'], 'de ' . count($filas) . ' equipos activados') ?>
  <?= $kpi('Con alertas', $k['alertas'], 'revisa antes de que llamen', $k['alertas'] ? 'c-r' : '') ?>
  <?= $kpi('En pruebas', $k['pruebas'], 'sus facturas no tienen validez', $k['pruebas'] ? 'c-a' : '') ?>
  <?= $kpi('Versión anterior', $k['viejos'], $pub ? 'publicada: ' . e($pub) : 'sin versión publicada', $k['viejos'] ? 'c-a' : '') ?>
</section>
<section class="card" data-recargar="60">
  <form class="filtros" method="get"><input type="search" name="q" value="<?= e((string)($_GET['q'] ?? '')) ?>" placeholder="Cliente, RUC, equipo o usuario">
    <select name="f" aria-label="Mostrar"><option value="">Todos los equipos</option><option value="enlinea"<?= $f === 'enlinea' ? ' selected' : '' ?>>Solo en línea</option><option value="alertas"<?= $f === 'alertas' ? ' selected' : '' ?>>Solo con alertas</option></select>
    <button class="btn">Filtrar</button><span class="sub auto-hint">Se actualiza solo cada minuto</span></form>
  <?php if (!$vistas): ?><div class="empty">Ningún equipo con ese filtro. Los programas desde la versión 0.15.0 envían su estado cada 5 minutos.</div><?php else: ?>
  <div class="tabla"><table><thead><tr><th>Cliente</th><th>Equipo</th><th>Estado</th><th>Empresa abierta</th><th>Hoy</th><th>Alertas</th></tr></thead><tbody>
  <?php foreach ($vistas as $v): $x = $v['x'];
    $cls = $v['enLinea'] ? 'p-on' : ($v['min'] !== null && $v['min'] < 1440 ? 'p-hoy' : ($v['min'] !== null && $v['min'] >= 10 * 1440 ? 'p-mal' : 'p-off'));
    $estado = $v['enLinea'] ? ((int)$x['en_uso'] ? 'Usando ahora' : 'Abierto, sin uso') : 'Desconectado'; ?>
    <tr class="<?= $v['graves'] ? 'fila-alerta' : '' ?>">
      <td><a href="licencia.php?id=<?= (int)$x['licencia_id'] ?>"><b><?= e($x['cliente']) ?></b></a><span class="sub mono"><?= e($x['ruc']) ?> · <?= e($x['lic_estado']) ?> hasta <?= fecha_ec($x['vence']) ?></span></td>
      <td><b><?= e($x['nombre'] ?: 'Equipo') ?></b><span class="sub"><?= e($x['so']) ?> · <span class="mono">v<?= e($v['version']) ?></span></span></td>
      <td><span class="punto-m <?= $cls ?>"></span><b><?= e($estado) ?></b><span class="sub"><?= e(mon_hace($v['min'])) ?><?= $x['usuario'] ? ' · usuario ' . e($x['usuario']) : '' ?></span></td>
      <td><?= $x['perfil_nombre'] ? e($x['perfil_nombre']) . '<span class="sub mono">' . e($x['perfil_ruc']) . ($x['ambiente'] === '2' ? ' · producción' : ($x['ambiente'] === '1' ? ' · pruebas' : '')) . '</span>' : '<span class="sub">—</span>' ?></td>
      <td class="mono"><?= $x['actualizado'] ? (int)$x['emitidos_hoy'] . ' emitidos' : '—' ?></td>
      <td><div class="chips"><?php foreach ($v['al'] as [$t, $c]) echo '<span class="badge ' . $c . '">' . e($t) . '</span>'; ?><?= $v['al'] ? '' : '<span class="badge t-g">Todo bien</span>' ?></div></td>
    </tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>

<?php elseif ($huella):
  $occ = mon_error_detalle($huella);
  if (!$occ): ?><section class="card"><div class="empty">Ese error ya no existe (se limpió o nunca llegó).</div><a class="btn" href="monitor.php?t=errores">Volver</a></section>
  <?php else:
    if (array_filter($occ, fn($o) => $o['estado'] === 'NUEVO')) db()->prepare("UPDATE monitor_errores SET estado = 'VISTO' WHERE huella = ? AND estado = 'NUEVO'")->execute([$huella]);
    $p = $occ[0];
    $veces = array_sum(array_map(fn($o) => (int)$o['veces'], $occ));
    $clientes = count(array_unique(array_column($occ, 'licencia_id')));
    $ctx = json_decode((string)$p['contexto'], true) ?: []; ?>
<section class="card">
  <div class="card-head"><div><span class="badge t-n"><?= e(MON_TIPOS[$p['tipo']] ?? $p['tipo']) ?></span><h2 class="mt8"><?= e($p['mensaje']) ?></h2></div><a class="btn" href="monitor.php?t=errores">← Todos los errores</a></div>
  <div class="kpis">
    <div class="kpi"><span>Veces</span><b><?= $veces ?></b><small>sumando todos los equipos</small></div>
    <div class="kpi"><span>Clientes afectados</span><b><?= $clientes ?></b><small><?= count($occ) ?> equipo(s)</small></div>
    <div class="kpi"><span>Primera vez</span><b class="kpi-fecha"><?= e(substr(min(array_column($occ, 'primero')), 0, 16)) ?></b><small>última: <?= e(substr($p['ultimo'], 0, 16)) ?></small></div>
  </div>
  <div class="acciones-m">
    <?= $formEstado($huella, 'RESUELTO', 'Marcar como resuelto', true, 'btn btn-accent') ?><?= $formEstado($huella, 'IGNORADO', 'Ignorar', true, 'btn') ?><?= $formEstado($huella, 'NUEVO', 'Reabrir', true, 'btn btn-ghost') ?>
  </div>
  <?php if ($p['detalle']): ?><h2 class="sep">Detalle técnico (el más reciente)</h2><pre class="det"><?= e($p['detalle']) ?></pre><?php endif; ?>
  <?php if ($ctx): ?><h2 class="sep">Contexto</h2><div class="tabla"><table><tbody><?php foreach ($ctx as $kk => $vv): ?><tr><td class="mono ctx-k"><?= e((string)$kk) ?></td><td class="mono"><?= e(is_scalar($vv) ? (string)$vv : json_encode($vv, JSON_UNESCAPED_UNICODE)) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  <h2 class="sep">Dónde pasó</h2>
  <div class="tabla"><table><thead><tr><th>Cliente</th><th>Equipo</th><th>Versión</th><th>Veces</th><th>Primera / última</th><th>Estado</th></tr></thead><tbody>
  <?php foreach ($occ as $o): ?>
    <tr><td><a href="licencia.php?id=<?= (int)$o['licencia_id'] ?>"><b><?= e($o['cliente']) ?></b></a><span class="sub mono"><?= e($o['ruc']) ?></span></td><td><?= e($o['equipo_nombre'] ?: 'Equipo') ?><span class="sub"><?= e($o['so'] ?? '') ?></span></td>
    <td class="mono"><?= e($o['version']) ?></td><td class="mono"><?= (int)$o['veces'] ?></td><td class="mono"><?= e(substr($o['primero'], 0, 16)) ?><span class="sub"><?= e(substr($o['ultimo'], 0, 16)) ?></span></td><td><?= $badgeEstado($o['estado']) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
</section>
  <?php endif; ?>

<?php else:
  $estado = (string)($_GET['estado'] ?? 'abiertos');
  $tipo = strtoupper(preg_replace('/[^A-Z]/i', '', (string)($_GET['tipo'] ?? '')));
  $dias = in_array((int)($_GET['dias'] ?? 30), [1, 7, 30, 90], true) ? (int)($_GET['dias'] ?? 30) : 30;
  $grupos = mon_errores_grupos($estado, $tipo, $dias);
  $abiertos = mon_errores_grupos('abiertos', '', 90);
  $nuevos = count(array_filter($abiertos, fn($g) => (int)$g['nuevos'] > 0));
  $reap = count(array_filter($abiertos, fn($g) => (int)$g['reaparecidos'] > 0));
  $r = db()->prepare("SELECT COUNT(DISTINCT licencia_id) FROM monitor_errores WHERE estado IN ('NUEVO', 'VISTO', 'REAPARECIO')");
  $r->execute();
  $afectados = (int)$r->fetchColumn();
  $r = db()->prepare('SELECT COALESCE(SUM(veces), 0) FROM monitor_errores WHERE ultimo >= ?');
  $r->execute([date('Y-m-d H:i:s', time() - 86400)]);
  $ult24 = (int)$r->fetchColumn(); ?>
<section class="kpis">
  <div class="kpi"><span>Errores abiertos</span><b class="<?= $abiertos ? 'c-a' : '' ?>"><?= count($abiertos) ?></b><small>distintos, últimos 90 días</small></div>
  <div class="kpi"><span>Nuevos</span><b class="<?= $nuevos ? 'c-r' : '' ?>"><?= $nuevos ?></b><small>sin revisar</small></div>
  <div class="kpi"><span>Reaparecieron</span><b class="<?= $reap ? 'c-r' : '' ?>"><?= $reap ?></b><small>estaban resueltos</small></div>
  <div class="kpi"><span>Clientes afectados</span><b><?= $afectados ?></b><small>con algún error abierto</small></div>
  <div class="kpi"><span>Últimas 24 horas</span><b><?= $ult24 ?></b><small>veces que ocurrieron</small></div>
</section>
<section class="card">
  <form class="filtros" method="get"><input type="hidden" name="t" value="errores">
    <select name="estado" aria-label="Estado"><?php foreach (['abiertos' => 'Abiertos', 'NUEVO' => 'Nuevos', 'REAPARECIO' => 'Reaparecieron', 'VISTO' => 'Vistos', 'RESUELTO' => 'Resueltos', 'IGNORADO' => 'Ignorados', 'todos' => 'Todos'] as $kk => $t): ?><option value="<?= $kk ?>"<?= $estado === $kk ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
    <select name="tipo" aria-label="Tipo"><option value="">Todos los tipos</option><?php foreach (MON_TIPOS as $kk => $t): ?><option value="<?= $kk ?>"<?= $tipo === $kk ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?></select>
    <select name="dias" aria-label="Periodo"><?php foreach ([1 => 'Último día', 7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días'] as $kk => $t): ?><option value="<?= $kk ?>"<?= $dias === $kk ? ' selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
    <button class="btn">Filtrar</button></form>
  <?php if (!$grupos): ?><div class="empty">Sin errores con ese filtro. Bien.</div><?php else: ?>
  <div class="tabla"><table><thead><tr><th>Tipo</th><th>Error</th><th>Clientes</th><th>Veces</th><th>Versiones</th><th>Último</th><th>Estado</th><th></th></tr></thead><tbody>
  <?php foreach ($grupos as $g):
    $st = (int)$g['nuevos'] ? 'NUEVO' : ((int)$g['reaparecidos'] ? 'REAPARECIO' : $g['estado']); ?>
    <tr class="<?= in_array($st, ['NUEVO', 'REAPARECIO'], true) ? 'fila-alerta' : '' ?>">
      <td><span class="badge t-n"><?= e(MON_TIPOS[$g['tipo']] ?? $g['tipo']) ?></span></td>
      <td><a href="monitor.php?t=errores&h=<?= e($g['huella']) ?>"><b><?= e(mb_substr($g['mensaje'], 0, 140)) ?></b></a></td>
      <td class="mono"><?= (int)$g['clientes'] ?><span class="sub"><?= (int)$g['equipos'] ?> equipo(s)</span></td>
      <td class="mono"><?= (int)$g['veces'] ?></td>
      <td class="mono"><?= e($g['version_min'] === $g['version_max'] ? $g['version_max'] : $g['version_min'] . ' – ' . $g['version_max']) ?></td>
      <td><?= e(mon_hace(mon_minutos($g['ultimo']))) ?></td>
      <td><?= $badgeEstado($st) ?></td>
      <td class="right nowrap"><?= $st !== 'RESUELTO' ? $formEstado($g['huella'], 'RESUELTO', 'Resuelto') : '' ?> <?= $st !== 'IGNORADO' ? $formEstado($g['huella'], 'IGNORADO', 'Ignorar', false, 'btn btn-sm btn-ghost') : '' ?></td>
    </tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php endif;
pie_html();
