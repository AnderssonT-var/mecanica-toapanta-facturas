# Portal de licencias · instalación en Hostinger

Destino: `https://mecanicatoapantavelez.com/licencias/`
(Revisa la ortografía exacta de tu dominio. Si es otro, el programa permite cambiar la dirección en la pantalla de activación → *Opciones avanzadas*, o se cambia `LICENCIAS_URL` en `desktop/src/config.js` antes de compilar.)

## Requisitos

- Plan de hosting con PHP **8.1 o superior** (hPanel → Avanzado → Configuración de PHP). Extensiones `pdo_mysql` y `sodium` activas (vienen activas por defecto en Hostinger).
- SSL activo en el dominio (hPanel → Seguridad → SSL). El portal obliga HTTPS.

## Pasos

1. **Crear la base de datos** — hPanel → Bases de datos → Administración. Crea una base (ej. `licencias`) y un usuario con contraseña segura. Anota los tres datos completos: `u123456789_licencias`, `u123456789_admin` y la contraseña.
2. **Subir los archivos** — hPanel → Administrador de archivos → `public_html`. Crea la carpeta `licencias` y sube dentro **todo el contenido** del zip (incluido `.htaccess` y `llave-incluida.php`). Debe quedar `public_html/licencias/api.php`, `public_html/licencias/lib/…`, etc.
3. **Instalar** — abre `https://mecanicatoapantavelez.com/licencias/instalar.php`.
   - Servidor: `localhost` · nombre de la base, usuario y contraseña del paso 1.
   - Crea tu usuario administrador (contraseña de 10 caracteres o más) y tu WhatsApp de soporte.
   - Al terminar, el instalador guarda `config.php` (protegido), **borra `llave-incluida.php`** y muestra la llave pública. Debe coincidir con:
     `MCowBQYDK2VwAyEAmXzpVuke3WMcc1Md5hcvjeYF4z21P+3TZ6HLFerVeEo=`
4. **Borrar `instalar.php`** desde el Administrador de archivos (por seguridad; si alguien lo abre después solo verá "ya instalado", pero mejor quitarlo).
5. **Probar la API** — abre `https://mecanicatoapantavelez.com/licencias/api.php?a=salud`. Debe responder `{"ok":true,...}`.
6. **Crear la primera licencia** — entra a `/licencias/`, *Nueva licencia*: cliente, RUC, plan, vigencia, empresas y equipos. Copia la clave `FX-XXXX-XXXX-XXXX` o envíala por WhatsApp/correo.
7. **Activar en el programa** — el cliente instala `Facturador-Setup-0.4.0.exe`, pega la clave y listo.

## Uso diario

- **Renovar:** en la licencia → *Renovar / registrar pago*. El programa recibe la nueva fecha en la siguiente verificación (máx. 6 h, o al pulsar *Verificar ahora* en Licencia).
- **Falta de pago:** *Suspender* → el programa bloquea la emisión en la siguiente verificación (la consulta de datos sigue disponible).
- **Cambio de computadora:** en *Equipos* pulsa *Liberar* y el cliente activa en la nueva PC.
- **Clave filtrada:** *Generar nueva clave* (libera todos los equipos).
- **Ajustes:** días permitidos sin conexión (15 por defecto), gracia, equipos por defecto, administradores.

## Respaldo y seguridad

- Descarga una copia de `config.php` y guárdala fuera del servidor: contiene la conexión y la **llave privada** con la que se firman las licencias. Si se pierde, tendrías que reinstalar y reactivar todos los clientes con una versión nueva del programa.
- Respalda la base de datos desde hPanel (o activa los respaldos automáticos del plan).
- No compartas `llave-incluida.php` ni `config.php` con nadie.
- Bloqueo automático tras 8 intentos fallidos de inicio de sesión (15 min) y límite de 20 claves erróneas por IP en la API (10 min).
