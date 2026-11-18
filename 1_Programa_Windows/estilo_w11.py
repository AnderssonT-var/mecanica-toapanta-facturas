"""
Apariencia estilo Windows 11 para el programa: tema «Sun Valley» (sv_ttk, botones y campos
redondeados como Windows 11), letra Segoe UI Variable, navegación lateral como la
Configuración de Windows y tarjetas para agrupar.

Si el tema no está instalado, se usa uno parecido con los controles normales de Windows.
"""

from __future__ import annotations

import sys
import tkinter as tk
from tkinter import font as tkfont
from tkinter import ttk

ACENTO = "#005fb8"          # azul de Windows 11
ACENTO_SUAVE = "#e5eef8"
FONDO = "#eef1f5"           # fondo gris azulado claro
LATERAL = "#e6eaf0"
TARJETA = "#fafafa"          # interior de las tarjetas (= fondo del tema)
BORDE = "#e5e5e5"
TEXTO = "#1b1b1b"
SUAVE = "#5f6368"
VERDE = "#0f7b0f"
ROJO = "#c42b1c"
AMBAR = "#9d5d00"

# iconos de Windows (Segoe Fluent Icons en Windows 11, Segoe MDL2 Assets en Windows 10)
ICONOS = {"inicio": "", "config": "", "pantalla": "", "historial": "", "movil": ""}
ICONOS_TEXTO = {"inicio": "⌂", "config": "⚙", "pantalla": "▭", "historial": "↺", "movil": "▯"}

_estado = {"sv": False, "fuente": ("Segoe UI", 10), "iconos": None, "imgs": {}}

RADIO_TARJETA = 16
RADIO_BOTON = 9
MARGEN = 4                  # espacio para la sombra suave de las tarjetas
DENTRO = 7                  # el contenido no tapa las esquinas redondeadas


def _hex(c: str) -> tuple:
    c = c.lstrip("#")
    return tuple(int(c[i:i + 2], 16) for i in (0, 2, 4))


def imagen_redondeada(w: int, h: int, radio: int, relleno: str, borde: str | None = None, fondo: str | None = None,
                      sombra: bool = False, margen: int = 0):
    """Rectángulo con esquinas redondeadas suavizadas (dibujado al triple y reducido).
    Con fondo=None las esquinas quedan transparentes."""
    from PIL import Image, ImageDraw, ImageFilter, ImageTk
    k = 3
    W, H = max(1, w) * k, max(1, h) * k
    base = Image.new("RGBA", (W, H), (*_hex(fondo), 255) if fondo else (0, 0, 0, 0))
    m = margen * k
    if sombra and fondo:
        capa = Image.new("RGBA", (W, H), (0, 0, 0, 0))
        ImageDraw.Draw(capa).rounded_rectangle((m, m + 2 * k, W - m - 1, H - m + k), radio * k, fill=(20, 30, 50, 34))
        capa = capa.filter(ImageFilter.GaussianBlur(3 * k))
        base = Image.alpha_composite(base, capa)
    d = ImageDraw.Draw(base)
    caja = (m, m, W - m - 1, H - m - 1)
    if borde:
        d.rounded_rectangle(caja, radio * k, fill=(*_hex(borde), 255))
        caja = (m + k, m + k, W - m - 1 - k, H - m - 1 - k)
        d.rounded_rectangle(caja, max(1, radio - 1) * k, fill=(*_hex(relleno), 255))
    else:
        d.rounded_rectangle(caja, radio * k, fill=(*_hex(relleno), 255))
    return ImageTk.PhotoImage(base.resize((max(1, w), max(1, h)), Image.LANCZOS))


def _botones_redondos(st: ttk.Style) -> None:
    """Botones con esquinas bien redondeadas (normal blanco, principal azul)."""
    try:
        def img(relleno, borde):
            return imagen_redondeada(24, 24, RADIO_BOTON, relleno, borde)
        n = {"n": img("#ffffff", "#d5dae1"), "h": img("#f6f8fb", "#c9cfd8"), "p": img("#e9edf2", "#c9cfd8"),
             "d": img("#f2f4f7", "#e1e5ea")}
        a = {"n": img("#0067c0", "#005fb8"), "h": img("#1975c5", "#1975c5"), "p": img("#005499", "#005499"),
             "d": img("#9bbbe0", "#9bbbe0")}
        _estado["imgs"]["botones"] = (n, a)
        for nombre, im in (("R.boton", n), ("R.acento", a)):
            st.element_create(nombre, "image", im["n"], ("disabled", im["d"]), ("pressed", im["p"]),
                              ("active", im["h"]), border=RADIO_BOTON + 1, padding=0, sticky="nsew")
        hijos = [("Button.padding", {"sticky": "nsew", "children": [("Button.label", {"sticky": "nsew"})]})]
        st.layout("TButton", [("R.boton", {"sticky": "nsew", "children": hijos})])
        st.layout("Grande.TButton", [("R.acento", {"sticky": "nsew", "children": hijos})])
        # campos de texto redondeados, con borde azul al escribir
        cn, cf = imagen_redondeada(24, 24, 8, "#ffffff", "#d5dae1"), imagen_redondeada(24, 24, 8, "#ffffff", ACENTO)
        cd = imagen_redondeada(24, 24, 8, "#f4f6f8", "#e1e5ea")
        _estado["imgs"]["campos"] = (cn, cf, cd)
        st.element_create("R.campo", "image", cn, ("disabled", cd), ("focus", cf), border=9, padding=0, sticky="nsew")
        st.layout("TEntry", [("R.campo", {"sticky": "nsew", "children": [
            ("Entry.padding", {"sticky": "nsew", "children": [("Entry.textarea", {"sticky": "nsew"})]})]})])
        st.configure("TEntry", padding=(9, 5))
        st.configure("TButton", padding=(14, 5))
        st.configure("Grande.TButton", padding=(22, 7), foreground="#ffffff")
        st.map("Grande.TButton", foreground=[("disabled", "#eef3fa"), ("!disabled", "#ffffff")])
    except Exception:  # noqa: BLE001
        pass


def _familia(preferidas: list[str]) -> str | None:
    try:
        hay = set(tkfont.families())
    except tk.TclError:
        return None
    return next((f for f in preferidas if f in hay), None)


def aplicar(root: tk.Tk) -> tuple[str, int]:
    """Aplica el tema. Devuelve la fuente base (familia, tamaño)."""
    try:
        import sv_ttk
        sv_ttk.set_theme("light", root)
        _estado["sv"] = True
    except Exception:  # noqa: BLE001
        st = ttk.Style(root)
        st.theme_use("vista" if "vista" in st.theme_names() else "clam")
    fam = _familia(["Segoe UI Variable Text", "Segoe UI", "Inter", "DejaVu Sans"]) or "TkDefaultFont"
    titulo = _familia(["Segoe UI Variable Display", "Segoe UI Variable Text", "Segoe UI Semibold", "Segoe UI",
                       "Inter Display", "Inter", "DejaVu Sans"]) or fam
    _estado["iconos"] = _familia(["Segoe Fluent Icons", "Segoe MDL2 Assets"])
    base = (fam, 10)
    _estado["fuente"] = base
    for nombre in ("TkDefaultFont", "TkTextFont", "TkMenuFont", "TkHeadingFont", "TkCaptionFont"):
        try:
            tkfont.nametofont(nombre).configure(family=fam, size=10)
        except tk.TclError:
            pass

    st = ttk.Style(root)
    root.configure(bg=FONDO)
    st.configure(".", font=base)
    # el fondo de la ventana es gris claro y las tarjetas un poco más claras, como Windows 11
    st.configure("Fondo.TFrame", background=FONDO)
    st.configure("Fondo.TLabel", background=FONDO)
    st.configure("Titulo.TLabel", font=(titulo, 20, "bold"), foreground=TEXTO)
    st.configure("Pagina.TLabel", font=(titulo, 22, "bold"), foreground=TEXTO, background=FONDO)
    st.configure("Seccion.TLabel", font=(titulo, 13, "bold"), foreground=TEXTO)
    st.configure("Estado.TLabel", font=(titulo, 15, "bold"))
    st.configure("Numero.TLabel", font=(titulo, 26, "bold"), foreground=TEXTO)
    st.configure("Suave.TLabel", foreground=SUAVE)
    st.configure("Chico.TLabel", foreground=SUAVE, font=(fam, 9), background=FONDO)
    st.configure("Treeview", rowheight=30)
    if _estado["sv"]:
        st.configure("Grande.TButton", font=(fam, 10, "bold"))
    else:
        st.configure("Grande.TButton", font=(fam, 10, "bold"), padding=(18, 8))
    _botones_redondos(st)
    return base


def fondo(widget=None) -> str:
    return FONDO


def nota(padre, texto: str) -> tk.Label:
    """Texto chico gris sobre el fondo de la ventana (fuera de las tarjetas)."""
    return tk.Label(padre, text=texto, bg=FONDO, fg=SUAVE, font=(_estado["fuente"][0], 9))


class _Tarjeta(ttk.Frame):
    """Tarjeta con esquinas redondeadas y sombra suave. Se usa como un Frame: sus .pack()/.grid()
    ubican la tarjeta entera y los controles se ponen adentro."""

    def __init__(self, padre, padding=16):
        self._lienzo = tk.Canvas(padre, bg=FONDO, highlightthickness=0, bd=0)
        super().__init__(self._lienzo, padding=padding)
        self._img = None
        self._tam = None
        self._fondo_id = self._lienzo.create_image(0, 0, anchor="nw")
        self._win = self._lienzo.create_window(MARGEN + DENTRO, MARGEN + DENTRO, window=self, anchor="nw")
        self.bind("<Configure>", self._interior, add="+")
        self._lienzo.bind("<Configure>", self._exterior, add="+")

    def _interior(self, _e=None):
        rw, rh = self.winfo_reqwidth(), self.winfo_reqheight()
        e = 2 * (MARGEN + DENTRO)
        self._lienzo.configure(width=rw + e, height=rh + e)

    def _exterior(self, e):
        cw, ch = e.width, e.height
        rw, rh = self.winfo_reqwidth(), self.winfo_reqheight()
        e = 2 * (MARGEN + DENTRO)
        self._lienzo.itemconfigure(self._win, width=max(1, cw - e),
                                   height=ch - e if ch - e > rh + 1 else 0)
        if (cw, ch) != self._tam and cw > 2 * MARGEN and ch > 2 * MARGEN:
            self._tam = (cw, ch)
            self._img = imagen_redondeada(cw, ch, RADIO_TARJETA, TARJETA, BORDE, FONDO, sombra=True, margen=MARGEN)
            self._lienzo.itemconfigure(self._fondo_id, image=self._img)

    # la tarjeta (el lienzo) es la que se ubica
    def pack(self, *a, **k): return self._lienzo.pack(*a, **k)
    def pack_configure(self, *a, **k): return self._lienzo.pack(*a, **k)
    def pack_forget(self): return self._lienzo.pack_forget()
    def grid(self, *a, **k): return self._lienzo.grid(*a, **k)
    def grid_configure(self, *a, **k): return self._lienzo.grid(*a, **k)
    def grid_forget(self): return self._lienzo.grid_forget()
    def grid_remove(self): return self._lienzo.grid_remove()
    def place(self, *a, **k): return self._lienzo.place(*a, **k)


def tarjeta(padre, titulo: str | None = None, ayuda: str | None = None, padding=16) -> ttk.Frame:
    """Un recuadro con esquinas bien redondeadas y sombra suave."""
    try:
        c = _Tarjeta(padre, padding=padding)
    except Exception:  # noqa: BLE001
        c = ttk.Frame(padre, style="Card.TFrame", padding=padding)
    if titulo:
        ttk.Label(c, text=titulo, style="Seccion.TLabel").pack(anchor="w")
    if ayuda:
        ttk.Label(c, text=ayuda, style="Suave.TLabel", wraplength=620, justify="left").pack(anchor="w", pady=(2, 8))
    return c


def redondear(img, radio: int = 14, fondo: str = TARJETA):
    """Esquinas redondeadas para una imagen de PIL (la vista en vivo del monitor)."""
    from PIL import Image, ImageDraw
    k = 3
    mascara = Image.new("L", (img.width * k, img.height * k), 0)
    ImageDraw.Draw(mascara).rounded_rectangle((0, 0, img.width * k - 1, img.height * k - 1), radio * k, fill=255)
    mascara = mascara.resize(img.size, Image.LANCZOS)
    salida = Image.new("RGB", img.size, _hex(fondo))
    salida.paste(img.convert("RGB"), (0, 0), mascara)
    return salida


class Navegacion(ttk.Frame):
    """Menú lateral con iconos (como la Configuración de Windows 11) y el área de la página.
    Tiene .add(pagina, text=…, icono=…) y .select(pagina) como un Notebook."""

    def __init__(self, root, titulo_app: str, subtitulo: str = ""):
        super().__init__(root, style="Fondo.TFrame")
        self.root = root
        self.paginas: list[dict] = []
        self.actual = None
        fam = _estado["fuente"][0]
        self.lateral = tk.Frame(self, bg=LATERAL, width=250)
        self.lateral.pack(side="left", fill="y")
        self.lateral.pack_propagate(False)
        cab = tk.Frame(self.lateral, bg=LATERAL)
        cab.pack(fill="x", padx=16, pady=(18, 14))
        logo = tk.Canvas(cab, width=30, height=30, bg=LATERAL, highlightthickness=0)
        logo.pack(side="left")
        self._logo = imagen_redondeada(30, 30, 9, ACENTO, None, LATERAL)
        logo.create_image(0, 0, anchor="nw", image=self._logo)
        logo.create_text(15, 15, text="F", fill="white", font=(fam, 12, "bold"))
        textos = tk.Frame(cab, bg=LATERAL)
        textos.pack(side="left", padx=10)
        tk.Label(textos, text=titulo_app, bg=LATERAL, fg=TEXTO, font=(fam, 11, "bold"), anchor="w").pack(anchor="w")
        if subtitulo:
            tk.Label(textos, text=subtitulo, bg=LATERAL, fg=SUAVE, font=(fam, 9), anchor="w").pack(anchor="w")
        self.menu = tk.Frame(self.lateral, bg=LATERAL)
        self.menu.pack(fill="x", padx=8)
        self.pie_lateral = tk.Frame(self.lateral, bg=LATERAL)
        self.pie_lateral.pack(side="bottom", fill="x", padx=16, pady=12)

        derecha = ttk.Frame(self, style="Fondo.TFrame")
        derecha.pack(side="left", fill="both", expand=True)
        self.lbl_pagina = tk.Label(derecha, text="", bg=FONDO, fg=TEXTO, font=ttk.Style().lookup("Pagina.TLabel", "font"))
        self.lbl_pagina.pack(anchor="w", padx=28, pady=(18, 6))
        self.contenido = ttk.Frame(derecha, style="Fondo.TFrame")
        self.contenido.pack(fill="both", expand=True, padx=(20, 12), pady=(0, 12))

    def add(self, pagina, text: str = "", icono: str = "inicio"):
        fam = _estado["fuente"][0]
        c = tk.Canvas(self.menu, bg=LATERAL, height=42, highlightthickness=0, bd=0, cursor="hand2")
        c.pack(fill="x", pady=2)
        glifo = ICONOS[icono] if _estado["iconos"] else ICONOS_TEXTO[icono]
        d = {"pagina": pagina, "texto": text.strip(), "lienzo": c, "estado": "", "img": None}
        d["fondo"] = c.create_image(0, 0, anchor="nw")
        d["barra"] = c.create_image(4, 21, anchor="w")
        c.create_text(22, 21, text=glifo, anchor="w", fill=TEXTO,
                      font=(_estado["iconos"], 13) if _estado["iconos"] else (fam, 13))
        d["txt"] = c.create_text(54, 21, text=d["texto"], anchor="w", fill=TEXTO, font=(fam, 10))
        self.paginas.append(d)
        c.bind("<Configure>", lambda e, d=d: self._pintar(d, forzar=True))
        c.bind("<Button-1>", lambda e, p=pagina: self.select(p))
        c.bind("<Enter>", lambda e, d=d: self._pintar(d, encima=True))
        c.bind("<Leave>", lambda e, d=d: self._pintar(d, encima=False))
        if self.actual is None:
            self.select(pagina)

    def _pintar(self, d, encima=None, forzar=False):
        """Fondo en forma de píldora: blanco la página abierta, gris al pasar el mouse."""
        if encima is not None:
            d["encima"] = encima
        activo = self.actual is d
        estado = "activo" if activo else ("encima" if d.get("encima") else "")
        c = d["lienzo"]
        w, h = c.winfo_width(), c.winfo_height()
        if w < 10:
            return
        if estado == d["estado"] and not forzar:
            return
        d["estado"] = estado
        if estado:
            d["img"] = imagen_redondeada(w - 4, h - 2, 10, "#ffffff" if activo else "#dde2ea",
                                         "#dfe3e9" if activo else None, LATERAL)
            c.itemconfigure(d["fondo"], image=d["img"])
            c.coords(d["fondo"], 2, 1)
        else:
            c.itemconfigure(d["fondo"], image="")
        if activo:
            d["img_barra"] = imagen_redondeada(4, 18, 2, ACENTO, None, "#ffffff")
            c.itemconfigure(d["barra"], image=d["img_barra"])
        else:
            c.itemconfigure(d["barra"], image="")
        c.itemconfigure(d["txt"], font=(_estado["fuente"][0], 10, "bold" if activo else "normal"))

    def select(self, pagina):
        for d in self.paginas:
            if d["pagina"] is pagina:
                self.actual = d
                self.lbl_pagina.configure(text=d["texto"])
                d["pagina"].pack(fill="both", expand=True)
            else:
                d["pagina"].pack_forget()
        for d in self.paginas:
            self._pintar(d, forzar=True)
        try:
            self.event_generate("<<NotebookTabChanged>>")
        except tk.TclError:
            pass
