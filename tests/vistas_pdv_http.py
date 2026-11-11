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
    sql('$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)")->execute(array("'+PREFIX+'_audit",json_encode(array("ver_ventas"=>true))));$role=$db->lastInsertId();$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");foreach(array("_a"=>1,"_b"=>1,"_audit"=>$role) as $s=>$r){$q->execute(array("QA interfaces","'+PREFIX+'".$s,$r,$h));}')
    a=Client();a.login('_a');b=Client();b.login('_b');audit=Client();audit.login('_audit');anon=Client()
    anon.request('/ventas/productos',expected=401);audit.request('/ventas/productos',expected=403);audit.request('/ventas/preferencias','PUT',{'vista':'mosaico'},403)
    assert a.request('/ventas/contexto')['vista']=='busqueda'
    a.request('/ventas/preferencias','PUT',{'vista':'mosaico'},403,csrf=False);a.request('/ventas/preferencias','PUT',{'vista':'otra'},422)
    a.request('/ventas/preferencias','PUT',{'vista':'mosaico','usuario_id':'1'});assert a.request('/ventas/contexto')['vista']=='mosaico' and b.request('/ventas/contexto')['vista']=='busqueda'
    reopened=Client();reopened.login('_a');assert reopened.request('/ventas/contexto')['vista']=='mosaico'
    location=json.loads(sql('$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,1)")->execute(array("'+PREFIX+'"));echo json_encode($db->lastInsertId());'))
    turns=[]
    for i,client in enumerate([a,b]):
        box=a.request('/caja/cajas','POST',{'nombre':PREFIX+'_box'+str(i),'ubicacion_id':location,'activo':1},201)['id'];turns.append(client.request('/caja/turnos','POST',{'caja_id':box,'monto_apertura':'0','clave':str(uuid.uuid4())},201)['turno_id'])
    catalogs=a.request('/catalogos');data={'nombre':PREFIX+'_menu','descripcion':'QA catálogo grande','categoria_id':None,'unidad_base_id':next(u['id'] for u in catalogs['unidades'] if u['codigo']=='pza'),'impuesto_id':next(i['id'] for i in catalogs['impuestos'] if i['activo']),'tipo_producto':'estandar','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,'variantes':[{'sku':PREFIX+'_'+str(i).zfill(3),'nombre':'Opción '+str(i),'costo':'10','precio':'100','stock_minimo':'0','activo':1} for i in range(60)]}
    pid=a.request('/productos','POST',data,201)['id'];p=next(p for p in a.request('/productos?todos=1') if p['id']==pid)
    context=a.request('/ventas/contexto');assert not context['productos'] and not context['codigos'] and context['vista']=='mosaico'
    root='/ventas/productos?turno_id='+turns[0]
    assert a.request(root)['productos']==[];b.request(root,expected=403);a.request(root+'&despues_de=0&modo=mosaico',expected=422)
    first=a.request(root+'&modo=mosaico&buscar='+PREFIX);assert len(first['productos'])==24 and first['siguiente']
    second=a.request(root+'&modo=mosaico&buscar='+PREFIX+'&despues_de='+first['siguiente']);assert len(second['productos'])==24 and second['siguiente']
    third=a.request(root+'&modo=mosaico&buscar='+PREFIX+'&despues_de='+second['siguiente']);assert len(third['productos'])==12 and third['siguiente'] is None
    ids=[v['id'] for page in [first,second,third] for v in page['productos']];assert len(set(ids))==60
    exact=p['variantes'][50];assert a.request(root+'&codigo='+exact['sku'])['productos'][0]['id']==exact['id']
    code='0000'+str(secrets.randbelow(999999999)).zfill(9);sql('$db->prepare("INSERT INTO codigos_barras (codigo,variante_id,cantidad) VALUES (?,?,6)")->execute(array("'+code+'","'+exact['id']+'"));')
    found=a.request(root+'&codigo='+code);assert len(found['productos'])==1 and found['productos'][0]['cantidad_agregar']=='6.0000'
    assert a.request(root+'&buscar='+PREFIX+'%25')['productos']==[]
    refreshed=a.request(root+'&ids='+','.join(ids[:3]));assert len(refreshed['productos'])==3
    a.request('/ventas/preferencias','PUT',{'vista':'busqueda'});assert reopened.request('/ventas/contexto')['vista']=='busqueda'
    print('OK: catálogo no precargado, páginas 24/24/12 sin duplicados, búsqueda, SKU/código de paquete, caja propia, preferencias persistentes y aisladas, permisos y CSRF.')
finally:
    sql('$db->beginTransaction();$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM cajas WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM ubicaciones WHERE nombre=?")->execute(array("'+PREFIX+'"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    print('Catálogo y preferencias QA retirados; datos del negocio conservados.')
    limpiar(PREFIX)
