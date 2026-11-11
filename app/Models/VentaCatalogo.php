<?php

class VentaCatalogo
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public function preferencia($usuarioId)
    {
        $q=$this->db->prepare('SELECT vista FROM preferencias_pdv WHERE usuario_id=?');$q->execute(array($usuarioId));return $q->fetchColumn()?:'busqueda';
    }
    public function guardarPreferencia(array $datos,$usuarioId)
    {
        $vista=isset($datos['vista'])?$datos['vista']:null;if(!is_string($vista)||!in_array($vista,array('busqueda','mosaico'),true)){throw new HttpError('Selecciona búsqueda o mosaico.');}
        $this->db->prepare('INSERT INTO preferencias_pdv (usuario_id,vista) VALUES (?,?) ON DUPLICATE KEY UPDATE vista=VALUES(vista)')->execute(array($usuarioId,$vista));return array('vista'=>$vista);
    }
    public function existencias($varianteId,array $turno)
    {
        $q=$this->db->prepare('SELECT v.id,v.sku,p.nombre AS producto_nombre,v.nombre AS variante_nombre,p.controla_inventario,p.tipo_producto,u.codigo AS unidad_codigo FROM variantes_producto v JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id WHERE v.id=? AND v.activo=1 AND p.activo=1');$q->execute(array($varianteId));$producto=$q->fetch();
        if(!$producto)throw new HttpError('El producto ya no está disponible. Actualiza el catálogo.',404);
        if($producto['tipo_producto']==='servicio')$producto['controla_inventario']=0;
        $ubicaciones=array();
        if($producto['controla_inventario']){
            $q=$this->db->prepare('SELECT l.id,l.nombre,l.activo,l.disponible_venta,COALESCE(e.cantidad,0.0000) AS cantidad FROM ubicaciones l LEFT JOIN existencias e ON e.ubicacion_id=l.id AND e.variante_id=? ORDER BY (l.id=?) DESC,l.activo DESC,l.disponible_venta DESC,l.nombre,l.id');$q->execute(array($varianteId,$turno['ubicacion_id']));$ubicaciones=$q->fetchAll();
        }
        return array('producto'=>$producto,'ubicaciones'=>$ubicaciones,'ubicacion_venta_id'=>$turno['ubicacion_id'],'ubicacion_venta_nombre'=>$turno['ubicacion_nombre'],'consultado_unix'=>time());
    }
    public function buscar(array $filtros,array $turno)
    {
        $buscar=Producto::texto($filtros,'buscar',150,false);$codigo=Producto::texto($filtros,'codigo',150,false);$modo=isset($filtros['modo'])?$filtros['modo']:'busqueda';
        if(!is_string($modo)||!in_array($modo,array('busqueda','mosaico'),true)){throw new HttpError('Vista de catálogo no válida.');}
        $despues=isset($filtros['despues_de'])&&$filtros['despues_de']!==''?Producto::identificador($filtros['despues_de']):null;
        $ids=array();if(isset($filtros['ids'])){if(!is_string($filtros['ids']))throw new HttpError('Revisa los productos.');$ids=explode(',',$filtros['ids']);if(count($ids)>100)throw new HttpError('Consulta hasta 100 variantes.');$ids=array_map(array('Producto','identificador'),$ids);}
        if(!$ids&&$buscar===''&&$codigo===''&&$modo==='busqueda')return array('productos'=>array(),'siguiente'=>null);
        $where='';$args=array($turno['ubicacion_id']);$cantidad='1.0000';$limit=$ids?100:24;
        if($ids){$where.=' AND v.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';$args=array_merge($args,$ids);}
        elseif($codigo!==''){
            $q=$this->db->prepare('SELECT variante_id,cantidad FROM codigos_barras WHERE codigo=? LIMIT 1');$q->execute(array($codigo));$code=$q->fetch();
            if($code){$where.=' AND v.id=?';$args[]=$code['variante_id'];$cantidad=$code['cantidad'];}else{$where.=' AND v.sku=?';$args[]=$codigo;}
        }elseif($buscar!==''){
            $patron='%'.str_replace(array('!','%','_'),array('!!','!%','!_'),$buscar).'%';$where.=" AND (p.nombre LIKE ? ESCAPE '!' OR v.nombre LIKE ? ESCAPE '!' OR v.sku LIKE ? ESCAPE '!')";array_push($args,$patron,$patron,$patron);
        }
        if($despues&&!$ids&&$codigo===''){$where.=' AND v.id>?';$args[]=$despues;}
        $q=$this->db->prepare('SELECT v.id,v.sku,v.nombre AS variante_nombre,v.precio,p.nombre AS producto_nombre,
            p.precio_incluye_impuesto,p.controla_inventario,p.tipo_producto,p.impuesto_id,i.tasa AS tasa_impuesto,u.codigo AS unidad_codigo,u.permite_decimales,
            ip.id AS imagen_id,COALESCE(e.cantidad,0.0000) AS existencia FROM variantes_producto v JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id
            JOIN impuestos i ON i.id=p.impuesto_id LEFT JOIN imagenes_producto ip ON ip.producto_id=p.id AND ip.es_principal=1
            LEFT JOIN existencias e ON e.variante_id=v.id AND e.ubicacion_id=? WHERE v.activo=1 AND p.activo=1 AND i.activo=1'.$where.' ORDER BY v.id LIMIT '.($limit+1));$q->execute($args);$items=$q->fetchAll();$more=count($items)>$limit;if($more)array_pop($items);
        foreach($items as &$p){$p['imagen_url']=$p['imagen_id']?ProductoImagen::url($p['imagen_id']):null;$p['cantidad_agregar']=$cantidad;if($p['tipo_producto']==='servicio')$p['controla_inventario']=0;unset($p['imagen_id']);}unset($p);
        return array('productos'=>$items,'siguiente'=>$more&&!$ids&&$codigo===''?$items[count($items)-1]['id']:null);
    }
}
