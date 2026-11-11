<?php

class Categoria
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }
    public function guardar(array $datos, $id = null)
    {
        $nombre = Producto::texto($datos, 'nombre', 120); $activo = Producto::bandera($datos, 'activo');
        $padre = Producto::identificador(isset($datos['categoria_padre_id']) ? $datos['categoria_padre_id'] : null, true);
        $this->db->beginTransaction();
        try {
            $rows = $this->db->query('SELECT id,categoria_padre_id,activo FROM categorias ORDER BY id FOR UPDATE')->fetchAll(); $map = array_column($rows, null, 'id');
            if ($id !== null && !isset($map[$id])) { throw new HttpError('Categoría no encontrada.', 404); }
            if ($padre !== null && (!isset($map[$padre]) || (!$map[$padre]['activo'] && ($id === null || (string) $map[$id]['categoria_padre_id'] !== $padre)))) { throw new HttpError('Selecciona una categoría superior activa.'); }
            $cursor = $padre; $vistos = array();
            while ($cursor !== null) {
                if ((string) $cursor === (string) $id || isset($vistos[$cursor])) { throw new HttpError('Una categoría no puede depender de sí misma ni de sus subcategorías.'); }
                $vistos[$cursor] = true; $cursor = $map[$cursor]['categoria_padre_id'];
            }
            $q = $this->db->prepare('SELECT id FROM categorias WHERE nombre = ? AND categoria_padre_id <=> ? AND id <> ?'); $q->execute(array($nombre,$padre,$id === null ? '0' : $id));
            if ($q->fetchColumn()) { throw new HttpError('Ya existe esa categoría en el mismo nivel.', 409); }
            if ($id === null) {
                $this->db->prepare('INSERT INTO categorias (nombre,categoria_padre_id,activo) VALUES (?,?,?)')->execute(array($nombre,$padre,$activo)); $id = $this->db->lastInsertId();
            } else { $this->db->prepare('UPDATE categorias SET nombre=?,categoria_padre_id=?,activo=? WHERE id=?')->execute(array($nombre,$padre,$activo,$id)); }
            $this->db->commit(); return array('id' => $id);
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }
}
