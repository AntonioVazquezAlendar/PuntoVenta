<?php

// Rutas del API

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

$base = '/PuntoVenta/public/api';
if ($uri !== $base && strpos($uri, $base . '/') !== 0) {
    Response::json(false, 'Ruta base no encontrada', array(), 404);
}

$path = substr($uri, strlen($base));

$configMethod=preg_match('#^/configuracion/metodos/([1-9][0-9]*)$#D',$path,$configMatch);
if(in_array($path,array('/configuracion','/configuracion/metodos'),true)||$configMethod){
    $allowed=$configMethod?array('PUT'):($path==='/configuracion'?array('GET','PUT'):array('POST'));
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));throw new HttpError('Método no permitido.',405);}
    $db=(new Database())->connect();$actor=(new Auth($db))->administrador();require_once __DIR__.'/../Models/ConfiguracionAdmin.php';$model=new ConfiguracionAdmin($db);
    if($method==='GET')Response::json(true,'Configuración administrativa.',$model->obtener());
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,16385);if(strlen($raw)>16384)throw new HttpError('La configuración supera el tamaño permitido.',413);$data=json_decode($raw,true);if(!is_array($data)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);
    $result=$path==='/configuracion'?$model->guardar($data,$actor['id']):$model->metodo($configMethod?$configMatch[1]:null,$data,$actor['id']);Response::json(true,'Configuración guardada.',$result);
}

$draftRoute=preg_match('#^/ventas/pendientes/([a-f0-9-]{36})(?:/(preparar-cobro|liberar-cobro|descartar))?$#D',$path,$draftMatch);
if($path==='/ventas/pendientes'||$draftRoute){
    $action=$draftRoute&&isset($draftMatch[2])?$draftMatch[2]:null;$allowed=$action?array('POST'):($draftRoute?array('GET','PUT'):array('GET'));if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));throw new HttpError('Método no permitido.',405);}
    $db=(new Database())->connect();$a=(new Auth($db))->usuario();if(empty($a['permisos']['todo'])&&empty($a['permisos']['vender'])&&empty($a['permisos']['ver_ventas']))throw new HttpError('No tienes permiso para consultar tickets pendientes.',403);require_once __DIR__.'/../Models/BorradorVenta.php';$m=new BorradorVenta($db);
    if($method==='GET')Response::json(true,'Tickets pendientes.',$draftRoute?$m->obtener($draftMatch[1],$a):$m->listar($a,$_GET));Auth::csrf();$raw=file_get_contents('php://input',false,null,0,196609);if(strlen($raw)>196608)throw new HttpError('El ticket supera el tamaño permitido.',413);$d=json_decode($raw,true);if(!is_array($d)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);
    $r=$method==='PUT'?$m->guardar($draftMatch[1],$d,$a['id']):($action==='preparar-cobro'?$m->preparar($draftMatch[1],$d,$a['id']):$m->terminar($draftMatch[1],$d,$a['id'],$action==='descartar'));Response::json(true,'Ticket actualizado.',$r);
}

if(in_array($path,array('/bitacora','/bitacora/catalogos'),true)||preg_match('#^/bitacora/([1-9][0-9]*)$#D',$path,$auditMatch)){
    if($method!=='GET'){header('Allow: GET');throw new HttpError('La bitácora solo permite consultar registros.',405);}
    $db=(new Database())->connect();(new Auth($db))->administrador();require_once __DIR__.'/../Models/Bitacora.php';$model=new Bitacora($db);Response::json(true,'Actividad consultada.',$path==='/bitacora/catalogos'?$model->catalogos():($path==='/bitacora'?$model->listar($_GET):$model->detalle($auditMatch[1])));
}

if($path==='/inicio'){
    if($method!=='GET'){header('Allow: GET');throw new HttpError('Método no permitido.',405);}
    $db=(new Database())->connect();$actor=(new Auth($db))->administrador();require_once __DIR__.'/../Models/Inicio.php';Response::json(true,'Resumen del negocio.',(new Inicio($db))->obtener($actor));
}

if(preg_match('#^/respaldos(?:/(importar|revisar|restaurar)|/([a-f0-9]{32})/(descargar)|/importar/([a-f0-9]{32})/(parte))?$#D',$path,$bm)){
    $action=isset($bm[5])&&$bm[5]!==''?'parte':(isset($bm[3])&&$bm[3]!==''?'descargar':(isset($bm[1])&&$bm[1]!==''?$bm[1]:($method==='GET'?'listar':'crear')));
    $allowed=in_array($action,array('listar','descargar'),true)?'GET':'POST';if($method!==$allowed){header('Allow: '.$allowed);throw new HttpError('Método no permitido.',405);}
    $d=array();if($method==='POST'){Auth::csrf();if($action!=='parte'){$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new HttpError('Solicitud demasiado grande.',413);$d=json_decode($raw,true);if(!is_array($d)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);}}
    require_once __DIR__.'/../Controllers/RespaldoController.php';(new RespaldoController((new Database())->connect()))->ejecutar($action,isset($bm[4])&&$bm[4]!==''?$bm[4]:(isset($bm[2])?$bm[2]:null),$d);
}

$gastoRuta=preg_match('#^/gastos/([1-9][0-9]*)$#D',$path,$gastoMatch);
$gastoCategoria=preg_match('#^/gastos/categorias/([1-9][0-9]*)$#D',$path,$gastoCatMatch);
if(in_array($path,array('/gastos','/gastos/catalogos','/gastos/categorias'),true)||$gastoRuta||$gastoCategoria){
    $allowed=$gastoCategoria?array('PUT'):($gastoRuta||$path==='/gastos/catalogos'?array('GET'):array('GET','POST'));if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));throw new HttpError('Método no permitido.',405);}
    $datos=array();if($method!=='GET'){Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new HttpError('Solicitud demasiado grande.',413);$datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);}
    $accion=$gastoCategoria||($path==='/gastos/categorias'&&$method==='POST')?'categoria':($gastoRuta?'detalle':($path==='/gastos/catalogos'?'catalogos':($path==='/gastos/categorias'?'categorias':($method==='POST'?'registrar':'listar'))));
    require_once __DIR__.'/../Controllers/GastoController.php';(new GastoController((new Database())->connect()))->ejecutar($accion,$gastoRuta?$gastoMatch[1]:($gastoCategoria?$gastoCatMatch[1]:null),$datos);
}

$cxpRuta=preg_match('#^/cuentas-pagar/([1-9][0-9]*)(?:/(pagos|conciliar))?$#D',$path,$cxpMatch);
if(in_array($path,array('/cuentas-pagar','/cuentas-pagar/catalogos'),true)||$cxpRuta){
    $accion=$cxpRuta?(isset($cxpMatch[2])?($cxpMatch[2]==='pagos'?'pagar':'conciliar'):'detalle'):($path==='/cuentas-pagar/catalogos'?'catalogos':'listar');$allowed=in_array($accion,array('pagar','conciliar'),true)?'POST':'GET';if($method!==$allowed){header('Allow: '.$allowed);throw new HttpError('Método no permitido.',405);}
    $datos=array();if($method==='POST'){Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new HttpError('Solicitud demasiado grande.',413);$datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);}
    require_once __DIR__.'/../Controllers/CuentaPagarController.php';(new CuentaPagarController((new Database())->connect()))->ejecutar($accion,$cxpRuta?$cxpMatch[1]:null,$datos);
}

$creditoRuta=preg_match('#^/creditos/([1-9][0-9]*)(?:/(abonos))?$#D',$path,$creditoMatch);
$configCredito=preg_match('#^/creditos/clientes/([1-9][0-9]*)$#D',$path,$configMatch);
if(in_array($path,array('/creditos','/creditos/clientes'),true)||$creditoRuta||$configCredito){
    $abono=$creditoRuta&&isset($creditoMatch[2]);$allowed=$abono?array('POST'):($configCredito?array('GET','PUT'):array('GET'));
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));throw new HttpError('Método no permitido.',405);}
    $datos=array();if($method!=='GET'){Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new HttpError('Solicitud demasiado grande.',413);$datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);}
    $accion=$abono?'abonar':($creditoRuta?'detalle':($configCredito?($method==='GET'?'configuracion':'guardar'):($path==='/creditos/clientes'?'clientes':'listar')));
    require_once __DIR__.'/../Controllers/CreditoController.php';(new CreditoController((new Database())->connect()))->ejecutar($accion,$creditoRuta?$creditoMatch[1]:($configCredito?$configMatch[1]:null),$datos);
}

if(in_array($path,array('/reportes/ventas','/reportes/catalogos','/reportes/ventas/excel'),true)){
    if($method!=='GET'){header('Allow: GET');throw new HttpError('Método no permitido.',405);}
    require_once __DIR__.'/../Controllers/ReporteController.php';(new ReporteController((new Database())->connect()))->consultar($path==='/reportes/catalogos'?'catalogos':($path==='/reportes/ventas/excel'?'excel':'ventas'));
}

$clienteRuta=preg_match('#^/clientes/([1-9][0-9]*)(?:/(historial))?$#D',$path,$clienteMatch);
if(in_array($path,array('/clientes','/clientes/buscar'),true)||$clienteRuta){
    $historial=$clienteRuta&&isset($clienteMatch[2]);$allowed=$path==='/clientes/buscar'||$historial?array('GET'):($clienteRuta?array('GET','PUT'):array('GET','POST'));
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));throw new HttpError('Método no permitido.',405);}
    require_once __DIR__.'/../Controllers/ClienteController.php';$controller=new ClienteController((new Database())->connect());
    if($method==='GET'){if($path==='/clientes/buscar')$controller->buscar();elseif($historial)$controller->historial($clienteMatch[1]);elseif($clienteRuta)$controller->obtener($clienteMatch[1]);else $controller->listar();}
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new HttpError('La solicitud es demasiado grande.',413);$datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);
    $controller->guardar($datos,$clienteRuta?$clienteMatch[1]:null);
}

if($path==='/reembolsos/folio'){
    if($method!=='POST'){header('Allow: POST');throw new HttpError('Método no permitido.',405);}
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,2049);if(strlen($raw)>2048)throw new HttpError('Solicitud demasiado grande.',413);$datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);
    require_once __DIR__.'/../Controllers/VentaController.php';(new VentaController((new Database())->connect()))->reembolsoFolio($datos);
}

if(preg_match('#^/ventas/([1-9][0-9]*)/reembolsos(?:/(contexto|cotizar))?$#D',$path,$refundMatch)){
    $accion=isset($refundMatch[2])?$refundMatch[2]:null;$allowed=$accion==='contexto'?array('GET'):($accion==='cotizar'?array('POST'):array('GET','POST'));
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));throw new HttpError('Método no permitido.',405);}
    require_once __DIR__.'/../Controllers/VentaController.php';$controller=new VentaController((new Database())->connect());
    if($method==='GET')$controller->reembolsos($refundMatch[1],$accion==='contexto'?'contexto':'listar');
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536)throw new HttpError('Solicitud demasiado grande.',413);$datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);
    $controller->reembolsos($refundMatch[1],$accion,$datos);
}

$ventaRuta=preg_match('#^/ventas/([1-9][0-9]*)(?:/(logo))?$#D',$path,$ventaMatch);
if(in_array($path,array('/ventas','/ventas/contexto','/ventas/productos','/ventas/existencias','/ventas/preferencias','/ventas/cotizar'),true)||$ventaRuta){
    $allowed=$path==='/ventas/preferencias'?array('PUT'):($ventaRuta||in_array($path,array('/ventas/contexto','/ventas/productos','/ventas/existencias'),true)?array('GET'):($path==='/ventas/cotizar'?array('POST'):array('GET','POST')));
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));Response::json(false,'Método no permitido',array(),405);}
    require_once __DIR__.'/../Controllers/VentaController.php';$controller=new VentaController((new Database())->connect());
    if($method==='GET'){
        if($path==='/ventas/contexto'){$controller->contexto();}
        elseif($path==='/ventas/productos'){$controller->productos();}
        elseif($path==='/ventas/existencias'){$controller->existencias();}
        elseif($ventaRuta){if(isset($ventaMatch[2])){$controller->logo($ventaMatch[1]);}else{$controller->obtener($ventaMatch[1]);}}
        else{$controller->listar();}
    }
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,65537);if(strlen($raw)>65536){throw new HttpError('La solicitud es demasiado grande.',413);}
    $datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{'){throw new HttpError('Envía un objeto JSON válido.',400);}
    if($path==='/ventas/preferencias'){$controller->preferencias($datos);}
    if($path==='/ventas/cotizar'){$controller->cotizar($datos);}else{$controller->cobrar($datos);}
}

$cajaRuta=preg_match('#^/caja/cajas/([1-9][0-9]*)$#D',$path,$cajaMatch);
$turnoRuta=preg_match('#^/caja/turnos/([1-9][0-9]*)(?:/(movimientos|cerrar))?$#D',$path,$turnoMatch);
if(in_array($path,array('/caja/catalogos','/caja/cajas','/caja/turnos'),true)||$cajaRuta||$turnoRuta){
    $accion=$turnoRuta&&isset($turnoMatch[2])?$turnoMatch[2]:null;
    $allowed=$cajaRuta?array('PUT'):($turnoRuta?($accion?array('POST'):array('GET')):($path==='/caja/turnos'?array('GET','POST'):($path==='/caja/cajas'?array('POST'):array('GET'))));
    if(!in_array($method,$allowed,true)){header('Allow: '.implode(', ',$allowed));Response::json(false,'Método no permitido',array(),405);}
    require_once __DIR__.'/../Controllers/CajaController.php';$controller=new CajaController((new Database())->connect());
    if($method==='GET'){
        if($path==='/caja/catalogos'){$controller->catalogos();}
        elseif($turnoRuta){$controller->obtener($turnoMatch[1]);}
        else{$controller->listar();}
    }
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);
    if(strlen($raw)>8192){throw new HttpError('La solicitud es demasiado grande.',413);}
    $datos=json_decode($raw,true);if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{'){throw new HttpError('Envía un objeto JSON válido.',400);}
    if($cajaRuta||$path==='/caja/cajas'){$controller->guardar($datos,$cajaRuta?$cajaMatch[1]:null);}
    elseif($turnoRuta){$controller->operar($turnoMatch[1],$datos,$accion==='movimientos'?'movimiento':'cerrar');}
    else{$controller->abrir($datos);}
}

$ubicacionRuta=preg_match('#^/almacen/ubicaciones/([1-9][0-9]*)$#D',$path,$ubicacionMatch);
if(in_array($path,array('/almacen/operaciones','/almacen/ubicaciones','/almacen/traspasos','/almacen/mermas'),true)||$ubicacionRuta){
    $allowed=$ubicacionRuta?'PUT':($path==='/almacen/operaciones'?'GET':'POST');
    if($method!==$allowed){header('Allow: '.$allowed);Response::json(false,'Método no permitido',array(),405);}
    require_once __DIR__.'/../Controllers/AlmacenController.php';$controller=new AlmacenController((new Database())->connect());
    if($method==='GET'){$controller->listar();}
    Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);
    if(strlen($raw)>8192){throw new HttpError('La solicitud es demasiado grande.',413);}
    $datos=json_decode($raw,true);
    if(!is_array($datos)||substr(ltrim($raw),0,1)!=='{'){throw new HttpError('Envía un objeto JSON válido.',400);}
    if($ubicacionRuta||$path==='/almacen/ubicaciones'){$controller->ubicacion($datos,$ubicacionRuta?$ubicacionMatch[1]:null);}
    else{$controller->registrar($datos,$path==='/almacen/traspasos'?'traspaso':'merma');}
}

$compraRuta = preg_match('#^/compras/([1-9][0-9]*)(?:/(recibir|cancelar))?$#D', $path, $compraMatch);
$proveedorRuta = preg_match('#^/proveedores/([1-9][0-9]*)$#D', $path, $proveedorMatch);
if (in_array($path, array('/compras', '/compras/catalogos', '/proveedores'), true) || $compraRuta || $proveedorRuta) {
    $accion = $compraRuta && isset($compraMatch[2]) ? $compraMatch[2] : null;
    $allowed = $path === '/compras/catalogos' ? array('GET') : ($proveedorRuta ? array('PUT') : ($compraRuta ? ($accion ? array('POST') : array('GET', 'PUT')) : array('GET', 'POST')));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        Response::json(false, 'Método no permitido', array(), 405);
    }
    require_once __DIR__ . '/../Controllers/CompraController.php';
    $controller = new CompraController((new Database())->connect());
    if ($method === 'GET') {
        if ($path === '/compras/catalogos') {
            $controller->catalogos();
        } elseif ($path === '/proveedores') {
            $controller->proveedores();
        } elseif ($compraRuta) {
            $controller->obtener($compraMatch[1]);
        } else {
            $controller->index();
        }
    }
    Auth::csrf();
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        throw new HttpError('La solicitud es demasiado grande.', 413);
    }
    $datos = json_decode($raw, true);
    if (!is_array($datos) || substr(ltrim($raw), 0, 1) !== '{') {
        throw new HttpError('Envía un objeto JSON válido.', 400);
    }
    if ($proveedorRuta || $path === '/proveedores') {
        $controller->proveedor($datos, $proveedorRuta ? $proveedorMatch[1] : null);
    } elseif ($accion) {
        $controller->estado($compraMatch[1], $datos, $accion);
    } else {
        $controller->guardar($datos, $compraRuta ? $compraMatch[1] : null);
    }
}

if(in_array($path,array('/inventario/alertas','/inventario/alertas/catalogos','/inventario/alertas/csv','/inventario/minimos'),true)){
    $allowed=$path==='/inventario/minimos'?'PUT':'GET';if($method!==$allowed){header('Allow: '.$allowed);throw new HttpError('Método no permitido.',405);}
    $d=array();if($method==='PUT'){Auth::csrf();$raw=file_get_contents('php://input',false,null,0,8193);if(strlen($raw)>8192)throw new HttpError('Solicitud demasiado grande.',413);$d=json_decode($raw,true);if(!is_array($d)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);}
    require_once __DIR__.'/../Controllers/AlertaInventarioController.php';$action=$path==='/inventario/minimos'?'guardar':($path==='/inventario/alertas/catalogos'?'catalogos':($path==='/inventario/alertas/csv'?'csv':'listar'));(new AlertaInventarioController((new Database())->connect()))->ejecutar($action,$d);
}

if (in_array($path, array('/inventario', '/inventario/ubicaciones', '/inventario/movimientos', '/inventario/ajustes'), true)) {
    $allowed = $path === '/inventario/ajustes' ? 'POST' : 'GET';
    if ($method !== $allowed) {
        header('Allow: ' . $allowed);
        Response::json(false, 'Método no permitido', array(), 405);
    }
    require_once __DIR__ . '/../Controllers/InventarioController.php';
    $controller = new InventarioController((new Database())->connect());
    if ($method === 'GET') {
        if ($path === '/inventario/ubicaciones') {
            $controller->ubicaciones();
        } elseif ($path === '/inventario/movimientos') {
            $controller->historial();
        } else {
            $controller->index();
        }
    }
    Auth::csrf();
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if (strlen($raw) > 8192) {
        throw new HttpError('La solicitud es demasiado grande.', 413);
    }
    $datos = json_decode($raw, true);
    if (!is_array($datos) || substr(ltrim($raw), 0, 1) !== '{') {
        throw new HttpError('Envía un objeto JSON válido.', 400);
    }
    $controller->ajustar($datos);
}

if (in_array($path, array('/empresa', '/empresa/logo', '/empresa/tema.css'), true)) {
    $allowed = $path === '/empresa' ? array('GET', 'POST') : array('GET');
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        Response::json(false, 'Método no permitido', array(), 405);
    }
    require_once __DIR__ . '/../Controllers/EmpresaController.php';
    $controller = new EmpresaController((new Database())->connect());
    if ($path === '/empresa/logo') {
        $controller->logo();
    } elseif ($path === '/empresa/tema.css') {
        $controller->estilos();
    } elseif ($method === 'GET') {
        $controller->obtener();
    } else {
        $controller->guardar();
    }
}

$usuarioRoutes = array(
    '/sesion' => array('GET', 'sesion'),
    '/configuracion-inicial' => array('POST', 'configurar'),
    '/login' => array('POST', 'login'),
    '/logout' => array('POST', 'logout'),
    '/usuarios' => array('GET, POST', null),
    '/roles' => array('GET, POST', 'roles'),
    '/permisos' => array('GET', 'catalogoPermisos')
);
$editarUsuario = preg_match('#^/usuarios/([1-9][0-9]*)$#D', $path, $coincidencias);
$editarRol = preg_match('#^/roles/([1-9][0-9]*)/permisos$#D', $path, $rolCoincidencias);
if (isset($usuarioRoutes[$path]) || $editarUsuario || $editarRol) {
    $allowed = ($editarUsuario || $editarRol) ? 'PUT' : $usuarioRoutes[$path][0];
    if (!in_array($method, explode(', ', $allowed), true)) {
        header('Allow: ' . $allowed);
        Response::json(false, 'Método no permitido', array(), 405);
    }
    $datos = array();
    if ($method !== 'GET') {
        Auth::csrf();
        $raw = file_get_contents('php://input', false, null, 0, 16385);
        if (strlen($raw) > 16384) {
            throw new HttpError('La solicitud es demasiado grande.', 413);
        }
        $datos = json_decode($raw, true);
        if (!is_array($datos) || substr(ltrim($raw), 0, 1) !== '{') {
            throw new HttpError('Envía un objeto JSON válido.', 400);
        }
    }
    require_once __DIR__ . '/../Controllers/UsuarioController.php';
    $controller = new UsuarioController((new Database())->connect());
    if ($editarRol) {
        $controller->guardarPermisos($datos, $rolCoincidencias[1]);
    } elseif ($editarUsuario) {
        $controller->guardar($datos, $coincidencias[1]);
    } elseif ($path === '/usuarios') {
        if ($method === 'GET') {
            $controller->listar();
        } else {
            $controller->guardar($datos);
        }
    } elseif ($path === '/roles' && $method === 'POST') {
        $controller->crearRol($datos);
    } else {
        $action = $usuarioRoutes[$path][1];
        $controller->$action($datos);
    }
}

if(preg_match('#^/productos/imagenes/([1-9][0-9]*)$#D',$path,$fotoMatch)){
    if($method!=='GET'){header('Allow: GET');throw new HttpError('Método no permitido',405);}
    require_once __DIR__.'/../Controllers/ProductoController.php';(new ProductoController((new Database())->connect()))->imagen($fotoMatch[1]);
}

$editarProducto = preg_match('#^/productos/([1-9][0-9]*)$#D', $path, $productoMatch);
$editarCategoria = preg_match('#^/categorias/([1-9][0-9]*)$#D', $path, $categoriaMatch);
if (in_array($path, array('/productos', '/catalogos', '/categorias'), true) || $editarProducto || $editarCategoria) {
    $allowed = $editarProducto ? array('PUT','POST') : ($editarCategoria ? array('PUT') : ($path === '/productos' ? array('GET', 'POST') : ($path === '/catalogos' ? array('GET') : array('POST'))));
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        Response::json(false, 'Método no permitido', array(), 405);
    }

    require_once __DIR__ . '/../Controllers/ProductoController.php';
    $controller = new ProductoController((new Database())->connect());
    if ($method === 'GET') {
        if ($path === '/catalogos') {
            $controller->catalogos();
        } else {
            $controller->index();
        }
    }
    Auth::csrf();
    if($method==='POST'&&($editarProducto||$path==='/productos')&&strpos(isset($_SERVER['CONTENT_TYPE'])?$_SERVER['CONTENT_TYPE']:'','multipart/form-data')===0){
        if(!isset($_POST['datos'])||!is_string($_POST['datos'])||strlen($_POST['datos'])>65536){throw new HttpError('No se recibió el formulario. Reduce las imágenes a 6 MB por guardado.',413);}
        $datos=json_decode($_POST['datos'],true);if(!is_array($datos)||substr(ltrim($_POST['datos']),0,1)!=='{'){throw new HttpError('Envía los datos del producto válidos.',400);}
        $controller->guardar($datos,$editarProducto?$productoMatch[1]:null,$_FILES);
    }
    if($editarProducto&&$method==='POST'){throw new HttpError('Usa un formulario con las imágenes del producto.',415);}
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) {
        throw new HttpError('La solicitud es demasiado grande.', 413);
    }
    $datos = json_decode($raw, true);
    if (!is_array($datos) || substr(ltrim($raw), 0, 1) !== '{') {
        throw new HttpError('Envía un objeto JSON válido.', 400);
    }
    if ($editarCategoria || $path === '/categorias') {
        $controller->categoria($datos, $editarCategoria ? $categoriaMatch[1] : null);
    } else {
        $controller->guardar($datos, $editarProducto ? $productoMatch[1] : null);
    }
}

Response::json(
    false,
    'Ruta no encontrada',
    array(),
    404
);
