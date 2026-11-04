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
  const PRO = ['documentos', 'retenciones', 'inventario'];
  const CORTOS = { documentos: 'Documentos', retenciones: 'Retenciones', inventario: 'Inventario', nube: 'Nube', movil: 'Móvil' };
  const checks = () => [...caja.querySelectorAll('input[name="funciones[]"]:not([type=hidden])')];
  const marcadas = () => checks().filter((c) => c.checked && c.value).map((c) => c.value);
  const precios = JSON.parse((document.getElementById('precio-sugerido') || {}).dataset.precios || '{}');
  function nombre(f) {
    const pro = PRO.every((x) => f.includes(x));
    if (pro && f.includes('nube') && f.includes('movil')) return 'Completo';
    let extras = f.filter((x) => !(pro && PRO.includes(x)));
    if (extras.includes('movil')) extras = extras.filter((x) => x !== 'nube');
    return (pro ? 'Profesional' : 'Básico') + (extras.length ? ' + ' + extras.map((x) => CORTOS[x]).join(' + ') : '');
  }
  function precio(f, n) {
    if (n === 'Completo') return precios.completo || 0;
    const pro = n.startsWith('Profesional');
    let t = (pro ? precios.profesional : precios.basico) || 0;
    f.filter((x) => !(pro && PRO.includes(x))).forEach((x) => { if (!(x === 'nube' && f.includes('movil'))) t += (precios.extra || {})[x] || 0; });
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
    if (c.value === 'movil' && c.checked) checks().find((x) => x.value === 'nube').checked = true; // el celular trabaja sobre la nube
    if (c.value === 'nube' && !c.checked) checks().find((x) => x.value === 'movil').checked = false;
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
