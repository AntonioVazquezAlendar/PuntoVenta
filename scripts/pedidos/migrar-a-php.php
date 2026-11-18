<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../../app/Config/Database.php';
require __DIR__.'/../../app/Helpers/HttpError.php';
require __DIR__.'/../../app/Models/Pedido.php';
$db=(new Database())->connect();Pedido::instalar($db);$cfg=(new Pedido($db))->conexion();
$file=__DIR__.'/../../portal-pedidos/.local/portal.sqlite';$orders=array();
if(is_file($file)){
    if(!in_array('sqlite',PDO::getAvailableDrivers(),true))throw new RuntimeException('Habilita PDO SQLite en PHP CLI para conservar los pedidos anteriores. No se eliminó ningún archivo.');
    $old=new PDO('sqlite:'.$file);$old->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$old->exec('PRAGMA query_only=ON');
    $installation=$old->query('SELECT installation FROM catalog WHERE id=1')->fetchColumn();$count=(int)$old->query('SELECT COUNT(*) FROM orders')->fetchColumn();
    if($count&&$installation!==$cfg['instalacion'])throw new RuntimeException('El portal anterior pertenece a otra instalación. No se importaron pedidos.');
    if($count)$orders=$old->query('SELECT * FROM orders ORDER BY seq')->fetchAll(PDO::FETCH_ASSOC);
}
$added=0;$db->beginTransaction();try{
    foreach($orders as $o){
        BorradorVenta::uuid($o['id']);$q=$db->prepare('SELECT id FROM pedidos WHERE remoto_id=?');$q->execute(array($o['id']));if($q->fetchColumn())continue;
        $items=json_decode($o['items'],true);if(!is_array($items)||!count($items))throw new RuntimeException('Un pedido anterior no es válido; se conservan sus archivos para revisión.');
        if(!in_array($o['status'],array('nuevo','confirmado','listo','en_venta','vendido','cancelado'),true))throw new RuntimeException('Estado anterior no válido.');
        $content=$o;$content['items']=$items;$content['source']='portal_anterior';$content['requires_quote']=false;foreach($items as $l)if(!empty($l['quote_only']))$content['requires_quote']=true;
        $version=max(1,(int)$o['pos_version']);$q=$db->prepare("INSERT INTO pedidos(remoto_id,secuencia,folio,nombre,celular,notas,contenido,total_estimado,estado,version,sincronizado_version,creado_unix,origen) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'portal')");
        $q->execute(array($o['id'],$o['seq'],$o['folio'],$o['name'],$o['phone'],$o['notes'],json_encode($content),Caja::dinero((int)$o['total_cents']),$o['status'],$version,$version,$o['created']));$added++;
        Auditoria::registrar($db,null,'pedido_creado',$o['folio'],'Se conservó un pedido del portal anterior al migrar a PHP',null,array('pedido_id'=>$db->lastInsertId(),'origen'=>'portal_anterior'));
    }
    $db->exec("UPDATE conexion_pedidos SET modo='php' WHERE id=1");$db->commit();
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
echo 'Portal PHP activo. Pedidos anteriores importados: '.$added.". La base anterior se conserva.\n";
echo "Clientes: http://localhost:8080/PuntoVenta/public/pedidos-clientes/\n";
echo "POS y personal: http://localhost:8080/PuntoVenta/public/\n";
