<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/Config/Database.php';require __DIR__.'/../app/Helpers/HttpError.php';require __DIR__.'/../app/Services/Auth.php';require __DIR__.'/../app/Models/Venta.php';require __DIR__.'/../app/Models/Inicio.php';require __DIR__.'/../app/Models/ConfiguracionAdmin.php';
$db=(new Database())->connect();$original=$db->query('SELECT DATABASE()')->fetchColumn();$catalog=json_decode(file_get_contents(__DIR__.'/../scripts/demos/catalogo.json'),true);
function demoCheck($ok,$message){if(!$ok)throw new RuntimeException($message);}
function demoUuid(){$s=bin2hex(random_bytes(16));return substr($s,0,8).'-'.substr($s,8,4).'-'.substr($s,12,4).'-'.substr($s,16,4).'-'.substr($s,20);}
foreach($catalog as $key=>$meta){
    $qa='qa_demoseed_'.bin2hex(random_bytes(6));
    try{
        $sql=file_get_contents(__DIR__.'/../scripts/demos/'.$key.'.sql');$sql=str_replace('`'.$meta['base'].'`','`'.$qa.'`',$sql);$sql=preg_replace('/^--[^\r\n]*$/m','',$sql);
        foreach(preg_split('/;[\t ]*(?:\r?\n|$)/',$sql) as $stmt)if(trim($stmt)!==''){$q=$db->query($stmt);if($q)$q->closeCursor();}
        demoCheck((int)$db->query('SELECT COUNT(*) FROM productos')->fetchColumn()===$meta['productos'],'Product count');demoCheck((int)$db->query('SELECT COUNT(*) FROM variantes_producto')->fetchColumn()===$meta['variantes'],'Variant count');
        foreach($db->query('SELECT contrasena_hash FROM usuarios')->fetchAll(PDO::FETCH_COLUMN) as $hash)demoCheck(password_verify('Admin1.1',$hash),'Password');
        demoCheck((int)$db->query("SELECT COUNT(*) FROM existencias e LEFT JOIN (SELECT ubicacion_id,variante_id,SUM(cantidad) total FROM movimientos_inventario GROUP BY ubicacion_id,variante_id) m USING(ubicacion_id,variante_id) WHERE e.cantidad<>COALESCE(m.total,0)")->fetchColumn()===0,'Stock / ledger mismatch');
        $q=$db->prepare('SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_SCHEMA<>?');$q->execute(array($qa,$qa));demoCheck((int)$q->fetchColumn()===0,'References outside QA');
        $u=new Usuario($db);$admin=$u->buscar(1);demoCheck(!empty($admin['permisos']['todo']),'Admin');demoCheck(empty($u->buscar(2)['permisos']['todo']),'Manager admin escalation');
        $cfg=(new ConfiguracionAdmin($db))->obtener();demoCheck($cfg['sistema']['moneda']==='MXN','Config');
        $inicio=(new Inicio($db))->obtener($admin);demoCheck($inicio['ventas']['neto']==='0.00','Initial sales');
        $cashier=$u->buscar(3);$box=new Caja($db);$turn=$box->abrir(array('caja_id'=>'1','monto_apertura'=>'500','clave'=>demoUuid()),3);$turnId=$turn['turno_id'];
        $sale=new Venta($db);$context=$sale->contexto($cashier);demoCheck((string)$context['turno_id']===(string)$turnId,'POS context');
        $item=$db->query('SELECT id,precio FROM variantes_producto ORDER BY id LIMIT 1')->fetch();$input=array('turno_id'=>$turnId,'cliente_id'=>null,'cliente_nombre'=>'Cliente de prueba','notas'=>'Prueba temporal de la demo','detalles'=>array(array('variante_id'=>$item['id'],'cantidad'=>'1','precio_unitario'=>$item['precio'],'descuento'=>'0')));
        $quote=$sale->cotizar($input,3);$payload=$input;$payload['sello']=$quote['sello'];$payload['clave']=demoUuid();$payload['pagos']=array(array('metodo_pago_id'=>'1','monto'=>$quote['total'],'recibido'=>null,'referencia'=>''));$paid=$sale->cobrar($payload,3);demoCheck($paid==$sale->cobrar($payload,3),'Repeated sale');
        $ticket=$sale->obtener($paid['venta_id'],$cashier);demoCheck($ticket['ticket']['nombre_comercial']===$meta['nombre'],'Receipt brand');
        $summary=$box->obtener($turnId,$cashier)['resumen'];$closed=$box->operar($turnId,array('version'=>$summary['version'],'monto_cierre'=>$summary['esperado'],'notas'=>'Prueba temporal','clave'=>demoUuid()),3,'cerrar');
        demoCheck($box->obtener($turnId,$cashier)['estatus']==='cerrado','Close');
        echo 'OK '.$key.': '.$meta['productos'].' productos, '.$meta['variantes']." variantes, contraseñas, permisos, configuración, inventario, venta única y corte.\n";
    }finally{
        if($db->inTransaction())$db->rollBack();$db->exec('SET FOREIGN_KEY_CHECKS=1');
        if(!preg_match('/^qa_demoseed_[a-f0-9]{12}$/D',$qa)||$qa===$original)throw new RuntimeException('Unsafe QA database');$db->exec('USE `'.$original.'`');$db->exec('DROP DATABASE IF EXISTS `'.$qa.'`');
    }
}
echo "Bases temporales retiradas. Base original y su configuración conservadas.\n";
