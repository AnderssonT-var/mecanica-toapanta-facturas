# Monitor de Facturas — Mecánica Toapanta

Programa con ventana que vigila la carpeta de facturas. Cuando aparece un PDF con
el celular del cliente como nombre (`0959133066.pdf`, `593 0959133066.pdf`…):

1. Lo sube a la página `mecanicatoapantavelez.com` y obtiene el link del cliente.
2. Abre WhatsApp de escritorio en el chat del cliente, adjunta el PDF y envía:
   *"Buenos días/tardes/noches, de parte de Mecánica Toapanta puede visualizar
   su factura en el siguiente link: …"*
3. Mueve el PDF a "Facturas enviadas".

**Al cerrar la ventana sigue trabajando**: queda un ícono junto al reloj de
Windows. Clic en el ícono para abrirlo; clic derecho → *Salir* para cerrarlo del todo.

---

## Paso A — Construir el programa (una sola vez, lo hace el técnico)

En cualquier PC con Windows y Python 3.10+ (marcar *"Add python.exe to PATH"*):

1. Doble clic en **`construir_exe.bat`** y esperar (unos minutos).
2. Sale el archivo **`MonitorFacturas_para_el_taller.zip`**.

La PC del taller NO necesita Python.

> Si la PC del taller tiene **Windows 7 u 8**, construye con **Python 3.8**
> (las versiones nuevas ya no funcionan en Windows 7).

## Paso B — Instalar en la PC del taller

1. Copiar `MonitorFacturas_para_el_taller.zip`, clic derecho → *Extraer todo*.
2. Doble clic en **`Instalar en esta PC.bat`**.
   Queda un acceso directo **"Monitor de Facturas"** en el escritorio.

## Paso C — Configurar (desde la ventana)

Pestaña **Configuración**:

- **Carpetas**: por defecto `C:\Facturas`. Se pueden cambiar con *Buscar…*.
- **Clave de subida**: pega la clave. Si no la tienes, *Generar clave nueva…*
  te da un código para pegar en Hostinger → `config.php` → `upload_key_hash`.
- **Mensaje**: se ve una vista previa de cómo le llegará al cliente.
- **Velocidad de la PC**: si los mensajes salen incompletos, elige *Lenta* o
  *Muy lenta*.
- Marca **"Abrir este programa al encender la computadora"**.
- Presiona **Guardar**.

Luego en **Inicio** → *Probar el envío* con tu propio número.

## Uso diario

- No hay que hacer nada: guardar el PDF en la carpeta y listo.
- Mientras envía (30–40 s por factura) el programa usa el teclado: no toques
  la PC en ese momento. Si alguien la está usando, el programa espera.
- **Historial**: todas las facturas enviadas y con error (se puede abrir en Excel).
- Las que fallan 5 veces van a "Facturas con error". Para reintentar, devuélvelas
  a la carpeta de facturas.

## Requisitos

- **WhatsApp de escritorio** (Microsoft Store) con la sesión del taller iniciada.
  Necesita Windows 10 u 11.
- Internet.

## Archivos (para el técnico)

| Archivo | Para qué |
|---|---|
| `app_facturas.pyw` | El programa con ventana |
| `monitor_facturas.py` | El motor (también funciona por consola) |
| `construir_exe.bat` | Crea el .exe y el zip para el taller |
| `Instalar en esta PC.bat` | Instalador que va dentro del zip |
| `hacer_icono.py` | Crea el ícono del programa |

La configuración, el historial y el registro se guardan en
`%APPDATA%\MonitorFacturasToapanta`, así que reinstalar no los borra.
