from qa_auditoria import limpiar
"""Pruebas HTTP locales. Crea cuentas QA aleatorias y elimina solo esas cuentas al terminar.
Ejecutar: python tests/usuarios_http.py
"""
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
PREFIX = 'qa_' + secrets.token_hex(6)
PASSWORD = secrets.token_urlsafe(6)  # Exactamente 8 caracteres para comprobar el nuevo mínimo.
created_ids = []


def db_php(source):
    result = subprocess.run([
        'php', '-d', 'extension_dir=C:/AppServ/php7/ext', '-d', 'extension=php_pdo_mysql.dll'
    ], input='<?php require "app/Config/Database.php"; $db=(new Database())->connect(); ' + source,
        cwd=ROOT, text=True, capture_output=True, check=True)
    return result.stdout


class Client:
    def __init__(self):
        self.jar = http.cookiejar.CookieJar()
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))
        self.csrf = ''

    def request(self, path, method='GET', data=None, expected=200, csrf=True):
        headers = {'Accept': 'application/json'}
        body = None
        if method != 'GET':
            headers['Content-Type'] = 'application/json'
            if csrf:
                headers['X-CSRF-Token'] = self.csrf
            body = json.dumps(data or {}).encode()
        req = urllib.request.Request(BASE + path, data=body, headers=headers, method=method)
        try:
            response = self.http.open(req, timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        result = json.loads(response.read())
        assert response.status == expected, (path, response.status, expected, result)
        assert 'contrasena_hash' not in json.dumps(result), 'Se expuso un hash'
        if isinstance(result['data'], dict) and 'csrf' in result['data']:
            self.csrf = result['data']['csrf']
        return result


def payload(suffix, role, active=1):
    return {'nombre': 'QA temporal ' + suffix, 'nombre_usuario': PREFIX + suffix,
            'contrasena': PASSWORD, 'rol_id': role, 'activo': active}


try:
    admin = Client()
    state = admin.request('/sesion')['data']
    admin.request('/usuarios', expected=401)
    admin.request('/productos', expected=401)
    admin.request('/usuarios', 'POST', {}, expected=403, csrf=False)
    # Si la instalación ya tiene cuentas, crear una cuenta QA por CLI sin tocar las existentes.
    if state['configuracion_inicial']:
        result = admin.request('/configuracion-inicial', 'POST', payload('_admin', 1), expected=201)
        ids = db_php('$q=$db->prepare("SELECT id FROM usuarios WHERE nombre_usuario = ?"); '
                     '$q->execute(array("' + PREFIX + '_admin")); echo $q->fetchColumn();')
        admin_id = ids.strip()
    else:
        data64 = base64.b64encode(json.dumps(payload('_admin', 1)).encode()).decode()
        admin_id = db_php('$d=json_decode(base64_decode("' + data64 + '"),true); '
            '$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,1,?)"); '
            '$q->execute(array($d["nombre"],$d["nombre_usuario"],password_hash($d["contrasena"],PASSWORD_BCRYPT))); echo $db->lastInsertId();').strip()
    created_ids.append(admin_id)
    admin.request('/configuracion-inicial', 'POST', payload('_other', 1), expected=409)
    admin.request('/login', 'POST', {'nombre_usuario': PREFIX + '_admin', 'contrasena': 'incorrecta'}, expected=401)
    admin.request('/login', 'POST', {'nombre_usuario': PREFIX + '_admin', 'contrasena': PASSWORD})
    roles = admin.request('/roles')['data']
    cashier_role = next(r['id'] for r in roles if r['nombre'] == 'Cajero')
    admin.request('/usuarios', 'POST', payload('_bad name', cashier_role), expected=422)
    invalid = payload('_short', cashier_role); invalid['contrasena'] = '1234567'
    admin.request('/usuarios', 'POST', invalid, expected=422)
    cashier = admin.request('/usuarios', 'POST', payload('_cashier', cashier_role), expected=201)['data']
    created_ids.append(cashier['id'])
    admin.request('/usuarios', 'POST', payload('_cashier', cashier_role), expected=409)
    admin.request('/usuarios/' + admin_id, 'PUT', payload('_admin', 1, 0), expected=409)
    admin.request('/usuarios/' + admin_id, 'PUT', payload('_admin', cashier_role), expected=409)
    assert any(str(u['id']) == str(cashier['id']) for u in admin.request('/usuarios')['data'])
    other = Client(); other.request('/sesion')
    login = {'nombre_usuario': PREFIX + '_cashier', 'contrasena': PASSWORD}
    other.request('/login', 'POST', login)
    assert other.request('/productos')['success']
    other.request('/usuarios', expected=403)
    other.request('/roles', expected=403)
    other.request('/usuarios', 'POST', payload('_unauthorized', cashier_role), expected=403)
    # Reset de contraseña revoca sesiones existentes.
    updated = payload('_cashier', cashier_role); updated['contrasena'] = PASSWORD + 'x'
    admin.request('/usuarios/' + str(cashier['id']), 'PUT', updated)
    assert other.request('/sesion')['data']['usuario'] is None
    other.request('/login', 'POST', login, expected=401)
    login['contrasena'] = PASSWORD + 'x'; other.request('/login', 'POST', login)
    updated['activo'] = 0; updated['contrasena'] = ''
    admin.request('/usuarios/' + str(cashier['id']), 'PUT', updated)
    other.request('/usuarios', expected=401)
    other.request('/login', 'POST', login, expected=401)
    admin.request('/logout', 'POST')
    admin.request('/usuarios', expected=401)
    # Cinco fallos bloquean la dirección incluso desde otra sesión.
    admin.request('/sesion')
    admin.request('/login', 'POST', {'nombre_usuario': PREFIX + '_admin', 'contrasena': PASSWORD})
    admin.request('/logout', 'POST')
    fresh = Client(); fresh.request('/sesion')
    for _ in range(5):
        fresh.request('/login', 'POST', {'nombre_usuario': PREFIX + '_none', 'contrasena': PASSWORD}, expected=401)
    fresh.request('/login', 'POST', {'nombre_usuario': PREFIX + '_admin', 'contrasena': PASSWORD}, expected=429)
    print('OK: configuración inicial, login/logout, CSRF, roles, altas, edición, duplicados, validaciones, protección de administrador, revocación de sesión y límite de intentos.')
finally:
    encoded = base64.b64encode(json.dumps(created_ids).encode()).decode()
    # El prefijo aleatorio y los IDs evitan eliminar cuentas ajenas a esta ejecución.
    db_php('$ids=json_decode(base64_decode("' + encoded + '"),true); '
        '$q=$db->prepare("DELETE FROM usuarios WHERE id = ? AND nombre_usuario LIKE ?"); '
        'foreach($ids as $id){$q->execute(array($id,"' + PREFIX + '%"));} '
        '$q=$db->prepare("DELETE FROM intentos_acceso WHERE clave = ?"); '
        'foreach(array("127.0.0.1","::1","::ffff:127.0.0.1") as $ip){$q->execute(array(hash("sha256",$ip)));}')
    print('Cuentas temporales y límite de pruebas eliminados.')
    limpiar(PREFIX)
