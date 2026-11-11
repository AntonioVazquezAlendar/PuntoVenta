<?php
class BackupGate {
    private static $lock;
    public static function acquire(){
        $root=dirname(__DIR__,2).'/storage';self::$lock=fopen($root.'/operation.lock','c');if(!self::$lock)throw new RuntimeException('No se pudo proteger la operación.');
        $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$exclusive=$_SERVER['REQUEST_METHOD']==='POST'&&in_array($path,array('/PuntoVenta/public/api/respaldos','/PuntoVenta/public/api/respaldos/restaurar'),true);$until=microtime(true)+10;
        while(true){$recover=is_file($root.'/backups/recovery.json');$mode=($exclusive||$recover)?LOCK_EX:LOCK_SH;if(flock(self::$lock,$mode|LOCK_NB)){
            if(is_file($root.'/backups/recovery.json')&&$mode!==LOCK_EX){flock(self::$lock,LOCK_UN);continue;}
            if(is_file($root.'/backups/recovery.json')){require_once __DIR__.'/Respaldo.php';(new Respaldo((new Database())->connect()))->recoverJournal();}return;
        }if(microtime(true)>$until)throw new HttpError('Hay un respaldo o una restauración en curso. Espera y vuelve a intentar.',503);usleep(100000);}
    }
}
