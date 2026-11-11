<?php
require_once __DIR__.'/../Services/Auditoria.php';

class Rol
{
    private $db;
    public function __construct(PDO $db) { $this->db = $db; }

    public static function catalogo()
    {
        return array(
            array('clave' => 'ver_productos', 'nombre' => 'Consultar productos', 'grupo' => 'Catálogo', 'disponible' => true),
            array('clave' => 'editar_productos', 'nombre' => 'Crear, editar y desactivar productos y categorías', 'grupo' => 'Catálogo', 'disponible' => true),
            array('clave' => 'vender', 'nombre' => 'Realizar ventas y consultar mis tickets', 'grupo' => 'Ventas', 'disponible' => true),
            array('clave' => 'ver_ventas', 'nombre' => 'Consultar mis ventas y tickets', 'grupo' => 'Ventas', 'disponible' => true),
            array('clave' => 'dar_descuento', 'nombre' => 'Dar descuentos', 'grupo' => 'Ventas', 'disponible' => true),
            array('clave' => 'cambiar_precio', 'nombre' => 'Cambiar precios al vender', 'grupo' => 'Ventas', 'disponible' => true),
            array('clave' => 'cancelar_venta', 'nombre' => 'Solicitar cancelaciones del turno original abierto', 'grupo' => 'Ventas', 'disponible' => true),
            array('clave' => 'hacer_devoluciones', 'nombre' => 'Solicitar devoluciones y reembolsos', 'grupo' => 'Ventas', 'disponible' => true),
            array('clave'=>'ver_clientes','nombre'=>'Consultar clientes e historial autorizado','grupo'=>'Clientes','disponible'=>true),
            array('clave'=>'editar_clientes','nombre'=>'Crear, editar y desactivar clientes','grupo'=>'Clientes','disponible'=>true),
            array('clave' => 'ver_inventario', 'nombre' => 'Consultar existencias e historial', 'grupo' => 'Inventario', 'disponible' => true),
            array('clave'=>'ver_alertas_inventario','nombre'=>'Consultar alertas de inventario y lista de reposición','grupo'=>'Inventario','disponible'=>true),
            array('clave'=>'configurar_minimos','nombre'=>'Configurar mínimos por ubicación','grupo'=>'Inventario','disponible'=>true),
            array('clave' => 'ajustar_inventario', 'nombre' => 'Registrar inventario inicial y ajustes', 'grupo' => 'Inventario', 'disponible' => true),
            array('clave' => 'administrar_ubicaciones', 'nombre' => 'Crear y editar ubicaciones de venta o resguardo', 'grupo' => 'Inventario', 'disponible' => true),
            array('clave' => 'realizar_traspasos', 'nombre' => 'Traspasar mercancía entre ubicaciones', 'grupo' => 'Inventario', 'disponible' => true),
            array('clave' => 'registrar_mermas', 'nombre' => 'Registrar bajas definitivas por merma', 'grupo' => 'Inventario', 'disponible' => true),
            array('clave' => 'hacer_compras', 'nombre' => 'Administrar compras, proveedores y recepción', 'grupo' => 'Compras', 'disponible' => true),
            array('clave' => 'operar_caja', 'nombre' => 'Abrir, operar y cerrar mis turnos de caja', 'grupo' => 'Caja', 'disponible' => true),
            array('clave' => 'supervisar_caja', 'nombre' => 'Consultar y operar turnos de otros usuarios', 'grupo' => 'Caja', 'disponible' => true),
            array('clave' => 'administrar_cajas', 'nombre' => 'Crear y configurar cajas', 'grupo' => 'Caja', 'disponible' => true),
            array('clave'=>'ver_gastos','nombre'=>'Consultar mis gastos (administrador: todos)','grupo'=>'Gastos','disponible'=>true),
            array('clave'=>'registrar_gastos','nombre'=>'Registrar gastos del negocio','grupo'=>'Gastos','disponible'=>true),
            array('clave'=>'ver_cuentas_pagar','nombre'=>'Consultar cuentas por pagar de proveedores','grupo'=>'Proveedores','disponible'=>true),
            array('clave'=>'pagar_proveedores','nombre'=>'Registrar pagos a proveedores','grupo'=>'Proveedores','disponible'=>true),
            array('clave'=>'vender_credito','nombre'=>'Vender a crédito dentro del límite del cliente','grupo'=>'Crédito','disponible'=>true),
            array('clave'=>'ver_creditos','nombre'=>'Consultar créditos de mis ventas (administrador: todos)','grupo'=>'Crédito','disponible'=>true),
            array('clave'=>'cobrar_abonos','nombre'=>'Recibir abonos en mi caja','grupo'=>'Crédito','disponible'=>true),
            array('clave' => 'ver_reportes', 'nombre' => 'Consultar reportes de mis operaciones (administrador: todas)', 'grupo' => 'Reportes', 'disponible' => true)
        );
    }

    public function guardar($id, array $datos, $actorId)
    {
        $nombre = null;
        if ($id === null) {
            $nombre = isset($datos['nombre']) && is_string($datos['nombre']) ? trim($datos['nombre']) : '';
            if (!preg_match('/^.{1,60}$/uD', $nombre) || preg_match('/[\x00-\x1f\x7f]/u', $nombre)) {
                throw new HttpError('Escribe un nombre de rol de entre 1 y 60 caracteres, sin saltos de línea.');
            }
        }
        if (!isset($datos['permisos']) || !is_array($datos['permisos'])) { throw new HttpError('Envía la lista de permisos.'); }
        $claves = array_column(self::catalogo(), 'clave');
        if (count($datos['permisos']) !== count($claves) || array_diff(array_keys($datos['permisos']), $claves)) {
            throw new HttpError('La lista de permisos no es válida. Recarga la página.');
        }
        foreach ($claves as $clave) {
            if (!array_key_exists($clave, $datos['permisos']) || !is_bool($datos['permisos'][$clave])) {
                throw new HttpError('Cada permiso debe estar activado o desactivado.');
            }
        }
        // Las acciones de venta requieren acceso a ventas.
        if($datos['permisos']['editar_clientes']&&!$datos['permisos']['ver_clientes'])throw new HttpError('Activa Consultar clientes antes de permitir editarlos.');
        if ($datos['permisos']['editar_productos'] && !$datos['permisos']['ver_productos']) { throw new HttpError('Activa Consultar productos antes de permitir editar el catálogo.'); }
        if ($datos['permisos']['ajustar_inventario'] && !$datos['permisos']['ver_inventario']) { throw new HttpError('Activa Consultar existencias e historial antes de permitir ajustes.'); }
        if($datos['permisos']['ver_alertas_inventario']&&!$datos['permisos']['ver_inventario'])throw new HttpError('Activa Consultar existencias e historial antes de permitir alertas.');
        if($datos['permisos']['configurar_minimos']&&!$datos['permisos']['ver_alertas_inventario'])throw new HttpError('Activa Consultar alertas antes de permitir configurar mínimos.');
        foreach (array('administrar_ubicaciones','realizar_traspasos','registrar_mermas') as $permiso) {
            if ($datos['permisos'][$permiso] && !$datos['permisos']['ver_inventario']) { throw new HttpError('Activa Consultar existencias e historial antes de permitir operaciones de almacén.'); }
        }
        foreach(array('supervisar_caja','administrar_cajas') as $permiso){
            if($datos['permisos'][$permiso]&&!$datos['permisos']['operar_caja']){throw new HttpError('Activa Abrir, operar y cerrar mis turnos antes de permitir supervisión o configuración de cajas.');}
        }
        foreach(array('cancelar_venta','hacer_devoluciones') as $permiso)if($datos['permisos'][$permiso]&&(!$datos['permisos']['vender']||!$datos['permisos']['operar_caja']))throw new HttpError('Activa ventas y operación de caja antes de permitir cancelaciones o devoluciones.');
        foreach (array('dar_descuento', 'cambiar_precio', 'cancelar_venta') as $dependiente) {
            if ($datos['permisos'][$dependiente] && !$datos['permisos']['vender']) {
                throw new HttpError('Activa Realizar ventas antes de permitir descuentos, cambios de precio o cancelaciones.');
            }
        }
        if($datos['permisos']['vender_credito']&&(!$datos['permisos']['vender']||!$datos['permisos']['operar_caja']))throw new HttpError('Activa ventas y operación de caja antes de permitir crédito.');
        if($datos['permisos']['cobrar_abonos']&&(!$datos['permisos']['ver_creditos']||!$datos['permisos']['operar_caja']))throw new HttpError('Activa consulta de créditos y operación de caja antes de permitir abonos.');
        if($datos['permisos']['pagar_proveedores']&&!$datos['permisos']['ver_cuentas_pagar'])throw new HttpError('Activa consulta de cuentas por pagar antes de permitir pagos.');
        if($datos['permisos']['registrar_gastos']&&!$datos['permisos']['ver_gastos'])throw new HttpError('Activa consulta de gastos antes de permitir su registro.');
        $this->db->beginTransaction();
        try {
            $roles = $this->db->query('SELECT id, nombre, permisos FROM roles ORDER BY id FOR UPDATE')->fetchAll();
            $actor = (new Usuario($this->db))->buscar($actorId);
            if (!$actor || !$actor['activo'] || empty($actor['permisos']['todo'])) {
                throw new HttpError('Solo el administrador puede asignar permisos.', 403);
            }
            if ($id === null) {
                $this->db->prepare('INSERT INTO roles (nombre, permisos) VALUES (?, ?)')
                    ->execute(array($nombre, json_encode($datos['permisos'])));
                $id = $this->db->lastInsertId();
                Auditoria::registrar($this->db,$actorId,'rol_creado','Rol '.$id,$nombre,null,array('nombre'=>$nombre,'permisos'=>$datos['permisos']));
                $this->db->commit();
                return array('id' => $id, 'nombre' => $nombre, 'permisos' => $datos['permisos']);
            }
            $rol = null;
            foreach ($roles as $row) { if ((string) $row['id'] === (string) $id) { $rol = $row; break; } }
            if (!$rol) { throw new HttpError('Rol no encontrado.', 404); }
            $actuales = json_decode($rol['permisos'], true) ?: array();
            if (!empty($actuales['todo']) || $rol['nombre'] === 'Administrador') {
                throw new HttpError('El administrador conserva acceso completo. Sus permisos no se modifican.', 409);
            }
            $this->db->prepare('UPDATE roles SET permisos = ? WHERE id = ?')
                ->execute(array(json_encode($datos['permisos']), $id));
            $normal=array_fill_keys(array_column(self::catalogo(),'clave'),false);foreach($normal as $k=>&$v)$v=!empty($actuales[$k]);unset($v);Auditoria::registrar($this->db,$actorId,'permisos_editados','Rol '.$id,$rol['nombre'],array('permisos'=>$normal),array('permisos'=>$datos['permisos']));
            $this->db->commit();
            return array('id' => $rol['id'], 'nombre' => $rol['nombre'], 'permisos' => $datos['permisos']);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            if ($e instanceof PDOException && isset($e->errorInfo[1]) && (int) $e->errorInfo[1] === 1062) {
                throw new HttpError('Ya existe un rol con ese nombre. Elige otro.', 409);
            }
            throw $e;
        }
    }
}
