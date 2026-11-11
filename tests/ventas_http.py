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
    fixture=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_operator",json_encode(array("vender"=>true,"operar_caja"=>true))));$op=$db->lastInsertId();$q->execute(array("'+PREFIX+'_audit",json_encode(array("ver_ventas"=>true))));$audit=$db->lastInsertId();'
        '$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");$q->execute(array("QA ventas admin","'+PREFIX+'_admin",1,$h));$q->execute(array("QA ventas A","'+PREFIX+'_a",$op,$h));$q->execute(array("QA ventas B","'+PREFIX+'_b",$op,$h));$q->execute(array("QA auditor","'+PREFIX+'_audit",$audit,$h));'
        '$q=$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,?)");$q->execute(array("'+PREFIX+'_venta",1));$l=$db->lastInsertId();$q->execute(array("'+PREFIX+'_damaged",0));$d=$db->lastInsertId();'
        '$db->prepare("INSERT INTO impuestos (nombre,tasa) VALUES (?,0.1600)")->execute(array("'+PREFIX+'_tax"));$tax=$db->lastInsertId();'
        '$q=$db->prepare("INSERT INTO metodos_pago (nombre,tipo) VALUES (?,?)");$methods=array();foreach(array("efectivo","tarjeta","transferencia","credito") as $type){$q->execute(array("'+PREFIX+'_".$type,$type));$methods[$type]=$db->lastInsertId();}'
        '$db->commit();echo json_encode(array("role"=>$op,"l"=>$l,"d"=>$d,"tax"=>$tax,"methods"=>$methods));'))
    admin=Client();admin.login('_admin');a=Client();a.login('_a');b=Client();b.login('_b');audit=Client();audit.login('_audit');anon=Client()
    anon.request('/ventas',expected=401);audit.request('/ventas/contexto',expected=403);audit.request('/ventas','POST',{},expected=403)
    boxes=[]
    for suffix in ['_caja_a','_caja_b','_caja_admin']:
        boxes.append(admin.request('/caja/cajas','POST',{'nombre':PREFIX+suffix,'ubicacion_id':fixture['l'],'activo':1},201)['id'])
    initial=a.request('/ventas/contexto');assert initial['turno_id'] is None and initial['mi_turno'] is None and initial['puede_abrir_caja']
    assert set(boxes).issubset({c['id'] for c in initial['cajas_disponibles']})
    sql('$db->prepare("UPDATE roles SET permisos=? WHERE id=?")->execute(array(json_encode(array("vender"=>true)),"'+fixture['role']+'"));')
    limited=a.request('/ventas/contexto');assert not limited['puede_abrir_caja'] and not limited['cajas_disponibles']
    a.request('/caja/turnos','POST',{'caja_id':boxes[0],'monto_apertura':'100','clave':str(uuid.uuid4())},403)
    sql('$db->prepare("UPDATE roles SET permisos=? WHERE id=?")->execute(array(json_encode(array("vender"=>true,"operar_caja"=>true)),"'+fixture['role']+'"));')
    ta=a.request('/caja/turnos','POST',{'caja_id':boxes[0],'monto_apertura':'100','notas':'QA','clave':str(uuid.uuid4())},201)['turno_id']
    tb=b.request('/caja/turnos','POST',{'caja_id':boxes[1],'monto_apertura':'0','notas':'QA','clave':str(uuid.uuid4())},201)['turno_id']
    a.request('/caja/turnos','POST',{'caja_id':boxes[2],'monto_apertura':'0','clave':str(uuid.uuid4())},409)
    adminContext=admin.request('/ventas/contexto');assert adminContext['turno_id'] is None and not adminContext['turnos']
    assert boxes[0] not in [c['id'] for c in adminContext['cajas_disponibles']]
    admin.request('/ventas/contexto?turno_id='+ta,expected=403)
    tc=admin.request('/caja/turnos','POST',{'caja_id':boxes[2],'monto_apertura':'0','notas':'QA','clave':str(uuid.uuid4()),'abierto_por':fixture['role']},201)['turno_id']
    own=admin.request('/ventas/contexto');assert own['turno_id']==tc and own['mi_turno']['id']==tc and len(own['turnos'])==1 and not own['cajas_disponibles']
    units={u['codigo']:u['id'] for u in admin.request('/catalogos')['unidades']};products={}
    for suffix,unit,kind,price,included in [('_piece','pza','estandar','116',1),('_bulk','kg','granel','100',0),('_service','srv','servicio','58',1),('_free','srv','servicio','0',1),('_overflow','kg','granel','0',1)]:
        data={'nombre':PREFIX+suffix,'descripcion':'QA','categoria_id':None,'unidad_base_id':units[unit],'impuesto_id':fixture['tax'],'tipo_producto':kind,'precio_incluye_impuesto':included,'controla_inventario':1,'activo':1,
              'variantes':[{'sku':PREFIX+suffix,'nombre':'QA','costo':'50','precio':price,'stock_minimo':'1','activo':1}]}
        pid=admin.request('/productos','POST',data,201)['id'];products[suffix]=next(p for p in admin.request('/productos?todos=1') if str(p['id'])==str(pid))
    piece=products['_piece']['variantes'][0]['id'];bulk=products['_bulk']['variantes'][0]['id'];service=products['_service']['variantes'][0]['id'];free=products['_free']['variantes'][0]['id']
    for v,q,l in [(piece,'10',fixture['l']),(bulk,'5',fixture['l']),(piece,'20',fixture['d'])]:
        admin.request('/inventario/ajustes','POST',{'variante_id':v,'ubicacion_id':l,'modo':'inicial','cantidad':q,'motivo':'QA','clave':str(uuid.uuid4())},201)
    extra=json.loads(sql('$q=$db->prepare("INSERT INTO ubicaciones (nombre,activo,disponible_venta) VALUES (?,?,?)");$q->execute(array("'+PREFIX+'_bodega",1,1));$b=$db->lastInsertId();$q->execute(array("'+PREFIX+'_vacia",1,1));$e=$db->lastInsertId();$q->execute(array("'+PREFIX+'_inactiva",0,1));echo json_encode(array("bodega"=>$b,"vacia"=>$e,"inactiva"=>$db->lastInsertId()));'))
    admin.request('/inventario/ajustes','POST',{'variante_id':piece,'ubicacion_id':extra['bodega'],'modo':'inicial','cantidad':'25','motivo':'QA','clave':str(uuid.uuid4())},201)
    stock_url='/ventas/existencias?turno_id='+ta+'&variante_id='+piece
    anon.request(stock_url,expected=401);audit.request(stock_url,expected=403);b.request(stock_url,expected=403);admin.request(stock_url,expected=403)
    a.request(stock_url,'POST',{},405);a.request('/ventas/existencias?turno_id='+ta+'&variante_id=0',expected=422);a.request('/ventas/existencias?turno_id='+ta+'&variante_id=999999999',expected=404)
    movements=sql('$q=$db->prepare("SELECT COUNT(*) FROM movimientos_inventario WHERE variante_id=?");$q->execute(array("'+piece+'"));echo $q->fetchColumn();')
    balances=a.request(stock_url);locations={r['id']:r for r in balances['ubicaciones']}
    assert balances['ubicacion_venta_id']==fixture['l'] and balances['ubicaciones'][0]['id']==fixture['l']
    assert locations[fixture['l']]['cantidad']=='10.0000' and locations[fixture['d']]['cantidad']=='20.0000' and int(locations[fixture['d']]['disponible_venta'])==0
    assert locations[extra['bodega']]['cantidad']=='25.0000' and locations[extra['vacia']]['cantidad']=='0.0000' and int(locations[extra['inactiva']]['activo'])==0
    assert 'costo' not in json.dumps(balances) and 'movimientos' not in balances
    assert not a.request('/ventas/existencias?turno_id='+ta+'&variante_id='+service)['ubicaciones']
    assert sql('$q=$db->prepare("SELECT COUNT(*) FROM movimientos_inventario WHERE variante_id=?");$q->execute(array("'+piece+'"));echo $q->fetchColumn();')==movements
    barcode='00000'+str(secrets.randbelow(99999999)).zfill(8)
    sql('$db->prepare("INSERT INTO codigos_barras (variante_id,codigo,cantidad) VALUES (?,?,2)")->execute(array("'+str(piece)+'","'+barcode+'"));')
    context=a.request('/ventas/contexto?turno_id='+ta);assert context['productos']==[] and context['codigos']==[]
    scan=a.request('/ventas/productos?turno_id='+ta+'&codigo='+barcode);assert scan['productos'][0]['id']==piece and scan['productos'][0]['cantidad_agregar']=='2.0000'
    assert not any(m['tipo']=='credito' for m in context['metodos']) and not context['puede_precio'] and not context['puede_descuento']
    b.request('/ventas/contexto?turno_id='+ta,expected=403)
    cart={'turno_id':ta,'notas':'QA ticket','cliente_nombre':'Cliente QA','detalles':[{'variante_id':piece,'cantidad':'2','precio_unitario':'116','descuento':'0'},{'variante_id':bulk,'cantidad':'1.2500','precio_unitario':'100','descuento':'0'},{'variante_id':service,'cantidad':'1','precio_unitario':'58','descuento':'0'}]}
    def quote(data,client=a,expected=200):return client.request('/ventas/cotizar','POST',data,expected)
    def payment(method,amount,received=None):return {'metodo_pago_id':fixture['methods'][method],'monto':amount,'recibido':received,'referencia':'QA'}
    def sale(data,pays,client=a):return dict(data,pagos=pays,sello=quote(data,client)['sello'],clave=str(uuid.uuid4()))
    def stock(v,l=None):return Decimal(next(r for r in admin.request('/inventario?ubicacion_id='+str(l or fixture['l'])) if str(r['variante_id'])==str(v))['cantidad'])
    a.request('/ventas/cotizar','POST',cart,403,csrf=False)
    bad=copy.deepcopy(cart);bad['detalles'][0]['cantidad']='0.5';quote(bad,expected=422)
    bad=copy.deepcopy(cart);bad['detalles'][0]['precio_unitario']='1';quote(bad,expected=403)
    bad=copy.deepcopy(cart);bad['detalles'][0]['descuento']='1';quote(bad,expected=403)
    quote(cart,admin,403)
    q=quote(cart);assert q['subtotal']=='375.00' and q['impuesto_total']=='60.00' and q['total']=='435.00'
    bad=copy.deepcopy(cart);bad['turno_id']=tc;bad['detalles'][0]['descuento']='32';dq=quote(bad,admin);assert dq['total']=='403.00' and dq['subtotal']=='347.41' and dq['impuesto_total']=='55.59'
    bad['detalles'][0]['descuento']='10000';quote(bad,admin,422)
    payload=dict(cart,pagos=[payment('efectivo','200','500'),payment('tarjeta','235')],sello=q['sello'],clave=str(uuid.uuid4()),total='0.01')
    invalid=copy.deepcopy(payload);invalid['pagos'][0]['recibido']='100';a.request('/ventas','POST',invalid,422)
    invalid=copy.deepcopy(payload);invalid['pagos'][1]['monto']='234';a.request('/ventas','POST',invalid,422)
    invalid=copy.deepcopy(payload);invalid['pagos']=[payment('credito','435')];a.request('/ventas','POST',invalid,403)
    invalid=copy.deepcopy(payload);invalid['detalles'][0]['cantidad']='3';a.request('/ventas','POST',invalid,409)
    sql('$db->prepare("UPDATE impuestos SET tasa=0.0800 WHERE id=?")->execute(array("'+fixture['tax']+'"));')
    a.request('/ventas','POST',payload,409)
    sql('$db->prepare("UPDATE impuestos SET tasa=0.1600 WHERE id=?")->execute(array("'+fixture['tax']+'"));')
    assert stock(piece)==10 and stock(bulk)==5
    completed=a.request('/ventas','POST',payload,201);assert completed['total']=='435.00' and completed['cambio']=='300.00'
    assert a.request('/ventas','POST',payload,201)==completed
    changed_customer=dict(payload,cliente_nombre='Otro cliente');a.request('/ventas','POST',changed_customer,409)
    checked={r['id']:r['cantidad'] for r in a.request(stock_url)['ubicaciones']};assert checked[fixture['l']]=='8.0000' and checked[extra['bodega']]=='25.0000' and checked[fixture['d']]=='20.0000'
    assert stock(piece)==8 and stock(bulk)==Decimal('3.7500') and stock(piece,fixture['d'])==20
    assert sql('$q=$db->prepare("SELECT COUNT(*) FROM existencias WHERE variante_id=?");$q->execute(array("'+str(service)+'"));echo $q->fetchColumn();').strip()=='0'
    ticket=a.request('/ventas/'+completed['venta_id']);assert ticket['ticket']['cliente_nombre']=='Cliente QA';assert len(ticket['detalles'])==3 and ticket['ticket']['usuario_nombre']=='QA ventas A'
    assert ticket['pagos'][0]['monto']=='200.00' and ticket['pagos'][0]['cambio']=='300.00'
    assert a.request('/caja/turnos/'+ta)['resumen']['esperado']=='300.00'
    b.request('/ventas/'+completed['venta_id'],expected=403);assert not b.request('/ventas')['ventas'];audit.request('/ventas/'+completed['venta_id'],expected=403);assert not audit.request('/ventas')['ventas'];admin.request('/ventas/'+completed['venta_id']);assert any(v['id']==completed['venta_id'] for v in admin.request('/ventas')['ventas'])
    # Los nombres, precios, tasas y métodos aplicados quedan congelados.
    sql('$db->prepare("UPDATE variantes_producto SET precio=999,nombre=\'Cambió\' WHERE id=?")->execute(array("'+str(piece)+'"));'
        '$db->prepare("UPDATE impuestos SET tasa=0.0800 WHERE id=?")->execute(array("'+fixture['tax']+'"));'
        '$db->prepare("UPDATE metodos_pago SET nombre=?,tipo=\'otro\' WHERE id=?")->execute(array("'+PREFIX+'_renamed","'+fixture['methods']['efectivo']+'"));')
    frozen=a.request('/ventas/'+completed['venta_id']);assert frozen['detalles']==ticket['detalles'] and frozen['pagos']==ticket['pagos']
    assert a.request('/caja/turnos/'+ta)['resumen']['esperado']=='300.00'
    sql('$db->prepare("UPDATE variantes_producto SET precio=116,nombre=\'QA\' WHERE id=?")->execute(array("'+str(piece)+'"));'
        '$db->prepare("UPDATE impuestos SET tasa=0.1600 WHERE id=?")->execute(array("'+fixture['tax']+'"));'
        '$db->prepare("UPDATE metodos_pago SET nombre=?,tipo=\'efectivo\' WHERE id=?")->execute(array("'+PREFIX+'_efectivo","'+fixture['methods']['efectivo']+'"));')
    one={'turno_id':ta,'notas':'QA','detalles':[{'variante_id':piece,'cantidad':'1','precio_unitario':'116','descuento':'0'}]}
    # Dos reintentos simultáneos descuentan y cobran una sola vez.
    a2=Client();a2.login('_a');repeated=sale(one,[payment('efectivo','116')])
    with ThreadPoolExecutor(max_workers=2) as pool:r=list(pool.map(lambda c:c.request('/ventas','POST',repeated,201),[a,a2]))
    assert r[0]==r[1] and stock(piece)==7
    # Dos cajas vendiendo el mismo SKU no pierden actualizaciones.
    pa=sale(one,[payment('transferencia','116')]);oneb=dict(one,turno_id=tb);pb=sale(oneb,[payment('efectivo','116')],b)
    with ThreadPoolExecutor(max_workers=2) as pool:list(pool.map(lambda pair:pair[0].request('/ventas','POST',pair[1],201),[(a,pa),(b,pb)]))
    assert stock(piece)==5
    freecart={'turno_id':ta,'detalles':[{'variante_id':free,'cantidad':'1','precio_unitario':'0','descuento':'0'}]};f=a.request('/ventas','POST',sale(freecart,[]),201);assert f['total']=='0.00'
    # La política documentada permite inventario negativo, sin tomar el resguardo.
    negative=copy.deepcopy(one);negative['detalles'][0]['cantidad']='10';a.request('/ventas','POST',sale(negative,[payment('efectivo','1160')]),201)
    assert stock(piece)==-5 and stock(piece,fixture['d'])==20
    # Un error en el segundo renglón revierte ticket, pagos y descuento de la primera variante.
    overflow=products['_overflow']['variantes'][0]['id']
    admin.request('/inventario/ajustes','POST',{'variante_id':overflow,'ubicacion_id':fixture['l'],'modo':'salida','cantidad':'9999999999.9999','motivo':'QA límite','clave':str(uuid.uuid4())},201)
    atomic=copy.deepcopy(one);atomic['detalles'].append({'variante_id':overflow,'cantidad':'0.0001','precio_unitario':'0','descuento':'0'})
    previous_stock=stock(piece);previous_cash=a.request('/caja/turnos/'+ta)['resumen']['esperado'];previous_sales=len(a.request('/ventas')['ventas'])
    a.request('/ventas','POST',sale(atomic,[payment('efectivo','116')]),422)
    assert stock(piece)==previous_stock and a.request('/caja/turnos/'+ta)['resumen']['esperado']==previous_cash and len(a.request('/ventas')['ventas'])==previous_sales
    # Venta y corte simultáneos: no puede completarse una venta después del cierre.
    pending=sale(one,[payment('efectivo','116')]);summary=a.request('/caja/turnos/'+ta)['resumen']
    closing={'monto_cierre':summary['esperado'],'notas_cierre':'QA','version':summary['version'],'clave':str(uuid.uuid4())}
    with ThreadPoolExecutor(max_workers=2) as pool:
        futures=[pool.submit(a.request,'/ventas','POST',pending,(201,409)),pool.submit(admin.request,'/caja/turnos/'+ta+'/cerrar','POST',closing,(200,409))];results=[f.result() for f in futures]
    assert sum(isinstance(r,dict) and ('venta_id' in r or 'contado' in r) for r in results)==1
    state=admin.request('/caja/turnos/'+ta)
    if state['estatus']=='abierto':admin.request('/caja/turnos/'+ta+'/cerrar','POST',{'monto_cierre':state['resumen']['esperado'],'notas_cierre':'QA','version':state['resumen']['version'],'clave':str(uuid.uuid4())})
    a.request('/ventas/cotizar','POST',one,409)
    a.request(stock_url,expected=409)
    closed=a.request('/ventas/contexto');assert closed['turno_id'] is None and closed['mi_turno'] is None and boxes[0] in [c['id'] for c in closed['cajas_disponibles']]
    for v in [piece,bulk]:
        history=admin.request('/inventario/movimientos?ubicacion_id='+fixture['l']+'&variante_id='+str(v))['movimientos'];assert sum((Decimal(m['cantidad']) for m in history),Decimal(0))==stock(v)
    print('OK: precios/impuestos/descuentos, pagos mixtos y cambio, turno propio, crédito rechazado, snapshots sin costos, inventario exacto y servicios, reintentos, cajas concurrentes y cierre coordinado.')
finally:
    sql('$db->beginTransaction();'
        '$q=$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE s FROM solicitudes_venta s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE v FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE u.nombre_usuario LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE m FROM movimientos_efectivo m JOIN turnos_caja t ON t.id=m.turno_id JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM metodos_pago WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM ubicaciones WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM impuestos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Ventas y datos QA retirados; tickets, caja e inventario del negocio conservados.')
    limpiar(PREFIX)
