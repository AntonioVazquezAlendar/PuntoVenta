from qa_auditoria import limpiar
"""Compras: pruebas HTTP aisladas de recepción, costos, estados, permisos y concurrencia."""
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

ROOT=Path(__file__).resolve().parents[1]
BASE='http://127.0.0.1:8080/PuntoVenta/public/api'
PREFIX='qa_buy_'+secrets.token_hex(5)
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
    p64=base64.b64encode(PASSWORD.encode()).decode()
    fixture=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_role","{}"));$r=$db->lastInsertId();'
        '$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");'
        '$q->execute(array("QA compras admin","'+PREFIX+'_admin",1,$h));$q->execute(array("QA compras operador","'+PREFIX+'_reader",$r,$h));'
        '$q=$db->prepare("INSERT INTO ubicaciones (nombre) VALUES (?)");$q->execute(array("'+PREFIX+'_loc1"));$l1=$db->lastInsertId();$q->execute(array("'+PREFIX+'_loc2"));$l2=$db->lastInsertId();'
        '$db->prepare("INSERT INTO impuestos (nombre,tasa) VALUES (?,0.1600)")->execute(array("'+PREFIX+'_iva"));$t=$db->lastInsertId();'
        '$db->commit();echo json_encode(array("rol"=>$r,"l1"=>$l1,"l2"=>$l2,"tax"=>$t));'))
    admin=Client();admin.login('_admin');reader=Client();reader.login('_reader');anon=Client()
    anon.request('/compras',expected=401);reader.request('/compras',expected=403);reader.request('/compras/catalogos',expected=403)
    supplier={'nombre':PREFIX+'_supplier','contacto':'QA contacto','telefono':'5551234567','correo':'prueba@example.test','direccion':'QA','notas':'QA','activo':1}
    reader.request('/proveedores','POST',supplier,403)
    admin.request('/proveedores','POST',dict(supplier,correo='correo-invalido'),422)
    provider=admin.request('/proveedores','POST',supplier,201)['id']
    units={u['codigo']:u['id'] for u in admin.request('/catalogos')['unidades']}
    products={}
    for suffix,unit in [('_piece','pza'),('_bulk','kg'),('_overflow','kg')]:
        data={'nombre':PREFIX+suffix,'descripcion':'QA','categoria_id':None,'unidad_base_id':units[unit],'impuesto_id':fixture['tax'],'tipo_producto':'estandar' if unit=='pza' else 'granel','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,
              'variantes':[{'sku':PREFIX+suffix,'nombre':'','costo':'100','precio':'199','stock_minimo':'5','activo':1}]}
        pid=admin.request('/productos','POST',data,201)['id'];products[suffix]=next(p for p in admin.request('/productos?todos=1') if str(p['id'])==str(pid))
    piece=products['_piece']['variantes'][0]['id'];bulk=products['_bulk']['variantes'][0]['id'];overflow=products['_overflow']['variantes'][0]['id']
    def adjust(variant,qty,mode='inicial',loc=None):
        return admin.request('/inventario/ajustes','POST',{'ubicacion_id':loc or fixture['l1'],'variante_id':variant,'modo':mode,'cantidad':qty,'motivo':'QA compras','clave':str(uuid.uuid4())},201)
    def stock(variant=piece,loc=None):return next(r for r in admin.request('/inventario?ubicacion_id='+str(loc or fixture['l1'])) if str(r['variante_id'])==str(variant))['cantidad']
    def cost(variant=piece):return next(v for p in admin.request('/productos?todos=1') for v in p['variantes'] if str(v['id'])==str(variant))
    def payload(qty='10',price='120',variant=piece,loc=None):return {'proveedor_id':provider,'ubicacion_id':loc or fixture['l1'],'estatus':'borrador','factura_proveedor':'QA-FAC','notas':PREFIX,'clave':str(uuid.uuid4()),'detalles':[{'variante_id':variant,'cantidad':qty,'costo_unitario':price,'impuesto_id':fixture['tax']}]}
    def create(data):return admin.request('/compras','POST',data,201)
    def receive(p,client=admin,expected=200):return client.request('/compras/'+str(p['id'])+'/recibir','POST',{'version':p['version']},expected)
    adjust(piece,'10')
    data=payload();reader.request('/compras','POST',data,403);admin.request('/compras','POST',data,403,csrf=False)
    bad=copy.deepcopy(data);bad['detalles'].append(copy.deepcopy(bad['detalles'][0]));admin.request('/compras','POST',bad,422)
    admin.request('/compras','POST',payload('0.5'),422)
    admin.request('/compras','POST',payload('0'),422)
    admin.request('/compras','POST',payload('9999999999','9999999999'),422)
    data['total']='0.01';p=create(data)
    assert p['subtotal']=='1200.00' and p['impuesto_total']=='192.00' and p['total']=='1392.00'
    assert create(data)['id']==p['id']
    admin.request('/compras','POST',dict(data,notas='datos distintos'),409)
    assert stock()=='10.0000' and cost()['costo']=='100.0000'
    ordered=dict(data,estatus='pedida',version=p['version']);p=admin.request('/compras/'+str(p['id']),'PUT',ordered)
    admin.request('/compras/'+str(p['id']),'PUT',ordered,409)
    assert stock()=='10.0000'
    # La tasa guardada permanece aunque después cambie el catálogo de impuestos.
    sql('$db->prepare("UPDATE impuestos SET tasa=0.0800 WHERE id=?")->execute(array("'+fixture['tax']+'"));')
    received=receive(p);assert received['estatus']=='recibida' and received['total']=='1392.00' and received['detalles'][0]['tasa_impuesto']=='0.1600'
    assert stock()=='20.0000' and cost()['costo']=='110.0000' and cost()['precio']=='199.0000'
    receive(p);assert stock()=='20.0000'
    admin.request('/compras/'+str(p['id']),'PUT',dict(ordered,version=received['version']),409)
    admin.request('/compras/'+str(p['id'])+'/cancelar','POST',{'version':received['version']},409)
    sql('$db->prepare("UPDATE impuestos SET tasa=0.1600 WHERE id=?")->execute(array("'+fixture['tax']+'"));')
    # Recepciones distintas en paralelo y reintento concurrente de la misma compra.
    clients=[Client(),Client()]
    for c in clients:c.login('_admin')
    a=create(payload('5','140'));b=create(payload('5','170'))
    with ThreadPoolExecutor(max_workers=2) as pool:list(pool.map(lambda item:receive(item[0],item[1]),[(a,clients[0]),(b,clients[1])]))
    assert stock()=='30.0000' and cost()['costo']=='125.0000'
    duplicate=create(payload('2','125'))
    with ThreadPoolExecutor(max_workers=2) as pool:list(pool.map(lambda c:receive(duplicate,c),clients))
    assert stock()=='32.0000'
    # El costo usa el saldo global, no solo la ubicación destino.
    adjust(piece,'8',loc=fixture['l2']);global_purchase=create(payload('10','150'));receive(global_purchase)
    assert stock()=='42.0000' and stock(loc=fixture['l2'])=='8.0000' and cost()['costo']=='130.0000'
    # Stock global negativo: el costo nuevo es el de compra.
    adjust(bulk,'5','salida');negative=create(payload('2','50',bulk));receive(negative)
    assert stock(bulk)=='-3.0000' and cost(bulk)['costo']=='50.0000'
    fractional=create(payload('1.2500','1.2345',bulk));assert fractional['subtotal']=='1.54' and fractional['impuesto_total']=='0.25' and fractional['total']=='1.79'
    cancelled=admin.request('/compras/'+str(fractional['id'])+'/cancelar','POST',{'version':fractional['version']});assert cancelled['estatus']=='cancelada'
    receive(fractional,expected=409)
    # Fallar el segundo renglón revierte también el primero, su costo y sus movimientos.
    adjust(overflow,'9999999999.9999');atomic=payload('1','20');atomic['detalles'].append({'variante_id':overflow,'cantidad':'0.0001','costo_unitario':'1','impuesto_id':fixture['tax']})
    atomic=create(atomic);previous_stock=stock();previous_cost=cost()['costo'];receive(atomic,expected=422)
    assert stock()==previous_stock and cost()['costo']==previous_cost and admin.request('/compras/'+str(atomic['id']))['estatus']=='borrador'
    # Recepción de un proveedor desactivado se rechaza sin modificar saldo.
    pending=create(payload('1','130'));admin.request('/proveedores/'+str(provider),'PUT',dict(supplier,activo=0))
    receive(pending,expected=422);assert stock()==previous_stock
    admin.request('/proveedores/'+str(provider),'PUT',supplier)
    permissions={i['clave']:False for i in admin.request('/permisos')};permissions['hacer_compras']=True
    admin.request('/roles/'+fixture['rol']+'/permisos','PUT',{'permisos':permissions});reader.request('/compras/catalogos');receive(pending,reader)
    permissions['hacer_compras']=False;admin.request('/roles/'+fixture['rol']+'/permisos','PUT',{'permisos':permissions});reader.request('/compras',expected=403)
    # Reconciliar saldos contra todo el historial QA en cada ubicación.
    for loc in [fixture['l1'],fixture['l2']]:
        for variant in [piece,bulk,overflow]:
            history=admin.request('/inventario/movimientos?ubicacion_id='+loc+'&variante_id='+str(variant))['movimientos']
            assert sum((Decimal(m['cantidad']) for m in history),Decimal(0))==Decimal(stock(variant,loc))
    print('OK: proveedores, importes e IVA, borrador/pedida sin inventario, recepción única, costo promedio global, negativos, concurrencia, estados, permisos, edición obsoleta y rollback completo.')
finally:
    sql('$db->beginTransaction();'
        '$q=$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE s FROM solicitudes_compra s JOIN compras c ON c.id=s.compra_id JOIN proveedores p ON p.id=c.proveedor_id WHERE p.nombre=?");$q->execute(array("'+PREFIX+'_supplier"));'
        '$q=$db->prepare("DELETE s FROM solicitudes_compra s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$q=$db->prepare("DELETE c FROM compras c JOIN proveedores p ON p.id=c.proveedor_id WHERE p.nombre=?");$q->execute(array("'+PREFIX+'_supplier"));'
        '$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM proveedores WHERE nombre=?")->execute(array("'+PREFIX+'_supplier"));'
        '$q=$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+PREFIX+'_role"));'
        '$db->prepare("DELETE FROM ubicaciones WHERE nombre IN (?,?)")->execute(array("'+PREFIX+'_loc1","'+PREFIX+'_loc2"));'
        '$db->prepare("DELETE FROM impuestos WHERE nombre=?")->execute(array("'+PREFIX+'_iva"));$db->commit();')
    print('Datos QA retirados; compras e inventario del negocio conservados.')
    limpiar(PREFIX)
