@echo off
chcp 65001 >nul
cd /d "%~dp0"
echo ==========================================================
echo   Construir "Monitor de Facturas" (.exe)
echo   Esto se hace UNA vez, en cualquier PC con Python.
echo   El resultado se copia a la PC del taller (no necesita Python).
echo ==========================================================
echo.

where python >nul 2>nul
if errorlevel 1 (
  echo No se encontro Python. Instalalo desde https://www.python.org/downloads/
  echo marcando "Add python.exe to PATH" y vuelve a ejecutar este archivo.
  pause & exit /b 1
)

python -m pip install --upgrade pip >nul
python -m pip install -r requirements.txt pyinstaller
if errorlevel 1 ( echo Error instalando dependencias. & pause & exit /b 1 )

python hacer_icono.py

python -m PyInstaller --noconfirm --clean --windowed --onedir ^
  --name "MonitorFacturas" --icon icono.ico ^
  --hidden-import pystray._win32 ^
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
