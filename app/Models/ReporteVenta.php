<?php
require_once __DIR__.'/Producto.php';

class ReporteVenta
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    private function consultar($sql,array $args){$q=$this->db->prepare($sql);$q->execute($args);return $q->fetchAll();}
    public function filtros(array $datos,array $actor){
        $today=(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d');
        $dates=array();foreach(array('desde','hasta') as $key){$v=isset($datos[$key])?$datos[$key]:$today;$d=is_string($v)?DateTimeImmutable::createFromFormat('!Y-m-d',$v,new DateTimeZone('America/Mexico_City')):false;if(!$d||$d->format('Y-m-d')!==$v||$v<'2023-01-01'||$v>'9998-12-31')throw new HttpError('Selecciona fechas válidas a partir de 2023.');$dates[$key]=$d;}
        $days=(int)$dates['desde']->diff($dates['hasta'])->format('%r%a');if($days<0||$days>365)throw new HttpError('Elige un rango de hasta 366 días, con inicio anterior o igual al fin.');
        $user=Producto::identificador(isset($datos['usuario_id'])&&$datos['usuario_id']!==''?$datos['usuario_id']:null,true);
        if(empty($actor['permisos']['todo'])){if($user!==null&&(string)$user!==(string)$actor['id'])throw new HttpError('Solo puedes consultar tus propios reportes.',403);$user=$actor['id'];}
        return array('desde'=>$dates['desde']->format('Y-m-d'),'hasta'=>$dates['hasta']->format('Y-m-d'),'fin'=>$dates['hasta']->modify('+1 day')->format('Y-m-d'),'usuario_id'=>$user,'caja_id'=>Producto::identificador(isset($datos['caja_id'])&&$datos['caja_id']!==''?$datos['caja_id']:null,true));
    }
    private function eventos(array $f){
        $args=array();$w=array();foreach(array('v','r') as $alias){$where=$alias.($alias==='v'?'.completado_en':'.creado_en').' >= ? AND '.$alias.($alias==='v'?'.completado_en':'.creado_en').' < ?';$args[]=$f['desde'];$args[]=$f['fin'];if($f['usuario_id']!==null){$where.=' AND '.$alias.'.usuario_id=?';$args[]=$f['usuario_id'];}if($f['caja_id']!==null){$where.=' AND '.($alias==='v'?'v':'t').'.caja_id=?';$args[]=$f['caja_id'];}$w[$alias]=$where;}
        $sql="SELECT v.id,'venta' tipo,v.folio,v.completado_en fecha,v.usuario_id cajero_id,v.caja_id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.ticket_datos,'$.usuario_nombre')),u.nombre) cajero,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.ticket_datos,'$.caja_nombre')),c.nombre) caja,v.total,v.subtotal,v.impuesto_total impuestos,COALESCE((SELECT SUM(d.cantidad*d.costo_unitario) FROM detalle_venta d WHERE d.venta_id=v.id),0) costo FROM ventas v JOIN usuarios u ON u.id=v.usuario_id JOIN cajas c ON c.id=v.caja_id WHERE ".$w['v']." AND (v.estatus='completada' OR (v.estatus='cancelada' AND EXISTS(SELECT 1 FROM devoluciones_venta x WHERE x.venta_id=v.id AND x.tipo='cancelacion')))
        UNION ALL SELECT r.id,r.tipo,r.folio,r.creado_en,r.usuario_id,t.caja_id,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.datos,'$.usuario_nombre')),u.nombre),COALESCE(JSON_UNQUOTE(JSON_EXTRACT(r.datos,'$.caja_nombre')),c.nombre),-r.total,-r.subtotal,-r.impuesto_total,-COALESCE((SELECT SUM(d.cantidad*v.costo_unitario) FROM detalle_devolucion d JOIN detalle_venta v ON v.id=d.detalle_venta_id WHERE d.devolucion_id=r.id),0) FROM devoluciones_venta r JOIN turnos_caja t ON t.id=r.turno_id JOIN cajas c ON c.id=t.caja_id JOIN usuarios u ON u.id=r.usuario_id WHERE ".$w['r'];
        return array($sql,$args);
    }
    private function sumas(){return "SUM(tipo='venta') tickets,SUM(tipo<>'venta') reembolsos,CAST(COALESCE(SUM(CASE WHEN tipo='venta' THEN total ELSE 0 END),0) AS DECIMAL(24,2)) ventas,CAST(-COALESCE(SUM(CASE WHEN tipo<>'venta' THEN total ELSE 0 END),0) AS DECIMAL(24,2)) devuelto,CAST(COALESCE(SUM(total),0) AS DECIMAL(24,2)) neto,CAST(COALESCE(SUM(subtotal),0) AS DECIMAL(24,2)) sin_impuestos,CAST(COALESCE(SUM(impuestos),0) AS DECIMAL(24,2)) impuestos,CAST(COALESCE(SUM(costo),0) AS DECIMAL(24,2)) costo,CAST(COALESCE(SUM(subtotal-costo),0) AS DECIMAL(24,2)) utilidad";}
    public function resumen(array $f){list($sql,$args)=$this->eventos($f);return $this->consultar('SELECT '.$this->sumas().' FROM ('.$sql.') e',$args)[0];}
    public function catalogos(array $actor){
        $all=!empty($actor['permisos']['todo']);
        return array('todos'=>$all,'hoy'=>(new DateTimeImmutable('now',new DateTimeZone('America/Mexico_City')))->format('Y-m-d'),'usuarios'=>$all?$this->db->query('SELECT id,nombre,activo FROM usuarios ORDER BY nombre,id')->fetchAll():array(array('id'=>$actor['id'],'nombre'=>$actor['nombre'],'activo'=>$actor['activo'])),
            'cajas'=>$all?$this->db->query('SELECT id,nombre,activo FROM cajas ORDER BY nombre,id')->fetchAll():$this->consultar('SELECT DISTINCT c.id,c.nombre,c.activo FROM cajas c JOIN turnos_caja t ON t.caja_id=c.id WHERE t.abierto_por=? ORDER BY c.nombre,c.id',array($actor['id'])));
    }
    public function obtener(array $datos,array $actor,$exportar=false){
        $f=$this->filtros($datos,$actor);$page=isset($datos['pagina'])?$datos['pagina']:'1';if(!is_scalar($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$page))throw new HttpError('Página inválida.');$page=(int)$page;
        $this->db->exec("SET time_zone='-06:00'");$this->db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$this->db->beginTransaction();try{
            list($sql,$args)=$this->eventos($f);$base=' FROM ('.$sql.') e';$sum=$this->sumas();$summary=$this->resumen($f);
            $count=(int)$summary['tickets']+(int)$summary['reembolsos'];if($exportar&&$count>10000)throw new HttpError('La exportación admite hasta 10,000 movimientos. Reduce el rango o aplica filtros.',422);
            $result=array('filtros'=>array_diff_key($f,array('fin'=>true)),'todos'=>!empty($actor['permisos']['todo']),'resumen'=>$summary,
                'dias'=>$this->consultar('SELECT DATE(fecha) dia,'.$sum.$base.' GROUP BY DATE(fecha) ORDER BY dia',$args),
                'cajeros'=>$this->consultar('SELECT cajero_id,MAX(cajero) nombre,'.$sum.$base.' GROUP BY cajero_id ORDER BY neto DESC,cajero_id',$args),
                'cajas'=>$this->consultar('SELECT caja_id,MAX(caja) nombre,'.$sum.$base.' GROUP BY caja_id ORDER BY neto DESC,caja_id',$args));
            $paymentSql="SELECT tipo_metodo,MAX(nombre_metodo) nombre,CAST(SUM(cobrado) AS DECIMAL(24,2)) cobrado,CAST(SUM(devuelto) AS DECIMAL(24,2)) devuelto,CAST(SUM(cobrado-devuelto) AS DECIMAL(24,2)) neto FROM (SELECT COALESCE(p.tipo_metodo,m.tipo) tipo_metodo,COALESCE(p.nombre_metodo,m.nombre) nombre_metodo,p.monto cobrado,0 devuelto FROM (".$sql.") e JOIN pagos_venta p ON e.tipo='venta' AND p.venta_id=e.id AND p.abono_id IS NULL JOIN metodos_pago m ON m.id=p.metodo_pago_id UNION ALL SELECT p.tipo_metodo,p.nombre_metodo,0,p.monto FROM (".$sql.") e JOIN pagos_devolucion p ON e.tipo<>'venta' AND p.devolucion_id=e.id) pagos GROUP BY tipo_metodo ORDER BY tipo_metodo";
            $result['pagos']=$this->consultar($paymentSql,array_merge($args,$args));
            $productSql="SELECT variante_id,MAX(descripcion) nombre,MAX(unidad) unidad,CAST(SUM(vendidas) AS DECIMAL(24,4)) vendidas,CAST(SUM(devueltas) AS DECIMAL(24,4)) devueltas,CAST(SUM(vendidas-devueltas) AS DECIMAL(24,4)) cantidad_neta,CAST(SUM(importe) AS DECIMAL(24,2)) neto FROM (SELECT d.variante_id,d.descripcion,COALESCE(u.codigo,'') unidad,d.cantidad vendidas,0 devueltas,d.total_linea importe FROM (".$sql.") e JOIN detalle_venta d ON e.tipo='venta' AND d.venta_id=e.id LEFT JOIN unidades u ON u.id=d.unidad_id UNION ALL SELECT v.variante_id,v.descripcion,COALESCE(u.codigo,''),0,d.cantidad,-d.total_linea FROM (".$sql.") e JOIN detalle_devolucion d ON e.tipo<>'venta' AND d.devolucion_id=e.id JOIN detalle_venta v ON v.id=d.detalle_venta_id LEFT JOIN unidades u ON u.id=v.unidad_id) productos GROUP BY variante_id ORDER BY neto DESC,variante_id LIMIT 30";
            $result['productos']=$this->consultar($productSql,array_merge($args,$args));
            $limit=$exportar?10000:30;$offset=$exportar?0:($page-1)*30;
            $result['movimientos']=$this->consultar("SELECT id,tipo,folio,fecha,cajero,caja,total,subtotal,impuestos,CAST(costo AS DECIMAL(24,2)) costo,CAST(subtotal-costo AS DECIMAL(24,2)) utilidad".$base.' ORDER BY fecha DESC,tipo,id DESC LIMIT '.$limit.' OFFSET '.$offset,$args);
            $aw='a.creado_en>=? AND a.creado_en<?';$aa=array($f['desde'],$f['fin']);if($f['usuario_id']!==null){$aw.=' AND a.usuario_id=?';$aa[]=$f['usuario_id'];}if($f['caja_id']!==null){$aw.=' AND t.caja_id=?';$aa[]=$f['caja_id'];}
            $result['abonos']=$this->consultar('SELECT p.tipo_metodo tipo,COUNT(*) recibos,CAST(SUM(a.monto) AS DECIMAL(24,2)) cobrado FROM abonos_cliente a JOIN pagos_venta p ON p.abono_id=a.id JOIN turnos_caja t ON t.id=a.turno_id WHERE '.$aw.' GROUP BY p.tipo_metodo ORDER BY p.tipo_metodo',$aa);
            $pw='p.creado_en>=? AND p.creado_en<?';$pa=array($f['desde'],$f['fin']);if($f['usuario_id']!==null){$pw.=' AND p.usuario_id=?';$pa[]=$f['usuario_id'];}if($f['caja_id']!==null){$pw.=' AND t.caja_id=?';$pa[]=$f['caja_id'];}
            $result['pagos_proveedores']=$this->consultar('SELECT p.origen,p.tipo_metodo tipo,COUNT(*) recibos,CAST(SUM(p.monto) AS DECIMAL(24,2)) pagado FROM pagos_proveedor p LEFT JOIN turnos_caja t ON t.id=p.turno_id WHERE '.$pw.' GROUP BY p.origen,p.tipo_metodo ORDER BY p.origen,p.tipo_metodo',$pa);
            $gw='g.fecha>=? AND g.fecha<?';$ga=array($f['desde'],$f['fin']);if($f['usuario_id']!==null){$gw.=' AND g.usuario_id=?';$ga[]=$f['usuario_id'];}if($f['caja_id']!==null){$gw.=' AND t.caja_id=?';$ga[]=$f['caja_id'];}
            $gb=' FROM gastos g JOIN categorias_gasto cg ON cg.id=g.categoria_id LEFT JOIN turnos_caja t ON t.id=g.turno_id WHERE '.$gw;
            $expense=$this->consultar('SELECT CAST(COALESCE(SUM(g.monto),0) AS DECIMAL(24,2)) total,CAST(CAST(? AS DECIMAL(24,2))-COALESCE(SUM(g.monto),0) AS DECIMAL(24,2)) resultado'.$gb,array_merge(array($summary['utilidad']),$ga))[0];
            $result['resumen']['gastos']=$expense['total'];$result['resumen']['resultado_despues_gastos']=$expense['resultado'];
            $result['gastos']=$this->consultar('SELECT cg.nombre categoria,g.origen,COUNT(*) registros,CAST(SUM(g.monto) AS DECIMAL(24,2)) total'.$gb.' GROUP BY cg.id,cg.nombre,g.origen ORDER BY total DESC,cg.id,g.origen',$ga);
            $result['pagina']=$page;$result['paginas']=max(1,(int)ceil($count/30));$result['total_movimientos']=$count;
            $this->db->commit();return $result;
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
