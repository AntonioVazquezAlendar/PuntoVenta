<?php

require_once __DIR__.'/Inventario.php';
require_once __DIR__.'/Caja.php';
require_once __DIR__.'/VentaCatalogo.php';
require_once __DIR__.'/Cliente.php';
require_once __DIR__.'/Credito.php';

class Venta
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public static function veTodas(array $actor){return !empty($actor['permisos']['todo']);}
    private function autorizar($actorId)
    {
        $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();$q=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');$q->execute(array($actorId));$q->fetchColumn();
        $actor=(new Usuario($this->db))->buscar($actorId);if(!$actor||!$actor['activo']||(empty($actor['permisos']['todo'])&&empty($actor['permisos']['vender']))){throw new HttpError('No tienes permiso para registrar ventas.',403);}return $actor;
    }
    private function turno($id,array $actor,$lock=false)
    {
        $q=$this->db->prepare('SELECT caja_id FROM turnos_caja WHERE id=?');$q->execute(array($id));$cajaId=$q->fetchColumn();if(!$cajaId){throw new HttpError('Turno no encontrado.',404);}
        $q=$this->db->prepare('SELECT * FROM cajas WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute(array($cajaId));$caja=$q->fetch();
        $q=$this->db->prepare('SELECT * FROM turnos_caja WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute(array($id));$turno=$q->fetch();
        if(!$caja['activo']||$turno['estatus']!=='abierto'){throw new HttpError('Necesitas una caja activa con un turno abierto.',409);}
        if((string)$turno['abierto_por']!==(string)$actor['id']){throw new HttpError('Solo puedes vender en tu propio turno de caja.',403);}
        $ubicacion=$turno['ubicacion_id']===null?$caja['ubicacion_id']:$turno['ubicacion_id'];
        $q=$this->db->prepare('SELECT * FROM ubicaciones WHERE id=?'.($lock?' FOR SHARE':''));$q->execute(array($ubicacion));$lugar=$q->fetch();
        if(!$lugar||!$lugar['activo']||!$lugar['disponible_venta']){throw new HttpError('No se puede vender desde una ubicación inactiva o de resguardo.',409);}
        $turno['ubicacion_id']=$ubicacion;$turno['ubicacion_nombre']=$lugar['nombre'];$turno['caja_nombre']=$caja['nombre'];return $turno;
    }
    public function contexto(array $actor,$turnoId=null)
    {
        $sql="SELECT t.id,t.caja_id,c.nombre AS caja_nombre,t.abierto_por,u.nombre AS responsable,l.nombre AS ubicacion_nombre FROM turnos_caja t
            JOIN cajas c ON c.id=t.caja_id JOIN ubicaciones l ON l.id=COALESCE(t.ubicacion_id,c.ubicacion_id) JOIN usuarios u ON u.id=t.abierto_por
            WHERE t.estatus='abierto' AND c.activo=1 AND l.activo=1 AND l.disponible_venta=1";$values=array();
        $sql.=' AND t.abierto_por=?';$values[]=$actor['id'];
        $q=$this->db->prepare($sql.' ORDER BY t.id DESC');$q->execute($values);$turnos=$q->fetchAll();
        $id=$turnoId!==null?Producto::identificador($turnoId):($turnos?$turnos[0]['id']:null);$items=array();$codigos=array();
        if($id!==null)$this->turno($id,$actor);
        $q=$this->db->prepare("SELECT t.id,c.nombre AS caja_nombre,l.nombre AS ubicacion_nombre FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id
            JOIN ubicaciones l ON l.id=COALESCE(t.ubicacion_id,c.ubicacion_id) WHERE t.abierto_por=? AND t.estatus='abierto' ORDER BY t.id DESC LIMIT 1");$q->execute(array($actor['id']));$propio=$q->fetch();
        $puedeAbrir=!empty($actor['permisos']['todo'])||!empty($actor['permisos']['operar_caja']);$cajas=array();
        if(!$propio&&$puedeAbrir){$cajas=$this->db->query("SELECT c.id,c.nombre,l.nombre AS ubicacion_nombre FROM cajas c JOIN ubicaciones l ON l.id=c.ubicacion_id
            WHERE c.activo=1 AND l.activo=1 AND l.disponible_venta=1 AND NOT EXISTS (SELECT 1 FROM turnos_caja t WHERE t.caja_id=c.id AND t.estatus='abierto') ORDER BY c.nombre,c.id")->fetchAll();}
        return array('cliente_predeterminado'=>Cliente::predeterminado($this->db),'vista'=>(new VentaCatalogo($this->db))->preferencia($actor['id']),'turnos'=>$turnos,'turno_id'=>$id,'mi_turno'=>$propio?:null,'puede_abrir_caja'=>$puedeAbrir,'cajas_disponibles'=>$cajas,'productos'=>$items,'codigos'=>$codigos,
            'metodos'=>$this->db->query("SELECT id,nombre,tipo FROM metodos_pago WHERE activo=1".(!empty($actor['permisos']['todo'])||!empty($actor['permisos']['vender_credito'])?'':" AND tipo<>'credito'")." ORDER BY id")->fetchAll(),
            'puede_precio'=>!empty($actor['permisos']['todo'])||!empty($actor['permisos']['cambiar_precio']),
            'puede_descuento'=>!empty($actor['permisos']['todo'])||!empty($actor['permisos']['dar_descuento']));
    }
    public function productos(array $actor,array $filtros)
    {
        $id=Producto::identificador(isset($filtros['turno_id'])?$filtros['turno_id']:null);$turno=$this->turno($id,$actor);
        return (new VentaCatalogo($this->db))->buscar($filtros,$turno);
    }
    public function existencias(array $actor,array $filtros)
    {
        $id=Producto::identificador(isset($filtros['turno_id'])?$filtros['turno_id']:null);$variante=Producto::identificador(isset($filtros['variante_id'])?$filtros['variante_id']:null);
        return (new VentaCatalogo($this->db))->existencias($variante,$this->turno($id,$actor));
    }
    private function normalizar(array $datos)
    {
        $turno=Producto::identificador(isset($datos['turno_id'])?$datos['turno_id']:null);$notas=Producto::texto($datos,'notas',500,false);$cliente=Producto::texto($datos,'cliente_nombre',200,false);
        if(!isset($datos['detalles'])||!is_array($datos['detalles'])||count($datos['detalles'])<1||count($datos['detalles'])>100){throw new HttpError('Agrega entre 1 y 100 variantes a la venta.');}
        $clienteId=Producto::identificador(isset($datos['cliente_id'])?$datos['cliente_id']:null,true);
        $lines=array();$seen=array();foreach($datos['detalles'] as $line){if(!is_array($line)){throw new HttpError('Revisa los renglones.');}$v=Producto::identificador(isset($line['variante_id'])?$line['variante_id']:null);
            if(isset($seen[$v])){throw new HttpError('Suma la cantidad de una misma variante en un solo renglón.');}$seen[$v]=true;
            $qty=Inventario::unidades(isset($line['cantidad'])?$line['cantidad']:null);if($qty<=0){throw new HttpError('La cantidad debe ser mayor que cero.');}
            $price=isset($line['precio_unitario'])?Inventario::unidades($line['precio_unitario']):null;
            $discount=Caja::centavos(isset($line['descuento'])?$line['descuento']:'0');
            $lines[]=array('variante_id'=>$v,'cantidad'=>Inventario::decimal($qty),'precio_unitario'=>$price===null?null:Inventario::decimal($price),'descuento'=>Caja::dinero($discount));
        }usort($lines,function($a,$b){return (int)$a['variante_id']<=>(int)$b['variante_id'];});return array('turno_id'=>$turno,'notas'=>$notas,'cliente_nombre'=>$cliente?:'Público general','cliente_id'=>$clienteId,'detalles'=>$lines);
    }
    private function resolver(array $input,array $actor,array $turno)
    {
        $cliente=array('id'=>$input['cliente_id'],'nombre'=>$input['cliente_nombre']);
        if($input['cliente_id']!==null){$q=$this->db->prepare('SELECT id,nombre,activo FROM clientes WHERE id=? FOR UPDATE');$q->execute(array($input['cliente_id']));$c=$q->fetch();if(!$c||!$c['activo'])throw new HttpError('El cliente ya no está disponible. Elige otro o continúa como público general.',409);$cliente['nombre']=$c['nombre'];}
        $ids=array_column($input['detalles'],'variante_id');$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->db->prepare('SELECT producto_id FROM variantes_producto WHERE id IN ('.$marks.')');$q->execute($ids);$productIds=array_values(array_unique($q->fetchAll(PDO::FETCH_COLUMN)));sort($productIds,SORT_NUMERIC);
        if(!$productIds){throw new HttpError('Los productos ya no están disponibles.');}
        $q=$this->db->prepare('SELECT p.id,p.nombre,p.activo,p.controla_inventario,p.tipo_producto,p.unidad_base_id,p.precio_incluye_impuesto,p.impuesto_id,
            u.codigo AS unidad_codigo,u.permite_decimales,i.tasa,i.activo AS impuesto_activo FROM productos p JOIN unidades u ON u.id=p.unidad_base_id JOIN impuestos i ON i.id=p.impuesto_id
            WHERE p.id IN ('.implode(',',array_fill(0,count($productIds),'?')).') ORDER BY p.id FOR SHARE');$q->execute($productIds);$products=array_column($q->fetchAll(),null,'id');
        $q=$this->db->prepare('SELECT id,producto_id,nombre,sku,activo,precio,costo FROM variantes_producto WHERE id IN ('.$marks.') ORDER BY id FOR UPDATE');$q->execute($ids);$variants=array_column($q->fetchAll(),null,'id');
        $lines=array();$sub=0;$tax=0;$discountTotal=0;$total=0;$fingerprints=array();
        foreach($input['detalles'] as $line){
            $v=isset($variants[$line['variante_id']])?$variants[$line['variante_id']]:null;$p=$v?$products[$v['producto_id']]:null;
            if(!$v||!$v['activo']||!$p['activo']||!$p['impuesto_activo']){throw new HttpError('Una variante o su impuesto ya no están disponibles. Actualiza el catálogo.',409);}
            if($p['tipo_producto']==='servicio')$p['controla_inventario']=0;
            if(Inventario::unidades($p['tasa'])>10000){throw new HttpError('El impuesto del producto tiene una tasa no válida.');}
            if(!$p['permite_decimales']&&Inventario::unidades($line['cantidad'])%10000!==0){throw new HttpError('Una variante por pieza solo permite cantidades enteras.');}
            $price=$line['precio_unitario']===null?$v['precio']:$line['precio_unitario'];
            if(Inventario::unidades($price)!==Inventario::unidades($v['precio'])&&empty($actor['permisos']['todo'])&&empty($actor['permisos']['cambiar_precio'])){throw new HttpError('El precio cambió o no tienes permiso para modificarlo. Actualiza el catálogo.',403);}
            $disc=Caja::centavos($line['descuento']);if($disc>0&&empty($actor['permisos']['todo'])&&empty($actor['permisos']['dar_descuento'])){throw new HttpError('No tienes permiso para dar descuentos.',403);}
            $q=$this->db->prepare('SELECT ROUND(CAST(? AS DECIMAL(14,4))*CAST(? AS DECIMAL(14,4)),2)');$q->execute(array($line['cantidad'],$price));$gross=Caja::centavos($q->fetchColumn(),true);
            if($gross>Caja::MAXIMO||$disc>$gross){throw new HttpError('Revisa el precio, la cantidad y el descuento del renglón.');}$net=$gross-$disc;
            if($p['precio_incluye_impuesto']){
                $q=$this->db->prepare('SELECT ROUND(CAST(? AS DECIMAL(14,2))/(1+CAST(? AS DECIMAL(6,4))),2)');$q->execute(array(Caja::dinero($net),$p['tasa']));$base=Caja::centavos($q->fetchColumn(),true);$lineTotal=$net;
            }else{
                $base=$net;$q=$this->db->prepare('SELECT ROUND(CAST(? AS DECIMAL(14,2))*(1+CAST(? AS DECIMAL(6,4))),2)');$q->execute(array(Caja::dinero($base),$p['tasa']));$lineTotal=Caja::centavos($q->fetchColumn(),true);
            }
            $iva=$lineTotal-$base;$sub+=$base;$tax+=$iva;$discountTotal+=$disc;$total+=$lineTotal;
            if($total>Caja::MAXIMO||$discountTotal>Caja::MAXIMO){throw new HttpError('El importe de la venta supera el límite permitido.');}
            $name=$p['nombre'].($v['nombre']?' · '.$v['nombre']:'');preg_match('/^.{0,250}/us',$name,$match);
            $stock='0.0000';if($p['controla_inventario']){$q=$this->db->prepare('SELECT cantidad FROM existencias WHERE ubicacion_id=? AND variante_id=? FOR UPDATE');$q->execute(array($turno['ubicacion_id'],$v['id']));$found=$q->fetchColumn();$stock=$found===false?'0.0000':$found;}
            $lines[]=array('variante_id'=>$v['id'],'sku'=>$v['sku'],'descripcion'=>$match[0],'cantidad'=>$line['cantidad'],'unidad_id'=>$p['unidad_base_id'],'unidad_codigo'=>$p['unidad_codigo'],
                'precio_unitario'=>$price,'costo_unitario'=>$v['costo'],'descuento'=>$line['descuento'],'precio_incluye_impuesto'=>$p['precio_incluye_impuesto'],
                'impuesto_id'=>$p['impuesto_id'],'tasa_impuesto'=>$p['tasa'],'subtotal_linea'=>Caja::dinero($base),'impuesto_linea'=>Caja::dinero($iva),'total_linea'=>Caja::dinero($lineTotal),
                'controla_inventario'=>$p['controla_inventario'],'permite_decimales'=>$p['permite_decimales'],'existencia'=>$stock);
            $fingerprints[]=array($v['id'],$v['precio'],$p['impuesto_id'],$p['tasa'],$p['precio_incluye_impuesto'],$p['unidad_base_id'],$p['controla_inventario'],$price,$line['cantidad'],$line['descuento'],$base,$iva,$lineTotal);
        }
        if($cliente['id']!==null&&(!empty($actor['permisos']['todo'])||!empty($actor['permisos']['vender_credito']))){$cfg=(new Credito($this->db))->disponible($cliente['id']);$default=Cliente::predeterminado($this->db);$cliente['credito']=array('habilitado'=>(bool)$cfg['habilitado']&&(!$default||(string)$default['id']!==(string)$cliente['id']),'disponible'=>$cfg['disponible'],'dias'=>$cfg['dias']);}
        return array('cliente'=>$cliente,'detalles'=>$lines,'subtotal'=>Caja::dinero($sub),'impuesto_total'=>Caja::dinero($tax),'descuento_total'=>Caja::dinero($discountTotal),'total'=>Caja::dinero($total),'sello'=>hash('sha256',json_encode(array($turno['id'],$fingerprints,$cliente['id'],$cliente['id']!==null?$cliente['nombre']:null))));
    }
    public function cotizar(array $datos,$actorId)
    {
        $input=$this->normalizar($datos);$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{$actor=$this->autorizar($actorId);$turno=$this->turno($input['turno_id'],$actor,true);$result=$this->resolver($input,$actor,$turno);foreach($result['detalles'] as &$line){unset($line['costo_unitario']);}unset($line);$this->db->commit();return $result;}
        catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function cobrar(array $datos,$actorId)
    {
        $input=$this->normalizar($datos);$key=isset($datos['clave'])&&is_string($datos['clave'])?$datos['clave']:'';$sello=isset($datos['sello'])&&is_string($datos['sello'])?$datos['sello']:'';
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$key)||!preg_match('/^[a-f0-9]{64}$/D',$sello)){throw new HttpError('Cotiza la venta antes de confirmar el cobro.');}
        if(!isset($datos['pagos'])||!is_array($datos['pagos'])||count($datos['pagos'])>8){throw new HttpError('Revisa los pagos de la venta.');}
        $payments=array();$seen=array();foreach($datos['pagos'] as $pay){if(!is_array($pay)){throw new HttpError('Revisa los pagos.');}$method=Producto::identificador(isset($pay['metodo_pago_id'])?$pay['metodo_pago_id']:null);
            if(isset($seen[$method])){throw new HttpError('Cada método de pago debe aparecer una sola vez.');}$seen[$method]=true;
            $amount=Caja::centavos(isset($pay['monto'])?$pay['monto']:null);if($amount<=0){throw new HttpError('Cada pago debe ser mayor que cero.');}
            $received=isset($pay['recibido'])&&$pay['recibido']!==''?Caja::centavos($pay['recibido']):null;
            $payments[]=array('metodo_pago_id'=>$method,'monto'=>Caja::dinero($amount),'recibido'=>$received===null?null:Caja::dinero($received),'referencia'=>Producto::texto($pay,'referencia',100,false));
        }usort($payments,function($a,$b){return (int)$a['metodo_pago_id']<=>(int)$b['metodo_pago_id'];});
        $huella=hash('sha256',json_encode(array($input,$payments,$sello)));$this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');$this->db->beginTransaction();
        try{
            $actor=$this->autorizar($actorId);
            $this->db->prepare('INSERT IGNORE INTO solicitudes_venta (usuario_id,clave,huella) VALUES (?,?,?)')->execute(array($actorId,$key,$huella));
            $q=$this->db->prepare('SELECT id,huella,resultado FROM solicitudes_venta WHERE usuario_id=? AND clave=? FOR UPDATE');$q->execute(array($actorId,$key));$op=$q->fetch();
            if(!hash_equals($op['huella'],$huella)){throw new HttpError('Esa solicitud ya se usó con otros datos. Revisa la venta antes de volver a cobrar.',409);}
            if($op['resultado']!==null){$this->db->commit();return json_decode($op['resultado'],true);}
            $turno=$this->turno($input['turno_id'],$actor,true);$quote=$this->resolver($input,$actor,$turno);
            if(!hash_equals($quote['sello'],$sello)){throw new HttpError('El catálogo o los importes cambiaron. Vuelve a calcular la venta y revisa los pagos.',409);}
            $sum=0;$cash=0;$change=0;$credito=0;$creditCount=0;
            foreach($payments as &$pay){
                $q=$this->db->prepare('SELECT id,nombre,tipo,activo FROM metodos_pago WHERE id=? FOR SHARE');$q->execute(array($pay['metodo_pago_id']));$m=$q->fetch();
                if(!$m||!$m['activo'])throw new HttpError('Selecciona métodos de pago activos.');
                $n=Caja::centavos($pay['monto']);$sum+=$n;$pay['tipo']=$m['tipo'];$pay['nombre']=$m['nombre'];$pay['cambio']='0.00';
                if($m['tipo']==='efectivo'){$r=$pay['recibido']===null?$n:Caja::centavos($pay['recibido']);if($r<$n){throw new HttpError('El efectivo recibido no cubre el importe aplicado.');}$pay['recibido']=Caja::dinero($r);$pay['cambio']=Caja::dinero($r-$n);$cash+=$n;$change+=$r-$n;}
                elseif($pay['recibido']!==null){throw new HttpError('El campo recibido solo se utiliza para pagos en efectivo.');}
                if($m['tipo']==='credito'){$credito+=$n;if(++$creditCount>1)throw new HttpError('Usa un solo método de crédito por venta.');}
            }unset($pay);
            if($sum!==Caja::centavos($quote['total'])){throw new HttpError('La suma de los pagos debe coincidir exactamente con el total de la venta.');}
            $cfg=null;if($credito){
                if(empty($actor['permisos']['todo'])&&empty($actor['permisos']['vender_credito']))throw new HttpError('No tienes permiso para vender a crédito.',403);
                if($input['cliente_id']===null)throw new HttpError('Selecciona un cliente registrado para vender a crédito.');
                $cfg=(new Credito($this->db))->disponible($input['cliente_id'],true);$default=Cliente::predeterminado($this->db);
                if(!$cfg['habilitado']||!$cfg['activo']||($default&&(string)$default['id']===(string)$input['cliente_id']))throw new HttpError('El crédito no está habilitado para este cliente.');
                if($credito>Caja::centavos($cfg['disponible']))throw new HttpError('El importe a crédito supera el límite disponible del cliente.',409);
            }
            $drawer=(new Caja($this->db))->resumenBloqueado($turno);if(Caja::centavos($drawer['esperado'],true)+$cash>Caja::MAXIMO){throw new HttpError('La venta superaría el límite de efectivo de la caja.');}
            $ticket=$this->db->query('SELECT nombre_comercial,direccion,telefono,correo,logo_ruta,encabezado_ticket,pie_ticket,moneda FROM empresa WHERE id=1 FOR SHARE')->fetch();
            $ticket['caja_nombre']=$turno['caja_nombre'];$ticket['ubicacion_nombre']=$turno['ubicacion_nombre'];$ticket['usuario_nombre']=$actor['nombre'];$ticket['cliente_nombre']=$quote['cliente']['nombre'];$ticket['unidades']=array();
            foreach($quote['detalles'] as $line){$ticket['unidades'][$line['variante_id']]=$line['unidad_codigo'];}
            $this->db->prepare("INSERT INTO ventas (folio,ubicacion_id,caja_id,turno_id,usuario_id,cliente_id,estatus,subtotal,descuento_total,impuesto_total,total,notas,completado_en,ticket_datos) VALUES (?,?,?,?,?,?,'completada',?,?,?,?,?,CURRENT_TIMESTAMP,?)")
                ->execute(array('P-'.bin2hex(random_bytes(10)),$turno['ubicacion_id'],$turno['caja_id'],$turno['id'],$actorId,$input['cliente_id'],$quote['subtotal'],$quote['descuento_total'],$quote['impuesto_total'],$quote['total'],$input['notas'],json_encode($ticket)));
            $id=$this->db->lastInsertId();if($credito){$vence=(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->modify('+'.(int)$cfg['dias'].' days')->format('Y-m-d');$this->db->prepare('INSERT INTO creditos_venta(venta_id,cliente_id,monto_original,vence) VALUES (?,?,?,?)')->execute(array($id,$input['cliente_id'],Caja::dinero($credito),$vence));$ticket['credito_original']=Caja::dinero($credito);$ticket['credito_vence']=$vence;$this->db->prepare('UPDATE ventas SET ticket_datos=? WHERE id=?')->execute(array(json_encode($ticket),$id));}$folio='V-'.str_pad($id,8,'0',STR_PAD_LEFT);$this->db->prepare('UPDATE ventas SET folio=? WHERE id=?')->execute(array($folio,$id));
            $insert=$this->db->prepare('INSERT INTO detalle_venta (venta_id,variante_id,descripcion,cantidad,unidad_id,precio_unitario,costo_unitario,descuento,precio_incluye_impuesto,impuesto_id,tasa_impuesto,subtotal_linea,impuesto_linea,total_linea,controla_inventario,permite_decimales) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($quote['detalles'] as $line){$insert->execute(array($id,$line['variante_id'],$line['descripcion'],$line['cantidad'],$line['unidad_id'],$line['precio_unitario'],$line['costo_unitario'],$line['descuento'],$line['precio_incluye_impuesto'],$line['impuesto_id'],$line['tasa_impuesto'],$line['subtotal_linea'],$line['impuesto_linea'],$line['total_linea'],$line['controla_inventario'],$line['permite_decimales']));
                if($line['controla_inventario']){$balance=Inventario::unidades($line['existencia'],true)-Inventario::unidades($line['cantidad']);if(abs($balance)>Inventario::MAXIMO){throw new HttpError('La venta superaría el límite de inventario.');}
                    $this->db->prepare('INSERT INTO existencias (ubicacion_id,variante_id,cantidad) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cantidad=cantidad+?')->execute(array($turno['ubicacion_id'],$line['variante_id'],Inventario::decimal(-Inventario::unidades($line['cantidad'])),Inventario::decimal(-Inventario::unidades($line['cantidad']))));
                    $this->db->prepare("INSERT INTO movimientos_inventario (ubicacion_id,variante_id,tipo_movimiento,cantidad,costo_unitario,tipo_referencia,referencia_id,usuario_id,notas) VALUES (?,?,'venta',?,?,'venta',?,?,?)")
                        ->execute(array($turno['ubicacion_id'],$line['variante_id'],Inventario::decimal(-Inventario::unidades($line['cantidad'])),$line['costo_unitario'],$id,$actorId,'Venta '.$folio));
                }
            }
            $insert=$this->db->prepare('INSERT INTO pagos_venta (venta_id,metodo_pago_id,monto,recibido,cambio,referencia,tipo_metodo,nombre_metodo) VALUES (?,?,?,?,?,?,?,?)');
            foreach($payments as $pay){$insert->execute(array($id,$pay['metodo_pago_id'],$pay['monto'],$pay['recibido'],$pay['cambio'],$pay['referencia'],$pay['tipo'],$pay['nombre']));}
            $result=array('venta_id'=>$id,'folio'=>$folio,'total'=>$quote['total'],'cambio'=>Caja::dinero($change));
            $this->db->prepare('UPDATE solicitudes_venta SET venta_id=?,resultado=? WHERE id=?')->execute(array($id,json_encode($result),$op['id']));$this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function listar(array $actor,array $filtros)
    {
        $sql="SELECT v.id,v.folio,v.usuario_id,u.nombre AS usuario_nombre,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.ticket_datos,'$.cliente_nombre')),'Público general') AS cliente_nombre,v.estatus,v.total,COALESCE((SELECT SUM(r.total) FROM devoluciones_venta r WHERE r.venta_id=v.id),0.00) AS reembolsado,(SELECT COUNT(*) FROM devoluciones_venta r WHERE r.venta_id=v.id) AS reembolsos_count,v.turno_id,UNIX_TIMESTAMP(v.creado_en) AS creado_unix FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE 1=1";$values=array();
        if(!self::veTodas($actor)){$sql.=' AND v.usuario_id=?';$values[]=$actor['id'];}
        if(isset($filtros['antes_de'])&&$filtros['antes_de']!==''){$sql.=' AND v.id<?';$values[]=Producto::identificador($filtros['antes_de']);}
        $q=$this->db->prepare($sql.' ORDER BY v.id DESC LIMIT 51');$q->execute($values);$rows=$q->fetchAll();$more=count($rows)>50;if($more)array_pop($rows);return array('ventas'=>$rows,'siguiente'=>$more?$rows[count($rows)-1]['id']:null);
    }
    public function obtener($id,array $actor)
    {
        $q=$this->db->prepare('SELECT id,folio,ubicacion_id,caja_id,turno_id,usuario_id,cliente_id,estatus,subtotal,descuento_total,impuesto_total,total,notas,ticket_datos,UNIX_TIMESTAMP(completado_en) AS completado_unix FROM ventas WHERE id=?');$q->execute(array($id));$sale=$q->fetch();if(!$sale){throw new HttpError('Venta no encontrada.',404);}
        if(!self::veTodas($actor)&&(string)$sale['usuario_id']!==(string)$actor['id']){throw new HttpError('Solo puedes consultar tus propias ventas y tickets.',403);}
        $ticket=$sale['ticket_datos']?json_decode($sale['ticket_datos'],true):array('nombre_comercial'=>'PuntoVenta','moneda'=>'MXN');unset($sale['ticket_datos']);$sale['ticket']=$ticket;
        $sale['ticket']['logo_url']=!empty($ticket['logo_ruta'])?'/PuntoVenta/public/api/ventas/'.$id.'/logo':null;unset($sale['ticket']['logo_ruta']);
        $q=$this->db->prepare('SELECT d.id,d.variante_id,d.descripcion,d.cantidad,d.precio_unitario,d.descuento,d.precio_incluye_impuesto,d.tasa_impuesto,d.subtotal_linea,d.impuesto_linea,d.total_linea,u.codigo AS unidad_codigo FROM detalle_venta d LEFT JOIN unidades u ON u.id=d.unidad_id WHERE d.venta_id=? ORDER BY d.id');$q->execute(array($id));$sale['detalles']=$q->fetchAll();
        foreach($sale['detalles'] as &$line){if(isset($ticket['unidades'][$line['variante_id']]))$line['unidad_codigo']=$ticket['unidades'][$line['variante_id']];}unset($line,$sale['ticket']['unidades']);
        $q=$this->db->prepare('SELECT p.id,p.monto,p.recibido,p.cambio,p.referencia,COALESCE(p.tipo_metodo,m.tipo) AS tipo,COALESCE(p.nombre_metodo,m.nombre) AS nombre FROM pagos_venta p JOIN metodos_pago m ON m.id=p.metodo_pago_id WHERE p.venta_id=? AND p.abono_id IS NULL ORDER BY p.id');$q->execute(array($id));$sale['pagos']=$q->fetchAll();return $sale;
    }
    public function logo($id,array $actor)
    {
        $this->obtener($id,$actor);$q=$this->db->prepare('SELECT ticket_datos FROM ventas WHERE id=?');$q->execute(array($id));$ticket=json_decode($q->fetchColumn(),true);$path=isset($ticket['logo_ruta'])?$ticket['logo_ruta']:'';
        if(!is_string($path)||!preg_match('#^uploads/logos/[a-f0-9]{32}\.(png|jpg|webp)$#D',$path,$m)||!is_file(__DIR__.'/../../storage/'.$path)){throw new HttpError('Logo no encontrado.',404);}
        $types=array('png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp');header('Content-Type: '.$types[$m[1]]);readfile(__DIR__.'/../../storage/'.$path);exit;
    }
}
