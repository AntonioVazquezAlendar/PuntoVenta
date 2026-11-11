from qa_auditoria import limpiar
"""HTTP de Configuración: solo usuarios y rol QA, sin crear ventas, abrir cajas ni cambiar saldos."""
import base64,http.cookiejar,json,re,secrets,subprocess,sys,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];STATE=ROOT/'tests/tmp/config-ui.json';BASE='http://127.0.0.1:8080/PuntoVenta/public/api';PREFIX='qa_config_'+secrets.token_hex(5);PASSWORD=secrets.token_urlsafe(12)
def sql(code):return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout
def cleanup(prefix):
 limpiar(prefix)
 assert re.fullmatch(r'qa_config_[a-f0-9]{10}',prefix)
 sql('$db->beginTransaction();$db->prepare("DELETE FROM metodos_pago WHERE nombre=?")->execute(array("'+prefix+'_method"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array("'+prefix+'_admin","'+prefix+'_reader"));$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+prefix+'_role"));$db->commit();')
if '--cleanup-ui' in sys.argv:
 if STATE.exists():cleanup(json.loads(STATE.read_text(encoding='utf-8'))['prefix']);STATE.unlink();print('Cuentas QA de Configuración retiradas.')
 raise SystemExit(0)
assert not STATE.exists(),'Retira el fixture visual anterior con --cleanup-ui.'
class Client:
 def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def request(self,path,method='GET',data=None,expected=200,csrf=True):
  h={};body=None
  if method!='GET':h={'Content-Type':'application/json'};h.update({'X-CSRF-Token':self.csrf} if csrf else {});body=json.dumps(data or {}).encode()
  try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=h,method=method),timeout=30)
  except urllib.error.HTTPError as e:r=e
  result=json.load(r);assert r.status==expected,(path,r.status,result);d=result['data']
  if isinstance(d,dict) and 'csrf' in d:self.csrf=d['csrf']
  return d
 def login(self,suffix):self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})
keep=False
try:
 encoded=base64.b64encode(PASSWORD.encode()).decode()
 sql('$q=$db->prepare("INSERT INTO roles(nombre,permisos) VALUES(?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("vender"=>true,"ver_reportes"=>true))));$role=$db->lastInsertId();$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)");$h=password_hash(base64_decode("'+encoded+'"),PASSWORD_BCRYPT);$q->execute(array("QA configuración","'+PREFIX+'_admin",1,$h));$q->execute(array("QA sin acceso","'+PREFIX+'_reader",$role,$h));')
 anon=Client();anon.request('/sesion');anon.request('/configuracion',expected=401)
 admin=Client();admin.login('_admin');reader=Client();reader.login('_reader')
 reader.request('/configuracion',expected=403);reader.request('/configuracion/metodos','POST',{},403)
 current=admin.request('/configuracion');assert 'version' in current and 'metodos' in current and 'rfc' in current['negocio']
 public=anon.request('/empresa');assert 'rfc' not in public and 'correo' not in public
 admin.request('/configuracion','DELETE',{},405);admin.request('/configuracion','PUT',{},403,csrf=False);admin.request('/configuracion','PUT',{},422)
 admin.request('/configuracion','PUT',dict(current['negocio'],version='stale'),409)
 methods=admin.request('/configuracion/metodos','POST',{'nombre':PREFIX+'_method','tipo':'otro','activo':0});method=next(m for m in methods if m['nombre']==PREFIX+'_method')
 edit=dict(method);edit['nombre']+=' edit';edit.pop('id');admin.request('/configuracion/metodos/'+method['id'],'PUT',edit);admin.request('/configuracion/metodos/'+method['id'],'PUT',edit,409)
 sql('$db->prepare("UPDATE metodos_pago SET nombre=? WHERE id=?")->execute(array("'+PREFIX+'_method","'+method['id']+'"));')
 if '--keep-ui' in sys.argv:STATE.parent.mkdir(exist_ok=True);STATE.write_text(json.dumps({'prefix':PREFIX,'password':PASSWORD}),encoding='utf-8');keep=True
 print('OK: HTTP exclusivo de administrador, CSRF, validación, conflictos, marca pública sin datos privados y métodos QA inactivos. Datos reales sin cambios.')
finally:
 if not keep:cleanup(PREFIX);print('Cuentas, método y auditoría QA retirados.')
