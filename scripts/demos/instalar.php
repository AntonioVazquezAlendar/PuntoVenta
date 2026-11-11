<?php
// Herramienta local. Nunca disponible como ruta web.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../app/Config/Database.php';
$catalog=json_decode(file_get_contents(__DIR__.'/catalogo.json'),true);
function verificarDemo(PDO $db,array $demo){
    $name=$demo['base'];$db->exec('USE `'.$name.'`');
    $count=(int)$db->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn();
    if($count!==$demo['tablas'])throw new RuntimeException('El esquema de esta demo no coincide. No se activó.');
    $q=$db->query("SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.activo=1 AND JSON_EXTRACT(r.permisos,'$.todo')=TRUE");if(!$q->fetchColumn())throw new RuntimeException('No hay un administrador activo en esta demo.');
    if(!$db->query('SELECT COUNT(*) FROM empresa WHERE id=1')->fetchColumn())throw new RuntimeException('Faltan los datos del negocio.');
}
function activarDemo(PDO $db,$name){
    if($name!==null&&!preg_match('/^pos_demo_(abarrotes|ropa|agroquimica|restaurante|ferreteria)$/D',$name))throw new RuntimeException('Nombre de base no válido.');
    if($name!==null)$db->exec('USE `'.$name.'`');$root=dirname(__DIR__,2);$lock=fopen($root.'/storage/operation.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Hay operaciones abiertas. Espera y vuelve a ejecutar para cambiar de base.');
    try{
        if(is_file($root.'/storage/backups/recovery.json'))throw new RuntimeException('Resuelve la recuperación pendiente del respaldo antes de cambiar de base.');
        $selection=$root.'/storage/demo_database.json';
        // Invalida sesiones y copias locales antes de seleccionar otro contexto.
        if(file_put_contents($root.'/storage/session_epoch',bin2hex(random_bytes(16)),LOCK_EX)===false)throw new RuntimeException('No se pudieron cerrar las sesiones previas.');
        if($name===null){
            if(is_file($selection)&&!unlink($selection))throw new RuntimeException('No se pudo volver a la base original.');
            echo "Base original seleccionada. Inicia sesión nuevamente.\n";return;
        }
        $json=json_encode(array('base'=>$name));
        if(file_put_contents($selection,$json,LOCK_EX)!==strlen($json))throw new RuntimeException('No se pudo seleccionar la demo.');
        echo "Base activa: $name. Inicia sesión nuevamente.\n";
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
try{
    $arg=isset($argv[1])?$argv[1]:'';
    if($arg==='--original'){
        activarDemo((new Database())->connect(true),null);exit;
    }
    if(!isset($catalog[$arg])){echo "Uso: php scripts/demos/instalar.php abarrotes|ropa|agroquimica|restaurante|ferreteria [--activar]\nPara volver: php scripts/demos/instalar.php --original\n";exit(1);}
    $demo=$catalog[$arg];$db=(new Database())->connect(true);$q=$db->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?');$q->execute(array($demo['base']));
    if($q->fetchColumn()){
        echo "La base ya existe. Se conserva todo su contenido: ".$demo['base']."\n";verificarDemo($db,$demo);
    }else{
        $sql=file_get_contents(__DIR__.'/'.$arg.'.sql');$sql=preg_replace('/^--[^\r\n]*$/m','',$sql);
        foreach(preg_split('/;[\t ]*(?:\r?\n|$)/',$sql) as $statement){if(trim($statement)!==''){$query=$db->query($statement);if($query)$query->closeCursor();}}
        verificarDemo($db,$demo);echo 'Demo creada: '.$demo['base']."\n";
    }
    if(in_array('--activar',$argv,true))activarDemo($db,$demo['base']);else echo "No se cambió la base activa. Ejecuta de nuevo con --activar para usar esta demo.\n";
    echo "Usuarios: admin, gerente, cajero, cajero2, almacen, compras, consulta.\nContraseña para todos: Admin1.1\n";
    echo "Abre http://localhost:8080/PuntoVenta/public/ y recarga con Ctrl+F5.\n";
}catch(Throwable $e){fwrite(STDERR,"No se completó: ".$e->getMessage()."\nNo se eliminó ninguna base. Si hubo una importación parcial, se conserva para revisar.\n");exit(1);}
