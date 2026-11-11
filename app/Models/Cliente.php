<?php
require_once __DIR__.'/Producto.php';

class Cliente
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    private function salida(array $r){$r['version']=hash('sha256',json_encode($r));return $r;}
    private function campos(){return 'id,nombre,telefono,correo,direccion,notas,activo,UNIX_TIMESTAMP(creado_en) AS creado_unix,actualizado_en,EXISTS(SELECT 1 FROM configuracion_clientes cfg WHERE cfg.id=1 AND cfg.cliente_predeterminado_id=clientes.id) AS predeterminado';}
    public static function predeterminado(PDO $db){$q=$db->query('SELECT c.id,c.nombre,c.telefono FROM configuracion_clientes cfg JOIN clientes c ON c.id=cfg.cliente_predeterminado_id WHERE cfg.id=1 AND c.activo=1');return $q->fetch()?:null;}
    private function texto(array $d,$campo,$max,$obligatorio=false){
        if(isset($d[$campo])&&!is_string($d[$campo]))throw new HttpError('Revisa el campo '.$campo.'.');
        $v=Producto::texto($d,$campo,$max,$obligatorio);if(in_array($campo,array('nombre','telefono','correo'),true)&&preg_match('/[\x00-\x1f\x7f]/u',$v))throw new HttpError('Revisa el campo '.$campo.'.');return $v;
    }
    public function listar(array $filtros,$buscarVenta=false)
    {
        $buscar=$this->texto($filtros,'buscar',150);$where='1=1';$args=array();
        if($buscarVenta&&$buscar==='')return array('clientes'=>array(),'siguiente'=>null);
        $estado=$buscarVenta?'1':(isset($filtros['estado'])?$filtros['estado']:'1');
        if(!in_array($estado,array('','0','1'),true))throw new HttpError('Estado de cliente no válido.');
        if($estado!==''){$where.=' AND activo=?';$args[]=$estado;}
        if($buscar!==''){$p='%'.str_replace(array('!','%','_'),array('!!','!%','!_'),$buscar).'%';$where.=" AND (nombre LIKE ? ESCAPE '!' OR telefono LIKE ? ESCAPE '!'".($buscarVenta?'':" OR correo LIKE ? ESCAPE '!'").')';array_push($args,$p,$p);if(!$buscarVenta)$args[]=$p;}
        if(isset($filtros['antes_de'])&&$filtros['antes_de']!==''){$where.=' AND id<?';$args[]=Producto::identificador($filtros['antes_de']);}
        $limite=$buscarVenta?10:30;$campos=$buscarVenta?'id,nombre,telefono':$this->campos();$q=$this->db->prepare('SELECT '.$campos.' FROM clientes WHERE '.$where.' ORDER BY id DESC LIMIT '.($limite+1));$q->execute($args);$rows=$q->fetchAll();$more=count($rows)>$limite;if($more)array_pop($rows);
        if(!$buscarVenta)$rows=array_map(array($this,'salida'),$rows);
        return array('clientes'=>$rows,'siguiente'=>$more?$rows[count($rows)-1]['id']:null);
    }
    public function obtener($id){$q=$this->db->prepare('SELECT '.$this->campos().' FROM clientes WHERE id=?');$q->execute(array($id));$r=$q->fetch();if(!$r)throw new HttpError('Cliente no encontrado.',404);return $this->salida($r);}
    public function guardar(array $d,$actorId,$id=null)
    {
        $nombre=$this->texto($d,'nombre',200,true);$tel=$this->texto($d,'telefono',30);$correo=$this->texto($d,'correo',150);$direccion=$this->texto($d,'direccion',300);$notas=$this->texto($d,'notas',500);$activo=Producto::bandera($d,'activo');
        $predeterminado=array_key_exists('predeterminado',$d)?Producto::bandera($d,'predeterminado'):null;
        if($predeterminado===1&&!$activo)throw new HttpError('El cliente predeterminado debe estar activo.');
        if($correo!==''&&!filter_var($correo,FILTER_VALIDATE_EMAIL))throw new HttpError('Escribe un correo electrónico válido.');
        $this->db->beginTransaction();
        try{
            $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();$q=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');$q->execute(array($actorId));$q->fetchColumn();$actor=(new Usuario($this->db))->buscar($actorId);
            if(!$actor||!$actor['activo']||(empty($actor['permisos']['todo'])&&(empty($actor['permisos']['ver_clientes'])||empty($actor['permisos']['editar_clientes']))))throw new HttpError('No tienes permiso para editar clientes.',403);
            $config=$this->db->query('SELECT cliente_predeterminado_id FROM configuracion_clientes WHERE id=1 FOR UPDATE')->fetch();
            if($id!==null){$q=$this->db->prepare('SELECT '.$this->campos().' FROM clientes WHERE id=? FOR UPDATE');$q->execute(array($id));$actual=$q->fetch();if(!$actual)throw new HttpError('Cliente no encontrado.',404);$actual=$this->salida($actual);if(!isset($d['version'])||!is_string($d['version'])||!hash_equals($actual['version'],$d['version']))throw new HttpError('El cliente cambió. Vuelve a abrir su ficha antes de guardar.',409);}
            $v=array($nombre,$tel?:null,$correo?:null,$direccion?:null,$notas?:null,$activo);
            if($id===null){$this->db->prepare('INSERT INTO clientes (nombre,telefono,correo,direccion,notas,activo) VALUES (?,?,?,?,?,?)')->execute($v);$id=$this->db->lastInsertId();}
            else{$v[]=$id;$this->db->prepare('UPDATE clientes SET nombre=?,telefono=?,correo=?,direccion=?,notas=?,activo=? WHERE id=?')->execute($v);}
            if($predeterminado===1)$this->db->prepare('UPDATE configuracion_clientes SET cliente_predeterminado_id=? WHERE id=1')->execute(array($id));
            elseif((string)$config['cliente_predeterminado_id']===(string)$id&&($predeterminado===0||!$activo))$this->db->exec('UPDATE configuracion_clientes SET cliente_predeterminado_id=NULL WHERE id=1');
            $this->db->commit();return array('id'=>$id);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function historial($id,array $actor,array $filtros)
    {
        $cliente=$this->obtener($id);$todos=!empty($actor['permisos']['todo']);$where='v.cliente_id=?';$args=array($id);
        if(!$todos){$where.=' AND v.usuario_id=?';$args[]=$actor['id'];}
        $q=$this->db->prepare("SELECT COUNT(*) AS compras,COALESCE(SUM(v.total-COALESCE((SELECT SUM(r.total) FROM devoluciones_venta r WHERE r.venta_id=v.id),0)),0.00) AS total FROM ventas v WHERE ".$where." AND v.estatus='completada'");$q->execute($args);$resumen=$q->fetch();
        if(isset($filtros['antes_de'])&&$filtros['antes_de']!==''){$where.=' AND v.id<?';$args[]=Producto::identificador($filtros['antes_de']);}
        $q=$this->db->prepare("SELECT v.id,v.folio,v.estatus,v.total,COALESCE((SELECT SUM(r.total) FROM devoluciones_venta r WHERE r.venta_id=v.id),0.00) AS reembolsado,u.nombre AS cajero,COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v.ticket_datos,'$.cliente_nombre')),'Cliente') AS cliente_nombre,UNIX_TIMESTAMP(v.completado_en) AS completado_unix FROM ventas v JOIN usuarios u ON u.id=v.usuario_id WHERE ".$where.' ORDER BY v.id DESC LIMIT 31');$q->execute($args);$rows=$q->fetchAll();$more=count($rows)>30;if($more)array_pop($rows);
        return array('cliente'=>$cliente,'todas_las_ventas'=>$todos,'resumen'=>$resumen,'ventas'=>$rows,'siguiente'=>$more?$rows[count($rows)-1]['id']:null);
    }
}
