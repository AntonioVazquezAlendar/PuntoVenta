<?php

require_once __DIR__.'/../Models/Caja.php';

class CajaController
{
    private $auth;
    private $caja;
    public function __construct(PDO $db){$this->auth=new Auth($db);$this->caja=new Caja($db);}
    private function actor(){return $this->auth->permiso('operar_caja');}
    public function catalogos(){$this->actor();Response::json(true,'Cajas disponibles',$this->caja->catalogos());}
    public function listar(){$actor=$this->actor();Response::json(true,'Turnos consultados',$this->caja->listar($_GET,$actor));}
    public function obtener($id){$actor=$this->actor();Response::json(true,'Turno consultado',$this->caja->obtener($id,$actor));}
    public function guardar(array $datos,$id=null){$actor=$this->auth->permiso('administrar_cajas');$this->actor();Response::json(true,'Caja guardada',$this->caja->guardar($datos,$actor['id'],$id),$id===null?201:200);}
    public function abrir(array $datos){$actor=$this->actor();Response::json(true,'Turno abierto',$this->caja->abrir($datos,$actor['id']),201);}
    public function operar($id,array $datos,$accion){$actor=$this->actor();Response::json(true,$accion==='movimiento'?'Movimiento registrado':'Turno cerrado',$this->caja->operar($id,$datos,$actor['id'],$accion),$accion==='movimiento'?201:200);}
}
