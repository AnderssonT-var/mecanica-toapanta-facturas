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

## Facturar desde el celular (FactuSYS)

En el celular: `mecanicatoapantavelez.com/admin` → **Facturar** (marca *Recordar este
dispositivo*). Eliges Nancy o Nelson, escribes la cédula/RUC (si el cliente ya está en
FactuSYS se llena solo; si es nuevo FactuSYS trae nombre y dirección del SRI), pones
celular, correo, valor y si lleva IVA, revisas y *Sí, facturar*.

En la PC el programa hace **los mismos pasos que tú**:

1. Espera a que nadie use la PC y a que FactuSYS esté cerrado; muestra una cuenta
   regresiva (si mueves el mouse, lo deja para después).
2. Abre FactuSYS y hace clic en el perfil. NO lo maximiza (FactuSYS se traba): trabaja con la ventana del tamaño en que se abre; si se abre en otro lugar de la pantalla, los clics la siguen.
3. Factura → Factura comercial → cierra el Listado de clientes (el perfil ya entra en Ventas).
4. Escribe el RUC directo (el cursor ya está ahí) + Enter (si es RUC de 13, FoxPro salta solo), y avanza con TAB: nombre (solo si es nuevo), dirección, un teléfono, correo. Si el cursor no queda en el nombre tras el Enter, ajústalo en la pestaña.
5. Nuevo → 00038 + Enter → Aceptar → precio → Guardar.
6. Cerrar formulario → Grabar Datos → Aceptar. FactuSYS firma y autoriza en el SRI.
7. En la factura en pantalla: Guardar → PDF en el **Escritorio** como
   `0991112223_00002550.pdf` (se copia a la carpeta del monitor y sale por WhatsApp).
8. **Cierra FactuSYS.** La siguiente factura lo vuelve a abrir.

Confirma cada paso importante leyendo las tablas de FactuSYS (solo lectura). Si no
puede confirmar que se grabó, **no la repite**: en el celular sale *Revisar en FactuSYS*.
Si el SRI no responde, la factura queda grabada y se autoriza la próxima vez que se
abre FactuSYS; entonces el PDF se arma desde el XML autorizado.

### Puesta en marcha (una vez)

Pestaña **Facturación móvil**:

1. Carpeta de FactuSYS (`C:\FACTUSYS`) y dónde guardar el PDF (`escritorio`).
2. **Calibración guiada (F8)**: haz una factura como siempre; en cada paso pon el
   mouse encima de lo que dice el aviso verde, presiona **F8** y haz tu clic normal.
   F9 salta un paso (por ejemplo el perfil que no usas, o *Salir sin Grabar*); esos
   se capturan después con su botón *Capturar*.
3. **Probar los pasos**: escribe un cliente y el producto y sale con *Salir sin
   Grabar* (no crea facturas).
4. *Enviar clientes a la página*, marca **Activar** y **Guardar**.

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
| `facturador.py` | Facturación desde el celular (FactuSYS) |
| `pestana_movil.py` | Pestaña de calibración y pruebas |
| `dbf_lector.py` | Lee las tablas de FactuSYS (solo lectura) |
| `ride_pdf.py` | PDF de respaldo desde el XML autorizado (si FactuSYS no lo guardó) |

La configuración, el historial y el registro se guardan en
`%APPDATA%\MonitorFacturasToapanta`, así que reinstalar no los borra.


## Pantalla del cliente

Un teléfono en el mostrador le muestra al cliente, mientras haces la factura en
FactuSYS, su nombre, cédula/RUC, dirección, celular, correo, Subtotal (Base
Imponible), IVA y Total. El programa los lee de la pantalla de FactuSYS con el
reconocimiento de texto de Windows (Windows 10/11 con español; no hay que instalar
nada). La pantalla solo informa: el WhatsApp se envía como siempre.

Sin factura en curso, el teléfono muestra un cartel de neón o LED. Sus mensajes,
estilo, color y tiempos se cambian en el panel de la página →
Pantalla del cliente. Cuando el programa lee los datos de una factura, el cartel
se pausa y se muestran los datos; al enviar el WhatsApp sale «¡Gracias!» y vuelve
el cartel.

Configurar (una vez):
1. En el teléfono abre mecanicatoapantavelez.com/pantalla.php y escribe el código
   en el panel de la página → Pantalla del cliente.
2. En el programa, pestaña «Pantalla del cliente»: abre una factura en FactuSYS con
   un cliente de ejemplo (sin grabar), presiona «Marcar campos» y marca DOS
   recuadros: uno que abarque los datos del cliente (de «RUC» a «EMAIL Notif.»,
   con las etiquetas) y otro que abarque Base Imponible, IVA y Total. Luego
   «Probar lectura».
3. Marca «Activar la pantalla del cliente» y presiona «Guardar».

### Monitor por cable (HDMI) — v3.5

La pantalla también puede ir en un monitor conectado a la PC. Es lo más rápido: el
programa la sirve desde la misma PC (http://127.0.0.1:8790) y la abre a pantalla
completa en ese monitor con Microsoft Edge, sin pasar por internet.

1. Conecta el monitor y en Windows presiona Windows + P → «Extender».
2. Pestaña «Pantalla del cliente» → marca «Mostrar en un monitor conectado a esta
   PC (HDMI)», elige el monitor y presiona «Guardar». «Abrir en el monitor» la abre
   en el momento.
3. Si se cierra sola, el programa la vuelve a abrir en unos segundos. Si cambias
   algo en el panel de la página, el monitor lo toma en menos de 1 minuto.

### Lectura verificada (v3.6)

- La cédula/RUC solo se muestra al instante si cumple el dígito verificador.
- Con esa cédula/RUC se busca al cliente en las tablas de FactuSYS (solo lectura) y se
  muestran sus datos exactos. Si en el formulario se ve algo claramente distinto (se
  cambió a mano), se respeta lo que se ve.
- Cliente nuevo: el nombre se compara con el del SRI y se corrige la ortografía.
- El total pasa al instante solo si el IVA corresponde a una tarifa real del subtotal.
- «Probar lectura» dice qué se verificó.

### Formas de pago

En el programa (pestaña «Pantalla del cliente» → «Editar cartel…») o en el panel de
la página → Pantalla del cliente → «Subir forma de pago» sube una imagen o un PDF (se usa su primera página) y marca 1 o 2 para mostrar. Salen como un
turno más del cartel: la hora arriba y las imágenes abajo, lo más grandes posible.

La facturación desde el celular está en pausa: su pestaña ya no se muestra.
