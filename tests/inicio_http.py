from qa_auditoria import limpiar
"""HTTP de Inicio: solo usuarios y rol QA, sin crear ventas, abrir cajas ni cambiar saldos."""
import base64,http.cookiejar,json,re,secrets,subprocess,sys,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];STATE=ROOT/'tests/tmp/home-ui.json';BASE='http://127.0.0.1:8080/PuntoVenta/public/api';PREFIX='qa_home_'+secrets.token_hex(5);PASSWORD=secrets.token_urlsafe(12)
def sql(code):return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout
def cleanup(prefix):
 limpiar(prefix)
 assert re.fullmatch(r'qa_home_[a-f0-9]{10}',prefix)
 sql('$db->beginTransaction();$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array("'+prefix+'_admin","'+prefix+'_reader"));$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+prefix+'_role"));$db->commit();')
if '--cleanup-ui' in sys.argv:
 if STATE.exists():cleanup(json.loads(STATE.read_text(encoding='utf-8'))['prefix']);STATE.unlink();print('Cuentas QA de Inicio retiradas.')
 raise SystemExit(0)
assert not STATE.exists(),'Retira el fixture visual anterior con --cleanup-ui.'
class Client:
 def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def request(self,path,method='GET',data=None,expected=200):
  h={};body=None
  if method!='GET':h={'Content-Type':'application/json','X-CSRF-Token':self.csrf};body=json.dumps(data or {}).encode()
  try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=h,method=method),timeout=30)
  except urllib.error.HTTPError as e:r=e
  result=json.load(r);assert r.status==expected,(path,r.status,result);d=result['data']
  if isinstance(d,dict) and 'csrf' in d:self.csrf=d['csrf']
  return d
 def login(self,suffix):self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})
keep=False
try:
 encoded=base64.b64encode(PASSWORD.encode()).decode();sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles(nombre,permisos) VALUES(?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("ver_reportes"=>true,"ver_inventario"=>true,"ver_alertas_inventario"=>true,"operar_caja"=>true,"supervisar_caja"=>true,"ver_creditos"=>true,"ver_cuentas_pagar"=>true,"ver_gastos"=>true))));$rid=$db->lastInsertId();$hash=password_hash(base64_decode("'+encoded+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)");$q->execute(array("QA Inicio administrador","'+PREFIX+'_admin",1,$hash));$q->execute(array("QA Inicio supervisor","'+PREFIX+'_reader",$rid,$hash));$db->commit();')
 anon=Client();anon.request('/inicio',expected=401);a=Client();a.login('_admin');reader=Client();reader.login('_reader');reader.request('/inicio',expected=403);a.request('/inicio','POST',{},405)
 result=a.request('/inicio');report=a.request('/reportes/ventas?desde='+result['hoy']+'&hasta='+result['hoy']);assert all(result['ventas'][key]==report['resumen'][key] for key in ('ventas','devuelto','neto','costo','utilidad'));assert result['gastos']['total']==report['resumen']['gastos'];alerts=a.request('/inventario/alertas');assert int(result['inventario']['alertas'])==alerts['total'];assert len(result['inventario']['filas'])<=5 and len(result['cajas']['turnos'])<=8
 a.request('/logout','POST');a.request('/inicio',expected=401)
 if '--keep-ui' in sys.argv:STATE.parent.mkdir(exist_ok=True);STATE.write_text(json.dumps({'prefix':PREFIX,'password':PASSWORD}),encoding='utf-8');keep=True
 print('OK: Inicio exclusivo del administrador, 401/403/405, resumen diario igual al reporte, gastos y alertas consistentes, listas limitadas y sesión cerrada.')
finally:
 if not keep:cleanup(PREFIX);print('Cuentas QA retiradas; datos del negocio conservados.')
