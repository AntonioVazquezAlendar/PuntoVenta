<?php

require_once __DIR__.'/../Models/Venta.php';
require_once __DIR__.'/../Models/Reembolso.php';

class VentaController
{
    private $auth;
    private $ventas;
    private $db;
    public function __construct(PDO $db){$this->db=$db;$this->auth=new Auth($db);$this->ventas=new Venta($db);}
    private function lector(){
        $actor=$this->auth->usuario();if(empty($actor['permisos']['todo'])&&empty($actor['permisos']['vender'])&&empty($actor['permisos']['ver_ventas'])){throw new HttpError('No tienes permiso para consultar ventas.',403);}return $actor;
    }
    public function reembolsoFolio(array $datos){$actor=$this->lector();Response::json(true,'Ticket para devolución',(new Reembolso($this->db))->porFolio($datos,$actor));}
    public function reembolsos($id,$accion=null,array $datos=array()){
        $actor=$this->lector();$model=new Reembolso($this->db);
        if($accion==='contexto')Response::json(true,'Datos para devolución',$model->contexto($id,$actor));
        if($accion==='listar')Response::json(true,'Reembolsos consultados',$model->listar($id,$actor,$_GET));
        if($accion==='cotizar')Response::json(true,'Reembolso calculado',$model->cotizar($id,$datos,$actor['id']));
        Response::json(true,'Reembolso registrado',$model->registrar($id,$datos,$actor['id']),201);
    }
    public function contexto(){$actor=$this->auth->permiso('vender');Response::json(true,'Datos para vender',$this->ventas->contexto($actor,isset($_GET['turno_id'])&&$_GET['turno_id']!==''?$_GET['turno_id']:null));}
    public function productos(){$actor=$this->auth->permiso('vender');Response::json(true,'Productos encontrados',$this->ventas->productos($actor,$_GET));}
    public function existencias(){$actor=$this->auth->permiso('vender');Response::json(true,'Existencias por ubicación',$this->ventas->existencias($actor,$_GET));}
    public function preferencias(array $datos){$actor=$this->auth->permiso('vender');Response::json(true,'Vista predeterminada guardada',(new VentaCatalogo((new Database())->connect()))->guardarPreferencia($datos,$actor['id']));}
    public function listar(){$actor=$this->lector();Response::json(true,'Ventas consultadas',$this->ventas->listar($actor,$_GET));}
    public function obtener($id){$actor=$this->lector();Response::json(true,'Ticket de venta',$this->ventas->obtener($id,$actor));}
    public function logo($id){$actor=$this->lector();$this->ventas->logo($id,$actor);}
    public function cotizar(array $datos){$actor=$this->auth->permiso('vender');Response::json(true,'Importes calculados',$this->ventas->cotizar($datos,$actor['id']));}
    public function cobrar(array $datos){$actor=$this->auth->permiso('vender');Response::json(true,'Venta completada',$this->ventas->cobrar($datos,$actor['id']),201);}
}
