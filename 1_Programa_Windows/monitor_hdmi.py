"""
Pantalla del cliente en un monitor conectado a esta PC por cable (HDMI).

En vez de pasar por internet como el teléfono, el programa sirve la pantalla desde la
misma PC (http://127.0.0.1:8790) y la abre a pantalla completa en el segundo monitor con
Microsoft Edge (o Chrome). Los datos que se leen de FactuSYS llegan al instante.

Lo que se muestra (cartel, mensajes, formas de pago) se sigue configurando en el panel de
la página: el programa lo descarga cada 30 segundos y guarda una copia, así funciona
aunque se caiga el internet.
"""

from __future__ import annotations

import ctypes
import json
import logging
import os
import re
import shutil
import subprocess
import sys
import threading
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse

import requests

import monitor_facturas as core

log = logging.getLogger("facturas")

SECCION = "pantalla_cliente"
PUERTO = 8790
TITULO_VENTANA = "Pantalla del cliente · monitor"
CARPETA = core.DATA_DIR / "monitor_hdmi"

PAGINA_SIN_CONEXION = """<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Pantalla del cliente · monitor</title>
<style>html,body{margin:0;height:100%;background:#050506;color:#29d9ff;font:700 6vmin "Trebuchet MS",sans-serif;
display:flex;align-items:center;justify-content:center;text-align:center;cursor:none}
small{display:block;font-size:.45em;color:#8aa;margin-top:1em}</style></head>
<body><div>MECÁNICA TOAPANTA VÉLEZ<small>Conectando con la página por primera vez…</small></div>
<script>setTimeout(function(){location.reload()},5000)</script></body></html>"""


def activo(cfg) -> bool:
    return cfg.getboolean(SECCION, "monitor_hdmi", fallback=False)


def base_portal(cfg) -> str:
    url = cfg.get("servidor", "upload_url", fallback="").strip()
    return re.sub(r"api/upload\.php.*$", "", url) or "https://mecanicatoapantavelez.com/"


# ---------------------------------------------------------------------------
# Monitores de Windows
# ---------------------------------------------------------------------------

def _consciente():
    """Coordenadas reales de pantalla (sin el zoom de Windows 125 %, 150 %…)."""
    u = ctypes.windll.user32
    try:
        u.SetThreadDpiAwarenessContext.restype = ctypes.c_void_p
        u.SetThreadDpiAwarenessContext.argtypes = [ctypes.c_void_p]
        return u.SetThreadDpiAwarenessContext(ctypes.c_void_p(-4))
    except AttributeError:
        return None


def _restaurar(antes):
    if antes:
        try:
            ctypes.windll.user32.SetThreadDpiAwarenessContext(ctypes.c_void_p(antes))
        except Exception:  # noqa: BLE001
            pass


def monitores() -> list[dict]:
    """[{"n": 1, "x", "y", "w", "h", "principal": bool, "escala": 1.25}], el principal primero."""
    if sys.platform != "win32":
        return []
    from ctypes import wintypes as wt

    class MONITORINFOEXW(ctypes.Structure):
        _fields_ = [("cbSize", wt.DWORD), ("rcMonitor", wt.RECT), ("rcWork", wt.RECT),
                    ("dwFlags", wt.DWORD), ("szDevice", wt.WCHAR * 32)]

    res = []
    antes = _consciente()
    try:
        PROC = ctypes.WINFUNCTYPE(ctypes.c_int, wt.HMONITOR, wt.HDC, ctypes.POINTER(wt.RECT), wt.LPARAM)

        def cb(hmon, _hdc, _r, _l):
            mi = MONITORINFOEXW(); mi.cbSize = ctypes.sizeof(MONITORINFOEXW)
            if ctypes.windll.user32.GetMonitorInfoW(hmon, ctypes.byref(mi)):
                r = mi.rcMonitor
                escala = 1.0
                try:
                    dx, dy = ctypes.c_uint(), ctypes.c_uint()
                    ctypes.windll.shcore.GetDpiForMonitor(hmon, 0, ctypes.byref(dx), ctypes.byref(dy))
                    escala = dx.value / 96 or 1.0
                except Exception:  # noqa: BLE001
                    pass
                res.append({"x": r.left, "y": r.top, "w": r.right - r.left, "h": r.bottom - r.top,
                            "principal": bool(mi.dwFlags & 1), "escala": escala, "disp": mi.szDevice})
            return 1
        ctypes.windll.user32.EnumDisplayMonitors(None, None, PROC(cb), 0)
    finally:
        _restaurar(antes)
    res.sort(key=lambda m: (not m["principal"], m["x"], m["y"]))
    for i, m in enumerate(res, 1):
        m["n"] = i
    return res


def describir(m: dict) -> str:
    return f"Monitor {m['n']} — {m['w']}×{m['h']}" + (" (el principal, donde está FactuSYS)" if m["principal"] else "")


def elegido(cfg) -> dict | None:
    """El monitor configurado; si no está (se desconectó), el primero que no es el principal."""
    lista = monitores()
    n = cfg.getint(SECCION, "monitor_numero", fallback=2)
    for m in lista:
        if m["n"] == n:
            return m
    otros = [m for m in lista if not m["principal"]]
    return otros[0] if otros else None


def navegador() -> str | None:
    """Edge (viene con Windows 10/11) o Chrome."""
    rutas = []
    for var in ("PROGRAMFILES(X86)", "PROGRAMFILES", "LOCALAPPDATA"):
        base = os.environ.get(var)
        if base:
            rutas += [Path(base) / "Microsoft/Edge/Application/msedge.exe",
                      Path(base) / "Google/Chrome/Application/chrome.exe"]
    for r in rutas:
        if r.is_file():
            return str(r)
    return shutil.which("msedge") or shutil.which("chrome")


def _ventanas_pantalla() -> list[int]:
    """Ventanas abiertas con el título de la pantalla del cliente."""
    from ctypes import wintypes as wt
    halladas = []
    PROC = ctypes.WINFUNCTYPE(ctypes.c_bool, wt.HWND, wt.LPARAM)

    def cb(h, _l):
        n = ctypes.windll.user32.GetWindowTextLengthW(h)
        if n and ctypes.windll.user32.IsWindowVisible(h):
            b = ctypes.create_unicode_buffer(n + 1)
            ctypes.windll.user32.GetWindowTextW(h, b, n + 1)
            if TITULO_VENTANA in b.value:
                halladas.append(h)
        return True
    ctypes.windll.user32.EnumWindows(PROC(cb), 0)
    return halladas


def _rect_ventana(h) -> tuple[int, int, int, int]:
    from ctypes import wintypes as wt
    antes = _consciente()
    try:
        r = wt.RECT()
        ctypes.windll.user32.GetWindowRect(h, ctypes.byref(r))
        return r.left, r.top, r.right, r.bottom
    finally:
        _restaurar(antes)


# ---------------------------------------------------------------------------
# Servidor local + ventana en el monitor
# ---------------------------------------------------------------------------

class MonitorHDMI:
    def __init__(self, cfg):
        self.cfg = cfg
        self.base = base_portal(cfg)
        self.clave = cfg.get("servidor", "upload_key", fallback="").strip()
        CARPETA.mkdir(parents=True, exist_ok=True)
        (CARPETA / "img").mkdir(exist_ok=True)
        self.cond = threading.Condition()
        self.v = 1
        self.fase = "vacio"
        self.datos: dict = {}
        self.cartel = self._leer_json("cartel.json") or None
        self.cartel_v = (self.cartel or {}).get("_v", "")
        self.pagina = self._leer_archivo("pantalla.html")
        self.servidor: ThreadingHTTPServer | None = None
        self.proc: subprocess.Popen | None = None
        self.parar = threading.Event()
        self.estado = "apagado"
        self.intentos_lugar = 0
        self.modo_coord = "fisico"
        self.ultimo_error = ""

    # ---- archivos guardados ----------------------------------------------------------
    def _leer_json(self, nombre):
        try:
            return json.loads((CARPETA / nombre).read_text(encoding="utf-8"))
        except (OSError, ValueError):
            return None

    def _leer_archivo(self, nombre) -> bytes:
        try:
            return (CARPETA / nombre).read_bytes()
        except OSError:
            return b""

    # ---- lo que se muestra --------------------------------------------------------------
    def poner(self, fase: str, datos: dict | None = None) -> None:
        datos = dict(datos or {}) if fase != "vacio" else {}
        with self.cond:
            if fase == self.fase and datos == self.datos:
                return
            self.fase, self.datos = fase, datos
            self.v += 1
            self.cond.notify_all()

    def respuesta_estado(self, v_cliente: str) -> dict:
        with self.cond:
            if v_cliente == str(self.v):              # espera un cambio (respuesta al instante)
                self.cond.wait(timeout=2.5)
            return {"ok": True, "aprobado": True, "v": self.v, "fase": self.fase, "datos": self.datos or {},
                    "cartel": self.cartel, "cartel_v": self.cartel_v, "ahora": int(time.time() * 1000)}

    # ---- descarga desde la página -----------------------------------------------------
    def actualizar(self, pagina_tambien: bool = False) -> None:
        try:
            if pagina_tambien or not self.pagina:
                r = requests.get(self.base + "pantalla.php", params={"modo": "pc"}, timeout=20)
                if r.status_code == 200 and b"MODO" in r.content:
                    self.pagina = r.content
                    (CARPETA / "pantalla.html").write_bytes(r.content)
            r = requests.post(self.base + "api/agente.php", json={"accion": "pantalla_config"},
                              headers={"X-Upload-Key": self.clave}, timeout=20)
            j = r.json()
            if j.get("ok") and isinstance(j.get("cartel"), dict):
                for im in (j["cartel"].get("pago") or {}).get("imagenes") or []:
                    self.imagen(int(im["id"]), str(im["h"]))
                if j.get("cartel_v") != self.cartel_v:
                    with self.cond:
                        self.cartel, self.cartel_v = j["cartel"], j.get("cartel_v", "")
                        self.cond.notify_all()
                    guardado = dict(j["cartel"]); guardado["_v"] = self.cartel_v
                    (CARPETA / "cartel.json").write_text(json.dumps(guardado, ensure_ascii=False), encoding="utf-8")
            self.ultimo_error = ""
        except (requests.RequestException, ValueError, OSError) as e:
            if str(e) != self.ultimo_error:
                log.debug("Monitor HDMI: no se pudo actualizar el cartel: %s", e)
            self.ultimo_error = str(e)

    def imagen(self, id_: int, huella: str) -> Path | None:
        if not re.fullmatch(r"[0-9a-f]{16}", huella):
            return None
        ruta = CARPETA / "img" / f"{id_}_{huella}"
        if not ruta.exists():
            try:
                r = requests.get(self.base + "pantalla.php", params={"img": id_, "h": huella}, timeout=30)
                if r.status_code != 200 or not r.headers.get("Content-Type", "").startswith("image/"):
                    return None
                ruta.write_bytes(r.content)
                (CARPETA / "img" / f"{id_}_{huella}.tipo").write_text(r.headers["Content-Type"], encoding="utf-8")
            except (requests.RequestException, OSError):
                return None
        return ruta

    # ---- servidor ----------------------------------------------------------------------
    def _manejador(self):
        dueno = self

        class Manejador(BaseHTTPRequestHandler):
            def log_message(self, *a):          # sin registro por cada consulta
                pass

            def _enviar(self, codigo, tipo, cuerpo: bytes, cache="no-store"):
                self.send_response(codigo)
                self.send_header("Content-Type", tipo)
                self.send_header("Content-Length", str(len(cuerpo)))
                self.send_header("Cache-Control", cache)
                self.end_headers()
                try:
                    self.wfile.write(cuerpo)
                except (BrokenPipeError, ConnectionResetError):
                    pass

            def do_GET(self):
                u = urlparse(self.path)
                if u.path in ("/", "/index.html"):
                    self._enviar(200, "text/html; charset=utf-8", dueno.pagina or PAGINA_SIN_CONEXION.encode("utf-8"))
                elif u.path == "/estado":
                    v = (parse_qs(u.query).get("v") or [""])[0]
                    cuerpo = json.dumps(dueno.respuesta_estado(v), ensure_ascii=False).encode("utf-8")
                    self._enviar(200, "application/json; charset=utf-8", cuerpo)
                elif m := re.fullmatch(r"/img/(\d+)", u.path):
                    h = (parse_qs(u.query).get("h") or [""])[0]
                    ruta = dueno.imagen(int(m.group(1)), h)
                    if not ruta:
                        self._enviar(404, "text/plain", b"no")
                        return
                    try:
                        tipo = (ruta.parent / (ruta.name + ".tipo")).read_text(encoding="utf-8")
                    except OSError:
                        tipo = "image/png"
                    self._enviar(200, tipo, ruta.read_bytes(), "public, max-age=31536000, immutable")
                elif u.path == "/assets/icono.png":
                    self._enviar(204, "image/png", b"")
                else:
                    self._enviar(404, "text/plain", b"no")

        return Manejador

    # ---- arranque / parada ----------------------------------------------------------------
    def iniciar(self) -> None:
        self.parar.clear()
        try:
            self.servidor = ThreadingHTTPServer(("127.0.0.1", PUERTO), self._manejador())
        except OSError as e:
            self.estado = f"no se pudo abrir el puerto {PUERTO}: {e}"
            log.error("Monitor HDMI: %s", self.estado)
            return
        self.servidor.daemon_threads = True
        threading.Thread(target=self.servidor.serve_forever, daemon=True).start()
        threading.Thread(target=self._ciclo, daemon=True).start()
        log.info("Monitor HDMI: pantalla del cliente servida en http://127.0.0.1:%s", PUERTO)

    def detener(self) -> None:
        self.parar.set()
        self.cerrar_ventana()
        if self.servidor:
            try:
                self.servidor.shutdown()
                self.servidor.server_close()
            except Exception:  # noqa: BLE001
                pass
        self.servidor = None
        self.estado = "apagado"

    def _ciclo(self) -> None:
        self.actualizar(pagina_tambien=True)
        ultima_pagina = time.time()
        siguiente_cartel = time.time() + 30
        siguiente_revision = 0.0
        while not self.parar.is_set():
            ahora = time.time()
            if ahora >= siguiente_cartel:
                self.actualizar(pagina_tambien=ahora - ultima_pagina > 600)
                if ahora - ultima_pagina > 600:
                    ultima_pagina = ahora
                siguiente_cartel = ahora + 30
            if ahora >= siguiente_revision:
                try:
                    self.vigilar_ventana()
                except Exception as e:  # noqa: BLE001
                    log.debug("Monitor HDMI: %s", e)
                siguiente_revision = ahora + 8
            self.parar.wait(1)

    # ---- la ventana en el monitor ---------------------------------------------------------
    def abrir_ventana(self) -> None:
        m = elegido(self.cfg)
        if not m:
            self.estado = "no hay un segundo monitor conectado"
            return
        exe = navegador()
        if not exe:
            self.estado = "no se encontró Microsoft Edge ni Chrome"
            return
        x, y, w, h = m["x"], m["y"], m["w"], m["h"]
        if self.modo_coord == "dip":                   # Edge cuenta con el zoom del monitor principal
            esc = next((p["escala"] for p in monitores() if p["principal"]), 1.0) or 1.0
            x, y, w, h = int(x / esc), int(y / esc), int(w / m["escala"]), int(h / m["escala"])
        perfil = CARPETA / ("perfil_edge" if "msedge" in exe.lower() else "perfil_chrome")
        url = f"http://127.0.0.1:{PUERTO}/"
        args = [exe, f"--user-data-dir={perfil}", "--no-first-run", "--no-default-browser-check",
                "--disable-features=Translate,TranslateUI", "--disable-session-crashed-bubble", "--disable-infobars",
                f"--window-position={x},{y}", f"--window-size={w},{h}", "--kiosk"]
        args += [url, "--edge-kiosk-type=fullscreen"] if "msedge" in exe.lower() else [url]
        self.cerrar_ventana()
        antes = ctypes.windll.user32.GetForegroundWindow() if sys.platform == "win32" else 0
        self.proc = subprocess.Popen(args, creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0))
        if antes:
            threading.Thread(target=self._devolver_foco, args=(antes,), daemon=True).start()
        self.monitor_abierto = m
        self.abierta_en = time.time()
        self.estado = f"abierta en el monitor {m['n']}"
        log.info("Monitor HDMI: pantalla abierta en %s", describir(m))

    def _devolver_foco(self, antes) -> None:
        """Al abrir el monitor, Edge se queda con el teclado: se le devuelve a FactuSYS."""
        for _ in range(40):
            time.sleep(0.25)
            if _ventanas_pantalla():
                time.sleep(0.8)
                try:
                    u = ctypes.windll.user32
                    u.keybd_event(0x12, 0, 0, 0); u.keybd_event(0x12, 0, 2, 0)      # Alt: permite cambiar el foco
                    u.SetForegroundWindow(antes)
                except Exception:  # noqa: BLE001
                    pass
                return

    def cerrar_ventana(self) -> None:
        if self.proc and self.proc.poll() is None:
            try:
                subprocess.run(["taskkill", "/PID", str(self.proc.pid), "/T", "/F"], capture_output=True,
                               creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0), timeout=10)
            except Exception:  # noqa: BLE001
                try:
                    self.proc.kill()
                except Exception:  # noqa: BLE001
                    pass
        self.proc = None

    def vigilar_ventana(self) -> None:
        """La vuelve a abrir si se cerró, y revisa que esté en el monitor elegido."""
        if not activo(self.cfg):
            return
        if not self.proc or self.proc.poll() is not None:
            if sys.platform == "win32" and not _ventanas_pantalla():
                self.abrir_ventana()
            return
        if time.time() - getattr(self, "abierta_en", 0) < 6 or sys.platform != "win32":
            return
        ventanas = _ventanas_pantalla()
        m = getattr(self, "monitor_abierto", None)
        if not ventanas or not m:
            return
        l, t, r, b = _rect_ventana(ventanas[0])
        cx, cy = (l + r) // 2, (t + b) // 2
        dentro = m["x"] <= cx < m["x"] + m["w"] and m["y"] <= cy < m["y"] + m["h"]
        if dentro:
            self.intentos_lugar = 0
            return
        if self.intentos_lugar < 2:                    # abrió en otro monitor: se prueba con otras coordenadas
            self.intentos_lugar += 1
            self.modo_coord = "dip" if self.modo_coord == "fisico" else "fisico"
            log.info("Monitor HDMI: la ventana abrió en otro monitor; se vuelve a abrir.")
            self.abrir_ventana()
        else:
            self.estado = "no se pudo poner en el monitor elegido (revisa Configuración de pantalla de Windows)"
