<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../../app/Config/Database.php';require __DIR__.'/../../app/Helpers/HttpError.php';require __DIR__.'/../../app/Models/Pedido.php';
$db=(new Database())->connect();Pedido::instalar($db);$p=new Pedido($db);$cfg=$p->conexion();
if($cfg['url']!==''&&$cfg['url']!=='http://localhost:8788')throw new RuntimeException('Ya hay otro portal configurado. No se reemplazó su conexión.');
$dir=__DIR__.'/../../portal-pedidos';
if(file_put_contents($dir.'/.dev.vars','PEDIDOS_BRIDGE_KEY='.$cfg['clave']."\n",LOCK_EX)===false)throw new RuntimeException('No se pudo preparar la conexión privada.');
$db->exec("UPDATE conexion_pedidos SET url='http://localhost:8788' WHERE id=1");
echo "Portal de prueba conectado a http://localhost:8788. Publica únicamente los productos que selecciones desde Pedidos.\n";
