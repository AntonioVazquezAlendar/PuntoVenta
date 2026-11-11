@echo off
setlocal
cd /d "%~dp0\..\.."
set "DEMO_PHP=C:\AppServ\php7\php.exe"
if not exist "%DEMO_PHP%" set "DEMO_PHP=php"
"%DEMO_PHP%" -d extension_dir=C:/AppServ/php7/ext -d extension=php_pdo_mysql.dll "%~dp0instalar.php" restaurante --activar
if errorlevel 1 echo Revisa el mensaje anterior. No se borraron datos.
pause
