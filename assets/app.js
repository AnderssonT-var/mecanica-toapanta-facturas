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
