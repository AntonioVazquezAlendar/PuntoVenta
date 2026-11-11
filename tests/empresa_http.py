from qa_auditoria import limpiar
"""Verifica marca pública, permisos, colores y carga de logo. Restaura la configuración original."""
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
PREFIX = 'qa_brand_' + secrets.token_hex(6)
PASSWORD = secrets.token_urlsafe(24)


def sql(code):
    return subprocess.run(['php', '-d', 'extension_dir=C:/AppServ/php7/ext', '-d', 'extension=php_pdo_mysql.dll'],
        input='<?php require "app/Config/Database.php"; $db=(new Database())->connect(); ' + code,
        cwd=ROOT, text=True, encoding='utf-8', capture_output=True, check=True).stdout


class Client:
    def __init__(self):
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.csrf = ''

    def request(self, path, data=None, expected=200, file=None, token=True):
        headers = {}
        body = None
        if data is not None:
            boundary = '----QA' + secrets.token_hex(12)
            chunks = []
            for key, value in data.items():
                chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + key + '"\r\n\r\n' + value + '\r\n').encode())
            if file:
                chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="logo"; filename="' + file[0] + '"\r\nContent-Type: ' + file[1] + '\r\n\r\n').encode() + file[2] + b'\r\n')
            body = b''.join(chunks) + ('--' + boundary + '--\r\n').encode()
            headers['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            if token: headers['X-CSRF-Token'] = self.csrf
        try:
            response = self.opener.open(urllib.request.Request(BASE + path, data=body, headers=headers), timeout=15)
        except urllib.error.HTTPError as error:
            response = error
        raw = response.read()
        assert response.status == expected, (path, response.status, expected, raw[:300])
        return response.headers, raw

    def login(self, name):
        _, body = self.request('/sesion'); self.csrf = json.loads(body)['data']['csrf']
        body = json.dumps({'nombre_usuario': name, 'contrasena': PASSWORD}).encode()
        response = self.opener.open(urllib.request.Request(BASE + '/login', data=body,
            headers={'Content-Type':'application/json', 'X-CSRF-Token':self.csrf}), timeout=15)
        self.csrf = json.load(response)['data']['csrf']


snapshot = sql('echo json_encode($db->query("SELECT nombre_comercial,logo_ruta,color_primario,color_secundario,color_acento FROM empresa WHERE id=1")->fetch());')
saved = json.loads(snapshot)
logos = set()
try:
    password64 = base64.b64encode(PASSWORD.encode()).decode()
    sql('$q=$db->prepare("INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)"); '
        '$hash=password_hash(base64_decode("' + password64 + '"),PASSWORD_BCRYPT); '
        '$q->execute(array("QA marca","' + PREFIX + '_admin",1,$hash)); '
        '$q->execute(array("QA marca cajero","' + PREFIX + '_cashier",3,$hash));')
    data = {'nombre_comercial':'Termos QA', 'color_primario':'#FFFF00','color_secundario':'#102030','color_acento':'#FF8800'}
    anon = Client()
    anon.request('/empresa')
    anon.request('/empresa', data, expected=401)
    admin = Client(); admin.login(PREFIX + '_admin')
    admin.request('/empresa', data, expected=403, token=False)
    cashier = Client(); cashier.login(PREFIX + '_cashier')
    cashier.request('/empresa', data, expected=403)
    bad = dict(data); bad['color_primario'] = 'red; } body{display:none}'
    admin.request('/empresa', bad, expected=422)
    admin.request('/empresa', data, file=('logo.svg','image/svg+xml',b'<svg/>'), expected=422)
    admin.request('/empresa', data, file=('logo.png','image/png',b'no es una imagen'), expected=422)
    admin.request('/empresa', data, file=('logo.png','image/png',b'x' * (2 * 1024 * 1024 + 1)), expected=422)
    png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l9sAAAAASUVORK5CYII=')
    _, body = admin.request('/empresa', data, file=('logo.png','image/png',png))
    record = json.loads(body)['data']; assert record['nombre_comercial'] == 'Termos QA' and record['logo_url']
    logo_path = sql('echo $db->query("SELECT logo_ruta FROM empresa WHERE id=1")->fetchColumn();').strip()
    logos.add(logo_path)
    headers, body = anon.request('/empresa/logo')
    assert headers['Content-Type'] == 'image/png' and body == png
    headers, css = anon.request('/empresa/tema.css')
    assert headers['Content-Type'].startswith('text/css') and b'--on-primary:#000000' in css and b'--on-secondary:#FFFFFF' in css
    admin.request('/empresa', dict(data, quitar_logo='1'))
    anon.request('/empresa/logo', expected=404)
    assert json.loads(anon.request('/empresa')[1])['data']['logo_url'] is None
    print('OK: identidad pública, permisos, CSRF, colores, contraste, validación de archivos, carga y retiro de logo.')
finally:
    encoded = base64.b64encode(snapshot.encode()).decode()
    sql('$r=json_decode(base64_decode("' + encoded + '"),true); '
        '$db->prepare("UPDATE empresa SET nombre_comercial=?,logo_ruta=?,color_primario=?,color_secundario=?,color_acento=? WHERE id=1")->execute(array_values($r)); '
        '$db->prepare("DELETE FROM usuarios WHERE nombre_usuario IN (?,?)")->execute(array("' + PREFIX + '_admin","' + PREFIX + '_cashier"));')
    for logo in logos:
        target = (ROOT / 'storage' / logo).resolve()
        assert target.is_relative_to((ROOT / 'storage/uploads/logos').resolve())
        target.unlink(missing_ok=True)
    print('Configuración restaurada y archivos/cuentas QA retirados.')
    limpiar(PREFIX)
