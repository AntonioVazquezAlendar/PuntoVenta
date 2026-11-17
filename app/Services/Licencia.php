<?php

/** Licencia local por servidor. No forma parte de los respaldos de datos. */
class Licencia
{
    const DAY = 86400;
    private $root;
    private $publicKey;
    private $clock;
    private $machine;

    public static function habilitada()
    {
        return (require dirname(__DIR__) . '/Config/Licencias.php') === true;
    }

    public function __construct($root = null, $publicKey = null, callable $clock = null, $machine = null)
    {
        $this->root = $root ?: dirname(__DIR__, 2) . '/storage/licencia';
        $this->publicKey = $publicKey ?: dirname(__DIR__) . '/Config/licencia_publica.pem';
        $this->clock = $clock ?: function () { return time(); };
        $this->machine = $machine === null ? strtolower(php_uname('n')) : $machine;
    }

    private function transact(callable $action)
    {
        if (!is_dir($this->root) && !@mkdir($this->root, 0700, true) && !is_dir($this->root)) {
            throw new HttpError('No se pudo preparar la licencia. Contacta al proveedor.', 503);
        }
        $lock = @fopen($this->root . '/estado.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            if ($lock) fclose($lock);
            throw new HttpError('No se pudo consultar la licencia. Inténtalo nuevamente.', 503);
        }
        try {
            $file = $this->root . '/estado.json';
            $now = call_user_func($this->clock);
            if (!is_int($now) || $now < 0) throw new RuntimeException('Reloj no válido.');
            if (is_file($file)) {
                $s = json_decode(file_get_contents($file), true);
                if (!is_array($s) || !isset($s['version'], $s['seed'], $s['last_seen'], $s['trial_started']) ||
                    $s['version'] !== 1 || !is_string($s['seed']) || !preg_match('/^[a-f0-9]{64}$/D', $s['seed']) ||
                    !is_int($s['last_seen']) || $s['last_seen'] < 0 || !is_int($s['trial_started']) || $s['trial_started'] < 0 ||
                    !array_key_exists('token', $s) || ($s['token'] !== null && !is_string($s['token']))) {
                    throw new HttpError('El estado de licencia está dañado. Conserva los archivos y contacta al proveedor.', 503);
                }
            } else {
                $s = array('version' => 1, 'seed' => bin2hex(random_bytes(32)), 'last_seen' => $now,
                    'trial_started' => 0, 'token' => null);
            }
            $before = $s;
            $rollback = $now + 300 < $s['last_seen'];
            $effective = max($now, $s['last_seen']);
            $id = 'PV-' . strtoupper(substr(hash('sha256', $s['seed'] . '|' . $this->machine), 0, 32));
            $result = $action($s, $effective, $rollback, $id);
            // Persistir un máximo conocido impide ganar tiempo retrocediendo el reloj.
            if ($now > $s['last_seen'] + 60) $s['last_seen'] = $now;
            if ($before !== $s || !is_file($file)) {
                $temp = $this->root . '/estado-' . bin2hex(random_bytes(8)) . '.tmp';
                $json = json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if ($json === false || file_put_contents($temp, $json, LOCK_EX) !== strlen($json) || !@rename($temp, $file)) {
                    if (is_file($temp)) unlink($temp);
                    throw new HttpError('No se pudo guardar la licencia. Revisa los permisos del servidor.', 503);
                }
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function decode64($value)
    {
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return false;
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded !== false && rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=') === $value ? $decoded : false;
    }

    private function verify($token, $id)
    {
        if (!function_exists('openssl_verify')) {
            throw new HttpError('Activa la extensión OpenSSL de PHP para verificar las claves de licencia.', 503);
        }
        if (!is_file($this->publicKey)) throw new HttpError('Falta la llave pública del proveedor. Solicita su instalación.', 503);
        if (!is_string($token) || strlen($token) > 4096) throw new HttpError('La clave de licencia no es válida.', 422);
        $parts = explode('.', $token);
        if (count($parts) !== 3 || $parts[0] !== 'PV1') throw new HttpError('La clave de licencia no es válida.', 422);
        $raw = $this->decode64($parts[1]);
        $sig = $this->decode64($parts[2]);
        if ($raw === false || $sig === false || @openssl_verify('PV1.' . $parts[1], $sig,
            file_get_contents($this->publicKey), OPENSSL_ALGO_SHA256) !== 1) {
            throw new HttpError('La firma de la clave no es válida. Solicita la clave completa al proveedor.', 422);
        }
        $p = json_decode($raw, true);
        if (!is_array($p) || !isset($p['v'], $p['installation'], $p['business'], $p['plan'], $p['issued'], $p['starts'], $p['expires'], $p['serial']) ||
            $p['v'] !== 1 || !is_string($p['installation']) || !hash_equals($id, $p['installation']) ||
            !is_string($p['business']) || trim($p['business']) === '' || strlen($p['business']) > 300 ||
            !in_array($p['plan'], array('mensual', 'anual'), true) || !is_int($p['issued']) || $p['issued'] <= 0 ||
            !is_int($p['starts']) || !is_int($p['expires']) || $p['starts'] <= 0 || $p['expires'] <= $p['starts'] ||
            !is_string($p['serial']) || !preg_match('/^[a-f0-9]{32}$/D', $p['serial'])) {
            throw new HttpError('La clave no corresponde a esta instalación o contiene datos inválidos.', 422);
        }
        return $p;
    }

    private function summary(array $s, $now, $rollback, $id)
    {
        $plan = null; $business = null; $expires = null; $starts = null; $error = null;
        $operable = false; $state = 'sin_activar'; $grace = null;
        if ($s['trial_started'] > 0) {
            $plan = 'prueba'; $starts = $s['trial_started']; $expires = $starts + 15 * self::DAY;
        }
        if ($s['token'] !== null) {
            try {
                $p = $this->verify($s['token'], $id);
                $plan = $p['plan']; $business = $p['business']; $starts = $p['starts']; $expires = $p['expires'];
            } catch (HttpError $e) { $error = $e->getMessage(); $state = 'invalida'; }
        }
        if (!$error && $expires !== null) {
            $grace = $expires + ($plan === 'prueba' ? 0 : 3 * self::DAY);
            $state = $now < $starts ? 'aun_no_vigente' : ($now < $expires ? 'activa' : ($now < $grace ? 'gracia' : 'vencida'));
            $operable = in_array($state, array('activa', 'gracia'), true);
        }
        if ($rollback) { $state = 'reloj_incorrecto'; $operable = false; }
        $messages = array(
            'sin_activar' => 'Inicia la prueba de 15 días o introduce una clave para comenzar.',
            'activa' => $plan === 'prueba' ? 'Tu prueba está activa.' : 'Tu licencia está activa.',
            'gracia' => 'Tu licencia venció y estás en los tres días de gracia. Solicita una renovación.',
            'vencida' => 'Tu licencia venció. Introduce una nueva clave para registrar operaciones. Tus datos permanecen guardados.',
            'aun_no_vigente' => 'La vigencia de esta clave todavía no comienza. Revisa la fecha del equipo.',
            'invalida' => $error,
            'reloj_incorrecto' => 'La fecha del servidor retrocedió. Corrígela para continuar; no se perderán tus datos.'
        );
        return array('instalacion' => $id, 'estado' => $state, 'operable' => $operable, 'plan' => $plan,
            'negocio' => $business, 'inicio' => $starts, 'vencimiento' => $expires, 'fin_gracia' => $grace,
            'dias_restantes' => $expires === null ? 0 : max(0, (int) ceil(($expires - $now) / self::DAY)),
            'prueba_disponible' => $s['trial_started'] === 0 && $s['token'] === null,
            'mensaje' => $messages[$state]);
    }

    public function estado()
    {
        return $this->transact(function (&$s, $now, $rollback, $id) { return $this->summary($s, $now, $rollback, $id); });
    }

    public function iniciarPrueba()
    {
        return $this->transact(function (&$s, $now, $rollback, $id) {
            if ($rollback) throw new HttpError('Corrige la fecha del servidor antes de iniciar la prueba.', 409);
            if ($s['trial_started'] > 0 || $s['token'] !== null) throw new HttpError('Esta instalación ya utilizó su prueba o tiene una licencia.', 409);
            $s['trial_started'] = $now;
            return $this->summary($s, $now, false, $id);
        });
    }

    public function activar($token)
    {
        return $this->transact(function (&$s, $now, $rollback, $id) use ($token) {
            if ($rollback) throw new HttpError('Corrige la fecha del servidor antes de activar la licencia.', 409);
            if (!is_string($token)) throw new HttpError('Introduce una clave de licencia válida.', 422);
            $token = trim($token);
            $p = $this->verify($token, $id);
            if ($p['starts'] > $now + 300 || $p['issued'] > $now + 300 || $p['expires'] <= $now) {
                throw new HttpError('La clave está vencida o su fecha de inicio aún no llega. Revisa la fecha o solicita una nueva clave.', 422);
            }
            if ($s['token'] !== null && !hash_equals($s['token'], $token)) {
                // Un estado inválido puede repararse con una nueva clave válida.
                try { $old = $this->verify($s['token'], $id); } catch (HttpError $e) { $old = null; }
                if ($old && $p['expires'] <= $old['expires']) throw new HttpError('Esta clave no amplía la vigencia actual. Solicita la renovación más reciente.', 409);
            }
            $s['token'] = $token;
            return $this->summary($s, $now, false, $id);
        });
    }

    /** Lista cerrada: una nueva ruta de escritura queda protegida por defecto. */
    public static function permitidaSinLicencia($method, $path)
    {
        if ($method === 'GET' || $method === 'HEAD') return true;
        if ($method === 'POST' && in_array($path, array('/login', '/logout', '/configuracion-inicial', '/licencia/activar', '/licencia/prueba'), true)) return true;
        if ($method === 'POST' && preg_match('#^/respaldos(?:/(importar|revisar|restaurar)|/importar/[a-f0-9]{32}/parte)?$#D', $path)) return true;
        if ($method === 'POST' && preg_match('#^/caja/turnos/[1-9][0-9]*/cerrar$#D', $path)) return true;
        return $method === 'POST' && preg_match('#^/ventas/pendientes/[a-f0-9-]{36}/(descartar|liberar-cobro)$#D', $path) === 1;
    }

    public function proteger($method, $path)
    {
        if (self::permitidaSinLicencia($method, $path)) return;
        $s = $this->estado();
        if (!$s['operable']) throw new HttpError($s['mensaje'] . ' Abre Licencia y activación.', 402);
    }
}
