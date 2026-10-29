<?php
// Facturar desde el celular: se arma la factura aquí y el programa de la PC la hace en FactuSYS.
require __DIR__ . '/_lib.php';
requiere_admin();

const FS_PC_EN_LINEA_SEG = 120;   // si la PC no reporta en este tiempo se muestra "desconectada"

function json_salida(int $codigo, array $datos): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function estado_pc(PDO $pdo): array {
    $st = $pdo->query("SELECT svalue FROM site_settings WHERE skey = 'agente_visto'");
    $visto = $st ? (int)$st->fetchColumn() : 0;
    if ($visto <= 0) return ['conectada' => false, 'texto' => 'La PC aún no se ha conectado'];
    $hace = max(0, time() - $visto);
    if ($hace <= FS_PC_EN_LINEA_SEG) return ['conectada' => true, 'texto' => 'PC conectada'];
    $cuando = $hace < 3600 ? 'hace ' . max(1, intdiv($hace, 60)) . ' min'
        : ($hace < 86400 ? 'hace ' . intdiv($hace, 3600) . ' h' : 'el ' . fecha_corta(date('Y-m-d', $visto)));
    return ['conectada' => false, 'texto' => "PC desconectada (última vez $cuando). Las facturas esperan en cola."];
}

function fila_para_celular(array $f): array {
    return [
        'id' => (int)$f['id'], 'perfil' => FS_PERFILES[(int)$f['perfil']]['corto'] ?? '?',
        'nombre' => $f['nombre'], 'ruc' => $f['ruc'], 'telefono' => $f['telefono'],
        'total' => number_format((float)$f['total'], 2, '.', ''),
        'estado' => $f['estado'], 'estado_texto' => FS_ESTADOS[$f['estado']] ?? $f['estado'],
        'numero' => $f['numero'], 'mensaje' => $f['mensaje'],
        'hora' => substr((string)$f['created_at'], 11, 5),
        'fecha' => substr((string)$f['created_at'], 0, 10),
    ];
}

// ---------------------------------------------------------------- API (fetch del celular)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valido()) json_salida(400, ['ok' => false, 'error' => 'La página caducó. Recárgala.']);
    $accion = (string)($_POST['accion'] ?? '');

    if ($accion === 'buscar') {
        $perfil = (int)($_POST['perfil'] ?? 0);
        $ruc = preg_replace('/\D/', '', (string)($_POST['ruc'] ?? ''));
        if (!isset(FS_PERFILES[$perfil]) || !fs_identificacion_valida($ruc)) {
            json_salida(422, ['ok' => false, 'error' => 'La cédula tiene 10 dígitos y el RUC 13.']);
        }
        $st = $pdo->prepare('SELECT nombre, direccion, telefono, email FROM fs_clientes WHERE perfil = :p AND ruc = :r');
        $st->execute(['p' => $perfil, 'r' => $ruc]);
        if ($c = $st->fetch()) json_salida(200, ['ok' => true, 'origen' => 'factusys', 'cliente' => $c]);
        // Cliente facturado antes desde el celular, aunque la PC aún no haya mandado la lista nueva
        $st = $pdo->prepare("SELECT nombre, direccion, telefono, email FROM fs_cola WHERE perfil = :p AND ruc = :r
            AND estado NOT IN ('cancelada','error') ORDER BY id DESC LIMIT 1");
        $st->execute(['p' => $perfil, 'r' => $ruc]);
        if ($c = $st->fetch()) json_salida(200, ['ok' => true, 'origen' => 'factusys', 'cliente' => $c]);
        $sri = fs_consulta_sri($ruc);
        if ($sri) json_salida(200, ['ok' => true, 'origen' => 'sri', 'cliente' => $sri + ['telefono' => '', 'email' => '']]);
        json_salida(200, ['ok' => true, 'origen' => 'nuevo', 'cliente' => null]);
    }

    if ($accion === 'crear') {
        $d = fn($k, $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $max);
        $perfil = (int)($_POST['perfil'] ?? 0);
        $ruc = preg_replace('/\D/', '', (string)($_POST['ruc'] ?? ''));
        $nombre = mb_strtoupper($d('nombre', 200));
        $direccion = mb_strtoupper($d('direccion', 255));
        $email = mb_strtolower($d('email', 160));
        $detalle = mb_strtoupper($d('detalle', 300));
        $sinWhats = !empty($_POST['sin_whatsapp']);
        $telefono = preg_replace('/\D/', '', (string)($_POST['telefono'] ?? ''));
        $total = (float)str_replace(',', '.', (string)($_POST['total'] ?? '0'));
        $errores = [];
        if (!isset(FS_PERFILES[$perfil])) $errores[] = 'Elige a nombre de quién se factura.';
        if (!fs_identificacion_valida($ruc)) $errores[] = 'La cédula tiene 10 dígitos y el RUC 13.';
        $origen = (string)($_POST['origen'] ?? 'nuevo');
        if (!in_array($origen, ['factusys', 'sri', 'nuevo'], true)) $origen = 'nuevo';
        // FactuSYS trae nombre y dirección del SRI al dar Enter en el RUC; a mano solo si no se encontró
        if ($origen === 'nuevo' && mb_strlen($nombre) < 3) $errores[] = 'Falta el nombre del cliente.';
        if ($origen === 'nuevo' && strlen($ruc) === 10 && $direccion === '') $errores[] = 'Falta la dirección (con cédula FactuSYS no la trae).';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errores[] = 'El correo no es válido.';
        if (!$sinWhats && !preg_match('/^09\d{8}$/', $telefono)) $errores[] = 'El celular debe tener 10 dígitos y empezar con 09.';
        if ($detalle === '') $detalle = FS_DETALLE_POR_DEFECTO;
        if ($total < 0.01 || $total > 100000) $errores[] = 'Escribe el valor a cobrar.';
        if ($errores) json_salida(422, ['ok' => false, 'error' => implode(' ', $errores)]);
        if ($sinWhats && !preg_match('/^09\d{8}$/', $telefono)) $telefono = '';

        // Evita duplicados por doble toque: misma factura en los últimos 2 minutos
        $st = $pdo->prepare("SELECT id FROM fs_cola WHERE perfil = :p AND ruc = :r AND total = :t
            AND estado <> 'cancelada' AND created_at > NOW() - INTERVAL 2 MINUTE");
        $st->execute(['p' => $perfil, 'r' => $ruc, 't' => round($total, 2)]);
        if ($st->fetchColumn() && empty($_POST['repetir'])) {
            json_salida(409, ['ok' => false, 'duplicada' => true,
                'error' => 'Acabas de mandar una factura igual (mismo cliente y valor). ¿Seguro que es otra?']);
        }
        $existe = $pdo->prepare('SELECT 1 FROM fs_clientes WHERE perfil = :p AND ruc = :r');
        $existe->execute(['p' => $perfil, 'r' => $ruc]);
        $registrado = (bool)$existe->fetchColumn();
        if ($registrado) $origen = 'factusys';
        $conIva = ($_POST['con_iva'] ?? '0') === '1';
        $des = fs_desglose($total, $conIva);
        $pdo->prepare('INSERT INTO fs_cola (perfil, ruc, nombre, direccion, telefono, email, cliente_nuevo, detalle, total, base, iva, con_iva, origen)
            VALUES (:p, :r, :n, :d, :t, :e, :nuevo, :det, :tot, :b, :i, :ci, :o)')->execute([
            'p' => $perfil, 'r' => $ruc, 'n' => $nombre, 'd' => $direccion, 't' => $telefono, 'e' => $email,
            'nuevo' => $registrado ? 0 : 1, 'det' => $detalle, 'o' => $origen,
            'tot' => $des['total'], 'b' => $des['base'], 'i' => $des['iva'], 'ci' => $conIva ? 1 : 0,
        ]);
        json_salida(200, ['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
    }

    if ($accion === 'pantalla') {
        // lo que se está escribiendo, para la pantalla del cliente
        $fase = (string)($_POST['fase'] ?? '');
        if (!in_array($fase, PANTALLA_FASES, true)) json_salida(422, ['ok' => false]);
        $e = pantalla_poner($pdo, $fase, $_POST);
        json_salida(200, ['ok' => true, 'v' => (int)$e['v']]);
    }

    if ($accion === 'cancelar' || $accion === 'reintentar' || $accion === 'cerrar') {
        $id = (int)($_POST['id'] ?? 0);
        [$desde, $hacia] = [
            'cancelar' => [['cola'], 'cancelada'],
            'reintentar' => [['error', 'revisar'], 'cola'],
            'cerrar' => [['error', 'revisar'], 'cancelada'],
        ][$accion];
        $lista = implode(',', array_map(fn($x) => $pdo->quote($x), $desde));
        $st = $pdo->prepare("UPDATE fs_cola SET estado = :e, updated_at = NOW(), mensaje = :m WHERE id = :id AND estado IN ($lista)");
        $st->execute(['e' => $hacia, 'id' => $id, 'm' => $accion === 'reintentar' ? '' : 'Cerrada desde el celular']);
        if ($st->rowCount() !== 1) json_salida(409, ['ok' => false, 'error' => 'Ya no se puede: la PC la está procesando o ya cambió de estado.']);
        json_salida(200, ['ok' => true]);
    }

    json_salida(400, ['ok' => false, 'error' => 'Acción desconocida']);
}

if (($_GET['ver'] ?? '') === 'pantalla') {
    $e = pantalla_leer($pdo);
    json_salida(200, ['ok' => true, 'v' => (int)$e['v'], 'fase' => $e['fase'], 'resp' => $e['resp'], 'activa' => pantalla_activa($pdo)]);
}

if (($_GET['ver'] ?? '') === 'estado') {
    $filas = $pdo->query("SELECT * FROM fs_cola WHERE created_at > NOW() - INTERVAL 3 DAY
        OR estado IN ('cola','procesando','registrada','enviada_pend','error','revisar') ORDER BY id DESC LIMIT 30")->fetchAll();
    json_salida(200, ['ok' => true, 'pc' => estado_pc($pdo), 'facturas' => array_map('fila_para_celular', $filas)]);
}

// ---------------------------------------------------------------- página
$pc = estado_pc($pdo);
$sinClientes = !(int)$pdo->query('SELECT COUNT(*) FROM fs_clientes')->fetchColumn();
$hayPantalla = pantallas_aprobadas($pdo) > 0;
admin_inicio($pdo, 'Facturar', 'facturar');
?>
<style>
.fact { max-width: 620px; width: 100%; margin: 0 auto; display: flex; flex-direction: column; gap: 16px; }
.fact .caja { gap: 12px; }
.pc-estado { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: .9rem; padding: 8px 12px; border-radius: 10px; }
.pc-estado::before { content: ""; width: 10px; height: 10px; border-radius: 50%; flex: none; background: currentColor; }
.pc-on { background: var(--ok-fondo); color: var(--ok); }
.pc-off { background: var(--aviso-fondo); color: var(--aviso-texto); }
.perfiles { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
.perfiles input { position: absolute; opacity: 0; width: 1px; }
.perfiles label { margin: 0; border: 2px solid var(--borde); border-radius: 10px; padding: 12px; text-align: center; cursor: pointer; background: #fff; min-height: 54px; display: flex; flex-direction: column; justify-content: center; }
.perfiles label small { font-weight: 500; color: var(--suave); font-size: .72rem; }
.perfiles input:checked + label { border-color: var(--acento); background: var(--acento-suave); }
.perfiles input:focus-visible + label { outline: 3px solid #f2a900; }
.fact input, .fact textarea { font-size: 1.05rem; min-height: 50px; }
.fact textarea { min-height: 70px; }
.fila-ruc { display: flex; gap: 8px; }
.fila-ruc input { flex: 1; letter-spacing: .04em; }
.fila-ruc .boton { flex: none; }
.origen { font-size: .85rem; font-weight: 700; padding: 6px 10px; border-radius: 8px; }
.origen-factusys { background: var(--ok-fondo); color: var(--ok); }
.origen-sri { background: #e7f0ff; color: #1d4c8f; }
.origen-nuevo { background: var(--aviso-fondo); color: var(--aviso-texto); }
.valor { font-size: 1.6rem !important; font-weight: 800; text-align: right; }
.desglose { display: grid; grid-template-columns: 1fr auto; gap: 2px 12px; font-size: .92rem; color: var(--suave); }
.desglose b { color: var(--tinta); text-align: right; }
.resumen { display: grid; grid-template-columns: auto 1fr; gap: 6px 12px; font-size: .95rem; }
.resumen dt { color: var(--suave); }
.resumen dd { margin: 0; font-weight: 600; word-break: break-word; }
.cola { display: flex; flex-direction: column; gap: 8px; }
.item { border: 1px solid var(--borde); border-radius: 10px; padding: 10px 12px; display: flex; flex-direction: column; gap: 6px; background: #fff; }
.item .fila-entre { gap: 8px; }
.item-est { font-size: .8rem; font-weight: 700; border-radius: 999px; padding: 2px 9px; background: #eef1f2; color: var(--suave); white-space: nowrap; }
.est-procesando, .est-cola { background: #fff1c7; color: #6b4a00; }
.est-registrada, .est-autorizada { background: #e7f0ff; color: #1d4c8f; }
.est-enviada, .est-sin_envio { background: var(--ok-fondo); color: var(--ok); }
.est-enviada_pend { background: #e6f4ea; color: #6b4a00; }
.est-error, .est-revisar { background: var(--error-fondo); color: var(--error); }
.oculto { display: none !important; }
.pantalla-est { font-size: .9rem; font-weight: 700; padding: 8px 12px; border-radius: 10px; background: #eef1f2; color: var(--suave); }
.pantalla-est.p-ok { background: var(--ok-fondo); color: var(--ok); }
.pantalla-est.p-mal { background: var(--error-fondo); color: var(--error); }
</style>

<div class="fact">
<h1>Facturar</h1>
<p id="pc" class="pc-estado <?= $pc['conectada'] ? 'pc-on' : 'pc-off' ?>" role="status"><?= e($pc['texto']) ?></p>
<?php if ($sinClientes): ?>
<p class="alerta alerta-aviso">Todavía no llega la lista de clientes de FactuSYS. Cuando el programa de la PC la envíe, los clientes registrados se llenarán solos.</p>
<?php endif; ?>

<form id="form" class="caja" novalidate autocomplete="off">
<?= csrf_campo() ?>
<div>
<label>A nombre de</label>
<div class="perfiles">
<?php foreach (FS_PERFILES as $n => $p): ?>
<input type="radio" name="perfil" id="perfil<?= $n ?>" value="<?= $n ?>"<?= $n === 1 ? ' checked' : '' ?>>
<label for="perfil<?= $n ?>"><?= e($p['corto']) ?><small><?= e($p['largo']) ?></small></label>
<?php endforeach; ?>
</div>
</div>

<div class="campo">
<label for="ruc">Cédula o RUC del cliente</label>
<div class="fila-ruc">
<input id="ruc" name="ruc" inputmode="numeric" maxlength="13" placeholder="10 o 13 dígitos" required>
<button type="button" id="buscar" class="boton boton-borde">Buscar</button>
</div>
<p id="origen" class="origen oculto" role="status"></p>
</div>

<div id="datos" class="oculto" style="display:flex;flex-direction:column;gap:12px">
<div class="campo"><label for="nombre">Nombre / razón social <span id="nombre_nota" class="ayuda"></span></label><input id="nombre" name="nombre"></div>
<div class="campo"><label for="direccion">Dirección <span id="dir_nota" class="ayuda"></span></label><input id="direccion" name="direccion"></div>
<div class="campo"><label for="telefono">Celular (WhatsApp)</label><input id="telefono" name="telefono" inputmode="tel" maxlength="10" placeholder="09xxxxxxxx"></div>
<label class="casilla"><input type="checkbox" id="sin_whatsapp" name="sin_whatsapp" value="1"> No tiene WhatsApp (solo se envía al correo)</label>
<div class="campo"><label for="email">Correo</label><input id="email" name="email" type="email" inputmode="email" required></div>
<div class="campo"><label for="detalle">Detalle</label><textarea id="detalle" name="detalle" maxlength="300"><?= e(FS_DETALLE_POR_DEFECTO) ?></textarea></div>
<div>
<label>IVA</label>
<div class="perfiles">
<input type="radio" name="con_iva" id="iva0" value="0" checked><label for="iva0">Sin IVA<small>0 %</small></label>
<input type="radio" name="con_iva" id="iva1" value="1"><label for="iva1">Con IVA<small>15 % incluido en el valor</small></label>
</div>
</div>
<div class="campo"><label for="total">Valor a cobrar</label><input id="total" name="total" class="valor" inputmode="decimal" placeholder="0.00" required></div>
<div class="desglose" aria-live="polite"><span>Subtotal</span><b id="d_base">0.00</b><span id="d_iva_txt">IVA 0 %</span><b id="d_iva">0.00</b><span>Total</span><b id="d_total">0.00</b></div>
<p id="error" class="alerta alerta-error oculto" role="alert"></p>
<button type="submit" class="boton boton-principal boton-grande">Revisar y facturar</button>
</div>
</form>

<section id="confirmar" class="caja oculto" aria-labelledby="t_conf">
<h2 id="t_conf">¿Todo está bien?</h2>
<dl id="resumen" class="resumen"></dl>
<?php if ($hayPantalla): ?><p id="pantalla_est" class="pantalla-est" role="status">Mostrando en la pantalla del cliente…</p><?php endif; ?>
<p id="error2" class="alerta alerta-error oculto" role="alert"></p>
<button type="button" id="enviar" class="boton boton-principal boton-grande">Sí, facturar</button>
<button type="button" id="corregir" class="boton boton-claro">Corregir</button>
</section>

<section class="caja">
<div class="fila-entre"><h2>Facturas recientes</h2><span class="muted small">Se actualiza solo</span></div>
<div id="cola" class="cola"><p class="muted">Cargando…</p></div>
</section>
</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var csrf = document.querySelector('input[name="csrf"]').value;
  var IVA = <?= json_encode(FS_IVA) ?>;
  var form = $('form'), ultimoRuc = '', repetir = false, origen = 'nuevo';
  var HAY_PANTALLA = <?= $hayPantalla ? 'true' : 'false' ?>, tPantalla = null, tGracias = null, tVigilar = null;

  function r2(n) { return Math.round((n + Number.EPSILON) * 100) / 100; }
  function conIva() { return form.querySelector('input[name="con_iva"]:checked').value === '1'; }
  function desglose(total) {               // igual que fs_desglose() en el servidor
    total = r2(total);
    if (!conIva()) return { base: total, iva: 0, total: total };
    var aprox = r2(total / (1 + IVA)), mejor = null;
    [0, -0.01, 0.01, -0.02, 0.02].some(function (a) {
      var base = r2(aprox + a), iva = r2(base * IVA), dif = Math.abs(r2(base + iva) - total);
      if (!mejor || dif < mejor.dif - 0.0001) mejor = { base: base, iva: iva, dif: dif };
      return dif < 0.0001;
    });
    return { base: mejor.base, iva: mejor.iva, total: r2(mejor.base + mejor.iva) };
  }
  function valor() { return parseFloat(($('total').value || '').replace(',', '.')) || 0; }
  function pintarDesglose() {
    var d = desglose(valor());
    $('d_base').textContent = d.base.toFixed(2);
    $('d_iva').textContent = d.iva.toFixed(2);
    $('d_iva_txt').textContent = conIva() ? 'IVA 15 %' : 'IVA 0 %';
    $('d_total').textContent = d.total.toFixed(2);
    var v = r2(valor());
    $('d_total').title = '';
    $('d_total').style.color = (v && d.total !== v) ? 'var(--aviso-texto)' : '';
    if (v && d.total !== v) $('d_total').textContent = d.total.toFixed(2) + ' (con IVA no da exacto ' + v.toFixed(2) + ')';
  }
  function post(datos) {
    var fd = new FormData();
    fd.append('csrf', csrf);
    Object.keys(datos).forEach(function (k) { fd.append(k, datos[k]); });
    return fetch('facturar.php', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { j._codigo = r.status; return j; }); });
  }
  function perfil() { return form.querySelector('input[name="perfil"]:checked').value; }

  // --- pantalla del cliente: muestra lo que se escribe; el cliente confirma o pide corregir
  function aPantalla(fase) {
    if (!HAY_PANTALLA) return;
    clearTimeout(tPantalla); clearTimeout(tGracias);
    var d = desglose(valor()), datos = { accion: 'pantalla', fase: fase };
    if (fase !== 'vacio') {
      datos.nombre = $('nombre').value.toUpperCase(); datos.ruc = $('ruc').value;
      datos.direccion = $('direccion').value.toUpperCase();
      datos.telefono = $('sin_whatsapp').checked ? '' : $('telefono').value.replace(/\D/g, '');
      datos.email = $('email').value.trim().toLowerCase();
      datos.detalle = ($('detalle').value || '').toUpperCase();
      datos.base = d.base.toFixed(2); datos.iva = d.iva.toFixed(2); datos.total = d.total.toFixed(2);
      datos.iva_txt = conIva() ? 'IVA 15 %' : 'IVA 0 %';
    }
    post(datos).catch(function () {});
  }
  function aPantallaLuego() {
    if (!HAY_PANTALLA || $('datos').classList.contains('oculto')) return;
    clearTimeout(tPantalla); tPantalla = setTimeout(function () { aPantalla('llenando'); }, 450);
  }
  function vigilarPantalla(activo) {
    clearInterval(tVigilar);
    if (!HAY_PANTALLA || !activo) return;
    var est = $('pantalla_est');
    est.className = 'pantalla-est'; est.textContent = 'Mostrando en la pantalla del cliente…';
    tVigilar = setInterval(function () {
      fetch('facturar.php?ver=pantalla', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (r) { return r.json(); }).then(function (j) {
          if (!j.activa) { est.className = 'pantalla-est'; est.textContent = 'La pantalla del cliente no está conectada.'; return; }
          if (j.resp === 'ok') { est.className = 'pantalla-est p-ok'; est.textContent = '✓ El cliente confirmó que sus datos están correctos.'; }
          else if (j.resp === 'corregir') { est.className = 'pantalla-est p-mal'; est.textContent = '✗ El cliente dice que hay un error. Pregúntale qué corregir.'; }
          else { est.className = 'pantalla-est'; est.textContent = 'Esperando que el cliente revise sus datos en la pantalla…'; }
        }).catch(function () {});
    }, 2000);
  }
  function mostrarError(id, txt) { $(id).textContent = txt; $(id).classList.toggle('oculto', !txt); }

  function buscar() {
    var ruc = $('ruc').value.replace(/\D/g, '');
    $('ruc').value = ruc;
    if (ruc.length !== 10 && ruc.length !== 13) { mostrar('nuevo', 'La cédula tiene 10 dígitos y el RUC 13.', true); return; }
    ultimoRuc = perfil() + ':' + ruc;
    $('buscar').disabled = true; $('buscar').textContent = 'Buscando…';
    post({ accion: 'buscar', perfil: perfil(), ruc: ruc }).then(function (j) {
      if (!j.ok) { mostrar('nuevo', j.error, true); return; }
      var c = j.cliente || {};
      ['nombre', 'direccion', 'telefono', 'email'].forEach(function (k) { $(k).value = c[k] || ''; });
      $('telefono').value = ($('telefono').value.match(/09\d{8}/) || [''])[0];
      var textos = {
        factusys: 'Cliente registrado en FactuSYS. Revisa celular y correo.',
        sri: 'Cliente nuevo: FactuSYS trae nombre y dirección del SRI. Completa celular y correo.',
        nuevo: 'No aparece en el SRI: escribe el nombre' + (ruc.length === 10 ? ', la dirección' : '') + ', celular y correo.'
      };
      origen = j.origen;
      mostrar(j.origen, textos[j.origen], false);
      $('nombre_nota').textContent = origen === 'nuevo' ? '(obligatorio)' : '(lo pone FactuSYS)';
      $('dir_nota').textContent = (origen === 'nuevo' && ruc.length === 10) ? '(obligatoria)' : '(opcional)';
      $('datos').classList.remove('oculto');
      aPantalla('llenando');
      var vacio = ['nombre', 'direccion', 'telefono', 'email'].filter(function (k) { return !$(k).value; })[0];
      (vacio ? $(vacio) : $('total')).focus();
    }).catch(function () { mostrar('nuevo', 'Sin conexión. Intenta de nuevo.', true); })
      .finally(function () { $('buscar').disabled = false; $('buscar').textContent = 'Buscar'; });
  }
  function mostrar(origen, texto, soloAviso) {
    var o = $('origen');
    o.className = 'origen origen-' + origen; o.textContent = texto;
    if (soloAviso) o.className = 'origen origen-nuevo';
  }

  $('buscar').addEventListener('click', buscar);
  $('ruc').addEventListener('input', function () {
    var v = this.value.replace(/\D/g, '');
    if (v !== this.value) this.value = v;
    if ((v.length === 10 || v.length === 13) && perfil() + ':' + v !== ultimoRuc) {
      clearTimeout(this._t); this._t = setTimeout(buscar, 600);
    }
  });
  $('ruc').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); buscar(); } });
  form.querySelectorAll('input[name="perfil"]').forEach(function (r) {
    r.addEventListener('change', function () { if ($('ruc').value.length >= 10) buscar(); });
  });
  $('total').addEventListener('input', pintarDesglose);
  form.querySelectorAll('input[name="con_iva"]').forEach(function (r) { r.addEventListener('change', pintarDesglose); });
  $('sin_whatsapp').addEventListener('change', function () { $('telefono').disabled = this.checked; });
  ['nombre', 'direccion', 'telefono', 'email', 'detalle', 'total'].forEach(function (k) { $(k).addEventListener('input', aPantallaLuego); });
  form.addEventListener('change', aPantallaLuego);

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var err = [];
    if (!/^(\d{10}|\d{13})$/.test($('ruc').value)) err.push('Revisa la cédula o RUC.');
    if (origen === 'nuevo' && $('nombre').value.trim().length < 3) err.push('Falta el nombre.');
    if (origen === 'nuevo' && $('ruc').value.length === 10 && !$('direccion').value.trim()) err.push('Falta la dirección.');
    if (!$('sin_whatsapp').checked && !/^09\d{8}$/.test($('telefono').value.replace(/\D/g, ''))) err.push('El celular debe tener 10 dígitos y empezar con 09.');
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test($('email').value.trim())) err.push('El correo no es válido.');
    if (valor() < 0.01) err.push('Escribe el valor a cobrar.');
    mostrarError('error', err.join(' '));
    if (err.length) return;
    var d = desglose(valor()), p = form.querySelector('input[name="perfil"]:checked + label').firstChild.textContent;
    var filas = [['A nombre de', p], ['Cliente', $('nombre').value.toUpperCase() || '(lo pone FactuSYS)'], ['Cédula/RUC', $('ruc').value],
      ['Celular', $('sin_whatsapp').checked ? 'Sin WhatsApp' : $('telefono').value], ['Correo', $('email').value],
      ['Detalle', $('detalle').value.toUpperCase() || <?= json_encode(FS_DETALLE_POR_DEFECTO) ?>],
      ['Subtotal', d.base.toFixed(2)], [conIva() ? 'IVA 15 %' : 'IVA 0 %', d.iva.toFixed(2)], ['Total', '$ ' + d.total.toFixed(2)]];
    var dl = $('resumen'); dl.innerHTML = '';
    filas.forEach(function (f) {
      var dt = document.createElement('dt'), dd = document.createElement('dd');
      dt.textContent = f[0]; dd.textContent = f[1]; dl.appendChild(dt); dl.appendChild(dd);
    });
    repetir = false; mostrarError('error2', '');
    $('enviar').textContent = 'Sí, facturar';
    form.classList.add('oculto'); $('confirmar').classList.remove('oculto');
    window.scrollTo(0, 0);
    aPantalla('revisar'); vigilarPantalla(true);
  });
  $('corregir').addEventListener('click', function () {
    $('confirmar').classList.add('oculto'); form.classList.remove('oculto');
    vigilarPantalla(false); aPantalla('llenando');
  });
  $('enviar').addEventListener('click', function () {
    var b = this; b.disabled = true; b.textContent = 'Enviando…';
    var datos = { accion: 'crear', perfil: perfil() };
    ['ruc', 'nombre', 'direccion', 'telefono', 'email', 'detalle', 'total'].forEach(function (k) { datos[k] = $(k).value; });
    if ($('sin_whatsapp').checked) datos.sin_whatsapp = '1';
    datos.origen = origen;
    datos.con_iva = conIva() ? '1' : '0';
    if (repetir) datos.repetir = '1';
    post(datos).then(function (j) {
      if (j.ok) {
        vigilarPantalla(false);
        aPantalla('gracias');
        tGracias = setTimeout(function () { aPantalla('vacio'); }, 20000);
        form.reset(); pintarDesglose(); ultimoRuc = '';
        $('datos').classList.add('oculto'); $('origen').className = 'origen oculto';
        $('telefono').disabled = false;
        $('confirmar').classList.add('oculto'); form.classList.remove('oculto');
        aviso('Factura en cola. La PC la hará en FactuSYS.');
        refrescar();
        return;
      }
      if (j.duplicada) { repetir = true; b.textContent = 'Sí, es otra factura'; }
      else b.textContent = 'Sí, facturar';
      mostrarError('error2', j.error || 'No se pudo enviar.');
    }).catch(function () { mostrarError('error2', 'Sin conexión. Revisa el internet e intenta de nuevo.'); b.textContent = 'Sí, facturar'; })
      .finally(function () { b.disabled = false; });
  });

  function aviso(txt) {
    var a = document.createElement('div'); a.className = 'aviso-flotante'; a.setAttribute('role', 'status');
    a.textContent = txt; document.body.appendChild(a);
    setTimeout(function () { a.remove(); }, 4000);
  }

  var botones = {
    cola: [['cancelar', 'Cancelar', '¿Cancelar esta factura? Todavía no se ha hecho en FactuSYS.']],
    error: [['reintentar', 'Reintentar', ''], ['cerrar', 'Descartar', '¿Descartar esta factura?']],
    revisar: [['reintentar', 'No se creó: reintentar', 'Antes de reintentar abre FactuSYS y confirma que esta factura NO se creó, o saldrá dos veces. ¿Reintentar?'],
              ['cerrar', 'Ya está en FactuSYS', '¿Marcar como resuelta? El programa no la enviará por WhatsApp.']]
  };
  function pintarCola(lista) {
    var cont = $('cola'); cont.innerHTML = '';
    if (!lista.length) { cont.innerHTML = '<p class="muted">Aún no hay facturas hechas desde el celular.</p>'; return; }
    lista.forEach(function (f) {
      var it = document.createElement('div'); it.className = 'item';
      var cab = document.createElement('div'); cab.className = 'fila-entre';
      var nom = document.createElement('strong'); nom.textContent = f.nombre || ('RUC ' + f.ruc);
      var est = document.createElement('span'); est.className = 'item-est est-' + f.estado; est.textContent = f.estado_texto;
      cab.appendChild(nom); cab.appendChild(est); it.appendChild(cab);
      var det = document.createElement('div'); det.className = 'muted small';
      det.textContent = '$ ' + f.total + ' · ' + f.perfil + ' · ' + f.hora + (f.numero ? ' · Factura ' + f.numero : '');
      it.appendChild(det);
      if (f.mensaje) { var m = document.createElement('div'); m.className = 'small'; m.textContent = f.mensaje; it.appendChild(m); }
      (botones[f.estado] || []).forEach(function (bt) {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'boton boton-claro boton-chico'; b.textContent = bt[1];
        b.addEventListener('click', function () {
          if (bt[2] && !confirm(bt[2])) return;
          b.disabled = true;
          post({ accion: bt[0], id: f.id }).then(function (j) { if (!j.ok) aviso(j.error); refrescar(); });
        });
        it.appendChild(b);
      });
      cont.appendChild(it);
    });
  }
  var pc = $('pc');
  function refrescar() {
    fetch('facturar.php?ver=estado', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.redirected || !r.ok) throw 0; return r.json(); })
      .then(function (j) {
        pc.textContent = j.pc.texto; pc.className = 'pc-estado ' + (j.pc.conectada ? 'pc-on' : 'pc-off');
        pintarCola(j.facturas);
      }).catch(function () {});
  }
  refrescar();
  setInterval(function () { if (!document.hidden) refrescar(); }, 6000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refrescar(); });
})();
</script>
<?php admin_fin();
