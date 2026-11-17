<?php
// Ejecutar mediante tests/licencias_integracion.py; solo escribe en su fixture.
if (PHP_SAPI !== 'cli') exit;
require __DIR__ . '/../app/Helpers/HttpError.php';
require __DIR__ . '/../app/Services/Licencia.php';
$fixture = $argv[1];
$public = $fixture . '/publica.pem';
$private = openssl_pkey_get_private(file_get_contents($fixture . '/emisor/privada.pem'), 'QaLicencias-Only-2026!');
if (!$private) throw new RuntimeException('No se pudo leer la llave QA.');
$now = 1800000000; $checks = 0;
function checkLicense($v, $message) { global $checks; if (!$v) throw new RuntimeException($message); $checks++; }
function rejectLicense($fn, $code) {
    try { $fn(); } catch (HttpError $e) { checkLicense($e->getCode() === $code, $e->getMessage()); return; }
    throw new RuntimeException('Se aceptó una operación inválida.');
}
function encodeLicense($v) { return rtrim(strtr(base64_encode($v), '+/', '-_'), '='); }
function signedLicense($id, $starts, $expires, $plan = 'mensual', $extra = array()) {
    global $private;
    $p = array_merge(array('v'=>1, 'installation'=>$id, 'business'=>'QA negocio', 'plan'=>$plan,
        'issued'=>$starts, 'starts'=>$starts, 'expires'=>$expires, 'serial'=>bin2hex(random_bytes(16))), $extra);
    $body = 'PV1.' . encodeLicense(json_encode($p));
    if (!openssl_sign($body, $sig, $private, OPENSSL_ALGO_SHA256)) throw new RuntimeException('No se pudo firmar.');
    return $body . '.' . encodeLicense($sig);
}
$clock = function () use (&$now) { return $now; };
$m = new Licencia($fixture . '/unit', $public, $clock, 'qa-server');
$initial = $m->estado();
checkLicense($initial['estado'] === 'sin_activar' && !$initial['operable'] && $initial['prueba_disponible'], 'Estado inicial');
checkLicense($m->estado()['instalacion'] === $initial['instalacion'], 'Identidad cambió');
rejectLicense(function () use ($m) { $m->proteger('POST', '/ventas'); }, 402);
$start = $m->iniciarPrueba();
checkLicense($start['vencimiento'] === $now + 15*86400 && $start['dias_restantes'] === 15, 'Prueba no dura 15 días');
rejectLicense(function () use ($m) { $m->iniciarPrueba(); }, 409);
$now += 15*86400-1;
checkLicense($m->estado()['operable'], 'Prueba acabó antes de tiempo');
$now++;
checkLicense($m->estado()['estado'] === 'vencida' && !$m->estado()['operable'], 'Prueba no venció exactamente');
rejectLicense(function () use ($m) { $m->proteger('POST', '/gastos'); }, 402);
$id = $initial['instalacion'];
$token = signedLicense($id, $now, $now+30*86400);
$active = $m->activar($token);
checkLicense($active['estado'] === 'activa' && $active['plan'] === 'mensual', 'Mensual no activa');
checkLicense($m->activar($token)['vencimiento'] === $active['vencimiento'], 'Reintento agregó días');
rejectLicense(function () use ($m) { $m->iniciarPrueba(); }, 409);
rejectLicense(function () use ($m) { $m->activar(array()); }, 422);
rejectLicense(function () use ($m, $token) { $m->activar($token.'x'); }, 422);
rejectLicense(function () use ($m, $now) { $m->activar(signedLicense('PV-'.str_repeat('A',32),$now,$now+86400)); }, 422);
rejectLicense(function () use ($m, $id, $now) { $m->activar(signedLicense($id,$now+600,$now+86400)); }, 422);
rejectLicense(function () use ($m, $id, $now) { $m->activar(signedLicense($id,$now-1000,$now)); }, 422);
rejectLicense(function () use ($m, $id, $now) { $m->activar(signedLicense($id,$now,$now+86400)); }, 409);
rejectLicense(function () use ($m, $id, $now) { $m->activar(signedLicense($id,$now,$now+86400,'gratis')); }, 422);
rejectLicense(function () use ($m, $id, $now) { $m->activar(signedLicense($id,$now,$now+86400,'mensual',array('expires'=>'2100000000'))); }, 422);
$forged = explode('.', $token); $p = json_decode(base64_decode(strtr($forged[1],'-_','+/')),true); $p['expires'] += 99999999;
$forged[1] = encodeLicense(json_encode($p));
rejectLicense(function () use ($m,$forged) { $m->activar(implode('.',$forged)); }, 422);
$now = $active['vencimiento'];
checkLicense($m->estado()['estado'] === 'gracia' && $m->estado()['operable'], 'Falta gracia');
$now += 3*86400;
checkLicense($m->estado()['estado'] === 'vencida' && !$m->estado()['operable'], 'Gracia no venció');
$annual = signedLicense($id,$now,$now+365*86400,'anual');
$paid = $m->activar($annual);
checkLicense($paid['vencimiento'] === $now+365*86400 && $paid['plan'] === 'anual', 'Anual inválida');
$now += 86400; $m->estado(); $now -= 301;
checkLicense($m->estado()['estado'] === 'reloj_incorrecto' && !$m->estado()['operable'], 'Retroceso no detectado');
rejectLicense(function () use ($m,$annual) { $m->activar($annual); }, 409);
$now += 301;
checkLicense($m->estado()['operable'], 'Corrección del reloj no recupera');
$copy = new Licencia($fixture . '/unit',$public,$clock,'otro-servidor');
checkLicense(!$copy->estado()['operable'] && $copy->estado()['instalacion'] !== $id, 'Copia a otro servidor admitida');
$restored = new Licencia($fixture . '/unit',$public,$clock,'qa-server');
checkLicense($restored->estado()['vencimiento'] === $paid['vencimiento'] && !$restored->estado()['prueba_disponible'], 'Reinicio pierde licencia');
$missing = new Licencia($fixture . '/unit',$fixture.'/no-key.pem',$clock,'qa-server');
checkLicense(!$missing->estado()['operable'], 'Falta de llave habilita ventas');
foreach (array('/login','/logout','/configuracion-inicial','/licencia/activar','/licencia/prueba','/respaldos','/respaldos/revisar','/respaldos/restaurar','/caja/turnos/10/cerrar', '/ventas/pendientes/12345678-1234-1234-1234-123456789abc/descartar','/ventas/pendientes/12345678-1234-1234-1234-123456789abc/liberar-cobro') as $route) {
    checkLicense(Licencia::permitidaSinLicencia('POST',$route), 'Bloqueó acción permitida '.$route);
}
foreach (array('/ventas','/caja/turnos','/caja/turnos/10/movimientos','/gastos','/compras','/creditos/1/abonos','/cuentas-pagar/1/pagos','/productos','/usuarios','/nueva-ruta','/licencia/activar/extra','/respaldos/otro') as $route) {
    checkLicense(!Licencia::permitidaSinLicencia('POST',$route), 'Permitió escritura '.$route);
    checkLicense(Licencia::permitidaSinLicencia('GET',$route), 'Bloqueó consulta '.$route);
}
checkLicense(!Licencia::permitidaSinLicencia('PUT','/productos/1'), 'PUT permitido');
checkLicense(!Licencia::permitidaSinLicencia('DELETE','/productos/1'), 'DELETE permitido');
$data = json_decode(file_get_contents($fixture.'/unit/estado.json'),true); $data['token'] .= 'x';
file_put_contents($fixture.'/unit/estado.json',json_encode($data));
checkLicense(!$m->estado()['operable'], 'Clave guardada alterada permite operar');
$m->activar(signedLicense($id,$now,$now+366*86400,'anual'));
checkLicense($m->estado()['operable'], 'No permite reparar token inválido');
file_put_contents($fixture.'/unit/estado.json','{broken');
rejectLicense(function () use ($m) { $m->estado(); }, 503);
echo 'OK: '.$checks." comprobaciones de licencia, firmas, vigencias, reloj y permisos de rutas.\n";
