'use strict';
(function () {
  const d = JSON.parse(document.getElementById('ride-datos').textContent);
  const marco = document.getElementById('ride');
  let html;
  try {
    html = window.FXRIDE.ride(d.c, d.empresa, { logo: d.logo, programa: d.programa });
  } catch (e) {
    html = '<p style="font:15px sans-serif;padding:30px">Este tipo de comprobante todavía no tiene RIDE en la web. Ábrelo desde el programa.</p>';
  }
  marco.srcdoc = html;
  marco.addEventListener('load', () => {
    try { marco.style.height = (marco.contentDocument.documentElement.scrollHeight + 30) + 'px'; } catch (e) { /* sin acceso */ }
  });
  document.getElementById('imprimir').addEventListener('click', () => { marco.contentWindow.focus(); marco.contentWindow.print(); });
})();
