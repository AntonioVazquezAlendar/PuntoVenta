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
    f=json.loads(sql('$db->beginTransaction();$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_op",json_encode(array("vender"=>true,"operar_caja"=>true,"cancelar_venta"=>true,"hacer_devoluciones"=>true,"ver_reportes"=>true))));$role=$db->lastInsertId();$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_audit",json_encode(array("ver_reportes"=>true))));$audit=$db->lastInsertId();$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");foreach(array("_admin"=>1,"_a"=>$role,"_b"=>$role,"_audit"=>$audit) as $s=>$r){$q->execute(array("QA reembolsos","'+PREFIX+'".$s,$r,$h));}$q=$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,?)");$q->execute(array("'+PREFIX+'_venta",1));$l=$db->lastInsertId();$q->execute(array("'+PREFIX+'_resguardo",0));$d=$db->lastInsertId();$q=$db->prepare("INSERT INTO impuestos (nombre,tasa) VALUES (?,?)");$q->execute(array("'+PREFIX+'_iva",0.16));$tax=$db->lastInsertId();$q->execute(array("'+PREFIX+'_cero",0));$zero=$db->lastInsertId();$q=$db->prepare("INSERT INTO metodos_pago (nombre,tipo) VALUES (?,?)");$q->execute(array("'+PREFIX+'_cash","efectivo"));$cash=$db->lastInsertId();$q->execute(array("'+PREFIX+'_card","tarjeta"));$card=$db->lastInsertId();$db->commit();echo json_encode(array("role"=>$role,"l"=>$l,"d"=>$d,"tax"=>$tax,"zero"=>$zero,"cash"=>$cash,"card"=>$card));'))
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
    import datetime,io,zipfile,xml.etree.ElementTree as ET
    meta=a.request('/reportes/catalogos');today=meta['hoy'];yesterday=(datetime.date.fromisoformat(today)-datetime.timedelta(days=1)).isoformat()
    assert not meta['todos'] and [x['id'] for x in meta['usuarios']]==[a.request('/sesion')['usuario']['id']]
    assert [x['id'] for x in meta['cajas']]==[boxes[0]]
    anon.request('/reportes/ventas',expected=401)
    a.request('/reportes/ventas?desde=2026-02-30',expected=422)
    a.request('/reportes/ventas?desde=2023-01-01&hasta=2026-01-01',expected=422)
    a.request('/reportes/ventas?desde='+today+'&hasta='+yesterday,expected=422)
    b.request('/reportes/ventas?usuario_id='+meta['usuarios'][0]['id'],expected=403)
    a.request('/reportes/ventas','POST',{},expected=405)
    a.request('/reportes/ventas?pagina=0',expected=422)
    assert audit.request('/reportes/ventas')['total_movimientos']==0
    # Totales y pagos mixtos con devolución parcial y cancelación completa.
    partial=action(original,[ret(piece,'1'),ret(bulk,'0.25','resguardo')]);refund(original,partial,[(0,'100'),(1,'45')])
    cancel=sell([line(piece,'2')],[('cash','232')]);refund(cancel,action(cancel,tipo='cancelacion'),[(0,'232')])
    sql('$db->prepare("UPDATE ventas SET completado_en=? WHERE id=?")->execute(array("'+yesterday+' 12:00:00","'+original['id']+'"));$db->prepare("UPDATE variantes_producto SET costo=999 WHERE id=?")->execute(array("'+piece+'"));$db->prepare("UPDATE detalle_venta SET descripcion=? WHERE variante_id=?")->execute(array("=1+1","'+piece+'"));')
    params='desde='+yesterday+'&hasta='+today+'&caja_id='+boxes[0]
    r=a.request('/reportes/ventas?'+params);s=r['resumen']
    assert s['ventas']=='783.00' and s['devuelto']=='377.00' and s['neto']=='406.00',s
    assert s['sin_impuestos']=='350.00' and s['impuestos']=='56.00' and s['costo']=='200.00' and s['utilidad']=='150.00',s
    assert int(s['tickets'])==2 and int(s['reembolsos'])==2 and r['total_movimientos']==4
    pays={p['tipo_metodo']:p for p in r['pagos']};assert pays['efectivo']['neto']=='200.00' and pays['tarjeta']['neto']=='206.00'
    assert sum(Decimal(p['neto']) for p in r['productos'])==Decimal('406')
    byday={d['dia']:d for d in r['dias']};assert byday[yesterday]['neto']=='551.00' and byday[today]['neto']=='-145.00'
    assert a.request('/reportes/ventas?desde='+today+'&hasta='+today)['resumen']['neto']=='-145.00'
    assert b.request('/reportes/ventas?'+params)['total_movimientos']==0
    assert admin.request('/reportes/ventas?'+params+'&usuario_id='+meta['usuarios'][0]['id'])['resumen']==s
    a.request('/ventas/'+cancel['id'])
    # XLSX real, siete hojas y textos literales sin inyección de fórmulas.
    response=a.http.open(BASE+'/reportes/ventas/excel?'+params);assert response.headers['Content-Type'].startswith('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
    book=zipfile.ZipFile(io.BytesIO(response.read()));ns={'m':'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    assert len(ET.fromstring(book.read('xl/workbook.xml')).find('m:sheets',ns))==10
    for name in book.namelist():
        if name.endswith('.xml'):ET.fromstring(book.read(name))
    texts=b''.join(book.read(name) for name in book.namelist() if name.startswith('xl/worksheets/'))
    assert b'<f>' not in texts and b'=1+1' in texts and b'<v>406.00</v>' in texts
    empty=b.request('/reportes/ventas?'+params);assert empty['resumen']['neto']=='0.00' and not empty['dias']
    # El reembolso de una venta ajena se atribuye a quien lo registra, sin exponer esa venta.
    foreign=sell([line(piece,'1')],[('card','116')]);fd=dict(action(foreign,[{'detalle_venta_id':foreign['detalles'][0]['id'],'cantidad':'1','destino':'venta'}],turn=turns[1]),folio=foreign['folio']);refund(foreign,fd,[(0,'116')],b)
    own=b.request('/reportes/ventas');assert own['resumen']['neto']=='-116.00' and own['total_movimientos']==1 and own['movimientos'][0]['tipo']=='devolucion'
    b.request('/ventas/'+foreign['id'],expected=403)
    # Paginación y ventas gratuitas: no se omiten tickets de importe cero.
    for _ in range(31):sell([line(free,'1')],[],b,turns[1])
    p1=b.request('/reportes/ventas');p2=b.request('/reportes/ventas?pagina=2')
    assert p1['total_movimientos']==32 and p1['paginas']==2 and len(p1['movimientos'])==30 and len(p2['movimientos'])==2
    assert not {(x['tipo'],x['id']) for x in p1['movimientos']} & {(x['tipo'],x['id']) for x in p2['movimientos']}
    # Revocar el permiso se aplica también a exportación y catálogos.
    sql('$db->prepare("UPDATE roles SET permisos=JSON_REMOVE(permisos,\'$.ver_reportes\') WHERE id=?")->execute(array("'+f['role']+'"));')
    a.request('/reportes/ventas',expected=403);a.request('/reportes/catalogos',expected=403)
    try:a.http.open(BASE+'/reportes/ventas/excel?'+params);assert False
    except urllib.error.HTTPError as e:assert e.code==403
    print('OK: fechas CDMX, filtros, alcance por usuario, netos, utilidad histórica, pagos mixtos, cancelación/devolución por fecha, Excel válido y revocación de permiso.')

finally:
    sql('$db->beginTransaction();$db->prepare("DELETE s FROM solicitudes_reembolso s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE d FROM devoluciones_venta d JOIN usuarios u ON u.id=d.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_venta s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE m FROM movimientos_inventario m JOIN variantes_producto v ON v.id=m.variante_id WHERE v.sku LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE v FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE a FROM ajustes_inventario a JOIN usuarios u ON u.id=a.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE m FROM movimientos_efectivo m JOIN usuarios u ON u.id=m.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM ubicaciones WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM metodos_pago WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM impuestos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Ventas, devoluciones y saldos QA retirados; datos del negocio conservados.')
    limpiar(PREFIX)
