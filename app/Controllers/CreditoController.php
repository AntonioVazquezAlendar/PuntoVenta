<?php
require_once __DIR__.'/../Models/Credito.php';
class CreditoController
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public function ejecutar($accion,$id=null,array $datos=array()){
        $auth=new Auth($this->db);$model=new Credito($this->db);
        if(in_array($accion,array('clientes','configuracion','guardar'),true)){$actor=$auth->administrador();if($accion==='clientes')Response::json(true,'Clientes',$model->clientes($_GET));if($accion==='configuracion')Response::json(true,'Configuración de crédito',$model->disponible($id));Response::json(true,'Crédito configurado',$model->configurar($id,$datos,$actor['id']));}
        $actor=$auth->permiso('ver_creditos');if($accion==='abonar')Response::json(true,'Abono registrado',$model->abonar($id,$datos,$actor['id']),201);
        if($accion==='detalle')Response::json(true,'Crédito del cliente',$model->obtener($id,$actor));
        Response::json(true,'Créditos',$model->listar($_GET,$actor));
    }
}
