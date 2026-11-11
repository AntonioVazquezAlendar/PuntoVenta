<?php

require_once __DIR__.'/../Models/Almacen.php';

class AlmacenController
{
    private $auth;
    private $almacen;
    public function __construct(PDO $db){$this->auth=new Auth($db);$this->almacen=new Almacen($db);}
    public function listar(){$this->auth->permiso('ver_inventario');Response::json(true,'Operaciones de almacén',$this->almacen->listar($_GET));}
    public function ubicacion(array $datos,$id=null){$actor=$this->auth->permiso('administrar_ubicaciones');$this->auth->permiso('ver_inventario');Response::json(true,'Ubicación guardada',$this->almacen->guardarUbicacion($datos,$actor['id'],$id),$id===null?201:200);}
    public function registrar(array $datos,$tipo){$actor=$this->auth->permiso($tipo==='traspaso'?'realizar_traspasos':'registrar_mermas');$this->auth->permiso('ver_inventario');Response::json(true,'Operación registrada',$this->almacen->registrar($datos,$actor['id'],$tipo),201);}
}
