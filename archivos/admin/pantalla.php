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
        ]);
        guardar_ajuste($pdo, 'pantalla_cartel', json_encode($cartel, JSON_UNESCAPED_UNICODE));
        flash('Cartel guardado. La pantalla lo cambia en unos segundos.');
        volver_pantalla('cartel');
    }

    if ($accion === 'restablecer') {
        guardar_ajuste($pdo, 'pantalla_cartel', json_encode(PANTALLA_CARTEL_DEFECTO, JSON_UNESCAPED_UNICODE));
        flash('Se volvió al cartel original.');
        volver_pantalla('cartel');
    }
    volver_pantalla();
}

$cartel = pantalla_cartel($pdo);
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
</style>

<div class="pc-hoja">
<h1>Pantalla del cliente</h1>
<p class="muted">Un teléfono o tablet en el mostrador le muestra al cliente sus datos y el total mientras haces la factura en
FactuSYS (el programa de la PC los lee de la pantalla). Cuando no hay factura muestra el cartel: la hora y tus mensajes,
turnándose.</p>
<p class="pc-estado<?= $estado['fase'] !== 'vacio' ? ' ok' : '' ?>">Ahora muestra:
<?= e(['vacio' => 'el cartel', 'llenando' => 'datos de una factura', 'revisar' => 'datos de una factura',
    'gracias' => 'el agradecimiento'][$estado['fase']] ?? $estado['fase']) ?></p>

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
})();
</script>
<?php admin_fin();
