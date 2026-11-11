<?php

require_once __DIR__ . '/Inventario.php';
require_once __DIR__ . '/Proveedor.php';

class Compra
{
    private $db;
    const MAX_CENTAVOS=99999999999999;
    public function __construct(PDO $db) { $this->db=$db; }

    private function autorizar($actorId)
    {
        $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();
        $q=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR SHARE');$q->execute(array($actorId));$q->fetchColumn();
        $actor=(new Usuario($this->db))->buscar($actorId);
        if(!$actor||!$actor['activo']||(empty($actor['permisos']['todo'])&&empty($actor['permisos']['hacer_compras']))) { throw new HttpError('No tienes permiso para administrar compras.',403); }
    }

    public function listar()
    {
        return $this->db->query('SELECT c.id,c.folio,c.estatus,c.proveedor_id,p.nombre AS proveedor_nombre,
            c.ubicacion_id,u.nombre AS ubicacion_nombre,c.subtotal,c.impuesto_total,c.total,c.factura_proveedor,
            c.creado_en,UNIX_TIMESTAMP(c.creado_en) AS creado_unix,c.recibido_en
            FROM compras c JOIN proveedores p ON p.id=c.proveedor_id JOIN ubicaciones u ON u.id=c.ubicacion_id ORDER BY c.id DESC')->fetchAll();
    }

    public function catalogos()
    {
        return array('proveedores'=>(new Proveedor($this->db))->listar(),
            'ubicaciones'=>$this->db->query('SELECT id,nombre,activo,disponible_venta FROM ubicaciones ORDER BY activo DESC,nombre,id')->fetchAll(),
            'impuestos'=>$this->db->query('SELECT id,nombre,tasa,activo FROM impuestos ORDER BY id')->fetchAll(),
            'variantes'=>$this->db->query("SELECT v.id,v.sku,v.nombre AS variante_nombre,v.costo,p.nombre AS producto_nombre,p.impuesto_id,
                u.codigo AS unidad_codigo,u.permite_decimales FROM variantes_producto v JOIN productos p ON p.id=v.producto_id
                JOIN unidades u ON u.id=p.unidad_base_id WHERE v.activo=1 AND p.activo=1 AND p.controla_inventario=1 AND p.tipo_producto<>'servicio'
                ORDER BY p.nombre,v.nombre,v.id")->fetchAll());
    }

    private function base($id,$bloquear=false)
    {
        $q=$this->db->prepare('SELECT * FROM compras WHERE id=?'.($bloquear?' FOR UPDATE':''));$q->execute(array($id));$compra=$q->fetch();
        if(!$compra) { throw new HttpError('Compra no encontrada.',404); }
        $q=$this->db->prepare('SELECT * FROM detalle_compra WHERE compra_id=? ORDER BY variante_id,id'.($bloquear?' FOR UPDATE':''));$q->execute(array($id));
        $compra['detalles']=$q->fetchAll();$compra['version']=$this->version($compra);return $compra;
    }

    private function version(array $compra)
    {
        $parts=array();foreach(array('condicion_pago','vence','proveedor_id','ubicacion_id','factura_proveedor','notas','subtotal','impuesto_total','total','detalles') as $key){$parts[$key]=$compra[$key];}
        $parts['vence']=$compra['condicion_pago']==='credito'?$compra['vence']:null;
        return hash('sha256',json_encode($parts));
    }

    private function revisarVersion(array $datos,array $compra)
    {
        if(!isset($datos['version'])||!is_string($datos['version'])||!hash_equals($compra['version'],$datos['version'])) {
            throw new HttpError('La compra cambió. Vuelve a abrirla y revisa los datos antes de continuar.',409);
        }
    }

    public function obtener($id)
    {
        $compra=$this->base($id);
        $q=$this->db->prepare('SELECT p.nombre AS proveedor_nombre,l.nombre AS ubicacion_nombre,
            u.nombre AS creado_por_nombre,r.nombre AS recibido_por_nombre,UNIX_TIMESTAMP(c.creado_en) AS creado_unix,
            UNIX_TIMESTAMP(c.recibido_en) AS recibido_unix FROM compras c JOIN proveedores p ON p.id=c.proveedor_id
            JOIN ubicaciones l ON l.id=c.ubicacion_id JOIN usuarios u ON u.id=c.creado_por LEFT JOIN usuarios r ON r.id=c.recibido_por WHERE c.id=?');
        $q->execute(array($id));$compra=array_merge($compra,$q->fetch());
        $q=$this->db->prepare('SELECT d.*,v.sku,v.nombre AS variante_nombre,p.nombre AS producto_nombre,u.codigo AS unidad_codigo,u.permite_decimales,
            i.nombre AS impuesto_nombre FROM detalle_compra d JOIN variantes_producto v ON v.id=d.variante_id
            JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id JOIN impuestos i ON i.id=d.impuesto_id
            WHERE d.compra_id=? ORDER BY d.variante_id,d.id');$q->execute(array($id));$compra['detalles']=$q->fetchAll();return $compra;
    }

    private function centavos($texto)
    {
        if(!preg_match('/^[0-9]{1,12}\.[0-9]{2}$/D',(string)$texto)){throw new HttpError('El importe de la compra supera el límite permitido.');}
        return (int)str_replace('.','',$texto);
    }
    private function dinero($centavos) { return intdiv($centavos,100).'.'.str_pad((string)($centavos%100),2,'0',STR_PAD_LEFT); }

    private function referencias($proveedor,$ubicacion)
    {
        foreach(array(array('proveedores',$proveedor),array('ubicaciones',$ubicacion)) as $ref) {
            $q=$this->db->prepare('SELECT id FROM '.$ref[0].' WHERE id=? AND activo=1 FOR SHARE');$q->execute(array($ref[1]));
            if(!$q->fetchColumn()){throw new HttpError('Selecciona un proveedor y una ubicación activos.');}
        }
    }

    // Bloqueo común con inventario: productos ordenados y después variantes ordenadas.
    private function variantes(array $ids)
    {
        sort($ids,SORT_NUMERIC);$marks=implode(',',array_fill(0,count($ids),'?'));
        $q=$this->db->prepare('SELECT id,producto_id FROM variantes_producto WHERE id IN ('.$marks.') ORDER BY id');$q->execute($ids);$map=$q->fetchAll();
        if(count($map)!==count($ids)){throw new HttpError('Una variante ya no está disponible.');}
        $products=array_values(array_unique(array_column($map,'producto_id')));sort($products,SORT_NUMERIC);
        $q=$this->db->prepare('SELECT p.id,p.activo,p.controla_inventario,p.tipo_producto,u.permite_decimales
            FROM productos p JOIN unidades u ON u.id=p.unidad_base_id WHERE p.id IN ('.implode(',',array_fill(0,count($products),'?')).') ORDER BY p.id FOR SHARE');
        $q->execute($products);$productMap=array_column($q->fetchAll(),null,'id');
        $q=$this->db->prepare('SELECT id,producto_id,activo,costo FROM variantes_producto WHERE id IN ('.$marks.') ORDER BY id FOR UPDATE');$q->execute($ids);
        $rows=array();foreach($q->fetchAll() as $v){$p=$productMap[$v['producto_id']];
            if(!$v['activo']||!$p['activo']||!$p['controla_inventario']||$p['tipo_producto']==='servicio'){throw new HttpError('La compra solo puede incluir variantes activas que controlen inventario.');}
            $v['permite_decimales']=$p['permite_decimales'];$rows[$v['id']]=$v;
        }return $rows;
    }

    public function guardar(array $datos,$actorId,$id=null)
    {
        $proveedor=Producto::identificador(isset($datos['proveedor_id'])?$datos['proveedor_id']:null);
        $ubicacion=Producto::identificador(isset($datos['ubicacion_id'])?$datos['ubicacion_id']:null);
        $condicion=isset($datos['condicion_pago'])?$datos['condicion_pago']:null;if($condicion!==null&&!in_array($condicion,array('sin_definir','contado','credito'),true))throw new HttpError('Selecciona contado o crédito para la compra.');$vence=isset($datos['vence'])&&$datos['vence']!==''?$datos['vence']:null;if($vence!==null){$date=is_string($vence)?DateTimeImmutable::createFromFormat('!Y-m-d',$vence):false;if(!$date||$date->format('Y-m-d')!==$vence||$vence<'2023-01-01'||$vence>'9998-12-31')throw new HttpError('Selecciona una fecha de vencimiento válida.');}
        $factura=Producto::texto($datos,'factura_proveedor',60,false);$notas=Producto::texto($datos,'notas',500,false);
        $estado=isset($datos['estatus'])&&is_string($datos['estatus'])?$datos['estatus']:'';
        if(!in_array($estado,array('borrador','pedida'),true)){throw new HttpError('Guarda la compra como borrador o pedida. La recepción es una operación separada.');}
        if(!isset($datos['detalles'])||!is_array($datos['detalles'])||count($datos['detalles'])<1||count($datos['detalles'])>100){throw new HttpError('Agrega entre 1 y 100 renglones.');}
        $lines=array();$seen=array();
        foreach($datos['detalles'] as $line){
            if(!is_array($line)){throw new HttpError('Revisa los renglones de la compra.');}
            $v=Producto::identificador(isset($line['variante_id'])?$line['variante_id']:null);
            if(isset($seen[$v])){throw new HttpError('La misma variante no puede repetirse. Suma su cantidad en un solo renglón.');}$seen[$v]=true;
            $cantidad=Inventario::unidades(isset($line['cantidad'])?$line['cantidad']:null);$costo=Inventario::unidades(isset($line['costo_unitario'])?$line['costo_unitario']:null);
            if($cantidad<=0){throw new HttpError('La cantidad de cada renglón debe ser mayor que cero.');}
            $lines[]=array('variante_id'=>$v,'cantidad'=>Inventario::decimal($cantidad),'costo_unitario'=>Inventario::decimal($costo),
                'impuesto_id'=>Producto::identificador(isset($line['impuesto_id'])?$line['impuesto_id']:null));
        }
        usort($lines,function($a,$b){return (int)$a['variante_id']<=>(int)$b['variante_id'];});
        $huella=hash('sha256',json_encode(array($proveedor,$ubicacion,$factura,$notas,$estado,$lines,$condicion,$vence)));
        $this->db->beginTransaction();
        try{
            $this->autorizar($actorId);$solicitud=null;
            if($id===null){
                $clave=isset($datos['clave'])&&is_string($datos['clave'])?$datos['clave']:'';
                if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$clave)){throw new HttpError('La solicitud no es válida. Vuelve a abrir el formulario.');}
                $this->db->prepare('INSERT IGNORE INTO solicitudes_compra (usuario_id,clave,huella) VALUES (?,?,?)')->execute(array($actorId,$clave,$huella));
                $q=$this->db->prepare('SELECT id,huella,compra_id FROM solicitudes_compra WHERE usuario_id=? AND clave=? FOR UPDATE');$q->execute(array($actorId,$clave));$solicitud=$q->fetch();
                if(!hash_equals($solicitud['huella'],$huella)){throw new HttpError('Esa solicitud ya fue utilizada con otros datos. Vuelve a abrir la compra.',409);}
                if($solicitud['compra_id']!==null){$this->db->commit();return $this->obtener($solicitud['compra_id']);}
            }else{
                $actual=$this->base($id,true);$this->revisarVersion($datos,$actual);
                if(!in_array($actual['estatus'],array('borrador','pedida'),true)){throw new HttpError('Una compra recibida o cancelada ya no se puede editar.',409);}
                if($actual['estatus']==='pedida'&&$estado==='borrador'){throw new HttpError('Una compra pedida no puede regresar a borrador.',409);}
            }
            $effective=$condicion!==null?$condicion:(isset($actual)?$actual['condicion_pago']:'sin_definir');$due=($condicion===null&&isset($actual))?$actual['vence']:$vence;if($effective==='credito'&&$due===null)throw new HttpError('Indica el vencimiento de la compra a crédito.');if($effective!=='credito')$due=null;
            $this->referencias($proveedor,$ubicacion);$variants=$this->variantes(array_keys($seen));
            $subtotal=0;$iva=0;$total=0;
            foreach($lines as &$line){
                if(!$variants[$line['variante_id']]['permite_decimales']&&Inventario::unidades($line['cantidad'])%10000!==0){throw new HttpError('Una variante por pieza solo permite cantidades enteras.');}
                $q=$this->db->prepare('SELECT tasa FROM impuestos WHERE id=? AND activo=1 FOR SHARE');$q->execute(array($line['impuesto_id']));$tasa=$q->fetchColumn();
                if($tasa===false||Inventario::unidades($tasa)>10000){throw new HttpError('Selecciona un impuesto activo con una tasa válida.');}
                $line['tasa_impuesto']=$tasa;
                $q=$this->db->prepare('SELECT s.subtotal,ROUND(s.subtotal*CAST(? AS DECIMAL(6,4)),2) AS impuesto FROM (SELECT ROUND(CAST(? AS DECIMAL(14,4))*CAST(? AS DECIMAL(14,4)),2) AS subtotal) s');
                $q->execute(array($tasa,$line['cantidad'],$line['costo_unitario']));$amount=$q->fetch();
                $s=$this->centavos($amount['subtotal']);$i=$this->centavos($amount['impuesto']);
                $subtotal+=$s;$iva+=$i;$total+=$s+$i;
                if($total>self::MAX_CENTAVOS){throw new HttpError('El total de la compra supera el límite permitido.');}
                $line['subtotal_linea']=$this->dinero($s);$line['impuesto_linea']=$this->dinero($i);$line['total_linea']=$this->dinero($s+$i);
            }unset($line);
            $values=array($proveedor,$ubicacion,$estado,$factura,$this->dinero($subtotal),$this->dinero($iva),$this->dinero($total),$notas);
            if($id===null){
                $values[]=$actorId;$values[]='P-'.bin2hex(random_bytes(10));
                $this->db->prepare('INSERT INTO compras (proveedor_id,ubicacion_id,estatus,factura_proveedor,subtotal,impuesto_total,total,notas,creado_por,folio) VALUES (?,?,?,?,?,?,?,?,?,?)')->execute($values);$id=$this->db->lastInsertId();
                $this->db->prepare('UPDATE compras SET folio=? WHERE id=?')->execute(array('C-'.str_pad($id,8,'0',STR_PAD_LEFT),$id));
                $this->db->prepare('UPDATE solicitudes_compra SET compra_id=? WHERE id=?')->execute(array($id,$solicitud['id']));
            }else{
                $values[]=$id;$this->db->prepare('UPDATE compras SET proveedor_id=?,ubicacion_id=?,estatus=?,factura_proveedor=?,subtotal=?,impuesto_total=?,total=?,notas=? WHERE id=?')->execute($values);
                $this->db->prepare('DELETE FROM detalle_compra WHERE compra_id=?')->execute(array($id));
            }
            $this->db->prepare('UPDATE compras SET condicion_pago=?,vence=? WHERE id=?')->execute(array($effective,$due,$id));
            $insert=$this->db->prepare('INSERT INTO detalle_compra (compra_id,variante_id,cantidad,costo_unitario,impuesto_id,tasa_impuesto,subtotal_linea,impuesto_linea,total_linea) VALUES (?,?,?,?,?,?,?,?,?)');
            foreach($lines as $line){$insert->execute(array($id,$line['variante_id'],$line['cantidad'],$line['costo_unitario'],$line['impuesto_id'],$line['tasa_impuesto'],$line['subtotal_linea'],$line['impuesto_linea'],$line['total_linea']));}
            $this->db->commit();return $this->obtener($id);
        }catch(Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }

    public function cambiarEstado($id,array $datos,$actorId,$accion)
    {
        $this->db->beginTransaction();
        try{
            $this->autorizar($actorId);$compra=$this->base($id,true);$this->revisarVersion($datos,$compra);
            if($accion==='cancelar'){
                if($compra['estatus']==='recibida'){throw new HttpError('Una compra recibida no se puede cancelar desde aquí.',409);}
                $this->db->prepare("UPDATE compras SET estatus='cancelada' WHERE id=?")->execute(array($id));
            }else{
                if($compra['estatus']==='cancelada'){throw new HttpError('Una compra cancelada no se puede recibir.',409);}
                if($compra['estatus']==='recibida'){$this->db->commit();return $this->obtener($id);}
                if(!$compra['detalles']){throw new HttpError('La compra no tiene renglones.',409);}
                $this->referencias($compra['proveedor_id'],$compra['ubicacion_id']);
                $variants=$this->variantes(array_column($compra['detalles'],'variante_id'));
                foreach($compra['detalles'] as $line){
                    $v=$variants[$line['variante_id']];$cantidad=Inventario::unidades($line['cantidad']);
                    if(!$v['permite_decimales']&&$cantidad%10000!==0){throw new HttpError('La cantidad no corresponde a la unidad base actual.',409);}
                    $q=$this->db->prepare('SELECT ubicacion_id,cantidad FROM existencias WHERE variante_id=? ORDER BY ubicacion_id FOR UPDATE');$q->execute(array($line['variante_id']));
                    $global=0;$local=0;
                    foreach($q->fetchAll() as $e){$n=Inventario::unidades($e['cantidad'],true);$global+=$n;if((string)$e['ubicacion_id']===(string)$compra['ubicacion_id']){$local=$n;}}
                    if(!is_int($global)||abs($local+$cantidad)>Inventario::MAXIMO){throw new HttpError('La recepción supera el límite de existencias.');}
                    $nuevoCosto=$line['costo_unitario'];
                    if($global>0){
                        $q=$this->db->prepare('SELECT ROUND((CAST(? AS DECIMAL(30,4))*CAST(? AS DECIMAL(14,4))+CAST(? AS DECIMAL(14,4))*CAST(? AS DECIMAL(14,4)))/(CAST(? AS DECIMAL(30,4))+CAST(? AS DECIMAL(14,4))),4)');
                        $globalTexto=Inventario::decimal($global);$q->execute(array($globalTexto,$v['costo'],$line['cantidad'],$line['costo_unitario'],$globalTexto,$line['cantidad']));$nuevoCosto=$q->fetchColumn();
                    }
                    $this->db->prepare('UPDATE variantes_producto SET costo=? WHERE id=?')->execute(array($nuevoCosto,$line['variante_id']));
                    $this->db->prepare('INSERT INTO existencias (ubicacion_id,variante_id,cantidad) VALUES (?,?,?) ON DUPLICATE KEY UPDATE cantidad=cantidad+?')->execute(array($compra['ubicacion_id'],$line['variante_id'],$line['cantidad'],$line['cantidad']));
                    $this->db->prepare("INSERT INTO movimientos_inventario (ubicacion_id,variante_id,tipo_movimiento,cantidad,costo_unitario,tipo_referencia,referencia_id,usuario_id,notas) VALUES (?,?,'compra',?,?,'compra',?,?,?)")
                        ->execute(array($compra['ubicacion_id'],$line['variante_id'],$line['cantidad'],$line['costo_unitario'],$id,$actorId,'Recepción '.$compra['folio']));
                }
                $this->db->prepare("UPDATE compras SET estatus='recibida',recibido_por=?,recibido_en=CURRENT_TIMESTAMP,saldo_base=IF(condicion_pago='sin_definir',NULL,total),vence=IF(condicion_pago='contado',DATE(CONVERT_TZ(UTC_TIMESTAMP(),'+00:00','-06:00')),vence) WHERE id=?")->execute(array($actorId,$id));
            }
            $this->db->commit();return $this->obtener($id);
        }catch(Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}
