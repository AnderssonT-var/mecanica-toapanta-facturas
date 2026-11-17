"""
Facturación desde el celular — agente de la PC
==============================================

Hace la factura en FactuSYS igual que una persona (los mismos pasos del video
de Mecánica Toapanta):

  1. Abre FactuSYS y hace clic en el perfil pedido desde el celular (Nancy / Nelson).
  2. Ventas → Factura → Factura comercial, cierra el «Listado de clientes».
  3. Escribe el RUC/cédula + Enter (FactuSYS trae nombre y dirección del SRI),
     y llena dirección, teléfono y correo.
  4. Nuevo → producto 00038 + Enter → Aceptar el aviso → precio (y IVA) → Guardar.
  5. Cerrar formulario → Grabar Datos → Aceptar. FactuSYS firma, consulta al SRI
     y autoriza en el momento.
  6. Cuando aparece la factura en pantalla: Guardar → la guarda en el ESCRITORIO como
     <celular>_<número>.pdf → el Monitor la sube al portal y la envía por WhatsApp.
  7. Cierra FactuSYS. La siguiente factura vuelve a abrirlo desde cero.

Reglas de seguridad:
  * Nunca escribe en las tablas de FactuSYS ni modifica el programa (solo las lee
    para confirmar lo que pasó).
  * Si no puede confirmar que la factura se creó, NO la repite: la marca
    "Revisar en FactuSYS" para que una persona decida.
  * Solo usa el mouse cuando nadie ha usado la PC por un rato y FactuSYS está
    cerrado, y avisa antes con una cuenta regresiva (mover el mouse la cancela).
"""

from __future__ import annotations

import configparser
import json
import logging
import os
import re
import shutil
import subprocess
import sys
import threading
import time
from pathlib import Path

import requests

import monitor_facturas as core
from dbf_lector import leer_dbf

log = logging.getLogger("facturas")

VERSION = "3.6"
ESTADO_FILE = core.DATA_DIR / "facturador.json"
TRABAJO_DIR = core.DATA_DIR / "facturador"
ITEM_POR_DEFECTO = "00038"
DETALLE_POR_DEFECTO = "VENTA DE REPUESTOS Y MANTENIMIENTO"

# Puntos que se calibran, en el orden en que se usan (posición en la pantalla
# con FactuSYS del tamaño en que se abre; NO se maximiza porque se traba). Nombre, texto para la persona.
PUNTOS = [
    ("perfil1", "Inicio: botón del perfil de NANCY"),
    ("perfil2", "Inicio: botón del perfil de NELSON"),
    ("boton_factura", "Botón «Factura»"),
    ("factura_comercial", "Opción «Factura comercial»"),
    ("cerrar_listado", "La X del «Listado de clientes»"),
    ("boton_nuevo", "Botón «Nuevo»"),
    ("campo_producto", "Nuevo registro: campo «Producto»"),
    ("campo_descripcion", "Nuevo registro: cuadro «Descripción»"),
    ("campo_precio", "Nuevo registro: «Precio Unitario»"),
    ("guardar_item", "Nuevo registro: botón «Guardar»"),
    ("cerrar_formulario", "Botón «Cerrar formulario»"),
    ("grabar_datos", "Terminar transacción: «Grabar Datos»"),
    ("salir_sin_grabar", "Terminar transacción: «Salir sin Grabar»"),
    ("cerrar_respuesta", "Respuesta del SRI (AUTORIZADO): botón «Cerrar»"),
    ("vista_guardar", "Factura en pantalla: botón «Guardar»"),
    ("vista_cerrar", "Factura en pantalla: botón «Cerrar» (la X roja)"),
]

# Pasos por bloque. «?condición» al inicio hace el paso solo si se cumple.
# Datos disponibles para escribir: {ruc} {nombre} {direccion} {telefono} {email}
# {detalle} {precio} {item}
RECETA_POR_DEFECTO = {
    "inicio": "espera 8; clic perfil{perfil}; espera 15; anclar; espera 1",
    # El perfil ya entra en «Ventas». Al cerrar el listado de clientes el cursor
    # queda en el RUC: se escribe directo y se avanza con Enter y TAB. FoxPro
    # salta solo al siguiente campo cuando uno se llena (RUC de 13, teléfono de
    # 10): en ese caso no se presiona Enter/TAB de más.
    "datos": ("clic boton_factura; espera 1.5; clic factura_comercial; espera 5; "
              "clic cerrar_listado; espera 2; "
              "escribir {ruc}; ?ruc_corto tecla ENTER; espera 6; "
              "?en_ruc tecla TAB; "
              "?en_nombre ?nombre borrar; ?en_nombre ?nombre escribir {nombre}; ?en_nombre tecla TAB; "
              "?direccion borrar; ?direccion escribir {direccion}; tecla TAB; "
              "?telefono borrar; ?telefono escribir {telefono}; "
              "?tabs_email tecla TAB*{tabs_email}; borrar; escribir {email}; espera 1"),
    "producto": ("clic boton_nuevo; espera 3; clic campo_producto; escribir {item}; tecla ENTER; espera 2; "
                 "tecla ENTER; espera 1.5; "
                 "?detalle clic campo_descripcion; ?detalle borrar_todo; ?detalle escribir {detalle}; "
                 "clic campo_precio; borrar; escribir {precio}; tecla TAB; espera 1; "
                 "clic guardar_item; espera 3"),
    "grabar": "clic cerrar_formulario; espera 2.5; clic grabar_datos",
    "tras_grabar": "espera 1; tecla ENTER",
    "tras_autorizar": "espera 3; tecla ENTER; espera 3; clic cerrar_respuesta",
    "guardar_pdf": "clic vista_guardar; espera 3; guardar_como; espera 3; clic vista_cerrar; espera 3",
    "abortar": "clic cerrar_formulario; espera 2.5; clic salir_sin_grabar; espera 2",
}

CONFIG_EXTRA = r"""
[facturacion]
activa = no
factusys = C:\FACTUSYS
carpeta_pdf = escritorio
enviar_sin_autorizar = si
seguir_ventana = si
cursor_tras_ruc = nombre
tabs_telefono_email = 2
ancho_ruc = 13
ancho_telefono = 10
esperar_inactividad = 60
cuenta_regresiva = 8
espera_factura = 60
espera_autorizacion = 180
espera_vista = 45
intervalo_segundos = 20
respuestas = reemplazar=si; existe=si; salir=si; *=si

[factusys_puntos]

[factusys_ventana]

[factusys_receta]
""" + "".join(f"{k} = {v}\n" for k, v in RECETA_POR_DEFECTO.items())

# Sube cuando cambian los pasos por defecto: las configs con una versión menor
# reciben los pasos nuevos (los anteriores tenían errores ya corregidos).
RECETA_VERSION = 4

# claves de la versión anterior (importación por Excel) que ya no se usan
RECETA_VIEJA = {"abrir_bloque", "elegir_archivo", "importar", "generar", "cerrar_bloque",
                "inicio_perfil1", "inicio_perfil2"}


def completar_config(cfg: configparser.ConfigParser) -> None:
    """Agrega las opciones nuevas a una config vieja sin pisar lo que ya hay."""
    extra = configparser.ConfigParser(interpolation=None)
    extra.read_string(CONFIG_EXTRA)
    if cfg.has_section("factusys_receta") and any(cfg.has_option("factusys_receta", k) for k in RECETA_VIEJA):
        cfg.remove_section("factusys_receta")         # pasos del método anterior: se reemplazan
        cfg.remove_section("factusys_puntos")
    if cfg.has_section("factusys_receta") and cfg.getint("facturacion", "receta_version", fallback=0) < RECETA_VERSION:
        for bloque, defecto in RECETA_POR_DEFECTO.items():
            cfg.set("factusys_receta", bloque, defecto)
    if cfg.has_section("facturacion") or cfg.has_section("factusys_receta"):
        if not cfg.has_section("facturacion"):
            cfg.add_section("facturacion")
        cfg.set("facturacion", "receta_version", str(RECETA_VERSION))
    # pasos de versiones anteriores que usan puntos que ya no existen → los nuevos
    validos = {n for n, _ in PUNTOS} | {"perfil{perfil}"}
    if cfg.has_section("factusys_receta"):
        for bloque, defecto in RECETA_POR_DEFECTO.items():
            texto = cfg.get("factusys_receta", bloque, fallback="")
            usados = set(re.findall(r"(?:clic|doble)\s+([\w{}]+)", texto))
            if usados - validos:
                cfg.set("factusys_receta", bloque, defecto)
    if cfg.has_section("factusys_puntos"):
        for viejo in [k for k in cfg.options("factusys_puntos") if k not in validos]:
            cfg.remove_option("factusys_puntos", viejo)
    if cfg.has_option("factusys_receta", "inicio"):
        ini = cfg.get("factusys_receta", "inicio")
        if "maximizar" in ini:                         # al maximizar FactuSYS se traba
            cfg.set("factusys_receta", "inicio", ini.replace("maximizar", "anclar"))
    for sec in extra.sections():
        if not cfg.has_section(sec):
            cfg.add_section(sec)
        for k, v in extra.items(sec):
            if not cfg.has_option(sec, k):
                cfg.set(sec, k, v)


def url_agente(cfg) -> str:
    return re.sub(r"upload\.php$", "agente.php", cfg.get("servidor", "upload_url").strip())


def celular_ec(texto: str) -> str:
    """Primer celular 09xxxxxxxx que aparezca en el texto, o ''."""
    for t in re.findall(r"\d[\d\s-]{8,}", texto or ""):
        d = re.sub(r"\D", "", t)
        if d.startswith("593") and len(d) == 12:
            d = "0" + d[3:]
        if re.fullmatch(r"09\d{8}", d):
            return d
    return ""


def escritorio() -> Path:
    """Carpeta Escritorio real de Windows (también si está en OneDrive)."""
    if sys.platform == "win32":
        try:
            import ctypes
            from ctypes import wintypes
            buf = ctypes.create_unicode_buffer(260)
            if ctypes.windll.shell32.SHGetFolderPathW(None, 0x0010, None, 0, buf) == 0 and buf.value:
                return Path(buf.value)
        except Exception:
            pass
    return Path.home() / "Desktop"


def carpeta_pdf(cfg) -> Path:
    valor = cfg.get("facturacion", "carpeta_pdf", fallback="escritorio").strip()
    return escritorio() if valor.lower() in ("", "escritorio") else Path(valor)


def numero_texto(v: float) -> str:
    return f"{float(v):.2f}"


class ErrorFactura(Exception):
    """Falla ANTES de grabar: no se creó nada, se puede reintentar."""


class Incierto(Exception):
    """Falla DESPUÉS de presionar Grabar: puede que la factura exista."""


# ---------------------------------------------------------------------------
# Datos de FactuSYS (solo lectura)
# ---------------------------------------------------------------------------

class DatosFactuSYS:
    def __init__(self, raiz):
        self.raiz = Path(raiz)

    def exe(self) -> Path:
        return self.raiz / "FactuSYS.EXE"

    def perfiles(self) -> dict[int, dict]:
        res = {}
        for f in leer_dbf(self.raiz / "FCCIAS.DBF", ["CODCIA", "NOMCIA", "RUCCIA", "CAMINO", "PATHLOGO"]):
            try:
                n = int(f["CODCIA"])
            except (TypeError, ValueError):
                continue
            camino = (f["CAMINO"] or f"PERFIL{n:02d}").strip("\\/ ")
            carpeta = self.raiz / camino
            logo = Path(f["PATHLOGO"]) if f["PATHLOGO"] else None
            if logo and not logo.is_file():
                otro = carpeta / logo.name  # por si FactuSYS se movió de carpeta
                logo = otro if otro.is_file() else None
            res[n] = {"carpeta": carpeta, "nombre": f["NOMCIA"], "ruc": f["RUCCIA"], "logo": logo}
        return res

    def carpeta(self, perfil: int) -> Path:
        p = self.perfiles().get(perfil)
        return p["carpeta"] if p else self.raiz / f"PERFIL{perfil:02d}"

    def tabla_clientes(self, perfil: int) -> Path:
        return self.carpeta(perfil) / "Cob" / "DP05A110.DBF"

    def tabla_facturas(self, perfil: int) -> Path:
        return self.carpeta(perfil) / "Inv" / "DPINVCAB.DBF"

    def clientes(self, perfil: int) -> list[dict]:
        filas = leer_dbf(self.tabla_clientes(perfil),
                         ["CODIGO", "EMPCLI", "NOMCLI", "NCOMERCIAL", "RUC", "PASAPORTE", "DIRECCION",
                          "FONO1", "FONO2", "FONO3", "EMAIL", "EMAIL2", "ESTADO"])
        res = []
        for f in filas:
            ruc = re.sub(r"\s", "", f.get("RUC") or f.get("PASAPORTE") or "")
            if not ruc:
                continue
            fonos = " ".join(f.get(k) or "" for k in ("FONO1", "FONO2", "FONO3"))
            res.append({
                "ruc": ruc, "codigo": f.get("CODIGO") or "",
                "nombre": f.get("EMPCLI") or f.get("NOMCLI") or f.get("NCOMERCIAL") or "",
                "direccion": f.get("DIRECCION") or "",
                "telefono": celular_ec(fonos) or (f.get("FONO1") or ""),
                "email": (f.get("EMAIL") or f.get("EMAIL2") or "").split(";")[0].strip(),
            })
        return res

    def facturas(self, perfil: int) -> list[dict]:
        return [f for f in leer_dbf(self.tabla_facturas(perfil),
                                    ["TIPO", "NUMERO", "PROV_CLI", "TOTAL_MOV", "TOTAL_IVA", "TOTAL_TRN",
                                     "VTA_RUC", "VTA_CLI", "VTA_FO1", "USERFEC", "USERTIM", "NAUT_SRI",
                                     "CLAVE", "ANULADA"])
                if f.get("TIPO") == "FE"]

    def ultimo_numero(self, perfil: int) -> int:
        nums = [int(f["NUMERO"]) for f in self.facturas(perfil) if (f.get("NUMERO") or "").isdigit()]
        return max(nums, default=0)

    def buscar_nueva(self, perfil: int, desde: int, ruc: str, total: float) -> dict | None:
        """La factura creada por la importación: número mayor al anterior, mismo cliente y total."""
        candidatas = []
        for f in self.facturas(perfil):
            num = int(f["NUMERO"]) if (f.get("NUMERO") or "").isdigit() else 0
            if num <= desde or f.get("ANULADA"):
                continue
            mismo_total = abs(float(f.get("TOTAL_TRN") or 0) - total) < 0.025  # «Extraer IVA» puede mover 1 centavo
            mismo_cliente = (f.get("VTA_RUC") or "").strip() in ("", ruc) or ruc.startswith((f.get("VTA_RUC") or "").strip() or "-")
            if mismo_total and mismo_cliente:
                candidatas.append(f)
        return candidatas[0] if len(candidatas) == 1 else (candidatas[-1] if candidatas else None)

    def nuevas_desde(self, perfil: int, desde: int) -> list[dict]:
        return [f for f in self.facturas(perfil) if (f.get("NUMERO") or "0").isdigit() and int(f["NUMERO"]) > desde]

    def factura(self, perfil: int, numero: str) -> dict | None:
        for f in self.facturas(perfil):
            if f.get("NUMERO") == numero:
                return f
        return None

    def xml_autorizado(self, perfil: int, clave: str) -> Path | None:
        docs = self.carpeta(perfil) / "Documentos"
        for ext in (".xml", ".XML"):
            p = docs / "Autorizados" / f"{clave}{ext}"
            if p.is_file():
                return p
        return None

    def clave_de(self, perfil: int, numero: str) -> str:
        """Clave de acceso que FactuSYS anotó para esa factura (si ya la tiene)."""
        try:
            f = self.factura(perfil, numero) or {}
        except OSError:
            return ""
        c = re.sub(r"\D", "", str(f.get("CLAVE") or ""))
        return c if len(c) == 49 else ""

    @staticmethod
    def _fecha_clave(c: str):
        import datetime as _dt
        try:
            return _dt.date(int(c[4:8]), int(c[2:4]), int(c[0:2]))
        except ValueError:
            return None

    def _buscar_xml(self, perfil, subcarpetas, numero, ruc_emisor, desde=None):
        """XML de esa factura: por la clave exacta si FactuSYS la tiene; si no, por
        RUC + secuencial y solo de fechas recientes (una factura vieja de otra serie
        con el mismo número no cuenta)."""
        import datetime as _dt
        exacta = self.clave_de(perfil, numero)
        sec = numero.zfill(9)
        minimo = (_dt.date.fromtimestamp(desde) - _dt.timedelta(days=2)) if desde else None
        for sub in subcarpetas:
            carpeta = self.carpeta(perfil) / "Documentos" / sub
            try:
                for p in carpeta.iterdir():
                    c = p.stem
                    if len(c) != 49:
                        continue
                    if exacta:
                        if c == exacta:
                            return p
                        continue
                    if c[10:23] == ruc_emisor and c[30:39] == sec and c[8:10] == "01":
                        f = self._fecha_clave(c)
                        if minimo is None or (f and f >= minimo):
                            return p
            except OSError:
                pass
        return None

    def xml_firmado(self, perfil: int, numero: str, ruc_emisor: str, desde: float | None = None) -> Path | None:
        """XML firmado que FactuSYS mandó al SRI (aunque todavía no esté autorizado)."""
        return self._buscar_xml(perfil, ("Firmados", "Generados"), numero, ruc_emisor, desde)

    def xml_rechazado(self, perfil: int, numero: str, ruc_emisor: str, desde: float | None = None) -> Path | None:
        # la clave de acceso lleva la fecha (0-8), el RUC del emisor (10-23) y el secuencial (30-39)
        return self._buscar_xml(perfil, ("Rechazados",), numero, ruc_emisor, desde)


# ---------------------------------------------------------------------------
# Control de FactuSYS en la pantalla (Windows)
# ---------------------------------------------------------------------------

TECLAS = {
    "ENTER": 0x0D, "ESC": 0x1B, "TAB": 0x09, "ESPACIO": 0x20, "ALT": 0x12, "CTRL": 0x11, "SHIFT": 0x10,
    "ARRIBA": 0x26, "ABAJO": 0x28, "IZQUIERDA": 0x25, "DERECHA": 0x27, "INICIO": 0x24, "FIN": 0x23,
    "SUPR": 0x2E, "RETROCESO": 0x08, **{f"F{i}": 0x6F + i for i in range(1, 13)},
}


class ControlFactuSYS:
    """Mouse, teclado y ventanas de FactuSYS.

    Los controles de Visual FoxPro no son ventanas de Windows, así que se hace
    clic en posiciones de la pantalla guardadas en la calibración, con FactuSYS
    del tamaño en que se abre. Si la ventana aparece en otro lugar, los puntos se
    corren lo mismo que se movió. El cuadro «Guardar como» se llena directo.
    """

    WM_SETTEXT, WM_CLOSE, BM_CLICK = 0x000C, 0x0010, 0x00F5

    def __init__(self, cfg: configparser.ConfigParser, datos: "DatosFactuSYS"):
        import ctypes
        from ctypes import wintypes as wt
        self.ct, self.wt = ctypes, wt
        self.u32 = ctypes.WinDLL("user32", use_last_error=True)
        self.cfg, self.datos = cfg, datos
        self.whats = core.EnviadorWhatsAppEscritorio.__new__(core.EnviadorWhatsAppEscritorio)
        core.EnviadorWhatsAppEscritorio.__init__(self.whats, cfg)   # reutiliza utilidades de Win32
        u = self.u32
        u.SendMessageW.argtypes = [wt.HWND, wt.UINT, wt.WPARAM, wt.LPARAM]
        u.SendMessageW.restype = ctypes.c_ssize_t
        u.PostMessageW.argtypes = [wt.HWND, wt.UINT, wt.WPARAM, wt.LPARAM]
        u.GetClassNameW.argtypes = [wt.HWND, wt.LPWSTR, ctypes.c_int]
        u.GetCursorPos.argtypes = [ctypes.POINTER(wt.POINT)]
        u.GetDlgCtrlID.argtypes = [wt.HWND]
        u.IsWindow.argtypes = [wt.HWND]
        u.IsZoomed.argtypes = [wt.HWND]
        u.GetWindowRect.argtypes = [wt.HWND, ctypes.POINTER(wt.RECT)]
        u.VkKeyScanW.argtypes = [wt.WCHAR]
        u.VkKeyScanW.restype = ctypes.c_short
        u.MapVirtualKeyW.argtypes = [wt.UINT, wt.UINT]
        u.MapVirtualKeyW.restype = wt.UINT
        self.factor = cfg.getfloat("escritorio", "factor_lentitud", fallback=1.0)
        self.respuestas = []
        for par in cfg.get("facturacion", "respuestas", fallback="*=si").split(";"):
            if "=" in par:
                k, v = par.split("=", 1)
                self.respuestas.append((k.strip().lower(), v.strip().lower()))
        self.mensajes: list[str] = []
        self.desfase = (0, 0)
        self.datos_factura: dict = {}
        self.ruta_pdf: Path | None = None

        class KEYBDINPUT(ctypes.Structure):
            _fields_ = [("wVk", wt.WORD), ("wScan", wt.WORD), ("dwFlags", wt.DWORD),
                        ("time", wt.DWORD), ("dwExtraInfo", ctypes.c_size_t)]

        class _U(ctypes.Union):
            _fields_ = [("ki", KEYBDINPUT), ("pad", ctypes.c_byte * 32)]

        class INPUT(ctypes.Structure):
            _fields_ = [("type", wt.DWORD), ("u", _U)]
        self.INPUT, self.KEYBDINPUT = INPUT, KEYBDINPUT

    # --- ventanas -------------------------------------------------------------
    def _clase(self, hwnd) -> str:
        buf = self.ct.create_unicode_buffer(256)
        self.u32.GetClassNameW(hwnd, buf, 256)
        return buf.value

    def _texto(self, hwnd) -> str:
        buf = self.ct.create_unicode_buffer(1024)
        self.u32.GetWindowTextW(hwnd, buf, 1024)
        return buf.value

    def _ventanas(self):
        res = []

        @self.ct.WINFUNCTYPE(self.wt.BOOL, self.wt.HWND, self.wt.LPARAM)
        def cada(hwnd, _):
            if self.u32.IsWindowVisible(hwnd) and self.whats._exe(hwnd) == "factusys.exe":
                res.append(hwnd)
            return True
        self.u32.EnumWindows(cada, 0)
        return res

    def _hijas(self, hwnd):
        res = []

        @self.ct.WINFUNCTYPE(self.wt.BOOL, self.wt.HWND, self.wt.LPARAM)
        def cada(h, _):
            res.append(h)
            return True
        self.u32.EnumChildWindows(hwnd, cada, 0)
        return res

    def _area(self, h) -> int:
        r = self.wt.RECT()
        self.u32.GetWindowRect(h, self.ct.byref(r))
        return (r.right - r.left) * (r.bottom - r.top)

    def principal(self):
        """La ventana más grande de FactuSYS (no los cuadros de diálogo)."""
        cand = [h for h in self._ventanas() if self._clase(h) != "#32770"]
        return max(cand, key=self._area) if cand else None

    def abierto(self) -> bool:
        return bool(self._ventanas())

    def ventana_con_titulo(self, texto: str, internas: bool = True):
        """Ventana de FactuSYS con ese texto en el título. Busca también las
        ventanas internas (los formularios de FoxPro, la vista de la factura)."""
        t = texto.lower()
        tops = self._ventanas()
        for h in tops:
            if t in self._texto(h).lower():
                return h
        if internas:
            for h in tops:
                for c in self._hijas(h):
                    if self.u32.IsWindowVisible(c) and t in self._texto(c).lower():
                        return c
        return None

    def formularios_internos(self):
        """Formularios de FoxPro abiertos dentro de FactuSYS (con título)."""
        h = self.principal()
        if not h:
            return []
        titulo_principal = self._texto(h)
        return [c for c in self._hijas(h)
                if self.u32.IsWindowVisible(c) and self._texto(c).strip() and self._texto(c) != titulo_principal]

    def dialogos(self):
        return [h for h in self._ventanas() if self._clase(h) == "#32770"]

    def al_frente(self):
        h = self.principal()
        if not h:
            raise ErrorFactura("FactuSYS no está abierto.")
        try:
            self.whats._foco(h)
        except core.ErrorEnvio:
            raise ErrorFactura("No se pudo poner FactuSYS al frente.")
        return h

    def origen(self):
        """Esquina superior izquierda de la ventana principal de FactuSYS."""
        h = self.principal()
        if not h:
            return None
        r = self.wt.RECT()
        self.u32.GetWindowRect(h, self.ct.byref(r))
        return r.left, r.top

    def anclar(self):
        """Compara dónde se abrió FactuSYS con dónde estaba al calibrar."""
        self.desfase = (0, 0)
        if self.cfg.get("facturacion", "seguir_ventana", fallback="si").lower() not in ("si", "sí", "1", "true"):
            return
        guardado = self.cfg.get("factusys_ventana", "origen", fallback="").strip()
        ahora = self.origen()
        if not ahora or not re.fullmatch(r"-?\d+,-?\d+", guardado):
            return
        gx, gy = (int(v) for v in guardado.split(","))
        dx, dy = ahora[0] - gx, ahora[1] - gy
        if abs(dx) > 1500 or abs(dy) > 1500:       # ventana escondida o minimizada: no se corre
            return
        self.desfase = (dx, dy)
        if dx or dy:
            log.info("FactuSYS se abrió %+d,%+d px corrido; se ajustan los clics.", dx, dy)

    def maximizar(self):
        h = self.principal()
        if h and not self.u32.IsZoomed(h):
            self.u32.ShowWindow(h, 3)  # SW_MAXIMIZE
            time.sleep(1.5 * self.factor)

    # --- mouse y teclado ---------------------------------------------------------
    def posicion_mouse(self) -> tuple[int, int]:
        p = self.wt.POINT()
        self.u32.GetCursorPos(self.ct.byref(p))
        return p.x, p.y

    def punto(self, nombre: str) -> tuple[int, int]:
        valor = self.cfg.get("factusys_puntos", nombre, fallback="").strip()
        if not re.fullmatch(r"-?\d+\s*,\s*-?\d+", valor):
            raise ErrorFactura(f"Falta calibrar «{nombre}».")
        x, y = (int(v) for v in valor.split(","))
        if not nombre.startswith("perfil"):
            x, y = x + self.desfase[0], y + self.desfase[1]
        return x, y

    def clic(self, nombre: str, doble=False):
        x, y = self.punto(nombre)
        self.u32.SetCursorPos(x, y)
        time.sleep(0.25)
        for _ in range(2 if doble else 1):
            self.u32.mouse_event(0x0002, 0, 0, 0, 0)
            time.sleep(0.06)
            self.u32.mouse_event(0x0004, 0, 0, 0, 0)
            time.sleep(0.12)
        time.sleep(0.3 * self.factor)

    def tecla(self, combinacion: str):
        combinacion, _, veces = combinacion.partition("*")      # «TAB*2» = dos veces
        n = int(veces) if veces.strip().isdigit() else 1
        for i in range(n):
            self._tecla_una(combinacion)
            if i < n - 1:
                time.sleep(0.25 * self.factor)

    def _tecla_una(self, combinacion: str):
        vks = []
        for parte in combinacion.upper().split("+"):
            parte = parte.strip()
            if parte in TECLAS:
                vks.append(TECLAS[parte])
            elif len(parte) == 1 and parte.isalnum():
                vks.append(ord(parte))
            else:
                raise ErrorFactura(f"Tecla desconocida en los pasos: {parte}")
        self._teclas(*vks)
        time.sleep(0.2 * self.factor)

    # Inicio, Fin, flechas, Supr, Insert, RePág/AvPág: teclas «extendidas»
    EXTENDIDAS = {0x21, 0x22, 0x23, 0x24, 0x25, 0x26, 0x27, 0x28, 0x2D, 0x2E}

    def _enviar_vk(self, vk, arriba=False):
        """Una tecla real, con su código de escaneo. Visual FoxPro lee ese código:
        sin él, Inicio/Fin/Supr llegaban como la letra «Ÿ»."""
        scan = self.u32.MapVirtualKeyW(vk, 0) & 0xFF
        flags = (0x0001 if vk in self.EXTENDIDAS else 0) | (0x0002 if arriba else 0)
        self._enviar(vk, scan, flags)

    def _teclas(self, *vks):
        for vk in vks:
            self._enviar_vk(vk)
            time.sleep(0.05)
        for vk in reversed(vks):
            self._enviar_vk(vk, arriba=True)
            time.sleep(0.05)

    def _enviar(self, vk=0, scan=0, flags=0):
        inp = self.INPUT(type=1)
        inp.u.ki = self.KEYBDINPUT(vk, scan, flags, 0, 0)
        self.u32.SendInput(1, self.ct.byref(inp), self.ct.sizeof(self.INPUT))

    def escribir(self, texto: str):
        """Escribe letra por letra con las teclas reales del teclado (FoxPro las
        entiende mejor); lo que no esté en el teclado va como carácter Unicode."""
        for ch in texto:
            r = self.u32.VkKeyScanW(ch)
            vk, estado = r & 0xFF, (r >> 8) & 0xFF
            if r == -1 or vk == 0xFF or estado & 0xF8:
                for flags in (0x0004, 0x0004 | 0x0002):    # KEYEVENTF_UNICODE, + KEYUP
                    self._enviar(0, ord(ch), flags)
            else:
                if ch.isalpha() and self.u32.GetKeyState(0x14) & 1:   # Bloq Mayús activado
                    estado ^= 1
                mods = [m for bit, m in ((1, 0x10), (2, 0x11), (4, 0x12)) if estado & bit]  # Shift, Ctrl, Alt (AltGr)
                self._teclas(*mods, vk)
            time.sleep(0.04 * self.factor)
        time.sleep(0.2 * self.factor)

    def borrar(self, todo=False):
        if todo:  # cuadro de varias líneas
            self._teclas(0x11, 0x24)            # Ctrl+Inicio
            self._teclas(0x11, 0x10, 0x23)      # Ctrl+Shift+Fin
        else:
            self._teclas(0x24)                  # Inicio
            self._teclas(0x10, 0x23)            # Shift+Fin
        self._teclas(0x2E)                      # Supr
        time.sleep(0.15)

    # --- cuadros de Windows (Guardar como, confirmaciones) ------------------------
    def _textos_dialogo(self, hwnd):
        estaticos, botones = [], []
        for c in self._hijas(hwnd):
            clase = self._clase(c)
            if clase == "Static" and self._texto(c).strip():
                estaticos.append(self._texto(c).strip())
            elif clase == "Button" and self.u32.IsWindowVisible(c):
                botones.append((c, self._texto(c).replace("&", "").strip()))
        return " ".join(estaticos), botones

    def _es_guardar_como(self, hwnd) -> bool:
        return any(self._clase(c) in ("ComboBoxEx32", "DUIViewWndClassName", "DirectUIHWND") for c in self._hijas(hwnd))

    def _respuesta(self, texto: str) -> str:
        t = texto.lower()
        for clave, resp in self.respuestas:
            if clave != "*" and clave in t:
                return resp
        return dict(self.respuestas).get("*", "si")

    def dialogo_con(self, patron: str):
        """Busca un aviso de FactuSYS (cuadro de mensaje de Windows) cuyo texto o
        título coincida. Devuelve (ventana, texto) o None."""
        for d in self.dialogos():
            if self._es_guardar_como(d):
                continue
            texto, _ = self._textos_dialogo(d)
            completo = f"{self._texto(d)} {texto}"
            if re.search(patron, completo, re.I):
                return d, completo
        return None

    def aceptar_dialogo(self, d):
        _, botones = self._textos_dialogo(d)
        boton = next((b for b, n in botones if n.lower() in ("aceptar", "ok", "sí", "si", "yes", "cerrar")),
                     botones[0][0] if botones else None)
        if boton:
            self.u32.SendMessageW(boton, self.BM_CLICK, 0, 0)
        else:
            self.u32.PostMessageW(d, self.WM_CLOSE, 0, 0)
        time.sleep(1.0 * self.factor)

    def hay_ventana(self, texto: str) -> bool:
        """¿Hay una ventana de FactuSYS (o una ventana interna) con ese título?"""
        return bool(self.ventana_con_titulo(texto))

    def enter_en(self, hwnd):
        """Enter sobre un aviso propio de FoxPro (no es un cuadro de Windows)."""
        try:
            self.whats._foco(self.principal())
        except Exception:
            pass
        try:
            self.u32.SetForegroundWindow(hwnd)
        except Exception:
            pass
        time.sleep(0.3)
        self.tecla("ENTER")
        time.sleep(1.0 * self.factor)

    def atender_dialogos(self, espera=3):
        """Contesta los cuadros de mensaje de Windows que aparezcan (p. ej. «¿Reemplazar?»)."""
        fin = time.time() + espera * self.factor
        vistos = set()
        while time.time() < fin:
            for d in self.dialogos():
                if self._es_guardar_como(d) or d in vistos:
                    continue
                texto, botones = self._textos_dialogo(d)
                resp = self._respuesta(texto)
                nombres = {"si": ("sí", "si", "yes", "aceptar", "ok"), "no": ("no",)}.get(resp, ("sí", "si", "yes", "aceptar", "ok"))
                boton = next((b for b, n in botones if n.lower() in nombres), botones[0][0] if botones else None)
                log.info("Mensaje: «%s» → %s", texto[:200], resp)
                self.mensajes.append(texto[:200])
                vistos.add(d)
                if boton:
                    self.u32.SendMessageW(boton, self.BM_CLICK, 0, 0)
                else:
                    self.u32.PostMessageW(d, self.WM_CLOSE, 0, 0)
                time.sleep(1.0 * self.factor)
            time.sleep(0.3)

    def guardar_como(self, ruta: Path, espera=30):
        """Llena el cuadro «Guardar como» con la ruta completa del PDF y presiona Guardar."""
        limite = time.time() + espera * self.factor
        while time.time() < limite:
            for d in self.dialogos():
                if not self._es_guardar_como(d):
                    continue
                edit = next((c for c in self._hijas(d) if self._clase(c) == "Edit" and self.u32.IsWindowVisible(c)), None)
                if not edit:
                    continue
                buf = self.ct.create_unicode_buffer(str(ruta))
                self.u32.SendMessageW(edit, self.WM_SETTEXT, 0, self.ct.addressof(buf))
                time.sleep(0.6)
                ok = next((c for c in self._hijas(d) if self._clase(c) == "Button" and self.u32.GetDlgCtrlID(c) == 1), None)
                if ok:
                    self.u32.SendMessageW(ok, self.BM_CLICK, 0, 0)
                else:
                    self.whats._foco(d)
                    self.tecla("ENTER")
                time.sleep(1.5 * self.factor)
                self.atender_dialogos(3)          # «ya existe, ¿reemplazar?»
                return
            time.sleep(0.5)
        raise ErrorFactura("No apareció el cuadro «Guardar como» (revisa «vista_guardar»).")

    # --- abrir / cerrar FactuSYS -------------------------------------------------
    def abrir_programa(self, espera=120):
        exe = self.datos.exe()
        if not exe.is_file():
            raise ErrorFactura(f"No se encuentra {exe}.")
        subprocess.Popen([str(exe)], cwd=str(exe.parent))
        limite = time.time() + espera * self.factor
        while time.time() < limite:
            time.sleep(2)
            if self.abierto():
                return
        raise ErrorFactura("FactuSYS no abrió a tiempo.")

    def cerrar_programa(self, espera=60):
        """Cierra FactuSYS como al presionar la X (y contesta si pregunta)."""
        if not self.abierto():
            return
        self.atender_dialogos(2)                       # avisos que hayan quedado abiertos
        if self.ventana_con_titulo("Página"):          # la factura en pantalla
            try:
                self.clic("vista_cerrar")
                time.sleep(2 * self.factor)
            except ErrorFactura:
                pass
        h = self.principal()
        for v in self._ventanas():                     # otras ventanas sueltas de FactuSYS
            if v != h and self._clase(v) != "#32770":
                self.u32.PostMessageW(v, self.WM_CLOSE, 0, 0)
        for v in self.formularios_internos():          # avisos y formularios que quedaron abiertos
            self.u32.PostMessageW(v, self.WM_CLOSE, 0, 0)
        time.sleep(1 * self.factor)
        if h:
            self.u32.PostMessageW(h, self.WM_CLOSE, 0, 0)
        limite = time.time() + espera * self.factor
        enter = time.time() + 8 * self.factor
        while time.time() < limite:
            self.atender_dialogos(2)
            if not self.abierto():
                time.sleep(2 * self.factor)
                return
            if time.time() > enter:      # un aviso propio de FactuSYS («¿Desea salir?»): Enter = Sí
                try:
                    self.al_frente()
                    self.tecla("ENTER")
                except ErrorFactura:
                    pass
                enter = time.time() + 8 * self.factor
        raise ErrorFactura("FactuSYS no se cerró solo; ciérralo a mano.")

    # --- pasos -------------------------------------------------------------------
    def receta(self, nombre: str, datos: dict | None = None):
        datos = datos or self.datos_factura
        texto = self.cfg.get("factusys_receta", nombre, fallback=RECETA_POR_DEFECTO.get(nombre, ""))
        for paso in [p.strip() for p in texto.split(";") if p.strip()]:
            cumple = True
            while paso.startswith("?"):               # «?a ?b paso»: todas deben cumplirse
                cond, _, paso = paso[1:].partition(" ")
                cumple = cumple and bool(datos.get("si_" + cond))
                paso = paso.strip()
            if not cumple:
                continue
            orden, _, arg = paso.partition(" ")
            orden, arg = orden.lower(), arg.strip()
            try:
                arg = arg.format(**{k: v for k, v in datos.items() if not k.startswith("si_")})
            except (KeyError, IndexError, ValueError):
                raise ErrorFactura(f"Paso con un dato desconocido: {paso}")
            if orden == "clic":
                self.clic(arg)
            elif orden == "doble":
                self.clic(arg, doble=True)
            elif orden == "tecla":
                self.tecla(arg)
            elif orden == "escribir":
                self.escribir(arg)
            elif orden == "borrar":
                self.borrar()
            elif orden == "borrar_todo":
                self.borrar(todo=True)
            elif orden == "espera":
                time.sleep(float(arg.replace(",", ".")) * self.factor)
            elif orden == "maximizar":
                self.maximizar()
            elif orden == "anclar":
                self.anclar()
            elif orden == "guardar_como":
                if not self.ruta_pdf:
                    raise ErrorFactura("No hay nombre para el PDF.")
                self.guardar_como(self.ruta_pdf)
            elif orden == "dialogos":
                self.atender_dialogos(float(arg) if arg else 3)
            else:
                raise ErrorFactura(f"Paso desconocido en «{nombre}»: {paso}")


# ---------------------------------------------------------------------------
# Agente
# ---------------------------------------------------------------------------

class Facturador:
    def __init__(self, cfg: configparser.ConfigParser, al_evento=None, control=None, datos=None):
        completar_config(cfg)
        self.cfg = cfg
        self.al_evento = al_evento or (lambda *a: None)
        self.detener = threading.Event()
        self.datos = datos or DatosFactuSYS(cfg.get("facturacion", "factusys"))
        self._control = control
        self.url = url_agente(cfg)
        self.clave = cfg.get("servidor", "upload_key").strip()
        self.intervalo = cfg.getfloat("facturacion", "intervalo_segundos", fallback=20)
        self.estado = self._leer_estado()
        self.ultimo_ping = 0.0
        self.ultima_sync: dict[str, float] = {}
        self.texto_estado = "iniciando"
        TRABAJO_DIR.mkdir(parents=True, exist_ok=True)

    # --- estado local ---------------------------------------------------------
    def _leer_estado(self) -> dict:
        try:
            d = json.loads(ESTADO_FILE.read_text(encoding="utf-8"))
            d.setdefault("facturas", {})
            return d
        except (OSError, ValueError):
            return {"facturas": {}}

    def _guardar(self):
        tmp = ESTADO_FILE.with_suffix(".tmp")
        tmp.write_text(json.dumps(self.estado, indent=2, ensure_ascii=False, default=str), encoding="utf-8")
        tmp.replace(ESTADO_FILE)

    @property
    def control(self) -> ControlFactuSYS:
        if self._control is None:
            if sys.platform != "win32":
                raise ErrorFactura("Controlar FactuSYS solo funciona en Windows.")
            self._control = ControlFactuSYS(self.cfg, self.datos)
        return self._control

    # --- servidor ---------------------------------------------------------------
    def api(self, accion: str, **datos) -> dict:
        r = requests.post(self.url, json={"accion": accion, **datos}, headers={"X-Upload-Key": self.clave}, timeout=60)
        if r.status_code == 401:
            raise core.ErrorSubida("El servidor rechazó la clave de subida.")
        try:
            j = r.json()
        except ValueError:
            raise core.ErrorSubida(f"Respuesta inesperada del servidor ({r.status_code}). ¿Se subió api/agente.php?")
        if r.status_code == 409:
            j["conflicto"] = True
            return j
        if not j.get("ok"):
            raise core.ErrorSubida(j.get("error") or f"Error {r.status_code}")
        return j

    def reportar(self, fid, estado: str, **extra):
        try:
            j = self.api("estado", id=int(fid), estado=estado, **extra)
            if j.get("conflicto"):
                log.info("Factura #%s fue cancelada desde el celular.", fid)
        except (core.ErrorSubida, requests.RequestException) as e:
            self.estado.setdefault("avisos", []).append({"id": int(fid), "estado": estado, **extra})
            self._guardar()
            log.warning("No se pudo avisar al servidor (%s); se reintenta luego.", e)

    def _reenviar_avisos(self):
        pendientes = self.estado.get("avisos") or []
        if not pendientes:
            return
        self.estado["avisos"] = []
        self._guardar()
        for a in pendientes:
            a = dict(a)
            self.reportar(a.pop("id"), a.pop("estado"), **a)

    def ping(self, forzar=False):
        if not forzar and time.time() - self.ultimo_ping < 60:
            return
        self.api("ping", version=VERSION, estado=self.texto_estado[:100])
        self.ultimo_ping = time.time()

    def sincronizar_clientes(self, forzar=False):
        for n in self.datos.perfiles():
            tabla = self.datos.tabla_clientes(n)
            try:
                marca = f"{tabla.stat().st_mtime}:{tabla.stat().st_size}"
            except OSError:
                continue
            clave = str(n)
            if not forzar and self.estado.get("clientes_marca", {}).get(clave) == marca \
                    and time.time() - self.ultima_sync.get(clave, 0) < 6 * 3600:
                continue
            if not forzar and time.time() - self.ultima_sync.get(clave, 0) < 300:
                continue
            lista = self.datos.clientes(n)
            j = self.api("clientes", perfil=n, clientes=lista)
            self.estado.setdefault("clientes_marca", {})[clave] = marca
            self.ultima_sync[clave] = time.time()
            self._guardar()
            log.info("Clientes del perfil %02d enviados a la página: %s", n, j.get("guardados"))

    # --- uso de la PC -------------------------------------------------------------
    def pc_libre(self) -> bool:
        if self._control is None and sys.platform != "win32":
            return True
        segundos = self.cfg.getfloat("facturacion", "esperar_inactividad", fallback=60)
        return self.control.whats._segundos_inactivo() >= segundos

    def cuenta_regresiva(self) -> bool:
        """Avisa en pantalla; si alguien mueve el mouse o el teclado, se cancela."""
        seg = self.cfg.getint("facturacion", "cuenta_regresiva", fallback=8)
        if seg <= 0:
            return True
        self.al_evento("aviso", f"Facturando desde el celular en {seg} s… mueve el mouse para dejarlo para después.", seg)
        inicio = self.control.whats._segundos_inactivo()
        t0 = time.time()
        while time.time() - t0 < seg:
            time.sleep(0.3)
            if self.control.whats._segundos_inactivo() < (time.time() - t0) + inicio - 1:
                self.al_evento("aviso", "", 0)
                log.info("Alguien usó la PC: la factura queda para después.")
                return False
        self.al_evento("aviso", "", 0)
        return True

    # --- abrir y cerrar FactuSYS ---------------------------------------------------
    def abrir_con_perfil(self, perfil: int):
        """Abre FactuSYS desde cero y entra con el perfil (Nancy = 1, Nelson = 2)."""
        if self.control.abierto():
            raise ErrorFactura("FactuSYS ya estaba abierto.")
        log.info("Abriendo FactuSYS con el perfil %02d…", perfil)
        self.control.abrir_programa()
        self.control.receta("inicio", {"perfil": perfil})
        if not self.control.abierto():
            raise ErrorFactura(f"FactuSYS no quedó abierto con el perfil {perfil:02d}.")

    def cerrar(self):
        try:
            if self.control.abierto():
                log.info("Cerrando FactuSYS.")
                self.control.cerrar_programa()
        except ErrorFactura as e:
            log.warning("%s", e)
            self.al_evento("error", "", "FactuSYS", str(e))

    # --- una factura -----------------------------------------------------------------
    def datos_para_pasos(self, f: dict) -> dict:
        detalle = (f.get("detalle") or "").strip().upper()
        origen = f.get("origen") or ("factusys" if not f.get("cliente_nuevo") else "nuevo")
        con_iva = bool(f.get("con_iva"))
        cursor = self.cfg.get("facturacion", "cursor_tras_ruc", fallback="nombre").strip().lower()
        try:
            tabs_email = max(0, min(6, self.cfg.getint("facturacion", "tabs_telefono_email", fallback=2)))
        except ValueError:
            tabs_email = 2
        telefono = (re.findall(r"\d{6,}", f.get("telefono") or "") or [""])[0]   # un solo número
        ancho_ruc = self.cfg.getint("facturacion", "ancho_ruc", fallback=13)
        ancho_tel = self.cfg.getint("facturacion", "ancho_telefono", fallback=10)
        telefono = telefono[:ancho_tel]
        if telefono and len(telefono) >= ancho_tel:
            tabs_email -= 1          # el teléfono llenó su campo y FoxPro ya pasó al siguiente
        return {
            "ruc": f["ruc"], "nombre": (f.get("nombre") or "").upper(),
            "direccion": (f.get("direccion") or "").upper(), "telefono": telefono,
            "email": (f.get("email") or "").lower(), "detalle": detalle or DETALLE_POR_DEFECTO,
            # con IVA se escribe el total y FactuSYS separa el IVA con «Extraer IVA»
            "precio": numero_texto(f["total"]), "item": ITEM_POR_DEFECTO,
            "si_nombre": origen == "nuevo" and bool(f.get("nombre")),
            "si_direccion": origen != "factusys" and bool(f.get("direccion")),
            "si_telefono": bool(telefono),
            "si_en_ruc": cursor == "ruc",
            "si_ruc_corto": len(str(f["ruc"])) < ancho_ruc,
            "si_tabs_email": tabs_email > 0,
            "si_en_nombre": cursor in ("ruc", "nombre"),
            "tabs_email": str(tabs_email),
            "si_detalle": bool(detalle) and detalle != DETALLE_POR_DEFECTO,
            "si_con_iva": con_iva, "si_sin_iva": not con_iva,
        }

    def ruta_pdf(self, telefono: str, numero: str) -> Path:
        if telefono:
            return carpeta_pdf(self.cfg) / f"{telefono}_{numero}.pdf"
        # sin celular: carpeta aparte, para que el Monitor no la tome como un envío fallido
        return carpeta_pdf(self.cfg) / "Facturas sin celular" / f"FACTURA_{numero}.pdf"

    def facturar(self, f: dict):
        fid = str(f["id"])
        perfil = int(f["perfil"])
        reg = {"id": f["id"], "perfil": perfil, "ruc": f["ruc"], "total": f["total"], "telefono": f["telefono"],
               "nombre": f["nombre"], "fase": "tomada", "inicio": time.time()}
        self.estado["facturas"][fid] = reg
        self._guardar()
        log.info("Factura #%s desde el celular: %s, $%.2f (perfil %02d)", fid, f["nombre"] or f["ruc"], f["total"], perfil)
        ctl = self.control
        ctl.mensajes = []
        ctl.datos_factura = self.datos_para_pasos(f)
        ctl.ruta_pdf = None

        with core.ENVIO_LOCK:
            try:
                self.abrir_con_perfil(perfil)
                ctl.al_frente()
                reg["antes"] = self.datos.ultimo_numero(perfil)
                reg["antes_otros"] = {str(n): self.datos.ultimo_numero(n) for n in self.datos.perfiles() if n != perfil}
                self._guardar()
                try:
                    ctl.receta("datos")
                    ctl.receta("producto")
                except ErrorFactura:
                    try:
                        ctl.receta("abortar")         # Cerrar formulario → Salir sin Grabar
                    except Exception:
                        pass
                    raise

                reg["fase"] = "grabando"              # desde aquí puede que la factura exista
                self._guardar()
                ctl.receta("grabar")
                fila, aviso_visto = self.esperar_grabada(fid)
                if fila is None:
                    return
                if not aviso_visto:
                    ctl.receta("tras_grabar")         # Aceptar «Transacción procesada normalmente»
                autorizada = self.seguir_autorizacion(fid)
                self.guardar_pdf(fid, autorizada)
            except Incierto as e:
                log.warning("Factura #%s: %s", fid, e)
                if reg.get("fase") == "grabando":
                    self.esperar_factura(fid, nota=str(e))
            except ErrorFactura:
                raise
            except Exception as e:                     # error inesperado: queda anotado y se sigue
                log.exception("Factura #%s: error inesperado", fid)
                if reg.get("fase") in ("grabando", "registrada"):
                    reg["fase"] = "revisar"
                    self._guardar()
                    self.reportar(fid, "revisar", numero=reg.get("numero", ""),
                                  mensaje=f"Error del programa después de grabar ({e}). Revisa en FactuSYS."[:400])
                else:
                    raise ErrorFactura(f"Error del programa: {e}")
            finally:
                self.cerrar()
        self.revisar_autorizaciones()

    def _buscar_nueva(self, reg) -> dict | None:
        try:
            return self.datos.buscar_nueva(reg["perfil"], reg["antes"], reg["ruc"], float(reg["total"]))
        except (OSError, ValueError, KeyError) as e:      # tabla ocupada por FactuSYS: se reintenta
            log.debug("No se pudo leer la tabla de facturas: %s", e)
            return None

    def _ventana(self, titulo):
        try:
            return self.control.ventana_con_titulo(titulo)
        except AttributeError:
            return None

    def _dialogo(self, patron):
        try:
            return self.control.dialogo_con(patron)
        except AttributeError:
            return None

    def esperar_grabada(self, fid: str) -> tuple[dict | None, bool]:
        """Tras «Grabar Datos»: el aviso «Transacción procesada normalmente FE-…»
        trae el número; la tabla de FactuSYS lo confirma. Devuelve (fila, aviso_aceptado)."""
        reg = self.estado["facturas"][fid]
        limite = time.time() + self.cfg.getfloat("facturacion", "espera_factura", fallback=60)
        numero, fila, aceptado = None, None, False
        while time.time() < limite and not self.detener.is_set():
            d = self._dialogo(r"procesada|FE-\s*\d")
            if d:
                m = re.search(r"FE-?\s*0*(\d+)", d[1])
                log.info("Aviso de FactuSYS: «%s»", d[1].strip()[:150])
                if m:
                    numero = m.group(1).zfill(8)
                self.control.aceptar_dialogo(d[0])
                aceptado = True
            elif not aceptado:
                v = self._ventana("Aviso")
                if v:                                   # aviso propio de FoxPro: Enter
                    log.info("Aviso de FactuSYS (formulario): Enter")
                    self.control.enter_en(v)
                    aceptado = True
            fila = fila or self._buscar_nueva(reg)
            if aceptado and (fila or numero):
                break
            if fila and time.time() > limite - self.cfg.getfloat("facturacion", "espera_factura", fallback=60) + 15:
                break                                   # la tabla ya la tiene y el aviso no es de Windows
            time.sleep(1)
        if fila is None and numero:
            # la tabla aún no la muestra, pero FactuSYS dijo el número: vale
            fila = {"NUMERO": numero, "TOTAL_TRN": reg["total"], "VTA_RUC": reg["ruc"]}
            log.info("Número tomado del aviso de FactuSYS: %s", numero)
        if fila is None:
            return self.esperar_factura(fid), aceptado
        reg.update(fase="registrada", numero=fila["NUMERO"], registrada=time.time())
        self._guardar()
        self.reportar(fid, "registrada", numero=fila["NUMERO"], mensaje="")
        log.info("✓ Factura #%s grabada en FactuSYS con el número %s", fid, fila["NUMERO"])
        self.al_evento("factura", reg["telefono"], fila["NUMERO"], reg["nombre"])
        return fila, aceptado

    def seguir_autorizacion(self, fid: str) -> bool:
        """Atiende los avisos que siguen (firma, «Correo enviado», respuesta del SRI)
        hasta que aparece la factura en pantalla. Devuelve si quedó autorizada."""
        reg = self.estado["facturas"][fid]
        ctl = self.control
        limite = time.time() + self.cfg.getfloat("facturacion", "espera_autorizacion", fallback=180)
        autorizada, respuesta, avisos, vista = False, False, 0, False
        while time.time() < limite and not self.detener.is_set():
            if ctl.ventana_con_titulo("Página"):
                vista = True
                break                                   # ya está la factura en pantalla
            d = self._dialogo(r".")
            if d:
                texto = d[1]
                log.info("Aviso de FactuSYS: «%s»", texto.strip()[:200])
                ctl.mensajes.append(texto.strip()[:200])
                if re.search(r"\bAUTORIZAD[OA]\b", texto, re.I) and not re.search(r"NO\s+AUTORIZAD", texto, re.I):
                    autorizada = True
                if re.search(r"respuesta|autoriz|devuelt|rechaz", texto, re.I):
                    respuesta = True
                ctl.aceptar_dialogo(d[0])
                avisos += 1
                continue
            v = self._ventana("Aviso")
            if v and avisos < 6:                        # aviso propio de FoxPro («Correo enviado»…): Enter
                log.info("Aviso de FactuSYS (formulario): Enter")
                ctl.enter_en(v)
                avisos += 1
                continue
            try:
                if ctl.hay_ventana("Respuesta"):        # ventana propia de FactuSYS con botón «Cerrar»
                    try:
                        texto_v = " ".join(ctl._texto(c) for c in ctl._hijas(ctl.principal() or 0))
                    except Exception:
                        texto_v = ""
                    if "AUTORIZADO" in texto_v.upper() and "NO AUTORIZADO" not in texto_v.upper():
                        autorizada = True
                    ctl.clic("cerrar_respuesta")
                    respuesta = True
                    time.sleep(2)
                    continue
            except (AttributeError, ErrorFactura):
                pass
            try:
                f = self.datos.factura(reg["perfil"], reg["numero"])
                if f and f.get("NAUT_SRI"):
                    reg["autorizacion"] = f["NAUT_SRI"]
                    autorizada = True
            except (OSError, ValueError):
                pass
            time.sleep(1.5)
        if not avisos and not respuesta and not vista:
            # no se vio ningún aviso de Windows: los pasos de siempre (Enter + «Cerrar»)
            ctl.receta("tras_autorizar")
            try:
                f = self.datos.factura(reg["perfil"], reg["numero"])
                autorizada = autorizada or bool(f and f.get("NAUT_SRI"))
            except (OSError, ValueError):
                pass
        if autorizada:
            self._guardar()
            log.info("Factura %s autorizada por el SRI.", reg["numero"])
        else:
            log.warning("Factura %s: sin autorización del SRI todavía; se envía igual.", reg["numero"])
        return autorizada

    def esperar_factura(self, fid: str, nota: str = "") -> dict | None:
        """Espera a que la factura aparezca en las tablas de FactuSYS. Si no aparece: revisar."""
        reg = self.estado["facturas"][fid]
        limite = time.time() + self.cfg.getfloat("facturacion", "espera_factura", fallback=60)
        nueva = None
        while time.time() < limite and not self.detener.is_set():
            nueva = self._buscar_nueva(reg)
            if nueva:
                break
            time.sleep(2)
        if not nueva:
            try:
                otras = self.datos.nuevas_desde(reg["perfil"], reg["antes"])
            except OSError:
                otras = []
            if otras:
                detalle = ", ".join(f"{o['NUMERO']} por ${float(o.get('TOTAL_TRN') or 0):.2f}" for o in otras)
                motivo = f"Se grabó otra factura ({detalle}) que no coincide en cliente o valor. Revísala en FactuSYS."
            else:
                motivo = "No apareció la factura en FactuSYS (no se grabó)."
                for n, desde in (reg.get("antes_otros") or {}).items():
                    if self.datos.nuevas_desde(int(n), int(desde)):
                        motivo = (f"Parece que se grabó en el perfil {int(n):02d} y no en el {reg['perfil']:02d}. "
                                  "Revisa en FactuSYS y anúlala si está mal.")
            if nota:
                motivo = f"{nota} {motivo}"
            reg["fase"] = "revisar"
            self._guardar()
            self.reportar(fid, "revisar", mensaje=motivo[:400])
            self.al_evento("error", reg["telefono"], f"Factura #{fid}", motivo)
            log.error("Factura #%s: %s", fid, motivo)
            return None
        reg.update(fase="registrada", numero=nueva["NUMERO"], registrada=time.time())
        self._guardar()
        self.reportar(fid, "registrada", numero=nueva["NUMERO"], mensaje="")
        log.info("✓ Factura #%s grabada en FactuSYS con el número %s", fid, nueva["NUMERO"])
        self.al_evento("factura", reg["telefono"], nueva["NUMERO"], reg["nombre"])
        return nueva

    def esperar_autorizacion(self, fid: str) -> bool:
        reg = self.estado["facturas"][fid]
        limite = time.time() + self.cfg.getfloat("facturacion", "espera_autorizacion", fallback=180)
        while time.time() < limite and not self.detener.is_set():
            fila = self.datos.factura(reg["perfil"], reg["numero"])
            if fila and fila.get("NAUT_SRI"):
                reg["autorizacion"] = fila["NAUT_SRI"]
                self._guardar()
                log.info("Factura %s autorizada por el SRI.", reg["numero"])
                return True
            time.sleep(2)
        log.warning("Factura %s: el SRI no respondió a tiempo; se autoriza al volver a abrir FactuSYS.", reg["numero"])
        return False

    def guardar_pdf(self, fid: str, autorizada: bool):
        """Guarda el PDF desde la vista de FactuSYS en el Escritorio (o deja que el respaldo lo haga)."""
        reg = self.estado["facturas"][fid]
        ctl = self.control
        espera = self.cfg.getfloat("facturacion", "espera_vista", fallback=45)
        limite = time.time() + espera
        vista = None
        while time.time() < limite and not self.detener.is_set():
            vista = ctl.ventana_con_titulo("Página")
            if vista:
                break
            time.sleep(1.5)
        if not vista:
            log.warning("No apareció la factura en pantalla; el PDF se arma desde el XML autorizado.")
            return
        if not autorizada and not self.enviar_sin_autorizar():
            ctl.clic("vista_cerrar")
            return
        ruta = self.ruta_pdf(reg.get("telefono") or "", reg["numero"])
        ruta.parent.mkdir(parents=True, exist_ok=True)
        ctl.ruta_pdf = ruta
        try:
            ctl.receta("guardar_pdf")
        except ErrorFactura as e:
            log.warning("No se pudo guardar el PDF desde FactuSYS (%s); se arma desde el XML.", e)
            return
        for _ in range(20):
            if ruta.is_file() and ruta.stat().st_size > 1000:
                break
            time.sleep(0.5)
        if ruta.is_file():
            reg["pdf_factusys"] = str(ruta)
            self._guardar()
            log.info("PDF guardado en %s", ruta)

    # --- autorización y entrega ------------------------------------------------------
    def enviar_sin_autorizar(self) -> bool:
        return self.cfg.getboolean("facturacion", "enviar_sin_autorizar", fallback=True)

    def entregar_pendiente(self, fid: str, reg: dict) -> bool:
        """Envía la factura grabada aunque el SRI todavía no la autorice."""
        tel = reg.get("telefono") or ""
        ruta = Path(reg["pdf_factusys"]) if reg.get("pdf_factusys") else self.ruta_pdf(tel, reg["numero"])
        if not (ruta.is_file() and ruta.stat().st_size > 1000):
            ruc_emisor = self.datos.perfiles().get(reg["perfil"], {}).get("ruc", "")
            xml = self.datos.xml_firmado(reg["perfil"], reg["numero"], ruc_emisor, reg.get("inicio"))
            if not xml:
                return False
            import ride_pdf
            ride_pdf.generar_ride(xml, ruta, self.datos.perfiles().get(reg["perfil"], {}).get("logo"), permitir_pendiente=True)
            log.info("PDF armado desde el XML firmado (autorización pendiente): %s", ruta)
        entrada = Path(self.cfg.get("carpetas", "entrada"))
        if tel and ruta.parent.resolve() != entrada.resolve():
            entrada.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ruta, entrada / ruta.name)
        reg.update(fase="entregada" if tel else "pendiente_sri", pdf=ruta.name, pendiente_sri=True)
        self._guardar()
        self.reportar(fid, "registrada", numero=reg["numero"],
                      mensaje="Enviándose por WhatsApp; el SRI todavía no la autoriza." if tel
                      else "Grabada; el SRI todavía no la autoriza.")
        log.info("Factura %s enviada sin esperar al SRI (%s)", reg["numero"], ruta.name)
        return True

    def entregar(self, fid: str, reg: dict, clave: str):
        """Deja el PDF autorizado en el Escritorio y, si hace falta, en la carpeta del Monitor."""
        tel = reg.get("telefono") or ""
        ruta = Path(reg["pdf_factusys"]) if reg.get("pdf_factusys") else self.ruta_pdf(tel, reg["numero"])
        if not (ruta.is_file() and ruta.stat().st_size > 1000):
            xml = self.datos.xml_autorizado(reg["perfil"], clave)
            if not xml:
                return False
            import ride_pdf
            perfiles = self.datos.perfiles()
            ride_pdf.generar_ride(xml, ruta, perfiles.get(reg["perfil"], {}).get("logo"))
            log.info("PDF armado desde el XML autorizado: %s", ruta)
        entrada = Path(self.cfg.get("carpetas", "entrada"))
        if tel and ruta.parent.resolve() != entrada.resolve():
            entrada.mkdir(parents=True, exist_ok=True)
            shutil.copy2(ruta, entrada / ruta.name)        # el Monitor lo sube y lo envía
        reg.update(fase="entregada" if tel else "terminada", pdf=ruta.name, autorizacion=clave)
        self._guardar()
        if tel:
            self.reportar(fid, "autorizada", autorizacion=clave, mensaje="")
            log.info("Factura %s autorizada: PDF listo para WhatsApp (%s)", reg["numero"], ruta.name)
        else:
            self.reportar(fid, "sin_envio", autorizacion=clave, mensaje="Autorizada. FactuSYS la envió al correo.")
        return True

    def revisar_autorizaciones(self):
        enviadas = Path(self.cfg.get("carpetas", "enviadas"))
        errores = Path(self.cfg.get("carpetas", "errores"))
        perfiles = self.datos.perfiles()
        for fid, reg in list(self.estado["facturas"].items()):
            fase = reg.get("fase")
            ruc_emisor = perfiles.get(reg["perfil"], {}).get("ruc", "")
            clave = ""
            if fase in ("registrada", "entregada", "enviada_pend", "pendiente_sri"):
                fila = self.datos.factura(reg["perfil"], reg["numero"])
                clave = (fila or {}).get("NAUT_SRI") or ""
                if not clave and self.datos.xml_rechazado(reg["perfil"], reg["numero"], ruc_emisor, reg.get("inicio")):
                    ya = fase in ("entregada", "enviada_pend")
                    reg["fase"] = "rechazada"
                    self._guardar()
                    self.reportar(fid, "revisar", mensaje="El SRI rechazó la factura" + (" que ya se envió al cliente" if ya else "")
                                  + ". Revísala en FactuSYS (SRI → Rechazados).")
                    continue
            if fase == "registrada":
                if clave:
                    if not self.entregar(fid, reg, clave):
                        log.warning("Factura %s autorizada pero aún no está su XML; se reintenta.", reg["numero"])
                elif self.enviar_sin_autorizar():
                    if not self.entregar_pendiente(fid, reg):
                        log.warning("Factura %s: aún no hay PDF ni XML firmado; se reintenta.", reg["numero"])
            elif fase in ("enviada_pend", "pendiente_sri"):
                if clave:                          # el SRI ya la autorizó
                    reg.update(fase="terminada", pendiente_sri=False, autorizacion=clave)
                    self._guardar()
                    self.reportar(fid, "enviada" if fase == "enviada_pend" else "sin_envio", autorizacion=clave,
                                  mensaje="" if fase == "enviada_pend" else "Autorizada. FactuSYS la envió al correo.")
                    log.info("Factura %s autorizada por el SRI después de enviarla.", reg["numero"])
            elif fase == "entregada":
                if clave and reg.get("pendiente_sri"):
                    reg.update(pendiente_sri=False, autorizacion=clave)
                    self._guardar()
                stem = Path(reg["pdf"]).stem
                if any(p.name.startswith(stem) for p in self._listar(enviadas)):
                    if reg.get("pendiente_sri"):
                        reg["fase"] = "enviada_pend"
                        self._guardar()
                        self.reportar(fid, "enviada_pend", mensaje="")
                    else:
                        reg["fase"] = "terminada"
                        self._guardar()
                        self.reportar(fid, "enviada", autorizacion=reg.get("autorizacion", ""), mensaje="")
                elif any(p.name.startswith(stem) for p in self._listar(errores)):
                    reg["fase"] = "terminada"
                    self._guardar()
                    self.reportar(fid, "sin_envio", mensaje="Autorizada, pero no se pudo enviar por WhatsApp. Revisa la carpeta «Con error».")
        for fid, reg in list(self.estado["facturas"].items()):
            if reg.get("fase") in ("terminada", "rechazada", "revisar", "error") and time.time() - reg.get("inicio", 0) > 7 * 86400:
                self.estado["facturas"].pop(fid)
        self._guardar()

    @staticmethod
    def _listar(carpeta: Path):
        try:
            return list(carpeta.iterdir())
        except OSError:
            return []

    def autorizar_pendientes(self):
        """Si el SRI no respondió al momento: abrir FactuSYS (autoriza lo pendiente al abrir) y cerrarlo."""
        pendientes = [r for r in self.estado["facturas"].values()
                      if r.get("fase") == "registrada" or (r.get("pendiente_sri") and r.get("fase") in ("entregada", "enviada_pend", "pendiente_sri"))]
        if not pendientes or time.time() - self.estado.get("ultimo_reintento", 0) < 900:
            return
        if self.control.abierto() or not (self.pc_libre() and self.cuenta_regresiva()):
            return
        self.estado["ultimo_reintento"] = time.time()
        self._guardar()
        por_perfil: dict[int, list[str]] = {}
        for r in pendientes:
            por_perfil.setdefault(int(r["perfil"]), []).append(r["numero"])
        with core.ENVIO_LOCK:
            for perfil, numeros in por_perfil.items():
                try:
                    self.abrir_con_perfil(perfil)
                    limite = time.time() + self.cfg.getfloat("facturacion", "espera_autorizacion", fallback=180)
                    while time.time() < limite and not self.detener.is_set():
                        if all((self.datos.factura(perfil, n) or {}).get("NAUT_SRI") for n in numeros):
                            break
                        time.sleep(3)
                except ErrorFactura as e:
                    log.warning("No se pudo reintentar la autorización: %s", e)
                finally:
                    self.cerrar()
        self.revisar_autorizaciones()

    # --- recuperación tras apagar la PC ---------------------------------------------
    def retomar(self):
        """Facturas que el servidor tiene como 'procesando' pero esta PC no terminó."""
        # versiones anteriores marcaban «rechazada» si había CUALQUIER rechazo viejo con
        # el mismo número (de otra serie): se vuelve a revisar con la regla nueva
        perfiles = self.datos.perfiles()
        for fid, reg in self.estado["facturas"].items():
            if reg.get("fase") == "rechazada" and reg.get("numero") and not reg.get("rechazo_revisado"):
                reg["rechazo_revisado"] = True
                ruc_emisor = perfiles.get(reg["perfil"], {}).get("ruc", "")
                try:
                    sigue = self.datos.xml_rechazado(reg["perfil"], reg["numero"], ruc_emisor, reg.get("inicio"))
                except OSError:
                    sigue = True
                if not sigue:
                    reg["fase"] = "registrada"
                    log.info("Factura %s: el rechazo era de otra factura con el mismo número; se envía.", reg["numero"])
        self._guardar()
        try:
            activas = self.api("activas").get("facturas", [])
        except (core.ErrorSubida, requests.RequestException):
            return
        for f in activas:
            fid = str(f["id"])
            reg = self.estado["facturas"].get(fid)
            if f["estado"] != "procesando":
                if reg is None and f["estado"] == "registrada" and f.get("numero"):
                    self.estado["facturas"][fid] = {"id": f["id"], "perfil": f["perfil"], "numero": f["numero"],
                                                     "telefono": f["telefono"], "nombre": f["nombre"],
                                                     "fase": "registrada", "inicio": time.time()}
                continue
            if reg is None or reg.get("fase") == "tomada":
                self.reportar(fid, "error", mensaje="La PC se reinició antes de grabar. Toca «Reintentar».")
            elif reg.get("fase") == "grabando":
                nueva = self._buscar_nueva(reg)
                if nueva:
                    reg.update(fase="registrada", numero=nueva["NUMERO"], registrada=time.time())
                    self.reportar(fid, "registrada", numero=nueva["NUMERO"])
                else:
                    reg["fase"] = "revisar"
                    self.reportar(fid, "revisar", mensaje="La PC se interrumpió mientras grababa. Revisa en FactuSYS si se creó.")
        self._guardar()

    # --- prueba sin facturar -----------------------------------------------------------
    def probar_pasos(self, perfil: int, datos_prueba: dict) -> list[str]:
        """Hace todo hasta antes de «Grabar Datos» y sale SIN GRABAR. No crea facturas."""
        pasos = []
        ctl = self.control
        ctl.datos_factura = self.datos_para_pasos({"total": 1.0, "con_iva": False, "origen": "factusys", **datos_prueba})
        with core.ENVIO_LOCK:
            try:
                self.abrir_con_perfil(perfil)
                pasos.append(f"FactuSYS abierto con el perfil {perfil:02d}")
                ctl.receta("datos")
                pasos.append("Datos del cliente escritos")
                ctl.receta("producto")
                pasos.append("Producto agregado")
                ctl.receta("abortar")
                pasos.append("Formulario cerrado SIN GRABAR")
            finally:
                self.cerrar()
                pasos.append("FactuSYS cerrado" if not ctl.abierto() else "FactuSYS sigue abierto: ciérralo a mano")
        return pasos

    # --- bucle -------------------------------------------------------------------------
    def una_vuelta(self):
        self._reenviar_avisos()
        self.ping()
        try:
            self.sincronizar_clientes()
        except (OSError, ValueError) as e:
            log.warning("No se pudieron leer los clientes de FactuSYS: %s", e)
        self.revisar_autorizaciones()

        if any(r.get("fase") in ("tomada", "grabando") for r in self.estado["facturas"].values()):
            return
        if not self.pc_libre():
            self.texto_estado = "esperando que no usen la PC"
            return
        if self.control.abierto():
            self.texto_estado = "FactuSYS está abierto: espera a que lo cierren"
            return
        if not self.api("hay").get("cola"):
            self.texto_estado = "listo"
            self.autorizar_pendientes()
            return
        if not self.cuenta_regresiva():
            return
        f = self.api("tomar").get("factura")
        if not f:
            return
        self.texto_estado = f"facturando #{f['id']}"
        self.ping(forzar=True)
        try:
            self.facturar(f)
        except ErrorFactura as e:
            reg = self.estado["facturas"].get(str(f["id"]), {})
            reg["fase"] = "error"
            self._guardar()
            self.reportar(f["id"], "error", mensaje=str(e)[:400])
            self.al_evento("error", f.get("telefono", ""), f"Factura #{f['id']}", str(e))
            log.error("Factura #%s no se hizo: %s", f["id"], e)
        finally:
            self.texto_estado = "listo"

    def ejecutar(self):
        log.info("Facturación desde el celular activa (FactuSYS en %s)", self.datos.raiz)
        primera = True
        while not self.detener.is_set():
            try:
                if primera:
                    self.retomar()
                    primera = False
                self.una_vuelta()
            except (core.ErrorSubida, requests.RequestException) as e:
                self.texto_estado = "sin conexión con la página"
                log.warning("Facturación móvil: %s", e)
            except Exception:
                log.exception("Error inesperado en la facturación móvil")
            self.detener.wait(self.intervalo)
        log.info("Facturación móvil detenida.")


def problemas(cfg: configparser.ConfigParser) -> list[str]:
    completar_config(cfg)
    res = []
    raiz = Path(cfg.get("facturacion", "factusys"))
    if not (raiz / "FCCIAS.DBF").is_file():
        res.append(f"No se encuentra FactuSYS en {raiz}.")
    receta = " ".join(cfg.get("factusys_receta", k, fallback=v) for k, v in RECETA_POR_DEFECTO.items())
    for nombre, texto in PUNTOS:
        usado = re.search(rf"\b(clic|doble) {nombre}\b", receta) or (nombre.startswith("perfil") and "perfil{perfil}" in receta)
        if usado and not cfg.get("factusys_puntos", nombre, fallback="").strip():
            res.append(f"Falta calibrar: {texto}.")
    return res
