"""Portal PHP real y POS en base QA aislada; no inicia Node."""
import base64,concurrent.futures,json,os,shutil,socket,subprocess,sys,tempfile,time,urllib.request,urllib.error,uuid
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1];BASE=ROOT/'tests/tmp';BASE.mkdir(exist_ok=True)
fixture=Path(tempfile.mkdtemp(prefix='pedidos-php-',dir=BASE)).resolve()
php=['php','-d','extension_dir=C:/AppServ/php7/ext','-d','extension=php_pdo_mysql.dll'] if os.name=='nt' else ['php']
def cli(*args):return subprocess.run(php+[str(ROOT/'tests/portal_php.php'),*args],cwd=ROOT,capture_output=True,text=True,check=True).stdout
qa=None;server=None;log=None
try:
    state=json.loads(cli());qa=state['base']
    with socket.socket() as s:s.bind(('127.0.0.1',0));port=s.getsockname()[1]
    (fixture/'sessions').mkdir()
    router="""<?php
$root=ROOTVALUE;$uri=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);if(strpos($uri,'/PuntoVenta/public/assets/')===0)return false;
$code=file_get_contents($root.'/app/Config/Database.php');$code=str_replace(base64_decode('DBPATTERN'),base64_decode('DBVALUE'),$code,$count);if($count!==1)throw new Exception('No se aisló la base QA');$code=str_replace(base64_decode('DEMOPATTERN'),'if (false)',$code);eval(substr($code,5));
require $root.'/app/Helpers/HttpError.php';require $root.'/app/Helpers/Response.php';require $root.'/app/Services/Auth.php';
if($uri==='/PuntoVenta/public/pedidos-clientes'||strpos($uri,'/PuntoVenta/public/pedidos-clientes/')===0){require $root.'/app/Controllers/PortalClienteController.php';try{(new PortalClienteController((new Database())->connect()))->atender($uri,$_SERVER['REQUEST_METHOD']);}catch(HttpError $e){PortalClienteController::json($e->getMessage(),$e->getCode());}catch(Throwable $e){error_log((string)$e);PortalClienteController::json('Error QA.',500);}exit;}
Auth::iniciarSesion();if($uri==='/PuntoVenta/public/'){require $root.'/views/login/index.php';exit;}
try{$code=file_get_contents($root.'/app/Routes/api.php');$code=str_replace('__DIR__',var_export($root.'/app/Routes',true),$code);eval(substr($code,5));}catch(HttpError $e){Response::json(false,$e->getMessage(),array(),$e->getCode());}
""".replace('ROOTVALUE',json.dumps(ROOT.as_posix())).replace('DBPATTERN',base64.b64encode(b"private $database = 'pos_mx';").decode()).replace('DBVALUE',base64.b64encode(("private $database = '"+qa+"';").encode()).decode()).replace('DEMOPATTERN',base64.b64encode(b"if (!($original && PHP_SAPI === 'cli') && is_file($selection))").decode())
    (fixture/'router.php').write_text(router,encoding='utf-8');log=open(fixture/'server.log','w',encoding='utf-8')
    server=subprocess.Popen(php+['-d','session.save_path='+str(fixture/'sessions'),'-S','127.0.0.1:'+str(port),'-t',str(ROOT.parent),str(fixture/'router.php')],cwd=ROOT,stdout=log,stderr=log,creationflags=subprocess.CREATE_NO_WINDOW if os.name=='nt' else 0)
    for _ in range(60):
        try:
            with socket.create_connection(('127.0.0.1',port),timeout=.2):break
        except OSError:time.sleep(.1)
    origin='http://127.0.0.1:'+str(port);portal=origin+'/PuntoVenta/public/pedidos-clientes';checks=0
    def request(path,method='GET',data=None,status=200,headers=None,raw=None):
        global checks
        body=raw if raw is not None else json.dumps(data).encode() if data is not None else None
        req=urllib.request.Request(portal+path,data=body,method=method,headers={'Content-Type':'application/json',**(headers or {})})
        try:r=urllib.request.urlopen(req,timeout=30)
        except urllib.error.HTTPError as e:r=e
        content=r.read();assert r.code==status,(r.code,status,content[:300]);checks+=1
        return json.loads(content)['data'] if r.code<400 and path!='/times' and content.startswith(b'{') else content
    page=request('/');assert b'product-dialog' in page and b'portal-clientes.js' in page
    catalog=request('/api/catalog');assert len(catalog['products'])>2
    public=json.dumps(catalog);assert all(k not in public for k in ['"costo"','"existencias"','"ubicaciones"','"clave"','"installation"'])
    quoted=next(p for p in catalog['products'] if p['id']==state['cotizacion']);assert quoted['quote_only'] and quoted['price_cents'] is None
    order={'request_key':str(uuid.uuid4()),'catalog_version':catalog['version'],'name':'Cliente PHP','phone':'555 000 7777','notes':'Pedido QA','items':[{'id':state['variante'],'quantity':'2'}]}
    receipt=request('/api/orders','POST',order,201);assert receipt['total_cents']==20000
    assert set(receipt)=={'id','folio','total_cents','requires_quote','created'}
    assert request('/api/orders','POST',order,201)['id']==receipt['id']
    request('/api/orders','POST',{**order,'name':'Otra persona'},409)
    request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4()),'phone':'12'},422)
    request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4()),'items':[{'id':state['variante'],'quantity':'0.5'}]},422)
    request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4()),'items':order['items']*2},422)
    request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4()),'website':'spam'},422)
    request('/api/orders','POST',order,403,{'Origin':'https://other.test'})
    request('/api/orders','POST',order,403,{'Sec-Fetch-Site':'cross-site'})
    request('/api/orders','POST',order,415,{'Content-Type':'text/plain'})
    request('/api/orders','POST',status=413,raw=b'x'*32769)
    request('/api/orders',status=405);request('/api/orders/'+receipt['id'],status=404)
    request('/bridge/orders',status=404);request('/imagenes/999999999',status=404)
    q=request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4()),'name':'Cotización PHP','items':[{'id':state['cotizacion'],'quantity':'1'}]},201);assert q['requires_quote'] and q['total_cents']==0
    mixed=request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4()),'name':'Mixto PHP','items':[{'id':state['variante'],'quantity':'1'},{'id':state['cotizacion'],'quantity':'1'}]},201);assert mixed['requires_quote'] and mixed['total_cents']==10000
    cli('precio',qa);request('/api/orders','POST',{**order,'request_key':str(uuid.uuid4())},409);updated=request('/api/catalog');assert updated['version']!=catalog['version']
    assert request('/api/orders','POST',order,201)['id']==receipt['id']
    repeated={**order,'request_key':str(uuid.uuid4()),'catalog_version':updated['version'],'name':'Concurrente PHP'}
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        a,b=list(pool.map(lambda _:request('/api/orders','POST',repeated,201),range(2)))
    assert a['id']==b['id']
    print(cli('verificar',qa),end='')
    if '--ui' not in sys.argv:
        for _ in range(26):request('/api/orders','POST',{**repeated,'request_key':str(uuid.uuid4())},201)
        request('/api/orders','POST',{**repeated,'request_key':str(uuid.uuid4())},429)
    print('OK: '+str(checks)+' solicitudes HTTP del portal PHP sin Node.',flush=True)
    if '--ui' in sys.argv:
        print(json.dumps({'portal':portal+'/','pos':origin+'/PuntoVenta/public/','usuario':'admin','password':'Admin1.1','base':qa}),flush=True)
        input('Enter para retirar la base y el servidor QA: ')
finally:
    if server:server.terminate();server.wait(timeout=10)
    if log:log.close()
    if qa:cli('limpiar',qa)
    assert fixture.parent==BASE.resolve() and fixture.name.startswith('pedidos-php-')
    shutil.rmtree(fixture)
