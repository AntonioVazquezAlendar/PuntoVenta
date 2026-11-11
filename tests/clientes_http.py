from qa_auditoria import limpiar
"""Ventas de mostrador: fixtures QA, cálculos, caja, inventario, tickets y concurrencia."""
import base64
import copy
from concurrent.futures import ThreadPoolExecutor
from decimal import Decimal
import http.cookiejar
import json
from pathlib import Path
import secrets
import subprocess
import urllib.request
import urllib.error
import uuid

ROOT=Path(__file__).resolve().parents[1];BASE='http://127.0.0.1:8080/PuntoVenta/public/api'
PREFIX='qa_sale_'+secrets.token_hex(5);PASSWORD=secrets.token_urlsafe(18)
def sql(code):
    return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php"; $db=(new Database())->connect(); '+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout
class Client:
    def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
    def request(self,path,method='GET',data=None,expected=200,csrf=True):
        headers={};body=None
        if method!='GET':
            headers['Content-Type']='application/json';body=json.dumps(data or {}).encode()
            if csrf:headers['X-CSRF-Token']=self.csrf
        try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=headers,method=method),timeout=30)
        except urllib.error.HTTPError as e:r=e
        result=json.load(r);assert r.status in (expected if isinstance(expected,tuple) else (expected,)),(path,r.status,expected,result)
        assert 'costo_unitario' not in json.dumps(result) or path.startswith('/productos')
        if isinstance(result['data'],dict) and 'csrf' in result['data']:self.csrf=result['data']['csrf']
        return result['data']
    def login(self,suffix):self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})

try:
    p64=base64.b64encode(PASSWORD.encode()).decode()
    sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$roles=array();foreach(array("_reader"=>array("ver_clientes"=>true),"_editor"=>array("ver_clientes"=>true,"editar_clientes"=>true),"_seller"=>array("vender"=>true,"operar_caja"=>true,"ver_clientes"=>true)) as $s=>$p){$q->execute(array("'+PREFIX+'".$s,json_encode($p)));$roles[$s]=$db->lastInsertId();}$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");foreach(array("_admin"=>1,"_reader"=>$roles["_reader"],"_editor"=>$roles["_editor"],"_a"=>$roles["_seller"],"_b"=>$roles["_seller"]) as $s=>$r){$q->execute(array("QA clientes","'+PREFIX+'".$s,$r,$h));}$db->commit();')
    admin=Client();admin.login('_admin');reader=Client();reader.login('_reader');editor=Client();editor.login('_editor');a=Client();a.login('_a');b=Client();b.login('_b');anon=Client()
    anon.request('/clientes',expected=401);anon.request('/clientes/buscar?buscar=QA',expected=401)
    def payload(suffix):return {'nombre':PREFIX+suffix,'telefono':'555 123 4567','correo':'qa@example.test','direccion':'Dirección de prueba','notas':'Solo QA','activo':1}
    reader.request('/clientes','POST',payload('_blocked'),403)
    editor.request('/clientes','POST',payload('_csrf'),403,csrf=False)
    invalid=payload('_invalid');invalid['correo']='no es correo';editor.request('/clientes','POST',invalid,422)
    invalid=payload('_long');invalid['nombre']='x'*201;editor.request('/clientes','POST',invalid,422)
    c1=editor.request('/clientes','POST',dict(payload('_cliente'),saldo='999',limite_credito='999'),201)['id']
    c2=editor.request('/clientes','POST',dict(payload('_inactivo'),activo=0),201)['id']
    assert reader.request('/clientes/'+c1)['nombre']==PREFIX+'_cliente'
    assert json.loads(sql('$q=$db->prepare("SELECT saldo,limite_credito FROM clientes WHERE id=?");$q->execute(array("'+c1+'"));echo json_encode($q->fetch());'))=={'saldo':'0.00','limite_credito':'0.00'}
    assert a.request('/clientes/buscar')['clientes']==[]
    lookup=a.request('/clientes/buscar?buscar='+PREFIX+'_cliente')['clientes'];assert len(lookup)==1 and set(lookup[0])=={'id','nombre','telefono'}
    assert a.request('/clientes/buscar?buscar='+PREFIX+'_inactivo')['clientes']==[]
    assert [c['id'] for c in reader.request('/clientes?buscar='+PREFIX+'&estado=0')['clientes']]==[c2]
    admin.request('/clientes/'+c1,'DELETE',{},405)
    perms={p['clave']:False for p in admin.request('/permisos')};perms['editar_clientes']=True;admin.request('/roles','POST',{'nombre':PREFIX+'_bad','permisos':perms},422)
    original=editor.request('/clientes/'+c1);renamed=dict(original,nombre=PREFIX+'_nombre_nuevo');editor.request('/clientes/'+c1,'PUT',renamed);editor.request('/clientes/'+c1,'PUT',original,409)
    # Paginación real del directorio, conservando todas las fichas.
    sql('$q=$db->prepare("INSERT INTO clientes (nombre) VALUES (?)");for($i=0;$i<31;$i++){$q->execute(array("'+PREFIX+'_bulk".$i));}')
    page=reader.request('/clientes?estado=&buscar='+PREFIX);assert len(page['clientes'])==30 and page['siguiente'];second=reader.request('/clientes?estado=&buscar='+PREFIX+'&antes_de='+page['siguiente']);assert len(second['clientes'])==3 and not second['siguiente'];assert len(set(c['id'] for c in page['clientes']+second['clientes']))==33
    fixture=json.loads(sql('$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,1)")->execute(array("'+PREFIX+'"));$l=$db->lastInsertId();$db->prepare("INSERT INTO metodos_pago (nombre,tipo) VALUES (?,\'efectivo\')")->execute(array("'+PREFIX+'"));echo json_encode(array("l"=>$l,"metodo"=>$db->lastInsertId()));'))
    turns=[]
    for i,client in enumerate([a,b]):
        box=admin.request('/caja/cajas','POST',{'nombre':PREFIX+'_box'+str(i),'ubicacion_id':fixture['l'],'activo':1},201)['id'];turns.append(client.request('/caja/turnos','POST',{'caja_id':box,'monto_apertura':'0','clave':str(uuid.uuid4())},201)['turno_id'])
    cat=admin.request('/catalogos');p={'nombre':PREFIX+'_servicio','categoria_id':None,'unidad_base_id':next(u['id'] for u in cat['unidades'] if u['codigo']=='srv'),'impuesto_id':next(i['id'] for i in cat['impuestos'] if i['activo']),'tipo_producto':'servicio','precio_incluye_impuesto':1,'controla_inventario':0,'activo':1,'variantes':[{'sku':PREFIX+'_sku','nombre':'','costo':'0','precio':'10','stock_minimo':'0','activo':1}]};pid=admin.request('/productos','POST',p,201)['id'];vid=next(p for p in admin.request('/productos?todos=1') if p['id']==pid)['variantes'][0]['id']
    cart={'turno_id':turns[0],'cliente_id':c1,'cliente_nombre':'Nombre manipulado','detalles':[{'variante_id':vid,'cantidad':'1','precio_unitario':'10','descuento':'0'}]}
    quote=a.request('/ventas/cotizar','POST',cart);assert quote['cliente']['nombre']==PREFIX+'_nombre_nuevo'
    def pay(client,data,q=None,key=None):return dict(data,pagos=[{'metodo_pago_id':fixture['metodo'],'monto':'10','recibido':'10'}],sello=(q or client.request('/ventas/cotizar','POST',data))['sello'],clave=key or str(uuid.uuid4()))
    current=editor.request('/clientes/'+c1);editor.request('/clientes/'+c1,'PUT',dict(current,nombre=PREFIX+'_final'));a.request('/ventas','POST',pay(a,cart,quote),409)
    fresh=a.request('/ventas/cotizar','POST',cart);current=editor.request('/clientes/'+c1);editor.request('/clientes/'+c1,'PUT',dict(current,activo=0));a.request('/ventas','POST',pay(a,cart,fresh),409)
    current=editor.request('/clientes/'+c1);editor.request('/clientes/'+c1,'PUT',dict(current,activo=1));request=pay(a,cart);sale=a.request('/ventas','POST',request,201);ticket=a.request('/ventas/'+sale['venta_id']);assert ticket['cliente_id']==c1 and ticket['ticket']['cliente_nombre']==PREFIX+'_final'
    changed=dict(request,cliente_id=c2);a.request('/ventas','POST',changed,409)
    b.request('/ventas','POST',pay(b,dict(cart,turno_id=turns[1])),201)
    a.request('/ventas','POST',pay(a,dict(cart,cliente_id=None,cliente_nombre=PREFIX+'_final')),201)
    assert admin.request('/clientes/'+c1+'/historial')['resumen']=={'compras':'2','total':'20.00'}
    assert a.request('/clientes/'+c1+'/historial')['resumen']=={'compras':'1','total':'10.00'}
    assert b.request('/clientes/'+c1+'/historial')['resumen']=={'compras':'1','total':'10.00'}
    assert reader.request('/clientes/'+c1+'/historial')['ventas']==[]
    current=editor.request('/clientes/'+c1);editor.request('/clientes/'+c1,'PUT',dict(current,nombre=PREFIX+'_cambiado',activo=0));assert a.request('/ventas','POST',request,201)==sale
    assert a.request('/ventas/'+sale['venta_id'])['ticket']['cliente_nombre']==PREFIX+'_final'
    assert admin.request('/clientes/'+c1+'/historial')['resumen']['compras']=='2'
    print('OK: CRUD/contactos, validaciones y CSRF, permisos, paginación, selección mínima, cliente activo, nombre autoritativo, snapshot, reintentos e historial según acceso.')
finally:
    sql('$db->beginTransaction();$db->prepare("DELETE s FROM solicitudes_venta s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE v FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM clientes WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM ubicaciones WHERE nombre=?")->execute(array("'+PREFIX+'"));$db->prepare("DELETE FROM metodos_pago WHERE nombre=?")->execute(array("'+PREFIX+'"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Clientes, compras y cuentas temporales retirados; datos del negocio conservados.')
    limpiar(PREFIX)
