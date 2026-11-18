<?php
if(PHP_SAPI!=='cli')exit;
require __DIR__.'/../app/Config/Database.php';require __DIR__.'/../app/Helpers/HttpError.php';require __DIR__.'/../app/Models/Pedido.php';require __DIR__.'/../app/Models/Usuario.php';
$db=(new Database())->connect();$mode=isset($argv[1])?$argv[1]:'crear';
if($mode==='crear'){
    $qa='qa_portalphp_'.bin2hex(random_bytes(6));$sql=str_replace('pos_demo_abarrotes',$qa,file_get_contents(__DIR__.'/../scripts/demos/abarrotes.sql'));$db->exec($sql);$db->exec('USE `'.$qa.'`');Pedido::instalar($db);
    $ids=$db->query('SELECT v.id FROM variantes_producto v JOIN productos p ON p.id=v.producto_id WHERE v.activo=1 AND p.activo=1 AND p.controla_inventario=1 ORDER BY v.id LIMIT 2')->fetchAll(PDO::FETCH_COLUMN);
    $db->prepare('UPDATE variantes_producto SET precio=100 WHERE id=?')->execute(array($ids[0]));$db->prepare('UPDATE variantes_producto SET precio=50 WHERE id=?')->execute(array($ids[1]));$db->prepare('UPDATE productos p JOIN variantes_producto v ON v.producto_id=p.id SET p.precio_incluye_impuesto=1 WHERE v.id IN (?,?)')->execute($ids);
    $db->exec("UPDATE empresa SET nombre_comercial='Portal PHP de prueba' WHERE id=1");$db->prepare('INSERT INTO catalogo_pedidos(variante_id,cotizacion) VALUES(?,1)')->execute(array($ids[1]));
    echo json_encode(array('base'=>$qa,'variante'=>(string)$ids[0],'cotizacion'=>(string)$ids[1]));exit;
}
$qa=isset($argv[2])?$argv[2]:'';if(!preg_match('/^qa_portalphp_[a-f0-9]{12}$/D',$qa))throw new RuntimeException('Base QA inválida.');
if($mode==='limpiar'){$db->exec('DROP DATABASE `'.$qa.'`');exit;}$db->exec('USE `'.$qa.'`');
if($mode==='precio'){$db->exec('UPDATE variantes_producto SET precio=137 WHERE id=(SELECT id FROM (SELECT MIN(id) id FROM variantes_producto) q)');exit;}
if($mode!=='verificar')throw new RuntimeException('Modo inválido.');
$checks=0;$check=function($ok,$message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$check((int)$db->query('SELECT COUNT(*) FROM ventas')->fetchColumn()===0,'El portal registró ventas antes del cobro.');$check((int)$db->query("SELECT COUNT(*) FROM turnos_caja WHERE estatus='abierto'")->fetchColumn()===0,'El portal abrió una caja.');
$admin=$db->query("SELECT u.id FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE JSON_EXTRACT(r.permisos,'$.todo')=true LIMIT 1")->fetchColumn();$model=new Pedido($db);$order=$db->query("SELECT id FROM pedidos WHERE nombre='Cliente PHP' ORDER BY id LIMIT 1")->fetchColumn();$o=$model->obtener($order,$admin);
$check($o['origen']==='portal'&&$o['celular']==='5550007777','El pedido no llegó al POS con contacto correcto.');$check($o['total_estimado']==='200.00','La instantánea del pedido cambió al actualizar el precio.');
$box=$db->query('SELECT c.id,c.ubicacion_id FROM cajas c JOIN ubicaciones u ON u.id=c.ubicacion_id WHERE c.activo=1 AND u.activo=1 AND u.disponible_venta=1 LIMIT 1')->fetch();$cash=new Caja($db);$turn=$cash->abrir(array('caja_id'=>$box['id'],'monto_apertura'=>'500','notas'=>'QA portal PHP','clave'=>Pedido::uuid()),$admin)['turno_id'];
$variant=$o['contenido']['items'][0]['id'];$balance=function()use($db,$box,$variant){$q=$db->prepare('SELECT cantidad FROM existencias WHERE ubicacion_id=? AND variante_id=?');$q->execute(array($box['ubicacion_id'],$variant));return Inventario::unidades($q->fetchColumn(),true);};$before=$balance();$draft=$model->preparar($order,array('turno_id'=>$turn),$admin);$check($balance()===$before,'Preparar pedido alteró existencias.');
$drafts=new BorradorVenta($db);$user=(new Usuario($db))->buscar($admin);$saved=$drafts->obtener($draft['borrador_id'],$user);$payload=$saved['contenido']['venta'];$sale=new Venta($db);$quote=$sale->cotizar($payload,$admin);$method=$db->query("SELECT id FROM metodos_pago WHERE activo=1 AND tipo='efectivo' LIMIT 1")->fetchColumn();$payload['pagos']=array(array('metodo_pago_id'=>$method,'monto'=>$quote['total'],'recibido'=>$quote['total'],'referencia'=>''));$payload['sello']=$quote['sello'];$payload['clave']=Pedido::uuid();$charge=$drafts->preparar($saved['id'],array('version'=>$saved['version'],'cobro'=>$payload),$admin);$result=$sale->cobrar($charge['cobro'],$admin);$again=$sale->cobrar($charge['cobro'],$admin);
$check($result['venta_id']===$again['venta_id']&&$balance()===$before-20000,'Cobro duplicado o inventario incorrecto.');$check($model->obtener($order,$admin)['estado']==='vendido','No se actualizó el estado del pedido.');$check(!empty($model->sincronizar($admin)['integrado']),'Se intentó conectar al portal anterior.');
echo 'OK: '.$checks." comprobaciones PHP/MySQL de pedido público a cobro único.\n";
