from qa_auditoria import limpiar
"""Ubicaciones, traspasos y mermas: fixtures QA sin tocar existencias del negocio."""
import base64
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

ROOT=Path(__file__).resolve().parents[1]
BASE='http://127.0.0.1:8080/PuntoVenta/public/api'
PREFIX='qa_wh_'+secrets.token_hex(5)
PASSWORD=secrets.token_urlsafe(18)

def sql(code):
    return subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php"; $db=(new Database())->connect(); '+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True,check=True).stdout

class Client:
    def __init__(self):
        self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
    def request(self,path,method='GET',data=None,expected=200,csrf=True):
        headers={};body=None
        if method!='GET':
            headers['Content-Type']='application/json';body=json.dumps(data or {}).encode()
            if csrf:headers['X-CSRF-Token']=self.csrf
        try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=headers,method=method),timeout=30)
        except urllib.error.HTTPError as e:r=e
        result=json.load(r);assert r.status in (expected if isinstance(expected,tuple) else (expected,)),(path,r.status,expected,result)
        if isinstance(result['data'],dict) and 'csrf' in result['data']:self.csrf=result['data']['csrf']
        return result['data']
    def login(self,suffix):
        self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})

try:
    p64=base64.b64encode(PASSWORD.encode()).decode()
    role=sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("ver_inventario"=>true))));$r=$db->lastInsertId();'
        '$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");'
        '$q->execute(array("QA almacén admin","'+PREFIX+'_admin",1,$h));$q->execute(array("QA almacén lector","'+PREFIX+'_reader",$r,$h));$db->commit();echo $r;').strip()
    admin=Client();admin.login('_admin');reader=Client();reader.login('_reader');anon=Client()
    anon.request('/almacen/operaciones',expected=401)
    locs={}
    def loc_payload(name,sale=1,active=1):return {'nombre':PREFIX+name,'descripcion':'QA almacén','disponible_venta':sale,'activo':active}
    for suffix,sale in [('_bodega',1),('_tienda',1),('_danados',0),('_extra',1)]:
        locs[suffix]=admin.request('/almacen/ubicaciones','POST',loc_payload(suffix,sale),201)['id']
    admin.request('/almacen/ubicaciones','POST',loc_payload('_bodega'),409)
    admin.request('/almacen/ubicaciones','POST',loc_payload('_bodega'.upper()),409)
    reader.request('/almacen/ubicaciones','POST',loc_payload('_reader'),403)
    admin.request('/almacen/ubicaciones','POST',loc_payload('_csrf'),403,csrf=False)
    lookups=admin.request('/catalogos');units={u['codigo']:u['id'] for u in lookups['unidades']};tax=lookups['impuestos'][0]['id']
    products={}
    for suffix,unit in [('_piece','pza'),('_bulk','kg'),('_concurrent','pza')]:
        p={'nombre':PREFIX+suffix,'descripcion':'QA','categoria_id':None,'unidad_base_id':units[unit],'impuesto_id':tax,'tipo_producto':'estandar' if unit=='pza' else 'granel','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,
           'variantes':[{'sku':PREFIX+suffix,'nombre':'QA','costo':'100','precio':'199','stock_minimo':'5','activo':1}]}
        pid=admin.request('/productos','POST',p,201)['id'];products[suffix]=next(p for p in admin.request('/productos?todos=1') if str(p['id'])==str(pid))
    piece=products['_piece']['variantes'][0]['id'];bulk=products['_bulk']['variantes'][0]['id'];concurrent=products['_concurrent']['variantes'][0]['id']
    def adjust(v,n,l):return admin.request('/inventario/ajustes','POST',{'ubicacion_id':l,'variante_id':v,'modo':'inicial','cantidad':n,'motivo':'QA inicial','clave':str(uuid.uuid4())},201)
    def stock(v=piece,l=None):return Decimal(next(r for r in admin.request('/inventario?ubicacion_id='+str(l or locs['_bodega'])) if str(r['variante_id'])==str(v))['cantidad'])
    def sku(v=piece):return next(vv for p in admin.request('/productos?todos=1') for vv in p['variantes'] if str(vv['id'])==str(v))
    def payload(q='1',origin=None,dest=None,v=piece):return {'origen_id':origin or locs['_bodega'],'destino_id':dest or locs['_tienda'],'variante_id':v,'cantidad':q,'motivo':'QA movimiento','clave':str(uuid.uuid4())}
    def move(data,client=admin,expected=201):return client.request('/almacen/traspasos','POST',data,expected)
    def waste(data,client=admin,expected=201):return client.request('/almacen/mermas','POST',data,expected)
    adjust(piece,'20',locs['_bodega']);adjust(piece,'5',locs['_tienda'])
    assert Decimal(sku()['existencia_disponible'])==25
    reader.request('/almacen/traspasos','POST',payload(),403);reader.request('/almacen/mermas','POST',payload(),403)
    admin.request('/almacen/traspasos','POST',payload(),403,csrf=False)
    move(payload('0'),expected=422);move(payload('0.5'),expected=422);move(payload('1',dest=locs['_bodega']),expected=422)
    move(dict(payload(),motivo=''),expected=422);move(payload('21'),expected=409)
    transfer=payload('6');tr=move(transfer);assert tr['folio'].startswith('TR-') and len(tr['movimientos'])==2
    assert stock()==14 and stock(l=locs['_tienda'])==11 and Decimal(sku()['existencia_disponible'])==25
    assert move(transfer)==tr
    move(dict(transfer,cantidad='7'),expected=409)
    # Resguardo conserva stock físico y excluye la mercancía de disponibilidad.
    quarantine=move(payload('2',origin=locs['_tienda'],dest=locs['_danados']))
    assert stock(l=locs['_tienda'])==9 and stock(l=locs['_danados'])==2 and Decimal(sku()['existencia_disponible'])==23
    loss=waste(payload('1',origin=locs['_danados']));assert loss['folio'].startswith('ME-') and len(loss['movimientos'])==1
    assert stock(l=locs['_danados'])==1 and sum(stock(l=l) for l in locs.values())==24 and Decimal(sku()['existencia_disponible'])==23
    assert sku()['costo']=='100.0000' and sku()['precio']=='199.0000'
    admin.request('/almacen/ubicaciones/'+locs['_danados'],'PUT',loc_payload('_danados',0,0),409)
    # Cambio de uso actualiza disponibilidad sin mover mercancía.
    admin.request('/almacen/ubicaciones/'+locs['_tienda'],'PUT',loc_payload('_tienda',0))
    assert Decimal(sku()['existencia_disponible'])==14
    admin.request('/almacen/ubicaciones/'+locs['_tienda'],'PUT',loc_payload('_tienda',1))
    admin.request('/almacen/ubicaciones/'+locs['_extra'],'PUT',loc_payload('_extra',1,0))
    move(payload(dest=locs['_extra']),expected=422)
    admin.request('/almacen/ubicaciones/'+locs['_extra'],'PUT',loc_payload('_extra',1,1))
    # Decimales exactos y rechazo de overflow en destino sin modificar origen.
    adjust(bulk,'0.1250',locs['_bodega']);move(payload('0.0500',v=bulk))
    assert stock(bulk)==Decimal('.0750') and stock(bulk,locs['_tienda'])==Decimal('.0500')
    adjust(bulk,'9999999999.9999',locs['_extra']);move(payload('0.0001',dest=locs['_extra'],v=bulk),expected=422)
    assert stock(bulk)==Decimal('.0750')
    # Reintentos y salidas simultáneas no duplican ni dejan saldo negativo.
    adjust(concurrent,'20',locs['_bodega']);clients=[Client(),Client()]
    for c in clients:c.login('_admin')
    same=payload('2',v=concurrent)
    with ThreadPoolExecutor(max_workers=2) as pool:res=list(pool.map(lambda c:move(same,c),clients))
    assert res[0]==res[1] and stock(concurrent)==18
    with ThreadPoolExecutor(max_workers=2) as pool:
        list(pool.map(lambda pair:move(payload('12',dest=pair[1],v=concurrent),pair[0],(201,409)),[(clients[0],locs['_tienda']),(clients[1],locs['_danados'])]))
    assert stock(concurrent)==6
    with ThreadPoolExecutor(max_workers=2) as pool:list(pool.map(lambda c:waste(payload('4',v=concurrent),c,(201,409)),clients))
    assert stock(concurrent)==2 and sum(stock(concurrent,l) for l in locs.values())==16
    # Las variantes inactivas con saldo aún pueden reubicarse o darse de baja.
    sql('$db->prepare("UPDATE variantes_producto SET activo=0 WHERE id=?")->execute(array("'+str(concurrent)+'"));')
    move(payload('1',v=concurrent));waste(payload('1',v=concurrent));assert stock(concurrent)==0
    assert Decimal(sku(concurrent)['existencia_disponible'])==0
    # Permisos separados: otorgar traspasos no otorga mermas ni ubicaciones.
    permissions={p['clave']:False for p in admin.request('/permisos')};permissions['ver_inventario']=True;permissions['realizar_traspasos']=True
    admin.request('/roles/'+role+'/permisos','PUT',{'permisos':permissions});move(payload('1'),reader)
    waste(payload('1'),reader,403);reader.request('/almacen/ubicaciones','POST',loc_payload('_denied'),403)
    permissions['realizar_traspasos']=False;permissions['registrar_mermas']=True
    admin.request('/roles/'+role+'/permisos','PUT',{'permisos':permissions});waste(payload('1',origin=locs['_danados']),reader)
    move(payload('1'),reader,403)
    # Los documentos se ven en ambos extremos y el historial cuadra con el saldo.
    docs=reader.request('/almacen/operaciones?ubicacion_id='+locs['_tienda'])['operaciones'];assert any(d['folio']==tr['folio'] for d in docs)
    for variant in [piece,bulk,concurrent]:
        for loc in locs.values():
            history=admin.request('/inventario/movimientos?ubicacion_id='+loc+'&variante_id='+str(variant))['movimientos']
            assert sum((Decimal(m['cantidad']) for m in history),Decimal(0))==stock(variant,loc)
            for m in history:
                if m['tipo_referencia'] in ['traspaso','merma']:assert m['folio_documento']
    admin.request('/almacen/operaciones/'+str(tr['id']),'DELETE',{},404)
    print('OK: ubicaciones y resguardo; traspasos dobles y mermas simples; disponibilidad de venta; stock suficiente; decimales/overflow; idempotencia; concurrencia; permisos separados e historial reconciliado.')
finally:
    sql('$db->beginTransaction();'
        '$q=$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE o FROM operaciones_almacen o JOIN usuarios u ON u.id=o.usuario_id WHERE u.nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+PREFIX+'_role"));'
        '$db->prepare("DELETE FROM ubicaciones WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Datos QA retirados; ubicaciones y existencias del negocio conservadas.')
    limpiar(PREFIX)
