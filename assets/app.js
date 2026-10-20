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
