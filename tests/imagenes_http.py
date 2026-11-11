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

import struct,zlib

def png(color):
    def chunk(t,d):return struct.pack('>I',len(d))+t+d+struct.pack('>I',zlib.crc32(t+d)&0xffffffff)
    return b'\x89PNG\r\n\x1a\n'+chunk(b'IHDR',struct.pack('>IIBBBBB',2,2,8,2,0,0,0))+chunk(b'IDAT',zlib.compress((b'\0'+bytes(color)*2)*2))+chunk(b'IEND',b'')
RED=png((220,60,50));BLUE=png((40,100,210))
def multipart(client,path,data,files=None,expected=200,csrf=True):
    boundary='qa_'+secrets.token_hex(12);parts=[]
    parts.append(('--'+boundary+'\r\nContent-Disposition: form-data; name="datos"\r\n\r\n'+json.dumps(data)+'\r\n').encode())
    for key,content in (files or {}).items():parts.extend([('--'+boundary+'\r\nContent-Disposition: form-data; name="imagenes['+key+']"; filename="prueba.png"\r\nContent-Type: image/png\r\n\r\n').encode(),content,b'\r\n'])
    parts.append(('--'+boundary+'--\r\n').encode());headers={'Content-Type':'multipart/form-data; boundary='+boundary}
    if csrf:headers['X-CSRF-Token']=client.csrf
    try:r=client.http.open(urllib.request.Request(BASE+path,data=b''.join(parts),headers=headers,method='POST'),timeout=30)
    except urllib.error.HTTPError as e:r=e
    result=json.load(r);assert r.status==expected,(path,r.status,result);return result['data']
def gallery(p,photos,principal=None):
    d=copy.deepcopy(p);d['imagenes']=photos;d['imagen_principal']=principal;return d
try:
    p64=base64.b64encode(PASSWORD.encode()).decode()
    sql('$h=password_hash(base64_decode("'+p64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_read",json_encode(array("ver_productos"=>true))));$read=$db->lastInsertId();$q->execute(array("'+PREFIX+'_sell",json_encode(array("vender"=>true,"operar_caja"=>true))));$sell=$db->lastInsertId();$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");foreach(array("_admin"=>1,"_read"=>$read,"_sell"=>$sell) as $s=>$role){$q->execute(array("QA imágenes","'+PREFIX+'".$s,$role,$h));}')
    admin=Client();admin.login('_admin');reader=Client();reader.login('_read');seller=Client();seller.login('_sell');anon=Client()
    catalogs=admin.request('/catalogos');unit=next(u['id'] for u in catalogs['unidades'] if u['codigo']=='pza');tax=next(t['id'] for t in catalogs['impuestos'] if t['activo'])
    def payload(suffix):return {'nombre':PREFIX+suffix,'descripcion':'Galería de prueba','unidad_base_id':unit,'categoria_id':None,'impuesto_id':tax,'tipo_producto':'estandar','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,'variantes':[{'sku':PREFIX+suffix,'nombre':'QA','costo':'5','precio':'25','stock_minimo':'0','activo':1}],'imagenes_version':catalogs['imagenes_version_vacia']}
    def product(pid):return next(p for p in admin.request('/productos?todos=1') if p['id']==pid)
    a='a'*32;b='b'*32
    # Creación con una imagen: predeterminada automática.
    p1=multipart(admin,'/productos',gallery(payload('_one'),[{'archivo':a}]),{a:RED},201)['id'];p=product(p1)
    assert len(p['imagenes'])==1 and p['imagenes'][0]['principal'] and p['imagen_url']==p['imagenes'][0]['url']
    url=p['imagen_url'];assert admin.http.open(BASE.replace('/api','')+url.replace('/PuntoVenta/public','')).read()==RED
    try:anon.http.open(BASE.replace('/api','')+url.replace('/PuntoVenta/public',''));raise AssertionError('Imagen pública')
    except urllib.error.HTTPError as e:assert e.code==401
    assert seller.http.open(BASE.replace('/api','')+url.replace('/PuntoVenta/public','')).read()==RED
    assert reader.http.open(BASE.replace('/api','')+url.replace('/PuntoVenta/public','')).read()==RED
    p2=admin.request('/productos','POST',payload('_two'),201)['id'];other=product(p2)
    first=p['imagenes'][0]['id'];path='/productos/'+p1
    # Dos imágenes y elección explícita de la segunda.
    multipart(admin,path,gallery(p,[{'id':first},{'archivo':b}],'nuevo:'+b),{b:BLUE});p=product(p1);second=p['imagenes'][1]['id'];assert p['imagenes'][1]['principal'] and not p['imagenes'][0]['principal']
    stale=copy.deepcopy(p);multipart(admin,path,gallery(p,[{'id':first},{'id':second}],'id:'+first));p=product(p1);assert p['imagenes'][0]['principal']
    changed=gallery(stale,[{'id':first},{'id':second}],'id:'+second);changed['nombre']='No debe guardarse';multipart(admin,path,changed,expected=409);assert product(p1)['nombre']==PREFIX+'_one'
    multipart(reader,path,gallery(p,[]),expected=403);multipart(admin,path,gallery(p,[]),expected=403,csrf=False)
    multipart(admin,'/productos/'+p2,gallery(other,[{'id':first}]),expected=403)
    invalid=gallery(p,[{'id':first},{'archivo':a}]);invalid['nombre']='Nombre rechazado';multipart(admin,path,invalid,{a:b'<svg xmlns="http://www.w3.org/2000/svg"></svg>'},422);assert product(p1)['nombre']==PREFIX+'_one' and len(product(p1)['imagenes'])==2
    multipart(admin,path,gallery(p,[{'id':first},{'archivo':a}]),{a:b'x'*2097153},422)
    many={format(i,'032x'):RED for i in range(11)};multipart(admin,path,gallery(p,[{'archivo':key} for key in many]),many,422)
    # Quitar la principal elige la imagen restante automáticamente.
    multipart(admin,path,gallery(p,[{'id':second}]));p=product(p1);assert len(p['imagenes'])==1 and p['imagenes'][0]['principal'] and p['imagen_url'].endswith('/'+second)
    # La principal se entrega al vendedor sin permiso para consultar el catálogo.
    location=json.loads(sql('$db->prepare("INSERT INTO ubicaciones (nombre,disponible_venta) VALUES (?,1)")->execute(array("'+PREFIX+'"));echo json_encode($db->lastInsertId());'))
    box=admin.request('/caja/cajas','POST',{'nombre':PREFIX,'ubicacion_id':location,'activo':1},201)['id'];seller.request('/caja/turnos','POST',{'caja_id':box,'monto_apertura':'0','clave':str(uuid.uuid4())},201)
    context=seller.request('/ventas/contexto');items=seller.request('/ventas/productos?turno_id='+context['turno_id']+'&modo=mosaico')['productos'];item=next(v for v in items if v['sku']==PREFIX+'_one');assert item['imagen_url']==p['imagen_url']
    multipart(admin,path,gallery(p,[]));p=product(p1);assert not p['imagenes'] and p['imagen_url'] is None
    print('OK: una/varias imágenes, principal automática y manual, retirada, permisos, formatos/límites, versiones y rollback; principal en punto de venta.')
finally:
    paths=json.loads(sql('$q=$db->prepare("SELECT f.ruta FROM imagenes_producto f JOIN productos p ON p.id=f.producto_id WHERE p.nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));echo json_encode($q->fetchAll(PDO::FETCH_COLUMN));'))
    sql('$db->beginTransaction();$db->prepare("DELETE FROM productos WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE s FROM solicitudes_caja s JOIN usuarios u ON u.id=s.usuario_id WHERE u.nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE t FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id WHERE c.nombre=?")->execute(array("'+PREFIX+'"));$db->prepare("DELETE FROM cajas WHERE nombre=?")->execute(array("'+PREFIX+'"));$db->prepare("DELETE FROM ubicaciones WHERE nombre=?")->execute(array("'+PREFIX+'"));$db->prepare("DELETE FROM usuarios WHERE nombre_usuario LIKE ?")->execute(array("'+PREFIX+'%"));$db->prepare("DELETE FROM roles WHERE nombre LIKE ?")->execute(array("'+PREFIX+'%"));$db->commit();')
    for path in paths:
        target=(ROOT/'storage'/path).resolve();assert target.is_relative_to(ROOT/'storage'/'uploads'/'productos');target.unlink(missing_ok=True)
    print('Fixtures QA retirados; productos del negocio conservados.')
    limpiar(PREFIX)
