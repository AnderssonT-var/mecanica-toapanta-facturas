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
  // El RIDE se dibuja siempre con el ancho de una hoja A4 y, en pantallas angostas (celular), se reduce entero para
  // que se vea completo sin desplazarse de lado (en iOS el marco se estiraba al ancho del contenido y movía la página).
  const ANCHO = 794;
  const caja = document.getElementById('ride-caja');
  let alto = 1100;
  function ajustar() {
    const disponible = Math.max(280, document.documentElement.clientWidth - (document.documentElement.clientWidth > 900 ? 0 : 16));
    const escala = Math.min(1, disponible / ANCHO);
    marco.style.width = ANCHO + 'px';
    marco.style.height = alto + 'px';
    marco.style.transform = escala < 1 ? 'scale(' + escala + ')' : '';
    caja.style.width = Math.round(ANCHO * escala) + 'px';
    caja.style.height = Math.round(alto * escala) + 'px';
  }
  marco.addEventListener('load', () => {
    try { alto = marco.contentDocument.documentElement.scrollHeight + 30; } catch (e) { /* sin acceso */ }
    ajustar();
  });
  window.addEventListener('resize', ajustar);
  ajustar();
  document.getElementById('imprimir').addEventListener('click', () => { marco.contentWindow.focus(); marco.contentWindow.print(); });
})();
