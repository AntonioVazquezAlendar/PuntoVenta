<?php
// Tablas temporales para probar la configuración sin cambiar el cliente real del negocio.
require_once __DIR__.'/../app/Config/Database.php';
require_once __DIR__.'/../app/Helpers/HttpError.php';
require_once __DIR__.'/../app/Models/Usuario.php';
require_once __DIR__.'/../app/Models/Cliente.php';
require_once __DIR__.'/../app/Models/Venta.php';
function comprobar($valor,$mensaje){if(!$valor)throw new RuntimeException($mensaje);}
$db=(new Database())->connect();$externa=(new Database())->connect();
$original=$externa->query('SELECT * FROM configuracion_clientes WHERE id=1')->fetch();$actorId=null;
try{
    $nombre='qa_default_'.bin2hex(random_bytes(6));$db->prepare('INSERT INTO usuarios (nombre,nombre_usuario,rol_id,contrasena_hash) VALUES (?,?,1,?)')->execute(array('QA predeterminado',$nombre,password_hash(bin2hex(random_bytes(24)),PASSWORD_BCRYPT)));$actorId=$db->lastInsertId();
    $db->exec('CREATE TEMPORARY TABLE clientes (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,nombre VARCHAR(200) NOT NULL,telefono VARCHAR(30),correo VARCHAR(150),direccion VARCHAR(300),notas VARCHAR(500),activo TINYINT NOT NULL DEFAULT 1,creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)');$db->exec('CREATE TEMPORARY TABLE configuracion_clientes (id TINYINT PRIMARY KEY,cliente_predeterminado_id BIGINT UNSIGNED NULL)');$db->exec('INSERT INTO configuracion_clientes (id,cliente_predeterminado_id) VALUES (1,NULL)');
    $model=new Cliente($db);$datos=array('nombre'=>'QA predeterminado A','activo'=>1,'predeterminado'=>true);
    $a=$model->guardar($datos,$actorId)['id'];$primero=$model->obtener($a);comprobar((int)$primero['predeterminado']===1,'No se marcó el primero.');comprobar(Cliente::predeterminado($db)['id']===$a,'No se devuelve la selección.');
    $b=$model->guardar(array('nombre'=>'QA predeterminado B','activo'=>1,'predeterminado'=>true),$actorId)['id'];
    comprobar((int)$model->obtener($a)['predeterminado']===0&&(int)$model->obtener($b)['predeterminado']===1,'Debe existir un solo predeterminado.');
    try{$model->guardar($primero,$actorId,$a);throw new RuntimeException('Se aceptó una ficha obsoleta.');}catch(HttpError $e){comprobar($e->getCode()===409,'Se esperaba conflicto de versión.');}
    $actual=$model->obtener($b);unset($actual['predeterminado']);$model->guardar($actual,$actorId,$b);comprobar(Cliente::predeterminado($db)['id']===$b,'Editar sin el campo debe conservarlo.');
    $actual=$model->obtener($b);unset($actual['predeterminado']);$actual['activo']=0;$model->guardar($actual,$actorId,$b);comprobar(Cliente::predeterminado($db)===null,'Desactivar debe retirarlo.');
    $actual=$model->obtener($a);$actual['predeterminado']=true;$model->guardar($actual,$actorId,$a);
    $actor=(new Usuario($db))->buscar($actorId);$context=(new Venta($db))->contexto($actor);comprobar($context['cliente_predeterminado']['id']===$a,'El punto de venta no recibe la ficha.');comprobar(array_keys($context['cliente_predeterminado'])===array('id','nombre','telefono'),'El vendedor recibió datos innecesarios.');
    $actual=$model->obtener($a);$actual['predeterminado']=false;$model->guardar($actual,$actorId,$a);comprobar(Cliente::predeterminado($db)===null,'Desmarcar debe quitar la selección.');
    $actual=$model->obtener($b);$actual['predeterminado']=true;
    try{$model->guardar($actual,$actorId,$b);throw new RuntimeException('Se permitió predeterminado inactivo.');}catch(HttpError $e){comprobar($e->getCode()===422,'Se esperaba validación de cliente activo.');}
    comprobar($externa->query('SELECT * FROM configuracion_clientes WHERE id=1')->fetch()===$original,'Cambió la configuración del negocio.');
    echo "OK: selección única, reemplazo, versión, edición compatible, desactivación, contexto mínimo y retirada. Configuración real intacta.",PHP_EOL;
}finally{
    if($db->inTransaction())$db->rollBack();
    if($actorId!==null)$db->prepare('DELETE FROM usuarios WHERE id=? AND nombre_usuario=?')->execute(array($actorId,$nombre));
}
