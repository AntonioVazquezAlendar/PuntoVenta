"""Comprueba cambio/reversión de demos en una copia temporal de la configuración.
No cambia la selección, sesiones ni configuración de la aplicación en uso.
Requiere haber creado pos_demo_abarrotes con el instalador sin --activar.
"""
from pathlib import Path
import subprocess,shutil,secrets,json
ROOT=Path(__file__).resolve().parents[1]
TMP=(ROOT/'tests/tmp'/('demo_harness_'+secrets.token_hex(6))).resolve()
PHP=['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll']
def run(args):
    r=subprocess.run(PHP+args,cwd=TMP,text=True,capture_output=True,encoding='utf-8')
    assert r.returncode==0,r.stdout+r.stderr
    return r.stdout
def selected(original=False):
    code='<?php require "app/Config/Database.php";echo (new Database())->connect('+('true' if original else 'false')+')->query("SELECT DATABASE()")->fetchColumn();'
    r=subprocess.run(PHP,cwd=TMP,input=code,text=True,capture_output=True,encoding='utf-8',check=True)
    return r.stdout
try:
    for folder in ['app/Config','scripts/demos','storage']:(TMP/folder).mkdir(parents=True,exist_ok=True)
    config=(ROOT/'app/Config/Database.php').read_bytes();epoch=ROOT/'storage/session_epoch';before_epoch=epoch.read_bytes() if epoch.exists() else None
    shutil.copyfile(ROOT/'app/Config/Database.php',TMP/'app/Config/Database.php')
    for name in ['instalar.php','catalogo.json']:shutil.copyfile(ROOT/'scripts/demos'/name,TMP/'scripts/demos'/name)
    original=selected(True)
    assert 'Base activa: pos_demo_abarrotes' in run(['scripts/demos/instalar.php','abarrotes','--activar'])
    assert selected()=='pos_demo_abarrotes' and selected(True)==original
    first_epoch=(TMP/'storage/session_epoch').read_text()
    assert 'se conserva' in run(['scripts/demos/instalar.php','abarrotes','--activar']).lower()
    assert first_epoch!=(TMP/'storage/session_epoch').read_text()
    # Recovery remains possible even if the selection file is corrupted.
    (TMP/'storage/demo_database.json').write_text('{"base":"invalid"}',encoding='utf-8')
    run(['scripts/demos/instalar.php','--original']);assert selected()==original
    assert not (TMP/'storage/demo_database.json').exists()
    assert (ROOT/'app/Config/Database.php').read_bytes()==config
    assert (epoch.read_bytes() if epoch.exists() else None)==before_epoch
    print('OK: instalador conserva demos existentes, activa, cambia época de sesión y vuelve a original, incluso con selección inválida. Configuración real intacta.')
finally:
    assert TMP.parent==(ROOT/'tests/tmp').resolve() and TMP.name.startswith('demo_harness_')
    if TMP.exists():shutil.rmtree(TMP)
