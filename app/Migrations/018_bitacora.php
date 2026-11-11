<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';require_once __DIR__.'/../Services/Auditoria.php';
$lock=fopen(dirname(__DIR__,2).'/storage/operation.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Hay operaciones en curso. Reintenta la migración.\n");exit(1);}
$db=(new Database())->connect();$db->exec('CREATE TABLE IF NOT EXISTS bitacora_actividad (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,usuario_id BIGINT UNSIGNED NULL,usuario_nombre VARCHAR(150) NOT NULL,usuario_login VARCHAR(60) NULL,
 tipo VARCHAR(40) NOT NULL,referencia VARCHAR(100) NOT NULL,resumen VARCHAR(500) NOT NULL,detalle JSON NOT NULL,
 origen VARCHAR(20) NOT NULL DEFAULT \'cambio\',origen_id BIGINT UNSIGNED NULL,historico TINYINT(1) NOT NULL DEFAULT 0,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_bitacora_origen(origen,origen_id),KEY ix_bitacora_fecha(creado_en,id),KEY ix_bitacora_usuario(usuario_id,creado_en,id),KEY ix_bitacora_tipo(tipo,creado_en,id)
) ENGINE=InnoDB');
try{foreach(array('ajuste'=>'ajustes_inventario','almacen'=>'operaciones_almacen','reembolso'=>'devoluciones_venta','gasto'=>'gastos') as $source=>$table){$last='0';while(true){$q=$db->prepare('SELECT id FROM '.$table.' WHERE id>? ORDER BY id LIMIT 200');$q->execute(array($last));$ids=$q->fetchAll(PDO::FETCH_COLUMN);if(!$ids)break;$db->beginTransaction();foreach($ids as $id){Auditoria::operacion($db,$source,$id,true);$last=$id;}$db->commit();}}echo "Bitácora preparada. Historial disponible incorporado sin duplicar documentos.\n";}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}finally{flock($lock,LOCK_UN);fclose($lock);}
