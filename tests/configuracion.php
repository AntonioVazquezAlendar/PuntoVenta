<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/Config/Database.php';require __DIR__.'/../app/Helpers/HttpError.php';require __DIR__.'/../app/Models/ConfiguracionAdmin.php';
$db=(new Database())->connect();$original=$db->query('SELECT DATABASE()')->fetchColumn();$qa='qa_config_'.bin2hex(random_bytes(6));
function checkConfig($v,$message){if(!$v)throw new RuntimeException($message);}
function rejectsConfig($fn,$code=422){try{$fn();throw new RuntimeException('Invalid request accepted');}catch(HttpError $e){checkConfig($e->getCode()===$code,$e->getMessage());}}
try{
    $db->exec('CREATE DATABASE `'.$qa.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');foreach(array('roles','usuarios','empresa','metodos_pago','bitacora_actividad') as $table)$db->exec('CREATE TABLE `'.$qa.'`.`'.$table.'` LIKE `'.$original.'`.`'.$table.'`');$db->exec('USE `'.$qa.'`');
    $db->exec("INSERT INTO roles(id,nombre,permisos) VALUES(1,'Admin','{\"todo\":true}'),(2,'Cajero','{\"vender\":true}')");
    $q=$db->prepare('INSERT INTO usuarios(id,nombre,nombre_usuario,rol_id,contrasena_hash) VALUES(?,?,?,?,?)');foreach(array(1,2) as $i)$q->execute(array($i,'QA '.$i,'qa_config_'.$i,$i,password_hash('QaPass12!',PASSWORD_BCRYPT)));
    $db->exec("INSERT INTO empresa(id,nombre_comercial,pie_ticket) VALUES(1,'QA negocio','Gracias por su compra')");$db->exec("INSERT INTO metodos_pago(id,nombre,tipo) VALUES(1,'Efectivo','efectivo')");
    $m=new ConfiguracionAdmin($db);$before=$m->obtener();$d=$before['negocio'];$d['version']=$before['version'];$d['telefono']='555-123-4567';$d['correo']='qa@example.test';$d['sitio_web']='https://example.test';$d['rfc']='XAXX010101000';$d['codigo_postal']='01000';$d['encabezado_ticket']="Bienvenidos\nLínea dos";$d['pie_ticket']='Vuelve pronto';
    rejectsConfig(function()use($m,$d){$m->guardar($d,2);},403);$saved=$m->guardar($d,1);checkConfig($saved['negocio']['encabezado_ticket']===$d['encabezado_ticket'],'Header lost');checkConfig($saved['sistema']['moneda']==='MXN','Currency changed');checkConfig((int)$db->query('SELECT COUNT(*) FROM bitacora_actividad')->fetchColumn()===1,'Audit missing');
    rejectsConfig(function()use($m,$d){$m->guardar($d,1);},409);$d['version']=$saved['version'];$m->guardar($d,1);checkConfig((int)$db->query('SELECT COUNT(*) FROM bitacora_actividad')->fetchColumn()===1,'No-op audit');
    foreach(array('correo'=>'bad','sitio_web'=>'javascript:alert(1)','codigo_postal'=>'1234','rfc'=>'invalid','direccion'=>array(),'pie_ticket'=>str_repeat('x',301)) as $k=>$v){$bad=$d;$bad[$k]=$v;rejectsConfig(function()use($m,$bad){$m->guardar($bad,1);});}
    $bad=$d;unset($bad['direccion']);rejectsConfig(function()use($m,$bad){$m->guardar($bad,1);});
    $cash=$m->metodos()[0];rejectsConfig(function()use($m,$cash){$m->metodo(1,array('nombre'=>'Efectivo','activo'=>0,'version'=>$cash['version']),1);});
    $rows=$m->metodo(null,array('nombre'=>'Tarjeta QA','tipo'=>'tarjeta','activo'=>1),1);$card=end($rows);$payload=array('nombre'=>'Tarjeta renombrada','tipo'=>'tarjeta','activo'=>0,'version'=>$card['version']);$rows=$m->metodo($card['id'],$payload,1);$changed=end($rows);checkConfig(!$changed['activo']&&$changed['tipo']==='tarjeta','Method update failed');
    rejectsConfig(function()use($m,$card,$payload){$m->metodo($card['id'],$payload,1);},409);
    $payload['version']=$changed['version'];$payload['tipo']='efectivo';rejectsConfig(function()use($m,$card,$payload){$m->metodo($card['id'],$payload,1);});
    rejectsConfig(function()use($m){$m->metodo(null,array('nombre'=>'efectivo','tipo'=>'otro','activo'=>0),1);},409);
    rejectsConfig(function()use($m){$m->metodo(null,array('nombre'=>'QA','tipo'=>'otro','activo'=>0),2);},403);
    $db->exec('UPDATE usuarios SET activo=0 WHERE id=1');rejectsConfig(function()use($m,$d){$m->guardar($d,1);},403);
    checkConfig($db->query('SELECT nombre_comercial FROM empresa')->fetchColumn()==='QA negocio','Brand overwritten');
    echo "OK: configuración y métodos en base aislada; validación, administrador activo, no-op, auditoría, conflictos, tipo inmutable y último efectivo.\n";
}finally{
    if(!preg_match('/^qa_config_[a-f0-9]{12}$/D',$qa)||$qa===$original)throw new RuntimeException('Unsafe QA database');if($db->inTransaction())$db->rollBack();$db->exec('USE `'.$original.'`');$db->exec('DROP DATABASE IF EXISTS `'.$qa.'`');
}
