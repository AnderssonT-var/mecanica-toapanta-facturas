@echo off
chcp 65001 >nul
title Instalando Monitor de Facturas
set "ORIGEN=%~dp0"
set "DESTINO=%LOCALAPPDATA%\Programs\MonitorFacturas"

echo Instalando Monitor de Facturas, espera un momento...

taskkill /im MonitorFacturas.exe /f >nul 2>nul
if not exist "%DESTINO%" mkdir "%DESTINO%"
xcopy "%ORIGEN%*" "%DESTINO%\" /e /i /y /q >nul

powershell -NoProfile -Command ^
  "$w = New-Object -ComObject WScript.Shell;" ^
  "foreach ($d in @([Environment]::GetFolderPath('Desktop'), [Environment]::GetFolderPath('Programs'))) {" ^
  "  $s = $w.CreateShortcut($d + '\Monitor de Facturas.lnk');" ^
  "  $s.TargetPath = '%DESTINO%\MonitorFacturas.exe';" ^
  "  $s.WorkingDirectory = '%DESTINO%';" ^
  "  $s.IconLocation = '%DESTINO%\MonitorFacturas.exe,0';" ^
  "  $s.Save() }"

start "" "%DESTINO%\MonitorFacturas.exe"
echo.
echo Listo. El programa se abrio y tienes un acceso directo en el escritorio.
timeout /t 5 >nul
