"""
RIDE (representación impresa) de una factura electrónica autorizada por el SRI.

Se arma a partir del XML autorizado que FactuSYS guarda en
PERFILxx\\Documentos\\Autorizados\\<clave de acceso>.xml, así que el PDF tiene
exactamente los datos que el SRI autorizó.
"""

from __future__ import annotations

import xml.etree.ElementTree as ET
from pathlib import Path

from reportlab.graphics.barcode import code128
from reportlab.lib import colors
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas

FORMAS_PAGO = {
    "01": "SIN UTILIZACIÓN DEL SISTEMA FINANCIERO", "15": "COMPENSACIÓN DE DEUDAS",
    "16": "TARJETA DE DÉBITO", "17": "DINERO ELECTRÓNICO", "18": "TARJETA PREPAGO",
    "19": "TARJETA DE CRÉDITO", "20": "OTROS CON UTILIZACIÓN DEL SISTEMA FINANCIERO",
    "21": "ENDOSO DE TÍTULOS",
}
IVA_CODIGOS = {"0": "0%", "2": "12%", "3": "14%", "4": "15%", "5": "5%", "6": "No objeto", "7": "Exento", "8": "IVA diferenciado", "10": "13%"}


def _t(nodo, ruta, defecto=""):
    if nodo is None:
        return defecto
    e = nodo.find(ruta)
    return (e.text or "").strip() if e is not None and e.text else defecto


def leer_autorizado(ruta_xml) -> dict:
    """Extrae los datos del XML autorizado (formato <autorizacion> de FactuSYS / SRI)."""
    raiz = ET.fromstring(Path(ruta_xml).read_bytes())
    if raiz.tag.endswith("RespuestaAutorizacionComprobante") or raiz.find(".//autorizacion") is not None and raiz.tag != "autorizacion":
        raiz = raiz.find(".//autorizacion")
    if raiz is not None and raiz.tag == "factura":
        # XML firmado que todavía no autoriza el SRI (Documentos\Firmados o Generados)
        comp, estado, raiz = raiz, "PENDIENTE", None
    elif raiz is None or raiz.tag != "autorizacion":
        raise ValueError("El XML no es un comprobante de FactuSYS")
    else:
        estado = _t(raiz, "estado")
        comp = ET.fromstring(raiz.find("comprobante").text.strip().encode("utf-8"))
    it, inf = comp.find("infoTributaria"), comp.find("infoFactura")
    detalles = []
    for d in comp.findall("detalles/detalle"):
        detalles.append({
            "codigo": _t(d, "codigoPrincipal"), "descripcion": _t(d, "descripcion"),
            "cantidad": float(_t(d, "cantidad", "0")), "precio": float(_t(d, "precioUnitario", "0")),
            "descuento": float(_t(d, "descuento", "0")), "total": float(_t(d, "precioTotalSinImpuesto", "0")),
        })
    impuestos = []
    for ti in inf.findall("totalConImpuestos/totalImpuesto"):
        impuestos.append({"codigo": _t(ti, "codigo"), "porcentaje": _t(ti, "codigoPorcentaje"),
                          "base": float(_t(ti, "baseImponible", "0")), "valor": float(_t(ti, "valor", "0"))})
    pagos = [{"forma": _t(p, "formaPago"), "total": float(_t(p, "total", "0"))} for p in inf.findall("pagos/pago")]
    adicional = [(c.get("nombre", ""), (c.text or "").strip()) for c in comp.findall("infoAdicional/campoAdicional")]
    return {
        "estado": estado,
        # el número de autorización es la misma clave de acceso
        "numero_autorizacion": _t(raiz, "numeroAutorizacion") if raiz is not None else _t(it, "claveAcceso"),
        "fecha_autorizacion": _t(raiz, "fechaAutorizacion") if raiz is not None else "PENDIENTE",
        "ambiente": (_t(raiz, "ambiente") if raiz is not None else "") or ("PRODUCCIÓN" if _t(it, "ambiente") == "2" else "PRUEBAS"),
        "razon_social": _t(it, "razonSocial"), "nombre_comercial": _t(it, "nombreComercial"),
        "ruc": _t(it, "ruc"), "clave": _t(it, "claveAcceso"),
        "numero": f"{_t(it, 'estab')}-{_t(it, 'ptoEmi')}-{_t(it, 'secuencial')}",
        "secuencial": _t(it, "secuencial"),
        "dir_matriz": _t(it, "dirMatriz"), "dir_establecimiento": _t(inf, "dirEstablecimiento"),
        "contabilidad": _t(inf, "obligadoContabilidad"), "contribuyente_rimpe": _t(it, "contribuyenteRimpe"),
        "fecha_emision": _t(inf, "fechaEmision"),
        "cliente": _t(inf, "razonSocialComprador"), "id_cliente": _t(inf, "identificacionComprador"),
        "dir_cliente": _t(inf, "direccionComprador"),
        "subtotal": float(_t(inf, "totalSinImpuestos", "0")), "descuento": float(_t(inf, "totalDescuento", "0")),
        "propina": float(_t(inf, "propina", "0")), "total": float(_t(inf, "importeTotal", "0")),
        "detalles": detalles, "impuestos": impuestos, "pagos": pagos, "adicional": adicional,
    }


def _partir(c, texto, ancho, fuente, tam):
    """Divide un texto en líneas que caben en `ancho`."""
    palabras, lineas, actual = str(texto).split(), [], ""
    for p in palabras:
        prueba = (actual + " " + p).strip()
        if c.stringWidth(prueba, fuente, tam) <= ancho:
            actual = prueba
        else:
            if actual:
                lineas.append(actual)
            while c.stringWidth(p, fuente, tam) > ancho and len(p) > 1:  # palabra muy larga
                corte = len(p)
                while corte > 1 and c.stringWidth(p[:corte], fuente, tam) > ancho:
                    corte -= 1
                lineas.append(p[:corte])
                p = p[corte:]
            actual = p
    if actual:
        lineas.append(actual)
    return lineas or [""]


def _m(v: float) -> str:
    return f"{v:.2f}"


def generar_ride(ruta_xml, ruta_pdf, logo=None, permitir_pendiente=False) -> Path:
    d = leer_autorizado(ruta_xml)
    if d["estado"].upper() != "AUTORIZADO" and not (permitir_pendiente and d["estado"] == "PENDIENTE"):
        raise ValueError(f"El comprobante no está autorizado ({d['estado']})")
    ruta_pdf = Path(ruta_pdf)
    ruta_pdf.parent.mkdir(parents=True, exist_ok=True)
    tmp = ruta_pdf.with_suffix(".tmp")
    c = canvas.Canvas(str(tmp), pagesize=A4)
    c.setTitle(f"Factura {d['numero']}")
    c.setAuthor(d["razon_social"])
    W, H = A4
    M = 12 * mm
    gris = colors.HexColor("#555555")
    c.setLineWidth(0.6)

    # ---------------- cabecera: emisor (izq.) y datos tributarios (der.)
    top = H - M
    col = (W - 2 * M - 6 * mm) / 2
    der_x = M + col + 6 * mm
    alto_cab = 76 * mm

    # logo
    y_emisor = top - 2 * mm
    if logo and Path(logo).is_file():
        try:
            img = ImageReader(str(logo))
            iw, ih = img.getSize()
            esc = min(col / iw, 34 * mm / ih)
            c.drawImage(img, M + (col - iw * esc) / 2, top - ih * esc, iw * esc, ih * esc, mask="auto")
            y_emisor = top - ih * esc - 4 * mm
        except Exception:
            pass
    caja_em_top = y_emisor
    c.roundRect(M, top - alto_cab, col, caja_em_top - (top - alto_cab), 3 * mm)
    y = caja_em_top - 6 * mm
    c.setFont("Helvetica-Bold", 10)
    for ln in _partir(c, d["razon_social"], col - 8 * mm, "Helvetica-Bold", 10):
        c.drawString(M + 4 * mm, y, ln); y -= 4.6 * mm
    if d["nombre_comercial"]:
        c.setFont("Helvetica", 8.5)
        c.drawString(M + 4 * mm, y, d["nombre_comercial"]); y -= 4.6 * mm
    y -= 1 * mm
    for etiqueta, valor in (("Dirección Matriz:", d["dir_matriz"]), ("Dirección Sucursal:", d["dir_establecimiento"])):
        c.setFont("Helvetica-Bold", 7.5); c.drawString(M + 4 * mm, y, etiqueta); y -= 3.6 * mm
        c.setFont("Helvetica", 7.5)
        for ln in _partir(c, valor, col - 8 * mm, "Helvetica", 7.5):
            c.drawString(M + 4 * mm, y, ln); y -= 3.6 * mm
        y -= 0.8 * mm
    c.setFont("Helvetica-Bold", 7.5)
    c.drawString(M + 4 * mm, y, f"OBLIGADO A LLEVAR CONTABILIDAD: {d['contabilidad'] or 'NO'}")
    if d["contribuyente_rimpe"]:
        y -= 3.6 * mm
        c.drawString(M + 4 * mm, y, d["contribuyente_rimpe"][:70])

    # caja derecha
    c.roundRect(der_x, top - alto_cab, col, alto_cab, 3 * mm)
    x = der_x + 4 * mm
    y = top - 7 * mm
    c.setFont("Helvetica-Bold", 10.5); c.drawString(x, y, f"R.U.C.: {d['ruc']}"); y -= 7 * mm
    c.setFont("Helvetica-Bold", 13); c.drawString(x, y, "FACTURA"); y -= 5.5 * mm
    c.setFont("Helvetica", 9.5); c.drawString(x, y, f"No. {d['numero']}"); y -= 6 * mm
    c.setFont("Helvetica-Bold", 7.5); c.drawString(x, y, "NÚMERO DE AUTORIZACIÓN"); y -= 4 * mm
    c.setFont("Helvetica", 7.2); c.drawString(x, y, d["numero_autorizacion"]); y -= 5.5 * mm
    fa = d["fecha_autorizacion"].replace("T", " ")[:19]
    c.setFont("Helvetica-Bold", 7.5); c.drawString(x, y, "FECHA Y HORA DE AUTORIZACIÓN:")
    c.setFont("Helvetica", 7.5); c.drawString(x + 47 * mm, y, fa); y -= 5 * mm
    c.setFont("Helvetica-Bold", 7.5); c.drawString(x, y, "AMBIENTE:")
    c.setFont("Helvetica", 7.5); c.drawString(x + 47 * mm, y, d["ambiente"]); y -= 5 * mm
    c.setFont("Helvetica-Bold", 7.5); c.drawString(x, y, "EMISIÓN:")
    c.setFont("Helvetica", 7.5); c.drawString(x + 47 * mm, y, "NORMAL"); y -= 6 * mm
    c.setFont("Helvetica-Bold", 7.5); c.drawString(x, y, "CLAVE DE ACCESO"); y -= 13 * mm
    barra = code128.Code128(d["clave"], barHeight=11 * mm, barWidth=0.235 * mm, quiet=False)
    escala = min(1.0, (col - 8 * mm) / barra.width)
    c.saveState(); c.translate(x, y); c.scale(escala, 1); barra.drawOn(c, 0, 0); c.restoreState()
    y -= 4 * mm
    c.setFont("Helvetica", 6.8); c.drawString(x, y, d["clave"])

    # ---------------- comprador
    y = top - alto_cab - 4 * mm
    alto_comp = 17 * mm
    c.rect(M, y - alto_comp, W - 2 * M, alto_comp)
    yy = y - 5 * mm
    c.setFont("Helvetica-Bold", 7.8); c.drawString(M + 3 * mm, yy, "Razón Social / Nombres y Apellidos:")
    c.setFont("Helvetica", 7.8)
    nombre_cli = d["cliente"]
    while nombre_cli and c.stringWidth(nombre_cli, "Helvetica", 7.8) > 86 * mm:
        nombre_cli = nombre_cli[:-1]
    c.drawString(M + 52 * mm, yy, nombre_cli)
    c.setFont("Helvetica-Bold", 7.8); c.drawString(M + 140 * mm, yy, "Identificación:")
    c.setFont("Helvetica", 7.8); c.drawString(M + 160 * mm, yy, d["id_cliente"])
    yy -= 5 * mm
    c.setFont("Helvetica-Bold", 7.8); c.drawString(M + 3 * mm, yy, "Fecha de Emisión:")
    c.setFont("Helvetica", 7.8); c.drawString(M + 52 * mm, yy, d["fecha_emision"])
    yy -= 5 * mm
    c.setFont("Helvetica-Bold", 7.8); c.drawString(M + 3 * mm, yy, "Dirección:")
    c.setFont("Helvetica", 7.8); c.drawString(M + 52 * mm, yy, d["dir_cliente"][:110])

    # ---------------- detalle
    y = y - alto_comp - 4 * mm
    cols = [("Cód. Principal", 24), ("Cant.", 15), ("Descripción", 86), ("Precio Unitario", 22), ("Descuento", 18), ("Precio Total", 21)]
    anchos = [w * mm for _, w in cols]
    xs = [M]
    for a in anchos:
        xs.append(xs[-1] + a)
    alto_tit = 8 * mm
    c.setFillColor(colors.HexColor("#eeeeee")); c.rect(M, y - alto_tit, xs[-1] - M, alto_tit, fill=1, stroke=1)
    c.setFillColor(colors.black)
    c.setFont("Helvetica-Bold", 7.2)
    for i, (t, _) in enumerate(cols):
        c.drawCentredString((xs[i] + xs[i + 1]) / 2, y - 5.2 * mm, t)
    y -= alto_tit
    c.setFont("Helvetica", 7.5)
    for det in d["detalles"]:
        lineas = _partir(c, det["descripcion"], anchos[2] - 3 * mm, "Helvetica", 7.5)
        alto = max(6 * mm, (len(lineas) * 3.6 + 2.6) * mm)
        if y - alto < 70 * mm:  # nueva página si no cabe (facturas largas)
            c.showPage(); y = H - M; c.setFont("Helvetica", 7.5)
        c.rect(M, y - alto, xs[-1] - M, alto)
        for xi in xs[1:-1]:
            c.line(xi, y, xi, y - alto)
        base = y - 4.2 * mm
        c.drawString(xs[0] + 1.5 * mm, base, det["codigo"])
        cant = f"{det['cantidad']:.2f}".rstrip("0").rstrip(".") if det["cantidad"] % 1 else f"{det['cantidad']:.0f}"
        c.drawRightString(xs[2] - 1.5 * mm, base, cant)
        for k, ln in enumerate(lineas):
            c.drawString(xs[2] + 1.5 * mm, base - k * 3.6 * mm, ln)
        c.drawRightString(xs[4] - 1.5 * mm, base, f"{det['precio']:.2f}")
        c.drawRightString(xs[5] - 1.5 * mm, base, _m(det["descuento"]))
        c.drawRightString(xs[6] - 1.5 * mm, base, _m(det["total"]))
        y -= alto

    # ---------------- totales (der.) e información adicional + pagos (izq.)
    y -= 4 * mm
    bases = {}
    for imp in d["impuestos"]:
        bases[imp["porcentaje"]] = bases.get(imp["porcentaje"], 0) + imp["base"]
    iva_total = sum(i["valor"] for i in d["impuestos"] if i["codigo"] == "2")
    tarifa = next((IVA_CODIGOS.get(i["porcentaje"], "") for i in d["impuestos"] if i["codigo"] == "2" and i["valor"] > 0), "15%")
    filas_tot = [
        (f"SUBTOTAL {tarifa}", sum(v for k, v in bases.items() if k not in ("0", "6", "7"))),
        ("SUBTOTAL 0%", bases.get("0", 0)),
        ("SUBTOTAL NO OBJETO DE IVA", bases.get("6", 0)),
        ("SUBTOTAL EXENTO DE IVA", bases.get("7", 0)),
        ("SUBTOTAL SIN IMPUESTOS", d["subtotal"]),
        ("TOTAL DESCUENTO", d["descuento"]),
        (f"IVA {tarifa}", iva_total),
        ("PROPINA", d["propina"]),
        ("VALOR TOTAL", d["total"]),
    ]
    tx, tw1, tw2 = xs[3], xs[5] - xs[3], xs[6] - xs[5]
    alto_f = 5.2 * mm
    yt = y
    for etiqueta, valor in filas_tot:
        negrita = etiqueta == "VALOR TOTAL"
        c.rect(tx, yt - alto_f, tw1, alto_f); c.rect(tx + tw1, yt - alto_f, tw2, alto_f)
        c.setFont("Helvetica-Bold" if negrita else "Helvetica", 6.9)
        c.drawString(tx + 1.5 * mm, yt - 3.7 * mm, etiqueta)
        c.drawRightString(tx + tw1 + tw2 - 1.5 * mm, yt - 3.7 * mm, _m(valor))
        yt -= alto_f

    ancho_izq = xs[3] - M - 4 * mm
    ya = y
    if d["adicional"]:
        lineas = []
        c.setFont("Helvetica", 7.5)
        for nombre, valor in d["adicional"]:
            for k, ln in enumerate(_partir(c, valor, ancho_izq - 30 * mm, "Helvetica", 7.5)):
                lineas.append((nombre if k == 0 else "", ln))
        alto = (len(lineas) * 3.8 + 9) * mm
        c.rect(M, ya - alto, ancho_izq, alto)
        c.setFont("Helvetica-Bold", 8); c.drawString(M + 3 * mm, ya - 5 * mm, "Información Adicional")
        yy = ya - 9.5 * mm
        for nombre, ln in lineas:
            c.setFont("Helvetica-Bold", 7.5); c.drawString(M + 3 * mm, yy, nombre)
            c.setFont("Helvetica", 7.5); c.drawString(M + 30 * mm, yy, ln)
            yy -= 3.8 * mm
        ya -= alto + 3 * mm
    if d["pagos"]:
        c.setFillColor(colors.HexColor("#eeeeee"))
        c.rect(M, ya - 6 * mm, ancho_izq, 6 * mm, fill=1); c.setFillColor(colors.black)
        c.setFont("Helvetica-Bold", 7.5)
        c.drawString(M + 2 * mm, ya - 4.2 * mm, "Forma de pago"); c.drawRightString(M + ancho_izq - 2 * mm, ya - 4.2 * mm, "Valor")
        ya -= 6 * mm
        c.setFont("Helvetica", 7)
        for p in d["pagos"]:
            texto = f"{p['forma']} - {FORMAS_PAGO.get(p['forma'], '')}"
            c.rect(M, ya - 6 * mm, ancho_izq, 6 * mm)
            c.drawString(M + 2 * mm, ya - 4.2 * mm, texto[:70])
            c.drawRightString(M + ancho_izq - 2 * mm, ya - 4.2 * mm, _m(p["total"]))
            ya -= 6 * mm

    c.setFont("Helvetica", 6.5); c.setFillColor(gris)
    c.drawCentredString(W / 2, 8 * mm, "Documento generado a partir del comprobante electrónico autorizado por el SRI."
                        if d["estado"].upper() == "AUTORIZADO" else
                        "Comprobante electrónico enviado al SRI; la autorización está en proceso.")
    c.save()
    tmp.replace(ruta_pdf)
    return ruta_pdf


if __name__ == "__main__":
    import sys
    generar_ride(sys.argv[1], sys.argv[2], sys.argv[3] if len(sys.argv) > 3 else None)
