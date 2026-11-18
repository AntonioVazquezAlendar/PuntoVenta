<?php
require_once __DIR__.'/../Models/Pedido.php';

class PortalClienteController
{
    const BASE='/PuntoVenta/public/pedidos-clientes';
    private $db;
    private $pedidos;
    public function __construct(PDO $db){$this->db=$db;$this->pedidos=new Pedido($db);}
    public static function json($data,$status=200)
    {
        http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
        echo json_encode($status<400?array('ok'=>true,'data'=>$data):array('ok'=>false,'message'=>$data),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    public function atender($path,$method)
    {
        $route=substr($path,strlen(self::BASE));
        if($route===''&&$method==='GET'){header('Location: '.self::BASE.'/',true,302);exit;}
        if($route==='/'&&$method==='GET'){
            header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: same-origin');
            header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
            require __DIR__.'/../../views/portal/index.php';exit;
        }
        if($route==='/api/catalog'&&$method==='GET')self::json($this->pedidos->catalogoClientes());
        if($route==='/api/orders'&&$method==='POST'){
            $this->origen();$type=isset($_SERVER['CONTENT_TYPE'])?$_SERVER['CONTENT_TYPE']:'';
            if(strtolower(trim(explode(';',$type)[0]))!=='application/json')throw new HttpError('Envía el pedido como JSON.',415);
            $raw=file_get_contents('php://input',false,null,0,32769);if(strlen($raw)>32768)throw new HttpError('El pedido supera el tamaño permitido.',413);
            $d=json_decode($raw,true);if(!is_array($d)||substr(ltrim($raw),0,1)!=='{')throw new HttpError('Envía un objeto JSON válido.',400);
            require_once __DIR__.'/../Services/Licencia.php';if(Licencia::habilitada())(new Licencia())->proteger('POST','/pedidos');
            self::json($this->pedidos->crearPublico($d,isset($_SERVER['REMOTE_ADDR'])?$_SERVER['REMOTE_ADDR']:'local'),201);
        }
        if($method==='GET'&&preg_match('#^/imagenes/([1-9][0-9]*)$#D',$route,$m)){
            $q=$this->db->prepare('SELECT f.ruta FROM imagenes_producto f JOIN productos p ON p.id=f.producto_id WHERE f.id=? AND p.activo=1 AND EXISTS(SELECT 1 FROM variantes_producto v WHERE v.producto_id=p.id AND v.activo=1)');$q->execute(array($m[1]));$this->imagen($q->fetchColumn(),'productos');
        }
        if($route==='/logo'&&$method==='GET')$this->imagen($this->db->query('SELECT logo_ruta FROM empresa WHERE id=1')->fetchColumn(),'logos');
        $known=in_array($route,array('/','/api/catalog','/api/orders','/logo'),true)||preg_match('#^/imagenes/[1-9][0-9]*$#D',$route);
        throw new HttpError($known?'Método no permitido.':'Ruta no encontrada.',$known?405:404);
    }
    private function origen()
    {
        if(isset($_SERVER['HTTP_SEC_FETCH_SITE'])&&$_SERVER['HTTP_SEC_FETCH_SITE']==='cross-site')throw new HttpError('Envía el pedido desde el portal del negocio.',403);
        if(!isset($_SERVER['HTTP_ORIGIN']))return;
        $scheme=!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http';
        $expected=$scheme.'://'.(isset($_SERVER['HTTP_HOST'])?$_SERVER['HTTP_HOST']:'');
        if(!hash_equals($expected,$_SERVER['HTTP_ORIGIN']))throw new HttpError('Envía el pedido desde el portal del negocio.',403);
    }
    private function imagen($ruta,$folder)
    {
        if(!is_string($ruta)||!preg_match('#^uploads/'.$folder.'/[a-f0-9]{32}\.(png|jpg|webp)$#D',$ruta,$m))throw new HttpError('Imagen no encontrada.',404);
        $file=dirname(__DIR__,2).'/storage/'.$ruta;if(!is_file($file)||!is_readable($file)||filesize($file)>2097152)throw new HttpError('Imagen no encontrada.',404);
        $mime=array('png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp');header('Content-Type: '.$mime[$m[1]]);header('X-Content-Type-Options: nosniff');header('Cache-Control: public, max-age=300');readfile($file);exit;
    }
}
