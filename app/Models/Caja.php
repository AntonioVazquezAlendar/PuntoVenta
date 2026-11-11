<?php

require_once __DIR__.'/Producto.php';

class Caja
{
    private $db;
    const MAXIMO=99999999999999;
    public function __construct(PDO $db){$this->db=$db;}
    public static function centavos($valor,$firmado=false)
    {
        $patron=$firmado?'/^-?(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,2})?$/D':'/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D';
        if(!is_scalar($valor)||!preg_match($patron,(string)$valor)){throw new HttpError('Escribe un importe válido con un máximo de 2 decimales.');}
        $texto=(string)$valor;$partes=explode('.',ltrim($texto,'-'));$numero=(int)$partes[0]*100+(int)str_pad(isset($partes[1])?$partes[1]:'',2,'0');
        return substr($texto,0,1)==='-'?-$numero:$numero;
    }
    public static function dinero($n){return ($n<0?'-':'').intdiv(abs($n),100).'.'.str_pad((string)(abs($n)%100),2,'0',STR_PAD_LEFT);}
    private function actor($id,$permiso='operar_caja')
    {
        $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();
        $q=$this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR UPDATE');$q->execute(array($id));$q->fetchColumn();
        $actor=(new Usuario($this->db))->buscar($id);
        if(!$actor||!$actor['activo']||(empty($actor['permisos']['todo'])&&(empty($actor['permisos']['operar_caja'])||empty($actor['permisos'][$permiso])))){throw new HttpError('No tienes permiso para realizar esta operación de caja.',403);}
        return $actor;
    }
    private function supervisor(array $actor){return !empty($actor['permisos']['todo'])||!empty($actor['permisos']['supervisar_caja']);}
    private function acceso(array $actor,array $turno){if((string)$actor['id']!==(string)$turno['abierto_por']&&!$this->supervisor($actor)){throw new HttpError('Solo puedes consultar y operar los turnos que abriste.',403);}}

    public function catalogos()
    {
        return array('cajas'=>$this->db->query("SELECT c.id,c.nombre,c.ubicacion_id,c.activo,l.nombre AS ubicacion_nombre,l.activo AS ubicacion_activa,l.disponible_venta,
            t.id AS turno_abierto_id,t.abierto_por,u.nombre AS responsable FROM cajas c JOIN ubicaciones l ON l.id=c.ubicacion_id
            LEFT JOIN turnos_caja t ON t.caja_id=c.id AND t.estatus='abierto' LEFT JOIN usuarios u ON u.id=t.abierto_por ORDER BY c.activo DESC,c.nombre,c.id")->fetchAll(),
            'ubicaciones'=>$this->db->query('SELECT id,nombre,activo,disponible_venta FROM ubicaciones ORDER BY activo DESC,nombre,id')->fetchAll());
    }
    public function guardar(array $datos,$actorId,$id=null)
    {
        $nombre=Producto::texto($datos,'nombre',100);$ubicacion=Producto::identificador(isset($datos['ubicacion_id'])?$datos['ubicacion_id']:null);$activo=Producto::bandera($datos,'activo');
        $this->db->beginTransaction();
        try{
            $this->actor($actorId,'administrar_cajas');$actual=null;
            if($id!==null){$q=$this->db->prepare('SELECT * FROM cajas WHERE id=? FOR UPDATE');$q->execute(array($id));$actual=$q->fetch();if(!$actual){throw new HttpError('Caja no encontrada.',404);}}
            $q=$this->db->prepare('SELECT id,activo,disponible_venta FROM ubicaciones WHERE id=? FOR SHARE');$q->execute(array($ubicacion));$l=$q->fetch();
            if(!$l||($activo&&(!$l['activo']||!$l['disponible_venta']))){throw new HttpError('Una caja activa necesita una ubicación activa y disponible para venta.');}
            if($actual&&(!$activo||(string)$actual['ubicacion_id']!==$ubicacion)){
                $q=$this->db->prepare("SELECT id FROM turnos_caja WHERE caja_id=? AND estatus='abierto' FOR UPDATE");$q->execute(array($id));
                if($q->fetchColumn()){throw new HttpError('Cierra el turno antes de desactivar la caja o cambiar su ubicación.',409);}
            }
            if($id===null){$this->db->prepare('INSERT INTO cajas (nombre,ubicacion_id,activo) VALUES (?,?,?)')->execute(array($nombre,$ubicacion,$activo));$id=$this->db->lastInsertId();}
            else{$this->db->prepare('UPDATE cajas SET nombre=?,ubicacion_id=?,activo=? WHERE id=?')->execute(array($nombre,$ubicacion,$activo,$id));}
            $this->db->commit();return array('id'=>$id);
        }catch(Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}if($e instanceof PDOException&&isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062){throw new HttpError('Ya existe una caja con ese nombre.',409);}throw $e;}
    }
    public function listar(array $filtros,array $actor)
    {
        $sql='SELECT t.id,t.caja_id,c.nombre AS caja_nombre,t.ubicacion_id,l.nombre AS ubicacion_nombre,t.abierto_por,u.nombre AS responsable,
            t.estatus,t.monto_apertura,t.monto_esperado,t.monto_cierre,t.diferencia,UNIX_TIMESTAMP(t.abierto_en) AS abierto_unix,UNIX_TIMESTAMP(t.cerrado_en) AS cerrado_unix
            FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id JOIN ubicaciones l ON l.id=COALESCE(t.ubicacion_id,c.ubicacion_id) JOIN usuarios u ON u.id=t.abierto_por WHERE 1=1';$values=array();
        if(!$this->supervisor($actor)){$sql.=' AND t.abierto_por=?';$values[]=$actor['id'];}
        foreach(array('caja_id','antes_de') as $key){if(isset($filtros[$key])&&$filtros[$key]!==''){$sql.=$key==='caja_id'?' AND t.caja_id=?':' AND t.id<?';$values[]=Producto::identificador($filtros[$key]);}}
        if(isset($filtros['estatus'])&&$filtros['estatus']!==''){if(!in_array($filtros['estatus'],array('abierto','cerrado'),true)){throw new HttpError('Estado de turno no válido.');}$sql.=' AND t.estatus=?';$values[]=$filtros['estatus'];}
        $q=$this->db->prepare($sql.' ORDER BY t.id DESC LIMIT 51');$q->execute($values);$rows=$q->fetchAll();$more=count($rows)>50;if($more){array_pop($rows);}
        return array('turnos'=>$rows,'siguiente'=>$more?$rows[count($rows)-1]['id']:null);
    }
    private function turno($id,$bloquear=false)
    {
        $q=$this->db->prepare('SELECT * FROM turnos_caja WHERE id=?'.($bloquear?' FOR UPDATE':''));$q->execute(array($id));$row=$q->fetch();if(!$row){throw new HttpError('Turno no encontrado.',404);}return $row;
    }
    private function resumen(array $turno,$bloquear=false)
    {
        if($turno['estatus']==='cerrado'&&$turno['resumen_cierre']!==null){return json_decode($turno['resumen_cierre'],true);}
        $q=$this->db->prepare('SELECT id,tipo,monto FROM movimientos_efectivo WHERE turno_id=? ORDER BY id'.($bloquear?' FOR UPDATE':''));$q->execute(array($turno['id']));$movimientos=$q->fetchAll();$entradas=0;$salidas=0;
        foreach($movimientos as $m){$n=self::centavos($m['monto'],true);if($m['tipo']==='entrada'){$entradas+=$n;}else{$salidas+=$n;}}
        $q=$this->db->prepare("SELECT p.id,p.monto FROM pagos_venta p JOIN ventas v ON v.id=p.venta_id JOIN metodos_pago m ON m.id=p.metodo_pago_id
            WHERE p.abono_id IS NULL AND v.turno_id=? AND (v.estatus='completada' OR (v.estatus='cancelada' AND EXISTS(SELECT 1 FROM devoluciones_venta r WHERE r.venta_id=v.id AND r.tipo='cancelacion'))) AND COALESCE(p.tipo_metodo,m.tipo)='efectivo' ORDER BY p.id".($bloquear?' FOR SHARE':''));$q->execute(array($turno['id']));$pagos=$q->fetchAll();$ventas=0;
        foreach($pagos as $p){$ventas+=self::centavos($p['monto'],true);}
        $q=$this->db->prepare("SELECT id FROM ventas WHERE turno_id=? AND estatus IN ('abierta','en_espera') ORDER BY id".($bloquear?' FOR SHARE':''));$q->execute(array($turno['id']));$pendientes=$q->fetchAll(PDO::FETCH_COLUMN);
        $q=$this->db->prepare("SELECT p.id,p.monto FROM pagos_devolucion p JOIN devoluciones_venta r ON r.id=p.devolucion_id WHERE r.turno_id=? AND p.tipo_metodo='efectivo' ORDER BY p.id".($bloquear?' FOR SHARE':''));$q->execute(array($turno['id']));$reembolsos=$q->fetchAll();$devuelto=0;foreach($reembolsos as $r)$devuelto+=self::centavos($r['monto'],true);
        $q=$this->db->prepare("SELECT a.id,a.monto FROM abonos_cliente a JOIN pagos_venta p ON p.abono_id=a.id WHERE a.turno_id=? AND p.tipo_metodo='efectivo' ORDER BY a.id".($bloquear?' FOR SHARE':''));$q->execute(array($turno['id']));$abonos=$q->fetchAll();$cobrado=0;foreach($abonos as $a)$cobrado+=self::centavos($a['monto']);
        $q=$this->db->prepare("SELECT id,monto FROM pagos_proveedor WHERE turno_id=? AND origen='caja' ORDER BY id".($bloquear?' FOR SHARE':''));$q->execute(array($turno['id']));$proveedores=$q->fetchAll();$pagado=0;foreach($proveedores as $p)$pagado+=self::centavos($p['monto']);
        $esperado=self::centavos($turno['monto_apertura'],true)+$ventas+$cobrado+$entradas-$salidas-$devuelto-$pagado;
        if(abs($esperado)>self::MAXIMO){throw new HttpError('El efectivo esperado supera el límite permitido.');}
        $q=$this->db->prepare("SELECT monto FROM gastos WHERE turno_id=? AND origen='caja'".($bloquear?' FOR SHARE':''));$q->execute(array($turno['id']));$gastoTotal=0;foreach($q->fetchAll(PDO::FETCH_COLUMN) as $g)$gastoTotal+=self::centavos($g);
        $data=array('gastos_incluidos_salidas'=>self::dinero($gastoTotal),'fondo_inicial'=>$turno['monto_apertura'],'pagos_proveedores'=>self::dinero($pagado),'abonos_efectivo'=>self::dinero($cobrado),'ventas_efectivo'=>self::dinero($ventas),'reembolsos_efectivo'=>self::dinero($devuelto),'entradas'=>self::dinero($entradas),'salidas'=>self::dinero($salidas),
            'esperado'=>$turno['estatus']==='cerrado'?$turno['monto_esperado']:self::dinero($esperado),'ventas_pendientes'=>count($pendientes));
        $data['version']=hash('sha256',json_encode(array($turno['id'],$turno['estatus'],$data,array_column($movimientos,'id'),array_column($pagos,'id'),array_column($reembolsos,'id'),array_column($abonos,'id'),array_column($proveedores,'id'),$pendientes)));
        return $data;
    }
    // Solo para operaciones que ya bloquearon caja y turno dentro de su transacción.
    public function resumenBloqueado(array $turno){return $this->resumen($turno,true);}
    public function obtener($id,array $actor)
    {
        $this->db->beginTransaction();
        try{
            $t=$this->turno($id);$this->acceso($actor,$t);$resumen=$this->resumen($t);
            $q=$this->db->prepare('SELECT c.nombre AS caja_nombre,l.nombre AS ubicacion_nombre,u.nombre AS responsable,r.nombre AS cerrado_por_nombre,
                UNIX_TIMESTAMP(t.abierto_en) AS abierto_unix,UNIX_TIMESTAMP(t.cerrado_en) AS cerrado_unix FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id
                JOIN ubicaciones l ON l.id=COALESCE(t.ubicacion_id,c.ubicacion_id) JOIN usuarios u ON u.id=t.abierto_por LEFT JOIN usuarios r ON r.id=t.cerrado_por WHERE t.id=?');$q->execute(array($id));$t=array_merge($t,$q->fetch());
            $q=$this->db->prepare('SELECT m.id,m.tipo,m.monto,m.motivo,m.usuario_id,u.nombre AS usuario_nombre,UNIX_TIMESTAMP(m.creado_en) AS creado_unix FROM movimientos_efectivo m JOIN usuarios u ON u.id=m.usuario_id WHERE m.turno_id=? ORDER BY m.id DESC');$q->execute(array($id));$t['movimientos']=$q->fetchAll();
            unset($t['clave_abierto'],$t['resumen_cierre']);$t['resumen']=$resumen;$this->db->commit();return $t;
        }catch(Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
    private function solicitud(array $datos,$actorId,$accion,$huella)
    {
        $clave=isset($datos['clave'])&&is_string($datos['clave'])?$datos['clave']:'';
        if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$clave)){throw new HttpError('La solicitud no es válida. Vuelve a abrir el formulario.');}
        $this->db->prepare('INSERT IGNORE INTO solicitudes_caja (usuario_id,clave,accion,huella) VALUES (?,?,?,?)')->execute(array($actorId,$clave,$accion,$huella));
        $q=$this->db->prepare('SELECT id,huella,resultado FROM solicitudes_caja WHERE usuario_id=? AND clave=? FOR UPDATE');$q->execute(array($actorId,$clave));$s=$q->fetch();
        if(!hash_equals($s['huella'],$huella)){throw new HttpError('Esa solicitud ya se utilizó con otros datos. Vuelve a abrir el formulario.',409);}return $s;
    }
    private function resultado(array $s,$turnoId,array $resultado)
    {
        $this->db->prepare('UPDATE solicitudes_caja SET turno_id=?,resultado=? WHERE id=?')->execute(array($turnoId,json_encode($resultado),$s['id']));$this->db->commit();return $resultado;
    }
    public function abrir(array $datos,$actorId)
    {
        $cajaId=Producto::identificador(isset($datos['caja_id'])?$datos['caja_id']:null);$fondo=self::centavos(isset($datos['monto_apertura'])?$datos['monto_apertura']:null);$notas=Producto::texto($datos,'notas',300,false);
        $this->db->beginTransaction();
        try{
            $this->actor($actorId);$s=$this->solicitud($datos,$actorId,'abrir',hash('sha256',json_encode(array('abrir',$cajaId,$fondo,$notas))));
            if($s['resultado']!==null){$this->db->commit();return json_decode($s['resultado'],true);}
            $q=$this->db->prepare('SELECT * FROM cajas WHERE id=? FOR UPDATE');$q->execute(array($cajaId));$caja=$q->fetch();if(!$caja||!$caja['activo']){throw new HttpError('Selecciona una caja activa.');}
            $q=$this->db->prepare('SELECT id,activo,disponible_venta FROM ubicaciones WHERE id=? FOR SHARE');$q->execute(array($caja['ubicacion_id']));$l=$q->fetch();
            if(!$l||!$l['activo']||!$l['disponible_venta']){throw new HttpError('La caja debe estar en una ubicación activa y habilitada para venta.');}
            $q=$this->db->prepare("SELECT id FROM turnos_caja WHERE caja_id=? AND estatus='abierto' FOR UPDATE");$q->execute(array($cajaId));if($q->fetchColumn()){throw new HttpError('Esta caja ya tiene un turno abierto.',409);}
            $q=$this->db->prepare("SELECT id FROM turnos_caja WHERE abierto_por=? AND estatus='abierto' FOR UPDATE");$q->execute(array($actorId));if($q->fetchColumn()){throw new HttpError('Ya tienes un turno abierto. Ciérralo antes de abrir otro.',409);}
            $this->db->prepare('INSERT INTO turnos_caja (caja_id,ubicacion_id,abierto_por,monto_apertura,notas) VALUES (?,?,?,?,?)')->execute(array($cajaId,$caja['ubicacion_id'],$actorId,self::dinero($fondo),$notas));$id=$this->db->lastInsertId();
            return $this->resultado($s,$id,array('turno_id'=>$id,'mensaje'=>'Turno abierto'));
        }catch(Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
    public function operar($id,array $datos,$actorId,$accion)
    {
        $tipo=null;$monto=null;$motivo=null;$contado=null;$notas=null;
        if($accion==='movimiento'){
            $tipo=isset($datos['tipo'])&&is_string($datos['tipo'])?$datos['tipo']:'';if(!in_array($tipo,array('entrada','salida'),true)){throw new HttpError('Selecciona entrada o salida.');}
            $monto=self::centavos(isset($datos['monto'])?$datos['monto']:null);if($monto<=0){throw new HttpError('El monto debe ser mayor que cero.');}$motivo=Producto::texto($datos,'motivo',200);
        }else{
            $contado=self::centavos(isset($datos['monto_cierre'])?$datos['monto_cierre']:null);$notas=Producto::texto($datos,'notas_cierre',300,false);
        }
        $huella=hash('sha256',json_encode(array($accion,(string)$id,$tipo,$monto,$motivo,$contado,$notas,isset($datos['version'])?$datos['version']:null)));
        $this->db->beginTransaction();
        try{
            $actor=$this->actor($actorId);$s=$this->solicitud($datos,$actorId,$accion,$huella);if($s['resultado']!==null){$this->db->commit();return json_decode($s['resultado'],true);}
            $q=$this->db->prepare('SELECT caja_id FROM turnos_caja WHERE id=?');$q->execute(array($id));$cajaId=$q->fetchColumn();if(!$cajaId){throw new HttpError('Turno no encontrado.',404);}
            $q=$this->db->prepare('SELECT id FROM cajas WHERE id=? FOR UPDATE');$q->execute(array($cajaId));$q->fetchColumn();
            $turno=$this->turno($id,true);$this->acceso($actor,$turno);if($turno['estatus']!=='abierto'){throw new HttpError('El turno ya está cerrado. Su historial no se puede modificar.',409);}
            $resumen=$this->resumen($turno,true);$esperado=self::centavos($resumen['esperado'],true);
            if($accion==='movimiento'){
                if($tipo==='salida'&&$monto>$esperado){throw new HttpError('La salida supera el efectivo esperado en el cajón.',409);}
                $nuevo=$esperado+($tipo==='entrada'?$monto:-$monto);if(abs($nuevo)>self::MAXIMO){throw new HttpError('El efectivo resultante supera el límite permitido.');}
                $this->db->prepare('INSERT INTO movimientos_efectivo (turno_id,tipo,monto,motivo,usuario_id) VALUES (?,?,?,?,?)')->execute(array($id,$tipo,self::dinero($monto),$motivo,$actorId));
                return $this->resultado($s,$id,array('turno_id'=>(string)$id,'movimiento_id'=>$this->db->lastInsertId(),'esperado'=>self::dinero($nuevo)));
            }
            if(!isset($datos['version'])||!is_string($datos['version'])||!hash_equals($resumen['version'],$datos['version'])){throw new HttpError('El efectivo o las ventas del turno cambiaron. Actualiza el turno y revisa el corte antes de cerrar.',409);}
            if($resumen['ventas_pendientes']>0){throw new HttpError('Hay ventas abiertas o en espera en este turno. Complétalas o cancélalas antes de cerrar.',409);}
            $diferencia=$contado-$esperado;if(abs($diferencia)>self::MAXIMO){throw new HttpError('La diferencia del corte supera el límite permitido.');}
            if($diferencia!==0&&$notas===''){throw new HttpError('Escribe una nota para explicar el faltante o sobrante del corte.');}
            $this->db->prepare("UPDATE turnos_caja SET estatus='cerrado',cerrado_por=?,monto_esperado=?,monto_cierre=?,notas_cierre=?,resumen_cierre=?,cerrado_en=CURRENT_TIMESTAMP WHERE id=?")
                ->execute(array($actorId,self::dinero($esperado),self::dinero($contado),$notas,json_encode($resumen),$id));
            return $this->resultado($s,$id,array('turno_id'=>(string)$id,'esperado'=>self::dinero($esperado),'contado'=>self::dinero($contado),'diferencia'=>self::dinero($diferencia)));
        }catch(Throwable $e){if($this->db->inTransaction()){$this->db->rollBack();}throw $e;}
    }
}
