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
    f=json.loads(sql('$db->beginTransaction();$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_op",json_encode(array("vender"=>true,"operar_caja"=>true,"cancelar_venta"=>true,"hacer_devoluciones"=>true))));$role=$db->lastInsertId();$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_audit",json_encode(array("ver_ventas"=>true))));$audit=$db->lastInsertId();$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");foreach(array("_admin"=>1,"_a"=>$role,"_b"=>$role,"_audit"=>$audit) as $s=>$r){$q->execute(array("QA reembolsos","'+PREFIX+'".$s,$r,$h));}$q=$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,?)");$q->execute(array("'+PREFIX+'_venta",1));$l=$db->lastInsertId();$q->execute(array("'+PREFIX+'_resguardo",0));$d=$db->lastInsertId();$q=$db->prepare("INSERT INTO impuestos (nombre,tasa) VALUES (?,?)");$q->execute(array("'+PREFIX+'_iva",0.16));$tax=$db->lastInsertId();$q->execute(array("'+PREFIX+'_cero",0));$zero=$db->lastInsertId();$q=$db->prepare("INSERT INTO metodos_pago (nombre,tipo) VALUES (?,?)");$q->execute(array("'+PREFIX+'_cash","efectivo"));$cash=$db->lastInsertId();$q->execute(array("'+PREFIX+'_card","tarjeta"));$card=$db->lastInsertId();$db->commit();echo json_encode(array("role"=>$role,"l"=>$l,"d"=>$d,"tax"=>$tax,"zero"=>$zero,"cash"=>$cash,"card"=>$card));'))
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
    path='/ventas/'+original['id']+'/reembolsos'
    anon.request(path,expected=401);b.request(path,expected=403);audit.request(path,expected=403);audit.request(path+'/contexto',expected=403);audit.request(path+'/cotizar','POST',action(original,[ret(piece,'1')]),403)
    ctx=a.request(path+'/contexto');assert ctx['puede_cancelar'] and ctx['puede_devolver']
    a.request(path+'/cotizar','POST',action(original,[ret(piece,'1')]),403,csrf=False)
    qrefund(original,action(original,[ret(piece,'0.5')]),expected=422)
    bad=action(original,[ret(piece,'1')]);bad['motivo']='';qrefund(original,bad,expected=422)
    qrefund(original,action(original,[ret(piece,'4')]),expected=409)
    # Las reglas y costos al vender se conservan aunque la ficha cambie después.
    sql('$db->prepare("UPDATE productos SET controla_inventario=0 WHERE id=?")->execute(array("'+products['_piece']['id']+'"));$db->prepare("UPDATE variantes_producto SET costo=300 WHERE id=?")->execute(array("'+piece+'"));')
    partial=action(original,[ret(piece,'1'),ret(bulk,'0.25','resguardo')]);quote=qrefund(original,partial);assert quote['subtotal']=='125.00' and quote['impuesto_total']=='20.00' and quote['total']=='145.00'
    required=dict(partial,sello=quote['sello'],clave=str(uuid.uuid4()),reembolsos=[{'pago_venta_id':original['pagos'][0]['id'],'monto':'100'},{'pago_venta_id':original['pagos'][1]['id'],'monto':'45'}])
    a.request(path,'POST',required,403)
    a.request(path,'POST',dict(required,autorizacion={'nombre_usuario':PREFIX+'_admin','contrasena':'WrongPassword123'}),403)
    a.request(path,'POST',dict(required,autorizacion={'nombre_usuario':PREFIX+'_b','contrasena':PASSWORD}),403)
    out,payload=refund(original,partial,[(0,'100'),(1,'45')],q=quote);assert a.request(path,'POST',payload,201)==out
    assert stock(piece)==8 and stock(bulk)==Decimal('3.75') and stock(bulk,f['d'])==Decimal('.25')
    assert a.request('/caja/turnos/'+turns[0])['resumen']['esperado']=='300.00'
    stale=dict(payload,clave=str(uuid.uuid4()));a.request(path,'POST',stale,409)
    qrefund(original,action(original,tipo='cancelacion'),expected=409)
    history=a.request(path);assert history['documentos'][0]['total']=='145.00'
    assert sql('$q=$db->prepare("SELECT costo_unitario FROM movimientos_inventario WHERE tipo_referencia=\'devolucion_venta\' AND variante_id=?");$q->execute(array("'+piece+'"));echo $q->fetchColumn();').strip()=='50.0000'
    service_out,_=refund(original,action(original,[ret(service,'1','sin_reingreso')]),[(0,'58')]);assert sql('$q=$db->prepare("SELECT COUNT(*) FROM existencias WHERE variante_id=?");$q->execute(array("'+service+'"));echo $q->fetchColumn();').strip()=='0'
    refund(original,action(original,[ret(piece,'2','sin_reingreso'),ret(bulk,'1','resguardo')]),[(0,'142'),(1,'206')]);ctx=a.request(path+'/contexto');assert ctx['venta']['reembolsado']=='551.00' and ctx['venta']['neto']=='0.00' and not ctx['puede_devolver']
    assert stock(piece)==8 and stock(bulk,f['d'])==Decimal('1.25')
    assert a.request('/caja/turnos/'+turns[0])['resumen']['esperado']=='100.00'
    sql('$db->prepare("UPDATE productos SET controla_inventario=1 WHERE id=?")->execute(array("'+products['_piece']['id']+'"));')
    cancel_sale=sell([line(piece,'2')],[('cash','232')]);cdata=action(cancel_sale,tipo='cancelacion');cq=qrefund(cancel_sale,cdata);cancel_result,cancel_payload=refund(cancel_sale,cdata,[(0,'232')],q=cq)
    assert a.request('/ventas/'+cancel_sale['id'])['estatus']=='cancelada' and stock(piece)==8
    summary=a.request('/caja/turnos/'+turns[0])['resumen'];assert summary['esperado']=='100.00' and summary['ventas_efectivo']=='532.00' and summary['reembolsos_efectivo']=='532.00'
    qrefund(cancel_sale,cdata,expected=409)
    # Cortes cerrados se conservan; los reintegros posteriores salen de un turno nuevo.
    old_sale=sell([line(piece,'1')],[('cash','116')]);close_summary=a.request('/caja/turnos/'+turns[0])['resumen'];a.request('/caja/turnos/'+turns[0]+'/cerrar','POST',{'monto_cierre':close_summary['esperado'],'version':close_summary['version'],'clave':str(uuid.uuid4())})
    assert a.request('/ventas/'+cancel_sale['id']+'/reembolsos','POST',cancel_payload,201)==cancel_result
    old_turn=turns[0];old_cut=a.request('/caja/turnos/'+old_turn)['resumen'];turns[0]=a.request('/caja/turnos','POST',{'caja_id':boxes[0],'monto_apertura':'0','clave':str(uuid.uuid4())},201)['turno_id']
    qrefund(old_sale,action(old_sale,tipo='cancelacion'),expected=409)
    od=action(old_sale,[{'detalle_venta_id':old_sale['detalles'][0]['id'],'cantidad':'1','destino':'venta'}]);oq=qrefund(old_sale,od);refund(old_sale,od,[(0,'116')],q=oq,expected=409)
    a.request('/caja/turnos/'+turns[0]+'/movimientos','POST',{'tipo':'entrada','monto':'116','motivo':'QA fondo para devolución','clave':str(uuid.uuid4())},201)
    before=a.request('/caja/turnos/'+turns[0])['resumen'];refund(old_sale,od,[(0,'116')]);assert a.request('/caja/turnos/'+turns[0])['resumen']['esperado']=='0.00'
    assert a.request('/caja/turnos/'+old_turn)['resumen']==old_cut
    a.request('/caja/turnos/'+turns[0]+'/cerrar','POST',{'monto_cierre':'0','version':before['version'],'clave':str(uuid.uuid4())},409)
    # Dos devoluciones concurrentes no pueden reintegrar dos veces las mismas unidades.
    concurrent=sell([line(piece,'1')],[('card','116')]);rd=[{'detalle_venta_id':concurrent['detalles'][0]['id'],'cantidad':'1','destino':'venta'}];da=action(concurrent,rd);dc=action(concurrent,rd,turn=turns[2]);qa=qrefund(concurrent,da);qc=qrefund(concurrent,dc,admin)
    with ThreadPoolExecutor(max_workers=2) as pool:
        fa=pool.submit(refund,concurrent,da,[(0,'116')],a,qa,None,(201,409));fc=pool.submit(refund,concurrent,dc,[(0,'116')],admin,qc,None,(201,409));results=[fa.result()[0],fc.result()[0]]
    assert sum(isinstance(x,dict) and 'devolucion_id' in x for x in results)==1
    assert a.request('/ventas/'+concurrent['id']+'/reembolsos/contexto')['venta']['reembolsado']=='116.00'
    # Prorrateo decimal: devuelve todos los centavos, sin crear ni perder dinero.
    rounding=sell([line(tiny,'1')],[('cash','0.01')]);amounts=[]
    for n in ['0.3333','0.3333','0.3334']:
        data=action(rounding,[{'detalle_venta_id':rounding['detalles'][0]['id'],'cantidad':n,'destino':'venta'}]);q=qrefund(rounding,data);amounts.append(Decimal(q['total']));refund(rounding,data,[(0,q['total'])] if Decimal(q['total']) else [],q=q)
    assert sum(amounts)==Decimal('0.01')
    zero=sell([line(free,'1')],[]);q=qrefund(zero,action(zero,tipo='cancelacion'));refund(zero,action(zero,tipo='cancelacion'),[],q=q)
    # Un fallo al reingresar inventario revierte documento, caja y solicitud juntos.
    overflow=sell([line(bulk,'1')],[('cash','116')]);data=action(overflow,[{'detalle_venta_id':overflow['detalles'][0]['id'],'cantidad':'1','destino':'resguardo','ubicacion_id':f['d']}]);quote=qrefund(overflow,data)
    admin.request('/inventario/ajustes','POST',{'variante_id':bulk,'ubicacion_id':f['d'],'modo':'conteo','saldo_esperado':'1.25','cantidad':'9999999999.9999','motivo':'QA límite','clave':str(uuid.uuid4())},201)
    before_cash=a.request('/caja/turnos/'+turns[0])['resumen']['esperado'];_,failed=refund(overflow,data,[(0,'116')],q=quote,expected=422)
    assert a.request('/ventas/'+overflow['id']+'/reembolsos')['documentos']==[] and a.request('/caja/turnos/'+turns[0])['resumen']['esperado']==before_cash
    admin.request('/inventario/ajustes','POST',{'variante_id':bulk,'ubicacion_id':f['d'],'modo':'conteo','saldo_esperado':'9999999999.9999','cantidad':'1.25','motivo':'QA restaura','clave':str(uuid.uuid4())},201)
    a.request('/ventas/'+overflow['id']+'/reembolsos','POST',failed,201)
    # Buscar un folio ajeno habilita solo el flujo de reembolso, nunca su ticket general.
    foreign=sell([line(piece,'1')],[('card','116')]);fp='/ventas/'+foreign['id']+'/reembolsos'
    b.request('/ventas/'+foreign['id'],expected=403);b.request(fp+'/contexto',expected=403)
    lookup=b.request('/reembolsos/folio','POST',{'folio':foreign['folio']});assert lookup['venta']['id']==foreign['id'] and lookup['requiere_autorizacion']
    assert 'cliente_nombre' not in lookup['venta'] and 'pagos' not in lookup
    audit.request('/reembolsos/folio','POST',{'folio':foreign['folio']},403)
    b.request('/reembolsos/folio','POST',{'folio':foreign['folio']},403,csrf=False)
    fd=dict(action(foreign,[{'detalle_venta_id':foreign['detalles'][0]['id'],'cantidad':'1','destino':'venta'}],turn=turns[1]),folio=foreign['folio'])
    fq=qrefund(foreign,fd,b);qrefund(foreign,dict(fd,folio=original['folio']),b,403)
    refund(foreign,fd,[(0,'116')],b,fq)
    assert a.request(fp+'/contexto')['venta']['reembolsado']=='116.00'
    history=admin.request(fp)['documentos'];assert history[0]['autorizado_nombre']=='QA reembolsos'
    assert b.request('/sesion')['usuario']['nombre_usuario']==PREFIX+'_b'
    b.request('/ventas/'+foreign['id'],expected=403)
    print('OK: devoluciones parciales/total, resguardo y descartes, servicios, snapshot histórico, cancelación, pagos mixtos, centavos, caja insuficiente, cortes y reintentos, concurrencia y permisos.')
finally:
    sql('$db->beginTransaction();$db->prepare("DELETE s FROM solicitudes_reembolso s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE d FROM devoluciones_venta d JOIN usuarios u ON u.id=d.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_venta s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE v FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE m FROM movimientos_efectivo m JOIN usuarios u ON u.id=m.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM ubicaciones WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM metodos_pago WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM impuestos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Ventas, devoluciones y saldos QA retirados; datos del negocio conservados.')
    limpiar(PREFIX)
