<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../Config/Database.php';
$db=(new Database())->connect();
$columns=$db->query('SHOW COLUMNS FROM turnos_caja')->fetchAll(PDO::FETCH_COLUMN);
if(!in_array('ubicacion_id',$columns,true)){
    $db->exec('ALTER TABLE turnos_caja ADD COLUMN ubicacion_id BIGINT UNSIGNED NULL, ADD KEY idx_turno_ubicacion (ubicacion_id), ADD CONSTRAINT fk_turno_ubicacion FOREIGN KEY (ubicacion_id) REFERENCES ubicaciones(id)');
}
if(!in_array('notas_cierre',$columns,true)){$db->exec('ALTER TABLE turnos_caja ADD COLUMN notas_cierre VARCHAR(300) NULL');}
if(!in_array('resumen_cierre',$columns,true)){$db->exec('ALTER TABLE turnos_caja ADD COLUMN resumen_cierre JSON NULL');}
$db->exec('UPDATE turnos_caja t JOIN cajas c ON c.id=t.caja_id SET t.ubicacion_id=c.ubicacion_id WHERE t.ubicacion_id IS NULL');
$db->exec(file_get_contents(__DIR__.'/007_caja.sql'));
echo "Caja y turnos preparados.",PHP_EOL;
