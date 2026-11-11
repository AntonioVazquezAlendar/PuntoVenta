from qa_auditoria import limpiar
"""Pruebas del catálogo con datos QA aislados; no modifica productos del negocio."""
import base64
import copy
import http.cookiejar
import json
from pathlib import Path
import secrets
import subprocess
import urllib.request
import urllib.error

ROOT=Path(__file__).resolve().parents[1]
BASE='http://127.0.0.1:8080/PuntoVenta/public/api'
PREFIX='qa_cat_'+secrets.token_hex(5)
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
        try:r=self.http.open(urllib.request.Request(BASE+path,data=body,headers=headers,method=method),timeout=15)
        except urllib.error.HTTPError as e:r=e
        result=json.load(r);assert r.status==expected,(path,r.status,expected,result)
        if isinstance(result['data'],dict) and 'csrf' in result['data']:self.csrf=result['data']['csrf']
        return result['data']
    def login(self,suffix):
        self.request('/sesion');self.request('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})

try:
    password64=base64.b64encode(PASSWORD.encode()).decode()
    role_id=sql('$q=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'_role",json_encode(array("ver_productos"=>true))));$r=$db->lastInsertId();'
        '$h=password_hash(base64_decode("'+password64+'"),PASSWORD_BCRYPT);$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");'
        '$q->execute(array("QA catálogo admin","'+PREFIX+'_admin",1,$h));$q->execute(array("QA catálogo lector","'+PREFIX+'_reader",$r,$h));echo $r;').strip()
    admin=Client();admin.login('_admin');reader=Client();reader.login('_reader')
    lookups=admin.request('/catalogos');unit=lookups['unidades'][0]['id'];tax=lookups['impuestos'][0]['id']
    permissions={item['clave']:False for item in admin.request('/permisos')};permissions['ver_productos']=True
    cat={'nombre':PREFIX+'_root','categoria_padre_id':'','activo':1}
    category=admin.request('/categorias','POST',cat,201)['id']
    admin.request('/categorias','POST',cat,409)
    sub=admin.request('/categorias','POST',dict(cat,nombre=PREFIX+'_child',categoria_padre_id=category),201)['id']
    admin.request('/categorias/'+str(category),'PUT',dict(cat,categoria_padre_id=sub),422)
    variant={'sku':PREFIX+'-SKU1','nombre':'Azul','precio':'199.1234','costo':'100.0001','stock_minimo':'5','activo':1}
    product={'nombre':PREFIX+'_producto','descripcion':'Prueba transaccional','categoria_id':category,'unidad_base_id':unit,'impuesto_id':tax,'tipo_producto':'estandar','precio_incluye_impuesto':1,'controla_inventario':1,'activo':1,'variantes':[variant]}
    admin.request('/productos','POST',product,403,csrf=False)
    reader.request('/productos','POST',product,403)
    reader.request('/categorias','POST',cat,403)
    reader.request('/productos?todos=1',expected=403)
    bad=copy.deepcopy(product);bad['variantes'][0]['precio']='-1';admin.request('/productos','POST',bad,422)
    bad=copy.deepcopy(product);bad['variantes'][0]['precio']='1.12345';admin.request('/productos','POST',bad,422)
    bad=copy.deepcopy(product);bad['impuesto_id']='999999999';admin.request('/productos','POST',bad,422)
    product_id=admin.request('/productos','POST',product,201)['id']
    def find():return next(p for p in admin.request('/productos?todos=1') if str(p['id'])==str(product_id))
    actual=find();vid=actual['variantes'][0]['id'];assert actual['variantes'][0]['precio']=='199.1234'
    assert actual['variantes'][0]['costo']=='100.0001'
    assert 'costo' not in next(p for p in reader.request('/productos') if str(p['id'])==str(product_id))['variantes'][0]
    bad=copy.deepcopy(product);bad['nombre']=PREFIX+'_rollback';admin.request('/productos','POST',bad,409)
    assert not any(p['nombre']==bad['nombre'] for p in admin.request('/productos?todos=1'))
    product['variantes'][0]['id']=vid
    product['variantes'].append(dict(variant,id=None,sku=PREFIX+'-SKU2',nombre='Negro'))
    admin.request('/productos/'+str(product_id),'PUT',product)
    actual=find();assert len(actual['variantes'])==2 and any(str(v['id'])==str(vid) for v in actual['variantes'])
    product['variantes']=actual['variantes']
    bad=copy.deepcopy(product);bad['variantes'][0]['id']='999999999';admin.request('/productos/'+str(product_id),'PUT',bad,409)
    # Una existencia impide cambiar la unidad base.
    sql('$db->prepare("INSERT INTO existencias (ubicacion_id,variante_id,cantidad) VALUES (1,?,0)")->execute(array("'+str(vid)+'"));')
    bad=copy.deepcopy(product);bad['unidad_base_id']=next(u['id'] for u in lookups['unidades'] if str(u['id'])!=str(unit))
    admin.request('/productos/'+str(product_id),'PUT',bad,409)
    product['activo']=0;admin.request('/productos/'+str(product_id),'PUT',product)
    assert not any(str(p['id'])==str(product_id) for p in reader.request('/productos'))
    assert find()['activo']=='0'
    product['activo']=1;product['variantes'][0]['activo']=0;admin.request('/productos/'+str(product_id),'PUT',product)
    visible=next(p for p in reader.request('/productos') if str(p['id'])==str(product_id));assert len(visible['variantes'])==1
    permissions['editar_productos']=True;admin.request('/roles/'+role_id+'/permisos','PUT',{'permisos':permissions})
    assert 'costo' in next(p for p in reader.request('/productos?todos=1') if str(p['id'])==str(product_id))['variantes'][0]
    product['nombre']=PREFIX+'_editado';reader.request('/productos/'+str(product_id),'PUT',product)
    permissions['editar_productos']=False;admin.request('/roles/'+role_id+'/permisos','PUT',{'permisos':permissions})
    reader.request('/productos/'+str(product_id),'PUT',product,403)
    print('OK: altas/edición/desactivación, variantes y precisión, SKU duplicado con rollback, permisos en vivo, costos restringidos, categorías y ciclos, unidad base protegida.')
finally:
    sql('$db->beginTransaction();'
        '$q=$db->prepare("DELETE e FROM existencias e JOIN variantes_producto v ON v.id=e.variante_id WHERE v.sku LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE p FROM productos p WHERE p.nombre LIKE ?");$q->execute(array("'+PREFIX+'%"));'
        '$q=$db->prepare("DELETE FROM categorias WHERE nombre=?");$q->execute(array("'+PREFIX+'_child"));$q->execute(array("'+PREFIX+'_root"));'
        '$q=$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)");$q->execute(array("'+PREFIX+'_admin","'+PREFIX+'_reader"));'
        '$db->prepare("DELETE FROM roles WHERE nombre=?")->execute(array("'+PREFIX+'_role"));$db->commit();')
    print('Datos QA retirados; catálogo del negocio conservado.')
    limpiar(PREFIX)
