<?php

require_once __DIR__ . '/../Models/Producto.php';
require_once __DIR__ . '/../Models/Categoria.php';

class ProductoController
{
    private $db;
    private $auth;
    public function __construct(PDO $db) { $this->db = $db; $this->auth = new Auth($db); }
    public function index()
    {
        $u = $this->auth->permiso('ver_productos');
        $edita = !empty($u['permisos']['todo']) || !empty($u['permisos']['editar_productos']);
        $todos = isset($_GET['todos']) && $_GET['todos'] === '1';
        if ($todos && !$edita) { throw new HttpError('No tienes permiso para consultar productos inactivos.', 403); }
        Response::json(true, 'Productos consultados correctamente', (new Producto($this->db))->listarActivos($todos,$edita));
    }
    public function catalogos()
    {
        $this->auth->permiso('ver_productos');
        Response::json(true, 'Catálogos disponibles', array(
            'imagenes_version_vacia'=>hash('sha256','[]'),
            'categorias' => $this->db->query('SELECT id,nombre,categoria_padre_id,activo FROM categorias ORDER BY nombre,id')->fetchAll(),
            'unidades' => $this->db->query('SELECT id,codigo,nombre,permite_decimales,activo FROM unidades ORDER BY id')->fetchAll(),
            'impuestos' => $this->db->query('SELECT id,nombre,tasa,activo FROM impuestos ORDER BY id')->fetchAll()
        ));
    }
    public function guardar(array $datos, $id = null, array $archivos = array())
    {
        $actor=$this->auth->permiso('editar_productos'); $this->auth->permiso('ver_productos');
        Response::json(true, $id === null ? 'Producto creado' : 'Producto actualizado', (new Producto($this->db))->guardar($datos,$id,$archivos,$actor['id']), $id === null ? 201 : 200);
    }
    public function imagen($id)
    {
        $actor=$this->auth->usuario();if(empty($actor['permisos']['todo'])&&empty($actor['permisos']['ver_productos'])&&empty($actor['permisos']['vender'])){throw new HttpError('No tienes permiso para consultar imágenes.',403);}
        (new ProductoImagen($this->db))->mostrar($id,$actor);
    }
    public function categoria(array $datos, $id = null)
    {
        $this->auth->permiso('editar_productos'); $this->auth->permiso('ver_productos');
        Response::json(true, $id === null ? 'Categoría creada' : 'Categoría actualizada', (new Categoria($this->db))->guardar($datos,$id), $id === null ? 201 : 200);
    }
}
