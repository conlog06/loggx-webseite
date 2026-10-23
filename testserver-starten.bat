@echo off
chcp 65001 >nul
title LoggX Testserver
cd /d "%~dp0"

rem PHP suchen: erst im PATH, dann typische Ordner
set "PHP="
where php >nul 2>nul && set "PHP=php"
if not defined PHP if exist "C:\php\php.exe" set "PHP=C:\php\php.exe"
if not defined PHP if exist "C:\xampp\php\php.exe" set "PHP=C:\xampp\php\php.exe"
if not defined PHP if exist "%LOCALAPPDATA%\Programs\php\php.exe" set "PHP=%LOCALAPPDATA%\Programs\php\php.exe"
if not defined PHP (
  echo PHP wurde nicht gefunden.
  echo Bitte PHP installieren: https://windows.php.net/download  ^(oder XAMPP^)
  echo und z. B. nach C:\php entpacken.
  pause
  exit /b 1
)

set PORT=8000
echo.
echo  LoggX Testserver laeuft:
echo    Webseite:  http://localhost:%PORT%
echo    Admin:     http://localhost:%PORT%/admin/
echo    Finanzen:  http://localhost:%PORT%/admin/finanzen.php
echo.
echo  Zum Beenden dieses Fenster schliessen oder Strg+C druecken.
echo.
start "" "http://localhost:%PORT%/admin/"
"%PHP%" -S localhost:%PORT% _launch/testserver.php
pause
