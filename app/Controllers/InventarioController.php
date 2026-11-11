<?php

require_once __DIR__ . '/../Models/Inventario.php';

class InventarioController
{
    private $auth;
    private $inventario;
    public function __construct(PDO $db) { $this->auth = new Auth($db); $this->inventario = new Inventario($db); }
    public function ubicaciones() { $this->auth->permiso('ver_inventario'); Response::json(true,'Ubicaciones disponibles',$this->inventario->ubicaciones()); }
    public function index() {
        $this->auth->permiso('ver_inventario');
        $ubicacion = Producto::identificador(isset($_GET['ubicacion_id']) ? $_GET['ubicacion_id'] : null);
        Response::json(true,'Existencias consultadas',$this->inventario->existencias($ubicacion));
    }
    public function historial() { $this->auth->permiso('ver_inventario'); Response::json(true,'Historial consultado',$this->inventario->historial($_GET)); }
    public function ajustar(array $datos) {
        $actor = $this->auth->permiso('ajustar_inventario'); $this->auth->permiso('ver_inventario');
        Response::json(true,'Ajuste registrado',$this->inventario->ajustar($datos,$actor['id']),201);
    }
}
