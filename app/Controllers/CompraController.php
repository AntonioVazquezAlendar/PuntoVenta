<?php

require_once __DIR__ . '/../Models/Compra.php';

class CompraController
{
    private $db;
    private $auth;
    private $compras;
    public function __construct(PDO $db){$this->db=$db;$this->auth=new Auth($db);$this->compras=new Compra($db);}
    private function actor(){return $this->auth->permiso('hacer_compras');}
    public function index(){$this->actor();Response::json(true,'Compras consultadas',$this->compras->listar());}
    public function catalogos(){$this->actor();Response::json(true,'Datos de compra',$this->compras->catalogos());}
    public function obtener($id){$this->actor();Response::json(true,'Compra consultada',$this->compras->obtener($id));}
    public function guardar(array $datos,$id=null){$actor=$this->actor();Response::json(true,$id===null?'Compra creada':'Compra actualizada',$this->compras->guardar($datos,$actor['id'],$id),$id===null?201:200);}
    public function estado($id,array $datos,$accion){$actor=$this->actor();Response::json(true,$accion==='recibir'?'Compra recibida':'Compra cancelada',$this->compras->cambiarEstado($id,$datos,$actor['id'],$accion));}
    public function proveedores(){$this->actor();Response::json(true,'Proveedores consultados',(new Proveedor($this->db))->listar());}
    public function proveedor(array $datos,$id=null){$this->actor();Response::json(true,'Proveedor guardado',(new Proveedor($this->db))->guardar($datos,$id),$id===null?201:200);}
}
