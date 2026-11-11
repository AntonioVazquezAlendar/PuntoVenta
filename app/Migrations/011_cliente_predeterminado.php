<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$db->exec('CREATE TABLE IF NOT EXISTS configuracion_clientes (
    id TINYINT UNSIGNED PRIMARY KEY,
    cliente_predeterminado_id BIGINT UNSIGNED NULL,
    FOREIGN KEY (cliente_predeterminado_id) REFERENCES clientes(id) ON DELETE SET NULL
) ENGINE=InnoDB');
$db->exec('INSERT IGNORE INTO configuracion_clientes (id,cliente_predeterminado_id) VALUES (1,NULL)');
echo "Cliente predeterminado preparado.",PHP_EOL;
