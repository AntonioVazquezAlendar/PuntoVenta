<?php
require_once __DIR__.'/../Models/AlertaInventario.php';
class AlertaInventarioController {
 private $auth;private $model;
 public function __construct(PDO $db){$this->auth=new Auth($db);$this->model=new AlertaInventario($db);}
 public function ejecutar($action,array $d=array()){
  $this->auth->permiso('ver_inventario');$actor=$this->auth->permiso('ver_alertas_inventario');
  if($action==='guardar'){$this->auth->permiso('configurar_minimos');Response::json(true,'Mínimo guardado.',$this->model->guardar($d,$actor['id']));}
  if($action==='catalogos')Response::json(true,'Ubicaciones disponibles.',array('ubicaciones'=>$this->model->catalogos(),'puede_configurar'=>!empty($actor['permisos']['todo'])||!empty($actor['permisos']['configurar_minimos'])));
  $filters=$_GET;if(isset($filters['modo'])&&$filters['modo']==='minimos')$this->auth->permiso('configurar_minimos');
  if($action==='csv'){$filters['modo']='alertas';$result=$this->model->listar($filters,true);header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="Reposicion-'.date('Ymd-His').'.csv"');$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,array('Ubicación','Producto','Variante','SKU','Unidad','Existencia','Mínimo','Faltante para mínimo','Situación'));$labels=array('agotado'=>'Agotado','negativo'=>'Negativo','bajo'=>'Bajo mínimo','minimo'=>'En mínimo');foreach($result['filas'] as $r){$cells=array();foreach(array('ubicacion_nombre','producto_nombre','variante_nombre','sku','unidad_codigo') as $key){$text=(string)$r[$key];if(preg_match('/^[\x00-\x20]*[=+@-]/u',$text))$text="'".$text;$cells[]=$text;}array_push($cells,$r['cantidad'],$r['minimo'],$r['faltante'],$labels[$r['estado']]);fputcsv($out,$cells);}fclose($out);exit;}
  Response::json(true,'Inventario consultado.',$this->model->listar($filters));
 }
}
