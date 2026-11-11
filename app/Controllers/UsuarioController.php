<?php

class UsuarioController
{
    private $db;
    private $auth;

    public function __construct(PDO $db)
    {
        $this->db = $db;
        $this->auth = new Auth($db);
    }

    public function sesion()
    {
        Response::json(true, 'Estado de sesión', array(
            'usuario' => $this->auth->usuario(false),
            'csrf' => $_SESSION['csrf'],
            'configuracion_inicial' => $this->auth->necesitaConfiguracion(),
            'configuracion_local' => Auth::esLocal()
        ));
    }

    public function configurar(array $datos)
    {
        if (!Auth::esLocal()) { throw new HttpError('La primera cuenta debe crearse desde este equipo.', 403); }
        (new Usuario($this->db))->guardar($datos, null, null, true);
        Response::json(true, 'Administrador creado. Ya puedes iniciar sesión.', array(), 201);
    }

    public function login(array $datos)
    {
        $usuario = $this->auth->login($datos);
        Response::json(true, 'Sesión iniciada', array('usuario' => $usuario, 'csrf' => $_SESSION['csrf']));
    }

    public function logout()
    {
        Auth::logout();
        Response::json(true, 'Sesión cerrada');
    }

    public function listar()
    {
        $this->auth->administrador();
        Response::json(true, 'Usuarios consultados', (new Usuario($this->db))->listar());
    }

    public function roles()
    {
        $this->auth->administrador();
        $roles = $this->db->query('SELECT id, nombre, permisos FROM roles ORDER BY id')->fetchAll();
        foreach ($roles as &$rol) { $rol['permisos'] = json_decode($rol['permisos'], true) ?: array(); }
        unset($rol);
        Response::json(true, 'Roles consultados', $roles);
    }

    public function catalogoPermisos()
    {
        $this->auth->administrador();
        require_once __DIR__ . '/../Models/Rol.php';
        Response::json(true, 'Permisos disponibles', Rol::catalogo());
    }

    public function guardarPermisos(array $datos, $id)
    {
        $actor = $this->auth->administrador();
        require_once __DIR__ . '/../Models/Rol.php';
        $rol = (new Rol($this->db))->guardar($id, $datos, $actor['id']);
        Response::json(true, 'Permisos guardados para todas las cuentas de este rol', $rol);
    }

    public function crearRol(array $datos)
    {
        $actor = $this->auth->administrador();
        require_once __DIR__ . '/../Models/Rol.php';
        $rol = (new Rol($this->db))->guardar(null, $datos, $actor['id']);
        Response::json(true, 'Rol personalizado creado', $rol, 201);
    }

    public function guardar(array $datos, $id = null)
    {
        $actor = $this->auth->administrador();
        $usuario = (new Usuario($this->db))->guardar($datos, $id, $actor['id']);
        if ($id !== null && (string) $id === (string) $actor['id']) {
            $_SESSION['credencial'] = $this->auth->credencial($id);
        }
        Response::json(true, $id === null ? 'Usuario creado' : 'Usuario actualizado', $usuario, $id === null ? 201 : 200);
    }
}
