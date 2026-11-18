"""Prueba real Node/SQLite + PHP/MySQL; solo usa bases QA y archivos temporales."""
import os,json,socket,subprocess,tempfile,time,shutil,sys,base64
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'tests/tmp';BASE.mkdir(exist_ok=True)
fixture=Path(tempfile.mkdtemp(prefix='pedidos-',dir=BASE)).resolve()
node=os.environ.get('PEDIDOS_NODE',str(Path.home()/'.cache/codex-runtimes/codex-primary-runtime/dependencies/node/bin/node.exe')) if os.name=='nt' else 'node'
php=['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'] if os.name=='nt' else ['php']
with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
key='qa-only-pedidos-'+('a'*48)
env=dict(os.environ,PORT=str(port),PEDIDOS_DB=str(fixture/'portal.sqlite'),PEDIDOS_BRIDGE_KEY=key)
log=open(fixture/'server.log','w',encoding='utf-8');server=None;qa=None;backend=None
try:
    server=subprocess.Popen([node,str(ROOT/'portal-pedidos/scripts/dev.mjs')],env=env,cwd=ROOT,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
        except OSError:time.sleep(.1)
    result=subprocess.run(php+[str(ROOT/'tests/pedidos.php'),'http://127.0.0.1:'+str(port),key,'--keep'],cwd=ROOT,capture_output=True,text=True)
    print(result.stdout,flush=True)
    if result.returncode:raise AssertionError(result.stderr)
    state=json.loads(result.stdout.strip().splitlines()[-1]);qa=state['base']
    if '--ui' in sys.argv:
        with socket.socket() as s:s.bind(('127.0.0.1',0));pos_port=s.getsockname()[1]
        (fixture/'sessions').mkdir()
        router="""<?php
$root=ROOTVALUE;$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(strpos($uri,'/PuntoVenta/public/assets/')===0)return false;
$code=file_get_contents($root.'/app/Config/Database.php');$code=str_replace(base64_decode('DBPATTERN'),base64_decode('DBVALUE'),$code,$replaced);if($replaced!==1)throw new Exception('No se pudo aislar la base QA');$code=str_replace(base64_decode('DEMOPATTERN'),'if (false)',$code);eval(substr($code,5));
require $root.'/app/Helpers/HttpError.php';require $root.'/app/Helpers/Response.php';require $root.'/app/Services/Auth.php';Auth::iniciarSesion();
if($uri==='/PuntoVenta/public/'){require $root.'/views/login/index.php';exit;}
try{$code=file_get_contents($root.'/app/Routes/api.php');$code=str_replace('__DIR__',var_export($root.'/app/Routes',true),$code);eval(substr($code,5));}catch(HttpError $e){Response::json(false,$e->getMessage(),array(),$e->getCode());}catch(Throwable $e){error_log((string)$e);Response::json(false,'Error de prueba.',array(),500);}
""".replace('ROOTVALUE',json.dumps(ROOT.as_posix())).replace('DBVALUE',base64.b64encode(("private $database = '"+qa+"';").encode()).decode()).replace('DBPATTERN',base64.b64encode(b"private $database = 'pos_mx';").decode()).replace('DEMOPATTERN',base64.b64encode(b"if (!($original && PHP_SAPI === 'cli') && is_file($selection))").decode())
        (fixture/'router.php').write_text(router,encoding='utf-8')
        backend=subprocess.Popen(php+['-d','session.save_path='+str(fixture/'sessions'),'-S','127.0.0.1:'+str(pos_port),'-t',str(ROOT.parent),str(fixture/'router.php')],cwd=ROOT,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
        print(json.dumps({'portal':'http://127.0.0.1:'+str(port),'pos':'http://127.0.0.1:'+str(pos_port)+'/PuntoVenta/public/','usuario':state['usuario'],'password':state['password']}),flush=True)
        input('Enter para retirar la base QA y ambos servidores: ')
finally:
    for proc in (backend,server):
        if proc:proc.terminate();proc.wait(timeout=10)
    log.close()
    if qa:
        assert qa.startswith('qa_pedidos_') and len(qa)==23
        code="<?php require 'app/Config/Database.php';$db=(new Database())->connect();$db->exec('DROP DATABASE `"+qa+"`');"
        subprocess.run(php,input=code,cwd=ROOT,text=True,check=True,capture_output=True)
    assert fixture.parent==BASE.resolve() and fixture.name.startswith('pedidos-')
    shutil.rmtree(fixture)
