@echo off
setlocal EnableDelayedExpansion
title MyPOS Print Server - Instalador

echo.
echo  ============================================================
echo   MyPOS Print Server - Instalador Automatico
echo   Puerto 5555 ^| Python 3.11 ^| ESC/POS ^| PDF SII
echo  ============================================================
echo.

:: ── Verificar administrador ──────────────────────────────────
net session >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    echo  [!] Requiere permisos de Administrador.
    echo      Clic derecho en este archivo y "Ejecutar como administrador".
    echo.
    pause
    exit /b 1
)

set "INSTALL_DIR=C:\MyPOSPrint"
set "PYTHON_URL=https://www.python.org/ftp/python/3.11.9/python-3.11.9-amd64.exe"
set "VC_URL=https://aka.ms/vs/17/release/vc_redist.x64.exe"
set "SERVER_URL=https://mypos.cl/descargas/mypos_print_server.py"
set "REQ_URL=https://mypos.cl/descargas/requirements.txt"
set "PY_CMD="

:: ── Paso 1: Python ───────────────────────────────────────────
echo  [1/6] Verificando Python 3.11...

py -3.11 --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set "PY_CMD=py -3.11"
    for /f "tokens=*" %%v in ('py -3.11 --version 2^>^&1') do echo       OK: %%v ya instalado.
    goto :vc_check
)

python --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    for /f "tokens=*" %%v in ('python --version 2^>^&1') do (
        echo %%v | findstr "3.11" >nul 2>&1
        if !ERRORLEVEL! EQU 0 (
            set "PY_CMD=python"
            echo       OK: %%v ya instalado.
            goto :vc_check
        )
    )
)

:: Buscar instalador local primero (mismo directorio que este bat)
echo  [1/6] Instalando Python 3.11.9...
if exist "%~dp0python-3.11.9-amd64.exe" (
    echo       Usando instalador local...
    "%~dp0python-3.11.9-amd64.exe" /quiet InstallAllUsers=0 PrependPath=1 Include_test=0
) else (
    echo       Descargando desde python.org (puede tardar unos minutos)...
    powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol='Tls12,Tls13'; Invoke-WebRequest '%PYTHON_URL%' -OutFile '%TEMP%\python-3.11.9-amd64.exe' -UseBasicParsing"
    if not exist "%TEMP%\python-3.11.9-amd64.exe" (
        echo  [ERROR] No se pudo descargar Python. Revise la conexion a internet.
        pause & exit /b 1
    )
    "%TEMP%\python-3.11.9-amd64.exe" /quiet InstallAllUsers=0 PrependPath=1 Include_test=0
    del "%TEMP%\python-3.11.9-amd64.exe" >nul 2>&1
)

:: Actualizar PATH de la sesion actual
for /f "usebackq tokens=2,*" %%a in (`reg query "HKCU\Environment" /v PATH 2^>nul`) do (
    set "PATH=%%b;%PATH%"
)
set "PATH=%LOCALAPPDATA%\Programs\Python\Python311;%LOCALAPPDATA%\Programs\Python\Python311\Scripts;%PATH%"
set "PY_CMD=python"
echo       Python 3.11.9 instalado.

:vc_check
:: ── Paso 2: Visual C++ Redistributable ───────────────────────
echo.
echo  [2/6] Verificando Visual C++ Redistributable...
reg query "HKLM\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64" /v Version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    echo       VC++ ya instalado.
) else (
    echo  [2/6] Instalando Visual C++ Redistributable...
    if exist "%~dp0VC_redist.x64.exe" (
        echo       Usando instalador local...
        "%~dp0VC_redist.x64.exe" /quiet /norestart
    ) else (
        echo       Descargando desde Microsoft...
        powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol='Tls12,Tls13'; Invoke-WebRequest '%VC_URL%' -OutFile '%TEMP%\vc_redist.x64.exe' -UseBasicParsing"
        "%TEMP%\vc_redist.x64.exe" /quiet /norestart
        del "%TEMP%\vc_redist.x64.exe" >nul 2>&1
    )
    echo       VC++ instalado.
)

:: ── Paso 3: Directorio instalacion ───────────────────────────
echo.
echo  [3/6] Creando directorio %INSTALL_DIR%...
if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%"
echo       Listo.

:: ── Paso 4: Archivos del servidor ────────────────────────────
echo.
echo  [4/6] Obteniendo archivos del servidor de impresion...

if exist "%~dp0mypos_print_server.py" (
    copy /Y "%~dp0mypos_print_server.py" "%INSTALL_DIR%\mypos_print_server.py" >nul
    echo       mypos_print_server.py: copiado desde instalador local.
) else (
    powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol='Tls12,Tls13'; Invoke-WebRequest '%SERVER_URL%' -OutFile '%INSTALL_DIR%\mypos_print_server.py' -UseBasicParsing"
    if not exist "%INSTALL_DIR%\mypos_print_server.py" (
        echo  [ERROR] No se pudo obtener mypos_print_server.py
        pause & exit /b 1
    )
    echo       mypos_print_server.py: descargado desde mypos.cl
)

if exist "%~dp0requirements.txt" (
    copy /Y "%~dp0requirements.txt" "%INSTALL_DIR%\requirements.txt" >nul
) else (
    powershell -NoProfile -Command "[Net.ServicePointManager]::SecurityProtocol='Tls12,Tls13'; Invoke-WebRequest '%REQ_URL%' -OutFile '%INSTALL_DIR%\requirements.txt' -UseBasicParsing"
)

if exist "%~dp0python-3.11.9-amd64.exe" copy /Y "%~dp0python-3.11.9-amd64.exe" "%INSTALL_DIR%\python-3.11.9-amd64.exe" >nul
if exist "%~dp0VC_redist.x64.exe" copy /Y "%~dp0VC_redist.x64.exe" "%INSTALL_DIR%\VC_redist.x64.exe" >nul
if exist "%~dp0README.md" copy /Y "%~dp0README.md" "%INSTALL_DIR%\README.md" >nul
if exist "%~dp0SumatraPDF.exe" (
    copy /Y "%~dp0SumatraPDF.exe" "%INSTALL_DIR%\SumatraPDF.exe" >nul
    echo       SumatraPDF.exe: copiado desde instalador local.
) else (
    echo  [ADVERTENCIA] SumatraPDF.exe no viene en el instalador. Las boletas PDF no se imprimiran en silencio.
)

if exist "%~dp0SumatraPDF-3.6.1-64-install.exe" copy /Y "%~dp0SumatraPDF-3.6.1-64-install.exe" "%INSTALL_DIR%\SumatraPDF-3.6.1-64-install.exe" >nul
if exist "%~dp0PdfFilter.dll" copy /Y "%~dp0PdfFilter.dll" "%INSTALL_DIR%\PdfFilter.dll" >nul
if exist "%~dp0PdfPreview.dll" copy /Y "%~dp0PdfPreview.dll" "%INSTALL_DIR%\PdfPreview.dll" >nul

:: ── Paso 5: Librerias Python ─────────────────────────────────
echo.
echo  [5/6] Instalando librerias Python (flask, pywin32, Pillow...)
%PY_CMD% -m pip install --upgrade pip --quiet --no-warn-script-location
%PY_CMD% -m pip install -r "%INSTALL_DIR%\requirements.txt" --quiet --no-warn-script-location
if %ERRORLEVEL% NEQ 0 (
    echo  [ERROR] Fallo la instalacion de librerias. Revise la conexion.
    pause & exit /b 1
)
echo       Librerias instaladas.

:: ── Paso 6: Acceso directo en Escritorio ─────────────────────
echo.
echo  [6/6] Creando acceso directo en el Escritorio...

:: Script de inicio en el directorio de instalacion
(
    echo @echo off
    echo title MyPOS Print Server
    echo cd /d "%INSTALL_DIR%"
    echo echo.
    echo echo  ==========================================
    echo echo    MyPOS Print Server  ^|  Puerto 5555
    echo echo    Deja esta ventana abierta mientras
    echo echo    usas el sistema de impresion.
    echo echo    Cierra con Ctrl+C para detener.
    echo echo  ==========================================
    echo echo.
    echo py -3.11 mypos_print_server.py ^|^| python mypos_print_server.py
    echo if %%ERRORLEVEL%% NEQ 0 ^(
    echo     echo.
    echo     echo  [ERROR] El servidor no pudo iniciarse. Revisa el mensaje de arriba.
    echo ^)
    echo pause
) > "%INSTALL_DIR%\INICIAR_MYPOS_PRINT_SERVER.bat"

:: Acceso directo con cmd.exe explicito para que la ventana siempre sea visible
powershell -NoProfile -Command ^
    "$ws = New-Object -ComObject WScript.Shell; " ^
    "$s = $ws.CreateShortcut([Environment]::GetFolderPath('Desktop') + '\MyPOS Print Server.lnk'); " ^
    "$s.TargetPath = [Environment]::SystemDirectory + '\cmd.exe'; " ^
    "$s.Arguments = '/k \"%INSTALL_DIR%\INICIAR_MYPOS_PRINT_SERVER.bat\"'; " ^
    "$s.WorkingDirectory = '%INSTALL_DIR%'; " ^
    "$s.Description = 'Servidor de impresion MyPOS - Puerto 5555'; " ^
    "$s.Save()"

echo       Acceso directo creado en el Escritorio.

:: ── Resultado ─────────────────────────────────────────────────
echo.
echo  ============================================================
echo   INSTALACION COMPLETADA
echo.
echo   Directorio : %INSTALL_DIR%
echo   Acceso directo: "MyPOS Print Server" en el Escritorio
echo.
echo   Para iniciar el servidor de impresion:
echo   Doble clic en "MyPOS Print Server" en el Escritorio
echo  ============================================================
echo.
set /p "STARTNOW= Iniciar el servidor ahora mismo? [S/N]: "
if /i "!STARTNOW!"=="S" (
    start cmd /k "%INSTALL_DIR%\INICIAR_MYPOS_PRINT_SERVER.bat"
)

endlocal
