<?php
require_once __DIR__.'/../Models/Cliente.php';
class ClienteController
{
    private $auth;private $clientes;
    public function __construct(PDO $db){$this->auth=new Auth($db);$this->clientes=new Cliente($db);}
    public function listar(){$this->auth->permiso('ver_clientes');Response::json(true,'Clientes consultados',$this->clientes->listar($_GET));}
    public function buscar(){$this->auth->permiso('vender');Response::json(true,'Clientes disponibles',$this->clientes->listar($_GET,true));}
    public function obtener($id){$this->auth->permiso('ver_clientes');Response::json(true,'Cliente consultado',$this->clientes->obtener($id));}
    public function historial($id){$actor=$this->auth->permiso('ver_clientes');Response::json(true,'Historial de compras',$this->clientes->historial($id,$actor,$_GET));}
    public function guardar(array $datos,$id=null){$actor=$this->auth->permiso('editar_clientes');$this->auth->permiso('ver_clientes');Response::json(true,'Cliente guardado',$this->clientes->guardar($datos,$actor['id'],$id),$id===null?201:200);}
}
