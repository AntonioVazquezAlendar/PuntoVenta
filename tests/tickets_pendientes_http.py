"""Tickets pendientes y recuperación de cobros: fixtures QA independientes del negocio."""
from qa_auditoria import limpiar
import base64,copy,http.cookiejar,json,re,secrets,subprocess,sys,urllib.request,urllib.error,urllib.parse,uuid
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE='http://127.0.0.1:8080/PuntoVenta/public/api';STATE=ROOT/'tests/tmp/pending-ui.json';PREFIX='qa_pending_'+secrets.token_hex(5);PASSWORD=secrets.token_urlsafe(12)
def sql(code):return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout
def cleanup(prefix):
 assert re.fullmatch(r'qa_pending_[a-f0-9]{10}',prefix);limpiar(prefix)
 p64=base64.b64encode(prefix.encode()).decode();sql('$p=base64_decode("'+p64+'");$db->beginTransaction();foreach(array("DELETE d FROM borradores_venta d JOIN usuarios u ON u.id=d.usuario_id WHERE LEFT(u.nombre_usuario,?)=?","DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE LEFT(v.sku,?)=?","DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE LEFT(v.sku,?)=?","DELETE s FROM solicitudes_venta s JOIN usuarios u ON u.id=s.usuario_id WHERE LEFT(u.nombre_usuario,?)=?","DELETE v FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE LEFT(u.nombre_usuario,?)=?","DELETE FROM productos WHERE LEFT(nombre,?)=?","DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE LEFT(u.nombre_usuario,?)=?","DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE LEFT(u.nombre_usuario,?)=?","DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE LEFT(c.nombre,?)=?","DELETE FROM cajas WHERE LEFT(nombre,?)=?","DELETE FROM usuarios WHERE LEFT(nombre_usuario,?)=?","DELETE FROM roles WHERE LEFT(nombre,?)=?","DELETE FROM ubicaciones WHERE LEFT(nombre,?)=?") as $s)$db->prepare($s)->execute(array(strlen($p),$p));$db->commit();')
if '--cleanup-ui' in sys.argv:
 if STATE.exists():cleanup(json.loads(STATE.read_text(encoding='utf-8'))['prefix']);STATE.unlink();print('Fixtures de pendientes retirados.')
 raise SystemExit(0)
assert not STATE.exists(),'Limpia primero el fixture anterior con --cleanup-ui.'
class Client:
 def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def req(self,path,method='GET',data=None,expected=200,csrf=True):
  h={};body=None
  if method!='GET':h={'Content-Type':'application/json'};body=json.dumps(data or {}).encode();h.update({'X-CSRF-Token':self.csrf} if csrf else {})
  try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=h,method=method),timeout=30)
  except urllib.error.HTTPError as e:r=e
  result=json.load(r);assert r.status==expected,(path,r.status,expected,result);d=result['data']
  if isinstance(d,dict) and 'csrf' in d:self.csrf=d['csrf']
  return d
 def login(self,suffix):self.req('/sesion');self.req('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})
keep=False
try:
 encoded=base64.b64encode(PASSWORD.encode()).decode();f=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles(nombre,permisos) VALUES(?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("vender"=>true,"ver_ventas"=>true,"operar_caja"=>true))));$role=$db->lastInsertId();$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)");$h=password_hash(base64_decode("'+encoded+'"),PASSWORD_BCRYPT);$q->execute(array("QA pendientes administrador","'+PREFIX+'_admin",1,$h));$q->execute(array("QA pendientes cajero","'+PREFIX+'_a",$role,$h));$q->execute(array("QA pendientes otro","'+PREFIX+'_b",$role,$h));$db->prepare("INSERT INTO ubicaciones(nombre) VALUES(?)")->execute(array("'+PREFIX+'"));$loc=$db->lastInsertId();$db->commit();echo json_encode(array("loc"=>$loc));'))
 admin=Client();admin.login('_admin');a=Client();a.login('_a');b=Client();b.login('_b');anon=Client();anon.req('/sesion');look=admin.req('/catalogos');unit=next(u['id'] for u in look['unidades'] if u['codigo']=='pza');tax=next(t['id'] for t in look['impuestos'] if t['tasa']=='0.1600')
 pid=admin.req('/productos','POST',{'nombre':PREFIX+'_Termo','descripcion':'QA recuperación','categoria_id':None,'unidad_base_id':unit,'impuesto_id':tax,'tipo_producto':'estandar','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,'variantes':[{'sku':PREFIX,'nombre':'Negro','precio':'116','costo':'50','stock_minimo':'0','activo':1}]},201)['id'];product=next(p for p in admin.req('/productos?todos=1') if p['id']==pid);vid=product['variantes'][0]['id'];admin.req('/inventario/ajustes','POST',{'ubicacion_id':f['loc'],'variante_id':vid,'modo':'inicial','cantidad':'10','motivo':'QA','clave':str(uuid.uuid4())},201)
 turns=[];boxes=[]
 for i,client in enumerate([a,b]):
  box=admin.req('/caja/cajas','POST',{'nombre':PREFIX+'_caja'+str(i),'ubicacion_id':f['loc'],'activo':1},201)['id'];boxes.append(box);turns.append(client.req('/caja/turnos','POST',{'caja_id':box,'monto_apertura':'100','clave':str(uuid.uuid4())},201)['turno_id'])
 cash=next(m['id'] for m in a.req('/ventas/contexto')['metodos'] if m['tipo']=='efectivo');item=a.req('/ventas/productos?'+urllib.parse.urlencode({'turno_id':turns[0],'ids':vid}))['productos'][0]
 def content(turn=turns[0],n='2'):
  return {'venta':{'turno_id':turn,'cliente_id':None,'cliente_nombre':'Cliente QA pendiente','notas':'QA nota conservada','detalles':[{'variante_id':vid,'cantidad':n,'precio_unitario':'116.0000','descuento':'0'}]},'productos':[item],'pagos':[{'metodo_pago_id':cash,'monto':'232.00','recibido':'300','referencia':'','auto':False}],'cliente_tocado':True}
 path='/ventas/pendientes';did=str(uuid.uuid4());data={'version':0,'solicitud':str(uuid.uuid4()),'contenido':content(),'estado':'en_curso'}
 anon.req(path,expected=401);a.req(path+'?todos=1',expected=403);a.req(path+'/'+did,'PUT',data,403,csrf=False);d=a.req(path+'/'+did,'PUT',data);assert a.req(path+'/'+did,'PUT',data)['version']==d['version'];assert d['contenido']['venta']['notas']=='QA nota conservada';assert d['contenido']['productos'][0]['sku']==PREFIX and d['contenido']['productos'][0]['precio']==str(item['precio'])
 a.req(path+'?antes_de[]=bad',expected=422);bad_value=copy.deepcopy(data);bad_value['contenido']['venta']['detalles'][0]['cantidad']=[];a.req(path+'/'+str(uuid.uuid4()),'PUT',bad_value,422)
 b.req(path+'/'+did,expected=403);admin.req(path+'/'+did);bad=copy.deepcopy(data);bad['contenido']['venta']['turno_id']=turns[1];b.req(path+'/'+did,'PUT',bad,403)
 assert not b.req(path)['tickets'];assert any(x['id']==did for x in admin.req(path+'?todos=1')['tickets']);assert a.req('/caja/turnos/'+turns[0])['resumen']['esperado']=='100.00';assert not a.req('/ventas')['ventas']
 # Editing in two independent sessions uses version conflicts.
 other=Client();other.login('_a');r=a.req(path+'/'+did);payloads=[{'version':r['version'],'solicitud':str(uuid.uuid4()),'contenido':content(n=n)} for n in ('3','4')]
 def update(pair):
  client,payload=pair;req=urllib.request.Request(BASE+path+'/'+did,data=json.dumps(payload).encode(),headers={'Content-Type':'application/json','X-CSRF-Token':client.csrf},method='PUT')
  try:resp=client.http.open(req,timeout=30)
  except urllib.error.HTTPError as e:resp=e
  resp.read();return resp.status
 with ThreadPoolExecutor(2) as pool:results=list(pool.map(update,[(a,payloads[0]),(other,payloads[1])]))
 assert sorted(results)==[200,409],results
 r=a.req(path+'/'+did);d=a.req(path+'/'+did,'PUT',{'version':r['version'],'solicitud':str(uuid.uuid4()),'contenido':content(),'estado':'espera'});assert d['estado']=='espera'
 summary=a.req('/caja/turnos/'+turns[0])['resumen'];assert summary['borradores_pendientes']==1;a.req('/caja/turnos/'+turns[0]+'/cerrar','POST',{'version':summary['version'],'monto_cierre':'100','clave':str(uuid.uuid4())},409)
 cart=content()['venta'];q=a.req('/ventas/cotizar','POST',cart);sale=dict(cart,pagos=[{'metodo_pago_id':cash,'monto':'232.00','recibido':'300','referencia':''}],sello=q['sello'],clave=str(uuid.uuid4()))
 prepared=a.req(path+'/'+did+'/preparar-cobro','POST',{'version':d['version'],'cobro':sale});assert prepared['estado']=='cobrando' and prepared['cobro']['clave']==sale['clave'];assert a.req(path+'/'+did+'/preparar-cobro','POST',{'version':d['version'],'cobro':sale})['version']==prepared['version'];a.req(path+'/'+did,'PUT',dict(data,version=prepared['version'],solicitud=str(uuid.uuid4())),409)
 assert other.req(path+'/'+did)['cobro']==prepared['cobro'];tamper=copy.deepcopy(prepared['cobro']);tamper['pagos'][0]['recibido']='400';a.req('/ventas','POST',tamper,409)
 result=a.req('/ventas','POST',prepared['cobro'],201);assert a.req('/ventas','POST',prepared['cobro'],201)==result;paid=other.req(path+'/'+did);assert paid['estado']=='cobrado' and paid['venta_id']==result['venta_id'];assert not a.req(path)['tickets'];a.req(path+'/'+did,'PUT',dict(data,version=prepared['version'],solicitud=str(uuid.uuid4())),409)
 assert a.req('/caja/turnos/'+turns[0])['resumen']['esperado']=='332.00';assert next(r['cantidad'] for r in admin.req('/inventario?ubicacion_id='+f['loc']) if r['variante_id']==vid)=='8.0000';assert len(a.req('/ventas')['ventas'])==1
 # Cancelling a prepared charge makes an old in-flight payload unusable.
 second=str(uuid.uuid4());d=a.req(path+'/'+second,'PUT',dict(data,solicitud=str(uuid.uuid4())));sale['clave']=str(uuid.uuid4());pre=a.req(path+'/'+second+'/preparar-cobro','POST',{'version':d['version'],'cobro':sale});released=a.req(path+'/'+second+'/liberar-cobro','POST',{'version':pre['version'],'motivo':'QA revisión de pagos'});assert released['estado']=='en_curso';a.req('/ventas','POST',pre['cobro'],409);discarded=admin.req(path+'/'+second+'/descartar','POST',{'version':released['version'],'motivo':'QA descarte administrativo'});assert discarded['estado']=='descartado';assert a.req(path+'/'+second+'/descartar','POST',{'version':released['version'],'motivo':'QA repetido'})['estado']=='descartado'
 ids=[]
 for i in range(32):
  id=str(uuid.uuid4());a.req(path+'/'+id,'PUT',dict(data,solicitud=str(uuid.uuid4()),estado='espera'));ids.append(id)
 first=a.req(path);second_page=a.req(path+'?'+urllib.parse.urlencode({'antes_de':first['siguiente']}));assert len(first['tickets'])==30 and len(second_page['tickets'])==2 and not ({t['id'] for t in first['tickets']}&{t['id'] for t in second_page['tickets']})
 for id in ids:
  r=a.req(path+'/'+id);a.req(path+'/'+id+'/descartar','POST',{'version':r['version'],'motivo':'QA limpieza de borradores'})
 end=a.req('/caja/turnos/'+turns[0])['resumen'];assert end['ventas_pendientes']==0;a.req('/caja/turnos/'+turns[0]+'/cerrar','POST',{'version':end['version'],'monto_cierre':'332','clave':str(uuid.uuid4())});a.req(path+'/'+str(uuid.uuid4()),'PUT',dict(data,solicitud=str(uuid.uuid4())),409)
 # Keep the other isolated user's open turn for browser recovery tests only.
 if '--keep-ui' in sys.argv:STATE.parent.mkdir(exist_ok=True);STATE.write_text(json.dumps({'prefix':PREFIX,'password':PASSWORD,'fixture':f,'product':pid,'variant':vid,'box':boxes[1],'turn':turns[1]}),encoding='utf-8');keep=True
 print('OK: autosave sin movimiento de stock/caja, permisos, propietario, reintentos, conflictos entre sesiones, pausa, bloqueo de corte, preparación durable, cobro único, cancelación segura del cobro, descarte administrativo, paginación y turno cerrado.')
finally:
 if not keep:cleanup(PREFIX);print('Fixtures retirados; jornada y operaciones del negocio conservadas.')
