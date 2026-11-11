from qa_auditoria import limpiar
"""Gastos: salida única, categorías, permisos, fechas, reportes y concurrencia."""
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
PREFIX='qa_cash_'+secrets.token_hex(5)
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
    fixture=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_operator",json_encode(array("operar_caja"=>true))));$op=$db->lastInsertId();$q->execute(array("'+PREFIX+'_supervisor","{}"));$sup=$db->lastInsertId();'
        '$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");'
        '$q->execute(array("QA caja admin","'+PREFIX+'_admin",1,$h));$q->execute(array("QA cajero A","'+PREFIX+'_a",$op,$h));$q->execute(array("QA cajero B","'+PREFIX+'_b",$op,$h));$q->execute(array("QA supervisor","'+PREFIX+'_sup",$sup,$h));'
        '$q=$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,?)");$q->execute(array("'+PREFIX+'_loc1",1));$l1=$db->lastInsertId();$q->execute(array("'+PREFIX+'_loc2",1));$l2=$db->lastInsertId();$q->execute(array("'+PREFIX+'_resguardo",0));$res=$db->lastInsertId();'
        '$db->commit();echo json_encode(array("op"=>$op,"sup"=>$sup,"l1"=>$l1,"l2"=>$l2,"res"=>$res));'))
    admin=Client();admin.login('_admin');a=Client();a.login('_a');b=Client();b.login('_b');sup=Client();sup.login('_sup');anon=Client()
    import datetime
    sql('$db->prepare("UPDATE roles SET permisos=? WHERE id=?")->execute(array(json_encode(array("operar_caja"=>true,"ver_gastos"=>true,"registrar_gastos"=>true,"ver_reportes"=>true)),"'+fixture['op']+'"));$db->prepare("UPDATE roles SET permisos=? WHERE id=?")->execute(array(json_encode(array("ver_gastos"=>true)),"'+fixture['sup']+'"));$q=$db->prepare("INSERT INTO metodos_pago(nombre,tipo) VALUES (?,?)");$q->execute(array("'+PREFIX+'_cash","efectivo"));$q->execute(array("'+PREFIX+'_transfer","transferencia"));$q->execute(array("'+PREFIX+'_credit","credito"));')
    methods={m['tipo']:m['id'] for m in admin.request('/ventas/contexto')['metodos'] if m['nombre'].startswith(PREFIX)}
    def newbox(suffix):return admin.request('/caja/cajas','POST',{'nombre':PREFIX+suffix,'ubicacion_id':fixture['l1'],'activo':1},201)['id']
    caja1=newbox('_box1');caja2=newbox('_box2');turn=a.request('/caja/turnos','POST',{'caja_id':caja1,'monto_apertura':'100','clave':str(uuid.uuid4())},201)['turno_id'];other=admin.request('/caja/turnos','POST',{'caja_id':caja2,'monto_apertura':'100','clave':str(uuid.uuid4())},201)['turno_id']
    cat=admin.request('/gastos/categorias','POST',{'nombre':PREFIX+'_expense','activo':1},201)['id'];today=a.request('/gastos/catalogos')['hoy'];yesterday=(datetime.date.fromisoformat(today)-datetime.timedelta(days=1)).isoformat();future=(datetime.date.fromisoformat(today)+datetime.timedelta(days=1)).isoformat()
    def expense(amount='30',origin='caja',who=a,expected=201,**extra):
        data={'categoria_id':cat,'origen':origin,'turno_id':turn,'metodo_pago_id':methods['efectivo'] if origin=='caja' else methods['transferencia'],'monto':amount,'fecha':today,'cuenta':'QA fondos externos' if origin=='externo' else '','beneficiario':'QA beneficiario','descripcion':'QA gasto del negocio','referencia':'QA','clave':str(uuid.uuid4())};data.update(extra);return who.request('/gastos','POST',data,expected),data
    anon.request('/gastos',expected=401);a.request('/gastos/categorias','POST',{'nombre':PREFIX+'_denied','activo':1},403)
    admin.request('/gastos/categorias','POST',{'nombre':PREFIX+'_expense','activo':1},409)
    catrow=next(c for c in admin.request('/gastos/categorias') if c['id']==cat)
    base=a.request('/caja/turnos/'+turn)['resumen'];first,payload=expense();assert a.request('/gastos','POST',payload,201)==first
    a.request('/gastos','POST',dict(payload,monto='31'),409);a.request('/gastos','POST',dict(payload,clave=str(uuid.uuid4())),403,csrf=False)
    ledger=a.request('/caja/turnos/'+turn);assert ledger['resumen']['esperado']=='70.00' and ledger['resumen']['salidas']=='30.00' and ledger['resumen']['gastos_incluidos_salidas']=='30.00'
    detail=a.request('/gastos/'+first['id']);assert len(ledger['movimientos'])==1 and detail['movimiento_id']==ledger['movimientos'][0]['id'] and first['folio'] in ledger['movimientos'][0]['motivo']
    a.request('/caja/turnos/'+turn+'/cerrar','POST',{'monto_cierre':'70','version':base['version'],'clave':str(uuid.uuid4())},409)
    second,_=expense('20','externo');assert a.request('/caja/turnos/'+turn)['resumen']['esperado']=='70.00' and a.request('/gastos/'+second['id'])['movimiento_id'] is None
    assert a.request('/gastos')['resumen']['total']=='50.00' and a.request('/gastos?categoria_id='+cat)['resumen']['caja']=='30.00'
    assert b.request('/gastos')['resumen']['total']=='0.00';b.request('/gastos/'+first['id'],expected=403);sup.request('/gastos/'+first['id'],expected=403)
    expense(who=sup,expected=403);b.request('/gastos?usuario_id='+a.request('/sesion')['usuario']['id'],expected=403)
    admin.request('/gastos/'+first['id']);a.request('/gastos/'+first['id'],'PUT',{},405)
    expense('71',expected=409);expense('0',expected=422);expense('0.001',expected=422);expense('1',expected=403,turno_id=other)
    expense('1',expected=422,metodo_pago_id=methods['transferencia']);expense('1','externo',expected=422,cuenta='');expense('1','externo',expected=422,metodo_pago_id=methods['credito'])
    expense('1','externo',expected=422,fecha=future);expense('1',expected=422,fecha=yesterday)
    expense('10','externo',fecha=yesterday)
    report=a.request('/reportes/ventas');assert report['resumen']['utilidad']=='0.00' and report['resumen']['gastos']=='50.00' and report['resumen']['resultado_despues_gastos']=='-50.00'
    report=a.request('/reportes/ventas?caja_id='+caja1);assert report['resumen']['gastos']=='30.00'
    assert a.request('/reportes/ventas?desde='+yesterday+'&hasta='+today)['resumen']['gastos']=='60.00'
    # Inactivar una categoría no modifica gastos históricos; reintentos siguen siendo seguros.
    admin.request('/gastos/categorias/'+cat,'PUT',{'nombre':PREFIX+'_renamed','activo':0,'version':catrow['version']})
    assert a.request('/gastos/'+first['id'])['datos']['categoria']==PREFIX+'_expense'
    expense('1','externo',expected=422);assert a.request('/gastos','POST',payload,201)==first
    admin.request('/gastos/categorias/'+cat,'PUT',{'nombre':PREFIX+'_renamed','activo':1,'version':catrow['version']},409)
    catrow=next(c for c in admin.request('/gastos/categorias') if c['id']==cat);admin.request('/gastos/categorias/'+cat,'PUT',{'nombre':PREFIX+'_renamed','activo':1,'version':catrow['version']})
    # Nuevo gasto en otro turno: el corte anterior no se toca.
    before=a.request('/caja/turnos/'+turn)['resumen'];a.request('/caja/turnos/'+turn+'/cerrar','POST',{'monto_cierre':before['esperado'],'version':before['version'],'clave':str(uuid.uuid4())});closed=a.request('/caja/turnos/'+turn)['resumen'];oldturn=turn
    turn=a.request('/caja/turnos','POST',{'caja_id':caja1,'monto_apertura':'30','clave':str(uuid.uuid4())},201)['turno_id'];expense('10');assert a.request('/caja/turnos/'+oldturn)['resumen']==closed
    assert a.request('/gastos','POST',payload,201)==first
    # Dos solicitudes concurrentes no pueden consumir más efectivo del disponible.
    a2=Client();a2.login('_a')
    with ThreadPoolExecutor(max_workers=2) as pool:
        f1=pool.submit(expense,'15','caja',a,(201,409));f2=pool.submit(expense,'15','caja',a2,(201,409));results=[f1.result()[0],f2.result()[0]]
    assert sum('folio' in r for r in results)==1 and a.request('/caja/turnos/'+turn)['resumen']['esperado']=='5.00'
    for _ in range(31):expense('1','externo')
    p1=a.request('/gastos');p2=a.request('/gastos?antes_de='+p1['siguiente']);assert len(p1['gastos'])==30 and len(p2['gastos'])==5 and p1['resumen']['total']=='106.00'
    assert not {r['id'] for r in p1['gastos']} & {r['id'] for r in p2['gastos']}
    sql('$db->prepare("UPDATE roles SET permisos=JSON_SET(permisos,\'$.registrar_gastos\',false) WHERE id=?")->execute(array("'+fixture['op']+'"));')
    expense('1','externo',expected=403);assert not a.request('/gastos/catalogos')['puede_registrar']
    print('OK: categorías, permisos, alcance, gasto externo/caja, salida única, reintentos, fechas, saldo insuficiente y concurrencia, cortes históricos, reportes y paginación.')

finally:
    sql('$db->beginTransaction();'
        '$db->prepare("DELETE g FROM gastos g JOIN usuarios u ON u.id=g.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM categorias_gasto WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE p FROM pagos_venta p JOIN ventas v ON v.id=p.venta_id WHERE v.folio LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM ventas WHERE folio LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario IN (?,?,?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_a","'+PREFIX+'_b","'+PREFIX+'_sup"));'
        '$q=$db->prepare("DELETE m FROM movimientos_efectivo m JOIN turnos_caja t ON t.id=m.turno_id JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM metodos_pago WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));'
        '$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?,?,?)")->execute(array("'+PREFIX+'_admin","'+PREFIX+'_a","'+PREFIX+'_b","'+PREFIX+'_sup"));'
        '$db->prepare("DELETE FROM roles WHERE nombre IN (?,?)")->execute(array("'+PREFIX+'_operator","'+PREFIX+'_supervisor"));'
        '$db->prepare("DELETE FROM ubicaciones WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Turnos y operaciones QA retirados; cajas y efectivo del negocio conservados.')
    limpiar(PREFIX)
