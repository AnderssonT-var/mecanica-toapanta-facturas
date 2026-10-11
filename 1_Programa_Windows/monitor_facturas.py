"""
Monitor de facturas - Mecánica Toapanta
=======================================

Vigila una carpeta en Windows. Cuando aparece un PDF cuyo nombre es un
número de teléfono (ej. "593 0959133066.pdf" o "0959133066.pdf"):

  1. Sube la factura al portal en Hostinger (api/upload.php).
  2. Recibe el link privado del cliente.
  3. Abre el chat del cliente en WhatsApp de escritorio, adjunta el PDF y
     lo envía con un saludo según la hora + el link.
  4. Mueve el PDF a la carpeta "Facturas enviadas".

Pensado para PCs lentas: todos los tiempos de espera se ajustan en config.ini.

Si algo falla, reintenta solo. Si falla demasiadas veces, el PDF va a la
carpeta "Con error" para que lo revises (para reintentarlo basta con volver
a ponerlo en la carpeta de entrada).

Uso:
    python monitor_facturas.py                    -> monitorea sin parar
    python monitor_facturas.py --una-vez          -> procesa lo que haya y sale
    python monitor_facturas.py --probar-whatsapp 0959133066
                                                  -> envía un mensaje de prueba
"""

from __future__ import annotations

import argparse
import configparser
import csv
import hashlib
import json
import logging
import os
import re
import shutil
import socket
import struct
import sys
import threading
import time
from datetime import date, datetime
from logging.handlers import RotatingFileHandler
from pathlib import Path
from urllib.parse import quote

import requests

# Los datos (configuración, historial, registro) viven en una carpeta propia del
# usuario, para que el programa funcione aunque esté instalado en "Archivos de programa".
if sys.platform == "win32" and os.environ.get("APPDATA"):
    DATA_DIR = Path(os.environ["APPDATA"]) / "MonitorFacturasToapanta"
else:
    DATA_DIR = Path(__file__).resolve().parent / "datos"
DATA_DIR.mkdir(parents=True, exist_ok=True)
CONFIG_FILE = DATA_DIR / "config.ini"
STATE_FILE = DATA_DIR / "pendientes.json"
HISTORY_FILE = DATA_DIR / "historial_envios.csv"
LOG_FILE = DATA_DIR / "monitor_facturas.log"
LOCK_PORT = 47593  # evita que se abran dos monitores a la vez

# Solo un envío por WhatsApp a la vez (el monitor y el botón de prueba comparten teclado)
ENVIO_LOCK = threading.Lock()

CONFIG_POR_DEFECTO = r"""
[carpetas]
entrada = C:\Facturas
enviadas = C:\Facturas\Facturas enviadas
errores = C:\Facturas\Con error

[servidor]
upload_url = https://mecanicatoapantavelez.com/api/upload.php
upload_key =

[mensaje]
negocio = Mecánica Toapanta
plantilla = {saludo}, de parte de {negocio} puede visualizar su factura en el siguiente link:\n{link}\n\n¡Gracias por su preferencia!

[whatsapp]
metodo = escritorio
perfil_chrome = C:\Facturas\.whatsapp_sesion
cloud_token =
cloud_phone_id =
cloud_plantilla =
cloud_idioma = es

[escritorio]
adjuntar_pdf = si
espera_abrir_app = 90
espera_abrir_chat = 12
espera_adjunto = 10
espera_pegar_texto = 3
espera_envio = 10
factor_lentitud = 1.0
esperar_inactividad = 4

[general]
intervalo_segundos = 5
espera_archivo_listo = 3
reintentos_maximos = 5
iniciar_automaticamente = si
""".replace("\\\\", "\\")

log = logging.getLogger("facturas")


# ---------------------------------------------------------------------------
# Utilidades
# ---------------------------------------------------------------------------

def configurar_log(consola: bool = True) -> None:
    if any(isinstance(h, RotatingFileHandler) for h in log.handlers):
        return
    log.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)s  %(levelname)-7s  %(message)s", "%Y-%m-%d %H:%M:%S")
    archivo = RotatingFileHandler(LOG_FILE, maxBytes=2_000_000, backupCount=3, encoding="utf-8")
    archivo.setFormatter(fmt)
    log.addHandler(archivo)
    if consola and sys.stdout is not None:  # con pythonw no hay consola
        consola = logging.StreamHandler(sys.stdout)
        consola.setFormatter(fmt)
        log.addHandler(consola)


def nueva_config() -> configparser.ConfigParser:
    cfg = configparser.ConfigParser(interpolation=None)
    cfg.BOOLEAN_STATES = {**cfg.BOOLEAN_STATES, "si": True, "sí": True}
    cfg.read_string(CONFIG_POR_DEFECTO)
    return cfg


def cargar_config() -> configparser.ConfigParser:
    """Lee la configuración; los valores que falten toman el valor por defecto."""
    cfg = nueva_config()
    if CONFIG_FILE.exists():
        cfg.read(CONFIG_FILE, encoding="utf-8")
    else:
        guardar_config(cfg)
    return cfg


def guardar_config(cfg: configparser.ConfigParser) -> None:
    tmp = CONFIG_FILE.with_suffix(".tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        cfg.write(f)
    tmp.replace(CONFIG_FILE)


def problemas_config(cfg: configparser.ConfigParser) -> list[str]:
    """Lo que falta para poder empezar a trabajar, en palabras simples."""
    problemas = []
    if not cfg.get("servidor", "upload_key").strip():
        problemas.append("Falta la clave de subida.")
    if not cfg.get("carpetas", "entrada").strip():
        problemas.append("Falta elegir la carpeta de facturas.")
    try:
        armar_mensaje(cfg, "https://ejemplo")
    except (KeyError, ValueError, IndexError):
        problemas.append("El mensaje tiene llaves { } que no se reconocen.")
    return problemas


def leer_historial(limite: int = 200) -> list[dict]:
    try:
        with open(HISTORY_FILE, newline="", encoding="utf-8-sig") as f:
            filas = list(csv.DictReader(f, delimiter=";"))
    except OSError:
        return []
    return filas[-limite:][::-1]


def normalizar_telefono(nombre_archivo: str) -> str | None:
    """'593 0959133066' -> '593959133066'. Devuelve None si no es un celular de Ecuador."""
    nombre = re.sub(r"\(\d+\)\s*$", "", nombre_archivo.strip())  # quita copias "(1)"
    digitos = re.sub(r"\D", "", nombre)
    if digitos.startswith("5930"):
        digitos = "593" + digitos[4:]
    elif len(digitos) == 10 and digitos.startswith("09"):
        digitos = "593" + digitos[1:]
    elif len(digitos) == 9 and digitos.startswith("9"):
        digitos = "593" + digitos
    return digitos if re.fullmatch(r"5939\d{8}", digitos) else None


def saludo_segun_hora(ahora: datetime | None = None) -> str:
    hora = (ahora or datetime.now()).hour
    if 5 <= hora < 12:
        return "Buenos días"
    if 12 <= hora < 19:
        return "Buenas tardes"
    return "Buenas noches"


def armar_mensaje(cfg: configparser.ConfigParser, link: str, ahora: datetime | None = None) -> str:
    plantilla = cfg.get("mensaje", "plantilla").replace("\\n", "\n")
    return plantilla.format(
        saludo=saludo_segun_hora(ahora),
        negocio=cfg.get("mensaje", "negocio"),
        link=link,
    )


def archivo_estable(ruta: Path, espera: float = 1.5) -> bool:
    """True cuando el PDF terminó de copiarse/guardarse (tamaño fijo y se puede abrir)."""
    try:
        t1 = ruta.stat().st_size
        time.sleep(espera)
        t2 = ruta.stat().st_size
        if t1 != t2 or t2 == 0:
            return False
        with open(ruta, "rb") as f:
            return f.read(5) == b"%PDF-"
    except (OSError, PermissionError):
        return False


def huella(ruta: Path) -> str:
    h = hashlib.sha256()
    with open(ruta, "rb") as f:
        for bloque in iter(lambda: f.read(65536), b""):
            h.update(bloque)
    return h.hexdigest()


def mover_sin_pisar(origen: Path, carpeta: Path) -> Path:
    carpeta.mkdir(parents=True, exist_ok=True)
    destino = carpeta / origen.name
    if destino.exists():
        sello = datetime.now().strftime("%Y%m%d-%H%M%S")
        destino = carpeta / f"{origen.stem} {sello}{origen.suffix}"
    shutil.move(str(origen), str(destino))
    return destino


def leer_estado() -> dict:
    try:
        return json.loads(STATE_FILE.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return {}


def guardar_estado(estado: dict) -> None:
    tmp = STATE_FILE.with_suffix(".tmp")
    tmp.write_text(json.dumps(estado, indent=2, ensure_ascii=False), encoding="utf-8")
    tmp.replace(STATE_FILE)


def registrar_historial(telefono: str, archivo: str, link: str, estado: str, detalle: str = "") -> None:
    nuevo = not HISTORY_FILE.exists()
    with open(HISTORY_FILE, "a", newline="", encoding="utf-8-sig") as f:
        w = csv.writer(f, delimiter=";")
        if nuevo:
            w.writerow(["fecha", "telefono", "archivo", "link", "estado", "detalle"])
        w.writerow([datetime.now().strftime("%Y-%m-%d %H:%M:%S"), telefono, archivo, link, estado, detalle])


# ---------------------------------------------------------------------------
# Subida a Hostinger
# ---------------------------------------------------------------------------

class ErrorSubida(Exception):
    pass


def subir_factura(cfg: configparser.ConfigParser, ruta: Path, telefono: str) -> str:
    url = cfg.get("servidor", "upload_url")
    clave = cfg.get("servidor", "upload_key").strip()
    if not clave or clave.startswith("PEGA_AQUI"):
        raise ErrorSubida("Falta la clave de subida en la configuración")
    with open(ruta, "rb") as f:
        resp = requests.post(
            url,
            headers={"X-Upload-Key": clave},
            data={"phone": telefono, "invoice_date": date.today().isoformat()},
            files={"invoice": (ruta.name, f, "application/pdf")},
            timeout=180,  # PC/internet lentos
        )
    if resp.status_code == 401:
        raise ErrorSubida("El servidor rechazó la clave de subida: revisa la clave en la configuración")
    if resp.status_code != 200:
        raise ErrorSubida(f"Servidor respondió {resp.status_code}: {resp.text[:200]}")
    try:
        datos = resp.json()
    except ValueError:
        raise ErrorSubida(f"Respuesta inesperada del servidor: {resp.text[:200]}")
    if not datos.get("ok") or not datos.get("portal_url"):
        raise ErrorSubida(f"El servidor no devolvió el link: {datos}")
    return datos["portal_url"]


# ---------------------------------------------------------------------------
# Envío por WhatsApp
# ---------------------------------------------------------------------------

class ErrorEnvio(Exception):
    pass


def datos_dropfiles(ruta: Path) -> bytes:
    """Estructura DROPFILES de Windows: es lo que hay en el portapapeles al 'Copiar' un archivo."""
    cabecera = struct.pack("<IiiII", 20, 0, 0, 0, 1)  # pFiles=20, pt=(0,0), fNC=0, fWide=1
    return cabecera + (str(Path(ruta).resolve()) + "\0\0").encode("utf-16-le")


class EnviadorWhatsAppEscritorio:
    """
    Usa la app de WhatsApp instalada en Windows, como lo haría una persona:
      1. Abre el chat del cliente (enlace whatsapp://send?phone=...).
      2. Pega el PDF (como "Copiar archivo" + Ctrl+V) -> WhatsApp muestra la vista previa.
      3. Pega el texto como comentario del PDF y presiona Enter.
    Antes de tocar el teclado espera a que nadie esté usando la PC.
    """

    CF_UNICODETEXT, CF_HDROP = 13, 15
    VK_CONTROL, VK_MENU, VK_RETURN, VK_V = 0x11, 0x12, 0x0D, 0x56
    NAVEGADORES = ("chrome", "msedge", "firefox", "opera", "brave", "iexplore")

    def __init__(self, cfg: configparser.ConfigParser):
        if sys.platform != "win32":
            raise SystemExit("El método 'escritorio' solo funciona en Windows.")
        import ctypes
        from ctypes import wintypes as wt
        self.ct, self.wt = ctypes, wt
        u32 = self.u32 = ctypes.WinDLL("user32", use_last_error=True)
        k32 = self.k32 = ctypes.WinDLL("kernel32", use_last_error=True)

        k32.GlobalAlloc.argtypes = [wt.UINT, ctypes.c_size_t]
        k32.GlobalAlloc.restype = wt.HGLOBAL
        k32.GlobalLock.argtypes = [wt.HGLOBAL]
        k32.GlobalLock.restype = ctypes.c_void_p
        k32.GlobalUnlock.argtypes = [wt.HGLOBAL]
        k32.GlobalFree.argtypes = [wt.HGLOBAL]
        k32.OpenProcess.argtypes = [wt.DWORD, wt.BOOL, wt.DWORD]
        k32.OpenProcess.restype = wt.HANDLE
        k32.QueryFullProcessImageNameW.argtypes = [wt.HANDLE, wt.DWORD, wt.LPWSTR, ctypes.POINTER(wt.DWORD)]
        k32.CloseHandle.argtypes = [wt.HANDLE]
        k32.GetTickCount.restype = wt.DWORD
        u32.OpenClipboard.argtypes = [wt.HWND]
        u32.SetClipboardData.argtypes = [wt.UINT, wt.HANDLE]
        u32.SetClipboardData.restype = wt.HANDLE
        u32.GetForegroundWindow.restype = wt.HWND
        u32.SetForegroundWindow.argtypes = [wt.HWND]
        u32.ShowWindow.argtypes = [wt.HWND, ctypes.c_int]
        u32.IsIconic.argtypes = [wt.HWND]
        u32.IsWindowVisible.argtypes = [wt.HWND]
        u32.GetWindowTextW.argtypes = [wt.HWND, wt.LPWSTR, ctypes.c_int]
        u32.GetWindowThreadProcessId.argtypes = [wt.HWND, ctypes.POINTER(wt.DWORD)]

        class LASTINPUTINFO(ctypes.Structure):
            _fields_ = [("cbSize", wt.UINT), ("dwTime", wt.DWORD)]
        self.LASTINPUTINFO = LASTINPUTINFO
        u32.GetLastInputInfo.argtypes = [ctypes.POINTER(LASTINPUTINFO)]

        factor = cfg.getfloat("escritorio", "factor_lentitud", fallback=1.0)
        seg = lambda clave, defecto: cfg.getfloat("escritorio", clave, fallback=defecto) * factor
        self.esp_app = seg("espera_abrir_app", 60)
        self.esp_chat = seg("espera_abrir_chat", 10)
        self.esp_adjunto = seg("espera_adjunto", 8)
        self.esp_pegar = seg("espera_pegar_texto", 2)
        self.esp_envio = seg("espera_envio", 8)
        self.inactividad = cfg.getfloat("escritorio", "esperar_inactividad", fallback=4)
        self.adjuntar = cfg.getboolean("escritorio", "adjuntar_pdf", fallback=True)

    # --- ventanas -----------------------------------------------------------

    def _exe(self, hwnd) -> str:
        pid = self.wt.DWORD()
        self.u32.GetWindowThreadProcessId(hwnd, self.ct.byref(pid))
        h = self.k32.OpenProcess(0x1000, False, pid.value)  # PROCESS_QUERY_LIMITED_INFORMATION
        if not h:
            return ""
        try:
            buf = self.ct.create_unicode_buffer(520)
            n = self.wt.DWORD(520)
            if self.k32.QueryFullProcessImageNameW(h, 0, buf, self.ct.byref(n)):
                return Path(buf.value).name.lower()
            return ""
        finally:
            self.k32.CloseHandle(h)

    def _ventana(self):
        """Busca la ventana de la app WhatsApp (no una pestaña del navegador)."""
        encontradas = []

        @self.ct.WINFUNCTYPE(self.wt.BOOL, self.wt.HWND, self.wt.LPARAM)
        def revisar(hwnd, _):
            if self.u32.IsWindowVisible(hwnd):
                buf = self.ct.create_unicode_buffer(256)
                self.u32.GetWindowTextW(hwnd, buf, 256)
                titulo = re.sub(r"^\(\d+\)\s*", "", buf.value).strip()
                if titulo.lower() == "whatsapp":
                    exe = self._exe(hwnd)
                    if not any(n in exe for n in self.NAVEGADORES):
                        encontradas.append(hwnd)
            return True

        self.u32.EnumWindows(revisar, 0)
        return encontradas[0] if encontradas else None

    def _foco(self, hwnd) -> None:
        for _ in range(4):
            if self.u32.IsIconic(hwnd):
                self.u32.ShowWindow(hwnd, 9)  # SW_RESTORE
            self._tecla(self.VK_MENU)        # truco: Windows permite cambiar el foco tras pulsar Alt
            self.u32.SetForegroundWindow(hwnd)
            time.sleep(0.8)
            if self.u32.GetForegroundWindow() == hwnd:
                return
        raise ErrorEnvio("No se pudo poner WhatsApp al frente.")

    def _asegurar_app(self):
        hwnd = self._ventana()
        if hwnd:
            return hwnd
        log.info("Abriendo WhatsApp de escritorio…")
        try:
            os.startfile("whatsapp:")
        except OSError:
            raise ErrorEnvio("WhatsApp de escritorio no está instalado (Microsoft Store).")
        limite = time.time() + self.esp_app
        while time.time() < limite:
            time.sleep(2)
            hwnd = self._ventana()
            if hwnd:
                time.sleep(self.esp_chat)  # deja que cargue la lista de chats
                return hwnd
        raise ErrorEnvio("WhatsApp de escritorio no abrió a tiempo (sube espera_abrir_app).")

    # --- teclado, portapapeles e inactividad ----------------------------------

    def _tecla(self, *teclas) -> None:
        for vk in teclas:
            self.u32.keybd_event(vk, 0, 0, 0)
            time.sleep(0.05)
        for vk in reversed(teclas):
            self.u32.keybd_event(vk, 0, 2, 0)  # KEYEVENTF_KEYUP
            time.sleep(0.05)

    def _portapapeles(self, formato: int, datos: bytes) -> None:
        for _ in range(20):
            if self.u32.OpenClipboard(None):
                break
            time.sleep(0.25)
        else:
            raise ErrorEnvio("El portapapeles está ocupado.")
        try:
            self.u32.EmptyClipboard()
            h = self.k32.GlobalAlloc(0x0002, len(datos))  # GMEM_MOVEABLE
            p = self.k32.GlobalLock(h)
            self.ct.memmove(p, datos, len(datos))
            self.k32.GlobalUnlock(h)
            if not self.u32.SetClipboardData(formato, h):
                self.k32.GlobalFree(h)
                raise ErrorEnvio("No se pudo copiar al portapapeles.")
        finally:
            self.u32.CloseClipboard()

    def _segundos_inactivo(self) -> float:
        info = self.LASTINPUTINFO(cbSize=self.ct.sizeof(self.LASTINPUTINFO))
        self.u32.GetLastInputInfo(self.ct.byref(info))
        return ((self.k32.GetTickCount() - info.dwTime) & 0xFFFFFFFF) / 1000

    def _esperar_que_no_usen_la_pc(self) -> None:
        limite = time.time() + 300
        avisado = False
        while self._segundos_inactivo() < self.inactividad:
            if time.time() > limite:
                raise ErrorEnvio("La PC estuvo en uso; se envía más tarde.")
            if not avisado:
                log.info("Esperando que nadie use el teclado/mouse para enviar…")
                avisado = True
            time.sleep(1)

    # --- envío ------------------------------------------------------------------

    def enviar(self, telefono: str, texto: str, ruta: Path | None = None, **_) -> None:
        hwnd = self._asegurar_app()
        self._esperar_que_no_usen_la_pc()

        log.info("Abriendo chat de %s en WhatsApp…", telefono)
        os.startfile(f"whatsapp://send?phone={telefono}")
        time.sleep(self.esp_chat)
        hwnd = self._ventana() or hwnd
        self._foco(hwnd)

        if self.adjuntar and ruta is not None:
            self._portapapeles(self.CF_HDROP, datos_dropfiles(ruta))
            self._tecla(self.VK_CONTROL, self.VK_V)       # adjunta el PDF
            time.sleep(self.esp_adjunto)                  # vista previa del documento
            self._foco(hwnd)

        self._portapapeles(self.CF_UNICODETEXT, (texto + "\0").encode("utf-16-le"))
        self._tecla(self.VK_CONTROL, self.VK_V)           # texto (comentario del PDF)
        time.sleep(self.esp_pegar)
        self._foco(hwnd)
        self._tecla(self.VK_RETURN)                       # enviar
        time.sleep(self.esp_envio)

    def cerrar(self):
        pass


class EnviadorWhatsAppWeb:
    """Usa WhatsApp Web con el número del taller. El QR se escanea solo la primera vez."""

    def __init__(self, cfg: configparser.ConfigParser):
        self.perfil = Path(cfg.get("whatsapp", "perfil_chrome")).expanduser()
        self.driver = None

    def _abrir(self):
        from selenium import webdriver
        from selenium.webdriver.common.by import By
        from selenium.webdriver.support.ui import WebDriverWait

        if self.driver is not None:
            try:
                _ = self.driver.title
                return
            except Exception:
                self.driver = None

        self.perfil.mkdir(parents=True, exist_ok=True)
        opciones = webdriver.ChromeOptions()
        opciones.add_argument(f"--user-data-dir={self.perfil}")
        opciones.add_argument("--profile-directory=Default")
        opciones.add_argument("--disable-notifications")
        self.driver = webdriver.Chrome(options=opciones)
        self.driver.get("https://web.whatsapp.com")
        log.info("Abriendo WhatsApp Web. Si es la primera vez, escanea el código QR con el celular del taller.")
        try:
            WebDriverWait(self.driver, 300).until(
                lambda d: d.find_elements(By.ID, "pane-side")
            )
        except Exception:
            raise ErrorEnvio("WhatsApp Web no inició sesión (¿se escaneó el QR?).")
        log.info("WhatsApp Web listo.")

    def enviar(self, telefono: str, texto: str, **_) -> None:
        from selenium.webdriver.common.by import By
        from selenium.webdriver.common.keys import Keys
        from selenium.webdriver.support.ui import WebDriverWait

        self._abrir()
        d = self.driver
        d.get(f"https://web.whatsapp.com/send?phone={telefono}&text={quote(texto)}")

        caja_sel = 'footer div[contenteditable="true"]'

        def listo(drv):
            cajas = drv.find_elements(By.CSS_SELECTOR, caja_sel)
            if cajas and cajas[0].text.strip():
                return ("ok", cajas[0])
            for dlg in drv.find_elements(By.CSS_SELECTOR, 'div[role="dialog"]'):
                t = dlg.text.lower()
                if "no es válido" in t or "invalid" in t or "no está en whatsapp" in t:
                    return ("invalido", None)
            return False

        try:
            estado, caja = WebDriverWait(d, 60).until(listo)
        except Exception:
            raise ErrorEnvio("No cargó el chat en WhatsApp Web (tiempo agotado).")
        if estado == "invalido":
            raise ErrorEnvio(f"El número {telefono} no tiene WhatsApp.")

        time.sleep(1)
        caja.send_keys(Keys.ENTER)

        # Espera a que el mensaje salga (desaparece el relojito de "pendiente")
        try:
            WebDriverWait(d, 45).until(
                lambda drv: not drv.find_elements(By.CSS_SELECTOR, caja_sel)[0].text.strip()
                and not drv.find_elements(By.CSS_SELECTOR, 'span[data-icon="msg-time"]')
            )
        except Exception:
            raise ErrorEnvio("El mensaje quedó pendiente en WhatsApp Web (¿sin internet?).")
        time.sleep(2)

    def cerrar(self):
        if self.driver is not None:
            try:
                self.driver.quit()
            except Exception:
                pass
            self.driver = None


class EnviadorCloudAPI:
    """WhatsApp Cloud API oficial de Meta (requiere plantilla aprobada)."""

    def __init__(self, cfg: configparser.ConfigParser):
        self.token = cfg.get("whatsapp", "cloud_token")
        self.phone_id = cfg.get("whatsapp", "cloud_phone_id")
        self.plantilla = cfg.get("whatsapp", "cloud_plantilla")
        self.idioma = cfg.get("whatsapp", "cloud_idioma", fallback="es")
        if not (self.token and self.phone_id and self.plantilla):
            raise SystemExit("Faltan cloud_token, cloud_phone_id o cloud_plantilla en config.ini")

    def enviar(self, telefono: str, texto: str, saludo: str = "", link: str = "", **_) -> None:
        resp = requests.post(
            f"https://graph.facebook.com/v21.0/{self.phone_id}/messages",
            headers={"Authorization": f"Bearer {self.token}"},
            json={
                "messaging_product": "whatsapp",
                "to": telefono,
                "type": "template",
                "template": {
                    "name": self.plantilla,
                    "language": {"code": self.idioma},
                    "components": [{
                        "type": "body",
                        "parameters": [
                            {"type": "text", "text": saludo},
                            {"type": "text", "text": link},
                        ],
                    }],
                },
            },
            timeout=30,
        )
        if resp.status_code >= 300:
            raise ErrorEnvio(f"Cloud API respondió {resp.status_code}: {resp.text[:300]}")

    def cerrar(self):
        pass


class EnviadorConsola:
    """Solo para pruebas: muestra el mensaje en pantalla, no envía nada."""

    def __init__(self, cfg):
        pass

    def enviar(self, telefono: str, texto: str, **_) -> None:
        log.info("[PRUEBA] Mensaje para %s:\n%s", telefono, texto)

    def cerrar(self):
        pass


def crear_enviador(cfg: configparser.ConfigParser):
    metodo = cfg.get("whatsapp", "metodo", fallback="escritorio").strip().lower()
    return {
        "escritorio": EnviadorWhatsAppEscritorio,
        "web": EnviadorWhatsAppWeb,
        "cloud_api": EnviadorCloudAPI,
        "consola": EnviadorConsola,
    }.get(metodo, EnviadorWhatsAppEscritorio)(cfg)


# ---------------------------------------------------------------------------
# Proceso principal
# ---------------------------------------------------------------------------

class Monitor:
    def __init__(self, cfg: configparser.ConfigParser, al_evento=None):
        self.cfg = cfg
        self.al_evento = al_evento or (lambda *a: None)   # avisa a la ventana: ("enviada"/"error", tel, archivo, detalle)
        self.detener = threading.Event()
        self.entrada = Path(cfg.get("carpetas", "entrada"))
        self.enviadas = Path(cfg.get("carpetas", "enviadas"))
        self.errores = Path(cfg.get("carpetas", "errores"))
        self.max_intentos = cfg.getint("general", "reintentos_maximos", fallback=5)
        self.intervalo = cfg.getfloat("general", "intervalo_segundos", fallback=5)
        self.espera_estable = cfg.getfloat("general", "espera_archivo_listo", fallback=3)
        for c in (self.entrada, self.enviadas, self.errores):
            c.mkdir(parents=True, exist_ok=True)
        self.enviador = crear_enviador(cfg)
        self.estado = leer_estado()

    def pdfs_en_entrada(self) -> list[Path]:
        return sorted(
            (p for p in self.entrada.iterdir() if p.is_file() and p.suffix.lower() == ".pdf"
             and not p.name.startswith(("~", "."))),
            key=lambda p: p.stat().st_mtime,
        )

    def a_errores(self, ruta: Path, telefono: str, motivo: str) -> None:
        destino = mover_sin_pisar(ruta, self.errores)
        log.error("✗ %s -> 'Con error': %s", ruta.name, motivo)
        registrar_historial(telefono, destino.name, "", "ERROR", motivo)
        self.al_evento("error", telefono, ruta.name, motivo)

    def procesar(self, ruta: Path) -> None:
        telefono = normalizar_telefono(ruta.stem)
        if not telefono:
            self.a_errores(ruta, "", "El nombre del archivo no es un celular válido de Ecuador")
            return
        if not archivo_estable(ruta, self.espera_estable):
            return  # todavía se está guardando; se revisa en la siguiente vuelta

        clave = huella(ruta)
        info = self.estado.get(clave, {"telefono": telefono, "intentos": 0, "proximo": 0})
        if time.time() < info.get("proximo", 0):
            return  # esperando para reintentar

        try:
            if not info.get("link"):
                log.info("Subiendo %s (cliente %s)…", ruta.name, telefono)
                info["link"] = subir_factura(self.cfg, ruta, telefono)
                self.estado[clave] = info
                guardar_estado(self.estado)
                log.info("Factura en el portal: %s", info["link"])

            ahora = datetime.now()
            texto = armar_mensaje(self.cfg, info["link"], ahora)
            with ENVIO_LOCK:
                self.enviador.enviar(telefono, texto, ruta=ruta, saludo=saludo_segun_hora(ahora), link=info["link"])

            destino = mover_sin_pisar(ruta, self.enviadas)
            registrar_historial(telefono, destino.name, info["link"], "ENVIADA")
            self.estado.pop(clave, None)
            guardar_estado(self.estado)
            log.info("✓ %s enviada a %s y movida a 'Facturas enviadas'", ruta.name, telefono)
            self.al_evento("enviada", telefono, ruta.name, info["link"])

        except (ErrorSubida, ErrorEnvio, requests.RequestException) as e:
            info["intentos"] = info.get("intentos", 0) + 1
            if info["intentos"] >= self.max_intentos:
                self.estado.pop(clave, None)
                guardar_estado(self.estado)
                self.a_errores(ruta, telefono, f"{e} (tras {info['intentos']} intentos)")
                return
            espera = min(60 * 2 ** (info["intentos"] - 1), 1800)  # 1, 2, 4, 8… min
            info["proximo"] = time.time() + espera
            self.estado[clave] = info
            guardar_estado(self.estado)
            log.warning("Falló %s (intento %d/%d): %s. Reintento en %d min.",
                        ruta.name, info["intentos"], self.max_intentos, e, espera // 60)

    def una_vuelta(self) -> None:
        try:
            pdfs = self.pdfs_en_entrada()
        except OSError as e:
            log.error("No se puede leer la carpeta %s: %s", self.entrada, e)
            return
        for pdf in pdfs:
            if self.detener.is_set():
                return
            try:
                self.procesar(pdf)
            except Exception:
                log.exception("Error inesperado con %s", pdf.name)

    def ejecutar(self) -> None:
        log.info("Vigilando la carpeta %s", self.entrada)
        try:
            while not self.detener.is_set():
                self.una_vuelta()
                self.detener.wait(self.intervalo)
        except KeyboardInterrupt:
            pass
        finally:
            self.enviador.cerrar()
            log.info("Monitor detenido.")


def main() -> None:
    parser = argparse.ArgumentParser(description="Monitor de facturas - Mecánica Toapanta")
    parser.add_argument("--una-vez", action="store_true", help="Procesa lo pendiente y termina")
    parser.add_argument("--probar-whatsapp", metavar="TELEFONO", help="Envía un mensaje de prueba")
    args = parser.parse_args()

    configurar_log()
    cfg = cargar_config()
    if sys.platform == "win32":
        import ctypes  # prioridad baja: la PC sigue usable mientras el monitor trabaja
        ctypes.windll.kernel32.SetPriorityClass(ctypes.windll.kernel32.GetCurrentProcess(), 0x4000)

    if args.probar_whatsapp:
        tel = normalizar_telefono(args.probar_whatsapp)
        if not tel:
            raise SystemExit("Número no válido.")
        env = crear_enviador(cfg)
        try:
            env.enviar(tel, armar_mensaje(cfg, "https://mecanicatoapantavelez.com (mensaje de prueba)"), ruta=None)
            log.info("Mensaje de prueba enviado a %s", tel)
        finally:
            env.cerrar()
        return

    try:
        candado = socket.socket()
        candado.bind(("127.0.0.1", LOCK_PORT))
    except OSError:
        raise SystemExit("El monitor ya está abierto en esta computadora.")

    monitor = Monitor(cfg)
    if args.una_vez:
        monitor.una_vuelta()
        monitor.enviador.cerrar()
    else:
        monitor.ejecutar()


if __name__ == "__main__":
    main()
