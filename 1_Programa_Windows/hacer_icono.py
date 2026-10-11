"""Genera icono.ico para el .exe (usa el mismo dibujo que el ícono de la bandeja)."""
from PIL import Image, ImageDraw

def dibujar(color="#1f9d55", t=256):
    k = t / 64
    img = Image.new("RGBA", (t, t), (0, 0, 0, 0))
    d = ImageDraw.Draw(img)
    d.rounded_rectangle((2 * k, 2 * k, 62 * k, 62 * k), radius=14 * k, fill=color)
    d.rectangle((17 * k, 12 * k, 47 * k, 52 * k), fill="white")
    for y in (21, 29, 37):
        d.rectangle((22 * k, y * k, 42 * k, (y + 3) * k), fill=color)
    d.rectangle((22 * k, 45 * k, 34 * k, 47 * k), fill=color)
    return img

dibujar().save("icono.ico", sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (256, 256)])
print("icono.ico listo")
