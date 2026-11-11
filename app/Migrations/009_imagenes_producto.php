<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$db->exec(file_get_contents(__DIR__.'/009_imagenes_producto.sql'));
$columns=$db->query('SHOW COLUMNS FROM imagenes_producto')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('nombre_original',$columns,true))$db->exec("ALTER TABLE imagenes_producto ADD COLUMN nombre_original VARCHAR(150) NOT NULL DEFAULT 'Imagen'");
if(!in_array('principal_slot',$columns,true)){
    $db->exec('UPDATE imagenes_producto f JOIN (SELECT producto_id,COALESCE(MIN(CASE WHEN es_principal=1 THEN id END),MIN(id)) AS elegida FROM imagenes_producto GROUP BY producto_id) x ON x.producto_id=f.producto_id SET f.es_principal=IF(f.id=x.elegida,1,0)');
    $db->exec('ALTER TABLE imagenes_producto ADD COLUMN principal_slot TINYINT GENERATED ALWAYS AS (IF(es_principal=1,1,NULL)) STORED, ADD UNIQUE KEY uq_imagen_principal (producto_id,principal_slot)');
}
echo "Galerías de productos preparadas.",PHP_EOL;
