"""Pestaña «Pantalla del cliente» del programa (se mezcla con la clase App)."""

from __future__ import annotations

import threading
import tkinter as tk
from tkinter import messagebox, ttk

import monitor_facturas as core
import monitor_hdmi as hdmi
import pantalla_cliente as pc

APP = "Monitor de Facturas"
VERDE = "#17663a"
ROJO = "#a12622"
GRIS = "#5b6c75"
COLORES = ["#0d5c63", "#b45309", "#7c3aed", "#be123c", "#15803d", "#1d4ed8", "#a16207", "#0f766e"]


class PestanaPantalla:
    # ----- construcción --------------------------------------------------------
    def _construir_pantalla(self, tab):
        pc.completar_config(self.cfg)
        self.pantalla: pc.PantallaCliente | None = None
        self.monitor_hdmi: hdmi.MonitorHDMI | None = None
        self.hilo_pantalla = None
        self.lector_prueba = None
        self.ventanas_espera: dict[str, tuple] = {}

        lienzo = tk.Canvas(tab, highlightthickness=0, bg=self.root.cget("bg"))
        barra = ttk.Scrollbar(tab, orient="vertical", command=lienzo.yview)
        lienzo.configure(yscrollcommand=barra.set)
        barra.pack(side="right", fill="y")
        lienzo.pack(side="left", fill="both", expand=True)
        f = ttk.Frame(lienzo, padding=(0, 0, 12, 0))
        win = lienzo.create_window((0, 0), window=f, anchor="nw")
        f.bind("<Configure>", lambda e: lienzo.configure(scrollregion=lienzo.bbox("all")))
        lienzo.bind("<Configure>", lambda e: lienzo.itemconfigure(win, width=e.width))
        f.columnconfigure(1, weight=1)

        def rueda(e):
            lienzo.yview_scroll(-2 if (e.num == 4 or e.delta > 0) else 2, "units")
        lienzo.bind("<Enter>", lambda e: lienzo.bind_all("<MouseWheel>", rueda))
        lienzo.bind("<Leave>", lambda e: lienzo.unbind_all("<MouseWheel>"))
        r = 0

        def titulo(texto, ayuda=""):
            nonlocal r
            ttk.Label(f, text=texto, font=(self.fuente[0], 11, "bold")).grid(row=r, column=0, columnspan=3, sticky="w", pady=(10, 2)); r += 1
            if ayuda:
                ttk.Label(f, text=ayuda, style="Suave.TLabel", wraplength=680, justify="left").grid(row=r, column=0, columnspan=3, sticky="w"); r += 1

        ttk.Label(f, text="Mientras haces la factura en FactuSYS, un monitor conectado a esta PC (o un teléfono en el "
                          "mostrador) le muestra al cliente sus datos y el total. Sin factura en curso muestra el cartel: "
                          "hora, mensajes y formas de pago; eso se cambia en el panel de la página → Pantalla del cliente.",
                  wraplength=680, justify="left").grid(
            row=r, column=0, columnspan=3, sticky="w"); r += 1

        self.var_pant_activa = tk.BooleanVar()
        self.var_pant_esperar = tk.BooleanVar()
        ttk.Checkbutton(f, text="Activar la pantalla del cliente", variable=self.var_pant_activa).grid(
            row=r, column=0, columnspan=3, sticky="w", pady=(8, 0)); r += 1

        titulo("1. Dónde se muestra",
               "Monitor por cable (HDMI): es lo más rápido, los datos aparecen al instante y no depende del internet. "
               "Conecta el monitor, en Windows elige «Extender estas pantallas» (tecla Windows + P) y marca aquí cuál es.")
        self.var_hdmi = tk.BooleanVar()
        self.var_tel = tk.BooleanVar()
        self.var_monitor = tk.StringVar()
        ttk.Checkbutton(f, text="Mostrar en un monitor conectado a esta PC (HDMI)", variable=self.var_hdmi).grid(
            row=r, column=0, columnspan=3, sticky="w"); r += 1
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=3, sticky="w", pady=4); r += 1
        ttk.Label(fila, text="Monitor:").pack(side="left")
        self.cmb_monitor = ttk.Combobox(fila, textvariable=self.var_monitor, state="readonly", width=46)
        self.cmb_monitor.pack(side="left", padx=6)
        ttk.Button(fila, text="Buscar monitores", command=self._llenar_monitores).pack(side="left")
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=3, sticky="w", pady=(2, 4)); r += 1
        ttk.Button(fila, text="Abrir en el monitor", command=self._abrir_monitor).pack(side="left")
        ttk.Button(fila, text="Cerrar", command=self._cerrar_monitor).pack(side="left", padx=8)
        # vista en vivo de lo que se ve en el monitor del cliente
        vivo = ttk.Frame(f); vivo.grid(row=r, column=0, columnspan=3, sticky="w", pady=(6, 6)); r += 1
        self.lbl_vivo = tk.Label(vivo, bg="#050506", fg="#8aa0a8", width=48, height=12, text="Vista en vivo del monitor",
                                 cursor="hand2", font=(self.fuente[0], 9))
        self.lbl_vivo.pack(side="left")
        self.lbl_vivo.bind("<Button-1>", lambda e: self._vivo_grande())
        lado = ttk.Frame(vivo); lado.pack(side="left", padx=10, anchor="n")
        ttk.Label(lado, text="En vivo: lo que está viendo\nel cliente en el monitor.", justify="left").pack(anchor="w")
        ttk.Button(lado, text="Ver en grande", command=self._vivo_grande).pack(anchor="w", pady=6)
        self.vivo_grande = None
        self._vivo_foto = None
        self._vivo_ocupado = False
        self.root.after(1500, self._vivo_ciclo)
        ttk.Checkbutton(f, text="También en teléfonos o tablets (por internet)", variable=self.var_tel).grid(
            row=r, column=0, columnspan=3, sticky="w"); r += 1

        titulo("2. Marcar los campos de FactuSYS (una sola vez)",
               "Abre en FactuSYS una factura (Factura → Factura comercial), escribe un cliente de ejemplo y un producto con "
               "precio, SIN grabarla. Luego presiona «Marcar campos»: sale una foto de FactuSYS y marcas solo DOS "
               "recuadros: uno que abarque todos los datos del cliente (de «RUC» a «EMAIL Notif.») y otro que abarque "
               "Subtotal, IVA y Total. Incluye las etiquetas: el programa las usa para saber qué es cada dato.")
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=3, sticky="w", pady=4); r += 1
        ttk.Button(fila, text="Marcar campos", command=self._marcar_campos).pack(side="left")
        ttk.Button(fila, text="Probar lectura", command=lambda: self._probar_lectura(None)).pack(side="left", padx=8)
        self.lbl_campos = ttk.Label(f, text="", style="Suave.TLabel", wraplength=680, justify="left")
        self.lbl_campos.grid(row=r, column=0, columnspan=3, sticky="w"); r += 1

        titulo("3. Cartel, mensajes y formas de pago",
               "La hora, los mensajes, las imágenes de pago (QR, cuenta del banco) y los colores. Es lo mismo que el panel "
               "de la página: se guarda allá y lo ven el monitor y los teléfonos.")
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=3, sticky="w", pady=4); r += 1
        ttk.Button(fila, text="Editar cartel…", command=self._editar_cartel).pack(side="left")

        titulo("4. Guardar")
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=3, sticky="w", pady=4); r += 1
        ttk.Button(fila, text="Guardar", style="Grande.TButton", command=self.guardar_pantalla).pack(side="left")
        self.lbl_pant = ttk.Label(f, text="", foreground=GRIS, wraplength=680, justify="left")
        self.lbl_pant.grid(row=r, column=0, columnspan=3, sticky="w", pady=(8, 0)); r += 1
        self._cargar_pantalla()
        self.root.after(2000, self._pintar_pantalla)

    # ---- vista en vivo del monitor -----------------------------------------------------
    def _vivo_monitor(self):
        if not hdmi.activo(self.cfg) and not self.monitor_hdmi:
            return None
        return hdmi.elegido(self.cfg)

    def _vivo_ciclo(self):
        """Cada segundo, solo si la vista está a la vista (pestaña abierta o ventana grande)."""
        try:
            grande = self.vivo_grande is not None and self.vivo_grande.winfo_exists()
            visible = self.lbl_vivo.winfo_viewable() or grande
            if visible and not self._vivo_ocupado and not getattr(self, "saliendo", False):
                self._vivo_ocupado = True
                tam = (900, 506) if grande else (384, 216)
                threading.Thread(target=self._vivo_tomar, args=(tam, grande), daemon=True).start()
        except tk.TclError:
            pass
        self.root.after(1000, self._vivo_ciclo)

    def _vivo_tomar(self, tam, grande):
        texto, img = "", None
        try:
            m = self._vivo_monitor()
            if not m:
                texto = "Marca «Mostrar en un monitor conectado a esta PC»\npara ver aquí lo que ve el cliente."
            elif m["principal"]:
                texto = "El monitor elegido es el principal (el de FactuSYS).\nElige el monitor del cliente."
            else:
                from PIL import Image, ImageGrab
                img = ImageGrab.grab(bbox=(m["x"], m["y"], m["x"] + m["w"], m["y"] + m["h"]), all_screens=True)
                img.thumbnail(tam, Image.BILINEAR)
        except Exception as e:  # noqa: BLE001
            texto, img = f"No se pudo ver el monitor:\n{e}", None

        def poner():
            self._vivo_ocupado = False
            try:
                destino = self.lbl_vivo_g if grande else self.lbl_vivo
                if img is None:
                    destino.configure(image="", text=texto, width=48 if not grande else 90, height=12 if not grande else 25)
                    return
                from PIL import ImageTk
                foto = ImageTk.PhotoImage(img)
                destino.configure(image=foto, text="", width=img.width, height=img.height)
                if grande:
                    self._vivo_foto_g = foto
                else:
                    self._vivo_foto = foto
            except tk.TclError:
                pass
        self.root.after(0, poner)

    def _vivo_grande(self):
        if self.vivo_grande is not None and self.vivo_grande.winfo_exists():
            self.vivo_grande.lift()
            return
        v = tk.Toplevel(self.root)
        v.title("Monitor del cliente — en vivo")
        v.configure(bg="#050506")
        self.lbl_vivo_g = tk.Label(v, bg="#050506", fg="#8aa0a8", text="Cargando…", width=90, height=25)
        self.lbl_vivo_g.pack(padx=8, pady=8)
        self.vivo_grande = v

    def _editar_cartel(self):
        import editor_cartel
        if not self.cfg.get("servidor", "upload_key", fallback="").strip():
            messagebox.showwarning(APP, "Primero configura la conexión con la página (pestaña Configuración).")
            return
        editor_cartel.EditorCartel(self)

    def _llenar_monitores(self):
        self.lista_monitores = hdmi.monitores()
        textos = [hdmi.describir(m) for m in self.lista_monitores]
        self.cmb_monitor.configure(values=textos)
        n = self.cfg.getint(pc.SECCION, "monitor_numero", fallback=2)
        actual = next((t for m, t in zip(self.lista_monitores, textos) if m["n"] == n), None)
        if actual is None:
            otro = next((t for m, t in zip(self.lista_monitores, textos) if not m["principal"]), textos[0] if textos else "")
            actual = otro
        self.var_monitor.set(actual or "No se encontró ningún monitor")
        if len(self.lista_monitores) < 2:
            self.lbl_pant.configure(text="Windows ve un solo monitor: conecta el HDMI y elige «Extender» con Windows + P.",
                                    foreground=ROJO)

    def _monitor_marcado(self) -> int:
        texto = self.var_monitor.get()
        for m in getattr(self, "lista_monitores", []):
            if hdmi.describir(m) == texto:
                return m["n"]
        return self.cfg.getint(pc.SECCION, "monitor_numero", fallback=2)

    def _abrir_monitor(self):
        self.guardar_pantalla(reiniciar=False)
        if not self.monitor_hdmi:
            self.monitor_hdmi = hdmi.MonitorHDMI(self.cfg)
            self.monitor_hdmi.iniciar()
            self._monitor_suelto = True          # abierto a mano, sin el monitor de facturas corriendo
        self.monitor_hdmi.intentos_lugar = 0
        self.monitor_hdmi.abrir_ventana()
        self.lbl_pant.configure(text="Monitor: " + self.monitor_hdmi.estado, foreground=VERDE)

    def _cerrar_monitor(self):
        if self.monitor_hdmi:
            self.monitor_hdmi.cerrar_ventana()
            if getattr(self, "_monitor_suelto", False) and not self.pantalla:
                self.monitor_hdmi.detener()
                self.monitor_hdmi = None
        self.lbl_pant.configure(text="Monitor cerrado. Vuelve a abrirse solo al reiniciar el monitor de facturas si "
                                     "está marcado.", foreground=GRIS)

    def _cargar_pantalla(self):
        self.var_pant_activa.set(pc.activa(self.cfg))
        self.var_hdmi.set(hdmi.activo(self.cfg))
        self.var_tel.set(self.cfg.getboolean(pc.SECCION, "enviar_telefono", fallback=True))
        if not hasattr(self, "lista_monitores"):
            try:
                self._llenar_monitores()
            except Exception:  # noqa: BLE001
                self.lista_monitores = []
        marcados = [t.split(":")[0] for k, t, _ in pc.CAMPOS if pc.rect_de(self.cfg, k)]
        faltan = [t.split(":")[0] for t in pc.faltan_campos(self.cfg)]
        txt = ("Marcados: " + ", ".join(marcados) + ".") if marcados else "Todavía no hay recuadros marcados."
        if faltan:
            txt += "  Faltan: " + ", ".join(faltan) + "."
        self.lbl_campos.configure(text=txt, foreground=ROJO if faltan else VERDE)

    def guardar_pantalla(self, reiniciar: bool = True):
        activa = self.var_pant_activa.get()
        if activa and pc.faltan_campos(self.cfg):
            messagebox.showwarning(APP, "Antes de activar, marca los dos recuadros en «Marcar campos».")
            return
        self.cfg.set(pc.SECCION, "activa", "si" if activa else "no")
        self.cfg.set(pc.SECCION, "monitor_hdmi", "si" if self.var_hdmi.get() else "no")
        self.cfg.set(pc.SECCION, "monitor_numero", str(self._monitor_marcado()))
        self.cfg.set(pc.SECCION, "enviar_telefono", "si" if self.var_tel.get() else "no")
        if self.cfg.has_option(pc.SECCION, "esperar_confirmacion"):
            self.cfg.remove_option(pc.SECCION, "esperar_confirmacion")
        core.guardar_config(self.cfg)
        self._cargar_pantalla()
        if not reiniciar:
            return
        if self._corriendo():                       # el monitor usa la pantalla para decidir: se reinicia
            self.reiniciar_al_detener = True
            self.detener()
        self.lbl_pant.configure(text="Guardado.", foreground=VERDE)

    # ----- hilo ---------------------------------------------------------------
    def crear_pantalla(self):
        """Antes de crear el monitor: la pantalla que aprueba los envíos (o None si está apagada)."""
        if hdmi.activo(self.cfg):
            if not self.monitor_hdmi:
                self.monitor_hdmi = hdmi.MonitorHDMI(self.cfg)
                self.monitor_hdmi.iniciar()
            self._monitor_suelto = False
        elif self.monitor_hdmi:
            self.monitor_hdmi.detener()
            self.monitor_hdmi = None
        if not pc.activa(self.cfg):
            self.pantalla = None
            return None
        self.pantalla = pc.PantallaCliente(self.cfg, al_evento=lambda *ev: self.cola.put(("pant",) + ev),
                                           monitor=self.monitor_hdmi)
        self.hilo_pantalla = threading.Thread(target=self.pantalla.ejecutar, daemon=True)
        self.hilo_pantalla.start()
        return self.pantalla

    def detener_pantalla(self, cerrar_monitor: bool = False):
        if self.pantalla:
            self.pantalla.detener.set()
        self.pantalla = None
        if self.monitor_hdmi and (cerrar_monitor or not hdmi.activo(self.cfg)):
            self.monitor_hdmi.detener()
            self.monitor_hdmi = None
        for v, *_ in list(self.ventanas_espera.values()):
            try:
                v.destroy()
            except tk.TclError:
                pass
        self.ventanas_espera.clear()

    def _pintar_pantalla(self):
        if self.pantalla:
            extra = f"  ·  Monitor: {self.monitor_hdmi.estado}" if self.monitor_hdmi else ""
            self.lbl_pant.configure(text=f"Pantalla del cliente: {self.pantalla.texto_estado}{extra}", foreground=VERDE)
        elif pc.activa(self.cfg):
            self.lbl_pant.configure(text="Pantalla del cliente: se activa al iniciar el monitor.", foreground=GRIS)
        if not self.saliendo:
            self.root.after(2000, self._pintar_pantalla)

    # ----- avisos (un PDF espera la confirmación) ------------------------------------
    def atender_mensaje_pantalla(self, msg) -> bool:
        if msg[0] == "evento" and len(msg) >= 4 and msg[1] in ("enviada", "error"):
            self._cerrar_espera(msg[3])
            return False                            # el resto de la app también lo atiende
        if msg[0] != "pant":
            return False
        _, clase, tel, archivo, texto = msg
        if clase == "pantalla_ok":
            self._cerrar_espera(archivo)
            self._notificar(f"El cliente confirmó sus datos. Enviando {archivo}…")
        elif clase in ("pantalla_espera", "pantalla_error", "pantalla_sin_red"):
            self._ventana_espera(archivo, tel, clase, texto)
            if clase == "pantalla_error":
                self._notificar(f"El cliente indicó un error: {archivo} no se envió."[:250])
        return True

    def _cerrar_espera(self, archivo):
        v = self.ventanas_espera.pop(archivo, None)
        if v:
            try:
                v[0].destroy()
            except tk.TclError:
                pass

    def _ventana_espera(self, archivo, tel, clase, texto):
        existente = self.ventanas_espera.get(archivo)
        if existente:
            v, lbl = existente
        else:
            v = tk.Toplevel(self.root)
            v.title("Pantalla del cliente")
            v.attributes("-topmost", True)
            v.resizable(False, False)
            lbl = tk.Label(v, text="", font=(self.fuente[0], 12, "bold"), wraplength=380, justify="left", padx=16, pady=12)
            lbl.pack(fill="x")
            tk.Label(v, text=f"Factura: {archivo}", fg=GRIS, padx=16).pack(anchor="w")
            b = ttk.Frame(v, padding=12); b.pack(fill="x")

            def elegir(decision):
                if self.pantalla:
                    self.pantalla.elegir(archivo, decision)
                self._cerrar_espera(archivo)
            ttk.Button(b, text="Enviar sin confirmar", command=lambda: elegir("enviar")).pack(side="left")
            ttk.Button(b, text="No enviar", command=lambda: (
                messagebox.askyesno(APP, f"¿No enviar {archivo}? Se moverá a la carpeta de errores.", parent=v)
                and elegir("descartar"))).pack(side="left", padx=8)
            v.protocol("WM_DELETE_WINDOW", lambda: None)   # se cierra al decidir o al enviarse
            self.ventanas_espera[archivo] = (v, lbl)
        color = ROJO if clase == "pantalla_error" else "#7a4a00" if clase == "pantalla_sin_red" else "#0d5c63"
        lbl.configure(text=texto, fg=color)
        v.update_idletasks()
        v.geometry(f"+{max(0, v.winfo_screenwidth() - v.winfo_reqwidth() - 30)}"
                   f"+{max(0, v.winfo_screenheight() - v.winfo_reqheight() - 90)}")
        try:
            v.deiconify(); v.lift()
        except tk.TclError:
            pass

    # ----- marcar campos -------------------------------------------------------------
    def _foto_factusys(self, listo):
        """Minimiza el programa, pone FactuSYS al frente y le toma una foto."""
        ventana = pc.VentanaFactuSYS(self.cfg)
        try:
            if not ventana.ctl.abierto():
                raise RuntimeError("FactuSYS no está abierto.")
            if not ventana.formulario_abierto():
                raise RuntimeError("Abre una factura en FactuSYS (Factura → Factura comercial) y llena un cliente de "
                                   "ejemplo sin grabarla.")
        except Exception as e:  # noqa: BLE001
            messagebox.showwarning(APP, str(e))
            return
        self.root.iconify()

        def tomar():
            try:
                ventana.ctl.al_frente()
            except Exception:  # noqa: BLE001
                pass
            self.root.after(900, sacar)

        def sacar():
            try:
                rect = ventana.rect_principal()
                img = ventana.captura(rect)
            except Exception as e:  # noqa: BLE001
                self.root.deiconify()
                messagebox.showerror(APP, f"No se pudo tomar la foto de FactuSYS: {e}")
                return
            self.root.deiconify()
            listo(img)
        self.root.after(700, tomar)

    def _marcar_campos(self):
        self._foto_factusys(self._editor_campos)

    def _editor_campos(self, img):
        from PIL import ImageTk
        v = tk.Toplevel(self.root)
        v.title("Marcar campos de FactuSYS")
        v.attributes("-topmost", True)
        sw, sh = v.winfo_screenwidth(), v.winfo_screenheight()
        esc = min(1.0, (sw * 0.92) / img.width, (sh * 0.74) / img.height)
        vista = img.resize((max(1, int(img.width * esc)), max(1, int(img.height * esc))))
        foto = ImageTk.PhotoImage(vista)
        arriba = ttk.Frame(v, padding=8); arriba.pack(fill="x")
        lbl = tk.Label(arriba, text="", font=(self.fuente[0], 12, "bold"), fg="#0d5c63", anchor="w", justify="left")
        lbl.pack(fill="x")
        botones = ttk.Frame(arriba); botones.pack(anchor="w", pady=(6, 0))
        cv = tk.Canvas(v, width=vista.width, height=vista.height, cursor="crosshair", highlightthickness=0)
        cv.pack(padx=8, pady=(0, 8))
        cv.create_image(0, 0, image=foto, anchor="nw")
        cv.foto = foto
        nuevos = {k: pc.rect_de(self.cfg, k) for k, _, _ in pc.CAMPOS}
        estado = {"i": 0, "x0": 0, "y0": 0, "temp": None}

        def dibujar():
            cv.delete("marca")
            for n, (k, texto, _) in enumerate(pc.CAMPOS):
                rr = nuevos.get(k)
                if not rr:
                    continue
                x, y, w, h = (c * esc for c in rr)
                col = COLORES[n % len(COLORES)]
                cv.create_rectangle(x, y, x + w, y + h, outline=col, width=3, tags="marca")
                cv.create_text(x + 3, y - 2, text=texto.split(":")[0], anchor="sw", fill=col,
                               font=(self.fuente[0], 9, "bold"), tags="marca")

        def mostrar():
            i = estado["i"]
            if i >= len(pc.CAMPOS):
                lbl.configure(text="Listo. Revisa los rectángulos y presiona «Guardar campos».")
                return
            k, texto, oblig = pc.CAMPOS[i]
            lbl.configure(text=f"{i + 1}/{len(pc.CAMPOS)} · Arrastra un rectángulo sobre: {texto}"
                               + ("" if oblig else "   (opcional: «Saltar» si no está)"))

        def bajar(e):
            estado["x0"], estado["y0"] = e.x, e.y
            estado["temp"] = cv.create_rectangle(e.x, e.y, e.x, e.y, outline="#f59e0b", width=2, dash=(4, 2))

        def mover(e):
            if estado["temp"]:
                cv.coords(estado["temp"], estado["x0"], estado["y0"], e.x, e.y)

        def soltar(e):
            if not estado["temp"]:
                return
            cv.delete(estado["temp"]); estado["temp"] = None
            x0, x1 = sorted((estado["x0"], e.x)); y0, y1 = sorted((estado["y0"], e.y))
            if x1 - x0 < 6 or y1 - y0 < 5 or estado["i"] >= len(pc.CAMPOS):
                return
            k = pc.CAMPOS[estado["i"]][0]
            nuevos[k] = (int(x0 / esc), int(y0 / esc), int((x1 - x0) / esc), int((y1 - y0) / esc))
            estado["i"] += 1
            dibujar(); mostrar()

        def atras():
            estado["i"] = max(0, estado["i"] - 1); mostrar()

        def saltar():
            if estado["i"] < len(pc.CAMPOS):
                k, _, oblig = pc.CAMPOS[estado["i"]]
                if oblig:
                    messagebox.showinfo(APP, "Este campo es necesario.", parent=v)
                    return
                nuevos[k] = None
                estado["i"] += 1
                dibujar(); mostrar()

        def guardar():
            faltan = [t for k, t, o in pc.CAMPOS if o and not nuevos.get(k)]
            if faltan:
                messagebox.showwarning(APP, "Faltan: " + ", ".join(faltan), parent=v)
                return
            for k, _, _ in pc.CAMPOS:
                rr = nuevos.get(k)
                if rr:
                    self.cfg.set(pc.SECCION, f"r_{k}", ",".join(str(c) for c in rr))
                elif self.cfg.has_option(pc.SECCION, f"r_{k}"):
                    self.cfg.remove_option(pc.SECCION, f"r_{k}")
            core.guardar_config(self.cfg)
            self._cargar_pantalla()
            v.destroy()
            if messagebox.askyesno(APP, "Campos guardados. ¿Probar ahora la lectura con esta misma foto?"):
                self._probar_lectura(img)

        ttk.Button(botones, text="Atrás", command=atras).pack(side="left")
        ttk.Button(botones, text="Saltar", command=saltar).pack(side="left", padx=4)
        ttk.Button(botones, text="Guardar campos", command=guardar).pack(side="left")
        cv.bind("<ButtonPress-1>", bajar); cv.bind("<B1-Motion>", mover); cv.bind("<ButtonRelease-1>", soltar)
        dibujar(); mostrar()
        v.focus_force()

    # ----- probar lectura --------------------------------------------------------------
    def _probar_lectura(self, img):
        if pc.faltan_campos(self.cfg):
            messagebox.showwarning(APP, "Primero marca los campos.")
            return
        if img is None:
            self._foto_factusys(self._probar_lectura)
            return
        self.lbl_pant.configure(text="Leyendo los campos… (la primera vez tarda unos segundos)", foreground=GRIS)
        if self.pantalla:
            lector = self.pantalla.ocr
        else:
            self.lector_prueba = self.lector_prueba or pc.LectorOCR()
            lector = self.lector_prueba

        def tarea():
            valores, crudos = {}, []
            try:
                for zona, _, _ in pc.CAMPOS:
                    rr = pc.rect_de(self.cfg, zona)
                    if not rr:
                        continue
                    x, y, w, h = rr
                    vals, crudo = pc.leer_zona(lector, zona, img.crop((x, y, x + w, y + h)))
                    valores.update(vals)
                    crudos.append(crudo)
                fuentes = self.pantalla.fuentes if self.pantalla else pc.FuentesCliente(self.cfg, pc.Pagina(self.cfg))
                ruc = valores.get("ruc", "")
                cliente = fuentes.buscar(ruc)
                verificados = fuentes.aplicar(pc.armar_datos(valores))
                for k in ("nombre", "direccion", "telefono", "email"):
                    valores[k] = verificados.get(k, valores.get(k, ""))
                filas = [f"{texto}:  {valores.get(k) or '— no se encontró —'}" for k, texto in pc.DATOS]
                if ruc:
                    filas.append("✓ Cédula/RUC con dígito verificador correcto." if pc.identificacion_valida(ruc)
                                 else "⚠ La cédula/RUC no cumple el dígito verificador: revisa el número o el recuadro.")
                    filas.append("✓ Cliente encontrado en FactuSYS: nombre, dirección, teléfono y correo exactos."
                                 if cliente else "Cliente nuevo (no está en FactuSYS): se usa lo que se lee de la pantalla.")
                if valores.get("total"):
                    filas.append("✓ El IVA cuadra con el subtotal." if pc.totales_cuadran(valores)
                                 else "⚠ El IVA leído no cuadra con el total: revisa el recuadro de totales.")
                datos = pc.armar_datos(valores)
                pie = ("\n\n✓ Así se lo mostraría la pantalla al cliente." if pc.datos_completos(datos)
                       else "\n\n⚠ Falta la cédula/RUC, el nombre o el total: revisa que los recuadros abarquen esos "
                            "datos con sus etiquetas y vuelve a marcarlos.")
                leido = "\n\nTexto leído:\n" + "\n".join(f"• {c[:300]}" for c in crudos)
                self.cola.put(("prueba_lectura", "\n".join(filas) + pie + leido, None))
            except Exception as e:  # noqa: BLE001
                self.cola.put(("prueba_lectura", None, str(e)))
        threading.Thread(target=tarea, daemon=True).start()

    def atender_prueba_lectura(self, msg) -> bool:
        if msg[0] != "prueba_lectura":
            return False
        _, texto, error = msg
        if error:
            self.lbl_pant.configure(text=f"No se pudo leer: {error}", foreground=ROJO)
            messagebox.showerror(APP, f"No se pudo leer la pantalla:\n{error}\n\nEsto necesita Windows 10 u 11 con el idioma "
                                      "español instalado (Configuración → Hora e idioma → Idioma).")
        else:
            self.lbl_pant.configure(text="Lectura de prueba terminada.", foreground=VERDE)
            messagebox.showinfo(APP, "Esto leyó el programa:\n\n" + texto)
        return True
