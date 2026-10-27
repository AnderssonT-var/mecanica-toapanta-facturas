<?php
declare(strict_types=1);
define('FX_PORTAL', true);
require __DIR__ . '/lib/nucleo.php';
require __DIR__ . '/lib/licencias.php';
require __DIR__ . '/lib/nube.php';
$admin = exigir_admin();

$id = (int)($_GET['id'] ?? 0);
$error = '';
$form = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verificar_csrf();
  $accion = (string)($_POST['accion'] ?? '');
  try {
    switch ($accion) {
      case 'guardar':
        if ($id) { actualizar_licencia($id, $_POST); aviso('Licencia actualizada.'); }
        else { $id = crear_licencia($_POST); aviso('Licencia creada. Entrega la clave al cliente.'); }
        break;
      case 'renovar':
        $nuevo = renovar_licencia($id, (int)$_POST['meses'], (float)str_replace(',', '.', (string)$_POST['monto']), (string)$_POST['metodo'], (string)$_POST['referencia'], (string)$_POST['nota'], (int)$admin['id'], (string)($_POST['fecha'] ?? ''));
        aviso('Pago registrado. La licencia vence el ' . fecha_ec($nuevo) . '. El programa del cliente se actualiza solo al conectarse.');
        break;
      case 'suspender': cambiar_estado_licencia($id, true, (string)($_POST['motivo'] ?? '')); aviso('Licencia suspendida. El programa dejará de emitir al conectarse.', 'a'); break;
      case 'reactivar': cambiar_estado_licencia($id, false); aviso('Licencia reactivada.'); break;
      case 'regenerar': $c = regenerar_clave($id); aviso('Nueva clave: ' . $c . '. Los equipos deben activarse de nuevo.', 'a'); break;
      case 'liberar': liberar_equipo($id, (int)$_POST['equipo']); aviso('Equipo liberado. Puede activarse otro equipo.'); break;
      case 'correo':
        $l = licencia_por_id($id);
        if (!$l || !filter_var($l['email'], FILTER_VALIDATE_EMAIL)) throw new ErrorLicencia('DATOS', 'La licencia no tiene un email válido.');
        $marca = fx_config()['marca'] ?? 'Facturador';
        $txt = "Hola " . $l['cliente'] . ",\n\nEsta es tu clave de licencia de " . $marca . ":\n\n    " . $l['clave'] . "\n\nPlan: " . FX_PLANES[$l['plan']]['label'] . "\nVence: " . fecha_ec($l['vence'])
          . "\n\nPara activarla, abre el programa e ingrésala en la pantalla de activación (necesitas internet solo en ese momento).\n\nSoporte: " . ajuste('whatsapp', '') . "\n";
        $host = preg_replace('/[^a-z0-9.-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $ok = @mail($l['email'], '=?UTF-8?B?' . base64_encode('Tu licencia de ' . $marca) . '?=', $txt,
          "From: licencias@" . preg_replace('/^www\./', '', $host) . "\r\nContent-Type: text/plain; charset=UTF-8\r\n");
        registrar_evento($id, 'CORREO', $ok ? 'Clave enviada a ' . $l['email'] : 'Fallo al enviar a ' . $l['email']);
        aviso($ok ? 'Clave enviada a ' . $l['email'] . '.' : 'El servidor no pudo enviar el correo.', $ok ? 'g' : 'r');
        break;
      case 'web_usuario':
        try {
          nube_guardar_usuario($id, (string)($_POST['usuario'] ?? ''), (string)($_POST['nombre'] ?? ''), (string)($_POST['clave'] ?? ''), (string)($_POST['rol'] ?? 'ADMIN'), !isset($_POST['inactivo']));
        } catch (ErrorNube $ex) { throw new ErrorLicencia('DATOS', $ex->getMessage()); }
        aviso('Usuario web guardado.');
        break;
      case 'web_quitar': nube_eliminar_usuario($id, (int)$_POST['uid']); aviso('Usuario web quitado.'); break;
      default: throw new ErrorLicencia('DATOS', 'Acción desconocida.');
    }
    redirigir('licencia.php?id=' . $id);
  } catch (ErrorLicencia $ex) {
    $error = $ex->getMessage();
    if ($accion === 'guardar') $form = $_POST;
  }
}

$l = $id ? licencia_por_id($id) : null;
if ($id && !$l) { http_response_code(404); cabecera_html('No encontrada', $admin, 'licencias'); echo '<div class="empty">La licencia no existe.</div>'; pie_html(); exit; }
$hoy = hoy();
$v = $form ?? ($l ?: [
  'cliente' => '', 'ruc' => '', 'rucs' => '[]', 'email' => '', 'telefono' => '', 'plan' => 'LOCAL', 'inicio' => $hoy, 'vence' => sumar_meses($hoy, 1),
  'gracia' => ajuste('gracia_defecto', '3'), 'empresas' => 1, 'equipos_max' => ajuste('equipos_defecto', '1'), 'usuarios' => 1, 'puntos' => 1, 'precio' => '0', 'periodo' => 'MENSUAL', 'notas' => '',
]);
$rucsTxt = $form ? (string)($form['rucs'] ?? '') : implode(', ', json_decode((string)$v['rucs'], true) ?: []);

cabecera_html($l ? $l['cliente'] : 'Nueva licencia', $admin, 'licencias');
$in = fn($k, $label, $extra = '') => '<label>' . $label . '<input name="' . $k . '" value="' . e($v[$k] ?? '') . '" ' . $extra . '></label>';
?>
<div class="head"><div><span class="crumb"><a href="licencias.php">Licencias</a></span><h1><?= $l ? e($l['cliente']) : 'Nueva licencia' ?></h1></div>
<?php if ($l): ?><div class="row"><?= insignia(estado_licencia($l)) ?><span class="mono muted"><?= e(codigo_licencia($l)) ?></span></div><?php endif; ?></div>
<?php if ($error): ?><div class="aviso t-r" role="alert"><?= e($error) ?></div><?php endif; ?>

<div class="cols lateral-primero">
<section class="card col-2">
  <h2>Datos de la licencia</h2>
  <form method="post" class="form">
    <?= campo_csrf() ?><input type="hidden" name="accion" value="guardar">
    <div class="g2">
      <?= $in('cliente', 'Cliente (razón social)', 'required') ?>
      <?= $in('ruc', 'RUC principal', 'required maxlength="13" class="mono"') ?>
      <label class="span2">RUC adicionales (otros perfiles permitidos, separados por coma)<input name="rucs" class="mono" value="<?= e($rucsTxt) ?>"></label>
      <?= $in('email', 'Email', 'type="email"') ?>
      <?= $in('telefono', 'Teléfono / WhatsApp') ?>
      <label>Plan<select name="plan"><?php foreach (FX_PLANES as $k => $p): ?><option value="<?= $k ?>"<?= $v['plan'] === $k ? ' selected' : '' ?>><?= e($p['label'] . ' · ' . $p['desc']) ?></option><?php endforeach; ?></select></label>
      <label>Periodo de cobro<select name="periodo"><?php foreach (['MENSUAL', 'TRIMESTRAL', 'SEMESTRAL', 'ANUAL'] as $p): ?><option<?= $v['periodo'] === $p ? ' selected' : '' ?>><?= $p ?></option><?php endforeach; ?></select></label>
      <?= $in('inicio', 'Inicio', 'type="date" required') ?>
      <?= $in('vence', 'Vence', 'type="date" required') ?>
      <?= $in('precio', 'Precio por periodo (USD)', 'type="number" step="0.01" min="0"') ?>
      <?= $in('gracia', 'Días de gracia', 'type="number" min="0" max="30"') ?>
      <?= $in('equipos_max', 'Equipos (PC) permitidos', 'type="number" min="1" max="100"') ?>
      <?= $in('empresas', 'Empresas (RUC) permitidas', 'type="number" min="1" max="50"') ?>
      <?= $in('usuarios', 'Usuarios', 'type="number" min="1" max="200"') ?>
      <?= $in('puntos', 'Puntos de emisión', 'type="number" min="1" max="100"') ?>
      <label class="span2">Notas internas<textarea name="notas" rows="3"><?= e($v['notas'] ?? '') ?></textarea></label>
    </div>
    <div class="row"><button class="btn btn-dark"><?= $l ? 'Guardar cambios' : 'Crear licencia' ?></button><a class="btn btn-ghost" href="licencias.php">Cancelar</a></div>
  </form>
</section>

<?php if ($l): $est = estado_licencia($l); $equipos = equipos_activos($id);
  $pagos = db()->prepare('SELECT * FROM pagos WHERE licencia_id = ? ORDER BY fecha DESC, id DESC'); $pagos->execute([$id]); $pagos = $pagos->fetchAll();
  $evs = db()->prepare('SELECT * FROM eventos WHERE licencia_id = ? ORDER BY id DESC LIMIT 30'); $evs->execute([$id]); $evs = $evs->fetchAll();
  $wa = preg_replace('/\D/', '', (string)$l['telefono']);
  $msgWa = rawurlencode('Hola, tu clave de licencia es ' . $l['clave'] . ' (plan ' . FX_PLANES[$l['plan']]['label'] . ', vence ' . fecha_ec($l['vence']) . '). Ingrésala en el programa para activarlo.');
?>
<div class="col-1 pila">
  <section class="card dark">
    <span class="crumb claro">Clave para el cliente</span>
    <div class="clave" id="clave"><?= e($l['clave']) ?></div>
    <div class="row">
      <button class="btn btn-sm btn-accent" type="button" data-copiar="clave">Copiar</button>
      <?php if ($wa): ?><a class="btn btn-sm" href="https://wa.me/<?= e(strlen($wa) === 10 && $wa[0] === '0' ? '593' . substr($wa, 1) : $wa) ?>?text=<?= $msgWa ?>" target="_blank" rel="noopener">WhatsApp</a><?php endif; ?>
      <?php if ($l['email']): ?><form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="correo"><button class="btn btn-sm">Enviar por correo</button></form><?php endif; ?>
    </div>
    <div class="kv claro"><span>Vence</span><b><?= fecha_ec($l['vence']) ?></b></div>
    <div class="kv claro"><span>Días</span><b><?= isset($est['dias']) ? ($est['dias'] >= 0 ? 'Quedan ' . $est['dias'] : 'Venció hace ' . -$est['dias']) : '—' ?></b></div>
    <?php if ($l['estado'] === 'SUSPENDIDA' && $l['motivo']): ?><div class="kv claro"><span>Motivo</span><b><?= e($l['motivo']) ?></b></div><?php endif; ?>
  </section>

  <section class="card" id="renovar">
    <h2>Registrar pago / renovar</h2>
    <form method="post" class="form">
      <?= campo_csrf() ?><input type="hidden" name="accion" value="renovar">
      <div class="g2">
        <label>Meses a agregar<select name="meses"><?php foreach ([1, 3, 6, 12, 24, 0] as $m): ?><option value="<?= $m ?>"<?= ($l['periodo'] === 'ANUAL' ? 12 : ($l['periodo'] === 'SEMESTRAL' ? 6 : ($l['periodo'] === 'TRIMESTRAL' ? 3 : 1))) === $m ? ' selected' : '' ?>><?= $m ? $m . ' mes(es)' : 'Solo registrar pago' ?></option><?php endforeach; ?></select></label>
        <label>Monto USD<input name="monto" type="number" step="0.01" min="0" value="<?= e($l['precio']) ?>"></label>
        <label>Método<select name="metodo"><option>Transferencia</option><option>Depósito</option><option>Efectivo</option><option>Tarjeta</option><option>Otro</option></select></label>
        <label>Fecha del pago<input name="fecha" type="date" value="<?= hoy() ?>"></label>
        <label class="span2">Referencia / comprobante<input name="referencia"></label>
        <label class="span2">Nota<input name="nota"></label>
      </div>
      <button class="btn btn-accent">Registrar y renovar</button>
      <span class="muted small">Si ya venció, los meses se cuentan desde hoy.</span>
    </form>
  </section>

  <section class="card">
    <h2>Acciones</h2>
    <?php if ($l['estado'] === 'SUSPENDIDA'): ?>
      <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="reactivar"><button class="btn btn-dark">Reactivar licencia</button></form>
    <?php else: ?>
      <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="suspender">
        <label>Motivo de suspensión<input name="motivo" placeholder="Ej.: falta de pago"></label>
        <button class="btn btn-danger" data-confirmar="¿Suspender la licencia? El cliente no podrá emitir comprobantes.">Suspender</button></form>
    <?php endif; ?>
    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="regenerar">
      <button class="btn btn-ghost" data-confirmar="Se generará una clave nueva y todos los equipos deberán activarse otra vez. ¿Continuar?">Generar nueva clave</button></form>
  </section>
</div>
</div>

<div class="cols">
<div class="col-2 pila">
<section class="card">
  <div class="card-head"><h2>Equipos</h2><span class="muted"><?= count(array_filter($equipos, fn($x) => (int)$x['activo'])) ?> de <?= (int)$l['equipos_max'] ?> en uso</span></div>
  <?php if (!$equipos): ?><div class="empty">Aún no se activa en ningún equipo.</div><?php else: ?>
  <table><thead><tr><th>Equipo</th><th>Versión</th><th>Activado</th><th>Último contacto</th><th>Estado</th><th></th></tr></thead><tbody>
  <?php foreach ($equipos as $q): ?>
    <tr><td><b><?= e($q['nombre'] ?: 'Equipo') ?></b><span class="sub"><?= e($q['so']) ?> · <span class="mono"><?= e(substr($q['equipo'], 0, 12)) ?>…</span> · <?= e($q['ip']) ?></span></td>
    <td><?= e($q['version']) ?></td><td><?= e(substr($q['activado'], 0, 16)) ?></td><td><?= e(substr($q['ultimo_contacto'], 0, 16)) ?></td>
    <td><?= (int)$q['activo'] ? '<span class="badge t-g">En uso</span>' : '<span class="badge t-n">Liberado</span>' ?></td>
    <td class="right"><?php if ((int)$q['activo']): ?><form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="liberar"><input type="hidden" name="equipo" value="<?= (int)$q['id'] ?>"><button class="btn btn-sm" data-confirmar="¿Liberar este equipo? Dejará de poder facturar.">Liberar</button></form><?php endif; ?></td></tr>
  <?php endforeach; ?></tbody></table><?php endif; ?>
</section>
<?php if (in_array($l['plan'], ['FULL', 'WEB'], true)): $resNube = nube_resumen_licencia($id); $usuWeb = nube_usuarios_licencia($id); ?>
<section class="card">
  <div class="card-head"><h2>Nube y app web</h2><a class="link" href="app/" target="_blank" rel="noopener">Abrir app web</a></div>
  <?php if (!$resNube): ?><div class="empty">El programa del cliente todavía no sincroniza con la nube.</div><?php else: ?>
  <?php foreach ($resNube as $rn): ?><div class="kv"><span class="mono"><?= e($rn['perfil']) ?><span class="sub"><?= (int)$rn['docs'] ?> comprobantes · <?= (int)$rn['n'] ?> registros en total</span></span><span><?= $rn['ultima_sync'] ? 'Sincronizó ' . e(substr((string)$rn['ultima_sync'], 0, 16)) : 'Sin sincronizar' ?></span></div><?php endforeach; ?>
  <?php endif; ?>
  <?php $seriesLic = nube_series_licencia($id); if ($seriesLic): ?>
  <h2 class="sep">Puntos de emisión por equipo</h2>
  <?php foreach ($seriesLic as $se): ?><div class="kv"><span class="mono"><?= e($se['serie']) ?><span class="sub"><?= e($se['perfil']) ?></span></span><span><?= e($se['nombre']) ?><?= (int)$se['activo'] === 1 ? '' : ' <span class="badge t-n">equipo liberado</span>' ?></span></div><?php endforeach; ?>
  <?php endif; ?>
  <h2 class="sep">Usuarios de la app web</h2>
  <?php if (!$usuWeb): ?><div class="empty">Sin usuarios. El cliente los crea desde el programa (Nube y respaldos › Acceso web) o puedes crearlos aquí.</div><?php endif; ?>
  <?php foreach ($usuWeb as $uw): ?><div class="kv"><span><b><?= e($uw['usuario']) ?></b><span class="sub"><?= e($uw['nombre']) ?> · <?= e($uw['rol']) ?><?= (int)$uw['activo'] ? '' : ' · desactivado' ?> · último acceso: <?= e(substr((string)$uw['ultimo_acceso'], 0, 16) ?: 'nunca') ?></span></span>
    <form method="post"><?= campo_csrf() ?><input type="hidden" name="accion" value="web_quitar"><input type="hidden" name="uid" value="<?= (int)$uw['id'] ?>"><button class="btn btn-sm" data-confirmar="¿Quitar el acceso web de <?= e($uw['usuario']) ?>?">Quitar</button></form></div><?php endforeach; ?>
  <form method="post" class="form"><?= campo_csrf() ?><input type="hidden" name="accion" value="web_usuario">
    <div class="g2"><label>Usuario o correo<input name="usuario" required></label><label>Nombre<input name="nombre"></label>
    <label>Contraseña nueva (mín. 8)<input type="password" name="clave" minlength="8" autocomplete="new-password"></label>
    <label>Rol<select name="rol"><option value="ADMIN">Administrador</option><option value="VENDEDOR">Vendedor</option><option value="CONSULTA">Solo consulta</option></select></label></div>
    <span class="small muted">Si el usuario ya existe, se le cambia la contraseña (sirve para recuperar el acceso).</span>
    <button class="btn">Crear o restablecer usuario</button></form>
</section>
<?php endif; ?>
</div>
<section class="card col-1">
  <h2>Pagos</h2>
  <?php if (!$pagos): ?><div class="empty">Sin pagos registrados.</div><?php endif; ?>
  <?php foreach ($pagos as $pg): ?>
    <div class="kv"><span><?= fecha_ec($pg['fecha']) ?> · <?= e($pg['metodo']) ?><span class="sub"><?= (int)$pg['meses'] ?> mes(es) · vence <?= fecha_ec($pg['vence_nuevo']) ?><?= $pg['referencia'] ? ' · ' . e($pg['referencia']) : '' ?></span></span><b>$<?= number_format((float)$pg['monto'], 2) ?></b></div>
  <?php endforeach; ?>
  <h2 class="sep">Historial</h2>
  <?php foreach ($evs as $ev): ?><div class="ev"><span class="badge t-n"><?= e($ev['tipo']) ?></span><span class="sub"><?= e($ev['detalle']) ?> · <?= e(substr($ev['creado'], 0, 16)) ?></span></div><?php endforeach; ?>
</section>
</div>
<?php endif; ?>
<?php pie_html();
