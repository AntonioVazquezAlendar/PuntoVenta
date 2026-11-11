<?php
require_once __DIR__.'/../Services/Auditoria.php';

require_once __DIR__ . '/Inventario.php';

class Almacen
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    private function autorizar($actorId, $permiso)
    {
        $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();
        $q = $this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR SHARE'); $q->execute(array($actorId)); $q->fetchColumn();
        $actor = (new Usuario($this->db))->buscar($actorId);
        if (!$actor || !$actor['activo'] || (empty($actor['permisos']['todo']) && (empty($actor['permisos'][$permiso]) || empty($actor['permisos']['ver_inventario'])))) {
            throw new HttpError('No tienes permiso para realizar esta operación de almacén.', 403);
        }
    }

    public function guardarUbicacion(array $datos, $actorId, $id = null)
    {
        $nombre = Producto::texto($datos, 'nombre', 100);
        $descripcion = Producto::texto($datos, 'descripcion', 300, false);
        $activo = Producto::bandera($datos, 'activo');
        $venta = Producto::bandera($datos, 'disponible_venta');
        $this->db->beginTransaction();
        try {
            $this->autorizar($actorId, 'administrar_ubicaciones');
            $rows = $this->db->query('SELECT id,nombre FROM ubicaciones ORDER BY id FOR UPDATE')->fetchAll();
            $map = array_column($rows, null, 'id');
            if ($id !== null && !isset($map[$id])) { throw new HttpError('Ubicación no encontrada.', 404); }
            $q = $this->db->prepare('SELECT id FROM ubicaciones WHERE nombre=? AND id<>?'); $q->execute(array($nombre, $id === null ? '0' : $id));
            if ($q->fetchColumn()) { throw new HttpError('Ya existe una ubicación con ese nombre.', 409); }
            if($id!==null&&(!$activo||!$venta)){
                $q=$this->db->prepare("SELECT id FROM turnos_caja WHERE ubicacion_id=? AND estatus='abierto' LIMIT 1 FOR SHARE");$q->execute(array($id));
                if($q->fetchColumn()){throw new HttpError('La ubicación tiene un turno de caja abierto. Ciérralo antes de desactivarla o pasarla a resguardo.',409);}
            }
            if ($id !== null && !$activo) {
                $q = $this->db->prepare('SELECT id FROM existencias WHERE ubicacion_id=? AND cantidad<>0 LIMIT 1 FOR SHARE'); $q->execute(array($id));
                if ($q->fetchColumn()) { throw new HttpError('La ubicación aún tiene existencias. Traspásalas o registra las bajas correspondientes antes de desactivarla.', 409); }
            }
            if ($id === null) {
                $this->db->prepare('INSERT INTO ubicaciones (nombre,descripcion,activo,disponible_venta) VALUES (?,?,?,?)')->execute(array($nombre,$descripcion,$activo,$venta));
                $id = $this->db->lastInsertId();
            } else {
                $this->db->prepare('UPDATE ubicaciones SET nombre=?,descripcion=?,activo=?,disponible_venta=? WHERE id=?')->execute(array($nombre,$descripcion,$activo,$venta,$id));
            }
            $this->db->commit(); return array('id'=>$id);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) { throw new HttpError('Ya existe una ubicación con ese nombre.', 409); }
            throw $e;
        }
    }

    public function registrar(array $datos, $actorId, $tipo)
    {
        $permiso = $tipo === 'traspaso' ? 'realizar_traspasos' : 'registrar_mermas';
        $origen = Producto::identificador(isset($datos['origen_id']) ? $datos['origen_id'] : null);
        $destino = $tipo === 'traspaso' ? Producto::identificador(isset($datos['destino_id']) ? $datos['destino_id'] : null) : null;
        if ($destino !== null && $origen === $destino) { throw new HttpError('El origen y el destino deben ser ubicaciones diferentes.'); }
        $variante = Producto::identificador(isset($datos['variante_id']) ? $datos['variante_id'] : null);
        $cantidad = Inventario::unidades(isset($datos['cantidad']) ? $datos['cantidad'] : null);
        if ($cantidad <= 0) { throw new HttpError('La cantidad debe ser mayor que cero.'); }
        $motivo = Producto::texto($datos, 'motivo', 300);
        $clave = isset($datos['clave']) && is_string($datos['clave']) ? $datos['clave'] : '';
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $clave)) { throw new HttpError('La solicitud no es válida. Vuelve a abrir el formulario.'); }
        $huella = hash('sha256', json_encode(array($tipo,$origen,$destino,$variante,$cantidad,$motivo)));
        $this->db->beginTransaction();
        try {
            $this->autorizar($actorId, $permiso);
            $this->db->prepare('INSERT IGNORE INTO operaciones_almacen (tipo,usuario_id,clave,huella,motivo) VALUES (?,?,?,?,?)')->execute(array($tipo,$actorId,$clave,$huella,$motivo));
            $q = $this->db->prepare('SELECT id,huella,resultado FROM operaciones_almacen WHERE usuario_id=? AND clave=? FOR UPDATE'); $q->execute(array($actorId,$clave)); $op = $q->fetch();
            if (!hash_equals($op['huella'],$huella)) { throw new HttpError('Esa solicitud ya se usó con otros datos. Vuelve a abrir el formulario.',409); }
            if ($op['resultado'] !== null) { $this->db->commit(); return json_decode($op['resultado'],true); }
            $lugarIds = $destino === null ? array($origen) : array($origen,$destino); sort($lugarIds,SORT_NUMERIC);
            $q = $this->db->prepare('SELECT id,nombre,activo,disponible_venta FROM ubicaciones WHERE id IN ('.implode(',',array_fill(0,count($lugarIds),'?')).') ORDER BY id FOR SHARE'); $q->execute($lugarIds); $lugares = array_column($q->fetchAll(),null,'id');
            foreach ($lugarIds as $lugarId) { if (!isset($lugares[$lugarId]) || !$lugares[$lugarId]['activo']) { throw new HttpError('Selecciona ubicaciones activas.'); } }
            $q = $this->db->prepare('SELECT producto_id FROM variantes_producto WHERE id=?'); $q->execute(array($variante)); $productoId = $q->fetchColumn();
            if (!$productoId) { throw new HttpError('Variante no encontrada.',404); }
            $q = $this->db->prepare('SELECT p.id,p.controla_inventario,u.permite_decimales FROM productos p JOIN unidades u ON u.id=p.unidad_base_id WHERE p.id=? FOR SHARE'); $q->execute(array($productoId)); $producto = $q->fetch();
            $q = $this->db->prepare('SELECT id,costo FROM variantes_producto WHERE id=? FOR UPDATE'); $q->execute(array($variante)); $sku = $q->fetch();
            if (!$producto['controla_inventario']) { throw new HttpError('Esta variante no controla inventario.'); }
            if (!$producto['permite_decimales'] && $cantidad % 10000 !== 0) { throw new HttpError('La unidad de este producto solo permite cantidades enteras.'); }
            foreach ($lugarIds as $lugarId) {
                $this->db->prepare('INSERT INTO existencias (ubicacion_id,variante_id,cantidad) VALUES (?,?,0) ON DUPLICATE KEY UPDATE cantidad=cantidad')->execute(array($lugarId,$variante));
            }
            $q = $this->db->prepare('SELECT ubicacion_id,cantidad FROM existencias WHERE variante_id=? AND ubicacion_id IN ('.implode(',',array_fill(0,count($lugarIds),'?')).') ORDER BY ubicacion_id FOR UPDATE'); $q->execute(array_merge(array($variante),$lugarIds)); $saldos = array_column($q->fetchAll(),'cantidad','ubicacion_id');
            $saldoOrigen = Inventario::unidades($saldos[$origen],true);
            if ($saldoOrigen < $cantidad) { throw new HttpError('No hay suficiente existencia en el origen. Actualiza el inventario y revisa la cantidad.',409); }
            $saldoDestino = $destino === null ? null : Inventario::unidades($saldos[$destino],true);
            if ($destino !== null && abs($saldoDestino+$cantidad) > Inventario::MAXIMO) { throw new HttpError('El destino superaría el límite de existencias.'); }
            $folio = ($tipo === 'traspaso' ? 'TR-' : 'ME-').str_pad($op['id'],8,'0',STR_PAD_LEFT);
            $update = $this->db->prepare('UPDATE existencias SET cantidad=cantidad+? WHERE ubicacion_id=? AND variante_id=?');
            $update->execute(array(Inventario::decimal(-$cantidad),$origen,$variante));
            $insert = $this->db->prepare('INSERT INTO movimientos_inventario (ubicacion_id,variante_id,tipo_movimiento,cantidad,costo_unitario,tipo_referencia,referencia_id,usuario_id,notas) VALUES (?,?,?,?,?,?,?,?,?)');
            $insert->execute(array($origen,$variante,$tipo === 'traspaso' ? 'traspaso_salida' : 'merma',Inventario::decimal(-$cantidad),$sku['costo'],$tipo,$op['id'],$actorId,$motivo));
            $movimientos = array($this->db->lastInsertId());
            if ($destino !== null) {
                $update->execute(array(Inventario::decimal($cantidad),$destino,$variante));
                $insert->execute(array($destino,$variante,'traspaso_entrada',Inventario::decimal($cantidad),$sku['costo'],$tipo,$op['id'],$actorId,$motivo)); $movimientos[] = $this->db->lastInsertId();
            }
            $resultado = array('id'=>$op['id'],'folio'=>$folio,'tipo'=>$tipo,'cantidad'=>Inventario::decimal($cantidad),'origen_id'=>$origen,'destino_id'=>$destino,
                'origen_anterior'=>Inventario::decimal($saldoOrigen),'origen_nuevo'=>Inventario::decimal($saldoOrigen-$cantidad),
                'destino_anterior'=>$destino === null ? null : Inventario::decimal($saldoDestino),'destino_nuevo'=>$destino === null ? null : Inventario::decimal($saldoDestino+$cantidad),'movimientos'=>$movimientos);
            $this->db->prepare('UPDATE operaciones_almacen SET folio=?,origen_id=?,destino_id=?,variante_id=?,cantidad=?,costo_unitario=?,resultado=? WHERE id=?')
                ->execute(array($folio,$origen,$destino,$variante,Inventario::decimal($cantidad),$sku['costo'],json_encode($resultado),$op['id']));
            Auditoria::operacion($this->db,'almacen',$op['id']);
            $this->db->commit(); return $resultado;
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }

    public function listar(array $filtros)
    {
        $sql = 'SELECT o.id,o.folio,o.tipo,o.cantidad,o.motivo,UNIX_TIMESTAMP(o.creado_en) AS creado_unix,
            o.origen_id,l.nombre AS origen_nombre,o.destino_id,d.nombre AS destino_nombre,
            v.sku,v.nombre AS variante_nombre,p.nombre AS producto_nombre,u.codigo AS unidad_codigo,a.nombre AS usuario_nombre
            FROM operaciones_almacen o JOIN ubicaciones l ON l.id=o.origen_id LEFT JOIN ubicaciones d ON d.id=o.destino_id
            JOIN variantes_producto v ON v.id=o.variante_id JOIN productos p ON p.id=v.producto_id
            JOIN unidades u ON u.id=p.unidad_base_id JOIN usuarios a ON a.id=o.usuario_id WHERE o.resultado IS NOT NULL';
        $values = array();
        if (isset($filtros['ubicacion_id']) && $filtros['ubicacion_id'] !== '') { $id=Producto::identificador($filtros['ubicacion_id']);$sql.=' AND (o.origen_id=? OR o.destino_id=?)';$values[]=$id;$values[]=$id; }
        if (isset($filtros['antes_de']) && $filtros['antes_de'] !== '') { $sql.=' AND o.id<?';$values[]=Producto::identificador($filtros['antes_de']); }
        $q=$this->db->prepare($sql.' ORDER BY o.id DESC LIMIT 51');$q->execute($values);$rows=$q->fetchAll();$more=count($rows)>50;if($more){array_pop($rows);}
        return array('operaciones'=>$rows,'siguiente'=>$more?$rows[count($rows)-1]['id']:null);
    }
}
