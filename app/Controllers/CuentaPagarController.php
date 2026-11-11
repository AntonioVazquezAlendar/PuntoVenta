<?php
require_once __DIR__.'/../Models/CuentaPagar.php';
class CuentaPagarController
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public function ejecutar($accion,$id=null,array $datos=array()){
        $auth=new Auth($this->db);$actor=$accion==='conciliar'?$auth->administrador():$auth->permiso('ver_cuentas_pagar');$model=new CuentaPagar($this->db);
        if($accion==='conciliar')Response::json(true,'Saldo inicial confirmado',$model->conciliar($id,$datos,$actor['id']));
        if($accion==='pagar')Response::json(true,'Pago registrado',$model->pagar($id,$datos,$actor['id']),201);
        if($accion==='detalle')Response::json(true,'Cuenta por pagar',$model->obtener($id,$actor));
        if($accion==='catalogos')Response::json(true,'Proveedores con compras recibidas',$model->catalogos());
        Response::json(true,'Cuentas por pagar',$model->listar($_GET));
    }
}
