from qa_auditoria import limpiar
"""Caja: fixtures QA aislados para turnos, efectivo, corte, permisos y concurrencia."""
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
    anon.request('/caja/catalogos',expected=401);sup.request('/caja/catalogos',expected=403)
    def box(name,loc=None,active=1):return {'nombre':PREFIX+name,'ubicacion_id':loc or fixture['l1'],'activo':active}
    caja1=admin.request('/caja/cajas','POST',box('_box1'),201)['id'];caja2=admin.request('/caja/cajas','POST',box('_box2'),201)['id']
    admin.request('/caja/cajas','POST',box('_box1'),409);a.request('/caja/cajas','POST',box('_denied'),403)
    admin.request('/caja/cajas','POST',box('_bad',fixture['res']),422)
    def opening(caja,amount='0'):return {'caja_id':caja,'monto_apertura':amount,'notas':'QA apertura','clave':str(uuid.uuid4())}
    def entry(turno,amount='1',kind='entrada',client=a,expected=201):return client.request('/caja/turnos/'+str(turno)+'/movimientos','POST',{'tipo':kind,'monto':amount,'motivo':'QA movimiento','clave':str(uuid.uuid4())},expected)
    def detail(turno,client=a):return client.request('/caja/turnos/'+str(turno))
    def close(turno,amount='0',client=a,notes='',expected=200,version=None):
        data={'monto_cierre':amount,'notas_cierre':notes,'version':version or detail(turno,client)['resumen']['version'],'clave':str(uuid.uuid4())}
        return client.request('/caja/turnos/'+str(turno)+'/cerrar','POST',data,expected)
    a.request('/caja/turnos','POST',opening(caja1,'-1'),422);a.request('/caja/turnos','POST',opening(caja1,'1.001'),422)
    a.request('/caja/turnos','POST',opening(caja1),403,csrf=False)
    request=opening(caja1,'100.10');opened=a.request('/caja/turnos','POST',request,201);t=opened['turno_id']
    assert a.request('/caja/turnos','POST',request,201)==opened
    a.request('/caja/turnos','POST',dict(request,monto_apertura='200'),409)
    b.request('/caja/turnos','POST',opening(caja1),409);a.request('/caja/turnos','POST',opening(caja2),409)
    b.request('/caja/turnos/'+t,expected=403);sup.request('/caja/turnos/'+t,expected=403)
    admin.request('/caja/cajas/'+caja1,'PUT',box('_box1',active=0),409)
    admin.request('/caja/cajas/'+caja1,'PUT',box('_box1',fixture['l2']),409)
    admin.request('/almacen/ubicaciones/'+fixture['l1'],'PUT',{'nombre':PREFIX+'_loc1','descripcion':'QA','activo':1,'disponible_venta':0},409)
    old=detail(t)['resumen']['version']
    movement={'tipo':'entrada','monto':'25.25','motivo':'QA cambio','clave':str(uuid.uuid4())}
    first=a.request('/caja/turnos/'+t+'/movimientos','POST',movement,201);assert a.request('/caja/turnos/'+t+'/movimientos','POST',movement,201)==first
    entry(t,'10.10','salida');assert detail(t)['resumen']['esperado']=='115.25'
    entry(t,'116','salida',expected=409);entry(t,'0',expected=422);entry(t,'1.001',expected=422)
    # Ventas mixtas: solo cuenta el monto aplicado en efectivo, sin sumar recibido/cambio.
    usuario=a.request('/sesion')['usuario']['id']
    ids=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO metodos_pago (nombre,tipo) VALUES (?,?)");$ids=array();foreach(array("efectivo","tarjeta","transferencia","credito") as $tipo){$q->execute(array("'+PREFIX+'_".$tipo,$tipo));$ids[$tipo]=$db->lastInsertId();}'
        '$q=$db->prepare("INSERT INTO ventas (folio,ubicacion_id,caja_id,turno_id,usuario_id,estatus,total) VALUES (?,?,?,?,?, ?,?)");$q->execute(array("'+PREFIX+'_venta","'+fixture['l1']+'","'+caja1+'","'+t+'","'+str(usuario)+'","completada","640"));$venta=$db->lastInsertId();'
        '$q=$db->prepare("INSERT INTO pagos_venta (venta_id,metodo_pago_id,monto,recibido,cambio) VALUES (?,?,?,?,?)");$q->execute(array($venta,$ids["efectivo"],"100","200","100"));$q->execute(array($venta,$ids["tarjeta"],"500",null,"0"));$q->execute(array($venta,$ids["transferencia"],"40",null,"0"));'
        '$q=$db->prepare("INSERT INTO ventas (folio,ubicacion_id,caja_id,turno_id,usuario_id,estatus) VALUES (?,?,?,?,?,?)");$q->execute(array("'+PREFIX+'_pending","'+fixture['l1']+'","'+caja1+'","'+t+'","'+str(usuario)+'","en_espera"));$pending=$db->lastInsertId();$db->commit();echo json_encode(array("methods"=>$ids,"pending"=>$pending));'))
    report=detail(t);assert report['resumen']['ventas_efectivo']=='100.00' and report['resumen']['esperado']=='215.25'
    close(t,'215.25',version=old,expected=409)
    close(t,'215.25',expected=409)
    sql('$db->prepare("UPDATE ventas SET estatus=\'cancelada\' WHERE id=?")->execute(array("'+str(ids['pending'])+'"));')
    close(t,'214.25',expected=422)
    data={'monto_cierre':'214.25','notas_cierre':'QA falta una moneda','version':detail(t)['resumen']['version'],'clave':str(uuid.uuid4())}
    closed=a.request('/caja/turnos/'+t+'/cerrar','POST',data);assert closed['diferencia']=='-1.00' and closed['esperado']=='215.25'
    assert a.request('/caja/turnos/'+t+'/cerrar','POST',data)==closed
    entry(t,expected=409);close(t,expected=409)
    # El corte ya guardado no cambia por cambios posteriores en el catálogo.
    sql('$db->prepare("UPDATE metodos_pago SET tipo=\'otro\' WHERE id=?")->execute(array("'+str(ids['methods']['efectivo'])+'"));')
    assert detail(t)['resumen']['ventas_efectivo']=='100.00' and detail(t)['monto_esperado']=='215.25'
    admin.request('/caja/cajas/'+caja1,'PUT',box('_box1',fixture['l2']))
    assert detail(t)['ubicacion_id']==fixture['l1']
    assert not b.request('/caja/turnos')['turnos']
    # Concesión de supervisión independiente de administración de cajas.
    perms={p['clave']:False for p in admin.request('/permisos')};perms['operar_caja']=True;perms['supervisar_caja']=True
    admin.request('/roles/'+fixture['sup']+'/permisos','PUT',{'permisos':perms});detail(t,sup)
    sup.request('/caja/cajas','POST',box('_sup_denied'),403)
    # Límite decimal y salida total.
    tb=b.request('/caja/turnos','POST',opening(caja2),201)['turno_id'];entry(tb,'999999999999.99',client=b)
    entry(tb,'0.01',client=b,expected=422);assert detail(tb,b)['resumen']['esperado']=='999999999999.99'
    entry(tb,'999999999999.99','salida',b);close(tb,client=b)
    # Dos usuarios abriendo la misma caja: solo una apertura.
    with ThreadPoolExecutor(max_workers=2) as pool:
        results=list(pool.map(lambda c:c.request('/caja/turnos','POST',opening(caja1,'100'),(201,409)),[a,b]))
    opened=[r for r in results if isinstance(r,dict) and 'turno_id' in r];assert len(opened)==1
    concurrent_t=opened[0]['turno_id'];owner=a if isinstance(results[0],dict) and 'turno_id' in results[0] else b
    # Propietario y supervisor retiran simultáneamente: no se duplica el efectivo disponible.
    with ThreadPoolExecutor(max_workers=2) as pool:list(pool.map(lambda c:entry(concurrent_t,'80','salida',c,(201,409)),[owner,sup]))
    assert detail(concurrent_t,owner)['resumen']['esperado']=='20.00'
    close(concurrent_t,'20',sup)
    perms['supervisar_caja']=False;admin.request('/roles/'+fixture['sup']+'/permisos','PUT',{'permisos':perms});sup.request('/caja/turnos/'+t,expected=403)
    print('OK: cajas y permisos, apertura única, propiedad de turnos, importes exactos, movimientos idempotentes, corte con pagos mixtos, pendientes, versión obsoleta, snapshot, límite de efectivo, concurrencia y cierre inmutable.')
finally:
    sql('$db->beginTransaction();'
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
