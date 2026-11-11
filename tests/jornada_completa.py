"""Jornada completa solicitada: conserva registros PRUEBA para revisión.
Ejecutar solo con --ejecutar y PUNTO_PRUEBA_PASSWORD definido.
--reanudar permite continuar el estado privado de una ejecución interrumpida.
No elimina datos ni usa cajas, productos o cuentas operativas existentes.
"""
import argparse,base64,datetime,hashlib,html,http.cookiejar,json,os,secrets,subprocess,traceback,urllib.request,urllib.error,uuid
from decimal import Decimal
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE='http://127.0.0.1:8080/PuntoVenta/public/api';STORE=ROOT/'storage/pruebas'
ARTIFACTS=Path('C:/Users/Antonio/.codex/visualizations/2026/11/10/01a1c675-2d82-7c03-93e8-9a8d4a9e1ffc')

def sql(code):
    p=subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,encoding='utf-8',capture_output=True)
    if p.returncode:raise RuntimeError('Falló la preparación local: '+p.stdout+p.stderr)
    return json.loads(p.stdout) if p.stdout.strip() else None

def snapshot():
    return sql('''$db->exec("SET time_zone='+00:00'");$db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->beginTransaction();$out=array();foreach($db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t){if($t==='intentos_acceso')continue;$keys=$db->query('SHOW KEYS FROM `'.$t.'` WHERE Key_name=\\'PRIMARY\\'')->fetchAll();usort($keys,function($a,$b){return $a['Seq_in_index']<=>$b['Seq_in_index'];});$pk=array_column($keys,'Column_name');if(!$pk)throw new RuntimeException('Tabla sin clave primaria');$rows=array();foreach($db->query('SELECT * FROM `'.$t.'`')->fetchAll() as $r){$key=array();foreach($pk as $k)$key[]=$r[$k];$rows[base64_encode(json_encode($key))]=hash('sha256',json_encode(array_map(function($v){return $v===null?null:base64_encode((string)$v);},$r)));}$out[$t]=$rows;}$db->commit();echo json_encode($out);''')

class Client:
    def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
    def call(self,path,method='GET',data=None,expected=200):
        headers={'Accept':'application/json'};body=None
        if method!='GET':headers.update({'Content-Type':'application/json','X-CSRF-Token':self.csrf});body=json.dumps(data or {}).encode()
        try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=headers,method=method),timeout=45)
        except urllib.error.HTTPError as e:r=e
        content=json.load(r)
        if r.status!=expected:raise RuntimeError(f'{method} {path}: HTTP {r.status}; {content.get("message")}')
        result=content['data']
        if isinstance(result,dict) and 'csrf' in result:self.csrf=result['csrf']
        return result
    def login(self,name,password):self.call('/sesion');self.call('/login','POST',{'nombre_usuario':name,'contrasena':password})

class Jornada:
    def __init__(self,state,password):self.d=state;self.password=password;self.admin=Client();self.cashier=Client();self.phase='Preparación';self.path=STORE/('jornada-'+state['tag']+'.json')
    def save(self):
        STORE.mkdir(exist_ok=True);(STORE/'.htaccess').write_text('Require all denied\nDeny from all\n',encoding='utf-8');temporary=self.path.with_suffix('.tmp');temporary.write_text(json.dumps(self.d,ensure_ascii=False,indent=2),encoding='utf-8');temporary.replace(self.path)
    def key(self,name):
        if name not in self.d['keys']:self.d['keys'][name]=str(uuid.uuid4());self.save()
        return self.d['keys'][name]
    def request(self,name,client,path,method,data,expected=200):
        if name in self.d['results']:return self.d['results'][name]
        result=client.call(path,method,data,expected);self.d['results'][name]=result;self.save();return result
    def check(self,name,actual,expected):
        ok=actual==expected;self.d['checks']=[c for c in self.d['checks'] if (c['fase'],c['control'])!=(self.phase,name)];self.d['checks'].append({'fase':self.phase,'control':name,'esperado':expected,'obtenido':actual,'correcto':ok});self.save()
        if not ok:raise AssertionError(f'{name}: esperado {expected!r}, obtenido {actual!r}')
    def phase_run(self,name,fn):
        if name in self.d['completed']:return
        self.phase=name;fn();self.d['completed'].append(name);self.save();print('OK: '+name,flush=True)
    @property
    def e(self):return self.d['entities']
    def stock(self,location):
        rows=self.admin.call('/inventario?ubicacion_id='+self.e[location]);return next(r['cantidad'] for r in rows if r['variante_id']==self.e['variant'])
    def prepare(self):
        label=self.d['label'];R=self.request
        perms={p['clave']:False for p in self.admin.call('/permisos')}
        for key in ('vender','ver_ventas','operar_caja','ver_gastos','registrar_gastos','ver_creditos','vender_credito','cobrar_abonos','ver_cuentas_pagar','pagar_proveedores','hacer_devoluciones','cancelar_venta','ver_reportes'):perms[key]=True
        role=R('role',self.admin,'/roles','POST',{'nombre':label+' Cajero','permisos':perms},201);self.e['role']=role['id']
        user=R('cashier',self.admin,'/usuarios','POST',{'nombre':label+' · Cajero','nombre_usuario':self.d['cashier_login'],'rol_id':role['id'],'activo':1,'contrasena':self.password},201);self.e['cashier']=user['id']
        for key,name,sellable in [('warehouse','Bodega',1),('shop','Tienda',1),('damaged','Dañados',0)]:
            out=R(key,self.admin,'/almacen/ubicaciones','POST',{'nombre':label+' · '+name,'descripcion':'Jornada de simulación conservada para revisión.','activo':1,'disponible_venta':sellable},201);self.e[key]=out['id']
        supplier=R('supplier',self.admin,'/proveedores','POST',{'nombre':label+' · Proveedor','contacto':'Proveedor ficticio','telefono':'','correo':'','direccion':'','notas':'PRUEBA. No se realizó una compra ni un pago bancario real.','activo':1},201);self.e['supplier']=supplier['id']
        client=R('client',self.admin,'/clientes','POST',{'nombre':label+' · Cliente','telefono':'','correo':'','direccion':'','notas':'Cliente ficticio de la jornada de prueba.','activo':1,'predeterminado':False},201);self.e['client']=client['id']
        config=self.admin.call('/creditos/clientes/'+client['id']);R('credit_config',self.admin,'/creditos/clientes/'+client['id'],'PUT',{'habilitado':1,'limite':'1000','dias':15,'version':config['version']})
        catalog=self.admin.call('/catalogos');self.e['unit']=next(u['id'] for u in catalog['unidades'] if u['codigo']=='pza' and int(u['activo']));self.e['tax']=next(i['id'] for i in catalog['impuestos'] if Decimal(i['tasa'])==Decimal('.16') and int(i['activo']))
        methods=sql("echo json_encode($db->query('SELECT id,tipo FROM metodos_pago WHERE activo=1 ORDER BY id')->fetchAll());");self.e['methods']={k:next(m['id'] for m in methods if m['tipo']==k) for k in ('efectivo','tarjeta','credito')}
        p=R('product',self.admin,'/productos','POST',{'nombre':label+' · Termo 750 ml','descripcion':'PRUEBA DE JORNADA '+self.d['tag']+'. Mercancía ficticia; no mezclar con operaciones reales.','categoria_id':None,'unidad_base_id':self.e['unit'],'impuesto_id':self.e['tax'],'tipo_producto':'estandar','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,'variantes':[{'sku':'PRUEBA-TERMO-'+self.d['tag'],'nombre':'Negro','costo':'100','precio':'200','stock_minimo':'0','activo':1}]},201);self.e['product']=p['id']
        full=next(p for p in self.admin.call('/productos?todos=1') if p['id']==self.e['product']);self.e['variant']=full['variantes'][0]['id']
        for loc,minimo in [('warehouse','5'),('shop','6')]:R('minimum_'+loc,self.admin,'/inventario/minimos','PUT',{'ubicacion_id':self.e[loc],'variante_id':self.e['variant'],'usar_general':False,'minimo':minimo,'version':0})
        category=R('expense_category',self.admin,'/gastos/categorias','POST',{'nombre':label+' Gastos','activo':1},201);self.e['expense_category']=category['id'];self.save()
    def purchase(self):
        today=self.admin.call('/inicio')['hoy'];self.e['today']=today;due=(datetime.date.fromisoformat(today)+datetime.timedelta(days=15)).isoformat();R=self.request
        body={'proveedor_id':self.e['supplier'],'ubicacion_id':self.e['warehouse'],'estatus':'borrador','factura_proveedor':'PRUEBA-'+self.d['tag'],'notas':'Compra simulada de 20 termos a $100 más IVA; conservada para revisión.','clave':self.key('purchase'),'condicion_pago':'credito','vence':due,'detalles':[{'variante_id':self.e['variant'],'cantidad':'20','costo_unitario':'100','impuesto_id':self.e['tax']}]}
        p=R('purchase',self.admin,'/compras','POST',body,201);self.e['purchase']=p['id'];self.check('Total compra con IVA',p['total'],'2320.00');self.check('Bodega antes de recibir',self.stock('warehouse'),'0.0000')
        p=R('ordered',self.admin,'/compras/'+p['id'],'PUT',dict(body,estatus='pedida',version=p['version']));version=p['version'];p=R('received',self.admin,'/compras/'+p['id']+'/recibir','POST',{'version':version});self.check('Estado de compra',p['estatus'],'recibida');self.check('Bodega tras recepción',self.stock('warehouse'),'20.0000')
        again=self.admin.call('/compras/'+p['id']+'/recibir','POST',{'version':version});self.check('Reintento de recepción no duplica compra',again['id'],p['id']);self.check('Reintento no duplica existencias',self.stock('warehouse'),'20.0000');self.e['purchase_folio']=p['folio'];self.save()
    def transfer(self):
        out=self.request('transfer',self.admin,'/almacen/traspasos','POST',{'origen_id':self.e['warehouse'],'destino_id':self.e['shop'],'variante_id':self.e['variant'],'cantidad':'12','motivo':'PRUEBA: surtir tienda desde bodega.','clave':self.key('transfer')},201);self.e['transfer_folio']=out['folio'];self.check('Bodega después del traspaso',self.stock('warehouse'),'8.0000');self.check('Tienda después del traspaso',self.stock('shop'),'12.0000')
    def open_cash(self):
        box=self.request('box',self.admin,'/caja/cajas','POST',{'nombre':self.d['label']+' · Caja','ubicacion_id':self.e['shop'],'activo':1},201);self.e['box']=box['id']
        out=self.request('opening',self.cashier,'/caja/turnos','POST',{'caja_id':box['id'],'monto_apertura':'500','notas':'PRUEBA: fondo inicial simulado de $500.','clave':self.key('opening')},201);self.e['turn']=out['turno_id'];r=self.cashier.call('/caja/turnos/'+out['turno_id']);self.e['opening_version']=r['resumen']['version'];self.check('Fondo inicial',r['resumen']['esperado'],'500.00');self.save()
    def sell(self,name,qty,payments):
        cart={'turno_id':self.e['turn'],'cliente_id':self.e['client'],'notas':'PRUEBA '+name+' · '+self.d['tag'],'detalles':[{'variante_id':self.e['variant'],'cantidad':str(qty),'descuento':'0'}]};q=self.cashier.call('/ventas/cotizar','POST',cart);pays=[]
        for method,amount,received in payments:pays.append({'metodo_pago_id':self.e['methods'][method],'monto':amount,'recibido':received,'referencia':'PRUEBA simulada'})
        payload=dict(cart,pagos=pays,sello=q['sello'],clave=self.key(name));out=self.request(name,self.cashier,'/ventas','POST',payload,201);ticket=self.cashier.call('/ventas/'+out['venta_id']);self.e[name]=ticket['id'];self.e[name+'_folio']=ticket['folio'];self.check('Total '+name,ticket['total'],format(Decimal(qty)*200,'.2f'));self.save();return ticket,payload
    def sales(self):
        a,p=self.sell('venta_efectivo',3,[('efectivo','600','1000')]);self.check('Cambio en venta de efectivo',a['pagos'][0]['cambio'],'400.00');
        if not any(c['control']=='Reintento de venta conserva folio' for c in self.d['checks']):
            again=self.cashier.call('/ventas','POST',p,201);self.check('Reintento de venta conserva folio',again['venta_id'],a['id'])
        b,_=self.sell('venta_mixta',2,[('efectivo','150','200'),('tarjeta','250',None)]);self.check('Cambio en venta mixta',next(p['cambio'] for p in b['pagos'] if p['tipo']=='efectivo'),'50.00')
        self.sell('venta_credito',2,[('efectivo','100','100'),('credito','300',None)]);self.check('Deuda inicial de cliente',self.cashier.call('/creditos/'+self.e['venta_credito'])['saldo'],'300.00')
        self.sell('venta_cancelar',1,[('efectivo','200','200')]);self.check('Tienda después de cuatro ventas',self.stock('shop'),'4.0000');self.check('Efectivo bruto tras ventas',self.cashier.call('/caja/turnos/'+self.e['turn'])['resumen']['esperado'],'1550.00')
    def payments(self):
        R=self.request;turn=self.e['turn'];cash=self.e['methods']['efectivo'];g=R('expense',self.cashier,'/gastos','POST',{'categoria_id':self.e['expense_category'],'fecha':self.e['today'],'origen':'caja','turno_id':turn,'metodo_pago_id':cash,'monto':'40','descripcion':'PRUEBA: compra de garrafón de agua.','beneficiario':'Proveedor ficticio','referencia':self.d['tag'],'clave':self.key('expense')},201);self.e['expense_folio']=g['folio']
        a=R('installment',self.cashier,'/creditos/'+self.e['venta_credito']+'/abonos','POST',{'turno_id':turn,'metodo_pago_id':cash,'monto':'100','recibido':'200','referencia':'PRUEBA abono de cliente','clave':self.key('installment')},201);self.e['installment_folio']=a['folio'];self.check('Deuda tras abono',self.cashier.call('/creditos/'+self.e['venta_credito'])['saldo'],'200.00')
        p=R('supplier_payment',self.cashier,'/cuentas-pagar/'+self.e['purchase']+'/pagos','POST',{'origen':'caja','turno_id':turn,'metodo_pago_id':cash,'monto':'300','cuenta':'','referencia':self.d['tag'],'notas':'PRUEBA: anticipo simulado al proveedor.','clave':self.key('supplier_payment')},201);self.e['payment_folio']=p['folio'];self.check('Saldo de proveedor',self.admin.call('/cuentas-pagar/'+self.e['purchase'])['saldo'],'2020.00');self.save()
    def refunds(self):
        ticket=self.cashier.call('/ventas/'+self.e['venta_efectivo']);path='/ventas/'+ticket['id']+'/reembolsos';data={'tipo':'devolucion','turno_id':self.e['turn'],'motivo':'PRUEBA: un termo defectuoso, enviado a resguardo.','detalles':[{'detalle_venta_id':ticket['detalles'][0]['id'],'cantidad':'1','destino':'resguardo','ubicacion_id':self.e['damaged']}]};q=self.cashier.call(path+'/cotizar','POST',data);payload=dict(data,sello=q['sello'],clave=self.key('refund'),reembolsos=[{'pago_venta_id':ticket['pagos'][0]['id'],'monto':'200'}]);self.cashier.call(path,'POST',payload,403);self.d['checks'].append({'fase':self.phase,'control':'Devolución sin autorización rechazada','esperado':403,'obtenido':403,'correcto':True})
        r=self.request('refund',self.cashier,path,'POST',dict(payload,autorizacion={'nombre_usuario':self.d['admin_login'],'contrasena':self.password}),201);self.e['refund_folio']=r['folio'];self.check('Un termo en resguardo',self.stock('damaged'),'1.0000')
        ticket=self.cashier.call('/ventas/'+self.e['venta_cancelar']);path='/ventas/'+ticket['id']+'/reembolsos';data={'tipo':'cancelacion','turno_id':self.e['turn'],'motivo':'PRUEBA: cancelación autorizada del cuarto ticket.','detalles':[]};q=self.cashier.call(path+'/cotizar','POST',data);payload=dict(data,sello=q['sello'],clave=self.key('cancellation'),reembolsos=[{'pago_venta_id':ticket['pagos'][0]['id'],'monto':'200'}],autorizacion={'nombre_usuario':self.d['admin_login'],'contrasena':self.password});c=self.request('cancellation',self.cashier,path,'POST',payload,201);self.e['cancellation_folio']=c['folio'];self.check('Venta cancelada',self.cashier.call('/ventas/'+ticket['id'])['estatus'],'cancelada');self.check('Tienda al finalizar devoluciones',self.stock('shop'),'5.0000');self.save()
    def close_cash(self):
        t=self.cashier.call('/caja/turnos/'+self.e['turn']);r=t['resumen'];expected={'fondo_inicial':'500.00','ventas_efectivo':'1050.00','abonos_efectivo':'100.00','reembolsos_efectivo':'400.00','salidas':'40.00','gastos_incluidos_salidas':'40.00','pagos_proveedores':'300.00','esperado':'910.00'}
        for key,value in expected.items():self.check('Corte: '+key,r[key],value)
        self.check('Una sola salida de gasto',len(t['movimientos']),1);self.check('Sin tickets pendientes',int(r['ventas_pendientes']),0)
        self.cashier.call('/caja/turnos/'+self.e['turn']+'/cerrar','POST',{'monto_cierre':'910','version':self.e['opening_version'],'clave':self.key('stale_close')},409);self.d['checks'].append({'fase':self.phase,'control':'Corte con versión antigua rechazado','esperado':409,'obtenido':409,'correcto':True})
        out=self.request('closing',self.cashier,'/caja/turnos/'+self.e['turn']+'/cerrar','POST',{'monto_cierre':'910','notas_cierre':'PRUEBA COMPLETA: efectivo contado de forma simulada; sin faltantes ni sobrantes.','version':r['version'],'clave':self.key('closing')});self.check('Efectivo contado',out['contado'],'910.00');self.check('Diferencia del corte',out['diferencia'],'0.00');closed=self.cashier.call('/caja/turnos/'+self.e['turn']);self.check('Turno cerrado',closed['estatus'],'cerrado');self.d['cut']=closed;self.save()
    def verify(self):
        e=self.e;params='?desde='+e['today']+'&hasta='+e['today']+'&caja_id='+e['box']+'&usuario_id='+e['cashier'];report=self.admin.call('/reportes/ventas'+params);r=report['resumen'];self.check('Reporte: ventas brutas',r['ventas'],'1600.00');self.check('Reporte: devoluciones y cancelación',r['devuelto'],'400.00');self.check('Reporte: ventas netas',r['neto'],'1200.00');self.check('Reporte: costo histórico neto',r['costo'],'600.00');self.check('Reporte: gastos',r['gastos'],'40.00')
        self.check('Inventario final en bodega',self.stock('warehouse'),'8.0000');self.check('Inventario final en tienda',self.stock('shop'),'5.0000');self.check('Inventario final en dañados',self.stock('damaged'),'1.0000');self.check('Crédito pendiente',self.admin.call('/creditos/'+e['venta_credito'])['saldo'],'200.00');self.check('Proveedor pendiente',self.admin.call('/cuentas-pagar/'+e['purchase'])['saldo'],'2020.00')
        alerts=self.admin.call('/inventario/alertas?ubicacion_id='+e['shop']+'&q=PRUEBA-TERMO-'+self.d['tag']);self.check('Una alerta de mínimo para tienda',alerts['total'],1);self.check('Faltante para mínimo de tienda',alerts['filas'][0]['faltante'],'1.0000')
        audit=self.admin.call('/bitacora?desde='+e['today']+'&hasta='+e['today']+'&usuario_id='+e['cashier']);kinds=[r['tipo'] for r in audit['registros']];self.check('Bitácora: un gasto',kinds.count('gasto'),1);self.check('Bitácora: una devolución',kinds.count('devolucion'),1);self.check('Bitácora: una cancelación',kinds.count('cancelacion'),1);self.cashier.call('/bitacora',expected=403)
        before=self.d['cut']['resumen'];after=self.cashier.call('/caja/turnos/'+e['turn'])['resumen'];self.check('Corte cerrado conserva su resumen',after,before)
        self.d['report']=report;self.d['alerts']=alerts;self.d['audit']=audit
        current=snapshot();changes=[]
        for table,records in self.d['baseline'].items():
            for key,digest in (records or {}).items():
                if (current.get(table) or {}).get(key)!=digest:changes.append({'tabla':table,'clave':key})
        self.d['baseline_changes']=changes;self.check('Registros previos del negocio sin cambios',len(changes),0);self.save()
    def report(self):
        title='Prueba completa: compra a corte de caja';e=self.e;esc=lambda x:html.escape(str(x));checks=''.join('<tr><td>'+esc(c['fase'])+'</td><td>'+esc(c['control'])+'</td><td>'+esc('Resumen conservado' if isinstance(c['esperado'],dict) else c['esperado'])+'</td><td>'+esc('Resumen conservado' if isinstance(c['obtenido'],dict) else c['obtenido'])+'</td><td>'+('Correcto' if c['correcto'] else 'REVISAR')+'</td></tr>' for c in self.d['checks'])
        docs=[('Compra recibida',e.get('purchase_folio')),('Traspaso',e.get('transfer_folio')),('Venta efectivo',e.get('venta_efectivo_folio')),('Venta mixta',e.get('venta_mixta_folio')),('Venta crédito',e.get('venta_credito_folio')),('Venta cancelada',e.get('venta_cancelar_folio')),('Devolución',e.get('refund_folio')),('Cancelación',e.get('cancellation_folio')),('Gasto',e.get('expense_folio')),('Abono',e.get('installment_folio')),('Pago al proveedor',e.get('payment_folio')),('Turno cerrado',e.get('turn'))]
        links=''.join('<tr><td>'+esc(k)+'</td><td>'+esc(v or 'Pendiente')+'</td></tr>' for k,v in docs)
        output=ARTIFACTS/('prueba-compra-corte-'+self.d['tag']+'.html');page='''<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'''+title+'''</title><style>body{font:16px/1.55 "Segoe UI",sans-serif;color:#18352e;background:#f5f6f2;margin:0}main{max-width:1100px;margin:auto;padding:40px 24px}h1{font-size:32px;line-height:1.2}h2{margin-top:36px}table{width:100%;border-collapse:collapse;background:white}th,td{text-align:left;padding:12px;border-bottom:1px solid #d8dfd9;overflow-wrap:anywhere}th{background:#e6eee7}small,p{max-width:75ch}strong{color:#176549}.scroll{overflow:auto}code{overflow-wrap:anywhere}@media print{body{background:white}main{padding:0}tr{break-inside:avoid}}</style><main><h1>'''+title+'''</h1><p>'''+esc(self.d['label'])+''' · Estado: <strong>'''+esc(self.d['status'])+'''</strong></p><p>Jornada simulada conservada para revisión. No se ejecutaron cargos de tarjeta, pagos bancarios ni movimientos de dinero real. Sus documentos sí forman parte de los reportes del sistema y llevan la marca PRUEBA.</p><h2>Resultado del corte</h2><p><strong>$500 + $1,050 + $100 − $400 − $40 − $300 = $910.</strong><br>Fondo inicial + ventas en efectivo + abono − reembolsos − gasto − pago al proveedor.<br>Efectivo contado simulado: $910. Diferencia esperada y comprobada: $0.</p><p>Compra: 20 termos por $2,320 con IVA. Traspaso: 12 a tienda. Ventas brutas: $1,600. Devolución y cancelación: $400. Ventas netas: $1,200. Tarjeta: $250. Saldo del cliente: $200. Saldo del proveedor: $2,020.</p><p>Inventario final: bodega 8, tienda 5, dañados 1. Tienda tiene un mínimo de 6: faltante 1.</p><h2>Cómo revisar los registros</h2><p>En PuntoVenta entra a Compras para ver la recepción; Inventario para el traspaso y existencias; Caja para revisar el turno cerrado; Reportes para filtrar por la caja de prueba; Crédito y abonos, Cuentas por pagar y Bitácora para los documentos correspondientes.</p><p>Usuario administrador: <code>'''+esc(self.d['admin_login'])+'''</code><br>Usuario cajero: <code>'''+esc(self.d['cashier_login'])+'''</code><br>La contraseña de prueba se comparte en el chat, no se guarda en este informe.</p><h2>Folios de la jornada</h2><div class="scroll"><table><thead><tr><th>Documento</th><th>Folio o identificador</th></tr></thead><tbody>'''+links+'''</tbody></table></div><h2>Comprobaciones</h2><div class="scroll"><table><thead><tr><th>Etapa</th><th>Control</th><th>Esperado</th><th>Obtenido</th><th>Resultado</th></tr></thead><tbody>'''+checks+'''</tbody></table></div><p>Las filas previas del negocio se compararon mediante huellas SHA-256 antes y después. Se excluyó únicamente el control técnico de intentos de acceso. Los registros nuevos de esta jornada se conservaron por solicitud del usuario.</p></main></html>''';output.write_text(page,encoding='utf-8');self.d['report_path']=str(output);self.save();return output
    def run(self):
        self.admin.login(self.d['admin_login'],self.password)
        self.phase_run('Catálogos y permisos de prueba',self.prepare);self.cashier.login(self.d['cashier_login'],self.password)
        for name,fn in [('Compra y recepción',self.purchase),('Traspaso a tienda',self.transfer),('Apertura de caja',self.open_cash),('Ventas en efectivo, mixta y crédito',self.sales),('Gasto, abono y pago a proveedor',self.payments),('Devolución y cancelación autorizadas',self.refunds),('Corte de caja',self.close_cash),('Conciliación y protección de datos previos',self.verify)]:self.phase_run(name,fn)
        self.d['status']='COMPLETADA';self.d.pop('error',None);self.d.pop('failed_phase',None);self.save();report=self.report();print(json.dumps({'estado':self.d['status'],'comprobaciones':len(self.d['checks']),'turno':self.e['turn'],'caja':self.e['box'],'usuario_admin':self.d['admin_login'],'usuario_cajero':self.d['cashier_login'],'informe':str(report),'estado_privado':str(self.path)},ensure_ascii=False),flush=True)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--ejecutar',action='store_true');parser.add_argument('--reanudar',type=Path);args=parser.parse_args()
    if not args.ejecutar and not args.reanudar:parser.error('Indica --ejecutar para crear una jornada nueva o --reanudar con su archivo privado.')
    password=os.environ.get('PUNTO_PRUEBA_PASSWORD','')
    if len(password)<8:parser.error('Define PUNTO_PRUEBA_PASSWORD con al menos 8 caracteres.')
    if args.reanudar:state=json.loads(args.reanudar.read_text(encoding='utf-8'))
    else:
        tag=datetime.datetime.now().strftime('%y%m%d')+'-'+secrets.token_hex(2).upper();state={'tag':tag,'label':'PRUEBA Jornada '+tag,'admin_login':'prueba_admin_'+tag.lower().replace('-','_'),'cashier_login':'prueba_cajero_'+tag.lower().replace('-','_'),'entities':{},'results':{},'keys':{},'checks':[],'completed':[],'status':'EN CURSO','baseline':snapshot()}
    task=Jornada(state,password);task.save()
    if 'admin' not in task.e:
        data=base64.b64encode(json.dumps({'nombre':state['label']+' · Administrador','login':state['admin_login'],'password':password}).encode()).decode()
        seed=sql('require_once "app/Models/Usuario.php";$d=json_decode(base64_decode("'+data+'"),true);$db->beginTransaction();$roles=$db->query("SELECT id,permisos FROM roles ORDER BY id FOR SHARE")->fetchAll();$rid=null;foreach($roles as $r){$p=json_decode($r["permisos"],true);if(!empty($p["todo"])){$rid=$r["id"];break;}}if(!$rid)throw new RuntimeException("No hay rol administrador");$q=$db->prepare("SELECT id FROM usuarios WHERE nombre_usuario=?");$q->execute(array($d["login"]));$id=$q->fetchColumn();if(!$id){$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?)")->execute(array($d["nombre"],$d["login"],$rid,password_hash($d["password"],PASSWORD_BCRYPT)));$id=$db->lastInsertId();$u=(new Usuario($db))->buscar($id);Auditoria::registrar($db,$id,"usuario_creado","Usuario ".$id,$d["nombre"],null,Auditoria::usuario($u),array("motivo"=>"Preparación de jornada de prueba solicitada"));}$db->commit();echo json_encode(array("id"=>$id));')
        task.e['admin']=seed['id'];task.save()
    try:task.run()
    except Exception as error:
        task.d['status']='REQUIERE REVISIÓN';task.d['error']=str(error);task.d['failed_phase']=task.phase;task.save();print('La jornada quedó conservada para revisar la incidencia en '+str(task.path),flush=True);raise
