<?php
require_once __DIR__.'/../Services/Respaldo.php';
class RespaldoController {
    private $db;
    public function __construct(PDO $db){$this->db=$db;}
    public function ejecutar($action,$id=null,array $d=array()){
        $auth=new Auth($this->db);$actor=$auth->administrador();$s=new Respaldo($this->db);set_time_limit(300);
        switch($action){
        case 'listar':$r=array('respaldos'=>$s->historial(),'turnos_abiertos'=>(int)$this->db->query("SELECT COUNT(*) FROM turnos_caja WHERE estatus='abierto'")->fetchColumn());break;
        case 'crear':if(!isset($d['password'],$d['confirm_password'])||!is_string($d['password'])||$d['password']!==$d['confirm_password'])throw new HttpError('Las contraseñas del respaldo deben coincidir.');$r=$s->crear($d['password'],$actor);break;
        case 'descargar':$f=$s->archivo($id);header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="'.$f['meta']['nombre'].'"');header('Content-Length: '.filesize($f['path']));readfile($f['path']);exit;
        case 'importar':$r=$s->iniciarCarga(isset($d['nombre'])?$d['nombre']:null,isset($d['bytes'])?$d['bytes']:null,$actor['id']);$_SESSION['backup_uploads'][$r['id']]=time();break;
        case 'parte':if(empty($_SESSION['backup_uploads'][$id]))throw new HttpError('Inicia nuevamente la carga.',403);$offset=isset($_SERVER['HTTP_X_BACKUP_OFFSET'])?$_SERVER['HTTP_X_BACKUP_OFFSET']:'';if(!preg_match('/^(0|[1-9][0-9]{0,9})$/D',$offset))throw new HttpError('Posición de carga no válida.');$data=file_get_contents('php://input',false,null,0,524289);$r=$s->parte($id,(int)$offset,$data,$actor['id']);break;
        case 'revisar':unset($_SESSION['backup_preview']);if(isset($d['import_id'])&&empty($_SESSION['backup_uploads'][$d['import_id']]))throw new HttpError('La carga no pertenece a esta sesión.',403);$path=$s->fuente($d,$actor['id']);$r=$s->revisar($path,isset($d['password'])?$d['password']:null);unset($r['work'],$r['manifest']);$r['token']=bin2hex(random_bytes(16));$_SESSION['backup_preview']=array('token'=>$r['token'],'path'=>$path,'sha256'=>$r['sha256'],'actor'=>$actor['id'],'time'=>time());break;
        case 'restaurar':$p=isset($_SESSION['backup_preview'])?$_SESSION['backup_preview']:null;if(!$p||!isset($d['token'])||!is_string($d['token'])||!hash_equals($p['token'],$d['token'])||time()-$p['time']>900||(string)$p['actor']!==(string)$actor['id'])throw new HttpError('La revisión expiró. Revisa el respaldo nuevamente.',409);if(empty($d['confirm'])||$d['confirm']!==true||!isset($d['phrase'])||$d['phrase']!=='RESTAURAR DATOS')throw new HttpError('Escribe RESTAURAR DATOS y confirma la sustitución.');$auth->confirmarRestauracion($actor,isset($d['admin_password'])?$d['admin_password']:'');unset($_SESSION['backup_preview']);$r=$s->restaurar($p['path'],isset($d['password'])?$d['password']:null,$p['sha256'],$actor);Auth::logout();break;
        default:throw new HttpError('Ruta no encontrada.',404);
        }
        Response::json(true,'Operación completada.',$r);
    }
}
