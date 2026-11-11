<?php

require_once __DIR__ . '/../Models/Usuario.php';

class Auth
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public static function iniciarSesion()
    {
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_name('puntoventa_sesion');
        session_set_cookie_params(array(
            'lifetime' => 0, 'path' => '/PuntoVenta/public/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true, 'samesite' => 'Strict'
        ));
        session_start();
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (!isset($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    }

    public static function csrf()
    {
        $token = isset($_SERVER['HTTP_X_CSRF_TOKEN']) ? $_SERVER['HTTP_X_CSRF_TOKEN'] : '';
        if (!is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            throw new HttpError('La sesión del formulario cambió. Recarga la página e inténtalo de nuevo.', 403);
        }
    }

    public static function epoch(){ $p=dirname(__DIR__,2).'/storage/session_epoch';return is_file($p)?trim(file_get_contents($p)):'0'; }

    public function usuario($obligatorio = true)
    {
        $usuario = null;
        if (isset($_SESSION['usuario_id'], $_SESSION['actividad']) && hash_equals(self::epoch(),isset($_SESSION['epoch'])?$_SESSION['epoch']:'0') && time() - $_SESSION['actividad'] < 1800) {
            $usuario = (new Usuario($this->db))->buscar($_SESSION['usuario_id']);
            if ($usuario && $usuario['activo'] && isset($_SESSION['credencial'])
                && hash_equals($_SESSION['credencial'], $this->credencial($usuario['id']))) {
                $_SESSION['actividad'] = time(); return $usuario;
            }
        }
        unset($_SESSION['usuario_id'], $_SESSION['actividad'], $_SESSION['credencial']);
        if ($obligatorio) { throw new HttpError('Inicia sesión para continuar.', 401); }
        return null;
    }

    public function administrador()
    {
        $usuario = $this->usuario();
        if (empty($usuario['permisos']['todo'])) { throw new HttpError('Necesitas una cuenta de administrador.', 403); }
        return $usuario;
    }

    public function permiso($clave)
    {
        $usuario = $this->usuario();
        if (empty($usuario['permisos']['todo']) && empty($usuario['permisos'][$clave])) {
            throw new HttpError('No tienes permiso para realizar esta operación.', 403);
        }
        return $usuario;
    }

    public function necesitaConfiguracion()
    {
        return (int) $this->db->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() === 0;
    }

    public function credencial($id)
    {
        $q = $this->db->prepare('SELECT contrasena_hash FROM usuarios WHERE id = ?');
        $q->execute(array($id));
        return hash('sha256', (string) $q->fetchColumn());
    }

    public static function esLocal()
    {
        return in_array(isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '', array('127.0.0.1', '::1', '::ffff:127.0.0.1'), true);
    }

    private function verificarCredenciales(array $datos, $soloAdmin = false, $purpose = 'autorizar-reembolso:')
    {
        $login = isset($datos['nombre_usuario']) && is_string($datos['nombre_usuario']) ? strtolower(trim($datos['nombre_usuario'])) : '';
        $password = isset($datos['contrasena']) && is_string($datos['contrasena']) ? $datos['contrasena'] : '';
        if (strlen($login) > 60 || strlen($password) > 72 || $login === '' || $password === '') {
            throw new HttpError('Escribe tu usuario y contraseña.', 422);
        }
        // Límite por dirección de origen, persistente entre sesiones del navegador.
        $clave = hash('sha256', ($soloAdmin ? $purpose : '') . $_SERVER['REMOTE_ADDR']);
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT IGNORE INTO intentos_acceso (clave, inicio, intentos) VALUES (?, ?, 0)')->execute(array($clave, time()));
            $q = $this->db->prepare('SELECT inicio, intentos FROM intentos_acceso WHERE clave = ? FOR UPDATE');
            $q->execute(array($clave));
            $limite = $q->fetch();
            $intentos = time() - (int) $limite['inicio'] >= 900 ? 0 : (int) $limite['intentos'];
            if ($intentos >= 5) { throw new HttpError('Demasiados intentos. Espera 15 minutos antes de volver a intentar.', 429); }
            $q = $this->db->prepare('SELECT u.id, u.contrasena_hash, u.activo, r.permisos FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.nombre_usuario = ?');
            $q->execute(array($login));
            $usuario = $q->fetch();
            // Hash de relleno para que un usuario inexistente también ejecute bcrypt.
            $hash = $usuario ? $usuario['contrasena_hash'] : '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            $valido = password_verify($password, $hash) && $usuario && $usuario['activo'];
            if ($soloAdmin && $valido) { $permisos=json_decode($usuario['permisos'],true); $valido=!empty($permisos['todo']); }
            $this->db->prepare('UPDATE intentos_acceso SET inicio = ?, intentos = ? WHERE clave = ?')
                ->execute(array($intentos === 0 ? time() : $limite['inicio'], $valido ? 0 : $intentos + 1, $clave));
            if ($valido && !$soloAdmin) {
                $this->db->prepare('UPDATE usuarios SET ultimo_acceso = CURRENT_TIMESTAMP WHERE id = ?')->execute(array($usuario['id']));
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        if (!$valido) { throw new HttpError($soloAdmin ? 'Se requiere una cuenta activa de administrador y su contraseña correcta.' : 'Usuario o contraseña incorrectos.', $soloAdmin ? 403 : 401); }
        return $usuario;
    }

    public function autorizarReembolso(array $datos)
    {
        return $this->verificarCredenciales($datos, true);
    }

    public function confirmarRestauracion(array $actor,$password){$u=$this->verificarCredenciales(array('nombre_usuario'=>$actor['nombre_usuario'],'contrasena'=>$password),true,'restaurar-respaldo:');if((string)$u['id']!==(string)$actor['id'])throw new HttpError('Confirma con tu contraseña de administrador.',403);}

    public function login(array $datos)
    {
        $usuario=$this->verificarCredenciales($datos);
        session_regenerate_id(true);
        $_SESSION['epoch']=self::epoch();
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['actividad'] = time();
        $_SESSION['credencial'] = $this->credencial($usuario['id']);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        return (new Usuario($this->db))->buscar($usuario['id']);
    }

    public static function logout()
    {
        $_SESSION = array();
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}
