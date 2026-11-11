<?php
require_once __DIR__.'/../Services/Auditoria.php';
require_once __DIR__.'/Inventario.php';
class AlertaInventario {
 private $db;
 public function __construct(PDO $db){$this->db=$db;}
 public function catalogos(){return $this->db->query('SELECT id,nombre FROM ubicaciones WHERE activo=1 AND disponible_venta=1 ORDER BY nombre,id')->fetchAll();}
 private function consulta(array $f){
  $mode=isset($f['modo'])?$f['modo']:'alertas';if(!in_array($mode,array('alertas','minimos'),true))throw new HttpError('Selecciona alertas o configuración de mínimos.');
  $state=isset($f['estado'])?$f['estado']:'';if(!in_array($state,array('','agotado','negativo','bajo','minimo'),true))throw new HttpError('Selecciona una situación válida.');
  $search=isset($f['q'])?$f['q']:'';if(!is_string($search)||strlen($search)>200)throw new HttpError('La búsqueda es demasiado larga. Usa menos palabras.');$search=trim($search);
  $from=' FROM variantes_producto v JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id CROSS JOIN ubicaciones l LEFT JOIN existencias e ON e.variante_id=v.id AND e.ubicacion_id=l.id LEFT JOIN minimos_inventario mi ON mi.variante_id=v.id AND mi.ubicacion_id=l.id';
  $quantity='COALESCE(e.cantidad,0.0000)';$minimum='COALESCE(mi.minimo,v.stock_minimo)';$where=" WHERE p.activo=1 AND v.activo=1 AND p.controla_inventario=1 AND p.tipo_producto<>'servicio' AND l.activo=1 AND l.disponible_venta=1";$values=array();
  if(isset($f['ubicacion_id'])&&$f['ubicacion_id']!==''){$where.=' AND l.id=?';$values[]=Producto::identificador($f['ubicacion_id']);}
  if($search!==''){$like='%'.strtr($search,array('!'=>'!!','%'=>'!%','_'=>'!_')).'%';$where.=" AND (p.nombre LIKE ? ESCAPE '!' OR v.nombre LIKE ? ESCAPE '!' OR v.sku LIKE ? ESCAPE '!')";array_push($values,$like,$like,$like);}
  if($mode==='alertas')$where.=' AND '.$minimum.'>0 AND '.$quantity.'<='.$minimum;
  $conditions=array('agotado'=>$quantity.'=0','negativo'=>$quantity.'<0','bajo'=>$quantity.'>0 AND '.$quantity.'<'.$minimum,'minimo'=>$quantity.'>0 AND '.$quantity.'='.$minimum);
  if($state!=='')$where.=' AND '.$minimum.'>0 AND ('.$conditions[$state].')';
  $fields='l.id AS ubicacion_id,l.nombre AS ubicacion_nombre,v.id AS variante_id,p.id AS producto_id,p.nombre AS producto_nombre,v.nombre AS variante_nombre,v.sku,u.codigo AS unidad_codigo,u.permite_decimales,'.$quantity.' AS cantidad,'.$minimum.' AS minimo,v.stock_minimo AS minimo_general,mi.minimo AS minimo_local,COALESCE(mi.version,0) AS version,mi.actualizado_en,GREATEST('.$minimum.'-'.$quantity.',0) AS faltante,CASE WHEN '.$minimum.'=0 THEN \'desactivado\' WHEN '.$quantity.'<0 THEN \'negativo\' WHEN '.$quantity.'=0 THEN \'agotado\' WHEN '.$quantity.'<'.$minimum.' THEN \'bajo\' WHEN '.$quantity.'='.$minimum.' THEN \'minimo\' ELSE \'suficiente\' END AS estado';
  $order=' ORDER BY CASE WHEN '.$quantity.'<0 THEN 0 WHEN '.$quantity.'=0 THEN 1 WHEN '.$quantity.'<'.$minimum.' THEN 2 ELSE 3 END,p.nombre,v.nombre,v.id,l.nombre,l.id';return array($fields,$from,$where,$values,$order);
 }
 public function listar(array $f,$export=false){
  list($fields,$from,$where,$values,$order)=$this->consulta($f);$q=$this->db->prepare('SELECT COUNT(*)'.$from.$where);$q->execute($values);$total=(int)$q->fetchColumn();
  $page=isset($f['pagina'])?$f['pagina']:'1';if(!is_scalar($page)||!preg_match('/^[1-9][0-9]{0,5}$/D',(string)$page))throw new HttpError('Página no válida.');$page=(int)$page;
  if($export&&$total>10000)throw new HttpError('La lista supera 10 000 filas. Filtra por ubicación o producto antes de descargar.',422);
  $limit=$export?10001:50;$offset=$export?0:($page-1)*50;$q=$this->db->prepare('SELECT '.$fields.$from.$where.$order.' LIMIT '.$limit.' OFFSET '.$offset);$q->execute($values);$rows=$q->fetchAll();if($export&&count($rows)>10000)throw new HttpError('La lista cambió y supera 10 000 filas. Ajusta los filtros.',409);
  return array('filas'=>$rows,'total'=>$total,'pagina'=>$page,'por_pagina'=>50,'actualizado_unix'=>time());
 }
 public function resumen(){list($fields,$from,$where,$values,$order)=$this->consulta(array('modo'=>'alertas'));$q=$this->db->prepare('SELECT COUNT(*) alertas,COUNT(DISTINCT v.id) variantes'.$from.$where);$q->execute($values);$r=$q->fetch();$q=$this->db->prepare('SELECT '.$fields.$from.$where.$order.' LIMIT 5');$q->execute($values);$r['filas']=$q->fetchAll();return $r;}
 public function guardar(array $d,$actorId){
  $loc=Producto::identificador(isset($d['ubicacion_id'])?$d['ubicacion_id']:null);$variant=Producto::identificador(isset($d['variante_id'])?$d['variante_id']:null);
  if(!isset($d['usar_general'])||!is_bool($d['usar_general']))throw new HttpError('Indica si deseas usar el mínimo general.');
  $min=$d['usar_general']?null:Inventario::unidades(isset($d['minimo'])?$d['minimo']:null);
  if(!isset($d['version'])||!is_scalar($d['version'])||!preg_match('/^(0|[1-9][0-9]{0,17})$/D',(string)$d['version']))throw new HttpError('Actualiza la lista antes de editar el mínimo.');
  $this->db->beginTransaction();try{
   $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();$q=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR SHARE');$q->execute(array($actorId));$q->fetchColumn();$actor=(new Usuario($this->db))->buscar($actorId);
   if(!$actor||!$actor['activo']||(empty($actor['permisos']['todo'])&&(empty($actor['permisos']['ver_inventario'])||empty($actor['permisos']['ver_alertas_inventario'])||empty($actor['permisos']['configurar_minimos']))))throw new HttpError('No tienes permiso para configurar mínimos de inventario.',403);
   $q=$this->db->prepare('SELECT nombre,activo,disponible_venta FROM ubicaciones WHERE id=? FOR SHARE');$q->execute(array($loc));$place=$q->fetch();if(!$place||!$place['activo']||!$place['disponible_venta'])throw new HttpError('Selecciona una ubicación activa disponible para venta.');
   $q=$this->db->prepare('SELECT producto_id FROM variantes_producto WHERE id=?');$q->execute(array($variant));$pid=$q->fetchColumn();if(!$pid)throw new HttpError('Variante no encontrada.',404);
   $q=$this->db->prepare('SELECT p.nombre AS producto_nombre,p.activo,p.controla_inventario,p.tipo_producto,u.permite_decimales FROM productos p JOIN unidades u ON u.id=p.unidad_base_id WHERE p.id=? FOR SHARE');$q->execute(array($pid));$p=$q->fetch();$q=$this->db->prepare('SELECT sku,activo,stock_minimo FROM variantes_producto WHERE id=? FOR UPDATE');$q->execute(array($variant));$v=$q->fetch();
   if(!$p||!$p['activo']||!$v['activo']||!$p['controla_inventario']||$p['tipo_producto']==='servicio')throw new HttpError('Solo se configuran variantes activas que controlan inventario.');
   if($min!==null&&!$p['permite_decimales']&&$min%10000!==0)throw new HttpError('Esta unidad solo admite mínimos enteros.');
   $q=$this->db->prepare('SELECT version,minimo FROM minimos_inventario WHERE ubicacion_id=? AND variante_id=? FOR UPDATE');$q->execute(array($loc,$variant));$previous=$q->fetch();$version=$previous?(string)$previous['version']:'0';if((string)$d['version']!==$version)throw new HttpError('Otro usuario cambió este mínimo. Actualiza la lista y vuelve a editar.',409);
   $this->db->prepare('INSERT INTO minimos_inventario(ubicacion_id,variante_id,minimo,version,usuario_id) VALUES(?,?,?,1,?) ON DUPLICATE KEY UPDATE minimo=VALUES(minimo),version=version+1,usuario_id=VALUES(usuario_id),actualizado_en=CURRENT_TIMESTAMP')->execute(array($loc,$variant,$min===null?null:Inventario::decimal($min),$actorId));
   $before=array('minimo_local'=>$previous?$previous['minimo']:null);$after=array('minimo_local'=>$min===null?null:Inventario::decimal($min));if($before!==$after)Auditoria::registrar($this->db,$actorId,'minimo_configurado','Variante '.$variant.' / Ubicación '.$loc,$p['producto_nombre'].' · '.$v['sku'],$before,$after,array('producto'=>$p['producto_nombre'],'sku'=>$v['sku'],'ubicacion'=>$place['nombre'],'variante_id'=>$variant,'ubicacion_id'=>$loc,'minimo_general'=>$v['stock_minimo']));
   $this->db->commit();return array('version'=>(int)$version+1,'minimo'=>$min===null?$v['stock_minimo']:Inventario::decimal($min),'usar_general'=>$min===null);
  }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
 }
}
