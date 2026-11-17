"""
Lector mínimo de tablas DBF de Visual FoxPro / dBase (solo lectura).

FactuSYS guarda sus datos en tablas libres .DBF. El programa solo las LEE
(nunca escribe en ellas), abriéndolas en modo compartido para no molestar a
FactuSYS mientras está abierto.
"""

from __future__ import annotations

import datetime as dt
import struct
from pathlib import Path


class CampoDBF:
    __slots__ = ("nombre", "tipo", "desplazamiento", "largo", "decimales")

    def __init__(self, nombre, tipo, desplazamiento, largo, decimales):
        self.nombre, self.tipo, self.desplazamiento, self.largo, self.decimales = nombre, tipo, desplazamiento, largo, decimales


def _fecha_juliana(n: int):
    if n <= 0:
        return None
    try:
        return dt.date.fromordinal(n - 1721425)
    except ValueError:
        return None


def _valor(campo: CampoDBF, crudo: bytes, codificacion: str):
    t = campo.tipo
    if t in ("C", "V"):
        return crudo.decode(codificacion, errors="replace").rstrip(" \x00")
    if t in ("N", "F"):
        s = crudo.decode("ascii", errors="ignore").strip().replace(",", ".")
        if not s or s.strip("*") == "":
            return None
        try:
            return float(s) if (campo.decimales or "." in s) else int(s)
        except ValueError:
            return None
    if t == "D":
        s = crudo.decode("ascii", errors="ignore").strip()
        try:
            return dt.date(int(s[:4]), int(s[4:6]), int(s[6:8])) if len(s) == 8 else None
        except ValueError:
            return None
    if t == "T":  # fecha y hora de VFP: día juliano + milisegundos
        dia, ms = struct.unpack("<ii", crudo[:8])
        f = _fecha_juliana(dia)
        return dt.datetime.combine(f, dt.time()) + dt.timedelta(milliseconds=ms) if f else None
    if t == "L":
        return crudo[:1] in (b"T", b"t", b"Y", b"y")
    if t == "I":
        return struct.unpack("<i", crudo[:4])[0]
    if t == "Y":  # moneda: entero de 64 bits / 10000
        return struct.unpack("<q", crudo[:8])[0] / 10000
    if t == "B":
        return struct.unpack("<d", crudo[:8])[0]
    return None  # memos (M, G, W) y otros: no se necesitan


def leer_dbf(ruta, campos: list[str] | None = None, codificacion: str = "cp1252"):
    """Devuelve (lista de diccionarios) con los registros no borrados.

    `campos`: nombres a devolver (en mayúsculas); None = todos.
    """
    datos = Path(ruta).read_bytes()  # lectura de una vez: FactuSYS no queda bloqueado
    if len(datos) < 32:
        return []
    n_reg, largo_cab, largo_reg = struct.unpack("<IHH", datos[4:12])
    lista, pos, desplaz = [], 32, 1
    while pos + 32 <= largo_cab and datos[pos] != 0x0D:
        d = datos[pos:pos + 32]
        nombre = d[:11].split(b"\x00")[0].decode("ascii", errors="ignore").upper()
        tipo = chr(d[11])
        largo, dec = d[16], d[17]
        lista.append(CampoDBF(nombre, tipo, desplaz, largo, dec))
        desplaz += largo
        pos += 32
    if campos:
        pedidos = {c.upper() for c in campos}
        lista = [c for c in lista if c.nombre in pedidos]
    # VFP a veces aún no actualiza el conteo de la cabecera tras agregar un
    # registro: se leen también los registros completos que haya después.
    if largo_reg:
        en_archivo = (len(datos) - largo_cab) // largo_reg
        while en_archivo > n_reg and datos[largo_cab + (en_archivo - 1) * largo_reg] not in (0x20, 0x2A):
            en_archivo -= 1
        n_reg = max(n_reg, en_archivo)
    filas = []
    for i in range(n_reg):
        ini = largo_cab + i * largo_reg
        reg = datos[ini:ini + largo_reg]
        if len(reg) < largo_reg:
            break
        if reg[:1] == b"*":  # registro borrado
            continue
        if reg[:1] != b" ":  # basura o fin de archivo
            continue
        filas.append({c.nombre: _valor(c, reg[c.desplazamiento:c.desplazamiento + c.largo], codificacion) for c in lista})
    return filas


def nombres_campos(ruta) -> list[tuple[str, str, int]]:
    datos = Path(ruta).read_bytes()[:8192]
    largo_cab = struct.unpack("<H", datos[8:10])[0]
    res, pos = [], 32
    while pos + 32 <= largo_cab and datos[pos] != 0x0D:
        d = datos[pos:pos + 32]
        res.append((d[:11].split(b"\x00")[0].decode("ascii", "ignore"), chr(d[11]), d[16]))
        pos += 32
    return res
