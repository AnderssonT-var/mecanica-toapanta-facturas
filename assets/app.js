'use strict';
document.addEventListener('click', (e) => {
  const copiar = e.target.closest('[data-copiar]');
  if (copiar) {
    const el = document.getElementById(copiar.dataset.copiar);
    if (el && navigator.clipboard) {
      navigator.clipboard.writeText(el.textContent.trim()).then(() => {
        const t = copiar.textContent;
        copiar.textContent = 'Copiada';
        setTimeout(() => { copiar.textContent = t; }, 1500);
      });
    }
    return;
  }
  const conf = e.target.closest('[data-confirmar]');
  if (conf && !window.confirm(conf.dataset.confirmar)) e.preventDefault();
});

// En celular las tablas se muestran como fichas: cada celda lleva el nombre de su columna.
document.querySelectorAll('table').forEach((t) => {
  const cab = [...t.querySelectorAll('thead th')].map((th) => th.textContent.trim());
  if (!cab.length) return;
  t.classList.add('resp');
  t.querySelectorAll('tbody tr').forEach((tr) => {
    [...tr.children].forEach((td, i) => {
      if (cab[i]) td.dataset.label = cab[i]; else td.classList.add('sin-label');
      if (i > 0 && /^[—-]?$/.test(td.textContent.trim()) && !td.querySelector('form, a, .btn')) td.classList.add('vacio');
    });
  });
});

// Formulario de licencia: el nombre del plan y el precio sugerido se actualizan al marcar funciones.
(() => {
  const caja = document.getElementById('funciones');
  if (!caja) return;
  const PRO = ['documentos', 'retenciones', 'inventario', 'compras', 'cartera', 'pos'];
  const ORDEN = ['documentos', 'retenciones', 'inventario', 'compras', 'cartera', 'pos', 'nube', 'contabilidad', 'movil'];
  const CORTOS = { documentos: 'Documentos', retenciones: 'Retenciones', inventario: 'Inventario', compras: 'Compras', cartera: 'Cobros', pos: 'Punto de venta', nube: 'Nube', contabilidad: 'Contabilidad', movil: 'Portal' };
  const COMPLETO = ['nube', 'movil'];
  const checks = () => [...caja.querySelectorAll('input[name="funciones[]"]:not([type=hidden])')];
  const marcadas = () => ORDEN.filter((k) => checks().some((c) => c.checked && c.value === k));
  const precios = JSON.parse((document.getElementById('precio-sugerido') || {}).dataset.precios || '{}');
  function nombre(f) {
    const pro = PRO.every((x) => f.includes(x));
    if (pro && f.includes('nube') && f.includes('movil')) { const ex = f.filter((x) => !PRO.includes(x) && !COMPLETO.includes(x)); return 'Completo' + (ex.length ? ' + ' + ex.map((x) => CORTOS[x]).join(' + ') : ''); }
    let extras = f.filter((x) => !(pro && PRO.includes(x)));
    return (pro ? 'Profesional' : 'Básico') + (extras.length ? ' + ' + extras.map((x) => CORTOS[x]).join(' + ') : '');
  }
  function precio(f, n) {
    if (n.startsWith('Completo')) { let c = precios.completo || 0; f.filter((x) => !PRO.includes(x) && !COMPLETO.includes(x)).forEach((x) => { c += (precios.extra || {})[x] || 0; }); return Math.round(c * 100) / 100; }
    const pro = n.startsWith('Profesional');
    let t = (pro ? precios.profesional : precios.basico) || 0;
    f.filter((x) => !(pro && PRO.includes(x))).forEach((x) => { t += (precios.extra || {})[x] || 0; });
    return Math.round(t * 100) / 100;
  }
  function pintar() {
    const f = marcadas();
    const n = nombre(f);
    document.getElementById('plan-nombre').textContent = n;
    const ps = document.getElementById('precio-sugerido');
    const p = precio(f, n);
    ps.innerHTML = p > 0 ? 'Precio sugerido: <b>$' + p.toFixed(2) + ' al mes</b> <button type="button" class="btn btn-sm" id="usar-precio">Usar</button>' : '<span class="muted small">Define los precios de referencia en Ajustes para ver un precio sugerido.</span>';
    const b = document.getElementById('usar-precio');
    if (b) b.onclick = () => { const i = document.querySelector('input[name=precio]'); const per = (document.querySelector('select[name=periodo]') || {}).value; const m = { TRIMESTRAL: 3, SEMESTRAL: 6, ANUAL: 12 }[per] || 1; i.value = (p * m).toFixed(2); };
  }
  caja.addEventListener('change', (e) => {
    const c = e.target;
    pintar();
  });
  caja.addEventListener('click', (e) => {
    const b = e.target.closest('[data-preset]');
    if (!b) return;
    const set = b.dataset.preset ? b.dataset.preset.split(',') : [];
    checks().forEach((c) => { c.checked = c.type === 'radio' && !c.value ? !set.includes('inventario') : set.includes(c.value); });
    pintar();
  });
  pintar();
})();

// Funciones por categoría (licencia): filtros, «todo / nada» y categorías deshabilitadas si su módulo no está marcado.
(() => {
  const caja = document.getElementById('detalles');
  if (!caja) return;
  const modulos = () => [...document.querySelectorAll('#funciones input[name="funciones[]"]:checked')].map((c) => c.value).filter(Boolean);
  let cat = '';
  function pintar() {
    const m = modulos();
    const q = (document.getElementById('det-buscar').value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    const soloApagadas = document.getElementById('det-apagadas').checked;
    caja.querySelectorAll('.det-cat').forEach((c) => {
      const activa = !c.dataset.modulo || m.includes(c.dataset.modulo);
      let visibles = 0;
      c.querySelectorAll('label.det').forEach((l) => {
        const tambien = (l.dataset.tambien || '').split(',').filter(Boolean);
        const on = activa || tambien.some((x) => m.includes(x));
        const i = l.querySelector('input');
        i.disabled = !on;
        l.classList.toggle('off', !on);
        const txt = l.textContent.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        const ver = (!q || txt.includes(q)) && (!soloApagadas || (on && !i.checked));
        l.hidden = !ver;
        if (ver) visibles++;
      });
      const total = c.querySelectorAll('label.det').length;
      const enc = [...c.querySelectorAll('label.det input')].filter((i) => !i.disabled && i.checked).length;
      c.querySelector('.det-estado').textContent = c.querySelector('label.det input:not(:disabled)') ? enc + ' de ' + total + ' encendidas' : 'categoría no incluida en el plan';
      c.hidden = (cat && c.dataset.cat !== cat) || !visibles;
    });
  }
  caja.addEventListener('click', (e) => {
    const chip = e.target.closest('[data-cat].chip');
    if (chip) { cat = chip.dataset.cat; caja.querySelectorAll('.chip').forEach((b) => b.classList.toggle('on', b === chip)); pintar(); return; }
    const todo = e.target.closest('[data-det-todo]');
    if (todo) { todo.closest('.det-cat').querySelectorAll('label.det input:not(:disabled)').forEach((i) => { i.checked = todo.dataset.detTodo === '1'; }); pintar(); }
  });
  caja.addEventListener('input', pintar);
  caja.addEventListener('change', pintar);
  const f = document.getElementById('funciones');
  if (f) { f.addEventListener('change', pintar); f.addEventListener('click', () => setTimeout(pintar, 0)); }
  pintar();
})();

// Monitor: la lista de equipos se recarga sola cada minuto mientras está a la vista (sin interrumpir si escribes un filtro).
(() => {
  const el = document.querySelector('[data-recargar]');
  if (!el) return;
  const seg = Math.max(30, Number(el.dataset.recargar) || 60);
  setInterval(() => {
    if (document.visibilityState === 'visible' && !document.activeElement.matches('input, select, textarea')) location.reload();
  }, seg * 1000);
})();

// Botones para elegir archivo en español y con el estilo del portal (el control del navegador sale en inglés).
(() => {
  document.querySelectorAll('input[type=file]:not([data-crudo])').forEach((i) => {
    const caja = document.createElement('span');
    caja.className = 'archivo';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-sm';
    btn.textContent = i.multiple ? 'Elegir archivos' : 'Elegir archivo';
    const nom = document.createElement('span');
    nom.className = 'archivo-nombre muted small';
    nom.textContent = 'Ningún archivo elegido';
    i.classList.add('archivo-oculto');
    i.after(caja);
    caja.append(btn, nom);
    btn.addEventListener('click', () => i.click());
    i.addEventListener('change', () => { nom.textContent = i.files && i.files.length ? [...i.files].map((f) => f.name).join(', ') : 'Ningún archivo elegido'; });
  });
})();
