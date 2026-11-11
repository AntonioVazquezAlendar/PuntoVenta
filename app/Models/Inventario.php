<?php
require_once __DIR__.'/../Services/Auditoria.php';

require_once __DIR__ . '/Producto.php';

class Inventario
{
    private $db;
    const MAXIMO = 99999999999999;

    public function __construct(PDO $db) { $this->db = $db; }

    // Aritmética exacta a cuatro decimales, sin convertir cantidades a float.
    public static function unidades($valor, $firmado = false)
    {
        $patron = $firmado ? '/^-?(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?$/D' : '/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?$/D';
        if (!is_scalar($valor) || !preg_match($patron, (string) $valor)) { throw new HttpError('Escribe una cantidad válida, con un máximo de 4 decimales.'); }
        $texto = (string) $valor; $negativo = substr($texto, 0, 1) === '-';
        $partes = explode('.', ltrim($texto, '-'));
        $numero = (int) $partes[0] * 10000 + (int) str_pad(isset($partes[1]) ? $partes[1] : '', 4, '0');
        return $negativo ? -$numero : $numero;
    }

    public static function decimal($valor)
    {
        return ($valor < 0 ? '-' : '') . intdiv(abs($valor), 10000) . '.' . str_pad((string) (abs($valor) % 10000), 4, '0', STR_PAD_LEFT);
    }

    public function ubicaciones()
    {
        return $this->db->query('SELECT id,nombre,descripcion,activo,disponible_venta FROM ubicaciones ORDER BY activo DESC,nombre,id')->fetchAll();
    }

    public function existencias($ubicacion)
    {
        $q = $this->db->prepare('SELECT id FROM ubicaciones WHERE id=?'); $q->execute(array($ubicacion));
        if (!$q->fetchColumn()) { throw new HttpError('Ubicación no encontrada.', 404); }
        $q = $this->db->prepare('SELECT v.id AS variante_id,p.id AS producto_id,p.nombre AS producto_nombre,
            v.nombre AS variante_nombre,v.sku,p.categoria_id,c.nombre AS categoria_nombre,
            u.codigo AS unidad_codigo,u.permite_decimales,p.activo AS producto_activo,v.activo AS variante_activo,
            p.controla_inventario,COALESCE(mi.minimo,v.stock_minimo) AS stock_minimo,COALESCE(e.cantidad,0.0000) AS cantidad,e.actualizado_en,
            EXISTS(SELECT 1 FROM movimientos_inventario m WHERE m.variante_id=v.id AND m.ubicacion_id=?) AS tiene_movimientos
            FROM variantes_producto v JOIN productos p ON p.id=v.producto_id
            JOIN unidades u ON u.id=p.unidad_base_id LEFT JOIN categorias c ON c.id=p.categoria_id
            LEFT JOIN existencias e ON e.variante_id=v.id AND e.ubicacion_id=?
            LEFT JOIN minimos_inventario mi ON mi.variante_id=v.id AND mi.ubicacion_id=?
            WHERE p.controla_inventario=1 OR e.id IS NOT NULL
               OR EXISTS(SELECT 1 FROM movimientos_inventario h WHERE h.variante_id=v.id AND h.ubicacion_id=?)
            ORDER BY p.nombre,v.nombre,v.id');
        $q->execute(array($ubicacion,$ubicacion,$ubicacion,$ubicacion));
        return $q->fetchAll();
    }

    public function historial(array $filtros)
    {
        $sql = 'SELECT m.id,m.ubicacion_id,l.nombre AS ubicacion_nombre,m.variante_id,v.sku,
            p.nombre AS producto_nombre,v.nombre AS variante_nombre,u.codigo AS unidad_codigo,
            m.tipo_movimiento,m.tipo_referencia,m.referencia_id,m.cantidad,m.notas,m.creado_en,UNIX_TIMESTAMP(m.creado_en) AS creado_unix,
            usuario.nombre AS usuario_nombre,o.folio AS folio_documento,lo.nombre AS origen_nombre,ld.nombre AS destino_nombre FROM movimientos_inventario m
            JOIN ubicaciones l ON l.id=m.ubicacion_id JOIN variantes_producto v ON v.id=m.variante_id
            JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id
            LEFT JOIN usuarios usuario ON usuario.id=m.usuario_id
            LEFT JOIN operaciones_almacen o ON o.id=m.referencia_id AND m.tipo_referencia IN (\'traspaso\',\'merma\')
            LEFT JOIN ubicaciones lo ON lo.id=o.origen_id LEFT JOIN ubicaciones ld ON ld.id=o.destino_id WHERE 1=1';
        $values = array();
        foreach (array('ubicacion_id','variante_id') as $key) {
            if (isset($filtros[$key]) && $filtros[$key] !== '') { $sql .= ' AND m.' . $key . '=?'; $values[] = Producto::identificador($filtros[$key]); }
        }
        if (isset($filtros['antes_de']) && $filtros['antes_de'] !== '') { $sql .= ' AND m.id<?'; $values[] = Producto::identificador($filtros['antes_de']); }
        $sql .= ' ORDER BY m.id DESC LIMIT 51';
        $q = $this->db->prepare($sql); $q->execute($values); $rows = $q->fetchAll();
        $mas = count($rows) > 50; if ($mas) { array_pop($rows); }
        return array('movimientos'=>$rows,'siguiente'=>$mas ? $rows[count($rows)-1]['id'] : null);
    }

    public function ajustar(array $datos, $actorId)
    {
        $ubicacion = Producto::identificador(isset($datos['ubicacion_id']) ? $datos['ubicacion_id'] : null);
        $variante = Producto::identificador(isset($datos['variante_id']) ? $datos['variante_id'] : null);
        $motivo = Producto::texto($datos,'motivo',300);
        $modo = isset($datos['modo']) && is_string($datos['modo']) ? $datos['modo'] : '';
        if (!in_array($modo,array('inicial','entrada','salida','conteo'),true)) { throw new HttpError('Selecciona el tipo de ajuste.'); }
        $cantidad = self::unidades(isset($datos['cantidad']) ? $datos['cantidad'] : null);
        if ($modo !== 'conteo' && $cantidad === 0) { throw new HttpError('La cantidad debe ser mayor que cero.'); }
        $esperado = $modo === 'conteo' ? self::unidades(isset($datos['saldo_esperado']) ? $datos['saldo_esperado'] : null,true) : null;
        $clave = isset($datos['clave']) && is_string($datos['clave']) ? $datos['clave'] : '';
        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$clave)) { throw new HttpError('La solicitud no es válida. Cierra el formulario y vuelve a abrirlo.'); }
        $huella = hash('sha256',json_encode(array($ubicacion,$variante,$modo,$cantidad,$esperado,$motivo)));
        $this->db->beginTransaction();
        try {
            // Mismo orden de bloqueos que administración: roles, usuario y luego catálogo.
            $this->db->query('SELECT id FROM roles ORDER BY id FOR SHARE')->fetchAll();
            $q = $this->db->prepare('SELECT id FROM usuarios WHERE id=? FOR SHARE'); $q->execute(array($actorId)); $q->fetchColumn();
            $actor = (new Usuario($this->db))->buscar($actorId);
            if (!$actor || !$actor['activo'] || (empty($actor['permisos']['todo']) && (empty($actor['permisos']['ajustar_inventario']) || empty($actor['permisos']['ver_inventario'])))) {
                throw new HttpError('No tienes permiso para ajustar inventario.',403);
            }
            $q = $this->db->prepare('INSERT IGNORE INTO ajustes_inventario (usuario_id,clave,huella) VALUES (?,?,?)'); $q->execute(array($actorId,$clave,$huella));
            $q = $this->db->prepare('SELECT id,huella,resultado FROM ajustes_inventario WHERE usuario_id=? AND clave=? FOR UPDATE'); $q->execute(array($actorId,$clave)); $operacion = $q->fetch();
            if (!hash_equals($operacion['huella'],$huella)) { throw new HttpError('Esa solicitud ya se usó con otros datos. Vuelve a abrir el formulario.',409); }
            if ($operacion['resultado'] !== null) { $this->db->commit(); return json_decode($operacion['resultado'],true); }
            $q = $this->db->prepare('SELECT id,activo FROM ubicaciones WHERE id=? FOR SHARE'); $q->execute(array($ubicacion)); $lugar = $q->fetch();
            if (!$lugar || !$lugar['activo']) { throw new HttpError('Selecciona una ubicación activa.'); }
            // Bloquear producto antes de variante para coordinarse con la edición del catálogo.
            $q = $this->db->prepare('SELECT producto_id FROM variantes_producto WHERE id=?'); $q->execute(array($variante)); $productoId = $q->fetchColumn();
            if (!$productoId) { throw new HttpError('Variante no encontrada.',404); }
            $q = $this->db->prepare('SELECT p.activo,p.controla_inventario,p.tipo_producto,u.permite_decimales FROM productos p JOIN unidades u ON u.id=p.unidad_base_id WHERE p.id=? FOR SHARE'); $q->execute(array($productoId)); $producto = $q->fetch();
            $q = $this->db->prepare('SELECT id,activo,costo FROM variantes_producto WHERE id=? FOR UPDATE'); $q->execute(array($variante)); $sku = $q->fetch();
            if (!$producto || !$producto['activo'] || !$sku['activo'] || !$producto['controla_inventario'] || $producto['tipo_producto'] === 'servicio') { throw new HttpError('Solo se puede ajustar una variante activa que controle inventario.'); }
            if (!$producto['permite_decimales'] && $cantidad % 10000 !== 0) { throw new HttpError('La unidad de este producto solo permite cantidades enteras.'); }
            $this->db->prepare('INSERT INTO existencias (ubicacion_id,variante_id,cantidad) VALUES (?,?,0) ON DUPLICATE KEY UPDATE cantidad=cantidad')->execute(array($ubicacion,$variante));
            $q = $this->db->prepare('SELECT cantidad FROM existencias WHERE ubicacion_id=? AND variante_id=? FOR UPDATE'); $q->execute(array($ubicacion,$variante)); $saldo = self::unidades($q->fetchColumn(),true);
            if ($modo === 'inicial') {
                $q = $this->db->prepare('SELECT id FROM movimientos_inventario WHERE ubicacion_id=? AND variante_id=? LIMIT 1 FOR UPDATE'); $q->execute(array($ubicacion,$variante));
                if ($saldo !== 0 || $q->fetchColumn()) { throw new HttpError('Esta variante ya tiene inventario registrado en esta ubicación. Usa una entrada o un conteo físico.',409); }
            }
            if ($modo === 'conteo' && $saldo !== $esperado) { throw new HttpError('La existencia cambió mientras contabas. Actualiza el inventario y revisa el conteo antes de guardarlo.',409); }
            $delta = $modo === 'salida' ? -$cantidad : ($modo === 'conteo' ? $cantidad-$saldo : $cantidad);
            $nuevo = $saldo+$delta;
            if ($delta === 0) { throw new HttpError('El conteo coincide con la existencia actual. No hace falta un ajuste.'); }
            if (abs($nuevo) > self::MAXIMO || abs($delta) > self::MAXIMO) { throw new HttpError('La cantidad resultante supera el límite de inventario.'); }
            $this->db->prepare('UPDATE existencias SET cantidad=cantidad+? WHERE ubicacion_id=? AND variante_id=?')->execute(array(self::decimal($delta),$ubicacion,$variante));
            $this->db->prepare("INSERT INTO movimientos_inventario (ubicacion_id,variante_id,tipo_movimiento,cantidad,costo_unitario,tipo_referencia,referencia_id,usuario_id,notas) VALUES (?,?,'ajuste',?,?,?, ?,?,?)")
                ->execute(array($ubicacion,$variante,self::decimal($delta),$sku['costo'],$modo === 'inicial' ? 'inventario_inicial' : 'ajuste_' . $modo,$operacion['id'],$actorId,$motivo));
            $resultado = array('movimiento_id'=>$this->db->lastInsertId(),'cantidad_anterior'=>self::decimal($saldo),'cambio'=>self::decimal($delta),'cantidad_nueva'=>self::decimal($nuevo));
            $this->db->prepare('UPDATE ajustes_inventario SET resultado=? WHERE id=?')->execute(array(json_encode($resultado),$operacion['id']));
            Auditoria::operacion($this->db,'ajuste',$operacion['id']);
            $this->db->commit(); return $resultado;
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }
}
