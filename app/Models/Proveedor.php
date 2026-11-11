<?php

require_once __DIR__ . '/Producto.php';

class Proveedor
{
    private $db;
    public function __construct(PDO $db) { $this->db=$db; }
    public function listar() { return $this->db->query('SELECT id,nombre,contacto,telefono,correo,direccion,notas,activo FROM proveedores ORDER BY activo DESC,nombre,id')->fetchAll(); }
    public function guardar(array $datos,$id=null)
    {
        $values=array();
        foreach(array('nombre'=>200,'contacto'=>150,'telefono'=>30,'correo'=>150,'direccion'=>300,'notas'=>500) as $campo=>$max) {
            $values[]=Producto::texto($datos,$campo,$max,$campo==='nombre');
        }
        if($values[3]!==''&&!filter_var($values[3],FILTER_VALIDATE_EMAIL)) { throw new HttpError('Escribe un correo válido o deja el campo vacío.'); }
        $values[]=Producto::bandera($datos,'activo');
        if($id===null) {
            $this->db->prepare('INSERT INTO proveedores (nombre,contacto,telefono,correo,direccion,notas,activo) VALUES (?,?,?,?,?,?,?)')->execute($values);
            $id=$this->db->lastInsertId();
        } else {
            $q=$this->db->prepare('SELECT id FROM proveedores WHERE id=?');$q->execute(array($id));
            if(!$q->fetchColumn()) { throw new HttpError('Proveedor no encontrado.',404); }
            $values[]=$id;$this->db->prepare('UPDATE proveedores SET nombre=?,contacto=?,telefono=?,correo=?,direccion=?,notas=?,activo=? WHERE id=?')->execute($values);
        }
        return array('id'=>$id);
    }
}
