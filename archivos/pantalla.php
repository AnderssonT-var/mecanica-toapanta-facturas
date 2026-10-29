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
            'cartel' => $cartel, 'cartel_v' => substr(md5(json_encode($cartel)), 0, 10)]);
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
:root { --acento: #0d5c63; --tinta: #14212b; --suave: #5b6c75; --borde: #d3dfe0; --fondo: #edf2f4; --brillo: #ff2bd6; }
* { box-sizing: border-box; }
html, body { margin: 0; height: 100%; }
body { background: var(--fondo); color: var(--tinta); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
  font-size: 18px; line-height: 1.35; -webkit-text-size-adjust: 100%; -webkit-user-select: none; user-select: none; overflow: hidden; }
body.oscuro { background: #050506; }
.pant { height: 100%; display: flex; flex-direction: column; padding: 16px 16px calc(16px + env(safe-area-inset-bottom)); gap: 14px; overflow: auto; }
.cab { display: flex; align-items: center; gap: 10px; color: var(--acento); font-weight: 800; font-size: 1rem; letter-spacing: .02em; }
.marca { background: var(--acento); color: #fff; border-radius: 8px; padding: 4px 8px; font-size: .85rem; }
body.oscuro .cab { display: none; }
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
.total .grande { display: flex; justify-content: space-between; align-items: baseline; font-weight: 800; gap: 10px; }
.total .grande em { font-style: normal; font-size: 1.1rem; }
.total .grande strong { font-size: 2.5rem; line-height: 1; font-variant-numeric: tabular-nums; }
.lleno > .lado { display: flex; flex-direction: column; gap: 10px; }
@media (orientation: landscape) and (min-width: 600px) {
  .lleno.activa { display: grid; grid-template-columns: 1.6fr 1fr; align-items: start; gap: 14px; }
  .lleno h1 { font-size: 1.35rem; }
  .lleno .sub { font-size: .95rem; }
  .lleno .tarjeta { display: grid; grid-template-columns: 1fr 1fr; column-gap: 16px; padding: 10px 14px; }
  .lleno .dato { padding: 5px 0; }
  .lleno .dato.ancho { grid-column: 1 / -1; }
  .lleno .dato b { font-size: 1.08rem; }
}
/* cartel */
#v_cartel { position: fixed; inset: 0; align-items: center; justify-content: center; background: #050506; overflow: hidden; }
#neon { width: 94vw; text-align: center; font-family: "Trebuchet MS", "Segoe UI", Roboto, sans-serif; font-weight: 700;
  color: #fff; line-height: 1.05; text-transform: uppercase; white-space: nowrap; transition: opacity .6s;
  text-shadow: 0 0 4px #fff, 0 0 10px var(--brillo), 0 0 22px var(--brillo), 0 0 42px var(--brillo), 0 0 80px var(--brillo); }
#neon.parpadeo { animation: parpadeo 4.5s infinite; }
@keyframes parpadeo { 0%, 18%, 22%, 25%, 53%, 57%, 100% { opacity: 1; } 20%, 24%, 55% { opacity: .55; } }
.marco { position: absolute; inset: 4vmin; border: 3px solid var(--brillo); border-radius: 4vmin; pointer-events: none;
  box-shadow: 0 0 12px var(--brillo), inset 0 0 12px var(--brillo); opacity: .75; }
#led { position: absolute; inset: 0; width: 100%; height: 100%; }
.inicio { position: fixed; inset: 0; background: rgba(13,92,99,.96); color: #fff; display: flex; flex-direction: column;
  align-items: center; justify-content: center; text-align: center; gap: 10px; font-size: 1.4rem; font-weight: 800; padding: 24px; z-index: 5; }
.inicio small { font-weight: 500; font-size: 1rem; opacity: .9; }
.sinred { position: fixed; left: 0; right: 0; bottom: 0; background: #7a4a00; color: #fff; text-align: center; padding: 6px; font-size: .9rem; display: none; z-index: 6; }
</style>
</head>
<body>
<div class="pant">
<div class="cab"><span class="marca">MTV</span><span><?= e(APP_NOMBRE_POR_DEFECTO) ?></span></div>

<section id="v_codigo" class="vista centro" aria-live="polite">
<h1>Autorizar esta pantalla</h1>
<p class="sub">En el panel del taller entra a <b>Configuración → Pantalla del cliente</b> y escribe este código:</p>
<div id="codigo" class="codigo">······</div>
<p class="sub">Se hace una sola vez.</p>
</section>

<section id="v_cartel" class="vista" aria-hidden="true">
<div class="marco" id="marco"></div>
<div id="neon"></div>
<canvas id="led"></canvas>
</section>

<section id="v_datos" class="vista lleno" aria-live="polite">
<div class="lado">
<div>
<h1>Revise sus datos</h1>
<p class="sub">Así saldrá su factura electrónica.</p>
</div>
<div class="tarjeta" id="lista"></div>
</div>
<div class="lado">
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
<div id="inicio" class="inicio" role="button" tabindex="0">Toque la pantalla para empezar<small>Así la pantalla queda encendida y completa</small></div>
<div id="sinred" class="sinred">Sin conexión… reintentando</div>

<script>
(function () {
  var $ = function (id) { return document.getElementById(id); };
  var CLAVE = 'mtv_pantalla';
  var token = '';
  try { token = localStorage.getItem(CLAVE) || ''; } catch (e) {}
  if (!token) { var m = document.cookie.match(/(?:^|; )mtv_pantalla=([a-f0-9]{64})/); if (m) token = m[1]; }
  var version = -1, faseActual = '', espera = 1500, cartelV = '';

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
    if (id === 'v_cartel') cartel.empezar(); else cartel.pausar();
  }
  function fila(etiqueta, valor, ancho) {
    var d = document.createElement('div'); d.className = 'dato' + (ancho ? ' ancho' : '');
    var s = document.createElement('span'); s.textContent = etiqueta;
    var b = document.createElement('b'); b.textContent = valor || 'Se completa al facturar';
    if (!valor) b.className = 'vacio';
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
    lista.appendChild(fila('Cédula / RUC', d.ruc));
    lista.appendChild(fila('Dirección', d.direccion, true));
    lista.appendChild(fila('Celular (WhatsApp)', d.telefono));
    lista.appendChild(fila('Correo', d.email));
    if (d.detalle) lista.appendChild(fila('Detalle', d.detalle, true));
    $('t_base').parentNode.style.display = d.base ? '' : 'none';
    $('t_iva').parentNode.style.display = d.base ? '' : 'none';
    $('t_base').textContent = d.base || '0.00';
    $('t_iva').textContent = d.iva || '0.00';
    $('t_iva_txt').textContent = d.iva_txt || 'IVA';
    $('t_total').textContent = '$ ' + (d.total || '0.00');
    ver('v_datos');
  }

  // ---------------------------------------------------------------- cartel
  var COLORES = { rosa: '#ff2bd6', azul: '#29d9ff', verde: '#39ff7a', ambar: '#ffb020', rojo: '#ff3b3b', blanco: '#f4f4f4' };
  var cartel = (function () {
    var conf = { mensajes: ['BIENVENIDOS'], estilo: 'neon', color: 'rosa', velocidad: 'normal' };
    var activo = false, i = 0, tNeon = null, raf = null;
    var neon = $('neon'), canvas = $('led'), ctx = canvas.getContext('2d');
    var punto = 0, filas = 16, cols = 0, mapa = null, anchoMapa = 0, desplaz = 0, ultimo = 0;
    var off = document.createElement('canvas'), octx = off.getContext('2d');

    function color() { return COLORES[conf.color] || COLORES.rosa; }
    function ajustarNeon() {                       // la frase más grande que entre en la pantalla
      var tam = Math.min(window.innerHeight * 0.34, 220);
      neon.style.fontSize = tam + 'px';
      var w = neon.scrollWidth, max = window.innerWidth * 0.9;
      if (w > max) tam = tam * max / w;
      neon.style.fontSize = Math.max(18, tam) + 'px';
    }
    function siguienteNeon() {
      if (!activo || conf.estilo !== 'neon') return;
      neon.style.opacity = 0;
      setTimeout(function () {
        if (!activo) return;
        neon.textContent = conf.mensajes[i % conf.mensajes.length]; i++;
        ajustarNeon(); neon.style.opacity = 1;
      }, 650);
      var dur = { lenta: 7000, normal: 4500, rapida: 2800 }[conf.velocidad] || 4500;
      tNeon = setTimeout(siguienteNeon, dur);
    }
    // LED: el texto se dibuja chiquito y cada píxel se vuelve un foco
    function prepararLed() {
      var dpr = window.devicePixelRatio || 1;
      canvas.width = Math.floor(window.innerWidth * dpr); canvas.height = Math.floor(window.innerHeight * dpr);
      punto = Math.max(6, Math.floor(canvas.height * 0.62 / filas));
      cols = Math.ceil(canvas.width / punto) + 1;
      cargarMensaje();
    }
    function cargarMensaje() {
      var texto = '   ' + conf.mensajes[i % conf.mensajes.length] + '   '; i++;
      octx.font = 'bold 14px "Trebuchet MS", Arial, sans-serif';
      anchoMapa = Math.ceil(octx.measureText(texto).width) + 2;
      off.width = anchoMapa; off.height = filas;
      octx.fillStyle = '#000'; octx.fillRect(0, 0, anchoMapa, filas);
      octx.fillStyle = '#fff'; octx.font = 'bold 14px "Trebuchet MS", Arial, sans-serif'; octx.textBaseline = 'middle';
      octx.fillText(texto, 1, filas / 2 + 1);
      var px = octx.getImageData(0, 0, anchoMapa, filas).data;
      mapa = new Uint8Array(anchoMapa * filas);
      for (var k = 0; k < mapa.length; k++) mapa[k] = px[k * 4] > 110 ? 1 : 0;
      desplaz = -cols;                              // entra por la derecha
    }
    function dibujarLed(t) {
      if (!activo || conf.estilo !== 'led') return;
      var paso = { lenta: 70, normal: 42, rapida: 24 }[conf.velocidad] || 42;   // ms por columna
      if (t - ultimo >= paso) {
        ultimo = t; desplaz++;
        if (desplaz > anchoMapa) cargarMensaje();
        var W = canvas.width, H = canvas.height, top = Math.floor((H - punto * filas) / 2), r = punto * 0.36, c = color();
        ctx.fillStyle = '#050506'; ctx.fillRect(0, 0, W, H);
        for (var y = 0; y < filas; y++) {
          for (var x = 0; x < cols; x++) {
            var mx = x + desplaz, encendido = mx >= 0 && mx < anchoMapa && mapa[y * anchoMapa + mx];
            ctx.beginPath();
            ctx.arc(x * punto + punto / 2, top + y * punto + punto / 2, r, 0, 6.2832);
            if (encendido) { ctx.fillStyle = c; ctx.shadowColor = c; ctx.shadowBlur = punto * 0.8; }
            else { ctx.fillStyle = '#1b1b20'; ctx.shadowBlur = 0; }
            ctx.fill();
          }
        }
        ctx.shadowBlur = 0;
      }
      raf = requestAnimationFrame(dibujarLed);
    }
    function aplicar() {
      document.documentElement.style.setProperty('--brillo', color());
      var esNeon = conf.estilo === 'neon';
      neon.style.display = esNeon ? '' : 'none';
      $('marco').style.display = esNeon ? '' : 'none';
      canvas.style.display = esNeon ? 'none' : '';
      neon.className = esNeon ? 'parpadeo' : '';
    }
    function detener() { clearTimeout(tNeon); if (raf) cancelAnimationFrame(raf); raf = null; }
    return {
      configurar: function (c) {
        conf = c; i = 0; aplicar();
        if (activo) { detener(); activo = false; this.empezar(); }
      },
      empezar: function () {
        if (activo) return;
        activo = true; aplicar();
        if (conf.estilo === 'led') { prepararLed(); raf = requestAnimationFrame(dibujarLed); }
        else { neon.textContent = conf.mensajes[i % conf.mensajes.length]; i++; ajustarNeon(); neon.style.opacity = 1;
               tNeon = setTimeout(siguienteNeon, { lenta: 7000, normal: 4500, rapida: 2800 }[conf.velocidad] || 4500); }
      },
      pausar: function () { activo = false; detener(); },
      redimensionar: function () { if (!activo) return; if (conf.estilo === 'led') prepararLed(); else ajustarNeon(); }
    };
  })();
  window.addEventListener('resize', function () { cartel.redimensionar(); });

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
      if (j.ok && j.aprobado === false) { $('codigo').textContent = j.codigo; ver('v_codigo'); espera = 2500; faseActual = 'codigo'; }
      else if (j.ok) {
        espera = 1500;
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
    setTimeout(function () { cartel.redimensionar(); }, 400);
  }
  $('inicio').onclick = empezar;
  document.addEventListener('visibilitychange', function () { if (!document.hidden && $('inicio').style.display === 'none') mantenerEncendida(); });

  consultar();
})();
</script>
</body>
</html>
