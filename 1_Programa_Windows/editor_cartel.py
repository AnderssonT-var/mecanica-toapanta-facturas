"""
Editor del cartel de la pantalla del cliente desde el programa (lo mismo que el panel de la
página → Pantalla del cliente): la hora, los mensajes, las formas de pago y el fondo.
Todo se guarda en la página, así el monitor y los teléfonos muestran lo mismo.
"""

from __future__ import annotations

import base64
import io
import threading
import tkinter as tk
from pathlib import Path
from tkinter import colorchooser, filedialog, messagebox, ttk

import requests

import monitor_hdmi as hdmi
import pantalla_cliente as pc

APP = "Monitor de Facturas"
ESTILOS = {"neon": "Neón", "led": "Letrero LED"}
VELOCIDADES = {"lenta": "Lenta", "normal": "Normal", "rapida": "Rápida"}
COLORES = ["#ff2bd6", "#ffb020", "#39ff7a", "#29d9ff", "#ff3b3b", "#b26bff", "#f4f4f4"]


def preparar_imagen(ruta: str) -> tuple[bytes, str]:
    """Imagen lista para subir (PNG o JPG de hasta 2400 px). De un PDF se toma la primera página."""
    from PIL import Image
    p = Path(ruta)
    if p.suffix.lower() == ".pdf":
        try:
            import pypdfium2 as pdfium
        except ImportError:
            raise RuntimeError("Para usar un PDF falta un componente. Sube el PDF desde el panel de la página "
                               "o usa una imagen (PNG o JPG).")
        doc = pdfium.PdfDocument(str(p))
        pagina = doc[0]
        ancho, alto = pagina.get_size()
        escala = 2200 / max(ancho, alto)
        img = pagina.render(scale=escala).to_pil().convert("RGB")
        doc.close()
        formato = "PNG"
    else:
        img = Image.open(p)
        formato = "PNG" if img.format == "PNG" or img.mode in ("RGBA", "LA", "P") else "JPEG"
        img = img.convert("RGBA" if formato == "PNG" else "RGB")
    lado = max(img.size)
    if lado > 2400:
        k = 2400 / lado
        img = img.resize((round(img.width * k), round(img.height * k)), Image.LANCZOS)
    salida = io.BytesIO()
    img.save(salida, formato, **({"quality": 90} if formato == "JPEG" else {"optimize": True}))
    datos = salida.getvalue()
    if len(datos) > 3_800_000:                  # todavía pesada: JPG
        salida = io.BytesIO()
        img.convert("RGB").save(salida, "JPEG", quality=85)
        datos = salida.getvalue()
    return datos, p.stem


class EditorCartel:
    def __init__(self, app):
        self.app = app
        self.cfg = app.cfg
        self.pagina = pc.Pagina(self.cfg)
        self.base = hdmi.base_portal(self.cfg)
        self.v = tk.Toplevel(app.root)
        self.v.title("Cartel de la pantalla del cliente")
        self.v.geometry("760x640")
        self.v.minsize(640, 480)
        self.v.transient(app.root)
        self.miniaturas: list = []
        self.filas_msg: list[dict] = []
        self.vars_img: dict[int, tk.BooleanVar] = {}
        self.maximo = 2

        pie = ttk.Frame(self.v, padding=10)
        pie.pack(side="bottom", fill="x")
        self.lbl = ttk.Label(pie, text="Cargando…", style="Suave.TLabel")
        self.lbl.pack(side="left")
        ttk.Button(pie, text="Guardar", style="Grande.TButton", command=self.guardar).pack(side="right")
        ttk.Button(pie, text="Cerrar", command=self.v.destroy).pack(side="right", padx=8)

        lienzo = tk.Canvas(self.v, highlightthickness=0, bg=app.root.cget("bg"))
        barra = ttk.Scrollbar(self.v, orient="vertical", command=lienzo.yview)
        lienzo.configure(yscrollcommand=barra.set)
        barra.pack(side="right", fill="y")
        lienzo.pack(side="left", fill="both", expand=True)
        self.f = ttk.Frame(lienzo, padding=(14, 10, 14, 10))
        win = lienzo.create_window((0, 0), window=self.f, anchor="nw")
        self.f.bind("<Configure>", lambda e: lienzo.configure(scrollregion=lienzo.bbox("all")))
        lienzo.bind("<Configure>", lambda e: lienzo.itemconfigure(win, width=e.width))

        def rueda(e):
            lienzo.yview_scroll(-2 if (getattr(e, "num", 0) == 4 or e.delta > 0) else 2, "units")
        self.v.bind("<MouseWheel>", rueda)
        self.v.bind("<Button-4>", rueda)
        self.v.bind("<Button-5>", rueda)
        threading.Thread(target=self._cargar, daemon=True).start()

    # ---- datos ---------------------------------------------------------------------
    def _cargar(self):
        try:
            j = self.pagina.api("cartel_leer")
            self.v.after(0, lambda: self._construir(j))
        except Exception as e:  # noqa: BLE001
            self.v.after(0, lambda: self.lbl.configure(text=f"No se pudo leer el cartel de la página: {e}"))

    def _titulo(self, texto):
        ttk.Label(self.f, text=texto, font=(self.app.fuente[0], 11, "bold")).pack(anchor="w", pady=(12, 4))

    def _boton_color(self, padre, var: tk.StringVar):
        b = tk.Button(padre, width=4, relief="flat", bg=var.get(), activebackground=var.get(), cursor="hand2")

        def elegir():
            c = colorchooser.askcolor(color=var.get(), parent=self.v)[1]
            if c:
                var.set(c.lower()); b.configure(bg=c, activebackground=c)
        b.configure(command=elegir)
        return b

    def _construir(self, j):
        for w in self.f.winfo_children():
            w.destroy()
        c = j["cartel"]
        self.maximo = int(j.get("maximo") or 2)
        self.c = c
        # Hora
        self._titulo("Hora")
        h = c["hora"]
        self.h_activa = tk.BooleanVar(value=h["activa"])
        self.h_estilo = tk.StringVar(value=ESTILOS[h["estilo"]])
        self.h_color = tk.StringVar(value=h["color"])
        self.h_seg = tk.IntVar(value=h["segundos"])
        self.h_formato = tk.StringVar(value="12 horas (3:27 PM)" if h["formato"] == "12" else "24 horas (15:27)")
        self.h_fecha = tk.BooleanVar(value=h["fecha"])
        ttk.Checkbutton(self.f, text="Mostrar la hora", variable=self.h_activa).pack(anchor="w")
        fila = ttk.Frame(self.f); fila.pack(anchor="w", pady=4)
        ttk.Label(fila, text="Estilo").pack(side="left")
        ttk.Combobox(fila, textvariable=self.h_estilo, values=list(ESTILOS.values()), state="readonly", width=12).pack(side="left", padx=(4, 12))
        ttk.Label(fila, text="Color").pack(side="left")
        self._boton_color(fila, self.h_color).pack(side="left", padx=(4, 12))
        ttk.Label(fila, text="Segundos").pack(side="left")
        ttk.Spinbox(fila, from_=3, to=120, textvariable=self.h_seg, width=5).pack(side="left", padx=(4, 12))
        ttk.Combobox(fila, textvariable=self.h_formato, values=["12 horas (3:27 PM)", "24 horas (15:27)"], state="readonly", width=17).pack(side="left")
        ttk.Checkbutton(self.f, text="Mostrar también la fecha", variable=self.h_fecha).pack(anchor="w")

        # Mensajes
        self._titulo("Mensajes")
        self.m_estilo = tk.StringVar(value=ESTILOS[c["estilo"]])
        self.m_seg = tk.IntVar(value=c["segundos_mensaje"])
        self.m_vel = tk.StringVar(value=VELOCIDADES[c["velocidad"]])
        fila = ttk.Frame(self.f); fila.pack(anchor="w", pady=4)
        ttk.Label(fila, text="Estilo").pack(side="left")
        ttk.Combobox(fila, textvariable=self.m_estilo, values=list(ESTILOS.values()), state="readonly", width=12).pack(side="left", padx=(4, 12))
        ttk.Label(fila, text="Segundos por mensaje").pack(side="left")
        ttk.Spinbox(fila, from_=2, to=60, textvariable=self.m_seg, width=5).pack(side="left", padx=(4, 12))
        ttk.Label(fila, text="Velocidad LED").pack(side="left")
        ttk.Combobox(fila, textvariable=self.m_vel, values=list(VELOCIDADES.values()), state="readonly", width=9).pack(side="left", padx=4)
        self.marco_msg = ttk.Frame(self.f); self.marco_msg.pack(fill="x")
        self.filas_msg = []
        for m in c["mensajes"]:
            self._fila_mensaje(m["texto"], m["color"])
        fila = ttk.Frame(self.f); fila.pack(anchor="w", pady=4)
        ttk.Button(fila, text="+ Agregar mensaje", command=lambda: self._fila_mensaje("", COLORES[len(self.filas_msg) % len(COLORES)], True)).pack(side="left")
        ttk.Label(fila, text="Hasta 12, de 60 letras.", style="Suave.TLabel").pack(side="left", padx=8)

        # Formas de pago
        self._titulo("Formas de pago")
        p = c["pago"]
        self.p_activa = tk.BooleanVar(value=p["activa"])
        self.p_seg = tk.IntVar(value=p["segundos"])
        self.p_reloj = tk.BooleanVar(value=p["reloj"])
        self.p_datos = tk.BooleanVar(value=p.get("en_datos", True))
        ttk.Checkbutton(self.f, text="Mostrar las formas de pago en el cartel", variable=self.p_activa).pack(anchor="w")
        fila = ttk.Frame(self.f); fila.pack(anchor="w", pady=2)
        ttk.Label(fila, text="Segundos en pantalla").pack(side="left")
        ttk.Spinbox(fila, from_=4, to=300, textvariable=self.p_seg, width=5).pack(side="left", padx=4)
        ttk.Checkbutton(self.f, text="La hora arriba de las imágenes", variable=self.p_reloj).pack(anchor="w")
        ttk.Checkbutton(self.f, text="Mostrarlas también junto a los datos y el total mientras se factura",
                        variable=self.p_datos).pack(anchor="w")
        ttk.Label(self.f, text=f"Marca 1 o {self.maximo} imágenes para mostrar:", style="Suave.TLabel").pack(anchor="w", pady=(6, 2))
        self.marco_img = ttk.Frame(self.f); self.marco_img.pack(fill="x")
        self._pintar_imagenes(j.get("imagenes") or [], p["imagenes"])
        fila = ttk.Frame(self.f); fila.pack(anchor="w", pady=6)
        ttk.Button(fila, text="Subir imagen o PDF…", command=self.subir).pack(side="left")

        # Fondo
        self._titulo("Fondo del cartel")
        self.fondo = tk.StringVar(value=c["fondo"])
        fila = ttk.Frame(self.f); fila.pack(anchor="w")
        self._boton_color(fila, self.fondo).pack(side="left")
        ttk.Label(fila, text="Negro se ve mejor con neón y LED.", style="Suave.TLabel").pack(side="left", padx=8)
        self.lbl.configure(text="Los cambios se ven en el monitor y en los teléfonos al guardar.")

    def _fila_mensaje(self, texto, color, enfocar=False):
        if len(self.filas_msg) >= 12:
            messagebox.showinfo(APP, "Máximo 12 mensajes.", parent=self.v)
            return
        fila = ttk.Frame(self.marco_msg); fila.pack(fill="x", pady=2)
        var_t, var_c = tk.StringVar(value=texto), tk.StringVar(value=color)
        e = ttk.Entry(fila, textvariable=var_t)
        e.pack(side="left", fill="x", expand=True)
        var_t.trace_add("write", lambda *a: var_t.set(var_t.get().upper()[:60]) if var_t.get() != var_t.get().upper()[:60] else None)
        self._boton_color(fila, var_c).pack(side="left", padx=6)
        d = {"fila": fila, "texto": var_t, "color": var_c}

        def quitar():
            fila.destroy(); self.filas_msg.remove(d)
        ttk.Button(fila, text="Quitar", command=quitar).pack(side="left")
        self.filas_msg.append(d)
        if enfocar:
            e.focus_set()

    def _pintar_imagenes(self, imagenes, elegidas):
        for w in self.marco_img.winfo_children():
            w.destroy()
        self.vars_img = {}
        self.miniaturas = []
        if not imagenes:
            ttk.Label(self.marco_img, text="Todavía no hay imágenes.", style="Suave.TLabel").pack(anchor="w")
            return
        for n, im in enumerate(imagenes):
            caja = ttk.Frame(self.marco_img, padding=4, relief="groove")
            caja.grid(row=n // 3, column=n % 3, padx=4, pady=4, sticky="n")
            lbl = ttk.Label(caja, text="…")
            lbl.pack()
            threading.Thread(target=self._miniatura, args=(im, lbl), daemon=True).start()
            ttk.Label(caja, text=im["nombre"][:26]).pack()
            var = tk.BooleanVar(value=im["id"] in elegidas)
            self.vars_img[im["id"]] = var
            fila = ttk.Frame(caja); fila.pack(fill="x")
            ttk.Checkbutton(fila, text="Mostrar", variable=var, command=lambda i=im["id"]: self._limitar(i)).pack(side="left")
            ttk.Button(fila, text="Borrar", command=lambda i=im["id"], nom=im["nombre"]: self.borrar(i, nom)).pack(side="right")

    def _miniatura(self, im, lbl):
        try:
            from PIL import Image, ImageTk
            r = requests.get(self.base + "pantalla.php", params={"img": im["id"], "h": im["h"]}, timeout=20)
            img = Image.open(io.BytesIO(r.content))
            img.thumbnail((200, 150))

            def poner():
                foto = ImageTk.PhotoImage(img)
                self.miniaturas.append(foto)
                lbl.configure(image=foto, text="")
            self.v.after(0, poner)
        except Exception:  # noqa: BLE001
            self.v.after(0, lambda: lbl.configure(text="(sin vista)"))

    def _limitar(self, cambiada):
        if sum(v.get() for v in self.vars_img.values()) > self.maximo:
            self.vars_img[cambiada].set(False)
            messagebox.showinfo(APP, f"Puedes mostrar 1 o {self.maximo} formas de pago a la vez.", parent=self.v)

    # ---- acciones --------------------------------------------------------------------
    def _armar(self) -> dict:
        inv = lambda d, v: next((k for k, t in d.items() if t == v), next(iter(d)))  # noqa: E731
        return {
            "hora": {"activa": self.h_activa.get(), "estilo": inv(ESTILOS, self.h_estilo.get()), "color": self.h_color.get(),
                     "segundos": int(self.h_seg.get() or 10), "formato": "24" if self.h_formato.get().startswith("24") else "12",
                     "fecha": self.h_fecha.get()},
            "mensajes": [{"texto": d["texto"].get().strip(), "color": d["color"].get()} for d in self.filas_msg if d["texto"].get().strip()],
            "estilo": inv(ESTILOS, self.m_estilo.get()), "segundos_mensaje": int(self.m_seg.get() or 5),
            "velocidad": inv(VELOCIDADES, self.m_vel.get()), "fondo": self.fondo.get(),
            "pago": {"activa": self.p_activa.get(), "segundos": int(self.p_seg.get() or 15), "reloj": self.p_reloj.get(),
                     "en_datos": self.p_datos.get(), "imagenes": [i for i, v in self.vars_img.items() if v.get()]},
        }

    def guardar(self, despues=None):
        try:
            cartel = self._armar()
        except (tk.TclError, ValueError):
            messagebox.showwarning(APP, "Revisa los números de segundos.", parent=self.v)
            return
        self.lbl.configure(text="Guardando…")

        def tarea():
            try:
                self.pagina.api("cartel_guardar", cartel=cartel)
                mon = getattr(self.app, "monitor_hdmi", None)
                if mon:
                    mon.actualizar()
                self.v.after(0, lambda: self.lbl.configure(text="Guardado. El monitor y los teléfonos ya lo muestran."))
                if despues:
                    self.v.after(0, despues)
            except Exception as e:  # noqa: BLE001
                self.v.after(0, lambda: self.lbl.configure(text=f"No se pudo guardar: {e}"))
        threading.Thread(target=tarea, daemon=True).start()

    def subir(self):
        ruta = filedialog.askopenfilename(parent=self.v, title="Imagen o PDF de la forma de pago",
                                          filetypes=[("Imagen o PDF", "*.png *.jpg *.jpeg *.webp *.pdf"), ("Todos", "*.*")])
        if not ruta:
            return
        self.lbl.configure(text="Preparando la imagen…")

        def tarea():
            try:
                datos, nombre = preparar_imagen(ruta)
                j = self.pagina.api("img_subir", nombre=nombre, datos=base64.b64encode(datos).decode("ascii"))
                texto = "Imagen subida y elegida para mostrarse." if j.get("elegida") else \
                    f"Imagen subida. Ya hay {self.maximo} elegidas: marca cuáles mostrar y guarda."
                self._recargar(texto)
            except Exception as e:  # noqa: BLE001
                self.v.after(0, lambda: self.lbl.configure(text=f"No se pudo subir: {e}"))
        threading.Thread(target=tarea, daemon=True).start()

    def borrar(self, id_, nombre):
        if not messagebox.askyesno(APP, f"¿Borrar «{nombre}»?", parent=self.v):
            return

        def tarea():
            try:
                self.pagina.api("img_borrar", id=id_)
                self._recargar("Imagen borrada.")
            except Exception as e:  # noqa: BLE001
                self.v.after(0, lambda: self.lbl.configure(text=f"No se pudo borrar: {e}"))
        threading.Thread(target=tarea, daemon=True).start()

    def _recargar(self, texto):
        """Vuelve a leer las imágenes sin perder lo que se estaba editando en el resto."""
        j = self.pagina.api("cartel_leer")
        mon = getattr(self.app, "monitor_hdmi", None)
        if mon:
            threading.Thread(target=mon.actualizar, daemon=True).start()

        def poner():
            elegidas = j["cartel"]["pago"]["imagenes"]
            self._pintar_imagenes(j.get("imagenes") or [], elegidas)
            if elegidas:
                self.p_activa.set(j["cartel"]["pago"]["activa"])
            self.lbl.configure(text=texto)
        self.v.after(0, poner)
