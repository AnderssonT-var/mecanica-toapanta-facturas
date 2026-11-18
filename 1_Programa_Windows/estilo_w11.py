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
FONDO = "#f3f3f3"           # fondo «Mica» claro
LATERAL = "#eeeeee"
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

_estado = {"sv": False, "fuente": ("Segoe UI", 10), "iconos": None}


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
        # el botón principal es el azul de Windows 11
        st.configure("Grande.TButton", font=(fam, 10, "bold"), padding=(20, 7))
        try:
            st.layout("Grande.TButton", st.layout("Accent.TButton"))
            for opcion in ("foreground", "background"):
                m = st.map("Accent.TButton", opcion)
                if m:
                    st.map("Grande.TButton", **{opcion: m})
            st.configure("Grande.TButton", foreground="#ffffff")
        except tk.TclError:
            pass
    else:
        st.configure("Grande.TButton", font=(fam, 10, "bold"), padding=(18, 8))
        st.configure("Card.TFrame", background=TARJETA, relief="solid", borderwidth=1)
    return base


def fondo(widget=None) -> str:
    return FONDO


def nota(padre, texto: str) -> tk.Label:
    """Texto chico gris sobre el fondo de la ventana (fuera de las tarjetas)."""
    return tk.Label(padre, text=texto, bg=FONDO, fg=SUAVE, font=(_estado["fuente"][0], 9))


def tarjeta(padre, titulo: str | None = None, ayuda: str | None = None, padding=16) -> ttk.Frame:
    """Un recuadro redondeado como las tarjetas de la Configuración de Windows 11."""
    c = ttk.Frame(padre, style="Card.TFrame", padding=padding)
    if titulo:
        ttk.Label(c, text=titulo, style="Seccion.TLabel").pack(anchor="w")
    if ayuda:
        ttk.Label(c, text=ayuda, style="Suave.TLabel", wraplength=620, justify="left").pack(anchor="w", pady=(2, 8))
    return c


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
        logo.create_oval(2, 2, 28, 28, fill=ACENTO, outline="")
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
        item = tk.Frame(self.menu, bg=LATERAL, cursor="hand2", height=40)
        item.pack(fill="x", pady=1)
        item.pack_propagate(False)
        barra = tk.Frame(item, bg=LATERAL, width=3)
        barra.place(x=0, rely=0.5, anchor="w", height=18)
        glifo = ICONOS[icono] if _estado["iconos"] else ICONOS_TEXTO[icono]
        ic = tk.Label(item, text=glifo, bg=LATERAL, fg=TEXTO,
                      font=(_estado["iconos"], 13) if _estado["iconos"] else (fam, 13))
        ic.place(x=16, rely=0.5, anchor="w")
        tx = tk.Label(item, text=text.strip(), bg=LATERAL, fg=TEXTO, font=(fam, 10))
        tx.place(x=48, rely=0.5, anchor="w")
        d = {"pagina": pagina, "texto": text.strip(), "item": item, "barra": barra, "partes": (item, ic, tx)}
        self.paginas.append(d)

        def entrar(_e):
            if self.actual is not d:
                for w in d["partes"]:
                    w.configure(bg="#e6e6e6")

        def salir(_e):
            if self.actual is not d:
                for w in d["partes"]:
                    w.configure(bg=LATERAL)
        for w in d["partes"]:
            w.bind("<Button-1>", lambda e, p=pagina: self.select(p))
            w.bind("<Enter>", entrar)
            w.bind("<Leave>", salir)
        if self.actual is None:
            self.select(pagina)

    def select(self, pagina):
        for d in self.paginas:
            activo = d["pagina"] is pagina
            color = "#e3e3e3" if activo else LATERAL
            for w in d["partes"]:
                w.configure(bg=color)
            d["barra"].configure(bg=ACENTO if activo else color)
            if activo:
                self.actual = d
                self.lbl_pagina.configure(text=d["texto"])
                d["pagina"].pack(fill="both", expand=True)
            else:
                d["pagina"].pack_forget()
        try:
            self.event_generate("<<NotebookTabChanged>>")
        except tk.TclError:
            pass
