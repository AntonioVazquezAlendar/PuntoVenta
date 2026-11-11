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
        result=json.load(r);assert r.status in (expected if isinstance(expected,tuple) else (expected,)),(path,r.status,expected,result)
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
    import datetime
    today=datetime.date.fromisoformat(admin.request('/reportes/catalogos')['hoy']);due=(today+datetime.timedelta(days=30)).isoformat()
    sql('$db->prepare("UPDATE roles SET permisos=? WHERE id=?")->execute(array(json_encode(array("hacer_compras"=>true,"ver_cuentas_pagar"=>true,"pagar_proveedores"=>true,"operar_caja"=>true,"ver_reportes"=>true)),"'+fixture['rol']+'"));$q=$db->prepare("INSERT INTO metodos_pago(nombre,tipo) VALUES (?,?)");$q->execute(array("'+PREFIX+'_cash","efectivo"));$q->execute(array("'+PREFIX+'_transfer","transferencia"));')
    methods={m['tipo']:m['id'] for m in admin.request('/ventas/contexto')['metodos'] if m['nombre'].startswith(PREFIX)}
    box=admin.request('/caja/cajas','POST',{'nombre':PREFIX+'_box','ubicacion_id':fixture['l1'],'activo':1},201)['id'];turn=reader.request('/caja/turnos','POST',{'caja_id':box,'monto_apertura':'300','clave':str(uuid.uuid4())},201)['turno_id']
    abox=admin.request('/caja/cajas','POST',{'nombre':PREFIX+'_adminbox','ubicacion_id':fixture['l1'],'activo':1},201)['id'];aturn=admin.request('/caja/turnos','POST',{'caja_id':abox,'monto_apertura':'100','clave':str(uuid.uuid4())},201)['turno_id']
    def make(condition=None,receive=True):
        data={'proveedor_id':provider,'ubicacion_id':fixture['l1'],'estatus':'pedida','factura_proveedor':PREFIX+'_F1','notas':'QA','clave':str(uuid.uuid4()),'detalles':[{'variante_id':piece,'cantidad':'2','costo_unitario':'100','impuesto_id':fixture['tax']}]}
        if condition is not None:data.update(condicion_pago=condition,vence=due if condition=='credito' else '')
        p=admin.request('/compras','POST',data,201)
        if receive:
            out=admin.request('/compras/'+p['id']+'/recibir','POST',{'version':p['version']});assert admin.request('/compras/'+p['id']+'/recibir','POST',{'version':p['version']})['id']==p['id'];return out
        return p
    def pay(p,amount,origin='caja',who=reader,expected=201,key=None,**extra):
        data={'origen':origin,'turno_id':turn,'metodo_pago_id':methods['efectivo'] if origin=='caja' else methods['transferencia'],'monto':amount,'cuenta':'QA cuenta bancaria' if origin=='externo' else '','referencia':'QA','notas':'QA pago a proveedor','clave':key or str(uuid.uuid4())};data.update(extra);return who.request('/cuentas-pagar/'+p['id']+'/pagos','POST',data,expected),data
    anon.request('/cuentas-pagar',expected=401)
    legacy=make();modern=make('credito');cashpurchase=make('contado');pending=make('credito',False)
    assert modern['saldo_base']=='232.00' and cashpurchase['saldo_base']=='232.00' and legacy['saldo_base'] is None
    assert reader.request('/caja/turnos/'+turn)['resumen']['esperado']=='300.00'
    reader.request('/cuentas-pagar/'+pending['id'],expected=409)
    pay(legacy,'10',expected=409)
    lc=admin.request('/cuentas-pagar/'+legacy['id']);rec={'saldo_inicial':'100','condicion_pago':'credito','vence':(today-datetime.timedelta(days=1)).isoformat(),'motivo':'QA conciliación con registros previos','version':lc['version']}
    reader.request('/cuentas-pagar/'+legacy['id']+'/conciliar','POST',rec,403)
    admin.request('/cuentas-pagar/'+legacy['id']+'/conciliar','POST',dict(rec,saldo_inicial='233'),422)
    admin.request('/cuentas-pagar/'+legacy['id']+'/conciliar','POST',rec)
    lc=admin.request('/cuentas-pagar/'+legacy['id']);assert lc['saldo']=='100.00' and lc['conciliacion']['pagado_previamente']=='132.00'
    admin.request('/cuentas-pagar/'+legacy['id']+'/conciliar','POST',rec,409)
    assert reader.request('/caja/turnos/'+turn)['resumen']['esperado']=='300.00'
    assert any(c['id']==legacy['id'] for c in reader.request('/cuentas-pagar?estado=vencida&proveedor_id='+provider)['compras'])
    inventory=admin.request('/inventario?ubicacion_id='+fixture['l1'])
    stock_before=next(x for x in inventory if x['variante_id']==piece)['cantidad'];cost_before=next(p for p in admin.request('/productos?todos=1') if p['id']==products['_piece']['id'])['variantes'][0]['costo']
    data={'origen':'caja','turno_id':turn,'metodo_pago_id':methods['efectivo'],'monto':'50','notas':'QA','clave':str(uuid.uuid4())}
    reader.request('/cuentas-pagar/'+modern['id']+'/pagos','POST',data,403,csrf=False)
    stale=reader.request('/caja/turnos/'+turn)['resumen']
    first,payload=pay(modern,'50');assert reader.request('/cuentas-pagar/'+modern['id']+'/pagos','POST',payload,201)==first
    reader.request('/cuentas-pagar/'+modern['id']+'/pagos','POST',dict(payload,monto='51'),409)
    assert reader.request('/caja/turnos/'+turn)['resumen']['esperado']=='250.00'
    reader.request('/caja/turnos/'+turn+'/cerrar','POST',{'monto_cierre':'250','version':stale['version'],'clave':str(uuid.uuid4())},409)
    pay(modern,'100','externo',admin);assert admin.request('/caja/turnos/'+aturn)['resumen']['esperado']=='100.00'
    assert reader.request('/cuentas-pagar/'+modern['id'])['saldo']=='82.00'
    pay(modern,'83',expected=409);pay(modern,'10',expected=422,metodo_pago_id=methods['transferencia']);pay(modern,'10',expected=403,turno_id=aturn)
    pay(modern,'10','externo',expected=422,cuenta='');pay(modern,'0',expected=422)
    pay(modern,'82');assert reader.request('/cuentas-pagar/'+modern['id'])['saldo']=='0.00'
    assert reader.request('/caja/turnos/'+turn)['resumen']['esperado']=='168.00'
    assert any(c['id']==modern['id'] for c in reader.request('/cuentas-pagar?estado=pagada&proveedor_id='+provider)['compras'])
    # Los pagos no tocan inventario ni costo promedio, tampoco retiran efectivo externo.
    assert next(x for x in admin.request('/inventario?ubicacion_id='+fixture['l1']) if x['variante_id']==piece)['cantidad']==stock_before
    assert next(p for p in admin.request('/productos?todos=1') if p['id']==products['_piece']['id'])['variantes'][0]['costo']==cost_before
    pay(cashpurchase,'169',expected=409)
    before=reader.request('/caja/turnos/'+turn)['resumen'];reader.request('/caja/turnos/'+turn+'/cerrar','POST',{'monto_cierre':before['esperado'],'version':before['version'],'clave':str(uuid.uuid4())})
    closed=reader.request('/caja/turnos/'+turn)['resumen'];oldturn=turn;turn=reader.request('/caja/turnos','POST',{'caja_id':box,'monto_apertura':'100','clave':str(uuid.uuid4())},201)['turno_id']
    assert reader.request('/cuentas-pagar/'+modern['id']+'/pagos','POST',payload,201)==first
    pay(legacy,'100');assert reader.request('/caja/turnos/'+turn)['resumen']['esperado']=='0.00' and reader.request('/caja/turnos/'+oldturn)['resumen']==closed
    # No sobrepago con solicitudes simultáneas de distintos responsables.
    concurrent=make('credito')
    with ThreadPoolExecutor(max_workers=2) as pool:
        f1=pool.submit(pay,concurrent,'150','externo',reader,(201,409));f2=pool.submit(pay,concurrent,'150','externo',admin,(201,409));results=[f1.result()[0],f2.result()[0]]
    assert sum('pago_id' in r for r in results)==1 and admin.request('/cuentas-pagar/'+concurrent['id'])['saldo']=='82.00'
    report=reader.request('/reportes/ventas?caja_id='+box);assert report['resumen']['ventas']=='0.00' and report['resumen']['utilidad']=='0.00'
    assert sum(Decimal(p['pagado']) for p in report['pagos_proveedores'])==232
    detail=reader.request('/cuentas-pagar/'+modern['id']);assert detail['pagos'][0]['datos']['proveedor']==PREFIX+'_supplier'
    # Revocación inmediata del permiso de pago, conservando consulta.
    sql('$db->prepare("UPDATE roles SET permisos=JSON_SET(permisos,\'$.pagar_proveedores\',false) WHERE id=?")->execute(array("'+fixture['rol']+'"));')
    pay(concurrent,'1','externo',expected=403);assert not reader.request('/cuentas-pagar/'+concurrent['id'])['puede_pagar']
    print('OK: compras contado/crédito, saldo histórico conciliado, vencimientos, pagos parciales/totales, fondos externos/caja, permisos/CSRF, concurrencia, reintentos, cortes inmutables e inventario conservado.')

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
        '$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE m FROM movimientos_efectivo m JOIN usuarios u ON u.id=m.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM metodos_pago WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+PREFIX+'_role"));'
        '$db->prepare("DELETE FROM ubicaciones WHERE nombre IN (?,?)")->execute(array("'+PREFIX+'_loc1","'+PREFIX+'_loc2"));'
        '$db->prepare("DELETE FROM impuestos WHERE nombre=?")->execute(array("'+PREFIX+'_iva"));$db->commit();')
    print('Datos QA retirados; compras e inventario del negocio conservados.')
    limpiar(PREFIX)
