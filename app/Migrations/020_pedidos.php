<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../Config/Database.php';
$db = (new Database())->connect();
require_once __DIR__.'/../Models/Pedido.php';
Pedido::instalar($db);
echo "Módulo de pedidos preparado. No se modificaron ventas ni existencias.\n";
