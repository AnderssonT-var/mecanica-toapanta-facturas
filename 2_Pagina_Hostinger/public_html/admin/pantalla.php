<?php
// Pantalla del cliente: teléfonos/tablets autorizados y el cartel que muestran sin factura.
require __DIR__ . '/_lib.php';
requiere_admin();

function volver_pantalla(string $ancla = ''): void {
    header('Location: pantalla.php' . ($ancla ? '#' . $ancla : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_post_valido();
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'aprobar') {
        $codigo = preg_replace('/\D/', '', (string)($_POST['codigo'] ?? ''));
        $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 60) ?: 'Pantalla del mostrador';
        $st = $pdo->prepare('UPDATE pantalla_disp SET aprobado = 1, nombre = :n, visto = NOW()
            WHERE codigo = :c AND aprobado = 0 AND creado > NOW() - INTERVAL 1 DAY');
        $st->execute(['n' => $nombre, 'c' => $codigo]);
        if (strlen($codigo) === 6 && $st->rowCount() === 1) {
            flash("Listo: «{$nombre}» ya está autorizada.");
        } else {
            flash('No hay ninguna pantalla esperando con ese código. Revisa el número en el teléfono.', 'error');
        }
        volver_pantalla('telefonos');
    }

    if ($accion === 'quitar') {
        $pdo->prepare('DELETE FROM pantalla_disp WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? 0)]);
        flash('Pantalla quitada. Ese teléfono ya no verá los datos de los clientes.');
        volver_pantalla('telefonos');
    }

    if ($accion === 'cartel') {
        $textos = (array)($_POST['m_texto'] ?? []);
        $colores = (array)($_POST['m_color'] ?? []);
        $mensajes = [];
        foreach ($textos as $n => $t) {
            $mensajes[] = ['texto' => (string)$t, 'color' => (string)($colores[$n] ?? '')];
        }
        $cartel = pantalla_cartel_normalizar([
            'hora' => [
                'activa' => !empty($_POST['h_activa']),
                'estilo' => (string)($_POST['h_estilo'] ?? 'led'),
                'color' => (string)($_POST['h_color'] ?? ''),
                'segundos' => (int)($_POST['h_segundos'] ?? 10),
                'formato' => (string)($_POST['h_formato'] ?? '12'),
                'fecha' => !empty($_POST['h_fecha']),
            ],
            'mensajes' => $mensajes,
            'estilo' => (string)($_POST['estilo'] ?? 'neon'),
            'segundos_mensaje' => (int)($_POST['segundos_mensaje'] ?? 5),
            'velocidad' => (string)($_POST['velocidad'] ?? 'normal'),
            'fondo' => (string)($_POST['fondo'] ?? ''),
            'pago' => [
                'activa' => !empty($_POST['p_activa']),
                'segundos' => (int)($_POST['p_segundos'] ?? 15),
                'reloj' => !empty($_POST['p_reloj']),
                'en_datos' => !empty($_POST['p_en_datos']),
                'fondo' => !empty($_POST['p_fondo_auto']) ? '' : (string)($_POST['p_fondo'] ?? ''),
                'imagenes' => array_map('intval', (array)($_POST['p_img'] ?? [])),
            ],
        ]);
        if (count((array)($_POST['p_img'] ?? [])) > PANTALLA_IMG_MAX) {
            flash('Solo se pueden mostrar ' . PANTALLA_IMG_MAX . ' formas de pago a la vez: se guardaron las primeras.', 'error');
            guardar_ajuste($pdo, 'pantalla_cartel', json_encode($cartel, JSON_UNESCAPED_UNICODE));
            volver_pantalla('pago');
        }
        guardar_ajuste($pdo, 'pantalla_cartel', json_encode($cartel, JSON_UNESCAPED_UNICODE));
        flash('Cartel guardado. La pantalla lo cambia en unos segundos.');
        volver_pantalla('cartel');
    }

    if ($accion === 'subir_img') {
        $f = $_FILES['imagen'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('No llegó la imagen. Prueba otra vez (máximo 4 MB).', 'error');
            volver_pantalla('pago');
        }
        $nombre = trim((string)($_POST['nombre'] ?? '')) ?: preg_replace('/\.[a-z0-9]+$/i', '', (string)$f['name']);
        $r = pantalla_img_guardar($pdo, (string)file_get_contents($f['tmp_name']), $nombre);
        if (is_string($r)) { flash($r, 'error'); volver_pantalla('pago'); }
        // si todavía hay lugar, la nueva imagen queda elegida para mostrarse
        if (pantalla_img_elegir_si_hay_lugar($pdo, $r)) {
            flash('Imagen subida y elegida para mostrarse en la pantalla.');
        } else {
            flash('Imagen subida. Ya hay ' . PANTALLA_IMG_MAX . ' elegidas: marca cuáles quieres mostrar y guarda.');
        }
        volver_pantalla('pago');
    }

    if ($accion === 'borrar_img') {
        pantalla_img_borrar($pdo, (int)($_POST['id'] ?? 0));
        flash('Imagen borrada.');
        volver_pantalla('pago');
    }

    if ($accion === 'restablecer') {
        $antes = pantalla_cartel($pdo);        // las formas de pago no se tocan
        guardar_ajuste($pdo, 'pantalla_cartel', json_encode(['pago' => $antes['pago']] + PANTALLA_CARTEL_DEFECTO, JSON_UNESCAPED_UNICODE));
        flash('Se volvió al cartel original (las formas de pago se mantienen).');
        volver_pantalla('cartel');
    }
    volver_pantalla();
}

$cartel = pantalla_cartel($pdo);
$imagenesPago = $pdo->query('SELECT id, nombre, huella, ancho, alto FROM pantalla_img ORDER BY id')->fetchAll();
$pantallas = $pdo->query('SELECT id, nombre, visto FROM pantalla_disp WHERE aprobado = 1 ORDER BY id')->fetchAll();
$direccion = preg_replace('#^https?://#', '', rtrim((string)($config['portal_url'] ?? 'https://mecanicatoapantavelez.com'), '/')) . '/pantalla.php';
$estado = pantalla_leer($pdo);
admin_inicio($pdo, 'Pantalla del cliente', 'pantalla');

function select_opciones(array $opciones, string $actual): void {
    foreach ($opciones as $k => $t) {
        echo '<option value="' . e((string)$k) . '"' . ((string)$k === $actual ? ' selected' : '') . '>' . e($t) . '</option>';
    }
}
?>
<style>
.pc-hoja { max-width: 860px; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
.pc-pasos { margin: 0; padding-left: 20px; color: var(--suave); }
.pc-estado { font-weight: 700; padding: 8px 12px; border-radius: 10px; background: #eef1f2; color: var(--suave); }
.pc-estado.ok { background: var(--ok-fondo); color: var(--ok); }
.bloque { border: 1px solid var(--borde); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 12px; background: #fbfcfc; }
.bloque h3 { margin: 0; font-size: 1.05rem; }
.fila-campos { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; }
.color-campo { display: flex; align-items: center; gap: 8px; }
.color-campo input[type=color] { width: 54px; height: 44px; padding: 2px; border-radius: 8px; border: 1px solid var(--borde); background: #fff; cursor: pointer; }
.mensajes { display: flex; flex-direction: column; gap: 8px; }
.mensaje { display: grid; grid-template-columns: 1fr 54px auto; gap: 8px; align-items: center; }
.mensaje input[type=text] { min-height: 44px; text-transform: uppercase; }
.mensaje input[type=color] { width: 54px; height: 44px; padding: 2px; border-radius: 8px; border: 1px solid var(--borde); background: #fff; }
.muestra { border-radius: 12px; padding: 18px 12px; text-align: center; font-weight: 800; font-size: 1.6rem; letter-spacing: .03em;
  text-transform: uppercase; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.casilla-linea { display: flex; gap: 8px; align-items: center; font-weight: 600; }
.acciones-fila { display: flex; gap: 10px; flex-wrap: wrap; }
.pagos { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
.pago-item { border: 2px solid var(--borde); border-radius: 12px; background: #fff; overflow: hidden; display: flex; flex-direction: column; }
.pago-item.elegida { border-color: var(--acento); box-shadow: 0 0 0 3px rgba(13,92,99,.15); }
.pago-item img { width: 100%; aspect-ratio: 4 / 3; object-fit: contain; background: #eef1f2; display: block; }
.pago-item .pie { padding: 8px 10px; display: flex; flex-direction: column; gap: 6px; }
.pago-item .pie b { font-size: .9rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.pago-item .pie .fila { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.subir { display: grid; grid-template-columns: 1fr 1fr auto; gap: 10px; align-items: end; }
@media (max-width: 640px) { .subir { grid-template-columns: 1fr; } }
.previa-marco { display: flex; flex-direction: column; gap: 10px; align-items: center; }
.previa { border: 0; border-radius: 10px; background: #050506; box-shadow: 0 6px 24px rgba(0,0,0,.18); width: 100%; aspect-ratio: 16 / 9; }
.previa.vertical { width: min(100%, 300px); aspect-ratio: 9 / 16; }
.subiendo { color: var(--acento); font-weight: 700; display: none; }
</style>

<div class="pc-hoja">
<h1>Pantalla del cliente</h1>
<p class="muted">Un monitor conectado a la PC por cable (HDMI), o un teléfono o tablet en el mostrador, le muestra al cliente
sus datos y el total mientras haces la factura en FactuSYS (el programa de la PC los lee de la pantalla). Cuando no hay
factura muestra el cartel: la hora, tus mensajes y tus formas de pago, turnándose. El monitor se activa en el programa de la
PC (pestaña «Pantalla del cliente»); lo que se muestra se configura aquí.</p>
<p class="pc-estado<?= $estado['fase'] !== 'vacio' ? ' ok' : '' ?>">Ahora muestra:
<?= e(['vacio' => 'el cartel', 'llenando' => 'datos de una factura', 'revisar' => 'datos de una factura',
    'gracias' => 'el agradecimiento'][$estado['fase']] ?? $estado['fase']) ?></p>

<section class="caja" id="previa">
<h2>Vista previa</h2>
<p class="muted small">Así se ve el cartel ahora mismo (se actualiza al guardar). En el monitor o el teléfono ocupa toda la pantalla.</p>
<div class="previa-marco">
<div class="acciones-fila"><button type="button" class="boton boton-borde boton-chico" data-or="h">Monitor / de lado</button><button type="button" class="boton boton-claro boton-chico" data-or="v">Teléfono de pie</button></div>
<iframe class="previa" id="previa_marco" src="../pantalla.php?modo=muestra" title="Vista previa del cartel" loading="lazy"></iframe>
</div>
</section>

<section class="caja" id="telefonos">
<h2>Teléfonos y tablets</h2>
<ol class="pc-pasos">
<li>En el teléfono o tablet abre <b style="word-break:break-all"><?= e($direccion) ?></b> y toca la pantalla.</li>
<li>Aparece un código de 6 números: escríbelo aquí.</li>
</ol>
<form method="post" class="rejilla-form">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="aprobar">
<div class="campo"><label for="p_codigo">Código del teléfono</label><input id="p_codigo" name="codigo" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="000000" required></div>
<div class="campo"><label for="p_nombre">Nombre (opcional)</label><input id="p_nombre" name="nombre" maxlength="60" placeholder="Pantalla del mostrador"></div>
<div class="campo" style="justify-content:flex-end"><button class="boton boton-principal" type="submit">Autorizar pantalla</button></div>
</form>
<?php if ($pantallas): ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Pantalla</th><th>Última conexión</th><th></th></tr></thead>
<tbody>
<?php foreach ($pantallas as $p): $enLinea = $p['visto'] && strtotime((string)$p['visto']) > time() - 90; ?>
<tr>
<td><?= e($p['nombre']) ?></td>
<td><?= $enLinea ? '<b style="color:var(--ok)">Conectada ahora</b>' : ($p['visto'] ? e(fecha_corta(substr((string)$p['visto'], 0, 10)) . ' ' . substr((string)$p['visto'], 11, 5)) : '—') ?></td>
<td class="acciones"><form method="post" onsubmit="return confirm('¿Quitar esta pantalla? Dejará de mostrar los datos.');"><?= csrf_campo() ?><input type="hidden" name="accion" value="quitar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="boton boton-claro boton-chico" type="submit">Quitar</button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
</section>

<section class="caja" id="cartel">
<h2>Cartel</h2>
<p class="muted small">Se ve cuando no hay una factura en curso. Primero la hora por unos segundos, luego cada mensaje, y vuelve a
empezar. Si no escribes mensajes, se queda la hora. Al leer los datos de una factura el cartel se pausa.</p>
<form method="post" id="f_cartel" style="display:flex;flex-direction:column;gap:14px">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="cartel">

<div class="bloque">
<h3>Hora</h3>
<label class="casilla"><input type="checkbox" name="h_activa" value="1"<?= $cartel['hora']['activa'] ? ' checked' : '' ?>> Mostrar la hora</label>
<div class="fila-campos">
<div class="campo"><label for="h_estilo">Estilo</label><select id="h_estilo" name="h_estilo"><?php select_opciones(PANTALLA_CARTEL_ESTILOS, $cartel['hora']['estilo']); ?></select></div>
<div class="campo"><label for="h_color">Color</label><div class="color-campo"><input type="color" id="h_color" name="h_color" value="<?= e($cartel['hora']['color']) ?>"></div></div>
<div class="campo"><label for="h_segundos">Tiempo en pantalla (segundos)</label><input type="number" id="h_segundos" name="h_segundos" min="3" max="120" value="<?= (int)$cartel['hora']['segundos'] ?>"></div>
<div class="campo"><label for="h_formato">Formato</label><select id="h_formato" name="h_formato"><?php select_opciones(['12' => '12 horas (3:27 PM)', '24' => '24 horas (15:27)'], $cartel['hora']['formato']); ?></select></div>
</div>
<label class="casilla"><input type="checkbox" name="h_fecha" value="1"<?= $cartel['hora']['fecha'] ? ' checked' : '' ?>> Mostrar también la fecha</label>
<div class="muestra" id="v_hora">3:27 PM</div>
</div>

<div class="bloque">
<h3>Mensajes</h3>
<div class="fila-campos">
<div class="campo"><label for="estilo">Estilo</label><select id="estilo" name="estilo"><?php select_opciones(PANTALLA_CARTEL_ESTILOS, $cartel['estilo']); ?></select></div>
<div class="campo"><label for="segundos_mensaje">Tiempo por mensaje (segundos, neón)</label><input type="number" id="segundos_mensaje" name="segundos_mensaje" min="2" max="60" value="<?= (int)$cartel['segundos_mensaje'] ?>"></div>
<div class="campo"><label for="velocidad">Velocidad del letrero LED</label><select id="velocidad" name="velocidad"><?php select_opciones(PANTALLA_CARTEL_VELOCIDADES, $cartel['velocidad']); ?></select></div>
</div>
<div class="mensajes" id="mensajes">
<?php foreach ($cartel['mensajes'] as $m): ?>
<div class="mensaje"><input type="text" name="m_texto[]" maxlength="60" value="<?= e($m['texto']) ?>" aria-label="Mensaje"><input type="color" name="m_color[]" value="<?= e($m['color']) ?>" aria-label="Color del mensaje"><button type="button" class="boton boton-claro boton-chico quitar">Quitar</button></div>
<?php endforeach; ?>
</div>
<div><button type="button" class="boton boton-borde boton-chico" id="agregar">+ Agregar mensaje</button> <span class="muted small">Hasta 12, de 60 letras.</span></div>
</div>

<div class="bloque" id="pago">
<h3>Formas de pago</h3>
<p class="muted small">Un turno más del cartel: la hora arriba y abajo tus formas de pago (QR, cuenta del banco…), lo más grandes
que entren. Sube las imágenes o PDF más abajo y marca <b>1 o 2</b> para mostrar.</p>
<label class="casilla"><input type="checkbox" name="p_activa" value="1"<?= $cartel['pago']['activa'] ? ' checked' : '' ?>> Mostrar las formas de pago</label>
<div class="fila-campos">
<div class="campo"><label for="p_segundos">Tiempo en pantalla (segundos)</label><input type="number" id="p_segundos" name="p_segundos" min="4" max="300" value="<?= (int)$cartel['pago']['segundos'] ?>"></div>
</div>
<label class="casilla"><input type="checkbox" name="p_reloj" value="1"<?= $cartel['pago']['reloj'] ? ' checked' : '' ?>> La hora arriba de las imágenes (con el estilo y color de la hora)</label>
<label class="casilla"><input type="checkbox" name="p_en_datos" value="1"<?= $cartel['pago']['en_datos'] ? ' checked' : '' ?>> Mostrarlas también junto a los datos y el total mientras se factura</label>
<div class="fila-campos">
<div class="campo"><label for="p_fondo">Color de fondo detrás de las imágenes</label>
<input type="color" id="p_fondo" name="p_fondo" value="<?= e($cartel['pago']['fondo'] ?: '#3d1766') ?>"></div>
</div>
<label class="casilla"><input type="checkbox" id="p_fondo_auto" name="p_fondo_auto" value="1"<?= $cartel['pago']['fondo'] === '' ? ' checked' : '' ?>> Automático (toma el color del borde de la imagen)</label>
<?php if ($imagenesPago): ?>
<div class="pagos" id="pagos">
<?php foreach ($imagenesPago as $im): $sel = in_array((int)$im['id'], $cartel['pago']['imagenes'], true); ?>
<div class="pago-item<?= $sel ? ' elegida' : '' ?>">
<img src="../pantalla.php?img=<?= (int)$im['id'] ?>&amp;h=<?= e($im['huella']) ?>" alt="<?= e($im['nombre']) ?>" loading="lazy">
<div class="pie">
<b title="<?= e($im['nombre']) ?>"><?= e($im['nombre']) ?></b>
<div class="fila">
<label class="casilla"><input type="checkbox" class="elegir-pago" name="p_img[]" value="<?= (int)$im['id'] ?>"<?= $sel ? ' checked' : '' ?>> Mostrar</label>
<button type="submit" class="boton boton-claro boton-chico" form="borrar_<?= (int)$im['id'] ?>">Borrar</button>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<p class="muted small" id="aviso_pago">Marcadas: <b id="n_pago">0</b> de <?= PANTALLA_IMG_MAX ?>.</p>
<?php else: ?>
<p class="muted small">Todavía no has subido ninguna imagen.</p>
<?php endif; ?>
</div>

<div class="bloque">
<h3>Fondo</h3>
<div class="color-campo"><input type="color" name="fondo" id="fondo" value="<?= e($cartel['fondo']) ?>" aria-label="Color de fondo"><span class="muted small">Negro se ve mejor con neón y LED.</span></div>
</div>

<div class="acciones-fila">
<button class="boton boton-principal" type="submit">Guardar cartel</button>
</div>
</form>
<form method="post" onsubmit="return confirm('¿Volver al cartel original? Se borran tus mensajes.');">
<?= csrf_campo() ?><input type="hidden" name="accion" value="restablecer">
<button class="boton boton-claro boton-chico" type="submit">Volver al cartel original</button>
</form>
</section>

<section class="caja" id="subir_pago">
<h2>Subir forma de pago</h2>
<p class="muted small">Una imagen (PNG, JPG) o un PDF; del PDF se toma la primera página. Queda guardada aquí para usarla cuando quieras.</p>
<form method="post" enctype="multipart/form-data" id="f_subir" class="subir">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="subir_img">
<div class="campo"><label for="s_archivo">Imagen o PDF</label><input type="file" id="s_archivo" accept="image/png,image/jpeg,image/webp,application/pdf,.pdf" required></div>
<div class="campo"><label for="s_nombre">Nombre (opcional)</label><input id="s_nombre" name="nombre" maxlength="80" placeholder="Deuna / Banco Pichincha"></div>
<div class="campo" style="justify-content:flex-end"><button class="boton boton-principal" type="submit" id="s_boton">Subir</button></div>
<input type="file" name="imagen" id="s_imagen" hidden>
</form>
<p class="subiendo" id="s_estado">Preparando la imagen…</p>
<?php foreach ($imagenesPago as $im): ?>
<form method="post" id="borrar_<?= (int)$im['id'] ?>" onsubmit="return confirm('¿Borrar esta imagen?');"><?= csrf_campo() ?><input type="hidden" name="accion" value="borrar_img"><input type="hidden" name="id" value="<?= (int)$im['id'] ?>"></form>
<?php endforeach; ?>
</section>
</div>

<template id="t_mensaje"><div class="mensaje"><input type="text" name="m_texto[]" maxlength="60" aria-label="Mensaje"><input type="color" name="m_color[]" value="#ff2bd6" aria-label="Color del mensaje"><button type="button" class="boton boton-claro boton-chico quitar">Quitar</button></div></template>
<script>
(function () {
  var lista = document.getElementById('mensajes'), fondo = document.getElementById('fondo');
  var COLORES = ['#ff2bd6', '#ffb020', '#39ff7a', '#29d9ff', '#ff3b3b', '#b26bff', '#f4f4f4'];
  function brillo(el, color) {
    el.style.background = fondo.value; el.style.color = '#fff';
    el.style.textShadow = '0 0 4px #fff, 0 0 10px ' + color + ', 0 0 22px ' + color + ', 0 0 40px ' + color;
  }
  function pintar() {
    lista.querySelectorAll('.mensaje').forEach(function (m) {
      var t = m.querySelector('input[type=text]'), c = m.querySelector('input[type=color]');
      t.style.background = fondo.value; t.style.color = c.value; t.style.fontWeight = '800';
    });
    var h = document.getElementById('v_hora');
    h.textContent = document.getElementById('h_formato').value === '24' ? '15:27' : '3:27 PM';
    brillo(h, document.getElementById('h_color').value);
  }
  document.getElementById('agregar').addEventListener('click', function () {
    if (lista.children.length >= 12) { alert('Máximo 12 mensajes.'); return; }
    var n = document.getElementById('t_mensaje').content.firstElementChild.cloneNode(true);
    n.querySelector('input[type=color]').value = COLORES[lista.children.length % COLORES.length];
    lista.appendChild(n); n.querySelector('input[type=text]').focus(); pintar();
  });
  lista.addEventListener('click', function (ev) {
    if (ev.target.classList.contains('quitar')) { ev.target.closest('.mensaje').remove(); pintar(); }
  });
  document.getElementById('f_cartel').addEventListener('input', pintar);
  pintar();

  // formas de pago: máximo <?= PANTALLA_IMG_MAX ?> marcadas
  var MAX = <?= PANTALLA_IMG_MAX ?>;
  function contarPago(ev) {
    var marcadas = document.querySelectorAll('.elegir-pago:checked');
    if (marcadas.length > MAX && ev && ev.target) { ev.target.checked = false; alert('Puedes mostrar 1 o 2 formas de pago a la vez.'); }
    document.querySelectorAll('.elegir-pago').forEach(function (c) { c.closest('.pago-item').classList.toggle('elegida', c.checked); });
    var n = document.getElementById('n_pago'); if (n) n.textContent = document.querySelectorAll('.elegir-pago:checked').length;
  }
  document.querySelectorAll('.elegir-pago').forEach(function (c) { c.addEventListener('change', contarPago); });
  contarPago();

  // color de fondo de las formas de pago: al elegir un color se quita «Automático»
  var pf = document.getElementById('p_fondo'), pa = document.getElementById('p_fondo_auto');
  if (pf && pa) {
    pf.addEventListener('input', function () { pa.checked = false; });
    var sync = function () { pf.style.opacity = pa.checked ? .45 : 1; };
    pa.addEventListener('change', sync); pf.addEventListener('input', sync); sync();
  }

  // vista previa horizontal / vertical
  document.querySelectorAll('[data-or]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.getElementById('previa_marco').classList.toggle('vertical', b.dataset.or === 'v');
      document.querySelectorAll('[data-or]').forEach(function (o) { o.className = 'boton boton-chico ' + (o === b ? 'boton-borde' : 'boton-claro'); });
    });
  });

  // subir: un PDF se convierte a imagen aquí mismo (primera página); fotos muy grandes se achican
  var f = document.getElementById('f_subir'), archivo = document.getElementById('s_archivo'), destino = document.getElementById('s_imagen');
  var estado = document.getElementById('s_estado'), boton = document.getElementById('s_boton');
  function cargarScript(src) {
    return new Promise(function (ok, mal) { var s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = mal; document.head.appendChild(s); });
  }
  var PDFJS = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/';
  function pdfAImagen(file) {
    return (window.pdfjsLib ? Promise.resolve() : cargarScript(PDFJS + 'pdf.min.js')).then(function () {
      pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS + 'pdf.worker.min.js';
      return file.arrayBuffer();
    }).then(function (buf) { return pdfjsLib.getDocument({ data: buf }).promise; })
      .then(function (pdf) { return pdf.getPage(1); })
      .then(function (pag) {
        var v1 = pag.getViewport({ scale: 1 }), escala = 2200 / Math.max(v1.width, v1.height), v = pag.getViewport({ scale: escala });
        var c = document.createElement('canvas'); c.width = Math.round(v.width); c.height = Math.round(v.height);
        var g = c.getContext('2d'); g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
        return pag.render({ canvasContext: g, viewport: v }).promise.then(function () { return lienzoABlob(c, 'image/png'); });
      });
  }
  function lienzoABlob(c, tipo) { return new Promise(function (ok) { c.toBlob(ok, tipo, 0.92); }); }
  function achicar(file) {
    return new Promise(function (ok, mal) {
      var img = new Image(), url = URL.createObjectURL(file);
      img.onload = function () {
        var lado = Math.max(img.naturalWidth, img.naturalHeight);
        if (lado <= 2600 && file.size <= 3.5 * 1024 * 1024) { URL.revokeObjectURL(url); ok(file); return; }
        var k = Math.min(1, 2400 / lado), c = document.createElement('canvas');
        c.width = Math.round(img.naturalWidth * k); c.height = Math.round(img.naturalHeight * k);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height); URL.revokeObjectURL(url);
        lienzoABlob(c, file.type === 'image/png' ? 'image/png' : 'image/jpeg').then(ok);
      };
      img.onerror = function () { mal(new Error('No se pudo abrir la imagen.')); };
      img.src = url;
    });
  }
  f.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var file = archivo.files[0];
    if (!file) return;
    var esPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    estado.style.display = 'block'; estado.textContent = esPdf ? 'Convirtiendo el PDF en imagen…' : 'Preparando la imagen…'; boton.disabled = true;
    (esPdf ? pdfAImagen(file) : achicar(file)).then(function (blob) {
      var nombre = file.name.replace(/\.[a-z0-9]+$/i, '') + (blob.type === 'image/png' ? '.png' : '.jpg');
      var dt = new DataTransfer(); dt.items.add(new File([blob], nombre, { type: blob.type }));
      destino.files = dt.files;
      estado.textContent = 'Subiendo…';
      f.submit();
    }).catch(function (e) {
      estado.textContent = 'No se pudo preparar el archivo: ' + (e && e.message ? e.message : e); boton.disabled = false;
    });
  });
})();
</script>
<?php admin_fin();
