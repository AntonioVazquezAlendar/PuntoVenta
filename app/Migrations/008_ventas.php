<?php

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$columns=$db->query('SHOW COLUMNS FROM ventas')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('ticket_datos',$columns,true)){$db->exec('ALTER TABLE ventas ADD COLUMN ticket_datos JSON NULL');}
$columns=$db->query('SHOW COLUMNS FROM pagos_venta')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('tipo_metodo',$columns,true)){$db->exec('ALTER TABLE pagos_venta ADD COLUMN tipo_metodo VARCHAR(20) NULL');}
if(!in_array('nombre_metodo',$columns,true)){$db->exec('ALTER TABLE pagos_venta ADD COLUMN nombre_metodo VARCHAR(80) NULL');}
$db->exec(file_get_contents(__DIR__.'/008_ventas.sql'));echo "Ventas y tickets preparados.",PHP_EOL;
