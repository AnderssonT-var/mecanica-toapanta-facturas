'use strict';
// App web del Facturador: comportamientos generales y el formulario de factura.
(function () {
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const r2 = (n) => Math.round((Number(n) + Number.EPSILON) * 100) / 100;
  const dinero = (n) => '$' + r2(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // Confirmaciones y selects que se envían solos.
  document.addEventListener('click', (e) => {
    const c = e.target.closest('[data-confirmar]');
    if (c && !window.confirm(c.dataset.confirmar)) e.preventDefault();
  });
  $$('[data-auto-submit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));

  // Estado de una factura web: se actualiza sola mientras el programa la procesa.
  const rec = $('[data-recargar]');
  if (rec) setTimeout(() => { if (!document.hidden) location.reload(); else document.addEventListener('visibilitychange', () => location.reload(), { once: true }); }, Number(rec.dataset.recargar) * 1000);

  // ---------- Formulario de factura ----------
  const form = $('#facturar');
  if (!form) return;
  const cfg = JSON.parse($('#facturar-config').textContent);
  const T = cfg.tarifas;
  const ordenT = Object.keys(T).sort((a, b) => T[b].pct - T[a].pct);
  const csrf = form.querySelector('[name=csrf]').value;
  const st = { cf: false, items: [] };
  const BORRADOR = 'fx-factura-borrador';

  const subtotal = (it) => { const b = (Number(it.cantidad) || 0) * (Number(it.precioUnitario) || 0); return r2(b - r2(b * (Number(it.descuentoPct) || 0) / 100)); };
  function totales() {
    const por = {};
    let desc = 0;
    st.items.forEach((it) => { por[it.tarifa] = r2((por[it.tarifa] || 0) + subtotal(it)); const b = (Number(it.cantidad) || 0) * (Number(it.precioUnitario) || 0); desc += r2(b * (Number(it.descuentoPct) || 0) / 100); });
    let iva = 0;
    Object.keys(por).forEach((k) => { iva += r2(por[k] * ((T[k] || { pct: 0 }).pct) / 100); });
    const sin = r2(Object.values(por).reduce((a, v) => a + v, 0));
    return { por, desc: r2(desc), iva: r2(iva), sin, total: r2(sin + iva) };
  }

  function guardarBorrador() { try { localStorage.setItem(BORRADOR, JSON.stringify({ st, cli: leerCliente() })); } catch (e) { /* sin almacenamiento */ } }

  // ----- Cliente -----
  const campos = { tipo: $('#cli-tipo'), identificacion: $('#cli-id'), nombre: $('#cli-nombre'), direccion: $('#cli-direccion'), email: $('#cli-email'), telefono: $('#cli-telefono') };
  function leerCliente() {
    if (st.cf) return { tipo: 'CF' };
    const c = {};
    Object.entries(campos).forEach(([k, el]) => { c[k] = el.value.trim(); });
    return c;
  }
  function ponerCliente(c) {
    Object.entries(campos).forEach(([k, el]) => { if (c[k] !== undefined) el.value = c[k]; });
    pintarResumen();
  }
  $$('[data-tipo-cli]').forEach((b) => b.addEventListener('click', () => {
    st.cf = b.dataset.tipoCli === 'CF';
    $$('[data-tipo-cli]').forEach((x) => x.classList.toggle('on', x === b));
    $('#cli-con-id').hidden = st.cf;
    $('#cli-cf').hidden = !st.cf;
    pintarResumen();
  }));
  campos.identificacion.addEventListener('input', () => {
    const v = campos.identificacion.value.replace(/\D/g, '');
    if (campos.tipo.value !== 'PASAPORTE') { if (v.length === 13) campos.tipo.value = 'RUC'; else if (v.length === 10) campos.tipo.value = 'CEDULA'; }
    pintarResumen();
  });
  Object.values(campos).forEach((el) => el.addEventListener('input', () => { pintarResumen(); guardarBorrador(); }));

  // Búsquedas con sugerencias (clientes y productos).
  function buscador(input, lista, tipo, pintar, elegir) {
    let timer = null;
    let res = [];
    let activo = -1;
    const cerrar = () => { lista.hidden = true; activo = -1; };
    const marcar = () => $$('.sug', lista).forEach((x, i) => x.classList.toggle('on', i === activo));
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { cerrar(); return; }
      timer = setTimeout(async () => {
        try {
          const r = await fetch('./?v=buscar&t=' + tipo + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
          res = r.ok ? await r.json() : [];
        } catch (e) { res = []; }
        lista.innerHTML = res.length ? res.map((x, i) => '<button type="button" class="sug" data-i="' + i + '">' + pintar(x) + '</button>').join('') : '<div class="sug-vacio">Sin resultados' + (tipo === 'productos' ? ' · <button type="button" class="enlace" data-libre>usar "' + esc(q) + '" como línea libre</button>' : '') + '</div>';
        lista.hidden = false;
        activo = res.length ? 0 : -1;
        marcar();
      }, 180);
    });
    input.addEventListener('keydown', (e) => {
      if (lista.hidden) return;
      if (e.key === 'ArrowDown') { activo = Math.min(res.length - 1, activo + 1); marcar(); e.preventDefault(); }
      if (e.key === 'ArrowUp') { activo = Math.max(0, activo - 1); marcar(); e.preventDefault(); }
      if (e.key === 'Enter') { e.preventDefault(); if (res[activo]) { elegir(res[activo]); cerrar(); } }
      if (e.key === 'Escape') cerrar();
    });
    lista.addEventListener('click', (e) => {
      const b = e.target.closest('.sug');
      if (b) { elegir(res[Number(b.dataset.i)]); cerrar(); return; }
      if (e.target.closest('[data-libre]')) { agregarLinea({ codigo: '', descripcion: input.value.trim(), precio: 0, tarifa: cfg.tarifaDefecto }, true); input.value = ''; cerrar(); }
    });
    document.addEventListener('click', (e) => { if (!e.target.closest('.busca-sug')) cerrar(); });
  }
  buscador($('#cli-buscar'), $('#cli-sug'), 'clientes',
    (c) => '<b>' + esc(c.nombre) + '</b><small class="mono">' + esc(c.identificacion) + (c.email ? ' · ' + esc(c.email) : '') + '</small>',
    (c) => { ponerCliente(c); $('#cli-buscar').value = ''; guardarBorrador(); });

  // ----- Productos -----
  function agregarLinea(p, enfocarPrecio) {
    const igual = st.items.find((i) => i.codigo && i.codigo === p.codigo && i.descripcion === p.descripcion);
    if (igual) igual.cantidad = String((Number(igual.cantidad) || 0) + 1);
    else st.items.push({ codigo: p.codigo || '', codigoAux: p.codigo_aux || '', descripcion: p.descripcion || '', cantidad: '1', precioUnitario: Number(p.precio) > 0 ? String(p.precio) : '', descuentoPct: '0', tarifa: T[p.tarifa] ? String(p.tarifa) : cfg.tarifaDefecto });
    pintarLineas();
    const i = igual ? st.items.indexOf(igual) : st.items.length - 1;
    const fila = $('[data-fila="' + i + '"]');
    if (fila) { fila.classList.remove('nueva'); void fila.offsetWidth; fila.classList.add('nueva'); }
    const pre = $('[data-i="' + i + '"][data-k="precioUnitario"]');
    if (pre && (enfocarPrecio || !(Number(st.items[i].precioUnitario) > 0))) { pre.focus(); pre.select(); }
    if (!st.items[i].descripcion) { const d = $('[data-i="' + i + '"][data-k="descripcion"]'); if (d) d.focus(); }
  }
  buscador($('#prod-buscar'), $('#prod-sug'), 'productos',
    (p) => '<b>' + esc(p.descripcion) + '</b><small><span class="mono">' + esc(p.codigo) + '</span> · IVA ' + esc((T[p.tarifa] || { label: p.tarifa }).label) + '</small><span class="sug-der mono">' + (p.precio > 0 ? dinero(p.precio) : 'sin precio') + '</span>',
    (p) => { agregarLinea(p); $('#prod-buscar').value = ''; });

  function pintarRapidos() {
    const el = $('#rapidos');
    if (!cfg.rapidos.length) { el.hidden = true; return; }
    el.innerHTML = '<span class="rap-tit">Más vendidos</span><div class="rap-pista">' + cfg.rapidos.map((p, i) => {
      const n = st.items.filter((x) => x.codigo === p.codigo && x.descripcion === p.descripcion).reduce((a, x) => a + (Number(x.cantidad) || 0), 0);
      return '<button type="button" class="rap' + (n ? ' on' : '') + '" data-rap="' + i + '">' + (n ? '<span class="rap-n">' + n + '</span>' : '+ ') + esc(p.descripcion) + '</button>';
    }).join('') + '</div>';
  }
  $('#rapidos').addEventListener('click', (e) => { const b = e.target.closest('[data-rap]'); if (b) agregarLinea(cfg.rapidos[Number(b.dataset.rap)]); });
  $('#linea-libre').addEventListener('click', () => agregarLinea({ codigo: '', descripcion: '', precio: 0, tarifa: cfg.tarifaDefecto }));

  function pintarLineas() {
    const el = $('#lineas');
    if (!st.items.length) {
      el.innerHTML = '<div class="vacio">Toca un producto de "Más vendidos" o búscalo arriba.</div>';
    } else {
      el.innerHTML = st.items.map((it, i) => '<div class="linea" data-fila="' + i + '">'
        + '<div class="l-desc"><input data-i="' + i + '" data-k="descripcion" value="' + esc(it.descripcion) + '" placeholder="Descripción" aria-label="Descripción"><small class="mono">' + esc(it.codigo || 'Línea libre') + '</small></div>'
        + '<div class="l-cant"><button type="button" data-menos="' + i + '" aria-label="Menos">−</button><input data-i="' + i + '" data-k="cantidad" type="number" min="0" step="any" inputmode="decimal" value="' + esc(it.cantidad) + '" aria-label="Cantidad"><button type="button" data-mas="' + i + '" aria-label="Más">+</button></div>'
        + '<label class="l-precio' + (Number(it.precioUnitario) > 0 ? '' : ' falta') + '"><span>$</span><input data-i="' + i + '" data-k="precioUnitario" type="number" min="0" step="0.01" inputmode="decimal" placeholder="0.00" value="' + esc(it.precioUnitario) + '" aria-label="Precio sin IVA"></label>'
        + '<select data-i="' + i + '" data-k="tarifa" class="l-iva" aria-label="IVA">' + ordenT.map((k) => '<option value="' + esc(k) + '"' + (k === it.tarifa ? ' selected' : '') + '>' + esc(/^iva/i.test(T[k].label) ? T[k].label : 'IVA ' + T[k].label) + '</option>').join('') + '</select>'
        + '<b class="l-sub mono" id="sub-' + i + '">' + dinero(subtotal(it)) + '</b>'
        + '<button type="button" class="l-quitar" data-quitar="' + i + '" aria-label="Quitar">×</button></div>').join('');
    }
    pintarRapidos();
    pintarResumen();
    guardarBorrador();
  }
  $('#lineas').addEventListener('input', (e) => {
    const el = e.target;
    if (el.dataset.i === undefined) return;
    const it = st.items[Number(el.dataset.i)];
    it[el.dataset.k] = el.value;
    const s = $('#sub-' + el.dataset.i);
    if (s) s.textContent = dinero(subtotal(it));
    if (el.dataset.k === 'precioUnitario') el.parentElement.classList.toggle('falta', !(Number(el.value) > 0));
    pintarResumen();
    guardarBorrador();
  });
  $('#lineas').addEventListener('change', (e) => { if (e.target.dataset.k === 'tarifa') pintarLineas(); });
  $('#lineas').addEventListener('click', (e) => {
    const t = e.target;
    if (t.dataset.mas !== undefined) { const it = st.items[Number(t.dataset.mas)]; it.cantidad = String((Number(it.cantidad) || 0) + 1); pintarLineas(); }
    if (t.dataset.menos !== undefined) { const i = Number(t.dataset.menos); const n = (Number(st.items[i].cantidad) || 0) - 1; if (n <= 0) st.items.splice(i, 1); else st.items[i].cantidad = String(n); pintarLineas(); }
    if (t.dataset.quitar !== undefined) { st.items.splice(Number(t.dataset.quitar), 1); pintarLineas(); }
  });

  // ----- Resumen y validación -----
  function error() {
    const c = leerCliente();
    const t = totales();
    if (!st.cf) {
      if (!c.identificacion) return 'Identifica al cliente o elige Consumidor final.';
      if (c.tipo === 'CEDULA' && !/^\d{10}$/.test(c.identificacion)) return 'La cédula debe tener 10 dígitos.';
      if (c.tipo === 'RUC' && !/^\d{13}$/.test(c.identificacion)) return 'El RUC debe tener 13 dígitos.';
      if (!c.nombre) return 'Falta el nombre del cliente.';
    }
    if (!st.items.length) return 'Agrega al menos un producto.';
    if (st.items.some((i) => !String(i.descripcion).trim())) return 'Cada línea necesita una descripción.';
    if (st.items.some((i) => !(Number(i.precioUnitario) > 0))) return 'Hay productos sin precio.';
    if (t.total <= 0) return 'El total debe ser mayor a cero.';
    if (st.cf && t.total > cfg.limiteCF) return 'Ventas mayores a $' + cfg.limiteCF + ' deben identificar al cliente.';
    return '';
  }
  function pintarResumen() {
    const t = totales();
    const err = error();
    const lineas = ordenT.filter((k) => t.por[k] !== undefined).map((k) => '<div class="kv"><span>Subtotal ' + esc(T[k].label) + '</span><span class="mono">' + dinero(t.por[k]) + '</span></div>').join('')
      + (t.desc > 0 ? '<div class="kv"><span>Descuento</span><span class="mono">' + dinero(t.desc) + '</span></div>' : '')
      + '<div class="kv"><span>IVA</span><span class="mono">' + dinero(t.iva) + '</span></div>';
    $('#resumen').innerHTML = '<h2>Resumen</h2>' + lineas + '<div class="total-grande"><span>Total</span><b class="mono">' + dinero(t.total) + '</b></div>'
      + (err && (st.items.length || leerCliente().identificacion) ? '<div class="aviso t-r pequeno">' + esc(err) + '</div>' : '')
      + '<button class="btn btn-primario btn-lg ancho-completo" type="submit"' + (err ? ' disabled' : '') + '>Emitir factura</button>'
      + '<p class="muted pequeno">Tu programa la firmará y la enviará al SRI. Verás el resultado en unos segundos si está abierto.</p>';
    $('#total-movil').textContent = dinero(t.total);
    $('#enviar-movil').disabled = !!err;
    const fp = $('#forma-pago');
    $('#pago-res').textContent = (fp.options[fp.selectedIndex] || { text: '' }).text.replace(/^\d+ · /, '') + ' · ' + (Number($('#dias-credito').value) > 0 ? 'Crédito ' + $('#dias-credito').value + ' días' : 'Contado');
  }
  ['#forma-pago', '#dias-credito', '#observacion'].forEach((s) => $(s).addEventListener('input', pintarResumen));
  $('#forma-pago').value = cfg.formaPago in { '01': 1, '16': 1, '19': 1, '20': 1 } ? cfg.formaPago : '20';

  form.addEventListener('submit', (e) => {
    const err = error();
    if (err) { e.preventDefault(); alert(err); return; }
    $('#pedido-json').value = JSON.stringify({ cliente: leerCliente(), items: st.items, formaPago: $('#forma-pago').value, diasCredito: $('#dias-credito').value, observacion: $('#observacion').value });
    $$('button[type=submit]', form).forEach((b) => { b.disabled = true; b.textContent = 'Enviando…'; });
    try { localStorage.removeItem(BORRADOR); } catch (er) { /* nada */ }
  });

  // Recupera un borrador si la página se cerró sin enviar.
  try {
    const b = JSON.parse(localStorage.getItem(BORRADOR) || 'null');
    if (b && b.st && b.st.items && b.st.items.length) {
      st.items = b.st.items;
      if (b.cli && b.cli.tipo === 'CF') $('[data-tipo-cli=CF]').click(); else if (b.cli) ponerCliente(b.cli);
    }
  } catch (e) { /* sin borrador */ }
  void csrf;
  pintarLineas();
})();
