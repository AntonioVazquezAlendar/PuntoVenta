from qa_auditoria import limpiar
"""Prueba permisos con cuentas y roles QA aislados, sin cambiar los roles del negocio."""
import base64
import http.cookiejar
import json
from pathlib import Path
import secrets
import subprocess
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
BASE = 'http://127.0.0.1:8080/PuntoVenta/public/api'
PREFIX = 'qa_perm_' + secrets.token_hex(5)
PASSWORD = secrets.token_urlsafe(24)


def sql(code):
    return subprocess.run(['php', '-d', 'extension_dir=C:/AppServ/php7/ext', '-d', 'extension=php_pdo_mysql.dll'],
        input='<?php require "app/Config/Database.php"; $db=(new Database())->connect(); ' + code,
        cwd=ROOT, text=True, encoding='utf-8', capture_output=True, check=True).stdout


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''

    def request(self, path, method='GET', data=None, expected=200, csrf=True):
        headers = {}
        body = None
        if method != 'GET':
            headers['Content-Type'] = 'application/json'
            if csrf: headers['X-CSRF-Token'] = self.csrf
            body = json.dumps(data or {}).encode()
        try:
            response = self.opener.open(urllib.request.Request(BASE + path, data=body, headers=headers, method=method), timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        result = json.load(response)
        assert response.status == expected, (path, response.status, expected, result)
        if isinstance(result['data'], dict) and 'csrf' in result['data']: self.csrf = result['data']['csrf']
        return result['data']

    def login(self, suffix):
        self.request('/sesion')
        self.request('/login', 'POST', {'nombre_usuario': PREFIX + suffix, 'contrasena': PASSWORD})


try:
    encoded = base64.b64encode(PASSWORD.encode()).decode()
    fixture = json.loads(sql(
        '$db->beginTransaction(); '
        '$r=$db->prepare("INSERT INTO roles (nombre,permisos) VALUES (?,?)"); '
        '$r->execute(array("' + PREFIX + '_rol_a", "{}")); $a=$db->lastInsertId(); '
        '$r->execute(array("' + PREFIX + '_rol_b", "{}")); $b=$db->lastInsertId(); '
        '$hash=password_hash(base64_decode("' + encoded + '"),PASSWORD_BCRYPT); '
        '$u=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)"); '
        '$u->execute(array("QA administrador","' + PREFIX + '_admin",1,$hash)); '
        '$u->execute(array("QA operador A","' + PREFIX + '_a",$a,$hash)); '
        '$u->execute(array("QA operador B","' + PREFIX + '_b",$b,$hash)); '
        '$db->commit(); echo json_encode(array("a"=>$a,"b"=>$b));'))
    anon = Client(); anon.request('/permisos', expected=401)
    admin = Client(); admin.login('_admin')
    catalog = admin.request('/permisos')
    perms = {item['clave']: False for item in catalog}
    path = '/roles/' + fixture['a'] + '/permisos'
    a = Client(); a.login('_a')
    b = Client(); b.login('_b')
    a.request('/permisos', expected=403)
    a.request(path, 'PUT', {'permisos': perms}, expected=403)
    b.request(path, 'PUT', {'permisos': perms}, expected=403)
    admin.request(path, 'PUT', {'permisos': perms}, expected=403, csrf=False)
    admin.request('/roles/1/permisos', 'PUT', {'permisos': perms}, expected=409)
    admin.request(path, 'PUT', {'permisos': dict(perms, todo=True)}, expected=422)
    malformed = dict(perms, ver_productos='true')
    admin.request(path, 'PUT', {'permisos': malformed}, expected=422)
    admin.request(path, 'PUT', {'permisos': dict(perms, dar_descuento=True)}, expected=422)
    a.request('/productos', expected=403)
    perms['ver_productos'] = True
    admin.request(path, 'PUT', {'permisos': perms})
    a.request('/productos')
    b.request('/productos', expected=403)
    assert a.request('/sesion')['usuario']['permisos']['ver_productos'] is True
    a.request('/usuarios', expected=403)
    a.request(path, 'PUT', {'permisos': perms}, expected=403)
    perms['ver_productos'] = False
    admin.request(path, 'PUT', {'permisos': perms})
    a.request('/productos', expected=403)
    assert a.request('/sesion')['usuario']['permisos']['ver_productos'] is False
    assert next(r for r in admin.request('/roles') if str(r['id']) == '1')['permisos']['todo'] is True
    role_data = {'nombre': PREFIX + '_custom', 'permisos': perms}
    a.request('/roles', 'POST', role_data, expected=403)
    b.request('/roles', 'POST', role_data, expected=403)
    admin.request('/roles', 'POST', role_data, expected=403, csrf=False)
    admin.request('/roles', 'POST', {'nombre':'  ', 'permisos':perms}, expected=422)
    admin.request('/roles', 'POST', {'nombre':'x'*61, 'permisos':perms}, expected=422)
    admin.request('/roles', 'POST', {'nombre':PREFIX + '_invalid', 'permisos':dict(perms,todo=True)}, expected=422)
    created = admin.request('/roles', 'POST', role_data, expected=201)
    assert created['nombre'] == role_data['nombre'] and not created['permisos'].get('todo')
    admin.request('/roles', 'POST', role_data, expected=409)
    admin.request('/roles', 'POST', dict(role_data,nombre=role_data['nombre'].upper()), expected=409)
    assert any(str(r['id']) == str(created['id']) for r in admin.request('/roles'))
    user_b = b.request('/sesion')['usuario']
    assignment = {'nombre':user_b['nombre'], 'nombre_usuario':user_b['nombre_usuario'], 'rol_id':created['id'], 'activo':1, 'contrasena':''}
    b.request('/usuarios/' + str(user_b['id']), 'PUT', assignment, expected=403)
    admin.request('/usuarios/' + str(user_b['id']), 'PUT', assignment)
    assert str(b.request('/sesion')['usuario']['rol_id']) == str(created['id'])
    b.request('/productos', expected=403)
    custom_perms = dict(perms,ver_productos=True)
    admin.request('/roles/' + str(created['id']) + '/permisos', 'PUT', {'permisos':custom_perms})
    b.request('/productos')
    b.request('/roles', 'POST', role_data, expected=403)
    print('OK: solo administrador asigna permisos; CSRF; rechazo de escalamiento y datos inválidos; administrador protegido; cambios inmediatos y aislados por rol.')
    print('OK: creación de roles personalizados, nombres duplicados, asignación a usuarios y permisos efectivos en sesiones abiertas.')
finally:
    sql('$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?,?)")->execute(array("' + PREFIX + '_admin","' + PREFIX + '_a","' + PREFIX + '_b")); '
        '$db->prepare("DELETE FROM roles WHERE nombre IN (?,?,?)")->execute(array("' + PREFIX + '_rol_a","' + PREFIX + '_rol_b","' + PREFIX + '_custom"));')
    print('Cuentas y roles QA retirados. Roles del negocio conservados.')
    limpiar(PREFIX)
