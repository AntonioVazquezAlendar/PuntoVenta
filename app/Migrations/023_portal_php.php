<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';
require_once __DIR__.'/../Models/Pedido.php';
Pedido::instalar((new Database())->connect());
echo "Portal PHP preparado en AppServ.\n";
