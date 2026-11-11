from qa_auditoria import limpiar
"""Alertas: usa únicamente productos, ubicaciones, usuarios y permisos QA temporales."""
import base64,csv,http.cookiejar,io,json,re,secrets,subprocess,sys,urllib.request,urllib.error,uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];STATE=ROOT/'tests/tmp/alerts-ui.json';BASE='http://127.0.0.1:8080/PuntoVenta/public/api'
PREFIX='qa_alert_'+secrets.token_hex(5);PASSWORD=secrets.token_urlsafe(12)
def sql(code):
 return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout
class Client:
 def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def request(self,path,method='GET',data=None,expected=200,csrf=True):
  headers={};body=None
  if method!='GET':
   headers['Content-Type']='application/json';body=json.dumps(data or {}).encode()
   if csrf:headers['X-CSRF-Token']=self.csrf
  try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=headers,method=method),timeout=30)
  except urllib.error.HTTPError as e:r=e
  contents=r.read();assert r.status==expected,(path,r.status,expected,contents[:500])
  if r.headers.get_content_type()=='text/csv':return contents.decode('utf-8-sig')
  d=json.loads(contents)['data']
  if isinstance(d,dict) and 'csrf' in d:self.csrf=d['csrf']
  return d
 def login(self,suffix):self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})
def cleanup(prefix):
 limpiar(prefix)
 assert re.fullmatch(r'qa_alert_[a-f0-9]{10}',prefix)
 sql('$db->beginTransaction();$prefix="'+prefix+'";$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?")->execute(array($prefix."%"));$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?")->execute(array($prefix."%"));$db->prepare("DELETE FROM productos WHERE descripcion IN (?,?)")->execute(array($prefix,$prefix."batch"));$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario IN (?,?)")->execute(array($prefix."_admin",$prefix."_reader"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array($prefix."_admin",$prefix."_reader"));$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array($prefix."_role"));$db->prepare("DELETE FROM ubicaciones WHERE nombre IN (?,?,?,?)")->execute(array($prefix." Tienda",$prefix." Bodega",$prefix." Dañados",$prefix." Inactiva"));$db->commit();')
if '--cleanup-ui' in sys.argv:
 if STATE.exists():cleanup(json.loads(STATE.read_text(encoding='utf-8'))['prefix']);STATE.unlink();print('Fixtures de alertas retirados.')
 raise SystemExit(0)
assert not STATE.exists(),'Usa --cleanup-ui para retirar primero la prueba visual anterior.'
keep=False
try:
 p64=base64.b64encode(PASSWORD.encode()).decode()
 f=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles(nombre,permisos) VALUES(?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("ver_inventario"=>true))));$r=$db->lastInsertId();$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)");$q->execute(array("QA alertas administrador","'+PREFIX+'_admin",1,$h));$q->execute(array("QA alertas lector","'+PREFIX+'_reader",$r,$h));$q=$db->prepare("INSERT INTO ubicaciones(nombre,activo,disponible_venta) VALUES(?,?,?)");$q->execute(array("'+PREFIX+' Tienda",1,1));$l1=$db->lastInsertId();$q->execute(array("'+PREFIX+' Bodega",1,1));$l2=$db->lastInsertId();$q->execute(array("'+PREFIX+' Dañados",1,0));$l3=$db->lastInsertId();$q->execute(array("'+PREFIX+' Inactiva",0,1));$l4=$db->lastInsertId();$db->commit();echo json_encode(array("rol"=>$r,"l1"=>$l1,"l2"=>$l2,"l3"=>$l3,"l4"=>$l4));'))
 a=Client();a.login('_admin');reader=Client();reader.login('_reader');anon=Client();anon.request('/sesion')
 cat=a.request('/catalogos');units={u['codigo']:u['id'] for u in cat['unidades']};tax=cat['impuestos'][0]['id'];ids={}
 def product(suffix,name,unit='pza',minimum='5',active=1,control=1,kind='estandar',variants=None):
  body={'nombre':name,'descripcion':PREFIX,'categoria_id':None,'unidad_base_id':units[unit],'impuesto_id':tax,'tipo_producto':kind,'precio_incluye_impuesto':1,'controla_inventario':control,'activo':active,'variantes':variants or [{'sku':PREFIX+suffix,'nombre':'','costo':'10','precio':'20','stock_minimo':minimum,'activo':1}]}
  pid=a.request('/productos','POST',body,201)['id'];return next(p for p in a.request('/productos?todos=1') if str(p['id'])==str(pid))
 for suffix,name,kwargs in [('piece','Termo de prueba 750 ml',{}),('bulk','Insumo a granel de prueba',{'unit':'kg','minimum':'2.5','kind':'granel'}),('empty','=Termo de prueba agotado',{'minimum':'3'}),('zero','Termo sin alertas',{'minimum':'0'}),('inactive','Termo inactivo',{'active':0}),('service','Servicio de prueba',{'unit':'srv','kind':'servicio'}),('untracked','Sin control de inventario',{'control':0})]:ids[suffix]=product(suffix,name,**kwargs)['variantes'][0]['id']
 payload=base64.b64encode(json.dumps({'f':f,'ids':ids}).encode()).decode()
 sql('$d=json_decode(base64_decode("'+payload+'"),true);$q=$db->prepare("INSERT INTO existencias(ubicacion_id,variante_id,cantidad) VALUES(?,?,?)");$q->execute(array($d["f"]["l1"],$d["ids"]["piece"],2));$q->execute(array($d["f"]["l2"],$d["ids"]["piece"],20));$q->execute(array($d["f"]["l1"],$d["ids"]["bulk"],0.5));')
 root='/inventario/alertas';query='?q='+PREFIX
 def listing(who=a,**kw):return who.request(root+'?'+urllib.parse.urlencode(dict(q=PREFIX,**kw)))
 def row(variant,loc=None,who=a):return next(r for r in listing(who,modo='minimos',ubicacion_id=loc or f['l1'])['filas'] if str(r['variante_id'])==str(variant))
 def save(r,value='4',default=False,who=a,expected=200,**changes):
  d={'ubicacion_id':r['ubicacion_id'],'variante_id':r['variante_id'],'version':r['version'],'minimo':value,'usar_general':default};d.update(changes);return who.request('/inventario/minimos','PUT',d,expected)
 anon.request(root,expected=401);reader.request(root,expected=403);reader.request('/inventario/alertas/catalogos',expected=403);reader.request(root+'/csv',expected=403)
 a.request(root,'POST',{},405);a.request(root+'?pagina=0',expected=422);a.request(root+'?estado=invalid',expected=422)
 eligible=a.request(root+'/catalogos')['ubicaciones'];location_ids={str(l['id']) for l in eligible};n=len(eligible)
 first=listing();assert first['total']==3*n-1,first
 assert {str(r['variante_id']) for r in first['filas']}=={str(ids[k]) for k in ('piece','bulk','empty')}
 assert all(str(r['ubicacion_id']) in location_ids for r in first['filas'])
 assert listing(ubicacion_id=f['l1'])['total']==3
 assert listing(estado='agotado')['total']==3*n-3
 perms={p['clave']:False for p in a.request('/permisos')};perms['ver_alertas_inventario']=True
 rolepath='/roles/'+f['rol']+'/permisos';a.request(rolepath,'PUT',{'permisos':perms},422)
 perms['ver_inventario']=True;a.request(rolepath,'PUT',{'permisos':perms});assert listing(reader)['total']==3*n-1
 reader.request(root+'?modo=minimos',expected=403);reader.request(rolepath,'PUT',{'permisos':perms},403)
 r=row(ids['piece']);save(r,who=reader,expected=403)
 a.request('/inventario/minimos','PUT',{'ubicacion_id':f['l1']},403,csrf=False)
 save(r,'-1',expected=422);save(r,'1.2',expected=422);save(r,'10000000000',expected=422);save(r,'1',version='bad',expected=422);save(r,'1',ubicacion_id=f['l3'],expected=422);save(r,'1',ubicacion_id=f['l4'],expected=422);save(dict(r,variante_id=ids['inactive']),expected=422);save(dict(r,variante_id=ids['service']),expected=422)
 baseline=sql('$q=$db->prepare("SELECT e.ubicacion_id,e.variante_id,e.cantidad FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ? ORDER BY e.ubicacion_id,e.variante_id");$q->execute(array("'+PREFIX+'%"));echo json_encode($q->fetchAll());')
 save(r,'1');assert not any(str(t['variante_id'])==str(ids['piece']) and str(t['ubicacion_id'])==f['l1'] for t in listing()['filas'])
 save(r,'7',expected=409)
 r=row(ids['piece']);save(r,'0');assert row(ids['piece'])['estado']=='desactivado'
 save(row(ids['piece']),default=True);assert row(ids['piece'])['minimo']=='5.0000' and row(ids['piece'])['minimo_local'] is None
 stock=next(x for x in a.request('/inventario?ubicacion_id='+f['l1']) if x['variante_id']==ids['piece']);assert stock['stock_minimo']=='5.0000'
 perms['configurar_minimos']=True;a.request(rolepath,'PUT',{'permisos':perms});save(row(ids['bulk'],who=reader),'1.2345',who=reader);assert row(ids['bulk'])['minimo']=='1.2345'
 assert baseline==sql('$q=$db->prepare("SELECT e.ubicacion_id,e.variante_id,e.cantidad FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ? ORDER BY e.ubicacion_id,e.variante_id");$q->execute(array("'+PREFIX+'%"));echo json_encode($q->fetchAll());')
 # Concurrent edit: one succeeds, the other receives a conflict, without overwriting.
 other=Client();other.login('_reader');r=row(ids['piece']);payload={'ubicacion_id':r['ubicacion_id'],'variante_id':r['variante_id'],'version':r['version'],'minimo':'6','usar_general':False}
 def race(client):
  req=urllib.request.Request(BASE+'/inventario/minimos',data=json.dumps(payload).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':client.csrf},method='PUT')
  try:resp=client.http.open(req,timeout=30)
  except urllib.error.HTTPError as e:resp=e
  resp.read();return resp.status
 with ThreadPoolExecutor(2) as pool:statuses=list(pool.map(race,[reader,other]))
 assert sorted(statuses)==[200,409],statuses
 # Real stock movement only on QA fixture: alerts must reflect it immediately.
 a.request('/inventario/ajustes','POST',{'ubicacion_id':f['l1'],'variante_id':ids['piece'],'modo':'entrada','cantidad':'4','motivo':'QA alertas reposición','clave':str(uuid.uuid4())},201)
 assert row(ids['piece'])['estado']=='minimo' and row(ids['piece'])['faltante']=='0.0000'
 a.request('/inventario/ajustes','POST',{'ubicacion_id':f['l1'],'variante_id':ids['piece'],'modo':'salida','cantidad':'7','motivo':'QA alertas negativo','clave':str(uuid.uuid4())},201)
 assert listing(estado='negativo')['total']==1
 text=a.request(root+'/csv'+query);csvrows=list(csv.reader(io.StringIO(text)));assert len(csvrows)==listing()['total']+1;assert any(r[1].startswith("'=Termo") for r in csvrows[1:]);assert all(r[8] in ('Negativo','Agotado','Bajo mínimo','En mínimo') for r in csvrows[1:])
 variants=[{'sku':PREFIX+'_batch'+str(i),'nombre':str(i),'costo':'1','precio':'2','stock_minimo':'1','activo':1} for i in range(55)]
 batch=product('batch','QA paginación',variants=variants);p1=a.request(root+'?q='+PREFIX+'_batch');p2=a.request(root+'?q='+PREFIX+'_batch&pagina=2');assert p1['total']==55*n and len(p1['filas'])==50 and len(p2['filas'])==50
 key=lambda r:(r['variante_id'],r['ubicacion_id']);assert not set(map(key,p1['filas']))&set(map(key,p2['filas']))
 sql('$db->prepare("DELETE FROM productos WHERE id=? AND descripcion=?")->execute(array('+str(batch['id'])+',"'+PREFIX+'"));')
 # Revoke immediately; existing session must lose access.
 perms['configurar_minimos']=False;perms['ver_alertas_inventario']=False;a.request(rolepath,'PUT',{'permisos':perms});reader.request(root,expected=403);save(row(ids['piece']),who=reader,expected=403)
 if '--keep-ui' in sys.argv:
  STATE.parent.mkdir(exist_ok=True);STATE.write_text(json.dumps({'prefix':PREFIX,'password':PASSWORD,'fixture':f,'ids':ids}),encoding='utf-8');keep=True
 print('OK: mínimos locales/general/cero, faltantes exactos, estados, exclusiones, permisos en vivo, CSRF, validación de unidades, concurrencia, stock sin alteraciones al configurar, actualización tras ajustes, CSV protegido y paginación.')
finally:
 if not keep:cleanup(PREFIX);print('Fixtures retirados; existencias y roles del negocio conservados.')
