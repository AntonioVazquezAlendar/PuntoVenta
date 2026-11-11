<?php
require_once __DIR__.'/../Services/Auditoria.php';

require_once __DIR__.'/ProductoImagen.php';

class Producto
{
    private $connection;

    public function __construct(PDO $connection)
    {
        $this->connection = $connection;
    }

    public function listarActivos($todos = false, $conCosto = false)
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.nombre, p.descripcion, p.categoria_id,
                    c.nombre AS categoria_nombre, p.unidad_base_id,
                    u.codigo AS unidad_codigo, u.nombre AS unidad_nombre,
                    u.permite_decimales, p.impuesto_id,
                    i.nombre AS impuesto_nombre, i.tasa AS tasa_impuesto,
                    p.tipo_producto, p.precio_incluye_impuesto,
                    p.controla_inventario, p.datos_extra, p.activo,
                    v.id AS variante_id, v.sku, v.nombre AS variante_nombre,
                    v.precio, v.stock_minimo, v.activo AS variante_activo,
                    CASE WHEN p.activo=1 AND v.activo=1 AND p.controla_inventario=1 THEN COALESCE(s.disponible,0.0000) ELSE 0.0000 END AS existencia_disponible' . ($conCosto ? ', v.costo' : '') . '
             FROM productos p
             LEFT JOIN categorias c ON c.id = p.categoria_id
             JOIN unidades u ON u.id = p.unidad_base_id
             JOIN impuestos i ON i.id = p.impuesto_id
             LEFT JOIN variantes_producto v ON v.producto_id = p.id' . ($todos ? '' : ' AND v.activo = 1') . '
             LEFT JOIN (SELECT e.variante_id,SUM(e.cantidad) AS disponible FROM existencias e JOIN ubicaciones l ON l.id=e.ubicacion_id WHERE l.activo=1 AND l.disponible_venta=1 GROUP BY e.variante_id) s ON s.variante_id=v.id
             WHERE ' . ($todos ? '1 = :activo' : 'p.activo = :activo') . '
             ORDER BY p.nombre, p.id, v.id'
        );
        $statement->execute(array('activo' => 1));

        $productos = array();
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $id = $row['id'];
            if (!isset($productos[$id])) {
                $producto = $row;
                unset($producto['variante_id'], $producto['sku'],
                    $producto['variante_nombre'], $producto['precio'],
                    $producto['stock_minimo'], $producto['variante_activo'], $producto['costo'], $producto['existencia_disponible']);
                $producto['datos_extra'] = $row['datos_extra'] === null
                    ? null : json_decode($row['datos_extra']);
                $producto['variantes'] = array();
                $productos[$id] = $producto;
            }

            if ($row['variante_id'] !== null) {
                $variante = array(
                    'id' => $row['variante_id'],
                    'sku' => $row['sku'],
                    'nombre' => $row['variante_nombre'],
                    'precio' => $row['precio'],
                    'stock_minimo' => $row['stock_minimo'], 'activo' => $row['variante_activo'], 'existencia_disponible' => $row['existencia_disponible']
                );
                if ($conCosto) { $variante['costo'] = $row['costo']; }
                $productos[$id]['variantes'][] = $variante;
            }
        }

        return (new ProductoImagen($this->connection))->adjuntar(array_values($productos));
    }

    public static function texto($datos, $clave, $max, $requerido = true)
    {
        $valor = isset($datos[$clave]) && is_string($datos[$clave]) ? trim($datos[$clave]) : '';
        if (($requerido && $valor === '') || preg_match('/^.{0,' . $max . '}$/usD', $valor) !== 1) { throw new HttpError('Revisa el campo ' . str_replace('_', ' ', $clave) . ' (máximo ' . $max . ' caracteres).'); }
        return $valor;
    }

    public static function bandera($datos, $clave)
    {
        if (!isset($datos[$clave]) || !in_array($datos[$clave], array(0, 1, '0', '1', false, true), true)) { throw new HttpError('Revisa el campo ' . str_replace('_', ' ', $clave) . '.'); }
        return (int) $datos[$clave];
    }

    public static function identificador($valor, $opcional = false)
    {
        if ($opcional && ($valor === null || $valor === '')) { return null; }
        if (!is_scalar($valor) || !preg_match('/^[1-9][0-9]{0,19}$/D', (string) $valor)) { throw new HttpError('Selecciona una opción válida.'); }
        return (string) $valor;
    }

    public function guardar(array $datos, $id = null, array $archivos = array(), $actorId = null)
    {
        $nombre = self::texto($datos, 'nombre', 200);
        $descripcion = self::texto($datos, 'descripcion', 5000, false);
        $categoria = self::identificador(isset($datos['categoria_id']) ? $datos['categoria_id'] : null, true);
        $unidad = self::identificador(isset($datos['unidad_base_id']) ? $datos['unidad_base_id'] : null);
        $impuesto = self::identificador(isset($datos['impuesto_id']) ? $datos['impuesto_id'] : null);
        $tipo = isset($datos['tipo_producto']) && is_string($datos['tipo_producto']) ? $datos['tipo_producto'] : '';
        if (!in_array($tipo, array('estandar', 'granel', 'servicio', 'kit'), true)) { throw new HttpError('Selecciona un tipo de producto válido.'); }
        $activo = self::bandera($datos, 'activo'); $incluye = self::bandera($datos, 'precio_incluye_impuesto');
        $inventario = $tipo === 'servicio' ? 0 : self::bandera($datos, 'controla_inventario');
        if (!isset($datos['variantes']) || !is_array($datos['variantes']) || count($datos['variantes']) < 1 || count($datos['variantes']) > 100) { throw new HttpError('Agrega entre 1 y 100 variantes. Un producto sin colores o tallas también necesita un SKU.'); }
        $variantes = array(); $ids = array(); $activas = 0;
        foreach ($datos['variantes'] as $v) {
            if (!is_array($v)) { throw new HttpError('Revisa las variantes.'); }
            $variante = array('id' => self::identificador(isset($v['id']) ? $v['id'] : null, true), 'sku' => self::texto($v, 'sku', 64), 'nombre' => self::texto($v, 'nombre', 200, false), 'activo' => self::bandera($v, 'activo'));
            foreach (array('costo', 'precio', 'stock_minimo') as $campo) {
                if (!isset($v[$campo]) || !is_scalar($v[$campo]) || !preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,4})?$/D', (string) $v[$campo])) { throw new HttpError('El ' . str_replace('_', ' ', $campo) . ' debe ser positivo o cero, con hasta 4 decimales.'); }
                $variante[$campo] = (string) $v[$campo];
            }
            if ($variante['id'] !== null) {
                if (isset($ids[$variante['id']])) { throw new HttpError('Una variante está repetida.'); }
                $ids[$variante['id']] = true;
            }
            $activas += $variante['activo']; $variantes[] = $variante;
        }
        if ($activo && !$activas) { throw new HttpError('Un producto activo necesita al menos una variante activa.'); }
        $galeria=new ProductoImagen($this->connection);
        $this->connection->beginTransaction();
        try {
            $creando=$id===null;$anterior=null;
            $actual = null;
            if ($id !== null) {
                $q = $this->connection->prepare('SELECT * FROM productos WHERE id = ? FOR UPDATE'); $q->execute(array($id)); $actual = $q->fetch();
                if (!$actual) { throw new HttpError('Producto no encontrado.', 404); }$anterior=Auditoria::producto($this->connection,$id);
            }
            foreach (array(array('categorias', $categoria, $actual ? $actual['categoria_id'] : null), array('unidades', $unidad, $actual ? $actual['unidad_base_id'] : null), array('impuestos', $impuesto, $actual ? $actual['impuesto_id'] : null)) as $ref) {
                if ($ref[1] === null) { continue; }
                $q = $this->connection->prepare('SELECT id FROM ' . $ref[0] . ' WHERE id = ? AND (activo = 1 OR id = ?) FOR SHARE'); $q->execute(array($ref[1], $ref[2]));
                if (!$q->fetchColumn()) { throw new HttpError('La categoría, unidad o impuesto no está disponible.'); }
            }
            if ($actual && (string) $actual['unidad_base_id'] !== $unidad) {
                foreach (array('existencias', 'movimientos_inventario', 'detalle_venta', 'detalle_compra') as $tabla) {
                    $q = $this->connection->prepare('SELECT 1 FROM ' . $tabla . ' d JOIN variantes_producto v ON v.id = d.variante_id WHERE v.producto_id = ? LIMIT 1'); $q->execute(array($id));
                    if ($q->fetchColumn()) { throw new HttpError('No puedes cambiar la unidad base de un producto con inventario o historial.', 409); }
                }
            }
            $existentes = array();
            if ($actual) {
                $q = $this->connection->prepare('SELECT id FROM variantes_producto WHERE producto_id = ? FOR UPDATE'); $q->execute(array($id)); $existentes = $q->fetchAll(PDO::FETCH_COLUMN);
            }
            if (array_diff(array_keys($ids), $existentes) || array_diff($existentes, array_keys($ids))) { throw new HttpError('Las variantes cambiaron o no pertenecen a este producto. Cierra el formulario y vuelve a abrirlo.', 409); }
            $values = array($nombre, $descripcion, $categoria, $unidad, $impuesto, $tipo, $incluye, $inventario, $activo);
            if ($id === null) {
                $this->connection->prepare('INSERT INTO productos (nombre,descripcion,categoria_id,unidad_base_id,impuesto_id,tipo_producto,precio_incluye_impuesto,controla_inventario,activo) VALUES (?,?,?,?,?,?,?,?,?)')->execute($values); $id = $this->connection->lastInsertId();
            } else {
                $values[] = $id;
                $this->connection->prepare('UPDATE productos SET nombre=?,descripcion=?,categoria_id=?,unidad_base_id=?,impuesto_id=?,tipo_producto=?,precio_incluye_impuesto=?,controla_inventario=?,activo=? WHERE id=?')->execute($values);
            }
            foreach ($variantes as $v) {
                $values = array($v['sku'], $v['nombre'] === '' ? null : $v['nombre'], $v['costo'], $v['precio'], $v['stock_minimo'], $v['activo']);
                if ($v['id'] === null) {
                    $values[] = $id;
                    $this->connection->prepare('INSERT INTO variantes_producto (sku,nombre,costo,precio,stock_minimo,activo,producto_id) VALUES (?,?,?,?,?,?,?)')->execute($values);
                } else {
                    $values[] = $v['id']; $values[] = $id;
                    $this->connection->prepare('UPDATE variantes_producto SET sku=?,nombre=?,costo=?,precio=?,stock_minimo=?,activo=? WHERE id=? AND producto_id=?')->execute($values);
                }
            }
            if(array_key_exists('imagenes',$datos))$galeria->guardar($id,$datos,$archivos);
            Auditoria::registrar($this->connection,$actorId,$creando?'producto_creado':'producto_editado','Producto '.$id,$nombre,$anterior,Auditoria::producto($this->connection,$id));
            $this->connection->commit();$galeria->confirmar();return array('id' => $id);
        } catch (Throwable $e) {
            $galeria->cancelar();
            if ($this->connection->inTransaction()) { $this->connection->rollBack(); }
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) { throw new HttpError('Ese SKU ya existe. Usa un código distinto para cada variante.', 409); }
            throw $e;
        }
    }
}
