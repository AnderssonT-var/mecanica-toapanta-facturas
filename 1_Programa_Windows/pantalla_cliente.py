"""
Pantalla del cliente con FactuSYS.

Mientras se hace la factura en FactuSYS, este módulo lee de la pantalla los campos
del formulario «Facturación» (RUC, nombre, dirección, teléfono, correo, total) con
el reconocimiento de texto que trae Windows (Windows.Media.Ocr, sin instalar nada)
y los manda a la página, donde un teléfono del mostrador se los muestra al cliente.

La pantalla solo informa: el cliente revisa sus datos y el total mientras se
factura. Al enviar el WhatsApp muestra «¡Gracias!» y luego vuelve el cartel.

Los campos se marcan una vez sobre una captura de FactuSYS («Marcar campos»).
Las posiciones se guardan respecto a la esquina de la ventana de FactuSYS, así que
sirven aunque la ventana se mueva.
"""

from __future__ import annotations

import configparser
import hashlib
import json
import logging
import re
import subprocess
import sys
import tempfile
import threading
import time
from pathlib import Path

import requests

import monitor_facturas as core

log = logging.getLogger("facturas")

# Recuadros que se marcan sobre FactuSYS: (clave, texto para la persona, obligatorio).
# Cada recuadro abarca varios datos CON sus etiquetas; el programa los separa solo.
CAMPOS = [
    ("cliente", "Recuadro 1: los datos del cliente, desde «RUC» hasta «EMAIL Notif.» (con las etiquetas)", True),
    ("totales", "Recuadro 2: Subtotal, IVA y Total (con sus etiquetas)", True),
]
# Datos que salen de los recuadros, para mostrar en «Probar lectura»
DATOS = [("ruc", "Cédula / RUC"), ("nombre", "Nombre"), ("direccion", "Dirección"), ("telefono", "Celular"),
         ("email", "Correo"), ("subtotal", "Subtotal"), ("iva", "IVA"), ("total", "Total")]
SECCION = "pantalla_cliente"
CONFIG_EXTRA = f"""
[{SECCION}]
activa = no
titulo_formulario = Facturación
intervalo = 0.4
monitor_hdmi = no
monitor_numero = 2
enviar_telefono = si
"""

# Lo que ya está en Windows 10/11: OCR de Windows Runtime desde PowerShell 5.1.
# Lee rutas de imágenes por la entrada estándar y responde una línea JSON por imagen.
SCRIPT_OCR = r"""
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [System.Text.Encoding]::UTF8
Add-Type -AssemblyName System.Runtime.WindowsRuntime
$null = [Windows.Storage.StorageFile, Windows.Storage, ContentType = WindowsRuntime]
$null = [Windows.Media.Ocr.OcrEngine, Windows.Foundation, ContentType = WindowsRuntime]
$null = [Windows.Foundation.IAsyncOperation`1, Windows.Foundation, ContentType = WindowsRuntime]
$null = [Windows.Graphics.Imaging.SoftwareBitmap, Windows.Foundation, ContentType = WindowsRuntime]
$null = [Windows.Storage.Streams.RandomAccessStream, Windows.Storage.Streams, ContentType = WindowsRuntime]
try { $null = [Windows.Graphics.Imaging.BitmapDecoder, Windows.Graphics, ContentType = WindowsRuntime] } catch {}
try { $null = [Windows.Globalization.Language, Windows.Globalization, ContentType = WindowsRuntime] } catch {}
$asTask = ([System.WindowsRuntimeSystemExtensions].GetMethods() | Where-Object {
  $_.Name -eq 'AsTask' -and $_.GetParameters().Count -eq 1 -and $_.GetParameters()[0].ParameterType.Name -eq 'IAsyncOperation`1' })[0]
function Esperar($op, [Type]$tipo) {
  $t = $asTask.MakeGenericMethod($tipo).Invoke($null, @($op)); $null = $t.Wait(-1); $t.Result
}
$motor = $null
foreach ($l in @('es-EC', 'es-419', 'es-MX', 'es-ES', 'es')) {
  try { $lang = New-Object Windows.Globalization.Language $l
        if ([Windows.Media.Ocr.OcrEngine]::IsLanguageSupported($lang)) { $motor = [Windows.Media.Ocr.OcrEngine]::TryCreateFromLanguage($lang); break } } catch {}
}
if ($motor -eq $null) { $motor = [Windows.Media.Ocr.OcrEngine]::TryCreateFromUserProfileLanguages() }
if ($motor -eq $null) { [Console]::Out.WriteLine('{"error":"Windows no tiene un idioma con reconocimiento de texto instalado"}'); exit 1 }
[Console]::Out.WriteLine('{"listo":true,"idioma":"' + $motor.RecognizerLanguage.LanguageTag + '"}')
[Console]::Out.Flush()
while ($true) {
  $ruta = [Console]::In.ReadLine()
  if ($ruta -eq $null -or $ruta -eq 'salir') { break }
  try {
    $f = Esperar ([Windows.Storage.StorageFile]::GetFileFromPathAsync($ruta)) ([Windows.Storage.StorageFile])
    $s = Esperar ($f.OpenAsync([Windows.Storage.FileAccessMode]::Read)) ([Windows.Storage.Streams.IRandomAccessStream])
    $d = Esperar ([Windows.Graphics.Imaging.BitmapDecoder]::CreateAsync($s)) ([Windows.Graphics.Imaging.BitmapDecoder])
    $b = Esperar ($d.GetSoftwareBitmapAsync()) ([Windows.Graphics.Imaging.SoftwareBitmap])
    $r = Esperar ($motor.RecognizeAsync($b)) ([Windows.Media.Ocr.OcrResult])
    $s.Dispose()
    $palabras = New-Object System.Collections.ArrayList
    foreach ($ln in $r.Lines) { foreach ($w in $ln.Words) {
      $null = $palabras.Add(@{ t = $w.Text; x = [int]$w.BoundingRect.X; y = [int]$w.BoundingRect.Y;
                               w = [int]$w.BoundingRect.Width; h = [int]$w.BoundingRect.Height }) } }
    $lineas = @($r.Lines | ForEach-Object { $_.Text })
    [Console]::Out.WriteLine((ConvertTo-Json -InputObject @{ texto = ($lineas -join ' '); palabras = $palabras.ToArray() } -Compress -Depth 4))
  } catch {
    [Console]::Out.WriteLine((@{ error = $_.Exception.Message } | ConvertTo-Json -Compress))
  }
  [Console]::Out.Flush()
}
"""


def completar_config(cfg: configparser.ConfigParser) -> None:
    extra = configparser.ConfigParser(interpolation=None)
    extra.read_string(CONFIG_EXTRA)
    for sec in extra.sections():
        if not cfg.has_section(sec):
            cfg.add_section(sec)
        for k, v in extra.items(sec):
            if not cfg.has_option(sec, k):
                cfg.set(sec, k, v)
    if cfg.get(SECCION, "intervalo", fallback="") == "1.2":      # versiones anteriores: lectura más rápida
        cfg.set(SECCION, "intervalo", "0.4")


def activa(cfg) -> bool:
    return cfg.getboolean(SECCION, "activa", fallback=False)


def rect_de(cfg, campo: str) -> tuple[int, int, int, int] | None:
    v = cfg.get(SECCION, f"r_{campo}", fallback="").strip()
    if not re.fullmatch(r"-?\d+,-?\d+,\d+,\d+", v):
        return None
    x, y, w, h = (int(n) for n in v.split(","))
    return (x, y, w, h) if w >= 4 and h >= 4 else None


def faltan_campos(cfg) -> list[str]:
    return [texto for k, texto, oblig in CAMPOS if oblig and not rect_de(cfg, k)]


# ---------------------------------------------------------------------------
# Limpieza de lo que lee el OCR
# ---------------------------------------------------------------------------

_A_NUMERO = str.maketrans({"O": "0", "o": "0", "D": "0", "Q": "0", "l": "1", "I": "1", "i": "1", "|": "1",
                           "!": "1", "S": "5", "s": "5", "B": "8", "Z": "2", "z": "2", "G": "6", "g": "9"})


def _numerico(texto: str) -> str:
    """Corrige letras confundidas (O→0, l→1…) solo en palabras que ya tienen algún dígito:
    así una etiqueta como «Total» o «Tel.» no se vuelve números."""
    partes = [p.translate(_A_NUMERO) for p in re.split(r"\s+", texto) if re.search(r"\d", p)]
    return " ".join(partes)


def _solo_digitos(texto: str) -> str:
    return re.sub(r"\D", "", _numerico(texto))


def limpiar_ruc(texto: str) -> str:
    for parte in re.split(r"[\s/-]+", texto):
        d = _solo_digitos(parte)
        if len(d) in (10, 13):
            return d
    d = _solo_digitos(texto)
    return d if len(d) in (10, 13) else ""


def limpiar_telefono(texto: str) -> str:
    """El primer número de teléfono escrito: celular (0992306629) o convencional
    (2242240, 022242240, 02-224-2240). Ignora basura como «Ÿ»."""
    t = _numerico(texto)
    for grupo in re.findall(r"\d[\d\s\-./]*\d", t):
        d = re.sub(r"\D", "", grupo)
        if 6 <= len(d) <= 10:
            return d
        if len(d) > 10:                           # dos números pegados: el celular o los primeros 10
            m = re.search(r"09\d{8}", d)
            return m.group(0) if m else d[:10]
    return ""


def limpiar_email(texto: str) -> str:
    t = re.sub(r"\s+", "", texto).lower().replace(",", ".").replace("(at)", "@").replace("@@", "@")
    # el OCR a veces se come el punto: «gmailcom» → «gmail.com», «hotmailcomec» → «hotmail.com.ec»
    if "@" in t:
        usuario, _, dominio = t.partition("@")
        dominio = dominio.strip(".")
        if "." not in dominio:
            dominio = re.sub(r"(com|net|org|gob|edu)(ec)?$", lambda m: "." + m.group(1) + ("." + m.group(2) if m.group(2) else ""), dominio)
        # «Notif.» mal leída y pegada al correo («nobf.contabilidad@…»): se quita
        primero, punto, resto = usuario.partition(".")
        if punto and resto and 3 <= len(primero) <= 7:
            import difflib
            if difflib.SequenceMatcher(None, primero, "notif").ratio() >= 0.6:
                usuario = resto
        t = usuario + "@" + dominio
    m = re.search(r"[a-z0-9._%+\-ñ]+@[a-z0-9\-]+(\.[a-z0-9\-]+)+", t)
    return m.group(0) if m else ""


def limpiar_texto(texto: str) -> str:
    t = re.sub(r"\s+", " ", texto).strip(" .,:;-_|")
    return t.upper()


def limpiar_valor(texto: str) -> str:
    """«$ 1.250,00», «25,00», «48.30», «50.000000» (FactuSYS) → «1250.00», «25.00», «48.30», «50.00»."""
    if not re.search(r"\d", texto):
        return ""
    t = re.sub(r"[^0-9.,]", "", _numerico(texto).replace(" ", "")).strip(".,")
    if not t:
        return ""
    seps = [k for k, c in enumerate(t) if c in ".,"]
    if seps:
        ult = seps[-1]
        dec = t[ult + 1:]
        # 3 cifras tras el único separador = miles («1.250»); si no, son decimales
        if not (len(dec) == 3 and len(seps) == 1) and dec.isdigit():
            entero = re.sub(r"\D", "", t[:ult]) or "0"
            if len(entero) > 7:
                return ""
            return f"{float(entero + '.' + dec):.2f}"
    d = re.sub(r"\D", "", t)
    return f"{int(d)}.00" if d and len(d) <= 7 else ""


LIMPIEZA = {"ruc": limpiar_ruc, "nombre": limpiar_texto, "direccion": limpiar_texto, "telefono": limpiar_telefono,
            "email": limpiar_email, "subtotal": limpiar_valor, "iva": limpiar_valor, "total": limpiar_valor}


# ---------------------------------------------------------------------------
# Separar los datos de un recuadro (por filas y por la etiqueta de cada fila)
# ---------------------------------------------------------------------------

def _sin_tildes(t: str) -> str:
    import unicodedata
    return "".join(c for c in unicodedata.normalize("NFD", t) if unicodedata.category(c) != "Mn").upper()


def filas(palabras: list[dict]) -> list[list[dict]]:
    """Agrupa las palabras en filas (misma altura) y las ordena de izquierda a derecha."""
    ps = sorted((p for p in palabras if p.get("t")), key=lambda p: p["y"] + p["h"] / 2)
    res: list[list[dict]] = []
    for p in ps:
        centro = p["y"] + p["h"] / 2
        if res:
            ult = res[-1]
            c_ult = sum(q["y"] + q["h"] / 2 for q in ult) / len(ult)
            alto = max(max(q["h"] for q in ult), p["h"])
            if abs(centro - c_ult) <= alto * 0.6:
                ult.append(p)
                continue
        res.append([p])
    return [sorted(f, key=lambda p: p["x"]) for f in res]


_ETIQUETAS_CLIENTE = {
    "ruc": ("RUC", "R.U.C", "CEDULA", "C.I"),
    "direccion": ("DIREC", "DIRECCION"),
    "telefono": ("TELEF", "TELF", "FONO"),
    "email": ("EMAIL", "E-MAIL", "MAIL", "NOTIF", "CORREO"),
}
_IGNORAR = ("DIAS", "CREDITO", "VENDEDOR", "OBSERV", "TRANSAC", "SECUENCIA", "VCTO", "GUIA", "REMISION", "FECHA")


_RAICES_ETIQUETA = ("RUC", "CEDULA", "DIREC", "TELEF", "TELF", "EMAIL", "E-MAIL", "NOTIF", "CORREO")


def _es_etiqueta(palabra: str) -> bool:
    """Etiquetas del formulario («RUC:», «Dirección :», «Teléfonos:», «EMAIL Notif.:», la casilla «Ex»)."""
    u = re.sub(r"[^A-Z0-9@.\-]", "", _sin_tildes(palabra))
    if not u:
        return True                              # signos sueltos: «:», «|», «[_]»
    if "@" in u:
        return False
    if any(u.startswith(r) for r in _RAICES_ETIQUETA) and len(re.sub(r"\D", "", u)) <= 2:
        return True                              # «DIRECC1ON», «TELEF0NOS»: etiqueta con letras confundidas
    if re.search(r"\d", u):
        return False
    if u in ("EX", "E", "X", "DIR", "TEL"):
        return True
    return palabra.rstrip(" |").endswith(":")


def clasificar_cliente(palabras: list[dict]) -> dict:
    """RUC + nombre (misma fila), dirección, celular y correo desde el recuadro del cliente."""
    res = {"ruc": "", "nombre": "", "direccion": "", "telefono": "", "email": ""}
    sin_etiqueta: list[list[dict]] = []
    etiquetas = 0
    for fila in filas(palabras):
        texto = " ".join(p["t"] for p in fila)
        u = _sin_tildes(texto)
        if any(x in u for x in _IGNORAR) and "@" not in texto:
            continue
        tipo = next((k for k, claves in _ETIQUETAS_CLIENTE.items() if any(c in u.split() or u.startswith(c) or
                                                                         (" " + c) in u for c in claves)), None)
        if tipo is None:                       # etiqueta partida por el OCR: «Di reccién», «EMAI L Notif.»
            junto = re.sub(r"[^A-Z@]", "", u.split(":")[0] if ":" in u else u)[:20]
            tipo = next((k for k, claves in _ETIQUETAS_CLIENTE.items()
                         if any(junto.startswith(c.replace(".", "").replace("-", "")) for c in claves)), None)
        if tipo is None and "@" in texto:
            tipo = "email"
        if tipo is not None:
            etiquetas += 1
        if tipo is None:
            sin_etiqueta.append(fila)
            continue
        _llenar_fila(res, tipo, fila)
    # filas sin etiqueta reconocida: se asignan por orden (RUC, dirección, teléfono, correo),
    # solo si el recuadro sí tiene el formulario (al menos 2 etiquetas leídas). Así una lista de
    # clientes u otra ventana encima no se toma como datos.
    if etiquetas >= 2:
        orden = [k for k in ("ruc", "direccion", "telefono", "email") if not (res[k] or (k == "ruc" and res["nombre"]))]
        for fila, tipo in zip(sin_etiqueta, orden):
            _llenar_fila(res, tipo, fila)
    elif etiquetas == 0:
        return {"ruc": "", "nombre": "", "direccion": "", "telefono": "", "email": ""}
    return res


_RUIDO_CASILLA = re.compile(r"^\W*[A-Z]{0,3}EX\W*$")    # la casilla «Ex» leída como «CIEX», «LEX», «□Ex»


def _ruc_en(datos: list[str]) -> tuple[str, int]:
    """Cédula (10) o RUC (13) en la fila y en qué palabra está; ('', -1) si no hay."""
    for i, t in enumerate(datos):                   # 1) cifras seguidas dentro de una palabra («CIEX1718955246001»)
        for run in re.findall(r"\d+", t):
            if len(run) in (10, 13):
                return run, i
    for i, t in enumerate(datos):                   # 2) con letras confundidas (O→0, l→1…)
        d = _solo_digitos(t)
        if len(d) in (10, 13):
            return d, i
    pegado, ultimo = "", -1                         # 3) el número partido en varias palabras
    for i, t in enumerate(datos):
        if re.fullmatch(r"[\dOolIS]+", t):
            pegado += _solo_digitos(t); ultimo = i
            if len(pegado) in (10, 13):
                return pegado, ultimo
        elif pegado:
            pegado = ""
    return "", -1


def _sin_etiqueta_partida(fila: list[dict]) -> list[dict]:
    """Quita del inicio de la fila una etiqueta que el OCR partió en varias palabras
    («Di reccién :», «EMAI L Notif.:»): las palabras hasta los «:» si juntas forman una etiqueta."""
    for k in range(min(5, len(fila))):
        if ":" in fila[k]["t"]:
            if fila[k]["t"].rstrip(" |").endswith(":") or fila[k]["t"].strip(" |") == ":":
                junto = re.sub(r"[^A-Z]", "", _sin_tildes("".join(p["t"] for p in fila[:k + 1])))
                if any(junto.startswith(r.replace("-", "")) for r in _RAICES_ETIQUETA) and len(junto) <= 16:
                    return fila[k + 1:]
            break
    return fila


def _llenar_fila(res: dict, tipo: str, fila: list[dict]) -> None:
    fila = _sin_etiqueta_partida(fila)
    datos = [p["t"] for p in fila if not _es_etiqueta(p["t"])]
    texto = " ".join(datos)
    if tipo == "ruc":
        # el nombre va después del número; ahí no se descartan palabras por terminar en «:»
        # (el OCR a veces lee «CACARIN:»), solo signos sueltos
        todas = [re.sub(r"^[\W_]+|[\W_]+$", "", p["t"]) for p in fila]
        todas = [t for t in todas if t]
        ruc, idx = _ruc_en(todas)
        if ruc:
            res["ruc"] = ruc
        letras = todas[idx + 1:] if idx >= 0 else [t for t in datos if not re.search(r"\d", t)]
        letras = [t for t in letras if not re.search(r"\d", t)
                  and sum(c.isalpha() for c in t) >= max(1, len(t.strip(".,")) * 0.6)]
        while letras and _RUIDO_CASILLA.match(_sin_tildes(letras[0])):
            letras = letras[1:]
        nombre = limpiar_texto(" ".join(letras))
        if len(nombre) >= 3 and nombre != "CONSUMIDOR FINAL":
            res["nombre"] = nombre
    elif tipo == "direccion":
        res["direccion"] = res["direccion"] or limpiar_texto(texto)
    elif tipo == "telefono":
        res["telefono"] = res["telefono"] or limpiar_telefono(texto)
    elif tipo == "email":
        # solo la palabra con «@» (y su pedazo si el OCR la partió): así «Notif.:» mal leída
        # («nobf.») no se pega al correo
        palabras_dato = [p["t"] for p in fila]
        k = next((n for n, t in enumerate(palabras_dato) if "@" in t), None)
        if k is not None:
            correo = palabras_dato[k]
            if correo.startswith("@") and k > 0 and not _es_etiqueta(palabras_dato[k - 1]):
                correo = palabras_dato[k - 1] + correo
            if correo.endswith("@") and k + 1 < len(palabras_dato):
                correo = correo + palabras_dato[k + 1]
            res["email"] = res["email"] or limpiar_email(correo)
        else:
            res["email"] = res["email"] or limpiar_email(texto)


def clasificar_totales(palabras: list[dict]) -> dict:
    """Subtotal, IVA y Total desde el recuadro del pie de la factura."""
    res = {"subtotal": "", "iva": "", "total": ""}
    subtotales, ivas, sueltos = [], [], []
    for fila in filas(palabras):
        texto = " ".join(p["t"] for p in fila)
        u = _sin_tildes(texto)
        # el valor es el último número con decimales de la fila (no el «15» de «IVA 15 %»)
        nums = [limpiar_valor(p["t"]) for p in fila if "%" not in p["t"]
                and re.search(r"\d[.,]\d{2,6}\b|\d[.,]\d[O0o]", p["t"].translate(_A_NUMERO))]
        nums = [n for n in nums if n]
        if not nums:
            continue
        valor = nums[-1]
        letras = re.sub(r"[^A-Z ]", " ", u)
        etiqueta = " ".join(p["t"] for p in fila if not re.search(r"\d[.,]\d{2}", p["t"]))
        if "DESC" in letras:
            continue
        es_base = any(x in letras for x in ("SUB", "BASE", "IMPONIBLE", "OBJETO", "EXENTO"))
        if "TOTAL" in letras and not es_base:
            res["total"] = valor
        elif not es_base and ("IVA" in letras or "%" in texto):     # «IVA 15 %», «15.00 %»
            ivas.append(valor)
        elif es_base:
            subtotales.append((etiqueta, valor))
        else:
            sueltos.append(valor)
    if ivas:
        res["iva"] = f"{sum(float(v) for v in ivas):.2f}"
    if subtotales:
        solo = [v for l, v in subtotales if "%" not in l and not re.search(r"\d", l)]   # «SUBTOTAL» sin porcentaje
        res["subtotal"] = solo[-1] if len(subtotales) > 1 and solo else f"{sum(float(v) for _, v in subtotales):.2f}"
    if not res["total"] and sueltos:            # sin etiquetas legibles: el mayor es el total
        res["total"] = max(sueltos, key=float)
    if not res["total"] and res["subtotal"]:
        res["total"] = f"{float(res['subtotal']) + float(res['iva'] or 0):.2f}"
    if res["total"] and res["iva"]:             # el subtotal siempre cuadra: total − IVA
        res["subtotal"] = f"{float(res['total']) - float(res['iva']):.2f}"
    return res


CLASIFICAR = {"cliente": clasificar_cliente, "totales": clasificar_totales}


_PARTICULAS = {"DE", "LA", "EL", "Y", "DEL", "LOS", "LAS", "SN", "AV", "MZ", "LT"}


def _clave(t: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", _sin_tildes(t))


def separar_pegadas(normal: str, separado: str) -> str:
    """Separa en «normal» las palabras que salieron pegadas, usando la lectura hecha con los
    espacios ensanchados. Solo se separa si la otra lectura tiene exactamente las mismas letras
    y cada pedazo es una palabra creíble (3+ letras o «DE», «LA», «Y»…); así no se parte
    «FERNANDA» en «FERNAN DA» cuando el nombre ya venía bien."""
    if not normal or not separado:
        return normal or separado
    sp = separado.split()
    out: list[str] = []
    for t in normal.split():
        k = _clave(t)
        hecho = False
        if len(k) >= 5 and not re.search(r"\d", k):
            for i in range(len(sp)):
                acc = ""
                for m in range(i, min(i + 6, len(sp))):
                    acc += _clave(sp[m])
                    if acc == k:
                        partes = [re.sub(r"^[\W_]+|[\W_]+$", "", x) for x in sp[i:m + 1]]
                        if m > i and all(len(_clave(x)) >= 3 or _clave(x) in _PARTICULAS for x in partes):
                            out.extend(partes)
                            hecho = True
                        break
                    if not k.startswith(acc):
                        break
                if hecho:
                    break
        if not hecho:
            out.append(t)
    return " ".join(out)


def _hace_falta_separar(v: dict) -> bool:
    """La segunda lectura (espacios ensanchados) solo cuando algo parece pegado o falta: así
    cada vuelta tarda la mitad."""
    if not v.get("nombre") or not v.get("ruc"):
        return True
    for k in ("nombre", "direccion"):
        if any(len(_clave(t)) >= 11 and not re.search(r"\d", t) for t in (v.get(k) or "").split()):
            return True
    return False


def leer_zona(lector, zona: str, img) -> tuple[dict, str]:
    """Lee un recuadro y clasifica sus datos. En el del cliente se lee dos veces (normal y con
    los espacios entre palabras ensanchados) para separar nombres y direcciones pegados."""
    if not hasattr(lector, "leer_todo"):                 # lectores simples (pruebas)
        palabras = lector.leer_palabras(img)
        return CLASIFICAR[zona](palabras), " ".join(p["t"] for p in palabras)
    crudo, palabras = lector.leer_todo(img)
    valores = CLASIFICAR[zona](palabras)
    if zona == "cliente" and _hace_falta_separar(valores):
        try:
            crudo2, palabras2 = lector.leer_todo(img, separar=True)
        except Exception:  # noqa: BLE001
            return valores, crudo
        otros = CLASIFICAR[zona](palabras2)
        for k in ("nombre", "direccion"):
            valores[k] = separar_pegadas(valores.get(k, ""), otros.get(k, ""))
        for k, v in otros.items():
            if not valores.get(k):
                valores[k] = v
        crudo = crudo + "\n— con espacios ensanchados —\n" + crudo2
    return valores, crudo


def armar_datos(valores: dict) -> dict:
    """De los campos leídos a lo que muestra la pantalla."""
    total = valores.get("total", "")
    base = valores.get("subtotal", "")
    iva = valores.get("iva", "")
    iva_txt = ""
    if base and iva:
        try:
            porc = round(float(iva) / float(base) * 100) if float(base) else 0
            iva_txt = f"IVA {porc} %"
        except ValueError:
            iva_txt = "IVA"
    return {"ruc": valores.get("ruc", ""), "nombre": valores.get("nombre", ""),
            "direccion": valores.get("direccion", ""), "telefono": valores.get("telefono", ""),
            "email": valores.get("email", ""), "detalle": "", "base": base if iva_txt else "",
            "iva": iva if iva_txt else "", "iva_txt": iva_txt, "total": total}


def datos_completos(d: dict) -> bool:
    try:
        total = float(d.get("total") or 0)
    except ValueError:
        total = 0
    return bool(d.get("ruc")) and len(d.get("nombre", "")) >= 3 and total > 0


# ---------------------------------------------------------------------------
# OCR de Windows (proceso de PowerShell que queda abierto)
# ---------------------------------------------------------------------------

def _completo(k: str, v: str) -> bool:
    if k == "ruc":
        return len(v) in (10, 13) and v.isdigit()
    if k == "telefono":
        return 7 <= len(v) <= 10 and v.isdigit()
    if k == "email":
        return bool(re.fullmatch(r"[^@\s]+@[^@\s]+\.[a-z]{2,}", v, re.I))
    if k in ("subtotal", "iva", "total"):
        return bool(re.fullmatch(r"\d+\.\d{2}", v))
    return False


class ErrorOCR(Exception):
    pass


class LectorOCR:
    def __init__(self):
        self.proc = None
        self.lock = threading.Lock()
        self.carpeta = Path(tempfile.mkdtemp(prefix="mtv_ocr_"))
        self.n = 0
        self.idioma = ""

    def _arrancar(self):
        if self.proc and self.proc.poll() is None:
            return
        script = self.carpeta / "ocr.ps1"
        script.write_text(SCRIPT_OCR, encoding="utf-8-sig")
        flags = 0x08000000 if sys.platform == "win32" else 0   # CREATE_NO_WINDOW
        self.proc = subprocess.Popen(
            ["powershell.exe", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", str(script)],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL,
            text=True, encoding="utf-8", bufsize=1, creationflags=flags)
        primera = self._leer_linea(40)
        if not primera.get("listo"):
            self.cerrar()
            raise ErrorOCR(primera.get("error") or "El reconocimiento de texto de Windows no arrancó.")
        self.idioma = primera.get("idioma", "")
        log.info("Reconocimiento de texto de Windows listo (%s).", self.idioma)

    def _leer_linea(self, espera: float) -> dict:
        resultado = {}

        def leer():
            try:
                resultado["linea"] = self.proc.stdout.readline()
            except Exception as e:  # noqa: BLE001
                resultado["error"] = str(e)
        h = threading.Thread(target=leer, daemon=True)
        h.start()
        h.join(espera)
        linea = (resultado.get("linea") or "").strip().lstrip("﻿")
        if not linea:
            self.cerrar()
            raise ErrorOCR("El reconocimiento de texto de Windows no respondió.")
        try:
            return json.loads(linea)
        except ValueError:
            return {"error": linea[:200]}

    def leer(self, imagen) -> str:
        """Texto de una imagen de Pillow."""
        return self.leer_todo(imagen)[0]

    def leer_palabras(self, imagen) -> list[dict]:
        """Palabras con su posición: [{"t": texto, "x", "y", "w", "h"}]."""
        return self.leer_todo(imagen)[1]

    def leer_todo(self, imagen, separar: bool = False) -> tuple[str, list[dict]]:
        with self.lock:
            self._arrancar()
            self.n = (self.n + 1) % 20
            ruta = self.carpeta / f"c{self.n}.png"
            preparar(imagen, separar).save(ruta)
            self.proc.stdin.write(str(ruta) + "\n")
            self.proc.stdin.flush()
            r = self._leer_linea(15)
            if "error" in r:
                raise ErrorOCR(r["error"])
            palabras = r.get("palabras") or []
            if isinstance(palabras, dict):        # PowerShell entrega un objeto suelto si hay una sola palabra
                palabras = [palabras]
            return r.get("texto", ""), [p for p in palabras if isinstance(p, dict) and p.get("t")]

    def cerrar(self):
        if self.proc:
            try:
                self.proc.stdin.write("salir\n")
                self.proc.stdin.flush()
            except Exception:  # noqa: BLE001
                pass
            try:
                self.proc.kill()
            except Exception:  # noqa: BLE001
                pass
        self.proc = None


def separar_palabras(g, tinta: int = 120):
    """FoxPro dibuja la letra muy junta y el OCR une palabras («CACARINSIERRA»).
    Por cada fila de texto se buscan los huecos verticales más anchos que el espacio
    entre letras y se ensanchan, para que el OCR vea claramente cada espacio."""
    from PIL import Image
    w, h = g.size
    px = g.load()
    con_tinta = [any(px[x, y] < tinta for x in range(w)) for y in range(h)]
    # filas de texto (bandas con tinta, tolerando 1 px suelto)
    bandas, y = [], 0
    while y < h:
        if con_tinta[y]:
            y0 = y
            while y < h and (con_tinta[y] or (y + 1 < h and con_tinta[y + 1])):
                y += 1
            if y - y0 >= 5:                           # más bajo que eso es una raya, no texto
                bandas.append((max(0, y0 - 2), min(h, y + 2)))
        y += 1
    if not bandas:
        return g
    filas_nuevas, extra_max = [], 0
    for y0, y1 in bandas:
        alto = y1 - y0
        alto_letra = max(5, alto - 4)
        cols = [any(px[x, yy] < tinta for yy in range(y0, y1)) for x in range(w)]
        huecos, x = [], 0                              # (ancho, posición final) de cada hueco entre trazos
        while x < w:
            if not cols[x]:
                x0 = x
                while x < w and not cols[x]:
                    x += 1
                if x0 > 0 and x < w:
                    huecos.append((x - x0, x))
            else:
                x += 1
        if not huecos:
            continue
        # el hueco normal entre letras es el más común; un espacio es claramente más ancho
        anchos = sorted(a for a, _ in huecos)
        normal = anchos[len(anchos) // 3]
        minimo = max(3, round(alto_letra * 0.22), normal * 2 + 1)
        # el espacio se separa de los huecos entre letras con un salto claro de tamaño: se busca
        # el mayor salto entre anchos consecutivos (sin contar los huecos grandes entre recuadros)
        distintos = sorted({a for a in anchos if a <= alto_letra * 1.5})
        umbral, mejor = minimo, 0.0
        for a, b in zip(distintos, distintos[1:]):
            if b >= minimo and a >= normal and b / a > mejor:
                mejor, umbral = b / a, b
        if mejor < 1.3:
            umbral = max(minimo, round(alto_letra * 0.36))
        anadir = max(4, round(alto_letra * 0.6))
        cortes = [fin for ancho, fin in huecos if ancho >= umbral]
        extra = anadir * len(cortes)
        nueva = Image.new("L", (w + extra, alto), 255)
        antes, desp = 0, 0
        for c in cortes + [w]:
            nueva.paste(g.crop((antes, y0, c, y1)), (antes + desp, 0))
            antes = c
            desp += anadir
        filas_nuevas.append((y0, nueva))
        extra_max = max(extra_max, extra)
    salida = Image.new("L", (w + extra_max, h), 255)
    for y0, img in filas_nuevas:                      # entre filas se deja el fondo en blanco
        salida.paste(img, (0, y0))
    return salida


def preparar(imagen, separar: bool = False):
    """Gris, más grande y con borde blanco: así el OCR lee mejor la letra chica de FoxPro.
    Con «separar» además ensancha los espacios entre palabras (letra apretada)."""
    from PIL import Image, ImageOps
    g = ImageOps.grayscale(imagen)
    try:
        if separar:
            g = separar_palabras(g)
    except Exception:  # noqa: BLE001
        pass
    escala = 3 if g.height < 60 else 2
    g = g.resize((g.width * escala, g.height * escala), Image.LANCZOS)
    return ImageOps.expand(g, border=12, fill=255)


# ---------------------------------------------------------------------------
# Ventana de FactuSYS en pantalla (coordenadas físicas, igual que las capturas)
# ---------------------------------------------------------------------------

class VentanaFactuSYS:
    def __init__(self, cfg):
        self.cfg = cfg
        self._ctl = None

    @property
    def ctl(self):
        if self._ctl is None:
            import facturador as fz
            self._ctl = fz.ControlFactuSYS(self.cfg, None)
        return self._ctl

    def _consciente(self):
        """Contexto para que Windows dé coordenadas reales (pantallas con zoom 125 %, 150 %…)."""
        import ctypes
        u = ctypes.windll.user32
        try:
            u.SetThreadDpiAwarenessContext.restype = ctypes.c_void_p
            u.SetThreadDpiAwarenessContext.argtypes = [ctypes.c_void_p]
            return u, u.SetThreadDpiAwarenessContext(ctypes.c_void_p(-4))
        except AttributeError:
            return u, None

    def rect_principal(self) -> tuple[int, int, int, int] | None:
        import ctypes
        from ctypes import wintypes as wt
        h = self.ctl.principal()
        if not h:
            return None
        u, antes = self._consciente()
        try:
            r = wt.RECT()
            u.GetWindowRect(h, ctypes.byref(r))
            return r.left, r.top, r.right, r.bottom
        finally:
            if antes:
                u.SetThreadDpiAwarenessContext(ctypes.c_void_p(antes))

    def formulario_abierto(self) -> bool:
        titulo = self.cfg.get(SECCION, "titulo_formulario", fallback="Facturación")
        try:
            return bool(self.ctl.ventana_con_titulo(titulo))
        except Exception:  # noqa: BLE001
            return False

    def ventana_encima(self) -> bool:
        """Una lista o buscador de FactuSYS abierto sobre la factura (taparía los datos)."""
        for t in [x.strip() for x in self.cfg.get(SECCION, "ventanas_encima",
                                                   fallback="Listado;Buscar;Selector;Consulta").split(";") if x.strip()]:
            try:
                if self.ctl.ventana_con_titulo(t):
                    return True
            except Exception:  # noqa: BLE001
                pass
        return False

    def al_frente(self) -> bool:
        """FactuSYS es la ventana activa (si no, la captura sería de otra cosa)."""
        import ctypes
        h = ctypes.windll.user32.GetForegroundWindow()
        try:
            return bool(h) and self.ctl.whats._exe(h) == "factusys.exe"
        except Exception:  # noqa: BLE001
            return False

    def captura(self, bbox=None):
        from PIL import ImageGrab
        return ImageGrab.grab(bbox=bbox, all_screens=True)


# ---------------------------------------------------------------------------
# Comunicación con la página
# ---------------------------------------------------------------------------

class Pagina:
    def __init__(self, cfg):
        self.url = re.sub(r"upload\.php$", "agente.php", cfg.get("servidor", "upload_url").strip())
        self.clave = cfg.get("servidor", "upload_key", fallback="").strip()

    def api(self, accion: str, **datos) -> dict:
        r = requests.post(self.url, json={"accion": accion, **datos}, headers={"X-Upload-Key": self.clave}, timeout=20)
        try:
            j = r.json()
        except ValueError:
            raise core.ErrorSubida(f"Respuesta inesperada de la página ({r.status_code}).")
        if not j.get("ok"):
            raise core.ErrorSubida(j.get("error") or f"La página respondió {r.status_code}.")
        return j

    def poner(self, fase: str, datos: dict | None = None) -> dict:
        return self.api("pantalla", fase=fase, datos=datos or {})

    def ver(self) -> dict:
        return self.api("pantalla_ver")


class Salida:
    """Adónde va lo que se lee: el monitor por cable (al instante) y la página (teléfonos).
    Con monitor, la página se actualiza en segundo plano para no hacerlo esperar."""

    def __init__(self, pagina, monitor=None, telefono: bool = True):
        self.pagina, self.monitor, self.telefono = pagina, monitor, telefono
        self._pendiente = None
        self._hay = threading.Event()
        if monitor and telefono:
            threading.Thread(target=self._enviar_en_fondo, daemon=True).start()

    def poner(self, fase: str, datos: dict | None = None):
        if self.monitor:
            self.monitor.poner(fase, datos)
            if self.telefono:
                self._pendiente = (fase, datos)
                self._hay.set()
            return {}
        return self.pagina.poner(fase, datos)

    def _enviar_en_fondo(self):
        while True:
            self._hay.wait()
            self._hay.clear()
            fase, datos = self._pendiente
            try:
                self.pagina.poner(fase, datos)
            except Exception as e:  # noqa: BLE001
                log.debug("Pantalla del cliente (página): %s", e)
                time.sleep(3)
                if not self._hay.is_set():
                    self._hay.set()

    def ver(self) -> dict:
        return self.pagina.ver()


# ---------------------------------------------------------------------------
# Lectura continua de FactuSYS → pantalla
# ---------------------------------------------------------------------------

class PantallaCliente:
    """Hilo que lee FactuSYS y decide, para el monitor, si un PDF se puede enviar."""

    def __init__(self, cfg, al_evento=None, ventana=None, ocr=None, pagina=None, monitor=None):
        completar_config(cfg)
        self.cfg = cfg
        self.al_evento = al_evento or (lambda *a: None)
        self.ventana = ventana or VentanaFactuSYS(cfg)
        self.ocr = ocr or LectorOCR()
        self.pagina = Salida(pagina or Pagina(cfg), monitor,
                             telefono=cfg.getboolean(SECCION, "enviar_telefono", fallback=True))
        self.detener = threading.Event()
        self.ultimo_img: dict[str, str] = {}      # huella de la imagen de cada campo
        self.ultimo_txt: dict[str, str] = {}      # texto limpio de la lectura anterior
        self.estables: dict[str, str] = {}        # valor confirmado (igual en 2 lecturas)
        self.zonas: dict[str, dict] = {}          # datos sacados de cada recuadro
        self.enviado: dict | None = None          # lo último que se mandó a la página
        self.fase_enviada = ""
        self.formulario = False
        self.texto_estado = "apagada"
        self.error_ocr = ""
        # para el monitor
        self.decisiones: dict[str, str] = {}      # PDF → "enviar"/"descartar" (elegido en la PC)
        self.pendientes: dict[str, dict] = {}     # PDF esperando confirmación
        self.version_usada = -1
        self.lock = threading.Lock()

    # ---- lectura ---------------------------------------------------------------
    def leer_campos(self, origen: tuple[int, int]) -> dict:
        ox, oy = origen
        leidos: dict[str, str] = {}
        for zona, _, _ in CAMPOS:
            r = rect_de(self.cfg, zona)
            if not r:
                continue
            x, y, w, h = r
            img = self.ventana.captura((ox + x, oy + y, ox + x + w, oy + y + h))
            huella = hashlib.md5(img.tobytes()).hexdigest()
            if huella != self.ultimo_img.get(zona) or zona not in self.zonas:
                self.zonas[zona] = leer_zona(self.ocr, zona, img)[0]
                self.ultimo_img[zona] = huella
            leidos.update(self.zonas[zona])
        valores = {}
        for k, limpio in leidos.items():
            # Un dato que ya se ve completo (cédula/RUC de 10 o 13 cifras, teléfono, correo, valores)
            # pasa al instante; el resto se acepta cuando sale igual dos veces seguidas (evita
            # mostrar lecturas a medias mientras se escribe).
            if limpio and _completo(k, limpio):
                self.estables[k] = limpio
            elif limpio == self.ultimo_txt.get(k) and (limpio or not self.estables.get(k)):
                self.estables[k] = limpio      # un dato ya leído no se borra por una lectura vacía
            self.ultimo_txt[k] = limpio
            valores[k] = self.estables.get(k, "")
        return valores

    def una_vuelta(self) -> None:
        if not activa(self.cfg):
            self.texto_estado = "apagada"
            return
        abierto = self.ventana.formulario_abierto()
        if not abierto:
            if self.formulario:
                log.info("Pantalla del cliente: se cerró el formulario de FactuSYS.")
            self.formulario = False
            self.texto_estado = "esperando una factura en FactuSYS"
            if self.fase_enviada in ("llenando", "revisar"):   # sin factura en pantalla: vuelve el cartel
                self.pagina.poner("vacio")
                self.fase_enviada, self.enviado = "vacio", None
            return
        if not self.formulario:
            self.formulario = True
            self.ultimo_img.clear(); self.ultimo_txt.clear(); self.estables.clear(); self.zonas.clear()
            log.info("Pantalla del cliente: factura abierta en FactuSYS, leyendo datos…")
        if not self.ventana.al_frente():
            self.texto_estado = "FactuSYS no está al frente (no se lee)"
            return
        if self.ventana.ventana_encima():
            self.texto_estado = "hay una lista abierta sobre la factura (no se lee)"
            return
        rect = self.ventana.rect_principal()
        if not rect:
            return
        try:
            valores = self.leer_campos((rect[0], rect[1]))
            self.error_ocr = ""
        except ErrorOCR as e:
            if str(e) != self.error_ocr:
                log.error("Pantalla del cliente: %s", e)
            self.error_ocr = str(e)
            self.texto_estado = f"sin lectura: {e}"
            return
        datos = armar_datos(valores)
        try:
            hay_total = float(datos["total"] or 0) > 0
        except ValueError:
            hay_total = False
        if not (datos["ruc"] or datos["nombre"] or hay_total):     # formulario vacío (total 0.00): cartel
            fase = "vacio"
            if self.pendientes:          # hay un PDF esperando la confirmación: no se borra la pantalla
                return
        else:
            fase = "revisar" if datos_completos(datos) else "llenando"
        if fase == self.fase_enviada and datos == self.enviado:
            self.texto_estado = "mostrando al cliente: " + (datos["nombre"] or datos["ruc"] or "…")
            return
        self.pagina.poner(fase, datos if fase != "vacio" else None)
        self.fase_enviada, self.enviado = fase, datos
        self.texto_estado = "mostrando al cliente: " + (datos["nombre"] or datos["ruc"] or "…")

    def ejecutar(self) -> None:
        log.info("Pantalla del cliente activa (lee FactuSYS y espera la confirmación antes de cada WhatsApp).")
        while not self.detener.is_set():
            try:
                self.una_vuelta()
            except (core.ErrorSubida, requests.RequestException) as e:
                self.texto_estado = "sin conexión con la página"
                log.debug("Pantalla del cliente: %s", e)
            except Exception:  # noqa: BLE001
                log.exception("Error inesperado en la pantalla del cliente")
            self.detener.wait(max(0.15, self.cfg.getfloat(SECCION, "intervalo", fallback=0.4)))
        try:
            self.ocr.cerrar()
        except Exception:  # noqa: BLE001
            pass

    # ---- decisión para el monitor de WhatsApp --------------------------------------
    def decidir(self, ruta: Path, telefono: str) -> str:
        """Para el monitor: la pantalla solo informa al cliente (no tiene botones), así que
        el WhatsApp se envía siempre, como antes."""
        return "enviar"

    def _avisar(self, clave, pend, tipo, telefono, texto):
        if pend.get("avisado") != tipo:
            pend["avisado"] = tipo
            log.info("%s: %s", clave, texto)
            self.al_evento("pantalla_" + tipo, telefono, clave, texto)

    def elegir(self, archivo: str, decision: str) -> None:
        """Desde la PC: «Enviar sin confirmar» o «No enviar»."""
        with self.lock:
            self.decisiones[archivo] = decision

    def enviada(self, telefono: str) -> None:
        """Tras enviar el WhatsApp: agradecimiento y, 20 s después, pantalla en blanco."""
        try:
            local = "0" + telefono[3:] if telefono.startswith("593") else telefono
            datos = dict(self.enviado or {})
            datos["telefono"] = local
            self.pagina.poner("gracias", datos)
            self.fase_enviada, self.enviado = "gracias", datos

            def limpiar():
                if self.fase_enviada == "gracias":
                    try:
                        self.pagina.poner("vacio")
                        self.fase_enviada, self.enviado = "vacio", None
                    except Exception:  # noqa: BLE001
                        pass
            threading.Timer(20, limpiar).start()
        except Exception as e:  # noqa: BLE001
            log.debug("Pantalla del cliente: %s", e)
