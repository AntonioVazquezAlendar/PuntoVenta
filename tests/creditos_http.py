from qa_auditoria import limpiar
"""Reportes: alcance, fechas, importes históricos, agrupaciones y Excel."""
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
    f=json.loads(sql('$db->beginTransaction();$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_op",json_encode(array("vender"=>true,"operar_caja"=>true,"cancelar_venta"=>true,"hacer_devoluciones"=>true,"ver_reportes"=>true,"ver_creditos"=>true,"vender_credito"=>true,"cobrar_abonos"=>true))));$role=$db->lastInsertId();$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_audit",json_encode(array("ver_reportes"=>true,"ver_creditos"=>true,"vender_credito"=>true,"cobrar_abonos"=>true))));$audit=$db->lastInsertId();$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");foreach(array("_admin"=>1,"_a"=>$role,"_b"=>$role,"_audit"=>$audit) as $s=>$r){$q->execute(array("QA reembolsos","'+PREFIX+'".$s,$r,$h));}$q=$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,?)");$q->execute(array("'+PREFIX+'_venta",1));$l=$db->lastInsertId();$q->execute(array("'+PREFIX+'_resguardo",0));$d=$db->lastInsertId();$q=$db->prepare("INSERT INTO impuestos (nombre,tasa) VALUES (?,?)");$q->execute(array("'+PREFIX+'_iva",0.16));$tax=$db->lastInsertId();$q->execute(array("'+PREFIX+'_cero",0));$zero=$db->lastInsertId();$q=$db->prepare("INSERT INTO metodos_pago (nombre,tipo) VALUES (?,?)");$q->execute(array("'+PREFIX+'_cash","efectivo"));$cash=$db->lastInsertId();$q->execute(array("'+PREFIX+'_card","tarjeta"));$card=$db->lastInsertId();$db->commit();echo json_encode(array("role"=>$role,"l"=>$l,"d"=>$d,"tax"=>$tax,"zero"=>$zero,"cash"=>$cash,"card"=>$card));'))
    admin=Client();admin.login('_admin');a=Client();a.login('_a');b=Client();b.login('_b');audit=Client();audit.login('_audit');anon=Client()
    boxes=[];turns=[]
    for i,(client,fund) in enumerate([(a,'100'),(b,'0'),(admin,'500')]):
        box=admin.request('/caja/cajas','POST',{'nombre':PREFIX+'_box'+str(i),'ubicacion_id':f['l'],'activo':1},201)['id'];boxes.append(box);turns.append(client.request('/caja/turnos','POST',{'caja_id':box,'monto_apertura':fund,'clave':str(uuid.uuid4())},201)['turno_id'])
    cat=admin.request('/catalogos');units={u['codigo']:u['id'] for u in cat['unidades']};products={}
    for suffix,unit,kind,price,inc,tax in [('_piece','pza','estandar','116',1,f['tax']),('_bulk','kg','granel','100',0,f['tax']),('_service','srv','servicio','58',1,f['tax']),('_tiny','kg','granel','0.01',1,f['zero']),('_free','srv','servicio','0',1,f['zero'])]:
        data={'nombre':PREFIX+suffix,'categoria_id':None,'unidad_base_id':units[unit],'impuesto_id':tax,'tipo_producto':kind,'precio_incluye_impuesto':inc,'controla_inventario':1,'activo':1,'variantes':[{'sku':PREFIX+suffix,'nombre':'QA','costo':'50','precio':price,'stock_minimo':'0','activo':1}]};pid=admin.request('/productos','POST',data,201)['id'];products[suffix]=next(p for p in admin.request('/productos?todos=1') if p['id']==pid)
    piece=products['_piece']['variantes'][0]['id'];bulk=products['_bulk']['variantes'][0]['id'];service=products['_service']['variantes'][0]['id'];tiny=products['_tiny']['variantes'][0]['id'];free=products['_free']['variantes'][0]['id']
    for v,n in [(piece,'10'),(bulk,'5')]:admin.request('/inventario/ajustes','POST',{'variante_id':v,'ubicacion_id':f['l'],'modo':'inicial','cantidad':n,'motivo':'QA','clave':str(uuid.uuid4())},201)
    def stock(v,l=None):return Decimal(next(p for p in admin.request('/inventario?ubicacion_id='+(l or f['l'])) if p['variante_id']==v)['cantidad'])
    def line(v,n):return {'variante_id':v,'cantidad':n,'descuento':'0'}
    def sell(lines,pays,client=a,turn=None):
        data={'turno_id':turn or turns[0],'detalles':lines};q=client.request('/ventas/cotizar','POST',data);data.update(sello=q['sello'],clave=str(uuid.uuid4()),pagos=[{'metodo_pago_id':f[k],'monto':v} for k,v in pays]);out=client.request('/ventas','POST',data,201);return client.request('/ventas/'+out['venta_id'])
    def action(sale,details=None,tipo='devolucion',turn=None):return {'tipo':tipo,'turno_id':turn or turns[0],'motivo':'QA devolución','detalles':details or []}
    def qrefund(sale,data,client=a,expected=200):return client.request('/ventas/'+sale['id']+'/reembolsos/cotizar','POST',data,expected)
    def refund(sale,data,pays,client=a,q=None,key=None,expected=201):
        q=q or qrefund(sale,data,client);p=[{'pago_venta_id':sale['pagos'][i]['id'],'monto':n} for i,n in pays]
        payload=dict(data,sello=q['sello'],clave=key or str(uuid.uuid4()),reembolsos=p,autorizacion={'nombre_usuario':PREFIX+'_admin','contrasena':PASSWORD})
        return client.request('/ventas/'+sale['id']+'/reembolsos','POST',payload,expected),payload
    original=sell([line(piece,'3'),line(bulk,'1.25'),line(service,'1')],[('cash','300'),('card','251')]);assert original['total']=='551.00'
    detail={d['variante_id']:d['id'] for d in original['detalles']}
    def ret(v,n,dest='venta'):return {'detalle_venta_id':detail[v],'cantidad':n,'destino':dest,'ubicacion_id':f['d'] if dest=='resguardo' else None}
    client=admin.request('/clientes','POST',{'nombre':PREFIX+'_cliente','telefono':'','correo':'','direccion':'','notas':'','activo':1,'predeterminado':False},201)['id']
    f['client']=client
    sql('$db->prepare("INSERT INTO metodos_pago(nombre,tipo,activo) VALUES (?,\'credito\',1)")->execute(array("'+PREFIX+'_credito"));')
    credit=sql('$q=$db->prepare("SELECT id FROM metodos_pago WHERE nombre=?");$q->execute(array("'+PREFIX+'_credito"));echo $q->fetchColumn();').strip()
    admin.request('/creditos/clientes/'+client,'PUT',{'habilitado':1,'limite':'500','dias':30,'version':admin.request('/creditos/clientes/'+client)['version']})
    a.request('/creditos/clientes/'+client,expected=403)
    def salecredit(amount='232',cash='0',who=a,turn=None):
        d={'turno_id':turn or turns[0],'cliente_id':client,'detalles':[{'variante_id':piece,'cantidad':'2','descuento':'0'}]};q=who.request('/ventas/cotizar','POST',d);ps=[{'metodo_pago_id':credit,'monto':amount}]
        if Decimal(cash):ps.append({'metodo_pago_id':f['cash'],'monto':cash})
        out=who.request('/ventas','POST',dict(d,sello=q['sello'],clave=str(uuid.uuid4()),pagos=ps),201);return who.request('/ventas/'+out['venta_id'])
    loan=salecredit('200','32');lid=loan['id'];detailid=loan['detalles'][0]['id'];assert admin.request('/creditos/clientes/'+client)['saldo']=='200.00'
    ctx=a.request('/creditos/'+lid);assert ctx['saldo']=='200.00' and not ctx['abonos'];b.request('/creditos/'+lid,expected=403)
    assert not b.request('/creditos')['creditos'];assert any(x['venta_id']==lid for x in admin.request('/creditos')['creditos'])
    payload={'turno_id':turns[0],'metodo_pago_id':f['cash'],'monto':'50','recibido':'100','referencia':'QA abono','clave':str(uuid.uuid4())}
    a.request('/creditos/'+lid+'/abonos','POST',payload,403,csrf=False)
    before=a.request('/caja/turnos/'+turns[0])['resumen']['esperado'];ab=a.request('/creditos/'+lid+'/abonos','POST',payload,201);assert a.request('/creditos/'+lid+'/abonos','POST',payload,201)==ab
    assert a.request('/creditos/'+lid)['saldo']=='150.00' and len(a.request('/ventas/'+lid)['pagos'])==2
    assert Decimal(a.request('/caja/turnos/'+turns[0])['resumen']['esperado'])==Decimal(before)+50
    a.request('/creditos/'+lid+'/abonos','POST',dict(payload,clave=str(uuid.uuid4()),monto='151'),409)
    # La devolución reduce primero la deuda y no entrega dinero no recibido.
    data={'tipo':'devolucion','turno_id':turns[0],'motivo':'QA devolución crédito','detalles':[{'detalle_venta_id':detailid,'cantidad':'1','destino':'venta'}]};q=a.request('/ventas/'+lid+'/reembolsos/cotizar','POST',data)
    cp=next(p for p in q['pagos'] if p['tipo']=='credito');assert cp['disponible']=='150.00'
    a.request('/ventas/'+lid+'/reembolsos','POST',dict(data,sello=q['sello'],clave=str(uuid.uuid4()),autorizacion={'nombre_usuario':PREFIX+'_admin','contrasena':PASSWORD},reembolsos=[{'pago_venta_id':cp['pago_venta_id'],'monto':'116'}]),201)
    assert a.request('/creditos/'+lid)['saldo']=='34.00'
    pay2=dict(payload,monto='34',recibido='34',clave=str(uuid.uuid4()));a.request('/creditos/'+lid+'/abonos','POST',pay2,201);assert a.request('/creditos/'+lid)['saldo']=='0.00'
    # Al devolver lo restante, reembolsa anticipo y abonos; la deuda no reaparece.
    q=a.request('/ventas/'+lid+'/reembolsos/cotizar','POST',data);pays=[{'pago_venta_id':p['pago_venta_id'],'monto':p['disponible']} for p in q['pagos'] if Decimal(p['disponible'])]
    assert sum(Decimal(p['monto']) for p in pays)==116
    a.request('/ventas/'+lid+'/reembolsos','POST',dict(data,sello=q['sello'],clave=str(uuid.uuid4()),autorizacion={'nombre_usuario':PREFIX+'_admin','contrasena':PASSWORD},reembolsos=pays),201)
    assert a.request('/creditos/'+lid)['saldo']=='0.00' and admin.request('/creditos/clientes/'+client)['saldo']=='0.00'
    # Deshabilitar crédito conserva la cobranza y el límite se valida bajo concurrencia.
    l1=salecredit();l2=salecredit();cfg=admin.request('/creditos/clientes/'+client);assert cfg['saldo']=='464.00'
    d={'turno_id':turns[0],'cliente_id':client,'detalles':[{'variante_id':piece,'cantidad':'2','descuento':'0'}]};q=a.request('/ventas/cotizar','POST',d);a.request('/ventas','POST',dict(d,sello=q['sello'],clave=str(uuid.uuid4()),pagos=[{'metodo_pago_id':credit,'monto':'232'}]),409)
    admin.request('/creditos/clientes/'+client,'PUT',{'habilitado':0,'limite':'500','dias':15,'version':cfg['version']})
    a.request('/creditos/'+l1['id']+'/abonos','POST',dict(payload,monto='10',recibido='10',clave=str(uuid.uuid4())),201)
    admin.request('/creditos/clientes/'+client,'PUT',{'habilitado':0,'limite':'1','dias':15,'version':admin.request('/creditos/clientes/'+client)['version']},422)
    r=a.request('/reportes/ventas');assert next(x for x in r['abonos'] if x['tipo']=='efectivo')['cobrado']=='94.00'
    # Un abono de otro día se refleja en el nuevo turno; el corte anterior queda congelado.
    old_turn=turns[0];cut=a.request('/caja/turnos/'+old_turn)['resumen'];a.request('/caja/turnos/'+old_turn+'/cerrar','POST',{'monto_cierre':cut['esperado'],'version':cut['version'],'clave':str(uuid.uuid4())})
    closed=a.request('/caja/turnos/'+old_turn)['resumen'];turns[0]=a.request('/caja/turnos','POST',{'caja_id':boxes[0],'monto_apertura':'100','clave':str(uuid.uuid4())},201)['turno_id']
    a.request('/creditos/'+l2['id']+'/abonos','POST',dict(payload,turno_id=turns[0],monto='10',recibido='10',clave=str(uuid.uuid4())),201)
    assert a.request('/caja/turnos/'+old_turn)['resumen']==closed
    assert a.request('/caja/turnos/'+turns[0])['resumen']['esperado']=='110.00'
    assert a.request('/creditos/'+lid+'/abonos','POST',payload,201)==ab
    # El límite compartido entre cajeros no se puede rebasar con ventas simultáneas.
    cfg=admin.request('/creditos/clientes/'+client);admin.request('/creditos/clientes/'+client,'PUT',dict(habilitado=1,limite='700',dias=30,version=cfg['version']))
    def prepare_sale(who,turn):
        data={'turno_id':turn,'cliente_id':client,'detalles':[{'variante_id':piece,'cantidad':'2','descuento':'0'}]};quote=who.request('/ventas/cotizar','POST',data);return dict(data,sello=quote['sello'],clave=str(uuid.uuid4()),pagos=[{'metodo_pago_id':credit,'monto':'232'}])
    pa=prepare_sale(a,turns[0]);pb=prepare_sale(b,turns[1])
    with ThreadPoolExecutor(max_workers=2) as pool:
        fa=pool.submit(a.request,'/ventas','POST',pa,(201,409));fb=pool.submit(b.request,'/ventas','POST',pb,(201,409));wins=[fa.result(),fb.result()]
    assert sum('venta_id' in x for x in wins)==1 and Decimal(admin.request('/creditos/clientes/'+client)['saldo'])<=700
    # Cancelar una venta completamente a crédito elimina la deuda, sin mover efectivo.
    cfg=admin.request('/creditos/clientes/'+client);admin.request('/creditos/clientes/'+client,'PUT',dict(habilitado=1,limite='2000',dias=30,version=cfg['version']))
    unpaid=salecredit();debt_before=Decimal(admin.request('/creditos/clientes/'+client)['saldo']);cash_before=a.request('/caja/turnos/'+turns[0])['resumen']['esperado'];data={'tipo':'cancelacion','turno_id':turns[0],'motivo':'QA cancelación crédito'}
    q=a.request('/ventas/'+unpaid['id']+'/reembolsos/cotizar','POST',data);p=next(p for p in q['pagos'] if p['tipo']=='credito')
    a.request('/ventas/'+unpaid['id']+'/reembolsos','POST',dict(data,sello=q['sello'],clave=str(uuid.uuid4()),autorizacion={'nombre_usuario':PREFIX+'_admin','contrasena':PASSWORD},reembolsos=[{'pago_venta_id':p['pago_venta_id'],'monto':'232'}]),201)
    assert a.request('/creditos/'+unpaid['id'])['saldo']=='0.00' and a.request('/caja/turnos/'+turns[0])['resumen']['esperado']==cash_before
    assert Decimal(admin.request('/creditos/clientes/'+client)['saldo'])==debt_before-232
    print('OK: crédito mixto, límite, permisos, abonos/cambio/caja, reintentos, reducción de deuda y devolución de pagos recibidos, cobro con crédito deshabilitado.')

finally:
    sql('$db->beginTransaction();$db->prepare("DELETE s FROM solicitudes_reembolso s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE d FROM devoluciones_venta d JOIN usuarios u ON u.id=d.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_venta s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE v FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM clientes WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE m FROM movimientos_efectivo m JOIN usuarios u ON u.id=m.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM ubicaciones WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM metodos_pago WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM impuestos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Ventas, devoluciones y saldos QA retirados; datos del negocio conservados.')
    limpiar(PREFIX)
