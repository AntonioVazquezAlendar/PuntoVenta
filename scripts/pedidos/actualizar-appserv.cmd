@echo off
setlocal
set "PEDIDOS_PHP="
for /f "delims=" %%P in ('where php 2^>nul') do if not defined PEDIDOS_PHP set "PEDIDOS_PHP=%%P"
if not defined PEDIDOS_PHP if exist "C:\AppServ\php7\php.exe" set "PEDIDOS_PHP=C:\AppServ\php7\php.exe"
if not defined PEDIDOS_PHP (
    echo No se encontro PHP. Agrega PHP al PATH o usa la terminal de AppServ.
    pause
    exit /b 1
)
for %%P in ("%PEDIDOS_PHP%") do set "PEDIDOS_PHP_DIR=%%~dpP"
cd /d "%~dp0..\.."
if exist "%PEDIDOS_PHP_DIR%ext\php_pdo_mysql.dll" (
    if exist "%PEDIDOS_PHP_DIR%ext\php_pdo_sqlite.dll" (
        "%PEDIDOS_PHP%" -d "extension_dir=%PEDIDOS_PHP_DIR%ext" -d extension=php_pdo_mysql.dll -d extension=php_pdo_sqlite.dll scripts\pedidos\migrar-a-php.php
    ) else (
        "%PEDIDOS_PHP%" -d "extension_dir=%PEDIDOS_PHP_DIR%ext" -d extension=php_pdo_mysql.dll scripts\pedidos\migrar-a-php.php
    )
) else (
    "%PEDIDOS_PHP%" scripts\pedidos\migrar-a-php.php
)
if errorlevel 1 (
    echo No se completo la actualizacion. Conserva tus datos y revisa el mensaje anterior.
) else (
    echo Actualizacion terminada. Abre el POS y recarga con Ctrl + F5.
)
pause
