@echo off
setlocal EnableExtensions EnableDelayedExpansion
title MyPOS Print Server - Instalador

set "INSTALL_DIR=C:\MyPOSPrint"
set "PYTHON_URL=https://www.python.org/ftp/python/3.11.9/python-3.11.9-amd64.exe"
set "VC_URL=https://aka.ms/vs/17/release/vc_redist.x64.exe"
set "SERVER_URL=https://mypos.cl/descargas/mypos_print_server.py"
set "REQ_URL=https://mypos.cl/descargas/requirements.txt"
set "LOG_FILE=%TEMP%\mypos_print_server_install.log"
set "PY_CMD="

> "%LOG_FILE%" echo [%DATE% %TIME%] Inicio instalador MyPOS Print Server

echo.
echo  ============================================================
echo   MyPOS Print Server - Instalador Automatico
echo   Puerto 5555 ^| Python 3.11 ^| ESC/POS ^| PDF SII
echo  ============================================================
echo.
echo   Log: %LOG_FILE%
echo.

REM Verificar permisos de administrador.
net session >nul 2>&1
if %ERRORLEVEL% NEQ 0 (
    call :fail "Requiere permisos de Administrador. Clic derecho y ejecutar como administrador."
)

REM Paso 1: Python.
call :log "[1/6] Verificando Python 3.11..."

py -3.11 --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    set "PY_CMD=py -3.11"
    for /f "tokens=*" %%v in ('py -3.11 --version 2^>^&1') do call :log "      OK: %%v ya instalado."
    goto :vc_check
)

python --version >nul 2>&1
if %ERRORLEVEL% EQU 0 (
    for /f "tokens=*" %%v in ('python --version 2^>^&1') do (
        echo %%v | findstr /C:"3.11" >nul 2>&1
        if !ERRORLEVEL! EQU 0 (
            set "PY_CMD=python"
            call :log "      OK: %%v ya instalado."
            goto :vc_check
        )
    )
)

call :log "[1/6] Instalando Python 3.11.9..."
if exist "%~dp0python-3.11.9-amd64.exe" (
    call :log "      Usando instalador local..."
    start /wait "" "%~dp0python-3.11.9-amd64.exe" /quiet InstallAllUsers=0 PrependPath=1 Include_test=0
) else (
    call :log "      Descargando desde python.org..."
    powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%PYTHON_URL%' -OutFile '%TEMP%\python-3.11.9-amd64.exe' -UseBasicParsing" >> "%LOG_FILE%" 2>&1
    if %ERRORLEVEL% NEQ 0 call :fail "No se pudo descargar Python. Revise la conexion a internet."
    start /wait "" "%TEMP%\python-3.11.9-amd64.exe" /quiet InstallAllUsers=0 PrependPath=1 Include_test=0
    del "%TEMP%\python-3.11.9-amd64.exe" >nul 2>&1
)

set "PY_INSTALL_EXIT=%ERRORLEVEL%"
if not "%PY_INSTALL_EXIT%"=="0" if not "%PY_INSTALL_EXIT%"=="3010" call :fail "El instalador de Python fallo con codigo %PY_INSTALL_EXIT%."

call :refresh_python_path
py -3.11 --version >nul 2>&1
if %ERRORLEVEL% EQU 0 set "PY_CMD=py -3.11"
if not defined PY_CMD (
    python --version >nul 2>&1
    if !ERRORLEVEL! EQU 0 set "PY_CMD=python"
)
if not defined PY_CMD call :fail "Python 3.11 no quedo disponible en PATH despues de instalar."
call :log "      Python listo usando: %PY_CMD%"

:vc_check
REM Paso 2: Visual C++ Redistributable.
echo.
call :log "[2/6] Verificando Visual C++ Redistributable..."
call :vc_runtime_installed
if %ERRORLEVEL% EQU 0 (
    call :log "      VC++ ya instalado."
) else (
    call :log "[2/6] Instalando Visual C++ Redistributable..."
    set "VC_EXIT=0"
    if exist "%~dp0VC_redist.x64.exe" (
        call :log "      Usando instalador local..."
        start /wait "" "%~dp0VC_redist.x64.exe" /quiet /norestart
        set "VC_EXIT=!ERRORLEVEL!"
    ) else (
        call :log "      Descargando desde Microsoft..."
        powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%VC_URL%' -OutFile '%TEMP%\vc_redist.x64.exe' -UseBasicParsing" >> "%LOG_FILE%" 2>&1
        if !ERRORLEVEL! NEQ 0 (
            set "VC_EXIT=DOWNLOAD_FAILED"
        ) else (
            start /wait "" "%TEMP%\vc_redist.x64.exe" /quiet /norestart
            set "VC_EXIT=!ERRORLEVEL!"
            del "%TEMP%\vc_redist.x64.exe" >nul 2>&1
        )
    )
    if "!VC_EXIT!"=="0" (
        call :log "      VC++ instalado."
    ) else if "!VC_EXIT!"=="3010" (
        call :log "      VC++ instalado. Windows puede requerir reinicio."
    ) else if "!VC_EXIT!"=="1638" (
        call :log "      VC++ ya tenia otra version instalada."
    ) else (
        call :vc_runtime_installed
        if !ERRORLEVEL! EQU 0 (
            call :log "      VC++ parece instalado aunque el instalador devolvio codigo !VC_EXIT!."
        ) else (
            call :log "      ADVERTENCIA: Visual C++ Redistributable devolvio codigo !VC_EXIT!. Se continua; si pywin32 falla, instale VC++ manualmente."
        )
    )
)

REM Paso 3: Directorio de instalacion.
echo.
call :log "[3/6] Creando directorio %INSTALL_DIR%..."
if not exist "%INSTALL_DIR%" mkdir "%INSTALL_DIR%" >> "%LOG_FILE%" 2>&1
if not exist "%INSTALL_DIR%" call :fail "No se pudo crear %INSTALL_DIR%."
call :log "      Listo."

REM Paso 4: Archivos del servidor.
echo.
call :log "[4/6] Obteniendo archivos del servidor de impresion..."

if exist "%~dp0mypos_print_server.py" (
    copy /Y "%~dp0mypos_print_server.py" "%INSTALL_DIR%\mypos_print_server.py" >> "%LOG_FILE%" 2>&1
    if %ERRORLEVEL% NEQ 0 call :fail "No se pudo copiar mypos_print_server.py."
    call :log "      mypos_print_server.py: copiado desde instalador local."
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%SERVER_URL%' -OutFile '%INSTALL_DIR%\mypos_print_server.py' -UseBasicParsing" >> "%LOG_FILE%" 2>&1
    if %ERRORLEVEL% NEQ 0 call :fail "No se pudo descargar mypos_print_server.py."
    if not exist "%INSTALL_DIR%\mypos_print_server.py" call :fail "No se pudo obtener mypos_print_server.py."
    call :log "      mypos_print_server.py: descargado desde mypos.cl."
)

if exist "%~dp0requirements.txt" (
    copy /Y "%~dp0requirements.txt" "%INSTALL_DIR%\requirements.txt" >> "%LOG_FILE%" 2>&1
    if %ERRORLEVEL% NEQ 0 call :fail "No se pudo copiar requirements.txt."
) else (
    powershell -NoProfile -ExecutionPolicy Bypass -Command "[Net.ServicePointManager]::SecurityProtocol=[Net.SecurityProtocolType]::Tls12; Invoke-WebRequest -Uri '%REQ_URL%' -OutFile '%INSTALL_DIR%\requirements.txt' -UseBasicParsing" >> "%LOG_FILE%" 2>&1
    if %ERRORLEVEL% NEQ 0 call :fail "No se pudo descargar requirements.txt."
)

if exist "%~dp0python-3.11.9-amd64.exe" copy /Y "%~dp0python-3.11.9-amd64.exe" "%INSTALL_DIR%\python-3.11.9-amd64.exe" >> "%LOG_FILE%" 2>&1
if exist "%~dp0VC_redist.x64.exe" copy /Y "%~dp0VC_redist.x64.exe" "%INSTALL_DIR%\VC_redist.x64.exe" >> "%LOG_FILE%" 2>&1
if exist "%~dp0README.md" copy /Y "%~dp0README.md" "%INSTALL_DIR%\README.md" >> "%LOG_FILE%" 2>&1
if exist "%~dp0SumatraPDF.exe" (
    copy /Y "%~dp0SumatraPDF.exe" "%INSTALL_DIR%\SumatraPDF.exe" >> "%LOG_FILE%" 2>&1
    call :log "      SumatraPDF.exe: copiado desde instalador local."
) else (
    call :log "      ADVERTENCIA: SumatraPDF.exe no viene en el instalador. Los PDF no se imprimiran en silencio."
)
if exist "%~dp0SumatraPDF-3.6.1-64-install.exe" copy /Y "%~dp0SumatraPDF-3.6.1-64-install.exe" "%INSTALL_DIR%\SumatraPDF-3.6.1-64-install.exe" >> "%LOG_FILE%" 2>&1
if exist "%~dp0PdfFilter.dll" copy /Y "%~dp0PdfFilter.dll" "%INSTALL_DIR%\PdfFilter.dll" >> "%LOG_FILE%" 2>&1
if exist "%~dp0PdfPreview.dll" copy /Y "%~dp0PdfPreview.dll" "%INSTALL_DIR%\PdfPreview.dll" >> "%LOG_FILE%" 2>&1

REM Paso 5: Librerias Python.
echo.
call :log "[5/6] Instalando librerias Python (flask, pywin32, Pillow, pdf417...)"
%PY_CMD% -m pip install --upgrade pip --quiet --no-warn-script-location >> "%LOG_FILE%" 2>&1
if %ERRORLEVEL% NEQ 0 call :fail "Fallo la actualizacion de pip. Revise %LOG_FILE%."
%PY_CMD% -m pip install -r "%INSTALL_DIR%\requirements.txt" --quiet --no-warn-script-location >> "%LOG_FILE%" 2>&1
if %ERRORLEVEL% NEQ 0 call :fail "Fallo la instalacion de librerias Python. Revise conexion y log."
call :log "      Librerias instaladas."

REM Paso 6: Acceso directo.
echo.
call :log "[6/6] Creando acceso directo en el Escritorio..."

(
    echo @echo off
    echo setlocal EnableExtensions
    echo title MyPOS Print Server
    echo cd /d "%%~dp0"
    echo set "RUN_LOG=%%TEMP%%\mypos_print_server_run.log"
    echo ^> "%%RUN_LOG%%" echo [%%DATE%% %%TIME%%] Iniciando MyPOS Print Server
    echo echo.
    echo echo  ==========================================
    echo echo    MyPOS Print Server  ^|  Puerto 5555
    echo echo    Deja esta ventana abierta mientras
    echo echo    usas el sistema de impresion.
    echo echo    Cierra con Ctrl+C para detener.
    echo echo  ==========================================
    echo echo.
    echo echo  Carpeta: %%CD%%
    echo echo  Log: %%RUN_LOG%%
    echo echo.
    echo set "PY_SERVER="
    echo py -3.11 --version ^>nul 2^>^&1
    echo if %%ERRORLEVEL%% EQU 0 set "PY_SERVER=py -3.11"
    echo if not defined PY_SERVER ^(
    echo     python --version ^>nul 2^>^&1
    echo     if %%ERRORLEVEL%% EQU 0 set "PY_SERVER=python"
    echo ^)
    echo if not defined PY_SERVER ^(
    echo     echo [ERROR] No se encontro Python 3.11 ni python en PATH.
    echo     echo [%%DATE%% %%TIME%%] ERROR: Python no disponible ^>^> "%%RUN_LOG%%"
    echo     goto :fin
    echo ^)
    echo echo  Usando: %%PY_SERVER%%
    echo echo [%%DATE%% %%TIME%%] Usando %%PY_SERVER%% ^>^> "%%RUN_LOG%%"
    echo echo.
    echo %%PY_SERVER%% mypos_print_server.py
    echo set "SERVER_RC=%%ERRORLEVEL%%"
    echo echo [%%DATE%% %%TIME%%] Proceso finalizo con codigo %%SERVER_RC%% ^>^> "%%RUN_LOG%%"
    echo if not "%%SERVER_RC%%"=="0" ^(
    echo     echo.
    echo     echo  [ERROR] El servidor se cerro con codigo %%SERVER_RC%%.
    echo     echo  Revisa el mensaje anterior y el log:
    echo     echo  %%RUN_LOG%%
    echo ^)
    echo :fin
    echo echo.
    echo echo  Ventana de diagnostico. Presiona una tecla para cerrar.
    echo pause
    echo endlocal
) > "%INSTALL_DIR%\INICIAR_MYPOS_PRINT_SERVER.bat"
if %ERRORLEVEL% NEQ 0 call :fail "No se pudo crear el script de inicio."

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $desktop = [Environment]::GetFolderPath('Desktop'); $s = $ws.CreateShortcut((Join-Path $desktop 'MyPOS Print Server.lnk')); $s.TargetPath = [Environment]::SystemDirectory + '\cmd.exe'; $s.Arguments = '/k call ""%INSTALL_DIR%\INICIAR_MYPOS_PRINT_SERVER.bat""'; $s.WorkingDirectory = '%INSTALL_DIR%'; $s.Description = 'Servidor de impresion MyPOS - Puerto 5555'; $s.Save()" >> "%LOG_FILE%" 2>&1
if %ERRORLEVEL% NEQ 0 call :fail "No se pudo crear el acceso directo."
call :log "      Acceso directo creado en el Escritorio."

echo.
echo  ============================================================
echo   INSTALACION COMPLETADA
echo.
echo   Directorio      : %INSTALL_DIR%
echo   Acceso directo  : "MyPOS Print Server" en el Escritorio
echo   Log             : %LOG_FILE%
echo.
echo   Para iniciar el servidor de impresion:
echo   Doble clic en "MyPOS Print Server" en el Escritorio
echo  ============================================================
echo.
set /p "STARTNOW= Iniciar el servidor ahora mismo? [S/N]: "
if /i "!STARTNOW!"=="S" (
    start "MyPOS Print Server" cmd /k call "%INSTALL_DIR%\INICIAR_MYPOS_PRINT_SERVER.bat"
)

endlocal
exit /b 0

:refresh_python_path
for /f "usebackq tokens=2,*" %%a in (`reg query "HKCU\Environment" /v PATH 2^>nul`) do (
    set "PATH=%%b;%PATH%"
)
set "PATH=%LOCALAPPDATA%\Programs\Python\Python311;%LOCALAPPDATA%\Programs\Python\Python311\Scripts;%PATH%"
exit /b 0

:vc_runtime_installed
reg query "HKLM\SOFTWARE\Microsoft\VisualStudio\14.0\VC\Runtimes\X64" /v Version >nul 2>&1
if %ERRORLEVEL% EQU 0 exit /b 0
reg query "HKLM\SOFTWARE\WOW6432Node\Microsoft\VisualStudio\14.0\VC\Runtimes\X64" /v Version >nul 2>&1
if %ERRORLEVEL% EQU 0 exit /b 0
exit /b 1

:log
set "MSG=%~1"
echo  %MSG%
>> "%LOG_FILE%" echo [%DATE% %TIME%] %MSG%
exit /b 0

:fail
set "ERR=%~1"
echo.
echo  [ERROR] %ERR%
echo  [ERROR] %ERR%>> "%LOG_FILE%"
echo.
echo  Revise el log: %LOG_FILE%
echo.
pause
goto :abort

:abort
endlocal
exit 1
