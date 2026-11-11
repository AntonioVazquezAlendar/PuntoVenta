"""Retira únicamente eventos de cuentas QA con el prefijo aleatorio de la prueba."""
import base64,json,re,subprocess
from pathlib import Path
def limpiar(prefix):
    if not re.fullmatch(r'qa_[A-Za-z0-9_]{5,70}',prefix):raise ValueError('Prefijo QA no válido')
    encoded=base64.b64encode(prefix.encode()).decode()
    subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();$prefix=base64_decode("'+encoded+'");$q=$db->prepare("DELETE FROM bitacora_actividad WHERE LEFT(usuario_login,?)=?");$q->execute(array(strlen($prefix),$prefix));',cwd=Path(__file__).resolve().parents[1],text=True,capture_output=True,check=True)
