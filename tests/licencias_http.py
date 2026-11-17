"""API y UI con estado de licencia/reloj/sesiones aislados y cuentas QA temporales.
--ui mantiene el servidor hasta pulsar Enter para inspección de navegador.
"""
import base64
import http.cookiejar
import json
import os
from pathlib import Path
import secrets
import shutil
import socket
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT/'tests/tmp'
BASE.mkdir(exist_ok=True)
fixture = Path(tempfile.mkdtemp(prefix='licencias-http-',dir=BASE)).resolve()
prefix = 'qa_license_'+secrets.token_hex(6)
password = secrets.token_urlsafe(16)
php = ['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_openssl.dll','-d','extension=php_pdo_mysql.dll'] if os.name == 'nt' else ['php']
server = None
def sql(code):
    r = subprocess.run(php, input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,capture_output=True)
    assert r.returncode == 0, r.stderr
    return r.stdout
def literal(value):
    return "base64_decode('"+base64.b64encode(value.encode()).decode()+"')"
class Client:
    def __init__(self):
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''
    def req(self,path,method='GET',data=None,status=200,csrf=True):
        headers={}
        body=None
        if method!='GET':
            headers['Content-Type']='application/json'
            if csrf: headers['X-CSRF-Token']=self.csrf
            body=json.dumps(data or {}).encode()
        request=urllib.request.Request(base+path,body,headers,method=method)
        try:
            response=self.http.open(request)
        except urllib.error.HTTPError as e:
            response=e
        result=json.loads(response.read())
        assert response.code==status,(path,response.code,result)
        if path in ('/sesion','/login') and result['success']: self.csrf=result['data']['csrf']
        return result['data']
try:
    payload=literal(json.dumps({'prefix':prefix,'password':password}))
    sql('$d=json_decode('+payload+',true);$admin=$db->query("SELECT id FROM roles WHERE JSON_EXTRACT(permisos,\'$.todo\')=true LIMIT 1")->fetchColumn();if(!$admin)throw new Exception("No admin role");$db->beginTransaction();$db->prepare("INSERT INTO roles(nombre,permisos) VALUES(?,?)")->execute(array($d["prefix"],"{}"));$role=$db->lastInsertId();$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)");foreach(array("admin"=>$admin,"op"=>$role) as $suffix=>$r)$q->execute(array("QA licencia",$d["prefix"]."_".$suffix,$r,password_hash($d["password"],PASSWORD_BCRYPT)));$db->commit();')
    (fixture/'sessions').mkdir()
    emitter=['node',str(ROOT/'herramientas/licencias/emisor.js')]
    issuer_env=dict(os.environ,PV_LICENCIA_PASSWORD='QaHttp-Licencias-2026!')
    subprocess.run(emitter+['iniciar','--directorio',str(fixture/'emisor'),'--publica',str(fixture/'publica.pem')],env=issuer_env,cwd=ROOT,check=True,capture_output=True)
    now=int(time.time())
    (fixture/'clock.txt').write_text(str(now))
    (fixture/'enabled.txt').write_text('0')
    # Solo este router de pruebas inyecta su estado y reloj; producción no tiene bypass.
    service="new Licencia("+literal(fixture.as_posix()+'/state')+", "+literal((fixture/'publica.pem').as_posix())+", function(){ return (int)file_get_contents("+literal((fixture/'clock.txt').as_posix())+"); }, 'qa-http')"
    root=literal(ROOT.as_posix())
    router='''<?php
$root=ROOTVALUE;
$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($uri,'/PuntoVenta/public/assets/')===0){$f=realpath($root.'/public/'.substr($uri,strlen('/PuntoVenta/public/')));if($f&&strpos(str_replace('\\\\','/',$f),$root.'/public/assets/')===0)return false;http_response_code(404);exit;}
require $root.'/app/Config/Database.php';require $root.'/app/Helpers/HttpError.php';require $root.'/app/Helpers/Response.php';require $root.'/app/Services/Auth.php';Auth::iniciarSesion();
if($uri==='/PuntoVenta/public/'){require $root.'/views/login/index.php';exit;}
try{$code=file_get_contents($root.'/app/Routes/api.php');$code=str_replace('Licencia::habilitada()',FLAGVALUE,$code);$code=str_replace('new Licencia()',SERVICEVALUE,$code);$code=str_replace('__DIR__',var_export($root.'/app/Routes',true),$code);eval(substr($code,5));}
catch(HttpError $e){Response::json(false,$e->getMessage(),array(),$e->getCode());}
catch(Throwable $e){error_log((string)$e);Response::json(false,'Error de prueba.',array(),500);}
'''.replace('ROOTVALUE',root).replace('SERVICEVALUE',literal(service)).replace('FLAGVALUE',literal("(file_get_contents("+literal((fixture/'enabled.txt').as_posix())+") === '1')"))
    (fixture/'router.php').write_text(router,encoding='utf-8')
    with socket.socket() as s:
        s.bind(('127.0.0.1',0)); port=s.getsockname()[1]
    log=open(fixture/'server.log','w',encoding='utf-8')
    server=subprocess.Popen(php+['-d','session.save_path='+str(fixture/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(ROOT.parent),str(fixture/'router.php')],cwd=ROOT,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    base='http://127.0.0.1:'+str(port)+'/PuntoVenta/public/api'
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
        except OSError:time.sleep(.1)
    anon=Client();anon.req('/licencia',status=404)
    page=urllib.request.urlopen(base.removesuffix('/api')+'/').read().decode('utf-8')
    assert 'id="license-section"' not in page and '/assets/js/licencias.js' not in page
    admin=Client();admin.req('/sesion');admin.req('/login','POST',{'nombre_usuario':prefix+'_admin','contrasena':password})
    op=Client();op.req('/sesion');op.req('/login','POST',{'nombre_usuario':prefix+'_op','contrasena':password})
    admin.req('/licencia/prueba','POST',status=404)
    admin.req('/caja/turnos','POST',status=422)  # Llega a validación normal sin exigir licencia.
    assert not (fixture/'state/estado.json').exists()
    (fixture/'enabled.txt').write_text('1')
    anon.req('/licencia',status=401)
    assert admin.req('/licencia')['estado']=='sin_activar'
    admin.req('/licencia/prueba','POST',status=403,csrf=False)
    op.req('/licencia/prueba','POST',status=403)
    op.req('/licencia/activar','POST',{'clave':'bad'},status=403)
    admin.req('/licencia/activar','POST',{'clave':'bad'},status=422)
    admin.req('/licencia','PUT',status=405)
    admin.req('/ventas','POST',status=402)
    assert admin.req('/licencia/prueba','POST')['plan']=='prueba'
    admin.req('/licencia/prueba','POST',status=409)
    assert op.req('/licencia')['operable']
    # Al expirar, la protección intercepta escrituras aun con usuario administrador.
    (fixture/'clock.txt').write_text(str(now+15*86400))
    assert admin.req('/licencia')['estado']=='vencida'
    for route,method in [('/ventas','POST'),('/gastos','POST'),('/caja/turnos','POST'),('/compras','POST'),('/productos/1','PUT'),('/creditos/1/abonos','POST'),('/cuentas-pagar/1/pagos','POST'),('/usuarios','POST')]:
        admin.req(route,method,status=402)
    admin.req('/productos')  # Consulta real, solo lectura.
    admin.req('/caja/turnos/2147483647/cerrar','POST',status=422)
    admin.req('/ventas/pendientes/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa/descartar','POST',{'version':1,'motivo':'QA'},status=404)
    # No construir Respaldo sobre storage real: su limpieza de staging es activa.
    # La excepción de respaldos se verifica en la matriz de tests/licencias.php.
    installation=admin.req('/licencia')['instalacion']
    paid=[]
    for plan in ('mensual','anual'):
        output=fixture/(plan+'.txt')
        subprocess.run(emitter+['emitir','--directorio',str(fixture/'emisor'),'--instalacion',installation,'--negocio','QA HTTP','--plan',plan,'--salida',str(output)],env=issuer_env,cwd=ROOT,check=True,capture_output=True)
        token=output.read_text().strip()
        op.req('/licencia/activar','POST',{'clave':token},status=403)
        admin.req('/licencia/activar','POST',{'clave':token},status=403,csrf=False)
        result=admin.req('/licencia/activar','POST',{'clave':token})
        assert result['operable'] and result['plan']==plan
        assert admin.req('/licencia/activar','POST',{'clave':token})['vencimiento']==result['vencimiento']
        paid.append(result)
    assert paid[1]['vencimiento']-paid[0]['vencimiento']==365*86400
    admin.req('/licencia/activar','POST',{'clave':(fixture/'mensual.txt').read_text().strip()},status=409)
    (fixture/'clock.txt').write_text(str(paid[1]['vencimiento']))
    assert admin.req('/licencia')['estado']=='gracia'
    (fixture/'clock.txt').write_text(str(paid[1]['vencimiento']+3*86400))
    assert admin.req('/licencia')['estado']=='vencida'
    admin.req('/ventas','POST',status=402)
    preserved=(fixture/'state/estado.json').read_bytes()
    (fixture/'enabled.txt').write_text('0')
    admin.req('/licencia',status=404)
    admin.req('/caja/turnos','POST',status=422)
    assert (fixture/'state/estado.json').read_bytes()==preserved
    (fixture/'enabled.txt').write_text('1')
    assert admin.req('/licencia')['estado']=='vencida'
    print('OK: pausa oculta el panel, evita bloqueos y conserva el estado para reactivación.',flush=True)
    print('OK: activación mensual/anual por HTTP, renovación acumulada, reintentos, rechazo de clave anterior y tres días exactos de gracia.',flush=True)
    print('OK: API HTTP autenticada, CSRF, administrador, prueba única, vencimiento y consultas/cierre/descarte disponibles.',flush=True)
    if '--ui' in sys.argv:
        # El fixture expirado permite ver el aviso sin tocar la licencia real.
        print(json.dumps({'url':base.removesuffix('/api')+'/', 'usuario':prefix+'_admin','password':password}),flush=True)
        input('Pulsa Enter para retirar las cuentas QA y el servidor aislado: ')
finally:
    if server:
        server.terminate();server.wait(timeout=10);log.close()
    encoded=literal(prefix)
    sql('$prefix='+encoded+';$db->beginTransaction();$q=$db->prepare("DELETE FROM bitacora_actividad WHERE LEFT(usuario_login,?)=?");$q->execute(array(strlen($prefix),$prefix));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array($prefix."_admin",$prefix."_op"));$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array($prefix));$db->commit();')
    assert fixture.parent==BASE.resolve() and fixture.name.startswith('licencias-http-')
    shutil.rmtree(fixture)
