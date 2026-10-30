<?php
// Pantalla del cliente: un teléfono del mostrador muestra los datos del cliente y el
// valor a pagar mientras se hace la factura en FactuSYS (los lee el programa de la PC).
// Sin factura en curso muestra un cartel (neón o LED) con los mensajes de Configuración.
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
        $cartel = pantalla_cartel($pdo);
        pantalla_json(200, ['ok' => true, 'aprobado' => true, 'v' => (int)$e['v'], 'fase' => $e['fase'],
            'datos' => $e['fase'] === 'vacio' ? new stdClass() : $e['datos'],
            'cartel' => $cartel, 'cartel_v' => substr(md5(json_encode($cartel)), 0, 10),
            'ahora' => (int)round(microtime(true) * 1000)]);
    }
    pantalla_json(400, ['ok' => false]);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#050506">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<title>Pantalla del cliente</title>
<link rel="icon" href="assets/icono.png">
<style>
:root { --acento: #0d5c63; --tinta: #14212b; --suave: #5b6c75; --borde: #d3dfe0; --fondo: #edf2f4; --brillo: #ff2bd6; --cartel: #050506;
  /* todo crece con la pantalla: celular, tablet, de pie o de lado */
  --t: clamp(15px, 1.15vmin + 10px, 30px); }
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; }
body { background: var(--fondo); color: var(--tinta); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  font-size: var(--t); line-height: 1.3; -webkit-text-size-adjust: 100%; -webkit-user-select: none; user-select: none; overflow: hidden; }
body.oscuro { background: var(--cartel); }
.pant { height: 100%; display: flex; flex-direction: column; padding: max(12px, 2.2vmin) max(14px, 3vmin) calc(max(12px, 2.2vmin) + env(safe-area-inset-bottom)); gap: 1.6vmin; overflow: hidden; }
.cab { flex: none; background: var(--acento); color: #fff; border-radius: 14px; padding: .45em .8em; text-align: center;
  font-weight: 800; font-size: 1.25em; letter-spacing: .06em; text-transform: uppercase; white-space: nowrap; overflow: hidden; }
body.oscuro .cab { display: none; }
.vista { display: none; flex: 1; min-height: 0; flex-direction: column; gap: 2vmin; }
.vista.activa { display: flex; }
.centro { align-items: center; justify-content: center; text-align: center; }
h1 { margin: 0; font-size: 1.6em; line-height: 1.15; }
.sub { margin: 0; color: var(--suave); font-size: .95em; }
.codigo { font-size: 3.2em; font-weight: 800; letter-spacing: .12em; color: var(--acento); font-variant-numeric: tabular-nums; }
/* datos de la factura */
.lleno { min-height: 0; }
.titulo-datos { flex: none; }
.cuerpo { flex: 1; min-height: 0; display: flex; flex-direction: column; gap: 1.6vmin; }
.tarjeta { background: #fff; border: 1px solid var(--borde); border-radius: 14px; padding: .6em .9em; overflow: hidden; }
.dato { padding: .32em 0; border-bottom: 1px solid #edf1f2; min-width: 0; }
.dato:last-child { border-bottom: 0; }
.dato span { display: block; color: var(--suave); font-size: .68em; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }
.dato b { display: block; font-size: 1.15em; line-height: 1.2; word-break: break-word; }
.dato b.una { white-space: nowrap; overflow: hidden; word-break: normal; }
.dato b.vacio { color: #9aa8ae; font-weight: 500; }
.total { background: var(--acento); color: #fff; border-radius: 14px; padding: .7em .9em; display: flex; flex-direction: column; gap: .2em; }
.total .lineas { display: flex; justify-content: space-between; font-size: .95em; opacity: .9; }
.total .grande { display: flex; justify-content: space-between; align-items: baseline; font-weight: 800; gap: 10px; flex-wrap: wrap; margin-top: auto; }
.total .grande em { font-style: normal; font-size: 1em; }
.total .grande strong { font-size: 2.6em; line-height: 1; font-variant-numeric: tabular-nums; margin-left: auto; }
@media (orientation: landscape) {
  /* la tarjeta blanca y el total azul empiezan y terminan a la misma altura */
  .cuerpo { display: grid; grid-template-columns: 1.6fr 1fr; align-items: stretch; gap: 2.5vmin; flex: none; }
  .lleno h1 { font-size: 1.3em; }
  .lleno .tarjeta { display: grid; grid-template-columns: 1fr 1fr; column-gap: 1.2em; align-content: start; }
  .lleno .dato.ancho { grid-column: 1 / -1; }
  .total { justify-content: flex-start; }
}
@media (orientation: portrait) {
  .cuerpo { justify-content: space-between; }
  .lleno .dato b { font-size: 1.25em; }
}
/* cartel */
#v_cartel { position: fixed; inset: 0; align-items: center; justify-content: center; background: var(--cartel); overflow: hidden; }
#neon { position: absolute; left: 4vw; right: 4vw; top: 8vh; bottom: 8vh; display: flex; flex-direction: column; align-items: center;
  justify-content: center; text-align: center; font-family: "Trebuchet MS", "Segoe UI", Roboto, sans-serif; font-weight: 700;
  color: #fff; text-transform: uppercase; transition: opacity .6s; }
#neon .linea { white-space: nowrap; line-height: 1.05; text-shadow: 0 0 .03em #fff, 0 0 .08em var(--brillo), 0 0 .18em var(--brillo), 0 0 .35em var(--brillo), 0 0 .6em var(--brillo); }
#neon .fecha { font-size: .2em; margin-top: .5em; letter-spacing: .08em; opacity: .95; }
#neon.parpadeo .linea { animation: parpadeo 5s infinite; }
@keyframes parpadeo { 0%, 18%, 22%, 25%, 53%, 57%, 100% { opacity: 1; } 20%, 24%, 55% { opacity: .55; } }
.marco { position: absolute; inset: 3.5vmin; border: max(2px, .5vmin) solid var(--brillo); border-radius: 4vmin; pointer-events: none;
  box-shadow: 0 0 1.5vmin var(--brillo), inset 0 0 1.5vmin var(--brillo); opacity: .75; transition: border-color .6s, box-shadow .6s; }
#led { position: absolute; inset: 0; width: 100%; height: 100%; }
.inicio { position: fixed; inset: 0; background: rgba(13,92,99,.96); color: #fff; display: flex; flex-direction: column;
  align-items: center; justify-content: center; text-align: center; gap: 10px; font-size: 1.3em; font-weight: 800; padding: 24px; z-index: 5; }
.inicio small { font-weight: 500; font-size: .75em; opacity: .9; }
.sinred { position: fixed; left: 0; right: 0; bottom: 0; background: #7a4a00; color: #fff; text-align: center; padding: 6px; font-size: .8em; display: none; z-index: 6; }
</style>
</head>
<body>
<div class="pant">
<div class="cab"><?= e(APP_NOMBRE_POR_DEFECTO) ?></div>

<section id="v_codigo" class="vista centro" aria-live="polite">
<h1>Autorizar esta pantalla</h1>
<p class="sub">En el panel del taller entra a <b>Pantalla del cliente</b> y escribe este código:</p>
<div id="codigo" class="codigo">······</div>
<p class="sub">Se hace una sola vez.</p>
</section>

<section id="v_cartel" class="vista" aria-hidden="true">
<div class="marco" id="marco"></div>
<div id="neon"></div>
<canvas id="led"></canvas>
</section>

<section id="v_datos" class="vista lleno" aria-live="polite">
<div class="titulo-datos">
<h1>Revise sus datos</h1>
<p class="sub">Así saldrá su factura electrónica.</p>
</div>
<div class="cuerpo">
<div class="tarjeta" id="lista"></div>
<div class="total">
<div class="lineas"><span>Subtotal</span><span id="t_base">0.00</span></div>
<div class="lineas"><span id="t_iva_txt">IVA</span><span id="t_iva">0.00</span></div>
<div class="grande"><em>Total a pagar</em><strong id="t_total">$ 0.00</strong></div>
</div>
</div>
</section>

<section id="v_gracias" class="vista centro">
<h1>¡Gracias por su preferencia!</h1>
<p class="sub" id="gracias_txt">Su factura llegará por WhatsApp.</p>
</section>
</div>
<div id="inicio" class="inicio" role="button" tabindex="0">Toque la pantalla para empezar<small>Así queda encendida y en pantalla completa</small></div>
<div id="sinred" class="sinred">Sin conexión… reintentando</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var CLAVE = 'mtv_pantalla';
  var token = '';
  try { token = localStorage.getItem(CLAVE) || ''; } catch (e) {}
  if (!token) { var m = document.cookie.match(/(?:^|; )mtv_pantalla=([a-f0-9]{64})/); if (m) token = m[1]; }
  var version = -1, faseActual = '', espera = 1500, cartelV = '', desfase = 0;   // desfase: reloj del servidor − el del teléfono

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
    ['v_codigo', 'v_cartel', 'v_datos', 'v_gracias'].forEach(function (v) {
      $(v).className = $(v).className.replace(' activa', '') + (v === id ? ' activa' : '');
    });
    document.body.className = id === 'v_cartel' ? 'oscuro' : '';
    if (id !== 'v_datos') document.body.style.fontSize = '';
    if (id !== 'v_cartel') setTimeout(ajustarCabecera, 0);
    if (id === 'v_cartel') cartel.empezar(); else cartel.pausar();
  }
  function fila(etiqueta, valor, ancho, unaLinea) {
    var d = document.createElement('div'); d.className = 'dato' + (ancho ? ' ancho' : '');
    var s = document.createElement('span'); s.textContent = etiqueta;
    var b = document.createElement('b'); b.textContent = valor || '—';
    b.className = !valor ? 'vacio' : (unaLinea ? 'una' : '');
    d.appendChild(s); d.appendChild(b); return d;
  }
  function pintar(j) {
    var d = j.datos || {};
    if (j.fase === 'vacio') { ver('v_cartel'); return; }
    if (j.fase === 'gracias') {
      $('gracias_txt').textContent = d.telefono ? 'Su factura llegará por WhatsApp al ' + d.telefono + '.' : 'Su factura llegará a su correo.';
      ver('v_gracias'); return;
    }
    var lista = $('lista'); lista.innerHTML = '';
    lista.appendChild(fila('Nombre / razón social', d.nombre, true));
    lista.appendChild(fila('Cédula / RUC', d.ruc, false, true));
    lista.appendChild(fila('Teléfono', d.telefono, false, true));
    lista.appendChild(fila('Dirección', d.direccion, true));
    lista.appendChild(fila('Correo', d.email, true, true));
    if (d.detalle) lista.appendChild(fila('Detalle', d.detalle, true));
    $('t_base').parentNode.style.display = d.base ? '' : 'none';
    $('t_iva').parentNode.style.display = d.base ? '' : 'none';
    $('t_base').textContent = d.base || '0.00';
    $('t_iva').textContent = d.iva || '0.00';
    $('t_iva_txt').textContent = d.iva_txt || 'IVA';
    $('t_total').textContent = '$ ' + (d.total || '0.00');
    ver('v_datos');
    ajustarDatos();
  }
  // las letras crecen hasta llenar la pantalla (celular, tablet, de pie o de lado) sin cortarse
  function ajustarDatos() {
    if ($('v_datos').className.indexOf('activa') < 0) return;
    var pant = document.querySelector('.pant'), lo = 13, hi = 46, mejor = lo, unas = document.querySelectorAll('.dato b.una, .total strong');
    function cabe() {
      if (pant.scrollHeight > pant.clientHeight + 1 || pant.scrollWidth > pant.clientWidth + 1) return false;
      for (var u = 0; u < unas.length; u++) if (unas[u].scrollWidth > unas[u].clientWidth + 1) return false;
      return true;
    }
    for (var k = 0; k < 11; k++) {
      var med = (lo + hi) / 2;
      document.body.style.fontSize = med + 'px';
      if (cabe()) { mejor = med; lo = med; } else hi = med;
    }
    document.body.style.fontSize = Math.floor(mejor * 0.97) + 'px';
    ajustarCabecera();
  }
  // el nombre del taller siempre completo, de lado a lado
  function ajustarCabecera() {
    var c = document.querySelector('.cab');
    c.style.fontSize = '';
    var tam = parseFloat(getComputedStyle(c).fontSize);
    for (var k = 0; k < 30 && c.scrollWidth > c.clientWidth + 1 && tam > 9; k++) { tam *= 0.94; c.style.fontSize = tam + 'px'; }
  }

  // ------------------------------------------------------------------ cartel
  // Turnos: la hora (sus segundos) → cada mensaje → la hora… Si no hay mensajes, solo la hora.
  var DIAS = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
  var MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  function ahora() { return new Date(Date.now() + desfase); }
  function textoHora(conf) {
    var h = ahora(), hh = h.getHours(), mm = ('0' + h.getMinutes()).slice(-2);
    if (conf.formato === '24') return ('0' + hh).slice(-2) + ':' + mm;
    var ampm = hh < 12 ? 'AM' : 'PM'; hh = hh % 12 || 12;
    return hh + ':' + mm + ' ' + ampm;
  }
  function textoFecha() { var h = ahora(); return DIAS[h.getDay()] + ' ' + h.getDate() + ' de ' + MESES[h.getMonth()]; }

  var cartel = (function () {
    var conf = null, turnos = [], n = 0, actual = null, inicio = 0, activo = false, raf = null, mostrado = '';
    var neon = $('neon'), canvas = $('led'), ctx = canvas.getContext('2d');
    var off = document.createElement('canvas'), octx = off.getContext('2d');
    var FILAS = 16, punto = 0, cols = 0, mapa = null, anchoMapa = 0, desplaz = 0, ultimo = 0, rueda = false, dibujado = '';

    function armarTurnos() {
      turnos = [];
      if (!conf) return;
      if (conf.hora.activa || !conf.mensajes.length) {
        turnos.push({ tipo: 'hora', estilo: conf.hora.estilo, color: conf.hora.color, seg: conf.hora.segundos });
      }
      conf.mensajes.forEach(function (m) {
        turnos.push({ tipo: 'msg', texto: m.texto, estilo: conf.estilo, color: m.color, seg: conf.segundos_mensaje });
      });
    }
    // --- neón: el texto más grande que entre, en una o varias líneas
    function ajustarNeon() {
      var caja = neon.getBoundingClientRect(), lo = 10, hi = Math.max(caja.height, 40), mejor = lo;
      for (var k = 0; k < 14; k++) {
        var med = (lo + hi) / 2;
        neon.style.fontSize = med + 'px';
        if (neon.scrollWidth <= caja.width + 1 && neon.scrollHeight <= caja.height + 1) { mejor = med; lo = med; } else hi = med;
      }
      neon.style.fontSize = mejor + 'px';
    }
    function ponerNeon(lineas, fecha) {
      neon.innerHTML = '';
      lineas.forEach(function (t) { var d = document.createElement('div'); d.className = 'linea'; d.textContent = t; neon.appendChild(d); });
      if (fecha) { var f = document.createElement('div'); f.className = 'linea fecha'; f.textContent = fecha; neon.appendChild(f); }
      ajustarNeon();
    }
    function partir(texto) {                       // de pie: frases largas en 2-3 líneas
      var palabras = texto.split(' ');
      var vertical = window.innerHeight > window.innerWidth * 1.1;
      if (!vertical || palabras.length < 2) return [texto];
      var partes = palabras.length > 4 ? 3 : 2, por = Math.ceil(palabras.length / partes), res = [];
      for (var k = 0; k < palabras.length; k += por) res.push(palabras.slice(k, k + por).join(' '));
      return res;
    }
    // --- LED: el texto se dibuja chiquito y cada píxel es un foco
    function mapaDe(texto) {
      octx.font = 'bold 14px "Trebuchet MS", Arial, sans-serif';
      var w = Math.ceil(octx.measureText(texto).width) + 2;
      off.width = w; off.height = FILAS;
      octx.fillStyle = '#000'; octx.fillRect(0, 0, w, FILAS);
      octx.fillStyle = '#fff'; octx.font = 'bold 14px "Trebuchet MS", Arial, sans-serif'; octx.textBaseline = 'middle';
      octx.fillText(texto, 1, FILAS / 2 + 1);
      var px = octx.getImageData(0, 0, w, FILAS).data, m = new Uint8Array(w * FILAS);
      for (var k = 0; k < m.length; k++) m[k] = px[k * 4] > 110 ? 1 : 0;
      return { mapa: m, ancho: w };
    }
    function prepararCanvas() {
      var dpr = Math.min(window.devicePixelRatio || 1, 2);
      canvas.width = Math.floor(window.innerWidth * dpr); canvas.height = Math.floor(window.innerHeight * dpr);
    }
    function ponerLed(texto, fijo) {
      var r = mapaDe(texto); mapa = r.mapa; anchoMapa = r.ancho;
      var W = canvas.width, H = canvas.height;
      if (fijo) {                                     // la hora: quieta y del mayor tamaño que entre
        punto = Math.max(3, Math.floor(Math.min(W * 0.94 / anchoMapa, H * 0.7 / FILAS)));
        cols = anchoMapa; rueda = false;
      } else {                                        // mensaje: corre de derecha a izquierda
        var columnas = W > H ? 64 : 32;                 // de lado entran más letras
        punto = Math.max(4, Math.floor(Math.min(H * 0.45 / FILAS, W / columnas)));
        cols = Math.ceil(W / punto) + 1; rueda = anchoMapa > cols;
        if (!rueda) { punto = Math.max(3, Math.floor(Math.min(W * 0.94 / anchoMapa, H * 0.62 / FILAS))); cols = anchoMapa; }
        desplaz = rueda ? -cols : 0;
      }
      dibujado = '';
    }
    function dibujarLed(fecha) {
      var W = canvas.width, H = canvas.height, c = actual.color, r = punto * 0.38;
      var altoFecha = 0;
      if (fecha) {                                    // la fecha, del tamaño que entre a lo ancho
        altoFecha = Math.floor(Math.min(H * 0.09, punto * FILAS * 0.32));
        ctx.font = '600 ' + altoFecha + 'px "Trebuchet MS", Arial, sans-serif';
        var anchoF = ctx.measureText(fecha.toUpperCase()).width;
        if (anchoF > W * 0.9) altoFecha = Math.floor(altoFecha * W * 0.9 / anchoF);
      }
      var top = Math.floor((H - punto * FILAS - altoFecha * 1.4) / 2), izq = rueda ? 0 : Math.floor((W - cols * punto) / 2);
      ctx.fillStyle = conf.fondo; ctx.fillRect(0, 0, W, H);
      for (var y = 0; y < FILAS; y++) {
        for (var x = 0; x < cols; x++) {
          var mx = x + desplaz, on = mx >= 0 && mx < anchoMapa && mapa[y * anchoMapa + mx];
          ctx.beginPath();
          ctx.arc(izq + x * punto + punto / 2, top + y * punto + punto / 2, r, 0, 6.2832);
          if (on) { ctx.fillStyle = c; ctx.shadowColor = c; ctx.shadowBlur = punto * 0.7; }
          else { ctx.fillStyle = 'rgba(255,255,255,0.07)'; ctx.shadowBlur = 0; }
          ctx.fill();
        }
      }
      ctx.shadowBlur = 0;
      if (fecha) {
        ctx.fillStyle = c; ctx.shadowColor = c; ctx.shadowBlur = altoFecha * 0.4;
        ctx.font = '600 ' + altoFecha + 'px "Trebuchet MS", Arial, sans-serif'; ctx.textAlign = 'center'; ctx.textBaseline = 'top';
        ctx.fillText(fecha.toUpperCase(), W / 2, top + punto * FILAS + altoFecha * 0.5);
        ctx.shadowBlur = 0;
      }
    }
    // --- turnos
    function empezarTurno() {
      if (!turnos.length) armarTurnos();
      actual = turnos[n % turnos.length]; n++;
      inicio = performance.now(); mostrado = '';
      document.documentElement.style.setProperty('--brillo', actual.color);
      var esNeon = actual.estilo === 'neon';
      neon.style.display = esNeon ? '' : 'none';
      $('marco').style.display = esNeon ? '' : 'none';
      canvas.style.display = esNeon ? 'none' : '';
      neon.className = esNeon && actual.tipo === 'msg' ? 'parpadeo' : '';
      if (!esNeon) prepararCanvas();
      if (esNeon) neon.style.opacity = 0;
      refrescar(true);
      if (esNeon) setTimeout(function () { neon.style.opacity = 1; }, 30);
    }
    function refrescar(forzar) {                     // pinta el turno actual (la hora cambia cada minuto)
      var esHora = actual.tipo === 'hora', texto = esHora ? textoHora(conf.hora) : actual.texto;
      var fecha = esHora && conf.hora.fecha ? textoFecha() : '';
      var clave = texto + '|' + fecha + '|' + window.innerWidth + 'x' + window.innerHeight;
      if (!forzar && clave === mostrado) return;
      mostrado = clave;
      if (actual.estilo === 'neon') ponerNeon(esHora ? [texto] : partir(texto), fecha);
      else { prepararCanvas(); ponerLed(texto, esHora); dibujarLed(fecha); }
    }
    function cuadro(t) {
      if (!activo) return;
      var pasado = (t - inicio) / 1000;
      if (actual.estilo === 'led' && rueda) {
        var paso = { lenta: 70, normal: 42, rapida: 24 }[conf.velocidad] || 42;
        if (t - ultimo >= paso) {
          ultimo = t; desplaz++;
          dibujarLed('');
          if (desplaz > anchoMapa) { siguiente(); }
        }
      } else {
        if (actual.tipo === 'hora') refrescar(false);
        var dur = actual.tipo === 'hora' ? actual.seg : actual.seg;
        if (pasado >= dur && turnos.length > 1) { siguiente(); }
        else if (pasado >= dur) { inicio = t; }    // un solo turno: se queda
      }
      raf = requestAnimationFrame(cuadro);
    }
    function siguiente() {
      if (actual && actual.estilo === 'neon') {
        neon.style.opacity = 0;
        var antes = actual; actual = { tipo: 'espera', estilo: 'neon', seg: 99, color: antes.color };
        setTimeout(function () { if (activo) empezarTurno(); }, 600);
      } else empezarTurno();
    }
    return {
      configurar: function (c) {
        conf = c; document.documentElement.style.setProperty('--cartel', c.fondo);
        armarTurnos(); n = 0;
        if (activo) { cancelAnimationFrame(raf); empezarTurno(); raf = requestAnimationFrame(cuadro); }
      },
      empezar: function () {
        if (activo || !conf) return;
        activo = true; empezarTurno(); raf = requestAnimationFrame(cuadro);
      },
      pausar: function () { activo = false; if (raf) cancelAnimationFrame(raf); raf = null; },
      redimensionar: function () { if (activo && actual && actual.tipo !== 'espera') { if (actual.estilo === 'led' && rueda) { prepararCanvas(); ponerLed(actual.texto, false); } else refrescar(true); } }
    };
  })();
  var tRed = null;
  window.addEventListener('resize', function () { clearTimeout(tRed); tRed = setTimeout(function () { cartel.redimensionar(); ajustarDatos(); }, 200); });

  function consultar() {
    if (!token) {
      post({ accion: 'registrar' }, function (st, j) {
        if (j.token) guardarToken(j.token);
        if (j.codigo) { $('codigo').textContent = j.codigo; ver('v_codigo'); }
        setTimeout(consultar, j.token ? 1500 : 5000);
      });
      return;
    }
    var enviado = Date.now();
    post({ accion: 'estado' }, function (st, j) {
      if (st === 401 && j.registrar) {
        token = ''; try { localStorage.removeItem(CLAVE); } catch (e) {}
        setTimeout(consultar, 500); return;
      }
      if (j.ok && j.aprobado === false) { $('codigo').textContent = j.codigo; ver('v_codigo'); espera = 2500; faseActual = 'codigo'; }
      else if (j.ok) {
        espera = 1500;
        if (j.ahora) desfase = j.ahora - (enviado + Date.now()) / 2;
        if (j.cartel && j.cartel_v !== cartelV) { cartelV = j.cartel_v; cartel.configurar(j.cartel); }
        if (j.v !== version || j.fase !== faseActual) { version = j.v; faseActual = j.fase; pintar(j); }
      } else espera = 4000;
      setTimeout(consultar, espera);
    });
  }

  // pantalla completa y sin apagarse (donde el teléfono lo permita)
  function mantenerEncendida() {
    try { if (navigator.wakeLock) navigator.wakeLock.request('screen').then(function () {}, function () {}); } catch (e) {}
  }
  function empezar() {
    $('inicio').style.display = 'none';
    var el = document.documentElement, fs = el.requestFullscreen || el.webkitRequestFullscreen;
    try { if (fs) { var p = fs.call(el); if (p && p.catch) p.catch(function () {}); } } catch (e) {}
    mantenerEncendida();
    setTimeout(function () { cartel.redimensionar(); }, 500);
  }
  $('inicio').onclick = empezar;
  document.addEventListener('visibilitychange', function () { if (!document.hidden && $('inicio').style.display === 'none') mantenerEncendida(); });

  consultar();
})();
</script>
</body>
</html>
