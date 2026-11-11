from qa_auditoria import limpiar
"""Respaldo real de lectura + revisión HTTP; NUNCA ejecuta una restauración del negocio."""
import sys, re
import base64, http.cookiejar, json, secrets, subprocess, urllib.request, urllib.error
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
STATE=ROOT/'tests/tmp/backup-ui.json'
PREFIX='qa_backup_'+secrets.token_hex(6)
PASSWORD=secrets.token_urlsafe(12)
def sql(code):
 p=subprocess.run(['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'],input='<?php require "app/Config/Database.php";$db=(new Database())->connect();'+code,cwd=ROOT,text=True,capture_output=True,check=True)
 return p.stdout
def cleanup_ui():
 if not STATE.exists():return
 f=json.loads(STATE.read_text(encoding='utf-8'))
 assert re.fullmatch(r'qa_backup_[a-f0-9]{12}',f['prefix'])
 limpiar(f['prefix'])
 for key in ('backup_id','upload_id'):assert re.fullmatch(r'[a-f0-9]{32}',f[key])
 payload=base64.b64encode(json.dumps(f).encode()).decode()
 sql('$f=json_decode(base64_decode("'+payload+'"),true);$db->beginTransaction();$q=$db->prepare("SELECT id,nombre_usuario FROM usuarios WHERE id IN (?,?) FOR UPDATE");$q->execute(array($f["admin_id"],$f["operator_id"]));foreach($q->fetchAll() as $u){if(!in_array($u["nombre_usuario"],array($f["prefix"]."_admin",$f["prefix"]."_op"),true))throw new RuntimeException("QA identity mismatch");}$q=$db->prepare("SELECT nombre FROM roles WHERE id=? FOR UPDATE");$q->execute(array($f["role_id"]));$role=$q->fetchColumn();if($role!==false&&$role!==$f["prefix"])throw new RuntimeException("QA role mismatch");$db->prepare("DELETE FROM usuarios WHERE id IN (?,?)")->execute(array($f["admin_id"],$f["operator_id"]));$db->prepare("DELETE FROM roles WHERE id=?")->execute(array($f["role_id"]));$db->commit();')
 meta=ROOT/'storage/backups'/(f['backup_id']+'.json')
 if meta.exists():assert str(json.loads(meta.read_text(encoding='utf-8'))['usuario_id'])==str(f['admin_id'])
 for ext in ('.zip','.json'):(ROOT/'storage/backups'/(f['backup_id']+ext)).unlink(missing_ok=True)
 for ext in ('.upload','.upload.json'):(ROOT/'storage/backups/staging'/(f['upload_id']+ext)).unlink(missing_ok=True)
 STATE.unlink();print('Fixture UI retirado: solo cuentas, rol y archivos QA; datos del negocio conservados.')
if '--cleanup-ui' in sys.argv:
 cleanup_ui();raise SystemExit(0)
assert not STATE.exists(),'Limpia primero el fixture UI anterior con --cleanup-ui.'
class Client:
 def __init__(self):self.http=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()));self.csrf=''
 def req(self,path,method='GET',data=None,status=200,token=True,raw=None,offset=None):
  h={};body=None
  if method!='GET':
   h['Content-Type']='application/json' if raw is None else 'application/octet-stream';body=json.dumps(data or {}).encode() if raw is None else raw
   if token:h['X-CSRF-Token']=self.csrf
   if offset is not None:h['X-Backup-Offset']=str(offset)
  try:r=self.http.open(urllib.request.Request('http://127.0.0.1:8080/PuntoVenta/public/api'+path,data=body,headers=h,method=method),timeout=40)
  except urllib.error.HTTPError as e:r=e
  contents=r.read();assert r.status==status,(path,r.status,contents[:200])
  if r.headers.get_content_type()=='application/zip':return contents
  result=json.loads(contents);data=result['data']
  if isinstance(data,dict) and 'csrf' in data:self.csrf=data['csrf']
  return data
 def login(self,suffix):self.req('/sesion');self.req('/login','POST',{'nombre_usuario':PREFIX+suffix,'contrasena':PASSWORD})
admin_id=operator_id=role_id=None;backup_id=upload_id=None;keep=False
try:
 pw64=base64.b64encode(PASSWORD.encode()).decode()
 fixture=json.loads(sql('$db->beginTransaction();$q=$db->prepare("INSERT INTO roles(nombre,permisos) VALUES (?,?)");$q->execute(array("'+PREFIX+'","{}"));$rid=$db->lastInsertId();$q=$db->prepare("INSERT INTO usuarios(nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,?,?)");$h=password_hash(base64_decode("'+pw64+'"),PASSWORD_BCRYPT);$q->execute(array("QA respaldos temporal","'+PREFIX+'_admin",1,$h));$a=$db->lastInsertId();$q->execute(array("QA sin acceso respaldos","'+PREFIX+'_op",$rid,$h));$b=$db->lastInsertId();$db->commit();echo json_encode(array($a,$b,$rid));'))
 admin_id,operator_id,role_id=fixture
 a=Client();a.login('_admin');op=Client();op.login('_op');anon=Client();anon.req('/sesion')
 anon.req('/respaldos',status=401);op.req('/respaldos',status=403)
 a.req('/respaldos','POST',{},status=403,token=False)
 for path in ['/respaldos','/respaldos/importar','/respaldos/revisar','/respaldos/restaurar']:
  op.req(path,'POST',{},status=403)
 a.req('/respaldos','DELETE',{},status=405)
 a.req('/respaldos','POST',{'password':'short','confirm_password':'short'},status=422)
 m=a.req('/respaldos','POST',{'password':PASSWORD,'confirm_password':PASSWORD});backup_id=m['id']
 contents=a.req('/respaldos/'+backup_id+'/descargar');assert contents.startswith(b'PK') and len(contents)==m['bytes']
 op.req('/respaldos/'+backup_id+'/descargar',status=403)
 a.req('/respaldos/revisar','POST',{'id':backup_id,'password':'wrong-pass-123'},status=422)
 review=a.req('/respaldos/revisar','POST',{'id':backup_id,'password':PASSWORD});assert review['registros']>0 and 'manifest' not in review and 'work' not in review
 # Deliberately invalid confirmation: must stop before the restore service.
 a.req('/respaldos/restaurar','POST',{'token':review['token'],'password':PASSWORD,'phrase':'NO RESTAURAR','confirm':False},status=422)
 u=a.req('/respaldos/importar','POST',{'nombre':'QA.zip','bytes':len(contents)});upload_id=u['id']
 a.req('/respaldos/importar/'+upload_id+'/parte','POST',raw=contents[:512],offset=0,token=False,status=403)
 for offset in range(0,len(contents),u['chunk_bytes']):a.req('/respaldos/importar/'+upload_id+'/parte','POST',raw=contents[offset:offset+u['chunk_bytes']],offset=offset)
 preview=a.req('/respaldos/revisar','POST',{'import_id':upload_id,'password':PASSWORD});assert preview['sha256']==m['sha256']
 STATE.parent.mkdir(exist_ok=True);STATE.write_text(json.dumps({'prefix':PREFIX,'password':PASSWORD,'admin_id':admin_id,'operator_id':operator_id,'role_id':role_id,'backup_id':backup_id,'upload_id':upload_id}),encoding='utf-8');keep=True
 if '--keep-ui' not in sys.argv:cleanup_ui()
 print('OK: administrador exclusivo, CSRF, métodos, contraseña, creación/descarga cifrada, revisión compatible, confirmación inválida e importación por partes. Ninguna restauración del negocio ejecutada.')
finally:
 if not keep:
  limpiar(PREFIX)
  if admin_id:sql('$db->prepare("DELETE FROM usuarios WHERE id IN (?,?)")->execute(array('+str(admin_id)+','+str(operator_id)+'));$db->prepare("DELETE FROM roles WHERE id=?")->execute(array('+str(role_id)+'));')
  if backup_id:
   for ext in ('.zip','.json'):(ROOT/'storage/backups'/(backup_id+ext)).unlink(missing_ok=True)
  if upload_id:
   for ext in ('.upload','.upload.json'):(ROOT/'storage/backups/staging'/(upload_id+ext)).unlink(missing_ok=True)
