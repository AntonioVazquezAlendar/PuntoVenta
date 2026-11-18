<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../../app/Config/Database.php';
require __DIR__.'/../../app/Helpers/HttpError.php';
require __DIR__.'/../../app/Models/Pedido.php';
$db=(new Database())->connect();$model=new Pedido($db);$cfg=$model->conexion();
if(!in_array($cfg['url'],array('http://localhost:8788','http://127.0.0.1:8788'),true))throw new RuntimeException('Este script solo publica en el portal local de prueba.');
$admin=$db->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.activo=1 AND JSON_EXTRACT(r.permisos,'$.todo')=true ORDER BY u.id LIMIT 1")->fetchColumn();
if(!$admin)throw new RuntimeException('No hay un administrador activo.');
$result=$model->publicar($admin);echo 'Catálogo local actualizado: '.$result['products']." productos activos.\n";
