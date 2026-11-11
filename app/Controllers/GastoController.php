<?php
require_once __DIR__.'/../Models/Gasto.php';
class GastoController
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public function ejecutar($accion,$id=null,array $datos=array()){
        $auth=new Auth($this->db);$actor=$accion==='categoria'?$auth->administrador():$auth->permiso('ver_gastos');$model=new Gasto($this->db);
        if($accion==='categoria')Response::json(true,'Categoría guardada',$model->categoria($datos,$actor['id'],$id),$id===null?201:200);
        if($accion==='categorias')Response::json(true,'Categorías de gastos',$model->categorias());
        if($accion==='catalogos')Response::json(true,'Datos para gastos',$model->catalogos($actor));
        if($accion==='registrar')Response::json(true,'Gasto registrado',$model->registrar($datos,$actor['id']),201);
        if($accion==='detalle')Response::json(true,'Gasto',$model->obtener($id,$actor));
        Response::json(true,'Gastos consultados',$model->listar($_GET,$actor));
    }
}
