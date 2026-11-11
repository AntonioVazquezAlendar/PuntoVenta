"""Bitácora con cuentas, productos y registros QA desechables; no modifica registros del negocio."""
from qa_auditoria import limpiar
import base64,http.cookiejar,json,re,secrets,subprocess,sys,urllib.request,urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];STATE=ROOT/'tests/tmp/audit-ui.json';BASE='http://127.0.0.1:8080/PuntoVenta/public/api';PREFIX='qa_audit_'+secrets.token_hex(5);PASSWORD=secrets.token_urlsafe(12)
def sql(code):return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout
def cleanup(prefix):
 assert re.fullmatch(r'qa_audit_[a-f0-9]{10}',prefix);limpiar(prefix)
 sql('$db->beginTransaction();$db->prepare("DELETE FROM productos WHERE descripcion=?")->execute(array("'+prefix+'"));$db->prepare("DELETE FROM usuarios WHERE LEFT(nombre_usuario,?)=?")->execute(array('+str(len(prefix))+',"'+prefix+'"));$db->prepare("DELETE FROM roles WHERE LEFT(nombre,?)=?")->execute(array('+str(len(prefix))+',"'+prefix+'"));$db->prepare("DELETE FROM ubicaciones WHERE nombre=?")->execute(array("'+prefix+'"));$db->commit();')
if '--cleanup-ui' in sys.argv:
 if STATE.exists():cleanup(json.loads(STATE.read_text(encoding='utf-8'))['prefix']);STATE.unlink();print('Fixtures de bitácora retirados.')
 raise SystemExit(0)
assert not STATE.exists(),'Retira el fixture visual anterior con --cleanup-ui.'
class Client:
 def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def request(self,path,method='GET',data=None,expected=200):
  h={};body=None
  if method!='GET':h={'Content-Type':'application/json','X-CSRF-Token':self.csrf};body=json.dumps(data or {}).encode()
  try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=h,method=method),timeout=30)
  except urllib.error.HTTPError as e:r=e
  result=json.load(r);assert r.status==expected,(path,r.status,expected,result);d=result['data']
  if isinstance(d,dict) and 'csrf' in d:self.csrf=d['csrf']
  return d
 def login(self,suffix):self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})
keep=False
try:
 encoded=base64.b64encode(PASSWORD.encode()).decode();f=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles(nombre,permisos) VALUES(?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("ver_reportes"=>true,"editar_productos"=>true,"ver_productos"=>true,"ver_inventario"=>true,"ver_alertas_inventario"=>true))));$rid=$db->lastInsertId();$hash=password_hash(base64_decode("'+encoded+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)");$q->execute(array("QA bitácora administrador","'+PREFIX+'_admin",1,$hash));$admin=$db->lastInsertId();$q->execute(array("QA bitácora lector","'+PREFIX+'_reader",$rid,$hash));$db->prepare("INSERT INTO ubicaciones(nombre) VALUES(?)")->execute(array("'+PREFIX+'"));$loc=$db->lastInsertId();$db->commit();echo json_encode(array("admin"=>$admin,"rol"=>$rid,"loc"=>$loc));'))
 a=Client();a.login('_admin');reader=Client();reader.login('_reader');anon=Client();anon.request('/bitacora',expected=401);reader.request('/bitacora',expected=403);reader.request('/bitacora/catalogos',expected=403)
 for method in ('POST','PUT','DELETE'):a.request('/bitacora',method,{},405);a.request('/bitacora/1',method,{},405)
 meta=a.request('/bitacora/catalogos');query='?usuario_id='+f['admin'];catalog=a.request('/catalogos');units={u['codigo']:u['id'] for u in catalog['unidades']};tax=catalog['impuestos'][0]['id']
 body={'nombre':'Termo QA para bitácora','descripcion':PREFIX,'categoria_id':None,'unidad_base_id':units['pza'],'impuesto_id':tax,'tipo_producto':'estandar','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,'variantes':[{'sku':PREFIX,'nombre':'Negro','costo':'10','precio':'20','stock_minimo':'5','activo':1}]}
 product=a.request('/productos','POST',body,201);pid=product['id'];saved=next(p for p in a.request('/productos?todos=1') if p['id']==pid);body['variantes'][0]['id']=saved['variantes'][0]['id'];body['variantes'][0]['precio']='25';a.request('/productos/'+pid,'PUT',body)
 priceevent=next(r for r in a.request('/bitacora'+query+'&tipo=producto_editado')['registros'] if r['referencia']=='Producto '+pid);detail=a.request('/bitacora/'+priceevent['id']);assert detail['detalle']['antes']['variantes'][0]['precio']=='20.0000' and detail['detalle']['despues']['variantes'][0]['precio']=='25.0000'
 reader.request('/bitacora/'+priceevent['id'],expected=403)
 before=len(a.request('/bitacora'+query+'&tipo=producto_editado')['registros']);a.request('/productos/'+pid,'PUT',body);assert len(a.request('/bitacora'+query+'&tipo=producto_editado')['registros'])==before
 bad=json.loads(json.dumps(body));bad['variantes'].append(dict(bad['variantes'][0],id=None));a.request('/productos/'+pid,'PUT',bad,409);assert len(a.request('/bitacora'+query+'&tipo=producto_editado')['registros'])==before
 perms={p['clave']:False for p in a.request('/permisos')};newrole=a.request('/roles','POST',{'nombre':PREFIX+'_custom','permisos':perms},201);perms['ver_productos']=True;a.request('/roles/'+newrole['id']+'/permisos','PUT',{'permisos':perms});assert a.request('/bitacora'+query+'&tipo=rol_creado')['registros'] and a.request('/bitacora'+query+'&tipo=permisos_editados')['registros']
 target={'nombre':'QA persona','nombre_usuario':PREFIX+'_target','rol_id':newrole['id'],'activo':1,'contrasena':PASSWORD};t=a.request('/usuarios','POST',target,201);tid=t['id'];target['contrasena']='';a.request('/usuarios/'+tid,'PUT',target);target['contrasena']='QaChange8!';a.request('/usuarios/'+tid,'PUT',target);event=a.request('/bitacora'+query+'&tipo=usuario_editado')['registros'][0];d=a.request('/bitacora/'+event['id']);assert d['detalle']['contexto']['contrasena_modificada'] is True;assert all(secret not in json.dumps(d) for secret in (PASSWORD,'QaChange8!','contrasena_hash','$2y$'))
 variant=saved['variantes'][0]['id'];a.request('/inventario/minimos','PUT',{'ubicacion_id':f['loc'],'variante_id':variant,'version':0,'usar_general':False,'minimo':'3'});minimum=a.request('/bitacora'+query+'&tipo=minimo_configurado')['registros'][0];d=a.request('/bitacora/'+minimum['id']);assert d['detalle']['antes']['minimo_local'] is None and d['detalle']['despues']['minimo_local']=='3.0000'
 for i in range(33):target['nombre']='QA persona '+str(i);target['contrasena']='';a.request('/usuarios/'+tid,'PUT',target)
 p1=a.request('/bitacora'+query+'&tipo=usuario_editado');assert len(p1['registros'])==30 and p1['siguiente'];cursor=p1['siguiente'];target['nombre']='QA persona final';a.request('/usuarios/'+tid,'PUT',target);p2=a.request('/bitacora'+query+'&tipo=usuario_editado&antes_de='+cursor);assert p2['registros'] and not ({r['id'] for r in p1['registros']}&{r['id'] for r in p2['registros']})
 a.request('/bitacora'+query+'&tipo=producto_editado&antes_de='+cursor,expected=422);a.request('/bitacora?antes_de=invalid',expected=422);a.request('/bitacora?desde=2026-02-30',expected=422);a.request('/bitacora?desde=2023-01-01&hasta=2025-01-01',expected=422);a.request('/bitacora?tipo=invalid',expected=422);a.request('/bitacora/99999999999999999',expected=404)
 if '--keep-ui' in sys.argv:STATE.parent.mkdir(exist_ok=True);STATE.write_text(json.dumps({'prefix':PREFIX,'password':PASSWORD,'fixture':f,'event_id':priceevent['id']}),encoding='utf-8');keep=True
 print('OK: acceso exclusivo, API inmutable, productos/precios/usuarios/roles/permisos/mínimos antes-después, secretos excluidos, cambios sin efecto y fallos sin registro, paginación estable y filtros validados.')
finally:
 if not keep:cleanup(PREFIX);print('Fixtures retirados; registros del negocio conservados.')
