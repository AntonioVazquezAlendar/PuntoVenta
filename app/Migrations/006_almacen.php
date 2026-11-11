<?php

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../Config/Database.php';
$db = (new Database())->connect();
$columns = $db->query('SHOW COLUMNS FROM ubicaciones')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('disponible_venta', $columns, true)) {
    $db->exec("ALTER TABLE ubicaciones ADD COLUMN disponible_venta TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = resguardo, dañado o pendiente de revisión; no cuenta como disponible para venta'");
}
if (!in_array('descripcion', $columns, true)) {
    $db->exec('ALTER TABLE ubicaciones ADD COLUMN descripcion VARCHAR(300) NULL');
}
$db->exec(file_get_contents(__DIR__ . '/006_almacen.sql'));
echo "Ubicaciones y operaciones de almacén preparadas.", PHP_EOL;
