<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
$admin = exigir_admin();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verificar_csrf();
  $accion = (string)($_POST['accion'] ?? '');
  try {
    if ($accion === 'generales') {
      fijar_ajuste('dias_sin_conexion', (string)max(1, min(90, (int)$_POST['dias_sin_conexion'])));
      fijar_ajuste('equipos_defecto', (string)max(1, min(100, (int)$_POST['equipos_defecto'])));
      fijar_ajuste('gracia_defecto', (string)max(0, min(30, (int)$_POST['gracia_defecto'])));
      aviso('Ajustes guardados.');
    } elseif ($accion === 'soporte') {
      $email = trim((string)($_POST['soporte_email'] ?? ''));
      if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('El correo de soporte no es válido.');
      $web = trim((string)($_POST['soporte_web'] ?? ''));
      if ($web !== '' && !preg_match('~^https?://~i', $web)) $web = 'https://' . $web;
      if ($web !== '' && !filter_var($web, FILTER_VALIDATE_URL)) throw new RuntimeException('La página web de soporte no es válida.');
      $rucProv = preg_replace('/\D/', '', (string)($_POST['ruc_proveedor'] ?? ''));
      if ($rucProv !== '' && !validar_ruc($rucProv)) throw new RuntimeException('El RUC del proveedor no es válido.');
      fijar_ajuste('ruc_proveedor', $rucProv);
      fijar_ajuste('soporte_desarrollador', mb_substr(trim((string)($_POST['soporte_desarrollador'] ?? '')), 0, 60));
      fijar_ajuste('whatsapp', mb_substr(trim((string)($_POST['whatsapp'] ?? '')), 0, 40));
      fijar_ajuste('soporte_telefono', mb_substr(trim((string)($_POST['soporte_telefono'] ?? '')), 0, 40));
      fijar_ajuste('soporte_email', mb_substr($email, 0, 120));
      fijar_ajuste('soporte_web', mb_substr($web, 0, 160));
      fijar_ajuste('soporte_horario', mb_substr(trim((string)($_POST['soporte_horario'] ?? '')), 0, 120));
      aviso('Datos de soporte guardados. Los programas los reciben en su próxima verificación de licencia.');
    } elseif ($accion === 'clave') {
      $r = db()->prepare('SELECT hash FROM admins WHERE id = ?');
      $r->execute([$admin['id']]);
      if (!password_verify((string)$_POST['actual'], (string)$r->fetchColumn())) throw new RuntimeException('La contraseña actual no es correcta.');
      if (strlen((string)$_POST['nueva']) < 10) throw new RuntimeException('La nueva contraseña debe tener al menos 10 caracteres.');
      if ($_POST['nueva'] !== $_POST['nueva2']) throw new RuntimeException('Las contraseñas nuevas no coinciden.');
      db()->prepare('UPDATE admins SET hash = ? WHERE id = ?')->execute([password_hash((string)$_POST['nueva'], PASSWORD_DEFAULT), $admin['id']]);
      registrar_evento(null, 'CLAVE_ADMIN', 'Cambio de contraseña de ' . $admin['usuario']);
      aviso('Contraseña actualizada.');
    } elseif ($accion === 'nuevo_admin') {
      $u = trim((string)$_POST['usuario']);
      if (!preg_match('/^[a-z0-9_.-]{3,40}$/i', $u)) throw new RuntimeException('Usuario inválido.');
      if (strlen((string)$_POST['clave']) < 10) throw new RuntimeException('La contraseña debe tener al menos 10 caracteres.');
      $r = db()->prepare('SELECT 1 FROM admins WHERE usuario = ?');
      $r->execute([$u]);
      if ($r->fetchColumn()) throw new RuntimeException('Ese usuario ya existe.');
      db()->prepare('INSERT INTO admins (usuario, nombre, email, hash, activo, creado) VALUES (?, ?, ?, ?, 1, ?)')
        ->execute([$u, mb_substr(trim((string)$_POST['nombre']), 0, 120), mb_substr(trim((string)$_POST['email']), 0, 160), password_hash((string)$_POST['clave'], PASSWORD_DEFAULT), ahora()]);
      registrar_evento(null, 'ADMIN_NUEVO', $u);
      aviso('Administrador creado.');
    } elseif ($accion === 'estado_admin') {
      $idA = (int)$_POST['id'];
      if ($idA === (int)$admin['id']) throw new RuntimeException('No puedes desactivar tu propio usuario.');
      db()->prepare('UPDATE admins SET activo = 1 - activo WHERE id = ?')->execute([$idA]);
      aviso('Estado del administrador actualizado.');
    }
    redirigir('ajustes.php');
  } catch (RuntimeException $ex) {
    $error = $ex->getMessage();
  }
}

$admins = db()->query('SELECT id, usuario, nombre, email, activo, ultimo_acceso FROM admins ORDER BY id')->fetchAll();
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$base = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'tu-dominio') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
cabecera_html('Ajustes', $admin, 'ajustes');
?>
<div class="head"><div><span class="crumb">Sistema</span><h1>Ajustes</h1></div></div>
<?php if ($error): ?><div class="aviso t-r" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="cols">
  <div class="col-2 pila">
    <section class="card"><h2>Reglas de las licencias</h2>
      <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="generales">
        <div class="g2">
          <label>Días que el programa puede trabajar sin conectarse<input type="number" name="dias_sin_conexion" min="1" max="90" value="<?= e(ajuste('dias_sin_conexion', '15')) ?>"></label>
          <label>Días de gracia por defecto<input type="number" name="gracia_defecto" min="0" max="30" value="<?= e(ajuste('gracia_defecto', '3')) ?>"></label>
          <label>Equipos por defecto en licencias nuevas<input type="number" name="equipos_defecto" min="1" max="100" value="<?= e(ajuste('equipos_defecto', '1')) ?>"></label>
        </div>
        <button class="btn btn-dark">Guardar</button></form>
    </section>
    <section class="card"><h2>Soporte (se muestra en el programa)</h2>
      <p class="muted">Aparece en el botón "Desarrollado por…" y en la pantalla de activación de cada programa instalado.</p>
      <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="soporte">
        <div class="g2">
          <label>Desarrollado por<input name="soporte_desarrollador" maxlength="60" value="<?= e(ajuste('soporte_desarrollador', 'A.T.')) ?>"></label>
          <label>RUC del proveedor del sistema (tu RUC, va en cada comprobante como "RUC Proveedor")<input name="ruc_proveedor" maxlength="13" inputmode="numeric" value="<?= e(ajuste('ruc_proveedor', '')) ?>"></label>
          <label>WhatsApp<input name="whatsapp" maxlength="40" value="<?= e(ajuste('whatsapp', '')) ?>" placeholder="+593 99 999 9999"></label>
          <label>Teléfono<input name="soporte_telefono" maxlength="40" value="<?= e(ajuste('soporte_telefono', '')) ?>"></label>
          <label>Correo<input type="email" name="soporte_email" maxlength="120" value="<?= e(ajuste('soporte_email', '')) ?>"></label>
          <label>Página web<input name="soporte_web" maxlength="160" value="<?= e(ajuste('soporte_web', '')) ?>" placeholder="https://"></label>
          <label>Horario de atención<input name="soporte_horario" maxlength="120" value="<?= e(ajuste('soporte_horario', '')) ?>" placeholder="Lun a Vie 08:00 a 18:00"></label>
        </div>
        <button class="btn btn-dark">Guardar soporte</button></form>
    </section>
    <section class="card"><h2>Administradores</h2>
      <table><thead><tr><th>Usuario</th><th>Nombre</th><th>Último acceso</th><th>Estado</th><th></th></tr></thead><tbody>
      <?php foreach ($admins as $a): ?>
        <tr><td class="mono"><?= e($a['usuario']) ?></td><td><?= e($a['nombre']) ?><span class="sub"><?= e($a['email']) ?></span></td><td><?= e(substr((string)$a['ultimo_acceso'], 0, 16)) ?: '—' ?></td>
        <td><?= (int)$a['activo'] ? '<span class="badge t-g">Activo</span>' : '<span class="badge t-n">Inactivo</span>' ?></td>
        <td class="right"><?php if ((int)$a['id'] !== (int)$admin['id']): ?><form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="estado_admin"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn btn-sm"><?= (int)$a['activo'] ? 'Desactivar' : 'Activar' ?></button></form><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody></table>
      <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="nuevo_admin">
        <div class="g2"><label>Usuario<input name="usuario" required></label><label>Nombre<input name="nombre"></label><label>Email<input type="email" name="email"></label><label>Contraseña (mín. 10)<input type="password" name="clave" required minlength="10"></label></div>
        <button class="btn">Agregar administrador</button></form>
    </section>
  </div>
  <div class="col-1 pila">
    <section class="card"><h2>Cambiar mi contraseña</h2>
      <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="clave">
        <label>Contraseña actual<input type="password" name="actual" required autocomplete="current-password"></label>
        <label>Nueva contraseña<input type="password" name="nueva" required minlength="10" autocomplete="new-password"></label>
        <label>Repetir nueva<input type="password" name="nueva2" required minlength="10" autocomplete="new-password"></label>
        <button class="btn btn-dark">Cambiar</button></form>
    </section>
    <section class="card dark"><h2>Conexión del programa</h2>
      <span class="small">Dirección del servicio de licencias:</span><pre class="codigo"><?= e($base . '/api.php') ?></pre>
      <span class="small">Llave pública (debe coincidir con la del programa):</span><pre class="codigo"><?= e(llave_publica_pem()) ?></pre>
    </section>
  </div>
</div>
<?php pie_html();
