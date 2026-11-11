from qa_auditoria import limpiar
"""Verifica inventario usando únicamente productos, ubicaciones, cuentas y movimientos QA."""
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
PREFIX='qa_inv_'+secrets.token_hex(5)
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
        result=json.load(r);assert r.status==expected,(path,r.status,expected,result)
        if isinstance(result['data'],dict) and 'csrf' in result['data']:self.csrf=result['data']['csrf']
        return result['data']
    def login(self,suffix):
        self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})

try:
    password64=base64.b64encode(PASSWORD.encode()).decode()
    fixture=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("ver_inventario"=>true))));$r=$db->lastInsertId();'
        '$h=password_hash(base64_decode("'+password64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");'
        '$q->execute(array("QA inventario admin","'+PREFIX+'_admin",1,$h));$q->execute(array("QA inventario lector","'+PREFIX+'_reader",$r,$h));'
        '$q=$db->prepare("INSERT INTO ubicaciones (nombre) VALUES (?)");$q->execute(array("'+PREFIX+'_loc1"));$l1=$db->lastInsertId();$q->execute(array("'+PREFIX+'_loc2"));$l2=$db->lastInsertId();'
        '$db->commit();echo json_encode(array("rol"=>$r,"l1"=>$l1,"l2"=>$l2));'))
    admin=Client();admin.login('_admin');reader=Client();reader.login('_reader');anon=Client()
    anon.request('/inventario/ubicaciones',expected=401)
    catalogs=admin.request('/catalogos');units={u['codigo']:u['id'] for u in catalogs['unidades']};tax=catalogs['impuestos'][0]['id']
    products={}
    for suffix,unit,kind in [('_piece','pza','estandar'),('_bulk','kg','granel'),('_service','srv','servicio')]:
        data={'nombre':PREFIX+suffix,'descripcion':'QA','categoria_id':None,'unidad_base_id':units[unit],'impuesto_id':tax,'tipo_producto':kind,'precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,
              'variantes':[{'sku':PREFIX+suffix,'nombre':'','costo':'10.1234','precio':'20','stock_minimo':'5','activo':1}]}
        pid=admin.request('/productos','POST',data,201)['id']
        products[suffix]=next(p for p in admin.request('/productos?todos=1') if str(p['id'])==str(pid))
    piece=products['_piece']['variantes'][0]['id'];bulk=products['_bulk']['variantes'][0]['id'];service=products['_service']['variantes'][0]['id']
    def stock(variant=piece,loc=None):
        return next(r for r in reader.request('/inventario?ubicacion_id='+str(loc or fixture['l1'])) if str(r['variante_id'])==str(variant))
    def data(mode,quantity,variant=piece,loc=None,**extra):
        return dict({'ubicacion_id':loc or fixture['l1'],'variante_id':variant,'modo':mode,'cantidad':quantity,'motivo':'QA '+mode,'clave':str(uuid.uuid4())},**extra)
    def adjust(payload,expected=201,client=admin):return client.request('/inventario/ajustes','POST',payload,expected)
    assert Decimal(stock()['cantidad'])==0
    assert not any(str(r['variante_id'])==str(service) for r in reader.request('/inventario?ubicacion_id='+fixture['l1']))
    reader.request('/inventario/ajustes','POST',data('entrada','1'),403)
    admin.request('/inventario/ajustes','POST',data('entrada','1'),403,csrf=False)
    adjust(data('entrada','0.5'),422)
    adjust(data('entrada','1',motivo=''),422)
    adjust(data('entrada','0'),422)
    adjust(data('entrada','1.12345',variant=bulk),422)
    adjust(data('entrada','1',variant=service),422)
    sql('$db->prepare("UPDATE ubicaciones SET activo=0 WHERE id=?")->execute(array("'+fixture['l2']+'"));')
    adjust(data('entrada','1',loc=fixture['l2']),422)
    sql('$db->prepare("UPDATE ubicaciones SET activo=1 WHERE id=?")->execute(array("'+fixture['l2']+'"));'
        '$db->prepare("UPDATE variantes_producto SET activo=0 WHERE id=?")->execute(array("'+str(piece)+'"));')
    adjust(data('entrada','1'),422)
    sql('$db->prepare("UPDATE variantes_producto SET activo=1 WHERE id=?")->execute(array("'+str(piece)+'"));')
    initial=data('inicial','10');first=adjust(initial);assert first==adjust(initial)
    assert first['cantidad_nueva']=='10.0000'
    adjust(dict(initial,cantidad='11'),409)
    adjust(data('inicial','2'),409)
    negative=adjust(data('salida','15'));assert negative['cantidad_nueva']=='-5.0000'
    adjust(data('conteo','0',saldo_esperado='10'),409)
    count=adjust(data('conteo','0',saldo_esperado='-5.0000'));assert count['cambio']=='5.0000'
    adjust(data('conteo','0',saldo_esperado='0'),422)
    exact=adjust(data('inicial','0.0001',variant=bulk));assert exact['cantidad_nueva']=='0.0001'
    adjust(data('inicial','7',loc=fixture['l2']));assert stock(loc=fixture['l2'])['cantidad']=='7.0000'
    adjust(data('inicial','9999999999.9999',variant=bulk,loc=fixture['l2']))
    adjust(data('entrada','0.0001',variant=bulk,loc=fixture['l2']),422)
    assert stock(bulk,fixture['l2'])['cantidad']=='9999999999.9999'
    # Sesiones diferentes: las peticiones concurren realmente en Apache.
    clients=[Client() for _ in range(6)]
    for c in clients:c.login('_admin')
    with ThreadPoolExecutor(max_workers=6) as pool:
        list(pool.map(lambda c:adjust(data('entrada','1'),client=c),clients))
    assert stock()['cantidad']=='6.0000'
    repeated=data('entrada','1')
    with ThreadPoolExecutor(max_workers=2) as pool:
        results=list(pool.map(lambda c:adjust(repeated,client=c),clients[:2]))
    assert results[0]==results[1] and stock()['cantidad']=='7.0000'
    # Paginación y concordancia exacta de saldo e historial.
    for _ in range(46):adjust(data('entrada','1'))
    page=reader.request('/inventario/movimientos?ubicacion_id='+fixture['l1']+'&variante_id='+str(piece))
    assert len(page['movimientos'])==50 and page['siguiente']
    second=reader.request('/inventario/movimientos?ubicacion_id='+fixture['l1']+'&variante_id='+str(piece)+'&antes_de='+page['siguiente'])
    assert second['movimientos'] and not (set(m['id'] for m in page['movimientos'])&set(m['id'] for m in second['movimientos']))
    assert sum(Decimal(m['cantidad']) for m in page['movimientos']+second['movimientos'])==Decimal(stock()['cantidad'])
    assert all(m['usuario_nombre']=='QA inventario admin' for m in page['movimientos'])
    assert not any('costo_unitario' in m for m in page['movimientos'])
    # Concesión y revocación inmediatas, sin volver a iniciar sesión.
    permissions={item['clave']:False for item in admin.request('/permisos')};permissions['ver_inventario']=True;permissions['ajustar_inventario']=True
    admin.request('/roles/'+fixture['rol']+'/permisos','PUT',{'permisos':permissions})
    adjust(data('entrada','1'),client=reader)
    permissions['ajustar_inventario']=False;admin.request('/roles/'+fixture['rol']+'/permisos','PUT',{'permisos':permissions})
    adjust(data('entrada','1'),403,reader)
    permissions['ver_inventario']=False;admin.request('/roles/'+fixture['rol']+'/permisos','PUT',{'permisos':permissions})
    reader.request('/inventario/ubicaciones',expected=403)
    print('OK: saldos e historial exactos, inicial único, enteros/decimales, conteos con saldo esperado, negativos, overflow, ubicaciones, concurrencia, reintentos sin duplicar, paginación y permisos en vivo.')
finally:
    sql('$db->beginTransaction();'
        '$q=$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE FROM productos WHERE nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$q=$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+PREFIX+'_role"));'
        '$db->prepare("DELETE FROM ubicaciones WHERE nombre IN (?,?)")->execute(array("'+PREFIX+'_loc1","'+PREFIX+'_loc2"));$db->commit();')
    print('Datos QA retirados; inventario del negocio conservado.')
    limpiar(PREFIX)
