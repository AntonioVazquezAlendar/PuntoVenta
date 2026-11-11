<?php
require_once __DIR__.'/../Services/Auditoria.php';

class Usuario
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function buscar($id)
    {
        $q = $this->db->prepare('SELECT u.id, u.rol_id, u.nombre, u.nombre_usuario,
            u.activo, u.ultimo_acceso, u.creado_en, r.nombre AS rol_nombre, r.permisos
            FROM usuarios u JOIN roles r ON r.id = u.rol_id WHERE u.id = ?');
        $q->execute(array($id));
        $usuario = $q->fetch();
        if ($usuario) {
            $usuario['permisos'] = json_decode($usuario['permisos'], true) ?: array();
        }
        return $usuario;
    }

    public function listar()
    {
        return $this->db->query('SELECT u.id, u.rol_id, u.nombre, u.nombre_usuario,
            u.activo, u.ultimo_acceso, u.creado_en, r.nombre AS rol_nombre
            FROM usuarios u JOIN roles r ON r.id = u.rol_id ORDER BY u.activo DESC, u.nombre, u.id')->fetchAll();
    }

    public function guardar(array $datos, $id = null, $actorId = null, $inicial = false)
    {
        $nombre = isset($datos['nombre']) && is_string($datos['nombre']) ? trim($datos['nombre']) : '';
        $login = isset($datos['nombre_usuario']) && is_string($datos['nombre_usuario']) ? strtolower(trim($datos['nombre_usuario'])) : '';
        $password = isset($datos['contrasena']) && is_string($datos['contrasena']) ? $datos['contrasena'] : '';
        if ($nombre === '' || preg_match('/^.{1,150}$/us', $nombre) !== 1) {
            throw new HttpError('Escribe un nombre de hasta 150 caracteres.');
        }
        if (!preg_match('/^[a-z0-9._-]{3,60}$/D', $login)) {
            throw new HttpError('El usuario debe tener de 3 a 60 letras sin acentos, números, puntos, guiones o guiones bajos.');
        }
        if (($id === null || $password !== '') && (preg_match('/^.{8,}$/us', $password) !== 1 || strlen($password) > 72)) {
            throw new HttpError('La contraseña debe tener al menos 8 caracteres y no superar 72 bytes.');
        }
        if (isset($datos['activo']) && !in_array($datos['activo'], array(0, 1, '0', '1', true, false), true)) {
            throw new HttpError('El estado del usuario no es válido.');
        }
        $activo = isset($datos['activo']) ? (int) $datos['activo'] : 1;
        $hash = $password !== '' ? password_hash($password, PASSWORD_BCRYPT) : null;

        $creando=$id===null;$anterior=null;
        $this->db->beginTransaction();
        try {
            // Mismo orden de bloqueo en creación, configuración inicial y edición.
            $roles = $this->db->query('SELECT id, permisos FROM roles ORDER BY id FOR UPDATE')->fetchAll();
            $usuarios = $this->db->query('SELECT id, rol_id, activo FROM usuarios ORDER BY id FOR UPDATE')->fetchAll();
            $rolesAdmin = array();
            $rolesValidos = array();
            foreach ($roles as $rol) {
                $rolesValidos[] = (string) $rol['id'];
                $permisos = json_decode($rol['permisos'], true);
                if (!empty($permisos['todo'])) { $rolesAdmin[] = (string) $rol['id']; }
            }
            if ($inicial) {
                if (count($usuarios) !== 0) { throw new HttpError('La configuración inicial ya fue completada.', 409); }
                if (!$rolesAdmin) { throw new HttpError('No existe el rol de administrador.', 409); }
                $rolId = $rolesAdmin[0];
                $activo = 1;
            } else {
                // Volver a validar al actor después de obtener los bloqueos.
                $actor = $this->buscar($actorId);
                if (!$actor || !$actor['activo'] || empty($actor['permisos']['todo'])) {
                    throw new HttpError('Necesitas una cuenta de administrador.', 403);
                }
                $rolId = isset($datos['rol_id']) && is_scalar($datos['rol_id']) ? (string) $datos['rol_id'] : '';
            }
            if (!in_array((string) $rolId, $rolesValidos, true)) { throw new HttpError('Selecciona un rol válido.'); }
            if ($id !== null) {
                $actual = $this->buscar($id);$anterior=$actual?Auditoria::usuario($actual):null;
                if (!$actual) { throw new HttpError('Usuario no encontrado.', 404); }
                if ((string) $id === (string) $actorId && (!$activo || !in_array((string) $rolId, $rolesAdmin, true))) {
                    throw new HttpError('No puedes desactivar tu cuenta ni quitarte el acceso de administrador.', 409);
                }
                $administradores = 0;
                foreach ($usuarios as $usuario) {
                    $esEditado = (string) $usuario['id'] === (string) $id;
                    $rolFinal = $esEditado ? $rolId : $usuario['rol_id'];
                    $activoFinal = $esEditado ? $activo : $usuario['activo'];
                    if ($activoFinal && in_array((string) $rolFinal, $rolesAdmin, true)) { $administradores++; }
                }
                if (!$administradores) { throw new HttpError('Debe quedar al menos un administrador activo.', 409); }
                $sql = 'UPDATE usuarios SET nombre = ?, nombre_usuario = ?, rol_id = ?, activo = ?';
                $values = array($nombre, $login, $rolId, $activo);
                if ($hash !== null) { $sql .= ', contrasena_hash = ?'; $values[] = $hash; }
                $sql .= ' WHERE id = ?';
                $values[] = $id;
                $this->db->prepare($sql)->execute($values);
            } else {
                $this->db->prepare('INSERT INTO usuarios (nombre, nombre_usuario, rol_id, activo, contrasena_hash) VALUES (?, ?, ?, ?, ?)')
                    ->execute(array($nombre, $login, $rolId, $activo, $hash));
                $id = $this->db->lastInsertId();
            }
            $nuevo=$this->buscar($id);Auditoria::registrar($this->db,$inicial?$id:$actorId,$creando?'usuario_creado':'usuario_editado','Usuario '.$id,$nombre,$anterior,Auditoria::usuario($nuevo),$hash!==null?array('contrasena_modificada'=>true):array());
            $this->db->commit();
            return $nuevo;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                throw new HttpError('Ese nombre de usuario ya está registrado.', 409);
            }
            throw $e;
        }
    }
}
