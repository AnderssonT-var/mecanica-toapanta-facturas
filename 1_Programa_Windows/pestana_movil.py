"""Pestaña «Facturación móvil» del programa con ventana (calibración y pruebas)."""

from __future__ import annotations

import json
import sys
import threading
import tkinter as tk
from tkinter import messagebox, simpledialog, ttk

import facturador as fz
import monitor_facturas as core

APP = "Monitor de Facturas"
GRIS, ROJO, VERDE = "#718096", "#c53030", "#1f9d55"
FASES = {"tomada": "Empezando", "grabando": "Grabando en FactuSYS", "registrada": "Grabada · esperando al SRI",
         "entregada": "PDF listo para WhatsApp", "terminada": "Terminada", "revisar": "Revisar en FactuSYS",
         "error": "No se hizo", "rechazada": "Rechazada por el SRI"}
VK_F8, VK_F9, VK_ESC = 0x77, 0x78, 0x1B


class PestanaMovil:
    """Se mezcla con App (app_facturas.pyw). Usa self.root, self.cfg, self.cola, self.fuente."""

    # ----- construcción ------------------------------------------------------
    def _construir_movil(self, t):
        fz.completar_config(self.cfg)
        lienzo = tk.Canvas(t, highlightthickness=0, bg=self.root.cget("bg"))
        barra = ttk.Scrollbar(t, orient="vertical", command=lienzo.yview)
        lienzo.configure(yscrollcommand=barra.set)
        pie = ttk.Frame(t)
        pie.pack(side="bottom", fill="x", pady=(8, 0))
        ttk.Button(pie, text="Guardar", style="Grande.TButton", command=self.guardar_movil).pack(side="right")
        self.lbl_movil = ttk.Label(pie, text="", style="Suave.TLabel")
        self.lbl_movil.pack(side="left")
        barra.pack(side="right", fill="y")
        lienzo.pack(side="left", fill="both", expand=True)
        f = ttk.Frame(lienzo, padding=(0, 0, 12, 0))
        win = lienzo.create_window((0, 0), window=f, anchor="nw")
        f.bind("<Configure>", lambda e: lienzo.configure(scrollregion=lienzo.bbox("all")))
        lienzo.bind("<Configure>", lambda e: lienzo.itemconfigure(win, width=e.width))
        f.columnconfigure(1, weight=1)

        def rueda(e):
            lienzo.yview_scroll(-2 if (e.num == 4 or e.delta > 0) else 2, "units")
        lienzo.bind("<Enter>", lambda e: (lienzo.bind_all("<MouseWheel>", rueda),
                                          lienzo.bind_all("<Button-4>", rueda), lienzo.bind_all("<Button-5>", rueda)))
        lienzo.bind("<Leave>", lambda e: (lienzo.unbind_all("<MouseWheel>"),
                                          lienzo.unbind_all("<Button-4>"), lienzo.unbind_all("<Button-5>")))
        r = 0

        def titulo(texto, ayuda=""):
            nonlocal r
            ttk.Label(f, text=texto, font=(self.fuente[0], 10, "bold")).grid(row=r, column=0, columnspan=4, sticky="w", pady=(12 if r else 0, 2))
            r += 1
            if ayuda:
                ttk.Label(f, text=ayuda, style="Suave.TLabel", wraplength=640, justify="left").grid(row=r, column=0, columnspan=4, sticky="w", pady=(0, 4))
                r += 1

        titulo("Facturar desde el celular",
               "Las facturas que haces en la página (admin → Facturar) llegan aquí. El programa abre FactuSYS, "
               "elige el perfil, hace la factura como tú (Ventas → Factura), la graba, guarda el PDF en el Escritorio "
               "con el celular del cliente y cierra FactuSYS. Después el PDF se envía por WhatsApp.")
        self.var_mov_activa = tk.BooleanVar()
        ttk.Checkbutton(f, text="Activar la facturación desde el celular", variable=self.var_mov_activa).grid(row=r, column=0, columnspan=3, sticky="w"); r += 1
        ttk.Label(f, text="Carpeta de FactuSYS").grid(row=r, column=0, sticky="w", pady=2)
        self.var_mov_dir = tk.StringVar()
        ttk.Entry(f, textvariable=self.var_mov_dir).grid(row=r, column=1, sticky="ew", pady=2)
        ttk.Button(f, text="Buscar…", command=lambda: self._elegir_carpeta(self.var_mov_dir)).grid(row=r, column=2, padx=6); r += 1
        ttk.Label(f, text="Guardar el PDF en").grid(row=r, column=0, sticky="w", pady=2)
        self.var_mov_pdf = tk.StringVar()
        ttk.Entry(f, textvariable=self.var_mov_pdf).grid(row=r, column=1, sticky="ew", pady=2)
        ttk.Button(f, text="Buscar…", command=lambda: self._elegir_carpeta(self.var_mov_pdf)).grid(row=r, column=2, padx=6); r += 1
        ttk.Label(f, text="Escribe «escritorio» para el Escritorio. Si no es la carpeta que vigila el monitor, "
                          "el PDF se copia también allá para enviarlo.", style="Suave.TLabel", wraplength=470,
                  justify="left").grid(row=r, column=1, columnspan=3, sticky="w"); r += 1
        ttk.Label(f, text="Esperar sin uso (segundos)").grid(row=r, column=0, sticky="w", pady=2)
        self.var_mov_inact = tk.StringVar()
        ttk.Spinbox(f, from_=10, to=900, increment=10, width=8, textvariable=self.var_mov_inact).grid(row=r, column=1, sticky="w"); r += 1
        ttk.Label(f, text="Solo usa el mouse si nadie tocó la PC en ese tiempo y FactuSYS está cerrado. Antes muestra "
                          "una cuenta regresiva: si mueves el mouse, lo deja para después.",
                  style="Suave.TLabel", wraplength=640, justify="left").grid(row=r, column=0, columnspan=4, sticky="w"); r += 1
        ttk.Label(f, text="Tras escribir el RUC y Enter, el cursor queda en").grid(row=r, column=0, sticky="w", pady=2)
        self.var_mov_cursor = tk.StringVar()
        ttk.Combobox(f, textvariable=self.var_mov_cursor, values=("nombre", "direccion", "ruc"), state="readonly",
                     width=10).grid(row=r, column=1, sticky="w"); r += 1
        ttk.Label(f, text="TAB desde «Teléfonos» hasta «EMAIL Notif.» (teléfono corto)").grid(row=r, column=0, sticky="w", pady=2)
        self.var_mov_tabs = tk.StringVar()
        ttk.Spinbox(f, from_=1, to=6, width=4, textvariable=self.var_mov_tabs).grid(row=r, column=1, sticky="w"); r += 1
        ttk.Label(f, text="Los datos se llenan como a mano: clic en el RUC, Enter, y TAB de campo en campo (nombre → "
                          "dirección → teléfono → email). Se escribe un solo teléfono. Cuando un campo se llena (RUC de 13, "
                          "celular de 10) FoxPro salta solo al siguiente y el programa no presiona Enter/TAB de más.",
                  style="Suave.TLabel", wraplength=640, justify="left").grid(row=r, column=0, columnspan=4, sticky="w"); r += 1

        titulo("1. Calibrar (una sola vez)",
               "Presiona «Calibración guiada» y haz una factura como siempre, con FactuSYS del tamaño en que se abre (NO lo maximices). En cada paso el "
               "programa te dice qué buscar: pon el mouse encima y presiona F8 (y luego haz tu clic normal). F9 salta "
               "un paso que no aparece; Esc termina. También puedes recapturar un punto solo con su botón «Capturar».")
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=4, sticky="w", pady=(0, 6)); r += 1
        self.btn_guiada = ttk.Button(fila, text="Calibración guiada (F8)", command=self._calibracion_guiada)
        self.btn_guiada.pack(side="left")
        self.vars_puntos = {}
        for nombre, texto in fz.PUNTOS:
            ttk.Label(f, text=texto).grid(row=r, column=0, columnspan=2, sticky="w", pady=1)
            var = tk.StringVar()
            self.vars_puntos[nombre] = var
            ttk.Entry(f, textvariable=var, width=11).grid(row=r, column=2, sticky="e", padx=6)
            ttk.Button(f, text="Capturar", command=lambda n=nombre, tx=texto: self._capturar(n, tx)).grid(row=r, column=3, sticky="e")
            r += 1

        titulo("2. Pasos (avanzado)",
               "Cada línea es «bloque = pasos» separados por «;». Pasos: clic PUNTO, tecla ENTER|ESC|TAB… (TAB*2 = dos veces), escribir TEXTO, "
               "borrar, borrar_todo, espera SEGUNDOS, anclar, guardar_como. Datos: {ruc} {nombre} {direccion} "
               "{telefono} {email} {detalle} {precio} {item}. «?con_iva paso» lo hace solo si se cumple (se pueden poner varias: «?a ?b paso»).")
        self.txt_receta = tk.Text(f, height=9, wrap="none", relief="solid", borderwidth=1,
                                  font=("Consolas", 9) if sys.platform == "win32" else ("DejaVu Sans Mono", 9))
        self.txt_receta.grid(row=r, column=0, columnspan=4, sticky="ew", pady=2); r += 1
        ttk.Button(f, text="Volver a los pasos originales", command=self._receta_original).grid(row=r, column=0, sticky="w", pady=(2, 0)); r += 1

        titulo("3. Probar sin facturar",
               "Abre FactuSYS, escribe un cliente y el producto, y sale con «Salir sin Grabar». No crea facturas.")
        fila = ttk.Frame(f); fila.grid(row=r, column=0, columnspan=4, sticky="w"); r += 1
        self.var_mov_prueba = tk.StringVar(value="01 - Nancy")
        ttk.Combobox(fila, textvariable=self.var_mov_prueba, state="readonly", width=14,
                     values=["01 - Nancy", "02 - Nelson"]).pack(side="left")
        self.btn_mov_prueba = ttk.Button(fila, text="Probar los pasos", command=self._probar_pasos)
        self.btn_mov_prueba.pack(side="left", padx=6)
        self.btn_mov_clientes = ttk.Button(fila, text="Enviar clientes a la página", command=self._enviar_clientes)
        self.btn_mov_clientes.pack(side="left")

        titulo("Facturas desde el celular en esta PC")
        cols = ("id", "cliente", "numero", "fase")
        self.tabla_movil = ttk.Treeview(f, columns=cols, show="headings", height=6)
        for c, n, a in zip(cols, ("#", "Cliente", "Factura", "Estado"), (50, 260, 90, 220)):
            self.tabla_movil.heading(c, text=n)
            self.tabla_movil.column(c, width=a, anchor="w")
        self.tabla_movil.grid(row=r, column=0, columnspan=4, sticky="ew"); r += 1

        self.aviso_win = None
        self.facturador = None
        self.hilo_fact = None
        self.guia = None

    # ----- datos ---------------------------------------------------------------
    def _cargar_movil(self):
        c = self.cfg
        fz.completar_config(c)
        self.var_mov_activa.set(c.getboolean("facturacion", "activa", fallback=False))
        self.var_mov_dir.set(c.get("facturacion", "factusys"))
        self.var_mov_pdf.set(c.get("facturacion", "carpeta_pdf", fallback="escritorio"))
        self.var_mov_inact.set(c.get("facturacion", "esperar_inactividad", fallback="60"))
        self.var_mov_cursor.set(c.get("facturacion", "cursor_tras_ruc", fallback="nombre"))
        self.var_mov_tabs.set(c.get("facturacion", "tabs_telefono_email", fallback="2"))
        for n, var in self.vars_puntos.items():
            var.set(c.get("factusys_puntos", n, fallback=""))
        self._pintar_receta({k: c.get("factusys_receta", k, fallback=v) for k, v in fz.RECETA_POR_DEFECTO.items()})
        self._pintar_movil()

    def _pintar_receta(self, receta: dict):
        self.txt_receta.delete("1.0", "end")
        self.txt_receta.insert("1.0", "\n".join(f"{k} = {v}" for k, v in receta.items()))

    def _receta_original(self):
        if messagebox.askyesno(APP, "¿Volver a los pasos originales (los del video)?"):
            self._pintar_receta(dict(fz.RECETA_POR_DEFECTO))

    def _volcar_movil(self) -> list[str]:
        c = self.cfg
        c.set("facturacion", "activa", "si" if self.var_mov_activa.get() else "no")
        c.set("facturacion", "factusys", self.var_mov_dir.get().strip())
        c.set("facturacion", "carpeta_pdf", self.var_mov_pdf.get().strip() or "escritorio")
        c.set("facturacion", "esperar_inactividad", str(int(float(self.var_mov_inact.get() or 60))))
        c.set("facturacion", "cursor_tras_ruc", self.var_mov_cursor.get() or "nombre")
        c.set("facturacion", "tabs_telefono_email", str(max(1, int(float(self.var_mov_tabs.get() or 2)))))
        for n, var in self.vars_puntos.items():
            c.set("factusys_puntos", n, var.get().replace(" ", ""))
        errores = []
        for linea in self.txt_receta.get("1.0", "end").splitlines():
            if not linea.strip():
                continue
            if "=" not in linea:
                errores.append(f"Línea sin «=»: {linea}")
                continue
            k, v = linea.split("=", 1)
            c.set("factusys_receta", k.strip(), v.strip())
        return errores

    def guardar_movil(self):
        errores = self._volcar_movil()
        core.guardar_config(self.cfg)
        if errores:
            messagebox.showwarning(APP, "Se guardó, pero revisa los pasos:\n\n" + "\n".join(errores))
        faltan = fz.problemas(self.cfg) if self.var_mov_activa.get() else []
        if faltan:
            messagebox.showwarning(APP, "Guardado. Para facturar desde el celular todavía falta:\n\n• " + "\n• ".join(faltan[:10]))
        else:
            messagebox.showinfo(APP, "Guardado.")
        self._reiniciar_facturador()

    # ----- calibración ---------------------------------------------------------------
    def _control(self):
        if sys.platform != "win32":
            raise fz.ErrorFactura("La calibración solo funciona en Windows.")
        self._volcar_movil()
        return fz.ControlFactuSYS(self.cfg, fz.DatosFactuSYS(self.cfg.get("facturacion", "factusys")))

    def _guardar_punto(self, nombre, pos, ctl=None, nuevo_origen=False):
        """Guarda un punto. Los de FactuSYS quedan referidos a dónde estaba la
        ventana en la calibración, para seguirla si después se abre en otro lugar."""
        if not nombre.startswith("perfil") and ctl is not None:
            ahora = ctl.origen()
            if ahora:
                if not self.cfg.has_section("factusys_ventana"):
                    self.cfg.add_section("factusys_ventana")
                guardado = self.cfg.get("factusys_ventana", "origen", fallback="").strip()
                if nuevo_origen or not guardado:
                    self.cfg.set("factusys_ventana", "origen", f"{ahora[0]},{ahora[1]}")
                else:
                    try:
                        gx, gy = (int(v) for v in guardado.split(","))
                        pos = (pos[0] - (ahora[0] - gx), pos[1] - (ahora[1] - gy))
                    except ValueError:
                        self.cfg.set("factusys_ventana", "origen", f"{ahora[0]},{ahora[1]}")
        self.vars_puntos[nombre].set(f"{pos[0]},{pos[1]}")
        self.cfg.set("factusys_puntos", nombre, f"{pos[0]},{pos[1]}")
        core.guardar_config(self.cfg)

    def _panel(self, texto, color="#1a202c"):
        v = tk.Toplevel(self.root)
        v.overrideredirect(True)
        v.attributes("-topmost", True)
        lbl = tk.Label(v, text=texto, font=(self.fuente[0], 12, "bold"), bg=color, fg="white", padx=16, pady=10,
                       justify="left", wraplength=440)
        lbl.pack()
        v.geometry(f"+{self.root.winfo_screenwidth() - 500}+{self.root.winfo_screenheight() - 190}")
        return v, lbl

    def _capturar(self, nombre, texto):
        try:
            ctl = self._control()
        except fz.ErrorFactura as e:
            messagebox.showwarning(APP, str(e))
            return
        v, lbl = self._panel("")

        def paso(n):
            if n > 0:
                lbl.configure(text=f"Pon el mouse sobre:\n{texto}\n…{n}")
                self.root.after(1000, paso, n - 1)
                return
            pos = ctl.posicion_mouse()
            v.destroy()
            self._guardar_punto(nombre, pos, ctl)
            self.lbl_movil.configure(text=f"«{texto}» guardado en {pos[0]},{pos[1]}")
        self.root.iconify()
        paso(5)

    def _calibracion_guiada(self):
        try:
            ctl = self._control()
        except fz.ErrorFactura as e:
            messagebox.showwarning(APP, str(e))
            return
        if not messagebox.askokcancel(APP, "Vas a hacer una factura como siempre mientras el programa aprende dónde está "
                                           "cada botón.\n\n• Abre FactuSYS tú mismo (te avisará en cada pantalla).\n"
                                           "• En cada paso: pon el mouse encima, presiona F8 y luego haz tu clic normal.\n"
                                           "• F9 salta un paso; Esc termina.\n\nPara no crear una factura, al final elige "
                                           "«Salir sin Grabar» (los últimos pasos se pueden capturar en otra factura)."):
            return
        import ctypes
        u32 = ctypes.windll.user32
        estado = {"i": 0, "teclas": {VK_F8: False, VK_F9: False, VK_ESC: False}}
        v, lbl = self._panel("", "#22543d")
        self.guia = v
        self.root.iconify()

        def mostrar():
            i = estado["i"]
            nombre, texto = fz.PUNTOS[i]
            lbl.configure(text=f"Paso {i + 1} de {len(fz.PUNTOS)}\n{texto}\n\nMouse encima + F8 · F9 saltar · Esc terminar")

        def apretada(vk):
            ahora = bool(u32.GetAsyncKeyState(vk) & 0x8000)
            antes = estado["teclas"][vk]
            estado["teclas"][vk] = ahora
            return ahora and not antes

        def terminar(msg):
            v.destroy()
            self.guia = None
            self.root.deiconify()
            self.lbl_movil.configure(text=msg)
            self._cargar_movil()

        lado = {"izq": False}

        def apartar():
            # si el mouse se acerca al panel, el panel se pasa a la otra esquina
            mx, my = ctl.posicion_mouse()
            x0, y0, w, h = v.winfo_rootx(), v.winfo_rooty(), v.winfo_width(), v.winfo_height()
            if x0 - 40 <= mx <= x0 + w + 40 and y0 - 40 <= my <= y0 + h + 40:
                lado["izq"] = not lado["izq"]
                x = 20 if lado["izq"] else self.root.winfo_screenwidth() - 500
                v.geometry(f"+{x}+{self.root.winfo_screenheight() - 190}")

        def vigilar():
            if self.guia is None:
                return
            try:
                apartar()
            except tk.TclError:
                pass
            if apretada(VK_ESC):
                terminar("Calibración terminada antes de tiempo.")
                return
            avanzar = False
            if apretada(VK_F8):
                nombre, _ = fz.PUNTOS[estado["i"]]
                # «Factura» es el primer punto de la ventana principal: ahí se anota
                # dónde está FactuSYS en esta calibración
                self._guardar_punto(nombre, ctl.posicion_mouse(), ctl, nuevo_origen=(nombre == "boton_factura"))
                avanzar = True
            elif apretada(VK_F9):
                avanzar = True
            if avanzar:
                estado["i"] += 1
                if estado["i"] >= len(fz.PUNTOS):
                    terminar("Calibración completa. Revisa los puntos y presiona Guardar.")
                    return
                mostrar()
            self.root.after(60, vigilar)

        mostrar()
        vigilar()

    def _probar_pasos(self):
        perfil = int(self.var_mov_prueba.get()[:2])
        ruc = simpledialog.askstring(APP, "RUC o cédula de un cliente ya registrado para la prueba:", parent=self.root)
        if not ruc:
            return
        if not messagebox.askokcancel(APP, "El programa abrirá FactuSYS, escribirá el cliente y el producto y saldrá con "
                                           "«Salir sin Grabar» (no se crea factura). Al final cierra FactuSYS.\n\n"
                                           "Cierra FactuSYS si está abierto y no toques el mouse hasta que termine."):
            return
        self._volcar_movil()
        core.guardar_config(self.cfg)
        self.btn_mov_prueba.configure(state="disabled", text="Probando…")

        def tarea():
            try:
                ag = fz.Facturador(self.cfg)
                pasos = ag.probar_pasos(perfil, {"ruc": ruc.strip(), "email": "prueba@ejemplo.com", "telefono": "",
                                                 "nombre": "", "direccion": "", "detalle": ""})
                self.cola.put(("movil_msg", "Prueba terminada:\n\n• " + "\n• ".join(pasos)
                               + "\n\nRevisa en FactuSYS que no se haya grabado nada.", False))
            except BaseException as e:
                self.cola.put(("movil_msg", f"La prueba se detuvo:\n{e}", True))
            finally:
                self.cola.put(("movil_fin",))
        threading.Thread(target=tarea, daemon=True).start()

    def _enviar_clientes(self):
        self._volcar_movil()
        self.btn_mov_clientes.configure(state="disabled")

        def tarea():
            try:
                ag = fz.Facturador(self.cfg)
                ag.sincronizar_clientes(forzar=True)
                ag.ping(forzar=True)
                self.cola.put(("movil_msg", "Clientes enviados a la página.", False))
            except BaseException as e:
                self.cola.put(("movil_msg", f"No se pudieron enviar:\n{e}", True))
            finally:
                self.cola.put(("movil_fin",))
        threading.Thread(target=tarea, daemon=True).start()

    # ----- hilo del facturador -----------------------------------------------------
    def iniciar_facturador(self):
        if self.hilo_fact and self.hilo_fact.is_alive():
            if self.facturador and self.facturador.detener.is_set():
                self.root.after(1000, self.iniciar_facturador)  # termina lo que estaba haciendo
            return
        if not self.cfg.getboolean("facturacion", "activa", fallback=False):
            return
        faltan = fz.problemas(self.cfg)
        if faltan:
            core.log.warning("Facturación móvil sin iniciar: %s", "; ".join(faltan[:3]))
            return
        self.facturador = fz.Facturador(self.cfg, al_evento=lambda *ev: self.cola.put(("fact",) + ev))
        self.hilo_fact = threading.Thread(target=self.facturador.ejecutar, daemon=True)
        self.hilo_fact.start()

    def detener_facturador(self):
        if self.facturador:
            self.facturador.detener.set()

    def _reiniciar_facturador(self):
        if not self._corriendo():
            return
        self.detener_facturador()

        def esperar():
            if self.hilo_fact and self.hilo_fact.is_alive():
                self.root.after(500, esperar)
            else:
                self.iniciar_facturador()
        self.root.after(500, esperar)

    def atender_mensaje_movil(self, msg) -> bool:
        tipo = msg[0]
        if tipo == "fact":
            clase = msg[1]
            if clase == "aviso":
                self._aviso(msg[2])
            elif clase == "factura":
                self._notificar(f"Factura {msg[3]} grabada en FactuSYS ({msg[4]})")
            elif clase == "error":
                self._notificar(f"{msg[3]}: {msg[4]}"[:250])
            self._pintar_movil()
            return True
        if tipo == "movil_msg":
            (messagebox.showwarning if msg[2] else messagebox.showinfo)(APP, msg[1])
            return True
        if tipo == "movil_fin":
            self.btn_mov_prueba.configure(state="normal", text="Probar los pasos")
            self.btn_mov_clientes.configure(state="normal")
            self._pintar_movil()
            return True
        return False

    def _aviso(self, texto):
        if self.aviso_win is not None:
            try:
                self.aviso_win.destroy()
            except tk.TclError:
                pass
            self.aviso_win = None
        if not texto:
            return
        v = self.aviso_win = tk.Toplevel(self.root)
        v.overrideredirect(True)
        v.attributes("-topmost", True)
        tk.Label(v, text=texto, font=(self.fuente[0], 11, "bold"), bg="#b7791f", fg="white",
                 padx=16, pady=12, wraplength=420, justify="left").pack()
        v.geometry(f"+{self.root.winfo_screenwidth() - 470}+{self.root.winfo_screenheight() - 150}")

    def _pintar_movil(self):
        try:
            datos = json.loads(fz.ESTADO_FILE.read_text(encoding="utf-8")).get("facturas", {})
        except (OSError, ValueError):
            datos = {}
        self.tabla_movil.delete(*self.tabla_movil.get_children())
        for fid, reg in sorted(datos.items(), key=lambda x: -int(x[0]))[:30]:
            self.tabla_movil.insert("", "end", values=(fid, reg.get("nombre", "") or reg.get("ruc", ""), reg.get("numero", ""),
                                                       FASES.get(reg.get("fase", ""), reg.get("fase", ""))))
        activo = self.hilo_fact is not None and self.hilo_fact.is_alive()
        if activo and self.facturador:
            self.lbl_movil.configure(text=f"Facturación móvil: {self.facturador.texto_estado}", foreground=VERDE)
        elif self.cfg.getboolean("facturacion", "activa", fallback=False):
            self.lbl_movil.configure(text="Facturación móvil: se inicia junto con el monitor", foreground=GRIS)
        else:
            self.lbl_movil.configure(text="Facturación móvil desactivada", foreground=GRIS)
