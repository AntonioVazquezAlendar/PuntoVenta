<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$db->exec('CREATE TABLE IF NOT EXISTS sistema_recuperacion (id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,confirmado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB');
echo "Control de recuperación preparado.",PHP_EOL;
