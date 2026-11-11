<?php
require_once __DIR__.'/Usuario.php';
require_once __DIR__.'/../Services/Auditoria.php';

class ConfiguracionAdmin {
    private $db;
    private static $campos=array('razon_social'=>200,'rfc'=>13,'telefono'=>30,'correo'=>150,'sitio_web'=>200,'direccion'=>300,'codigo_postal'=>5,'encabezado_ticket'=>300,'pie_ticket'=>300);
    public function __construct(PDO $db){$this->db=$db;}
    private function version(array $r){return hash('sha256',json_encode($r,JSON_UNESCAPED_UNICODE));}
    private function comprobarVersion($v,array $r){if(!is_string($v)||!hash_equals($this->version($r),$v))throw new HttpError('La configuración cambió en otra sesión. Recarga y revisa los cambios antes de guardar.',409);}
    private function administrador($id){
        $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();
        $q=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR SHARE');$q->execute(array($id));
        $u=(new Usuario($this->db))->buscar($id);if(!$u||!$u['activo']||empty($u['permisos']['todo']))throw new HttpError('Solo el administrador puede cambiar la configuración.',403);
    }
    private function datos($lock=false){return $this->db->query('SELECT '.implode(',',array_keys(self::$campos)).' FROM empresa WHERE id=1'.($lock?' FOR UPDATE':''))->fetch();}
    public function obtener(){
        $r=$this->datos();if(!$r)throw new HttpError('No se encontró la configuración del negocio.',503);
        $fixed=$this->db->query('SELECT moneda,zona_horaria FROM empresa WHERE id=1')->fetch();
        return array('negocio'=>$r,'version'=>$this->version($r),'sistema'=>$fixed,'metodos'=>$this->metodos());
    }
    private function texto($v,$max){if($v===null)return null;if(!is_string($v)||!preg_match('/^.{0,'.$max.'}$/us',$v))throw new HttpError('Revisa la longitud y el formato de los campos.');$v=trim($v);return $v===''?null:$v;}
    public function guardar(array $d,$actor){
        $out=array();foreach(self::$campos as $k=>$max){if(!array_key_exists($k,$d))throw new HttpError('Falta completar la configuración.');$out[$k]=$this->texto($d[$k],$max);}
        if($out['rfc']!==null){$out['rfc']=strtoupper($out['rfc']);if(!preg_match('/^[A-Z&Ñ]{3,4}[0-9]{6}[A-Z0-9]{3}$/uD',$out['rfc']))throw new HttpError('Revisa el RFC: debe tener 12 o 13 caracteres con su formato habitual.');}
        if($out['correo']!==null&&!filter_var($out['correo'],FILTER_VALIDATE_EMAIL))throw new HttpError('Escribe un correo válido.');
        if($out['sitio_web']!==null&&(!filter_var($out['sitio_web'],FILTER_VALIDATE_URL)||!preg_match('#^https?://#i',$out['sitio_web'])))throw new HttpError('El sitio web debe comenzar con https:// o http://.');
        if($out['codigo_postal']!==null&&!preg_match('/^[0-9]{5}$/D',$out['codigo_postal']))throw new HttpError('El código postal debe tener cinco dígitos.');
        $this->db->beginTransaction();try{
            $this->administrador($actor);$old=$this->datos(true);$this->comprobarVersion(isset($d['version'])?$d['version']:null,$old);
            $sets=array_map(function($k){return $k.'=?';},array_keys(self::$campos));$this->db->prepare('UPDATE empresa SET '.implode(',',$sets).' WHERE id=1')->execute(array_values($out));
            Auditoria::registrar($this->db,$actor,'configuracion_editada','Empresa','Datos del negocio y ticket',$old,$out);
            $this->db->commit();return $this->obtener();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function metodos(){return array_map(function($m){$m['version']=$this->version($m);return $m;},$this->db->query('SELECT id,nombre,tipo,activo FROM metodos_pago ORDER BY id')->fetchAll());}
    public function metodo($id,array $d,$actor){
        $name=$this->texto(isset($d['nombre'])?$d['nombre']:null,80);if($name===null)throw new HttpError('Escribe el nombre del método de pago.');
        if(!isset($d['activo'])||!in_array($d['activo'],array(0,1,'0','1'),true))throw new HttpError('Selecciona si el método está activo.');$active=(int)$d['activo'];
        $this->db->beginTransaction();try{
            $this->administrador($actor);$rows=$this->db->query('SELECT id,nombre,tipo,activo FROM metodos_pago ORDER BY id FOR UPDATE')->fetchAll();$old=null;
            if($id!==null){foreach($rows as $r)if((string)$r['id']===(string)$id)$old=$r;if(!$old)throw new HttpError('Método de pago no encontrado.',404);$this->comprobarVersion(isset($d['version'])?$d['version']:null,$old);$type=$old['tipo'];if(isset($d['tipo'])&&$d['tipo']!==$type)throw new HttpError('El tipo de un método existente no puede cambiar. Crea otro método.');}
            else{$type=isset($d['tipo'])?$d['tipo']:null;if(!in_array($type,array('efectivo','tarjeta','transferencia','credito','vales','otro'),true))throw new HttpError('Selecciona un tipo de pago válido.');}
            if($old&&$type==='efectivo'&&!$active){$cash=array_filter($rows,function($r)use($id){return (string)$r['id']!==(string)$id&&$r['tipo']==='efectivo'&&$r['activo'];});if(!$cash)throw new HttpError('Conserva al menos un método de efectivo activo para operar cajas y abonos.');}
            if($id===null){$this->db->prepare('INSERT INTO metodos_pago(nombre,tipo,activo) VALUES(?,?,?)')->execute(array($name,$type,$active));$id=$this->db->lastInsertId();}
            else $this->db->prepare('UPDATE metodos_pago SET nombre=?,activo=? WHERE id=?')->execute(array($name,$active,$id));
            $q=$this->db->prepare('SELECT id,nombre,tipo,activo FROM metodos_pago WHERE id=?');$q->execute(array($id));$new=$q->fetch();
            Auditoria::registrar($this->db,$actor,'metodo_pago_editado','Método '.$id,$name,$old,$new);$this->db->commit();return $this->metodos();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062)throw new HttpError('Ya existe un método con ese nombre.',409);throw $e;}
    }
}
