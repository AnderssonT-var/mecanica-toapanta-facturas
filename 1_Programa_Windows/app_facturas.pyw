"""
Monitor de Facturas - Mecánica Toapanta  (programa con ventana)

- Al cerrar la ventana el programa sigue trabajando: queda un ícono junto al
  reloj de Windows. Desde ese ícono se abre de nuevo o se sale del todo.
- Puede arrancar solo al encender la PC (casilla en Configuración).
- Toda la configuración se hace desde la ventana; no hace falta la consola.
"""

from __future__ import annotations

import hashlib
import logging
import os
import queue
import secrets
import socket
import sys
import threading
from datetime import date
from pathlib import Path
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

import monitor_facturas as core

try:
    import pystray
    from PIL import Image, ImageDraw
except Exception:  # sin pystray el programa funciona igual, pero se minimiza en vez de ir a la bandeja
    pystray = None

APP = "Monitor de Facturas"
SUBTITULO = "Mecánica Toapanta"
VELOCIDADES = [("Normal", 1.0), ("Lenta", 1.5), ("Muy lenta", 2.0), ("Extremadamente lenta", 3.0)]
VERDE, ROJO, GRIS, AMBAR = "#1f9d55", "#c53030", "#718096", "#b7791f"
RUN_KEY = r"Software\Microsoft\Windows\CurrentVersion\Run"
RUN_NOMBRE = "MonitorFacturasToapanta"
LINK_PRUEBA = "https://mecanicatoapantavelez.com (mensaje de prueba)"


# ---------------------------------------------------------------------------
# Arranque con Windows
# ---------------------------------------------------------------------------

def comando_arranque() -> str:
    if getattr(sys, "frozen", False):  # programa empaquetado (.exe)
        return f'"{sys.executable}" --oculto'
    pyw = Path(sys.executable).with_name("pythonw.exe")
    interprete = pyw if pyw.exists() else Path(sys.executable)
    return f'"{interprete}" "{Path(__file__).resolve()}" --oculto'


def arranque_activo() -> bool:
    if sys.platform != "win32":
        return False
    import winreg
    try:
        with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY) as k:
            winreg.QueryValueEx(k, RUN_NOMBRE)
        return True
    except OSError:
        return False


def fijar_arranque(activo: bool) -> None:
    if sys.platform != "win32":
        return
    import winreg
    with winreg.OpenKey(winreg.HKEY_CURRENT_USER, RUN_KEY, 0, winreg.KEY_SET_VALUE) as k:
        if activo:
            winreg.SetValueEx(k, RUN_NOMBRE, 0, winreg.REG_SZ, comando_arranque())
        else:
            try:
                winreg.DeleteValue(k, RUN_NOMBRE)
            except OSError:
                pass


# ---------------------------------------------------------------------------
# Una sola copia abierta: si se abre otra vez, se muestra la ventana existente
# ---------------------------------------------------------------------------

def tomar_instancia_unica(cola: queue.Queue):
    servidor = socket.socket()
    try:
        servidor.bind(("127.0.0.1", core.LOCK_PORT))
    except OSError:
        try:
            with socket.create_connection(("127.0.0.1", core.LOCK_PORT), timeout=2) as c:
                c.sendall(b"mostrar")
        except OSError:
            pass
        return None
    servidor.listen(2)

    def escuchar():
        while True:
            try:
                conexion, _ = servidor.accept()
            except OSError:
                return
            with conexion:
                if conexion.recv(16) == b"mostrar":
                    cola.put(("mostrar",))

    threading.Thread(target=escuchar, daemon=True).start()
    return servidor


def dibujar_icono(color: str):
    img = Image.new("RGBA", (64, 64), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((2, 2, 62, 62), radius=14, fill=color)
    d.rectangle((17, 12, 47, 52), fill="white")            # hoja
    for y in (21, 29, 37):
        d.rectangle((22, y, 42, y + 3), fill=color)         # renglones
    d.rectangle((22, 45, 34, 47), fill=color)
    return img


class ManejadorCola(logging.Handler):
    """Lleva los mensajes del monitor a la ventana sin congelarla."""

    def __init__(self, cola: queue.Queue):
        super().__init__()
        self.cola = cola
        self.setFormatter(logging.Formatter("%(asctime)s   %(message)s", "%H:%M:%S"))

    def emit(self, record):
        self.cola.put(("log", self.format(record).split("\n")[0], record.levelno))


# ---------------------------------------------------------------------------
# Ventana
# ---------------------------------------------------------------------------

class App:
    def __init__(self, oculto: bool, servidor):
        self.servidor = servidor
        self.cola: queue.Queue = queue.Queue()
        core.configurar_log(consola=False)
        core.log.addHandler(ManejadorCola(self.cola))
        self.cfg = core.cargar_config()

        self.monitor: core.Monitor | None = None
        self.hilo: threading.Thread | None = None
        self.reiniciar_al_detener = False
        self.saliendo = False
        self.aviso_bandeja_mostrado = False
        self.tray = None

        self.root = tk.Tk()
        self.root.title(f"{APP} — {SUBTITULO}")
        self.root.geometry("780x600")
        self.root.minsize(680, 520)
        self.root.protocol("WM_DELETE_WINDOW", self.cerrar_ventana)
        self._estilos()
        self._construir()
        self._cargar_campos()
        self._crear_bandeja()

        self.root.after(150, self._atender_cola)
        self.root.after(500, self._refrescar)
        if oculto:
            self._ocultar()
        if self.cfg.getboolean("general", "iniciar_automaticamente", fallback=True) \
                and not core.problemas_config(self.cfg):
            self.root.after(800, self.iniciar)
        elif core.problemas_config(self.cfg):
            self.root.after(300, lambda: self.pestanas.select(self.tab_config))
            core.log.info("Bienvenido. Completa la configuración para empezar.")

    # ----- apariencia -----------------------------------------------------

    def _estilos(self):
        st = ttk.Style(self.root)
        if "vista" in st.theme_names():
            st.theme_use("vista")
        elif "clam" in st.theme_names():
            st.theme_use("clam")
        base = ("Segoe UI", 10) if sys.platform == "win32" else ("DejaVu Sans", 10)
        self.fuente = base
        from tkinter import font as tkfont
        for nombre in ("TkDefaultFont", "TkTextFont", "TkMenuFont", "TkHeadingFont"):
            try:
                tkfont.nametofont(nombre).configure(family=base[0], size=base[1])
            except tk.TclError:
                pass
        st.configure(".", font=base)
        st.configure("Titulo.TLabel", font=(base[0], 15, "bold"))
        st.configure("Estado.TLabel", font=(base[0], 13, "bold"))
        st.configure("Numero.TLabel", font=(base[0], 20, "bold"))
        st.configure("Suave.TLabel", foreground=GRIS)
        st.configure("Grande.TButton", font=(base[0], 11, "bold"), padding=(18, 8))
        st.configure("Treeview", rowheight=24)

    def _construir(self):
        cab = ttk.Frame(self.root, padding=(16, 12, 16, 4))
        cab.pack(fill="x")
        ttk.Label(cab, text=APP, style="Titulo.TLabel").pack(side="left")
        ttk.Label(cab, text=f"  {SUBTITULO}", style="Suave.TLabel").pack(side="left", pady=(5, 0))

        self.pestanas = ttk.Notebook(self.root)
        self.pestanas.pack(fill="both", expand=True, padx=12, pady=(4, 12))
        self.tab_inicio = ttk.Frame(self.pestanas, padding=14)
        self.tab_config = ttk.Frame(self.pestanas, padding=14)
        self.tab_hist = ttk.Frame(self.pestanas, padding=14)
        self.pestanas.add(self.tab_inicio, text="  Inicio  ")
        self.pestanas.add(self.tab_config, text="  Configuración  ")
        self.pestanas.add(self.tab_hist, text="  Historial  ")
        self._construir_inicio()
        self._construir_config()
        self._construir_historial()

    def _construir_inicio(self):
        t = self.tab_inicio
        fila = ttk.Frame(t)
        fila.pack(fill="x")
        self.punto = tk.Canvas(fila, width=18, height=18, highlightthickness=0, bg=self.root.cget("bg"))
        self.punto.pack(side="left", padx=(0, 8))
        cuadro = ttk.Frame(fila)
        cuadro.pack(side="left", fill="x", expand=True)
        self.lbl_estado = ttk.Label(cuadro, text="Detenido", style="Estado.TLabel")
        self.lbl_estado.pack(anchor="w")
        self.lbl_detalle = ttk.Label(cuadro, text="", style="Suave.TLabel")
        self.lbl_detalle.pack(anchor="w")
        self.btn_marcha = ttk.Button(fila, text="Iniciar", style="Grande.TButton", command=self.alternar)
        self.btn_marcha.pack(side="right")

        nums = ttk.Frame(t, padding=(0, 14, 0, 6))
        nums.pack(fill="x")
        self.num_enviadas = self._contador(nums, "Enviadas hoy")
        self.num_errores = self._contador(nums, "Con error hoy")
        self.num_espera = self._contador(nums, "En la carpeta")

        acc = ttk.Frame(t)
        acc.pack(fill="x", pady=(4, 8))
        ttk.Button(acc, text="Abrir carpeta de facturas",
                   command=lambda: self._abrir(self.cfg.get("carpetas", "entrada"))).pack(side="left")
        ttk.Button(acc, text="Ver enviadas",
                   command=lambda: self._abrir(self.cfg.get("carpetas", "enviadas"))).pack(side="left", padx=6)
        ttk.Button(acc, text="Ver con error",
                   command=lambda: self._abrir(self.cfg.get("carpetas", "errores"))).pack(side="left")

        prueba = ttk.LabelFrame(t, text=" Probar el envío ", padding=10)
        prueba.pack(fill="x", pady=(4, 8))
        ttk.Label(prueba, text="Celular:").pack(side="left")
        self.var_tel_prueba = tk.StringVar()
        ttk.Entry(prueba, textvariable=self.var_tel_prueba, width=16).pack(side="left", padx=6)
        self.btn_prueba = ttk.Button(prueba, text="Enviar mensaje de prueba", command=self.probar)
        self.btn_prueba.pack(side="left")

        ttk.Label(t, text="Actividad", style="Suave.TLabel").pack(anchor="w", pady=(6, 2))
        marco = ttk.Frame(t)
        marco.pack(fill="both", expand=True)
        self.txt_log = tk.Text(marco, height=8, wrap="word", state="disabled", relief="flat",
                               bg="#f7f7f7", padx=8, pady=6, font=(self.fuente[0], 9))
        barra = ttk.Scrollbar(marco, command=self.txt_log.yview)
        self.txt_log.configure(yscrollcommand=barra.set)
        self.txt_log.pack(side="left", fill="both", expand=True)
        barra.pack(side="right", fill="y")
        self.txt_log.tag_configure("aviso", foreground=AMBAR)
        self.txt_log.tag_configure("error", foreground=ROJO)
        self.txt_log.tag_configure("ok", foreground=VERDE)

        pie = ttk.Frame(t)
        pie.pack(fill="x", pady=(8, 0))
        ttk.Label(pie, text="Al cerrar esta ventana el programa sigue trabajando junto al reloj.",
                  style="Suave.TLabel").pack(side="left")
        ttk.Button(pie, text="Salir del programa", command=self.salir).pack(side="right")

    def _contador(self, padre, texto):
        f = ttk.Frame(padre, padding=(0, 0, 36, 0))
        f.pack(side="left")
        var = tk.StringVar(value="0")
        ttk.Label(f, textvariable=var, style="Numero.TLabel").pack(anchor="w")
        ttk.Label(f, text=texto, style="Suave.TLabel").pack(anchor="w")
        return var

    def _construir_config(self):
        pie = ttk.Frame(self.tab_config)
        pie.pack(side="bottom", fill="x", pady=(8, 0))
        ttk.Button(pie, text="Guardar", style="Grande.TButton", command=self.guardar).pack(side="right")
        ttk.Label(pie, text="Los cambios se aplican al presionar Guardar.", style="Suave.TLabel").pack(side="left")

        lienzo = tk.Canvas(self.tab_config, highlightthickness=0, bg=self.root.cget("bg"))
        barra = ttk.Scrollbar(self.tab_config, orient="vertical", command=lienzo.yview)
        lienzo.configure(yscrollcommand=barra.set)
        barra.pack(side="right", fill="y")
        lienzo.pack(side="left", fill="both", expand=True)
        t = ttk.Frame(lienzo, padding=(0, 0, 12, 0))
        ventana = lienzo.create_window((0, 0), window=t, anchor="nw")
        t.bind("<Configure>", lambda e: lienzo.configure(scrollregion=lienzo.bbox("all")))
        lienzo.bind("<Configure>", lambda e: lienzo.itemconfigure(ventana, width=e.width))

        def rueda(e):
            if e.num == 4 or e.delta > 0:
                lienzo.yview_scroll(-2, "units")
            else:
                lienzo.yview_scroll(2, "units")
        lienzo.bind("<Enter>", lambda e: (lienzo.bind_all("<MouseWheel>", rueda),
                                          lienzo.bind_all("<Button-4>", rueda), lienzo.bind_all("<Button-5>", rueda)))
        lienzo.bind("<Leave>", lambda e: (lienzo.unbind_all("<MouseWheel>"),
                                          lienzo.unbind_all("<Button-4>"), lienzo.unbind_all("<Button-5>")))
        t.columnconfigure(1, weight=1)
        r = 0

        def titulo(texto):
            nonlocal r
            ttk.Label(t, text=texto, font=(self.fuente[0], 10, "bold")).grid(
                row=r, column=0, columnspan=3, sticky="w", pady=(10 if r else 0, 4))
            r += 1

        def carpeta(etiqueta, var):
            nonlocal r
            ttk.Label(t, text=etiqueta).grid(row=r, column=0, sticky="w", padx=(0, 10), pady=2)
            ttk.Entry(t, textvariable=var).grid(row=r, column=1, sticky="ew", pady=2)
            ttk.Button(t, text="Buscar…", command=lambda: self._elegir_carpeta(var)).grid(
                row=r, column=2, padx=(6, 0), pady=2)
            r += 1

        titulo("Carpetas")
        self.var_entrada, self.var_enviadas, self.var_errores = tk.StringVar(), tk.StringVar(), tk.StringVar()
        carpeta("Facturas nuevas", self.var_entrada)
        carpeta("Facturas enviadas", self.var_enviadas)
        carpeta("Facturas con error", self.var_errores)

        titulo("Conexión con la página")
        ttk.Label(t, text="Clave de subida").grid(row=r, column=0, sticky="w", pady=2)
        self.var_clave = tk.StringVar()
        self.ent_clave = ttk.Entry(t, textvariable=self.var_clave, show="•")
        self.ent_clave.grid(row=r, column=1, sticky="ew", pady=2)
        cl = ttk.Frame(t)
        cl.grid(row=r, column=2, padx=(6, 0))
        self.var_ver_clave = tk.BooleanVar()
        ttk.Checkbutton(cl, text="Ver", variable=self.var_ver_clave,
                        command=lambda: self.ent_clave.configure(show="" if self.var_ver_clave.get() else "•")
                        ).pack(side="left")
        r += 1
        ttk.Button(t, text="Generar clave nueva…", command=self.generar_clave).grid(
            row=r, column=1, sticky="w", pady=(0, 2))
        r += 1

        titulo("Mensaje de WhatsApp")
        ttk.Label(t, text="Negocio").grid(row=r, column=0, sticky="w", pady=2)
        self.var_negocio = tk.StringVar()
        ttk.Entry(t, textvariable=self.var_negocio).grid(row=r, column=1, sticky="ew", pady=2)
        r += 1
        ttk.Label(t, text="Mensaje").grid(row=r, column=0, sticky="nw", pady=2)
        self.txt_plantilla = tk.Text(t, height=4, wrap="word", relief="solid", borderwidth=1,
                                     font=self.fuente, padx=6, pady=4)
        self.txt_plantilla.grid(row=r, column=1, columnspan=2, sticky="ew", pady=2)
        self.txt_plantilla.bind("<KeyRelease>", lambda e: self._vista_previa())
        r += 1
        ttk.Label(t, text="Puedes usar:  {saludo}   {negocio}   {link}",
                  style="Suave.TLabel").grid(row=r, column=1, columnspan=2, sticky="w")
        r += 1
        self.lbl_previa = ttk.Label(t, text="", style="Suave.TLabel", wraplength=480, justify="left")
        self.lbl_previa.grid(row=r, column=1, columnspan=2, sticky="w", pady=(2, 0))
        r += 1
        self.var_adjuntar = tk.BooleanVar()
        ttk.Checkbutton(t, text="Adjuntar también el PDF de la factura",
                        variable=self.var_adjuntar).grid(row=r, column=1, sticky="w", pady=(4, 0))
        r += 1

        titulo("Esta computadora")
        ttk.Label(t, text="Velocidad de la PC").grid(row=r, column=0, sticky="w", pady=2)
        self.var_velocidad = tk.StringVar()
        ttk.Combobox(t, textvariable=self.var_velocidad, state="readonly", width=24,
                     values=[n for n, _ in VELOCIDADES]).grid(row=r, column=1, sticky="w", pady=2)
        r += 1
        ttk.Label(t, text="Si los mensajes salen incompletos, elige una velocidad más lenta.",
                  style="Suave.TLabel").grid(row=r, column=1, columnspan=2, sticky="w")
        r += 1
        self.var_arranque = tk.BooleanVar()
        ttk.Checkbutton(t, text="Abrir este programa al encender la computadora",
                        variable=self.var_arranque).grid(row=r, column=1, columnspan=2, sticky="w", pady=(6, 0))
        r += 1
        self.var_auto = tk.BooleanVar()
        ttk.Checkbutton(t, text="Empezar a enviar facturas apenas se abre",
                        variable=self.var_auto).grid(row=r, column=1, columnspan=2, sticky="w")
        r += 1

    def _construir_historial(self):
        t = self.tab_hist
        cols = ("fecha", "telefono", "archivo", "estado", "detalle")
        nombres = ("Fecha", "Teléfono", "Archivo", "Estado", "Detalle")
        anchos = (150, 115, 170, 75, 260)
        marco = ttk.Frame(t)
        marco.pack(fill="both", expand=True)
        self.tabla = ttk.Treeview(marco, columns=cols, show="headings")
        for c, n, a in zip(cols, nombres, anchos):
            self.tabla.heading(c, text=n)
            self.tabla.column(c, width=a, anchor="w", stretch=(c == "detalle"))
        barra = ttk.Scrollbar(marco, command=self.tabla.yview)
        self.tabla.configure(yscrollcommand=barra.set)
        self.tabla.pack(side="left", fill="both", expand=True)
        barra.pack(side="right", fill="y")
        self.tabla.tag_configure("ERROR", foreground=ROJO)
        pie = ttk.Frame(t)
        pie.pack(fill="x", pady=(8, 0))
        ttk.Button(pie, text="Actualizar", command=self._cargar_historial).pack(side="left")
        ttk.Button(pie, text="Abrir en Excel", command=lambda: self._abrir(core.HISTORY_FILE)).pack(side="left", padx=6)

    # ----- configuración ----------------------------------------------------

    def _cargar_campos(self):
        c = self.cfg
        self.var_entrada.set(c.get("carpetas", "entrada"))
        self.var_enviadas.set(c.get("carpetas", "enviadas"))
        self.var_errores.set(c.get("carpetas", "errores"))
        self.var_clave.set(c.get("servidor", "upload_key"))
        self.var_negocio.set(c.get("mensaje", "negocio"))
        self.txt_plantilla.delete("1.0", "end")
        self.txt_plantilla.insert("1.0", c.get("mensaje", "plantilla").replace("\\n", "\n"))
        self.var_adjuntar.set(c.getboolean("escritorio", "adjuntar_pdf", fallback=True))
        factor = c.getfloat("escritorio", "factor_lentitud", fallback=1.0)
        nombre = min(VELOCIDADES, key=lambda v: abs(v[1] - factor))[0]
        self.var_velocidad.set(nombre)
        self.var_arranque.set(arranque_activo())
        self.var_auto.set(c.getboolean("general", "iniciar_automaticamente", fallback=True))
        self._vista_previa()
        self._cargar_historial()

    def _plantilla_actual(self) -> str:
        return self.txt_plantilla.get("1.0", "end-1c").strip().replace("\r", "").replace("\n", "\\n")

    def _vista_previa(self):
        plantilla = self._plantilla_actual().replace("\\n", " ")
        try:
            texto = plantilla.format(saludo=core.saludo_segun_hora(), negocio=self.var_negocio.get(),
                                     link="https://mecanicatoapantavelez.com/access.php?token=…")
            self.lbl_previa.configure(text="Así se verá: " + texto, foreground=GRIS)
        except (KeyError, ValueError, IndexError):
            self.lbl_previa.configure(text="Revisa las llaves { } del mensaje.", foreground=ROJO)

    def guardar(self):
        c = self.cfg
        c.set("carpetas", "entrada", self.var_entrada.get().strip())
        c.set("carpetas", "enviadas", self.var_enviadas.get().strip())
        c.set("carpetas", "errores", self.var_errores.get().strip())
        c.set("servidor", "upload_key", self.var_clave.get().strip())
        c.set("mensaje", "negocio", self.var_negocio.get().strip())
        c.set("mensaje", "plantilla", self._plantilla_actual())
        c.set("escritorio", "adjuntar_pdf", "si" if self.var_adjuntar.get() else "no")
        c.set("escritorio", "factor_lentitud", str(dict(VELOCIDADES).get(self.var_velocidad.get(), 1.0)))
        c.set("general", "iniciar_automaticamente", "si" if self.var_auto.get() else "no")

        problemas = core.problemas_config(c)
        core.guardar_config(c)
        try:
            fijar_arranque(self.var_arranque.get())
        except OSError as e:
            messagebox.showwarning(APP, f"No se pudo configurar el inicio con Windows:\n{e}")

        if problemas:
            messagebox.showwarning(APP, "Se guardó, pero todavía falta:\n\n• " + "\n• ".join(problemas))
            return
        if self._corriendo():
            self.reiniciar_al_detener = True
            self.detener()
            messagebox.showinfo(APP, "Configuración guardada. El monitor se reinicia con los cambios.")
        else:
            messagebox.showinfo(APP, "Configuración guardada.")
            if messagebox.askyesno(APP, "¿Empezar a enviar facturas ahora?"):
                self.iniciar()
                self.pestanas.select(self.tab_inicio)

    def generar_clave(self):
        if self.var_clave.get().strip() and not messagebox.askyesno(
                APP, "Ya hay una clave. Si creas una nueva, también debes cambiarla en Hostinger "
                     "o las facturas dejarán de subirse.\n\n¿Crear una clave nueva?"):
            return
        clave = secrets.token_urlsafe(32)
        huella = hashlib.sha256(clave.encode()).hexdigest()
        self.var_clave.set(clave)

        v = tk.Toplevel(self.root)
        v.title("Clave nueva")
        v.transient(self.root)
        v.resizable(False, False)
        f = ttk.Frame(v, padding=16)
        f.pack(fill="both")
        ttk.Label(f, text="1.  Copia este código y pégalo en Hostinger, archivo config.php,\n"
                          "     en la línea 'upload_key_hash' (entre las comillas):").pack(anchor="w")
        e = ttk.Entry(f, width=70)
        e.insert(0, huella)
        e.configure(state="readonly")
        e.pack(fill="x", pady=6)

        def copiar():
            self.root.clipboard_clear()
            self.root.clipboard_append(huella)
            btn.configure(text="Copiado ✓")
        btn = ttk.Button(f, text="Copiar código", command=copiar)
        btn.pack(anchor="w")
        ttk.Label(f, text="\n2.  Cierra esta ventana y presiona Guardar.").pack(anchor="w")
        ttk.Button(f, text="Cerrar", command=v.destroy).pack(anchor="e", pady=(10, 0))
        v.grab_set()

    # ----- monitor ------------------------------------------------------------

    def _corriendo(self) -> bool:
        return self.hilo is not None and self.hilo.is_alive()

    def alternar(self):
        self.detener() if self._corriendo() else self.iniciar()

    def iniciar(self):
        if self._corriendo():
            return
        problemas = core.problemas_config(self.cfg)
        if problemas:
            self._mostrar()
            self.pestanas.select(self.tab_config)
            messagebox.showwarning(APP, "Antes de empezar falta:\n\n• " + "\n• ".join(problemas))
            return
        try:
            self.monitor = core.Monitor(self.cfg, al_evento=lambda *ev: self.cola.put(("evento",) + ev))
        except BaseException as e:  # incluye SystemExit de un método de envío no disponible
            messagebox.showerror(APP, f"No se pudo iniciar:\n{e}")
            return
        self.hilo = threading.Thread(target=self._trabajar, daemon=True)
        self.hilo.start()
        self._pintar_estado()

    def _trabajar(self):
        try:
            self.monitor.ejecutar()
        except BaseException:
            core.log.exception("El monitor se detuvo por un error")
        finally:
            self.cola.put(("detenido",))

    def detener(self):
        if self.monitor and self._corriendo():
            self.monitor.detener.set()
            self.lbl_estado.configure(text="Deteniendo…")
            self.btn_marcha.configure(state="disabled")

    def probar(self):
        tel = core.normalizar_telefono(self.var_tel_prueba.get())
        if not tel:
            messagebox.showwarning(APP, "Escribe un celular válido, por ejemplo 0959133066.")
            return
        if not messagebox.askokcancel(APP, f"Se abrirá WhatsApp y se enviará un mensaje de prueba a {tel}.\n\n"
                                           "No toques el teclado ni el mouse hasta que termine."):
            return
        cfg = self.cfg
        self.btn_prueba.configure(state="disabled", text="Enviando…")

        def tarea():
            try:
                with core.ENVIO_LOCK:
                    env = core.crear_enviador(cfg)
                    try:
                        env.enviar(tel, core.armar_mensaje(cfg, LINK_PRUEBA), ruta=None)
                    finally:
                        env.cerrar()
                core.log.info("✓ Mensaje de prueba enviado a %s", tel)
            except BaseException as e:
                core.log.error("✗ La prueba falló: %s", e)
            finally:
                self.cola.put(("prueba_fin",))

        threading.Thread(target=tarea, daemon=True).start()

    # ----- comunicación con los hilos -------------------------------------

    def _atender_cola(self):
        try:
            while True:
                msg = self.cola.get_nowait()
                tipo = msg[0]
                if tipo == "log":
                    self._log(msg[1], msg[2])
                elif tipo == "evento":
                    _, clase, tel, archivo, detalle = msg
                    if clase == "enviada":
                        self._notificar(f"Factura enviada a {tel}")
                    else:
                        self._notificar(f"No se pudo enviar {archivo}: {detalle}"[:250])
                    self._refrescar(una_vez=True)
                    self._cargar_historial()
                elif tipo == "detenido":
                    self._pintar_estado()
                    if self.reiniciar_al_detener:
                        self.reiniciar_al_detener = False
                        self.iniciar()
                elif tipo == "prueba_fin":
                    self.btn_prueba.configure(state="normal", text="Enviar mensaje de prueba")
                elif tipo == "mostrar":
                    self._mostrar()
                elif tipo == "alternar":
                    self.alternar()
                elif tipo == "carpeta":
                    self._abrir(self.cfg.get("carpetas", "entrada"))
                elif tipo == "salir":
                    self._mostrar()
                    self.salir()
        except queue.Empty:
            pass
        if not self.saliendo:
            self.root.after(150, self._atender_cola)

    def _log(self, texto: str, nivel: int):
        etiqueta = "error" if nivel >= logging.ERROR else "aviso" if nivel >= logging.WARNING \
            else "ok" if "✓" in texto else ""
        self.txt_log.configure(state="normal")
        self.txt_log.insert("end", texto + "\n", etiqueta)
        lineas = int(self.txt_log.index("end-1c").split(".")[0])
        if lineas > 400:
            self.txt_log.delete("1.0", f"{lineas - 400}.0")
        self.txt_log.see("end")
        self.txt_log.configure(state="disabled")

    def _pintar_estado(self):
        activo = self._corriendo() and not (self.monitor and self.monitor.detener.is_set())
        color = VERDE if activo else ROJO
        self.punto.delete("all")
        self.punto.create_oval(2, 2, 16, 16, fill=color, outline=color)
        self.lbl_estado.configure(text="Trabajando" if activo else "Detenido")
        self.lbl_detalle.configure(
            text=f"Vigilando {self.cfg.get('carpetas', 'entrada')}" if activo
            else "Las facturas nuevas no se están enviando.")
        self.btn_marcha.configure(text="Detener" if activo else "Iniciar", state="normal")
        if self.tray:
            self.tray.icon = dibujar_icono(VERDE if activo else GRIS)
            self.tray.title = f"{APP}: {'trabajando' if activo else 'detenido'}"
            self.tray.update_menu()

    def _refrescar(self, una_vez: bool = False):
        hoy = date.today().isoformat()
        filas = [f for f in core.leer_historial(500) if f.get("fecha", "").startswith(hoy)]
        self.num_enviadas.set(str(sum(f.get("estado") == "ENVIADA" for f in filas)))
        self.num_errores.set(str(sum(f.get("estado") == "ERROR" for f in filas)))
        try:
            carpeta = Path(self.cfg.get("carpetas", "entrada"))
            self.num_espera.set(str(sum(1 for p in carpeta.iterdir() if p.suffix.lower() == ".pdf")))
        except OSError:
            self.num_espera.set("–")
        if not una_vez and not self.saliendo:
            self.root.after(5000, self._refrescar)

    def _cargar_historial(self):
        self.tabla.delete(*self.tabla.get_children())
        for f in core.leer_historial(300):
            self.tabla.insert("", "end", tags=(f.get("estado", ""),), values=(
                f.get("fecha", ""), f.get("telefono", ""), f.get("archivo", ""),
                "Enviada" if f.get("estado") == "ENVIADA" else "Error", f.get("detalle", "")))

    # ----- bandeja del sistema y ventana -----------------------------------

    def _crear_bandeja(self):
        if pystray is None:
            return
        menu = pystray.Menu(
            pystray.MenuItem("Abrir", lambda i, it: self.cola.put(("mostrar",)), default=True),
            pystray.MenuItem(lambda it: "Detener" if self._corriendo() else "Iniciar",
                             lambda i, it: self.cola.put(("alternar",))),
            pystray.MenuItem("Abrir carpeta de facturas", lambda i, it: self.cola.put(("carpeta",))),
            pystray.Menu.SEPARATOR,
            pystray.MenuItem("Salir", lambda i, it: self.cola.put(("salir",))),
        )
        try:
            self.tray = pystray.Icon("facturas_toapanta", dibujar_icono(GRIS), APP, menu)
            self.tray.run_detached()
        except Exception:
            self.tray = None
        self._pintar_estado()

    def _notificar(self, texto: str):
        if self.tray:
            try:
                self.tray.notify(texto, APP)
            except Exception:
                pass

    def _ocultar(self):
        if self.tray:
            self.root.withdraw()
        else:
            self.root.iconify()

    def _mostrar(self):
        self.root.deiconify()
        self.root.lift()
        self.root.attributes("-topmost", True)
        self.root.after(300, lambda: self.root.attributes("-topmost", False))
        self.root.focus_force()

    def cerrar_ventana(self):
        self._ocultar()
        if self.tray and not self.aviso_bandeja_mostrado:
            self.aviso_bandeja_mostrado = True
            self._notificar("Sigo trabajando en segundo plano. Para abrirme, "
                            "haz clic en mi ícono junto al reloj.")

    def salir(self):
        if self._corriendo() and not messagebox.askyesno(
                APP, "Si sales, las facturas nuevas NO se enviarán hasta que vuelvas a abrir el programa.\n\n"
                     "¿Salir de todas formas?"):
            return
        self.saliendo = True
        if self.monitor:
            self.monitor.detener.set()
        if self.tray:
            try:
                self.tray.stop()
            except Exception:
                pass
        if self.servidor:
            self.servidor.close()
        self.root.destroy()

    # ----- utilidades ---------------------------------------------------------

    def _elegir_carpeta(self, var: tk.StringVar):
        ruta = filedialog.askdirectory(initialdir=var.get() or "C:\\", parent=self.root)
        if ruta:
            var.set(str(Path(ruta)))

    def _abrir(self, ruta):
        ruta = Path(ruta)
        try:
            if ruta.suffix == "":
                ruta.mkdir(parents=True, exist_ok=True)
            if sys.platform == "win32":
                os.startfile(str(ruta))
            else:
                core.log.info("(abrir %s)", ruta)
        except OSError as e:
            messagebox.showerror(APP, f"No se pudo abrir {ruta}:\n{e}")

    def correr(self):
        self.root.mainloop()


def main():
    oculto = "--oculto" in sys.argv
    cola_previa: queue.Queue = queue.Queue()
    servidor = tomar_instancia_unica(cola_previa)
    if servidor is None:
        return  # ya estaba abierto: se le pidió que se muestre
    if sys.platform == "win32":
        import ctypes
        try:
            ctypes.windll.shcore.SetProcessDpiAwareness(1)  # letras nítidas
        except Exception:
            pass
        ctypes.windll.kernel32.SetPriorityClass(ctypes.windll.kernel32.GetCurrentProcess(), 0x4000)
    app = App(oculto, servidor)
    # las peticiones "mostrar" de otra copia llegan por la cola previa
    def puente():
        while True:
            app.cola.put(cola_previa.get())
    threading.Thread(target=puente, daemon=True).start()
    app.correr()


if __name__ == "__main__":
    main()
