@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo ==========================================================
echo   Construir "Monitor de Facturas" (.exe)
echo   Esto se hace UNA vez, en cualquier PC con Python.
echo   El resultado se copia a la PC del taller (no necesita Python).
echo ==========================================================
echo.

rem Python: primero "py" (instalador de python.org), luego "python"
set "PY="
py -3 --version >nul 2>nul && set "PY=py -3"
if not defined PY python --version >nul 2>nul && set "PY=python"
if not defined PY (
  echo No se encontro Python. Instalalo desde https://www.python.org/downloads/
  echo marcando "Add python.exe to PATH" y vuelve a ejecutar este archivo.
  pause & exit /b 1
)
echo Usando: %PY%

%PY% -m pip install --upgrade pip >nul
%PY% -m pip install -r requirements.txt pyinstaller
if errorlevel 1 ( echo Error instalando dependencias. & pause & exit /b 1 )
rem para convertir PDF de formas de pago en el programa (si no se instala, se usa el panel de la pagina)
%PY% -m pip install pypdfium2

%PY% hacer_icono.py

%PY% -m PyInstaller --noconfirm --clean --windowed --onedir ^
  --name "MonitorFacturas" --icon icono.ico ^
  --hidden-import pystray._win32 ^
  --hidden-import ride_pdf --hidden-import reportlab.graphics.barcode.code128 ^
  --hidden-import pantalla_cliente --hidden-import pestana_pantalla --hidden-import monitor_hdmi --hidden-import editor_cartel --collect-all pypdfium2 --collect-all pypdfium2_raw ^
  --hidden-import PIL.ImageGrab --hidden-import PIL.ImageTk ^
  --collect-submodules reportlab.graphics.barcode ^
  app_facturas.pyw
if errorlevel 1 ( echo Error al construir. & pause & exit /b 1 )

copy /y "Instalar en esta PC.bat" "dist\MonitorFacturas\" >nul

powershell -NoProfile -Command "Compress-Archive -Force -Path 'dist\MonitorFacturas' -DestinationPath 'MonitorFacturas_para_el_taller.zip'"

echo.
echo ==========================================================
echo   LISTO:  MonitorFacturas_para_el_taller.zip
echo   Copialo a la PC del taller, descomprimelo y haz doble clic
echo   en "Instalar en esta PC.bat".
echo ==========================================================
pause
