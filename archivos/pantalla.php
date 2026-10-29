<?php
// Pantalla del cliente: un teléfono del taller muestra, mientras se factura desde el
// panel, los datos del cliente y el valor a pagar para que el cliente los revise.
// El teléfono se autoriza una sola vez con un código de 6 dígitos (Configuración).
$config = require __DIR__ . '/config.php';
require __DIR__ . '/lib/bootstrap.php';
require __DIR__ . '/lib/app.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
$pdo = db($config);
app_schema($pdo);

function pantalla_json(int $codigo, array $datos): void {
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string)($_POST['accion'] ?? '');
    $token = strtolower((string)($_SERVER['HTTP_X_PANTALLA'] ?? ''));

    if ($accion === 'registrar') {
        // limpia pedidos viejos sin aprobar y limita cuántos puede haber
        $pdo->exec('DELETE FROM pantalla_disp WHERE aprobado = 0 AND creado < NOW() - INTERVAL 1 DAY');
        if ((int)$pdo->query('SELECT COUNT(*) FROM pantalla_disp WHERE aprobado = 0')->fetchColumn() >= 30) {
            $pdo->exec('DELETE FROM pantalla_disp WHERE aprobado = 0 ORDER BY creado LIMIT 10');
        }
        $nuevo = bin2hex(random_bytes(32));
        do {
            $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $st = $pdo->prepare('SELECT 1 FROM pantalla_disp WHERE codigo = :c AND aprobado = 0');
            $st->execute(['c' => $codigo]);
        } while ($st->fetchColumn());
        $pdo->prepare('INSERT INTO pantalla_disp (token_hash, codigo) VALUES (:h, :c)')
            ->execute(['h' => hash('sha256', $nuevo), 'c' => $codigo]);
        pantalla_json(200, ['ok' => true, 'token' => $nuevo, 'codigo' => $codigo]);
    }

    $disp = pantalla_dispositivo($pdo, $token);
    if (!$disp) pantalla_json(401, ['ok' => false, 'registrar' => true]);
    if (!(int)$disp['aprobado']) pantalla_json(200, ['ok' => true, 'aprobado' => false, 'codigo' => $disp['codigo']]);

    if ($accion === 'estado') {
        if (!$disp['visto'] || strtotime((string)$disp['visto']) < time() - 30) {
            $pdo->prepare('UPDATE pantalla_disp SET visto = NOW() WHERE id = :id')->execute(['id' => $disp['id']]);
        }
        $e = pantalla_leer($pdo);
        pantalla_json(200, ['ok' => true, 'aprobado' => true, 'v' => (int)$e['v'], 'fase' => $e['fase'],
            'datos' => $e['fase'] === 'vacio' ? new stdClass() : $e['datos'], 'resp' => $e['resp'],
            'negocio' => APP_NOMBRE_POR_DEFECTO]);
    }

    if ($accion === 'responder') {
        $resp = (string)($_POST['resp'] ?? '');
        $e = pantalla_leer($pdo);
        if (!in_array($resp, ['ok', 'corregir'], true) || $e['fase'] !== 'revisar' || (int)($_POST['v'] ?? 0) !== (int)$e['v']) {
            pantalla_json(409, ['ok' => false]);
        }
        $e['resp'] = $resp;          // la versión no cambia: el panel la ve en su próxima consulta
        pantalla_guardar($pdo, $e);
        pantalla_json(200, ['ok' => true]);
    }
    pantalla_json(400, ['ok' => false]);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#0d5c63">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Pantalla del cliente</title>
<link rel="icon" href="assets/icono.png">
<style>
:root { --acento: #0d5c63; --acento-suave: #e3f0ef; --tinta: #14212b; --suave: #5b6c75; --borde: #d3dfe0;
  --fondo: #edf2f4; --ok: #17663a; --ok-fondo: #e7f5ec; --error: #a12622; --error-fondo: #fdecea; }
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; }
body { background: var(--fondo); color: var(--tinta); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  font-size: 18px; line-height: 1.35; -webkit-text-size-adjust: 100%; -webkit-user-select: none; user-select: none; }
.pant { min-height: 100%; display: flex; flex-direction: column; padding: 16px 16px calc(16px + env(safe-area-inset-bottom)); gap: 14px; }
.cab { display: flex; align-items: center; gap: 10px; color: var(--acento); font-weight: 800; font-size: 1rem; letter-spacing: .02em; }
.marca { background: var(--acento); color: #fff; border-radius: 8px; padding: 4px 8px; font-size: .85rem; }
.vista { display: none; flex: 1; flex-direction: column; gap: 14px; }
.vista.activa { display: flex; }
.centro { align-items: center; justify-content: center; text-align: center; }
h1 { margin: 0; font-size: 1.7rem; line-height: 1.15; }
.sub { margin: 0; color: var(--suave); font-size: 1.05rem; }
.codigo { font-size: 3.4rem; font-weight: 800; letter-spacing: .12em; color: var(--acento); font-variant-numeric: tabular-nums; }
.tarjeta { background: #fff; border: 1px solid var(--borde); border-radius: 14px; padding: 14px 16px; }
.dato { padding: 6px 0; border-bottom: 1px solid #edf1f2; }
.dato:last-child { border-bottom: 0; }
.dato span { display: block; color: var(--suave); font-size: .85rem; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }
.dato b { display: block; font-size: 1.2rem; line-height: 1.25; word-break: break-word; }
.dato b.vacio { color: #9aa8ae; font-weight: 500; font-style: italic; font-size: 1.05rem; }
.total { background: var(--acento); color: #fff; border-radius: 14px; padding: 14px 16px; display: flex; flex-direction: column; gap: 4px; }
.total .lineas { display: flex; justify-content: space-between; font-size: 1rem; opacity: .9; }
.total .grande { display: flex; justify-content: space-between; align-items: baseline; font-weight: 800; }
.total .grande em { font-style: normal; font-size: 1.1rem; }
.total .grande strong { font-size: 2.5rem; line-height: 1; font-variant-numeric: tabular-nums; }
.botones { display: grid; gap: 8px; }
/* vertical: el total y los botones quedan siempre a la vista, abajo */
.lleno > .lado + .lado { position: sticky; bottom: 0; display: flex; flex-direction: column; gap: 8px;
  background: var(--fondo); padding: 8px 0 calc(4px + env(safe-area-inset-bottom)); margin-bottom: -16px; }
.lleno > .lado:first-child { display: flex; flex-direction: column; gap: 10px; }
button { font: inherit; border: 0; border-radius: 14px; min-height: 58px; font-size: 1.25rem; font-weight: 800; cursor: pointer; padding: 10px 14px; }
.b-ok { background: var(--ok); color: #fff; }
.b-mal { background: #fff; color: var(--error); border: 2px solid var(--error); }
button:active { transform: scale(.98); }
.respuesta { border-radius: 14px; padding: 14px 16px; font-size: 1.2rem; font-weight: 700; text-align: center; }
.r-ok { background: var(--ok-fondo); color: var(--ok); }
.r-mal { background: var(--error-fondo); color: var(--error); }
.reloj { font-size: 3rem; font-weight: 800; color: var(--acento); font-variant-numeric: tabular-nums; }
.inicio { position: fixed; inset: 0; background: rgba(13,92,99,.96); color: #fff; display: flex; flex-direction: column;
  align-items: center; justify-content: center; text-align: center; gap: 10px; font-size: 1.4rem; font-weight: 800; padding: 24px; z-index: 5; }
.inicio small { font-weight: 500; font-size: 1rem; opacity: .9; }
.sinred { position: fixed; left: 0; right: 0; bottom: 0; background: #7a4a00; color: #fff; text-align: center; padding: 6px; font-size: .9rem; display: none; }
@media (min-width: 700px) and (orientation: landscape) {
  .lleno { display: grid !important; grid-template-columns: 1.3fr 1fr; align-items: start; }
  .lleno .lado { display: flex; flex-direction: column; gap: 14px; }
  .lleno > .lado + .lado { position: sticky; top: 16px; bottom: auto; margin-bottom: 0; padding: 0; }
}
</style>
</head>
<body>
<div class="pant">
<div class="cab"><span class="marca">MTV</span><span id="negocio"><?= e(APP_NOMBRE_POR_DEFECTO) ?></span></div>

<section id="v_codigo" class="vista centro" aria-live="polite">
<h1>Autorizar esta pantalla</h1>
<p class="sub">En el panel del taller entra a <b>Configuración → Pantalla del cliente</b> y escribe este código:</p>
<div id="codigo" class="codigo">······</div>
<p class="sub">Se hace una sola vez.</p>
</section>

<section id="v_vacio" class="vista centro">
<h1>¡Bienvenido!</h1>
<p class="sub">Aquí verá los datos de su factura y el valor a pagar.</p>
<div id="reloj" class="reloj"></div>
</section>

<section id="v_datos" class="vista lleno" aria-live="polite">
<div class="lado">
<div>
<h1 id="titulo">Revise sus datos</h1>
<p class="sub" id="subtitulo">Así saldrá su factura electrónica.</p>
</div>
<div class="tarjeta" id="lista"></div>
</div>
<div class="lado">
<div class="total">
<div class="lineas"><span>Subtotal</span><span id="t_base">0.00</span></div>
<div class="lineas"><span id="t_iva_txt">IVA 0 %</span><span id="t_iva">0.00</span></div>
<div class="grande"><em>Total a pagar</em><strong id="t_total">$ 0.00</strong></div>
</div>
<div id="botones" class="botones" style="display:none">
<button type="button" class="b-ok" id="b_ok">✓ Mis datos están correctos</button>
<button type="button" class="b-mal" id="b_mal">✗ Hay un error</button>
</div>
<div id="respuesta" class="respuesta" style="display:none"></div>
</div>
</section>

<section id="v_gracias" class="vista centro">
<h1>¡Gracias por su preferencia!</h1>
<p class="sub" id="gracias_txt">Su factura llegará por WhatsApp.</p>
</section>
</div>
<div id="inicio" class="inicio" role="button" tabindex="0">Toque la pantalla para empezar<small>Así la pantalla queda encendida y completa</small></div>
<div id="sinred" class="sinred">Sin conexión… reintentando</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var CLAVE = 'mtv_pantalla';
  var token = '';
  try { token = localStorage.getItem(CLAVE) || ''; } catch (e) {}
  if (!token) { var m = document.cookie.match(/(?:^|; )mtv_pantalla=([a-f0-9]{64})/); if (m) token = m[1]; }
  var version = -1, faseActual = '', respondida = '', espera = 1500, ultimoDatos = {};

  function guardarToken(t) {
    token = t;
    try { localStorage.setItem(CLAVE, t); } catch (e) {}
    document.cookie = 'mtv_pantalla=' + t + '; max-age=' + (60 * 60 * 24 * 3650) + '; path=/; secure; samesite=strict';
  }
  function post(datos, listo) {
    var x = new XMLHttpRequest();
    x.open('POST', 'pantalla.php', true);
    x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    if (token) x.setRequestHeader('X-Pantalla', token);
    x.timeout = 10000;
    x.onload = function () {
      var j = null;
      try { j = JSON.parse(x.responseText); } catch (e) {}
      $('sinred').style.display = 'none';
      listo(x.status, j || {});
    };
    x.onerror = x.ontimeout = function () { $('sinred').style.display = 'block'; listo(0, {}); };
    var cuerpo = [];
    for (var k in datos) cuerpo.push(encodeURIComponent(k) + '=' + encodeURIComponent(datos[k]));
    x.send(cuerpo.join('&'));
  }
  function ver(id) {
    ['v_codigo', 'v_vacio', 'v_datos', 'v_gracias'].forEach(function (v) { $(v).className = $(v).className.replace(' activa', '') + (v === id ? ' activa' : ''); });
  }
  function fila(etiqueta, valor) {
    var d = document.createElement('div'); d.className = 'dato';
    var s = document.createElement('span'); s.textContent = etiqueta;
    var b = document.createElement('b'); b.textContent = valor || 'Se completa al facturar';
    if (!valor) b.className = 'vacio';
    d.appendChild(s); d.appendChild(b); return d;
  }
  function pintar(j) {
    var d = j.datos || {};
    if (j.fase !== 'vacio') ultimoDatos = d;
    if (j.fase === 'vacio') { ver('v_vacio'); return; }
    if (j.fase === 'gracias') {
      $('gracias_txt').textContent = d.telefono ? 'Su factura llegará por WhatsApp al ' + d.telefono + '.' : 'Su factura llegará a su correo.';
      ver('v_gracias'); return;
    }
    var lista = $('lista'); lista.innerHTML = '';
    lista.appendChild(fila('Nombre / razón social', d.nombre));
    lista.appendChild(fila('Cédula / RUC', d.ruc));
    lista.appendChild(fila('Dirección', d.direccion));
    lista.appendChild(fila('Celular (WhatsApp)', d.telefono));
    lista.appendChild(fila('Correo', d.email));
    if (d.detalle) lista.appendChild(fila('Detalle', d.detalle));
    $('t_base').parentNode.style.display = d.base ? '' : 'none';
    $('t_iva').parentNode.style.display = d.base ? '' : 'none';
    $('t_base').textContent = d.base || '0.00';
    $('t_iva').textContent = d.iva || '0.00';
    $('t_iva_txt').textContent = d.iva_txt || 'IVA 0 %';
    $('t_total').textContent = '$ ' + (d.total || '0.00');
    var revisar = j.fase === 'revisar';
    $('titulo').textContent = revisar ? '¿Sus datos están correctos?' : 'Revise sus datos';
    $('subtitulo').textContent = revisar ? 'Toque un botón para avisar al taller.' : 'Así saldrá su factura electrónica.';
    var resp = j.resp || respondida;
    $('botones').style.display = revisar && !resp ? 'grid' : 'none';
    var r = $('respuesta');
    if (revisar && resp) {
      r.style.display = 'block';
      r.className = 'respuesta ' + (resp === 'ok' ? 'r-ok' : 'r-mal');
      r.textContent = resp === 'ok' ? '✓ Gracias. Estamos emitiendo su factura.' : 'Indique al personal qué dato hay que corregir.';
    } else r.style.display = 'none';
    ver('v_datos');
  }
  function consultar() {
    if (!token) {
      post({ accion: 'registrar' }, function (st, j) {
        if (j.token) guardarToken(j.token);
        if (j.codigo) { $('codigo').textContent = j.codigo; ver('v_codigo'); }
        setTimeout(consultar, j.token ? 1500 : 5000);
      });
      return;
    }
    post({ accion: 'estado' }, function (st, j) {
      if (st === 401 && j.registrar) {
        token = ''; try { localStorage.removeItem(CLAVE); } catch (e) {}
        setTimeout(consultar, 500); return;
      }
      if (j.ok && j.aprobado === false) { $('codigo').textContent = j.codigo; ver('v_codigo'); espera = 2500; }
      else if (j.ok) {
        espera = 1500;
        if (j.v !== version || j.fase !== faseActual || (j.resp || '') !== respondida) {
          if (j.v !== version) respondida = '';
          version = j.v; faseActual = j.fase;
          if (j.resp) respondida = j.resp;
          pintar(j);
        }
      } else espera = 4000;
      setTimeout(consultar, espera);
    });
  }
  function responder(resp) {
    respondida = resp;
    pintar({ fase: 'revisar', resp: resp, datos: ultimoDatos });
    post({ accion: 'responder', resp: resp, v: version }, function () {});
  }
  $('b_ok').onclick = function () { responder('ok'); };
  $('b_mal').onclick = function () { responder('corregir'); };

  function reloj() {
    var h = new Date(), p = function (n) { return (n < 10 ? '0' : '') + n; };
    $('reloj').textContent = p(h.getHours()) + ':' + p(h.getMinutes());
  }
  reloj(); setInterval(reloj, 15000);

  // pantalla completa y sin apagarse (donde el teléfono lo permita)
  var bloqueo = null;
  function mantenerEncendida() {
    try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function (b) { bloqueo = b; }, function () {}); } catch (e) {}
  }
  function empezar() {
    $('inicio').style.display = 'none';
    var el = document.documentElement, fs = el.requestFullscreen || el.webkitRequestFullscreen;
    try { if (fs) { var p = fs.call(el); if (p && p.catch) p.catch(function () {}); } } catch (e) {}
    mantenerEncendida();
  }
  $('inicio').onclick = empezar;
  document.addEventListener('visibilitychange', function () { if (!document.hidden && $('inicio').style.display === 'none') mantenerEncendida(); });

  ver('v_vacio');
  consultar();
})();
</script>
</body>
</html>
