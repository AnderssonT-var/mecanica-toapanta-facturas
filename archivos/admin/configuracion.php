<?php
require __DIR__ . '/_lib.php';
requiere_admin();

function volver(string $ancla = ''): void {
    header('Location: configuracion.php' . ($ancla ? '#' . $ancla : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requiere_post_valido();
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'general') {
        $negocio = trim((string)($_POST['negocio'] ?? ''));
        $wa = trim((string)($_POST['whatsapp'] ?? ''));
        $resenas = trim((string)($_POST['google_review_url'] ?? ''));
        $errores = [];
        if ($negocio === '' || mb_strlen($negocio) > 80) $errores[] = 'el nombre del negocio';
        if ($wa !== '' && enlace_whatsapp($wa) === '') $errores[] = 'el número de WhatsApp (ej. 0959133066)';
        if ($resenas !== '' && url_segura($resenas) === '') $errores[] = 'el enlace de reseñas (debe empezar con https://)';
        if ($errores) {
            flash('Revisa ' . implode(', ', $errores) . '.', 'error');
            volver('general');
        }
        guardar_ajuste($pdo, 'negocio', $negocio);
        guardar_ajuste($pdo, 'whatsapp', $wa === '' ? '' : normalize_phone($wa));
        guardar_ajuste($pdo, 'google_review_url', $resenas);
        flash('Datos generales guardados.');
        volver('general');
    }

    if ($accion === 'sucursal_guardar') {
        $id = (int)($_POST['id'] ?? 0);
        $datos = [
            'name' => trim((string)($_POST['name'] ?? '')),
            'address' => trim((string)($_POST['address'] ?? '')),
            'maps_url' => trim((string)($_POST['maps_url'] ?? '')),
            'phone' => trim((string)($_POST['phone'] ?? '')),
            'hours' => trim((string)($_POST['hours'] ?? '')),
        ];
        if ($datos['name'] === '' || mb_strlen($datos['name']) > 120) {
            flash('La sucursal necesita un nombre.', 'error');
            volver('sucursales');
        }
        if ($datos['maps_url'] !== '' && url_segura($datos['maps_url']) === '') {
            flash('El enlace de Google Maps debe empezar con https://', 'error');
            volver('sucursales');
        }
        if ($datos['phone'] !== '') {
            if (enlace_whatsapp($datos['phone']) === '') {
                flash('Revisa el teléfono de la sucursal (ej. 0959133066).', 'error');
                volver('sucursales');
            }
            $datos['phone'] = normalize_phone($datos['phone']);
        }
        $datos['address'] = mb_substr($datos['address'], 0, 255);
        $datos['hours'] = mb_substr($datos['hours'], 0, 160);
        if ($id > 0) {
            $datos['id'] = $id;
            $pdo->prepare('UPDATE branches SET name = :name, address = :address, maps_url = :maps_url,
                phone = :phone, hours = :hours WHERE id = :id')->execute($datos);
            flash('Sucursal actualizada.');
        } else {
            $datos['orden'] = (int)$pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM branches')->fetchColumn();
            $pdo->prepare('INSERT INTO branches (name, address, maps_url, phone, hours, sort_order)
                VALUES (:name, :address, :maps_url, :phone, :hours, :orden)')->execute($datos);
            flash('Sucursal agregada.');
        }
        volver('sucursales');
    }

    if ($accion === 'sucursal_borrar') {
        $pdo->prepare('DELETE FROM branches WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? 0)]);
        flash('Sucursal eliminada.');
        volver('sucursales');
    }

    if ($accion === 'sucursal_subir') {
        $lista = $pdo->query('SELECT id FROM branches ORDER BY sort_order, id')->fetchAll(PDO::FETCH_COLUMN);
        $pos = array_search((int)($_POST['id'] ?? 0), array_map('intval', $lista), true);
        if ($pos !== false && $pos > 0) {
            [$lista[$pos - 1], $lista[$pos]] = [$lista[$pos], $lista[$pos - 1]];
            $up = $pdo->prepare('UPDATE branches SET sort_order = :o WHERE id = :id');
            foreach ($lista as $o => $bid) $up->execute(['o' => $o + 1, 'id' => $bid]);
        }
        volver('sucursales');
    }

    if ($accion === 'clave') {
        $actual = (string)($_POST['actual'] ?? '');
        $nueva = (string)($_POST['nueva'] ?? '');
        $repetir = (string)($_POST['repetir'] ?? '');
        if (!password_verify($actual, admin_hash_actual($pdo))) {
            flash('La contraseña actual no es correcta.', 'error');
        } elseif (mb_strlen($nueva) < 10) {
            flash('La nueva contraseña debe tener al menos 10 caracteres.', 'error');
        } elseif ($nueva !== $repetir) {
            flash('Las contraseñas nuevas no coinciden.', 'error');
        } else {
            guardar_ajuste($pdo, 'admin_password_hash', password_hash($nueva, PASSWORD_DEFAULT));
            session_regenerate_id(true);
            admin_olvidar_todos($pdo);   // los celulares recordados deben volver a entrar
            flash('Contraseña cambiada. Los celulares recordados tendrán que entrar de nuevo.');
        }
        volver('clave');
    }

    if ($accion === 'pantalla_aprobar') {
        $codigo = preg_replace('/\D/', '', (string)($_POST['codigo'] ?? ''));
        $nombre = mb_substr(trim((string)($_POST['nombre'] ?? '')), 0, 60) ?: 'Pantalla del mostrador';
        $st = $pdo->prepare('UPDATE pantalla_disp SET aprobado = 1, nombre = :n, visto = NOW()
            WHERE codigo = :c AND aprobado = 0 AND creado > NOW() - INTERVAL 1 DAY');
        $st->execute(['n' => $nombre, 'c' => $codigo]);
        if (strlen($codigo) === 6 && $st->rowCount() === 1) {
            flash("Listo: «{$nombre}» ya muestra los datos al facturar.");
        } else {
            flash('No hay ninguna pantalla esperando con ese código. Revisa el número en el teléfono.', 'error');
        }
        volver('pantalla');
    }

    if ($accion === 'pantalla_cartel') {
        $lineas = preg_split('/\R/u', (string)($_POST['mensajes'] ?? ''));
        $mensajes = [];
        foreach ($lineas as $l) {
            $l = mb_substr(trim(preg_replace('/\s+/u', ' ', $l)), 0, 60);
            if ($l !== '') $mensajes[] = $l;
        }
        $mensajes = array_slice($mensajes, 0, 12);
        $estilo = (string)($_POST['estilo'] ?? 'neon');
        $color = (string)($_POST['color'] ?? 'rosa');
        $velocidad = (string)($_POST['velocidad'] ?? 'normal');
        if (!$mensajes) {
            flash('Escribe al menos un mensaje para el cartel.', 'error');
        } elseif (!isset(PANTALLA_CARTEL_ESTILOS[$estilo], PANTALLA_CARTEL_COLORES[$color], PANTALLA_CARTEL_VELOCIDADES[$velocidad])) {
            flash('Opción no válida.', 'error');
        } else {
            guardar_ajuste($pdo, 'pantalla_cartel', json_encode(['mensajes' => $mensajes, 'estilo' => $estilo,
                'color' => $color, 'velocidad' => $velocidad], JSON_UNESCAPED_UNICODE));
            flash('Cartel guardado. La pantalla lo cambia en unos segundos.');
        }
        volver('pantalla');
    }

    if ($accion === 'pantalla_quitar') {
        $pdo->prepare('DELETE FROM pantalla_disp WHERE id = :id')->execute(['id' => (int)($_POST['id'] ?? 0)]);
        flash('Pantalla quitada. Ese teléfono ya no verá los datos de los clientes.');
        volver('pantalla');
    }
    volver();
}

$a = ajustes($config, true);
$lista = sucursales($config);
$editar = (int)($_GET['editar'] ?? 0);

function formulario_sucursal(array $s = []): void {
    $id = (int)($s['id'] ?? 0);
    ?>
<form method="post" class="caja" style="background:#fbfcfc">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="sucursal_guardar">
<input type="hidden" name="id" value="<?= $id ?>">
<h3><?= $id ? 'Editar sucursal' : 'Agregar sucursal' ?></h3>
<div class="rejilla-form">
<div class="campo"><label for="name<?= $id ?>">Nombre</label><input id="name<?= $id ?>" name="name" maxlength="120" required placeholder="Matriz" value="<?= e($s['name'] ?? '') ?>"></div>
<div class="campo"><label for="address<?= $id ?>">Dirección</label><input id="address<?= $id ?>" name="address" maxlength="255" placeholder="Av. principal y calle 2" value="<?= e($s['address'] ?? '') ?>"></div>
<div class="campo"><label for="maps<?= $id ?>">Enlace de Google Maps</label><input id="maps<?= $id ?>" name="maps_url" type="url" maxlength="500" placeholder="https://maps.app.goo.gl/…" value="<?= e($s['maps_url'] ?? '') ?>">
<span class="ayuda">En Google Maps: busca el taller → Compartir → Copiar vínculo.</span></div>
<div class="campo"><label for="phone<?= $id ?>">Teléfono / WhatsApp (opcional)</label><input id="phone<?= $id ?>" name="phone" inputmode="tel" maxlength="20" placeholder="0959133066" value="<?= e(!empty($s['phone']) ? telefono_local($s['phone']) : '') ?>"></div>
<div class="campo"><label for="hours<?= $id ?>">Horario (opcional)</label><input id="hours<?= $id ?>" name="hours" maxlength="160" placeholder="Lun a Sáb 08:00 – 18:00" value="<?= e($s['hours'] ?? '') ?>"></div>
</div>
<div class="acciones-fila">
<button class="boton boton-principal" type="submit"><?= $id ? 'Guardar cambios' : 'Agregar sucursal' ?></button>
<?php if ($id): ?><a class="boton boton-claro" href="configuracion.php#sucursales">Cancelar</a><?php endif; ?>
</div>
</form>
<?php
}

admin_inicio($pdo, 'Configuración', 'configuracion');
?>
<h1>Configuración</h1>

<section class="caja" id="general">
<h2>Contacto y reseñas</h2>
<form method="post" class="rejilla-form">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="general">
<div class="campo"><label for="negocio">Nombre del negocio</label><input id="negocio" name="negocio" maxlength="80" required value="<?= e($a['negocio']) ?>"></div>
<div class="campo"><label for="whatsapp">WhatsApp de contacto</label><input id="whatsapp" name="whatsapp" inputmode="tel" maxlength="20" placeholder="0959133066" value="<?= e($a['whatsapp'] !== '' ? telefono_local($a['whatsapp']) : '') ?>">
<span class="ayuda">Es el botón verde "¿Dudas? Escríbenos por WhatsApp".</span></div>
<div class="campo"><label for="google_review_url">Enlace para dejar reseña en Google</label><input id="google_review_url" name="google_review_url" type="url" maxlength="500" placeholder="https://g.page/r/…/review" value="<?= e($a['google_review_url']) ?>">
<span class="ayuda">En tu Perfil de Empresa de Google: "Pedir reseñas" → copiar enlace.</span></div>
<div class="campo" style="justify-content:flex-end"><button class="boton boton-principal" type="submit">Guardar</button></div>
</form>
</section>

<section class="caja" id="sucursales">
<h2>Sucursales y Google Maps</h2>
<p class="muted small">Aparecen al pie de la página, con el botón "Cómo llegar".</p>
<?php if ($lista): ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Sucursal</th><th>Google Maps</th><th>Teléfono</th><th></th></tr></thead>
<tbody>
<?php foreach ($lista as $i => $s): ?>
<tr>
<td><strong><?= e($s['name']) ?></strong><?php if ($s['address']): ?><br><span class="small muted"><?= e($s['address']) ?></span><?php endif; ?><?php if ($s['hours']): ?><br><span class="small muted"><?= e($s['hours']) ?></span><?php endif; ?></td>
<td><?php if (url_segura($s['maps_url'])): ?><a href="<?= e($s['maps_url']) ?>" target="_blank" rel="noopener">Abrir mapa</a><?php else: ?><span class="muted">Sin enlace</span><?php endif; ?></td>
<td><?= $s['phone'] ? e(telefono_local($s['phone'])) : '<span class="muted">—</span>' ?></td>
<td class="acciones">
<?php if ($i > 0): ?><form method="post"><?= csrf_campo() ?><input type="hidden" name="accion" value="sucursal_subir"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="boton boton-claro boton-chico" type="submit" aria-label="Subir <?= e($s['name']) ?>">Subir</button></form><?php endif; ?>
<a class="boton boton-claro boton-chico" href="configuracion.php?editar=<?= (int)$s['id'] ?>#sucursales">Editar</a>
<form method="post" onsubmit="return confirm('¿Eliminar la sucursal <?= e(addslashes($s['name'])) ?>?');"><?= csrf_campo() ?><input type="hidden" name="accion" value="sucursal_borrar"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button class="boton boton-claro boton-chico" type="submit">Eliminar</button></form>
</td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<?php
$enEdicion = array_values(array_filter($lista, fn($s) => (int)$s['id'] === $editar));
formulario_sucursal($enEdicion[0] ?? []);
?>
</section>

<section class="caja" id="pantalla">
<h2>Pantalla del cliente</h2>
<p class="muted">Un teléfono en el mostrador le muestra al cliente sus datos y el valor a pagar mientras haces la factura en
FactuSYS (el programa de la PC los lee de la pantalla). Cuando no hay una factura en curso muestra un cartel de neón o LED
con tus mensajes.</p>
<ol class="muted" style="margin:0;padding-left:20px">
<li>En ese teléfono abre <b style="word-break:break-all"><?= e(preg_replace('#^https?://#', '', rtrim((string)($config['portal_url'] ?? 'https://mecanicatoapantavelez.com'), '/'))) ?>/pantalla.php</b> y toca la pantalla (puedes guardarla en la pantalla de inicio).</li>
<li>Aparece un código de 6 números: escríbelo aquí abajo.</li>
</ol>
<form method="post" class="rejilla-form">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="pantalla_aprobar">
<div class="campo"><label for="p_codigo">Código del teléfono</label><input id="p_codigo" name="codigo" inputmode="numeric" maxlength="6" pattern="\d{6}" placeholder="000000" required></div>
<div class="campo"><label for="p_nombre">Nombre (opcional)</label><input id="p_nombre" name="nombre" maxlength="60" placeholder="Pantalla del mostrador"></div>
<div class="campo" style="justify-content:flex-end"><button class="boton boton-principal" type="submit">Autorizar pantalla</button></div>
</form>
<?php $cartel = pantalla_cartel($pdo); ?>
<h3 style="margin:8px 0 0">Cartel (cuando no hay factura)</h3>
<form method="post" class="rejilla-form">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="pantalla_cartel">
<div class="campo" style="grid-column:1/-1"><label for="c_mensajes">Mensajes (uno por línea, se van turnando)</label>
<textarea id="c_mensajes" name="mensajes" rows="5" maxlength="900"><?= e(implode("\n", $cartel['mensajes'])) ?></textarea></div>
<div class="campo"><label for="c_estilo">Estilo</label><select id="c_estilo" name="estilo">
<?php foreach (PANTALLA_CARTEL_ESTILOS as $k => $t): ?><option value="<?= $k ?>"<?= $cartel['estilo'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
</select></div>
<div class="campo"><label for="c_color">Color</label><select id="c_color" name="color">
<?php foreach (PANTALLA_CARTEL_COLORES as $k => $t): ?><option value="<?= $k ?>"<?= $cartel['color'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
</select></div>
<div class="campo"><label for="c_vel">Velocidad</label><select id="c_vel" name="velocidad">
<?php foreach (PANTALLA_CARTEL_VELOCIDADES as $k => $t): ?><option value="<?= $k ?>"<?= $cartel['velocidad'] === $k ? ' selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
</select></div>
<div class="campo" style="justify-content:flex-end"><button class="boton boton-principal" type="submit">Guardar cartel</button></div>
</form>
<?php $pantallas = $pdo->query('SELECT id, nombre, visto FROM pantalla_disp WHERE aprobado = 1 ORDER BY id')->fetchAll(); ?>
<?php if ($pantallas): ?>
<div class="tabla-envoltura"><table>
<thead><tr><th>Pantalla</th><th>Última conexión</th><th></th></tr></thead>
<tbody>
<?php foreach ($pantallas as $p): $enLinea = $p['visto'] && strtotime((string)$p['visto']) > time() - 90; ?>
<tr>
<td><?= e($p['nombre']) ?></td>
<td><?= $enLinea ? '<b style="color:var(--ok)">Conectada ahora</b>' : ($p['visto'] ? e(fecha_corta(substr((string)$p['visto'], 0, 10)) . ' ' . substr((string)$p['visto'], 11, 5)) : '—') ?></td>
<td class="acciones"><form method="post" onsubmit="return confirm('¿Quitar esta pantalla? Dejará de mostrar los datos.');"><?= csrf_campo() ?><input type="hidden" name="accion" value="pantalla_quitar"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="boton boton-claro boton-chico" type="submit">Quitar</button></form></td>
</tr>
<?php endforeach; ?>
</tbody>
</table></div>
<?php endif; ?>
</section>

<section class="caja" id="clave">
<h2>Contraseña del panel</h2>
<form method="post" class="rejilla-form">
<?= csrf_campo() ?>
<input type="hidden" name="accion" value="clave">
<div class="campo"><label for="actual">Contraseña actual</label><input id="actual" name="actual" type="password" autocomplete="current-password" required></div>
<div class="campo"><label for="nueva">Nueva contraseña</label><input id="nueva" name="nueva" type="password" autocomplete="new-password" minlength="10" required></div>
<div class="campo"><label for="repetir">Repetir nueva</label><input id="repetir" name="repetir" type="password" autocomplete="new-password" minlength="10" required></div>
<div class="campo" style="justify-content:flex-end"><button class="boton boton-principal" type="submit">Cambiar contraseña</button></div>
</form>
</section>
<?php admin_fin();
