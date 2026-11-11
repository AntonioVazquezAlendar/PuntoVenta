<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$db->exec("CREATE TABLE IF NOT EXISTS preferencias_pdv (
    usuario_id BIGINT UNSIGNED PRIMARY KEY,
    vista ENUM('busqueda','mosaico') NOT NULL DEFAULT 'busqueda',
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB");
echo "Preferencias de punto de venta preparadas.",PHP_EOL;
