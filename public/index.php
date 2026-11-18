<?php

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');

require_once __DIR__ . '/../app/Config/Config.php';
require_once __DIR__ . '/../app/Config/Database.php';
require_once __DIR__ . '/../app/Helpers/Response.php';
require_once __DIR__ . '/../app/Helpers/HttpError.php';
require_once __DIR__ . '/../app/Services/Auth.php';

require_once __DIR__.'/../app/Services/BackupGate.php';
try { BackupGate::acquire(); } catch (HttpError $e) { Response::json(false,$e->getMessage(),array(),$e->getCode()); } catch(Throwable $e) {error_log((string)$e);Response::json(false,'No se pudo preparar el sistema. Conserva los archivos y contacta al administrador.',array(),503);}
$portalPath=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($portalPath==='/PuntoVenta/public/pedidos-clientes'||strpos($portalPath,'/PuntoVenta/public/pedidos-clientes/')===0){
    require_once __DIR__.'/../app/Controllers/PortalClienteController.php';
    try{(new PortalClienteController((new Database())->connect()))->atender($portalPath,$_SERVER['REQUEST_METHOD']);}
    catch(HttpError $e){PortalClienteController::json($e->getMessage(),$e->getCode());}
    catch(Throwable $e){error_log((string)$e);PortalClienteController::json('No se pudo completar la solicitud. Intenta nuevamente.',500);}
    exit;
}
Auth::iniciarSesion();

$pagePath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (in_array($pagePath, array('/PuntoVenta/public', '/PuntoVenta/public/', '/PuntoVenta/public/index.php'), true)) {
    require __DIR__ . '/../views/login/index.php';
    exit;
}

try {
    require __DIR__ . '/../app/Routes/api.php';
} catch (HttpError $e) {
    Response::json(false, $e->getMessage(), array(), $e->getCode());
} catch (Throwable $e) {
    error_log((string) $e);
    Response::json(false, 'No se pudo completar la operación. Inténtalo de nuevo.', array(), 500);
}
