// Generado por desktop/tools/ride-web.js desde desktop/src/ride.js. No editar a mano.
(function () {
'use strict';
const module = { exports: {} };
const require = () => ({});
// RIDE (Representación Impresa del Documento Electrónico) de una factura, en HTML listo para imprimir a PDF.
// Contenido según la ficha técnica de comprobantes electrónicos del SRI (esquema offline).
const sri = require('./sri');

// ---------- Código de barras Code 128 (clave de acceso de 49 dígitos) ----------
const C128 = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213',
  '221312', '231212', '112232', '122132', '122231', '113222', '123122', '123221', '223211', '221132',
  '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112', '322211',
  '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313',
  '231113', '231311', '112133', '112331', '132131', '113123', '113321', '133121', '313121', '211331',
  '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311', '332111',
  '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214',
  '112412', '122114', '122411', '142112', '142211', '241211', '221114', '413111', '241112', '134111',
  '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211', '212141',
  '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141',
  '114131', '311141', '411131', '211412', '211214', '211232', '2331112'];

// Códigos Code 128 para una cadena de dígitos: set C por pares y, si sobra uno, cambio a set B.
function codigosCode128(digitos) {
  if (!/^\d+$/.test(digitos)) throw new Error('El código de barras solo admite dígitos.');
  const codes = [105];
  let i = 0;
  for (; i + 1 < digitos.length; i += 2) codes.push(Number(digitos.slice(i, i + 2)));
  if (i < digitos.length) { codes.push(100); codes.push(digitos.charCodeAt(i) - 32); }
  let suma = codes[0];
  for (let k = 1; k < codes.length; k++) suma += codes[k] * k;
  codes.push(suma % 103);
  codes.push(106);
  return codes;
}

function barrasSvg(digitos, { alto = 46, modulo = 1 } = {}) {
  const codes = codigosCode128(digitos);
  let x = 10 * modulo;
  let rects = '';
  for (const c of codes) {
    const p = C128[c];
    for (let k = 0; k < p.length; k++) {
      const w = Number(p[k]) * modulo;
      if (k % 2 === 0) rects += '<rect x="' + x + '" y="0" width="' + w + '" height="' + alto + '"/>';
      x += w;
    }
  }
  const ancho = x + 10 * modulo;
  return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' + ancho + ' ' + alto + '" preserveAspectRatio="none" style="width:100%;height:' + alto + 'px" role="img" aria-label="Código de barras de la clave de acceso"><g fill="#000">' + rects + '</g></svg>';
}

// ---------- RIDE ----------
const esc = (s) => String(s == null ? '' : s).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const num = (n, d = 2) => Number(n || 0).toFixed(d);
const fechaEc = (iso) => (iso ? iso.slice(0, 10).split('-').reverse().join('/') : '');

function fechaHoraAutorizacion(s) {
  if (!s) return '';
  const d = new Date(s);
  if (Number.isNaN(d.getTime())) return s;
  const p = (n) => String(n).padStart(2, '0');
  // Hora de Ecuador continental (UTC-5), como la muestra el SRI.
  const ec = new Date(d.getTime() - 5 * 3600000);
  return p(ec.getUTCDate()) + '/' + p(ec.getUTCMonth() + 1) + '/' + ec.getUTCFullYear() + ' ' + p(ec.getUTCHours()) + ':' + p(ec.getUTCMinutes()) + ':' + p(ec.getUTCSeconds());
}

const LEYENDA_RIMPE = {
  'RIMPE EMPRENDEDOR': 'CONTRIBUYENTE RÉGIMEN RIMPE',
  'RIMPE NEGOCIO POPULAR': 'CONTRIBUYENTE NEGOCIO POPULAR - RÉGIMEN RIMPE'
};


const NOMBRES = { '01': 'FACTURA', '04': 'NOTA DE CRÉDITO', '05': 'NOTA DE DÉBITO', '06': 'GUÍA DE REMISIÓN', '07': 'COMPROBANTE DE RETENCIÓN' };
const FORMAS = { '01': 'SIN UTILIZACIÓN DEL SISTEMA FINANCIERO', '16': 'TARJETA DE DÉBITO', '19': 'TARJETA DE CRÉDITO', '20': 'OTROS CON UTILIZACIÓN DEL SISTEMA FINANCIERO' };

const ESTILOS = '@page{size:A4;margin:11mm}*{box-sizing:border-box}body{margin:0;font:9.2pt Arial,Helvetica,"Liberation Sans",sans-serif;color:#000}'
  + '.top{display:grid;grid-template-columns:1fr 1fr;gap:10px}.box{border:1px solid #000;border-radius:8px;padding:8px 10px}'
  + '.logo{height:105px;display:flex;align-items:center;justify-content:center;margin-bottom:8px}.logo img{max-height:105px;max-width:100%;object-fit:contain}'
  + '.logo .sin{font:bold 15pt Arial;color:#888;border:1px dashed #aaa;border-radius:8px;padding:28px 20px}'
  + '.em b{font-size:10.5pt}.em div{margin-top:3px}.k{font-weight:bold}'
  + '.der{display:flex;flex-direction:column;gap:4px}.der .ruc{font-size:12pt;font-weight:bold}.der .tipo{font-size:13.5pt;font-weight:bold;letter-spacing:.5px}'
  + '.mono{font-family:"Courier New",monospace}.clave{font-size:7.4pt;letter-spacing:-.2px;word-break:break-all;text-align:center;font-family:"Courier New",monospace}'
  + '.cli{margin-top:9px;display:grid;grid-template-columns:1.6fr 1fr;gap:3px 16px}.full{grid-column:1/-1}'
  + 'table{border-collapse:collapse;width:100%}.det{margin-top:9px}.det th,.det td{border:1px solid #000;padding:3px 5px;vertical-align:top}'
  + '.det th{background:#eee;font-size:8.2pt}.n{text-align:right;white-space:nowrap}.c{text-align:center}'
  + '.bot{margin-top:9px;display:grid;grid-template-columns:1.25fr 1fr;gap:12px;align-items:start}'
  + '.adi th,.adi td{padding:2px 6px;text-align:left;vertical-align:top}.adi th{width:34%}'
  + '.tot td{border:1px solid #000;padding:3px 6px}.tot tr:last-child td{font-weight:bold;font-size:10.2pt}'
  + '.pag{margin-top:9px}.pag th,.pag td{border:1px solid #000;padding:3px 6px}.pag th{background:#eee}'
  + '.dest{margin-top:9px;border:1px solid #000;border-radius:8px;padding:8px 10px}.dest .cli{margin-top:0}'
  + '.pie{margin-top:12px;font-size:7.3pt;color:#444;display:flex;justify-content:space-between;border-top:1px solid #999;padding-top:4px}';

const RECHAZADOS = ['INVALIDO', 'DEVUELTA', 'NO AUTORIZADO'];

// Estado impreso en el RIDE (en lugar de marcas de agua), como en el portal del SRI.
function textoEstado(c, autorizado, rechazado) {
  if (c.anulado || c.estado === 'ANULADO') return 'ANULADO';
  if (autorizado) return 'AUTORIZADO';
  if (rechazado) return 'NO AUTORIZADO';
  return 'PENDIENTE DE AUTORIZACIÓN';
}

function encabezado(c, empresa, { logo, titulo, numero, sinClave }) {
  const autorizado = c && c.estado === 'AUTORIZADO';
  const rechazado = c && RECHAZADOS.includes(c.estado);
  const ambiente = c && c.clave_acceso ? (c.clave_acceso[23] === '2' ? 'PRODUCCIÓN' : 'PRUEBAS') : (empresa.ambiente === '2' ? 'PRODUCCIÓN' : 'PRUEBAS');
  const tel = [empresa.telefono1, empresa.telefono2].filter(Boolean).join(' / ');
  const leyendas = [
    empresa.contribuyenteEspecial ? 'Contribuyente Especial Nro: ' + esc(empresa.contribuyenteEspecial) : '',
    'OBLIGADO A LLEVAR CONTABILIDAD: ' + (empresa.obligadoContabilidad === 'SI' ? 'SI' : 'NO'),
    empresa.agenteRetencion ? 'Agente de Retención Resolución No. ' + esc(empresa.agenteRetencion) : '',
    LEYENDA_RIMPE[empresa.regimen] ? '<b>' + esc(LEYENDA_RIMPE[empresa.regimen]) + '</b>' : '<b>RÉGIMEN GENERAL</b>'
  ].filter(Boolean).map((x) => '<div>' + x + '</div>').join('');
  let der = '<div class="box der"><div class="ruc">R.U.C.: ' + esc(empresa.ruc) + '</div><div class="tipo">' + esc(titulo) + '</div>'
    + '<div><span class="k">No.</span> <span class="mono">' + esc(numero) + '</span></div>';
  if (!sinClave) {
    // Esquema offline del SRI: el número de autorización es la misma clave de acceso; mientras llega la respuesta se imprime la clave.
    const numAut = autorizado ? c.numero_autorizacion : rechazado ? 'NO AUTORIZADO POR EL SRI' : c.clave_acceso;
    const fecAut = autorizado ? fechaHoraAutorizacion(c.fecha_autorizacion) : rechazado ? '—' : 'PENDIENTE DE AUTORIZACIÓN';
    der += '<div class="k">NÚMERO DE AUTORIZACIÓN</div><div class="clave">' + esc(numAut) + '</div>'
      + '<div><span class="k">FECHA Y HORA DE AUTORIZACIÓN:</span> ' + esc(fecAut) + '</div>'
      + '<div><span class="k">ESTADO:</span> <b class="estado">' + esc(textoEstado(c, autorizado, rechazado)) + '</b></div>'
      + '<div><span class="k">AMBIENTE:</span> ' + ambiente + '</div><div><span class="k">EMISIÓN:</span> NORMAL</div>'
      + '<div class="k">CLAVE DE ACCESO</div>' + barrasSvg(c.clave_acceso) + '<div class="clave">' + esc(c.clave_acceso) + '</div>';
  }
  der += '</div>';
  return '<div class="top"><div><div class="logo">' + (logo ? '<img src="' + logo + '" alt="">' : '<span class="sin">NO TIENE LOGO</span>') + '</div>'
    + '<div class="box em"><b>' + esc(empresa.razonSocial) + '</b>' + (empresa.nombreComercial ? '<div>' + esc(empresa.nombreComercial) + '</div>' : '')
    + '<div><span class="k">Dir. Matriz:</span> ' + esc(empresa.dirMatriz) + '</div>'
    + (empresa.dirEstablecimiento ? '<div><span class="k">Dir. Sucursal:</span> ' + esc(empresa.dirEstablecimiento) + '</div>' : '')
    + (tel ? '<div><span class="k">Teléfono(s):</span> ' + esc(tel) + '</div>' : '')
    + (empresa.email ? '<div><span class="k">Email:</span> ' + esc(empresa.email) + '</div>' : '')
    + leyendas + '</div></div>' + der + '</div>';
}

function documento(titulo, cuerpo, { programa = 'Facturador' } = {}) {
  const ahora = new Date();
  const p = (n) => String(n).padStart(2, '0');
  const impreso = p(ahora.getDate()) + '/' + p(ahora.getMonth() + 1) + '/' + ahora.getFullYear() + ' ' + p(ahora.getHours()) + ':' + p(ahora.getMinutes());
  return '<!doctype html><html lang="es"><head><meta charset="utf-8"><title>' + esc(titulo) + '</title><style>' + ESTILOS + '</style></head><body>'
    + cuerpo + '<div class="pie"><span>' + esc(programa) + ' · Documento generado electrónicamente. Verifique su validez en www.sri.gob.ec</span><span>Impreso: ' + impreso + '</span></div></body></html>';
}

function infoAdicionalHtml(campos) {
  const filas = (campos || []).filter((x) => String(x.valor || '').trim()).map((x) => '<tr><th>' + esc(x.nombre) + '</th><td>' + esc(x.valor) + '</td></tr>').join('');
  return '<div class="box"><div class="k" style="margin-bottom:4px">Información Adicional</div><table class="adi">' + filas + '</table></div>';
}

function totalesHtml(t, extra = []) {
  const pt = t.porTarifa || {};
  const ivaDe = (cod) => (t.impuestos.find((i) => i.codigoPorcentaje === cod) || {}).valor || 0;
  const fila = (k, v) => '<tr><td>' + k + '</td><td class="n">' + num(v) + '</td></tr>';
  const gravadas = t.impuestos.filter((i) => Number(i.tarifa) > 0);
  const pcts = [...new Set(gravadas.map((i) => Number(i.tarifa)))];
  if (!pcts.includes(15)) pcts.unshift(15);
  if (!pcts.includes(5)) pcts.push(5);
  const baseDe = (pct) => gravadas.filter((i) => Number(i.tarifa) === pct).reduce((a, i) => a + i.base, 0);
  const ivaPct = (pct) => gravadas.filter((i) => Number(i.tarifa) === pct).reduce((a, i) => a + i.valor, 0);
  return '<table class="tot"><tbody>'
    + pcts.map((p) => fila('SUBTOTAL ' + p + '%', baseDe(p))).join('')
    + fila('SUBTOTAL 0%', pt['0']) + fila('SUBTOTAL NO OBJETO DE IVA', pt['6']) + fila('SUBTOTAL EXENTO DE IVA', pt['7'])
    + fila('SUBTOTAL SIN IMPUESTOS', t.totalSinImpuestos) + fila('TOTAL DESCUENTO', t.totalDescuento) + fila('ICE', 0)
    + pcts.map((p) => fila('IVA ' + p + '%', ivaPct(p))).join('') + extra.map(([k, v]) => fila(k, v)).join('')
    + fila('VALOR TOTAL', t.importeTotal) + '</tbody></table>';
  void ivaDe;
}

function detalleHtml(lineas, empresa) {
  const dp = Number(empresa.decimalesPrecio) || 2;
  const dc = Number(empresa.decimalesCantidad) || 2;
  return '<table class="det"><thead><tr><th style="width:11%">Cod. Principal</th><th style="width:9%">Cod. Auxiliar</th><th style="width:8%">Cant.</th><th>Descripción</th><th style="width:11%">Precio Unitario</th><th style="width:9%">Descuento</th><th style="width:11%">Precio Total</th></tr></thead><tbody>'
    + lineas.map((l) => '<tr><td class="mono">' + esc(l.codigo || '') + '</td><td class="mono">' + esc(l.codigoAux || '') + '</td><td class="n">' + num(l.cantidad, dc) + '</td><td>' + esc(l.descripcion) + '</td>'
      + '<td class="n">' + num(l.precioUnitario, dp) + '</td><td class="n">' + num(l.descuento) + '</td><td class="n">' + num(l.base) + '</td></tr>').join('')
    + '</tbody></table>';
}

function compradorHtml(c, extra = '') {
  const cli = c.data.cliente || {};
  return '<div class="box cli"><div><span class="k">Razón Social / Nombres y Apellidos:</span> ' + esc(c.cliente_nombre) + '</div>'
    + '<div><span class="k">Identificación:</span> <span class="mono">' + esc(c.cliente_identificacion) + '</span></div>'
    + '<div><span class="k">Fecha Emisión:</span> ' + fechaEc(c.fecha) + '</div>'
    + '<div><span class="k">Teléfono:</span> ' + esc(cli.telefono || '') + '</div>'
    + (cli.direccion ? '<div class="full"><span class="k">Dirección:</span> ' + esc(cli.direccion) + '</div>' : '') + extra + '</div>';
}

function pagosHtml(formaPago, total, plazo) {
  return '<table class="pag"><thead><tr><th>Forma de pago</th><th style="width:20%">Valor</th><th style="width:14%">Plazo</th><th style="width:14%">Tiempo</th></tr></thead><tbody><tr><td>'
    + esc(formaPago) + ' - ' + esc(FORMAS[formaPago] || '') + '</td><td class="n">' + num(total) + '</td><td class="n">' + (Number(plazo) || 0) + '</td><td>' + (Number(plazo) ? 'DÍAS' : '') + '</td></tr></tbody></table>';
}

function rideFactura(c, empresa, { logo = '', programa } = {}) {
  const d = c.data;
  const cuerpo = encabezado(c, empresa, { logo, titulo: 'FACTURA', numero: c.numero })
    + compradorHtml(c, '<div><span class="k">Guía de Remisión:</span> ' + esc(d.guiaRemision || '') + '</div>')
    + detalleHtml(d.totales.lineas, empresa)
    + '<div class="bot"><div>' + infoAdicionalHtml(d.camposAdicionales) + pagosHtml(d.formaPago, d.totales.importeTotal, d.diasCredito) + '</div>'
    + totalesHtml(d.totales, [['PROPINA', 0]]) + '</div>';
  return documento('RIDE ' + c.numero, cuerpo, { programa });
}

function rideNotaCredito(c, empresa, { logo = '', programa } = {}) {
  const d = c.data;
  const extra = '<div class="full" style="border-top:1px solid #000;margin-top:4px;padding-top:4px"><span class="k">Comprobante que se modifica:</span> FACTURA ' + esc(d.facturaNumero)
    + ' &nbsp; <span class="k">Fecha emisión (comprobante a modificar):</span> ' + fechaEc(d.facturaFecha) + '</div>'
    + '<div class="full"><span class="k">Razón de modificación:</span> ' + esc(d.motivo) + '</div>';
  const cuerpo = encabezado(c, empresa, { logo, titulo: 'NOTA DE CRÉDITO', numero: c.numero }) + compradorHtml(c, extra)
    + detalleHtml(d.totales.lineas, empresa)
    + '<div class="bot"><div>' + infoAdicionalHtml(d.camposAdicionales) + '</div>' + totalesHtml(d.totales) + '</div>';
  return documento('RIDE ' + c.numero, cuerpo, { programa });
}

function rideNotaDebito(c, empresa, { logo = '', programa } = {}) {
  const d = c.data;
  const extra = '<div class="full" style="border-top:1px solid #000;margin-top:4px;padding-top:4px"><span class="k">Comprobante que se modifica:</span> FACTURA ' + esc(d.facturaNumero)
    + ' &nbsp; <span class="k">Fecha emisión (comprobante a modificar):</span> ' + fechaEc(d.facturaFecha) + '</div>';
  const motivos = '<table class="det"><thead><tr><th>Razón de la modificación</th><th style="width:20%">Valor de la modificación</th></tr></thead><tbody>'
    + d.motivos.map((m) => '<tr><td>' + esc(m.razon) + '</td><td class="n">' + num(m.base) + '</td></tr>').join('') + '</tbody></table>';
  const cuerpo = encabezado(c, empresa, { logo, titulo: 'NOTA DE DÉBITO', numero: c.numero }) + compradorHtml(c, extra) + motivos
    + '<div class="bot"><div>' + infoAdicionalHtml(d.camposAdicionales) + pagosHtml(d.formaPago, d.totales.importeTotal, d.diasCredito) + '</div>' + totalesHtml(d.totales) + '</div>';
  return documento('RIDE ' + c.numero, cuerpo, { programa });
}

function rideGuia(c, empresa, { logo = '', programa } = {}) {
  const g = c.data.guia;
  const t = g.transportista;
  const dc = Number(empresa.decimalesCantidad) || 2;
  const trans = '<div class="box cli"><div><span class="k">Identificación (Transportista):</span> <span class="mono">' + esc(t.identificacion) + '</span></div>'
    + '<div><span class="k">Placa:</span> ' + esc(t.placa) + '</div>'
    + '<div class="full"><span class="k">Razón Social / Nombres y Apellidos:</span> ' + esc(t.nombre) + '</div>'
    + '<div class="full"><span class="k">Punto de Partida:</span> ' + esc(g.dirPartida) + '</div>'
    + '<div><span class="k">Fecha inicio Transporte:</span> ' + fechaEc(g.fechaIni) + '</div><div><span class="k">Fecha fin Transporte:</span> ' + fechaEc(g.fechaFin) + '</div></div>';
  const dest = g.destinatarios.map((d) => '<div class="dest"><div class="cli">'
    + (d.sustento ? '<div><span class="k">Comprobante de Venta:</span> FACTURA ' + esc(d.sustento.numero) + '</div><div><span class="k">Fecha de Emisión:</span> ' + esc(d.sustento.fecha) + '</div>'
      + '<div class="full"><span class="k">Número de Autorización:</span> <span class="mono">' + esc(d.sustento.autorizacion || '') + '</span></div>' : '')
    + '<div><span class="k">Motivo Traslado:</span> ' + esc(d.motivo) + '</div><div><span class="k">Ruta:</span> ' + esc(d.ruta || '') + '</div>'
    + '<div class="full"><span class="k">Destino (Punto de llegada):</span> ' + esc(d.direccion) + '</div>'
    + '<div><span class="k">Identificación (Destinatario):</span> <span class="mono">' + esc(d.identificacion) + '</span></div><div><span class="k">Cód. Establecimiento Destino:</span> ' + esc(d.codEstabDestino || '') + '</div>'
    + '<div class="full"><span class="k">Razón Social / Nombres y Apellidos:</span> ' + esc(d.nombre) + '</div></div>'
    + '<table class="det"><thead><tr><th style="width:12%">Cantidad</th><th>Descripción</th><th style="width:16%">Código Principal</th><th style="width:16%">Código Auxiliar</th></tr></thead><tbody>'
    + d.items.map((i) => '<tr><td class="n">' + num(i.cantidad, dc) + '</td><td>' + esc(i.descripcion) + '</td><td class="mono">' + esc(i.codigo) + '</td><td class="mono">' + esc(i.codigoAux || '') + '</td></tr>').join('')
    + '</tbody></table></div>').join('');
  const cuerpo = encabezado(c, empresa, { logo, titulo: 'GUÍA DE REMISIÓN', numero: c.numero }) + trans + dest
    + '<div class="bot"><div>' + infoAdicionalHtml(c.data.camposAdicionales) + '</div><div></div></div>';
  return documento('RIDE ' + c.numero, cuerpo, { programa });
}

function rideRetencion(c, empresa, { logo = '', programa } = {}) {
  const d = c.data;
  const s = d.sustento;
  const suj = '<div class="box cli"><div><span class="k">Razón Social / Nombres y Apellidos:</span> ' + esc(c.cliente_nombre) + '</div>'
    + '<div><span class="k">Identificación:</span> <span class="mono">' + esc(c.cliente_identificacion) + '</span></div>'
    + '<div><span class="k">Fecha Emisión:</span> ' + fechaEc(c.fecha) + '</div><div><span class="k">Periodo fiscal:</span> ' + esc(d.periodoFiscal) + '</div></div>';
  const IMP = { '1': 'RENTA', '2': 'IVA', '6': 'ISD' };
  const tabla = '<table class="det"><thead><tr><th>Comprobante</th><th>Número</th><th>Fecha Emisión</th><th>Ejercicio Fiscal</th><th>Base Imponible para la Retención</th><th>Impuesto</th><th>Código</th><th>Porcentaje Retención</th><th>Valor Retenido</th></tr></thead><tbody>'
    + d.retenciones.map((r) => '<tr><td>FACTURA</td><td class="mono">' + esc(s.numero) + '</td><td>' + esc(s.fecha) + '</td><td>' + esc(d.periodoFiscal) + '</td><td class="n">' + num(r.base)
      + '</td><td>' + (IMP[r.impuesto] || r.impuesto) + '</td><td class="c">' + esc(r.codigo) + '</td><td class="n">' + num(r.porcentaje) + '</td><td class="n">' + num(r.valor) + '</td></tr>').join('')
    + '</tbody></table>';
  const tot = '<table class="tot"><tbody><tr><td>TOTAL RETENIDO</td><td class="n">' + num(d.totalRetenido) + '</td></tr></tbody></table>';
  const cuerpo = encabezado(c, empresa, { logo, titulo: 'COMPROBANTE DE RETENCIÓN', numero: c.numero }) + suj + tabla
    + '<div class="bot"><div>' + infoAdicionalHtml(d.camposAdicionales) + '</div>' + tot + '</div>';
  return documento('RIDE ' + c.numero, cuerpo, { programa });
}

function rideProforma(p, empresa, { logo = '', programa } = {}) {
  const cli = p.cliente || {};
  const fake = { data: { cliente: cli }, cliente_nombre: cli.nombre, cliente_identificacion: cli.identificacion, fecha: p.fecha };
  const vence = new Date(p.fecha + 'T12:00:00');
  vence.setDate(vence.getDate() + (Number(p.validez) || 15));
  const extra = '<div><span class="k">Válida hasta:</span> ' + fechaEc(vence.toISOString()) + '</div>' + (p.observacion ? '<div class="full"><span class="k">Observación:</span> ' + esc(p.observacion) + '</div>' : '');
  const cuerpo = encabezado(null, empresa, { logo, titulo: 'PROFORMA', numero: 'PR-' + String(p.numero).padStart(6, '0'), sinClave: true })
    + compradorHtml(fake, extra) + detalleHtml(p.totales.lineas, empresa)
    + '<div class="bot"><div class="box">Documento sin validez tributaria. Precios sujetos a cambio después de la fecha de validez.</div>' + totalesHtml(p.totales) + '</div>';
  return documento('Proforma ' + p.numero, cuerpo, { programa });
}

function ride(c, empresa, opciones) {
  const fn = { '01': rideFactura, '04': rideNotaCredito, '05': rideNotaDebito, '06': rideGuia, '07': rideRetencion }[c.cod_doc];
  if (!fn) throw new Error('Tipo de comprobante sin RIDE.');
  return fn(c, empresa, opciones);
}

module.exports = { ride, rideFactura, rideNotaCredito, rideNotaDebito, rideGuia, rideRetencion, rideProforma, codigosCode128, barrasSvg, C128, NOMBRES };

window.FXRIDE = module.exports;
})();
