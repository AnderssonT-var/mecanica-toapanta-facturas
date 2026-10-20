<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
$admin = exigir_admin();
$error = '';
const DIR_ACT = __DIR__ . '/actualizaciones';

// Lee latest.yml (formato de electron-builder): versión, archivo y SHA-512 del instalador.
function leer_latest(string $yml): array {
  $v = preg_match('/^version:\s*[\'"]?([0-9]+\.[0-9]+\.[0-9]+)/m', $yml, $m) ? $m[1] : '';
  $ruta = preg_match('/^path:\s*[\'"]?([^\'"\r\n]+)/m', $yml, $m) ? trim($m[1]) : '';
  $sha = preg_match('/^sha512:\s*[\'"]?([A-Za-z0-9+\/=]+)/m', $yml, $m) ? $m[1] : '';
  $fecha = preg_match('/^releaseDate:\s*[\'"]?([^\'"\r\n]+)/m', $yml, $m) ? trim($m[1]) : '';
  return ['version' => $v, 'path' => $ruta, 'sha512' => $sha, 'fecha' => $fecha];
}

// Publica una versión: comprueba que el instalador coincide con el SHA-512 de latest.yml antes de dejarla disponible.
function publicar_actualizacion(array $f): void {
  foreach (['latest' => 'latest.yml', 'instalador' => 'instalador (.exe)', 'blockmap' => 'archivo .blockmap'] as $k => $nombre) {
    if (!isset($f[$k]) || ($f[$k]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Falta subir el ' . $nombre . ' o no se pudo recibir (revisa el tamaño máximo de subida).');
  }
  $yml = (string)file_get_contents($f['latest']['tmp_name']);
  $info = leer_latest($yml);
  if (!$info['version'] || !$info['path'] || !$info['sha512']) throw new RuntimeException('latest.yml no es válido.');
  $exe = basename((string)$f['instalador']['name']);
  if ($exe !== $info['path'] || !preg_match('/^[A-Za-z0-9._-]+\.exe$/', $exe)) throw new RuntimeException('El instalador debe llamarse ' . $info['path'] . ' (como indica latest.yml).');
  if (basename((string)$f['blockmap']['name']) !== $exe . '.blockmap') throw new RuntimeException('El blockmap debe llamarse ' . $exe . '.blockmap');
  $sha = base64_encode(hash_file('sha512', $f['instalador']['tmp_name'], true));
  if (!hash_equals($info['sha512'], $sha)) throw new RuntimeException('El instalador no coincide con latest.yml (SHA-512 distinto). Sube los tres archivos de la misma compilación.');
  if (!is_dir(DIR_ACT) && !mkdir(DIR_ACT, 0755, true)) throw new RuntimeException('No se pudo crear la carpeta de actualizaciones.');
  if (!move_uploaded_file($f['instalador']['tmp_name'], DIR_ACT . '/' . $exe)) throw new RuntimeException('No se pudo guardar el instalador.');
  move_uploaded_file($f['blockmap']['tmp_name'], DIR_ACT . '/' . $exe . '.blockmap');
  // latest.yml al final y de forma atómica: los programas solo ven la versión nueva cuando ya está completa.
  file_put_contents(DIR_ACT . '/latest.yml.tmp', $yml, LOCK_EX);
  rename(DIR_ACT . '/latest.yml.tmp', DIR_ACT . '/latest.yml');
  // Conserva solo esta versión y la anterior (para descargas diferenciales).
  $exes = glob(DIR_ACT . '/*.exe') ?: [];
  usort($exes, fn($a, $b) => filemtime($b) <=> filemtime($a));
  foreach (array_slice($exes, 2) as $viejo) { @unlink($viejo); @unlink($viejo . '.blockmap'); }
  registrar_evento(null, 'ACTUALIZACION', 'Versión ' . $info['version'] . ' publicada');
  aviso('Versión ' . $info['version'] . ' publicada. Los programas la descargarán solos en las próximas horas.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verificar_csrf();
  $accion = (string)($_POST['accion'] ?? '');
  try {
    if ($accion === 'actualizacion') {
      publicar_actualizacion($_FILES);
    } elseif ($accion === 'version_minima') {
      $vm = trim((string)($_POST['version_minima'] ?? ''));
      if ($vm !== '' && !preg_match('/^\d+\.\d+\.\d+$/', $vm)) throw new RuntimeException('La versión mínima debe tener el formato 0.4.5');
      fijar_ajuste('version_minima', $vm);
      registrar_evento(null, 'VERSION_MINIMA', $vm === '' ? 'sin mínimo' : $vm);
      aviso($vm === '' ? 'Sin versión mínima: todas las versiones pueden emitir.' : 'Versión mínima ' . $vm . ': las versiones anteriores dejarán de emitir en su próxima verificación de licencia.');
    }
    redirigir('versiones.php');
  } catch (RuntimeException $ex) {
    $error = $ex->getMessage();
  }
}

$pub = is_file(DIR_ACT . '/latest.yml') ? leer_latest((string)file_get_contents(DIR_ACT . '/latest.yml')) : null;
$minima = ajuste('version_minima', '');
// Equipos activos agrupados por la versión que reportaron en su última verificación.
$porVersion = db()->query("SELECT version, COUNT(*) AS n, MAX(ultimo_contacto) AS ultimo FROM equipos WHERE activo = 1 GROUP BY version")->fetchAll();
usort($porVersion, fn($a, $b) => version_compare((string)$b['version'], (string)$a['version']));
$totalEquipos = array_sum(array_map(fn($r) => (int)$r['n'], $porVersion));
$historial = db()->query("SELECT tipo, detalle, creado FROM eventos WHERE tipo IN ('ACTUALIZACION', 'VERSION_MINIMA') ORDER BY creado DESC, id DESC LIMIT 12")->fetchAll();
$tamano = fn(string $f) => is_file($f) ? number_format(filesize($f) / 1048576, 1) . ' MB' : '—';
cabecera_html('Versiones', $admin, 'versiones');
?>
<div class="head"><div><span class="crumb">Programa de escritorio</span><h1>Versiones y actualizaciones</h1></div></div>
<?php if ($error): ?><div class="aviso t-r" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="cols lateral-primero">
  <div class="col-2 pila">
    <section class="card"><h2>Publicar una versión nueva</h2>
      <p class="muted">Sube los tres archivos de <b>desktop/dist</b> de la misma compilación. Los programas instalados revisan al abrir y cada 6 horas, descargan solos y se actualizan al cerrar.</p>
      <form method="post" class="form" enctype="multipart/form-data"><?= campo_csrf() ?><input type="hidden" name="accion" value="actualizacion">
        <div class="g2">
          <label>latest.yml<input type="file" name="latest" accept=".yml" required></label>
          <label>Instalador (.exe)<input type="file" name="instalador" accept=".exe" required></label>
          <label>Blockmap (.exe.blockmap)<input type="file" name="blockmap" required></label>
        </div>
        <button class="btn btn-dark">Publicar versión</button></form>
    </section>
    <section class="card"><h2>Versión mínima obligatoria</h2>
      <p class="muted">Opcional. Los programas con una versión anterior dejan de emitir comprobantes hasta actualizarse (pueden seguir consultando). Úsala solo para correcciones importantes, por ejemplo un cambio del SRI.</p>
      <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="version_minima">
        <label>Versión mínima (vacío = sin mínimo)<input name="version_minima" placeholder="<?= e($pub['version'] ?? '0.4.7') ?>" value="<?= e($minima) ?>" inputmode="decimal"></label>
        <button class="btn">Guardar versión mínima</button></form>
    </section>
    <section class="card"><h2>Historial</h2>
      <?php if (!$historial): ?><div class="empty">Todavía no se ha publicado ninguna versión.</div><?php else: ?>
      <table><thead><tr><th>Fecha</th><th>Cambio</th></tr></thead><tbody>
      <?php foreach ($historial as $h): ?>
        <tr><td class="mono"><?= e(substr((string)$h['creado'], 0, 16)) ?></td><td><?= $h['tipo'] === 'ACTUALIZACION' ? e($h['detalle']) : 'Versión mínima: ' . e($h['detalle']) ?></td></tr>
      <?php endforeach; ?></tbody></table><?php endif; ?>
    </section>
  </div>
  <div class="col-1 pila">
    <section class="card dark"><h2>Publicada ahora</h2>
      <div class="kv claro"><span>Versión</span><b><?= $pub ? e($pub['version']) : 'Ninguna todavía' ?></b></div>
      <?php if ($pub): ?>
      <div class="kv claro"><span>Instalador</span><b class="mono small"><?= e($pub['path']) ?></b></div>
      <div class="kv claro"><span>Tamaño</span><b><?= e($tamano(DIR_ACT . '/' . basename($pub['path']))) ?></b></div>
      <div class="kv claro"><span>Compilada</span><b><?= e(substr($pub['fecha'], 0, 10)) ?: '—' ?></b></div>
      <?php endif; ?>
      <div class="kv claro"><span>Versión mínima</span><b><?= $minima !== '' ? e($minima) : 'Sin mínimo' ?></b></div>
    </section>
    <section class="card"><h2>Equipos por versión</h2>
      <?php if (!$porVersion): ?><div class="empty">Aún no hay equipos activados.</div><?php else: ?>
      <?php foreach ($porVersion as $r):
        $v = (string)$r['version'];
        if ($pub && $v === $pub['version']) $estado = '<span class="badge t-g">Al día</span>';
        elseif ($minima !== '' && $v !== '' && version_compare($v, $minima, '<')) $estado = '<span class="badge t-r">Bloqueada</span>';
        else $estado = '<span class="badge t-a">Pendiente</span>';
        if (!$pub && $estado !== '<span class="badge t-r">Bloqueada</span>') $estado = '';
      ?>
        <div class="kv"><span>v<?= e($v ?: '?') ?> <?= $estado ?></span><b><?= (int)$r['n'] ?> <?= (int)$r['n'] === 1 ? 'equipo' : 'equipos' ?></b></div>
      <?php endforeach; ?>
      <p class="small muted">Total: <?= $totalEquipos ?> activos. La versión se actualiza cada vez que el programa verifica su licencia.</p>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php pie_html();
