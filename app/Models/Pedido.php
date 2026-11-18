<?php
require_once __DIR__.'/Producto.php';
require_once __DIR__.'/Venta.php';
require_once __DIR__.'/../Services/PortalPedidos.php';

class Pedido
{
    private $db;
    public function __construct(PDO $db){$this->db=$db;}

    public static function instalar(PDO $db)
    {
        $db->exec("CREATE TABLE IF NOT EXISTS conexion_pedidos (id TINYINT UNSIGNED PRIMARY KEY,url VARCHAR(300) NOT NULL DEFAULT '',clave VARCHAR(128) NOT NULL,instalacion CHAR(32) NOT NULL,cursor_remoto BIGINT UNSIGNED NOT NULL DEFAULT 0,ultima_sync TIMESTAMP NULL) ENGINE=InnoDB");
        $q=$db->prepare('INSERT IGNORE INTO conexion_pedidos(id,clave,instalacion) VALUES(1,?,?)');$q->execute(array(bin2hex(random_bytes(32)),bin2hex(random_bytes(16))));
        $db->exec("CREATE TABLE IF NOT EXISTS catalogo_pedidos (variante_id BIGINT UNSIGNED PRIMARY KEY,cotizacion TINYINT(1) NOT NULL DEFAULT 0,FOREIGN KEY(variante_id) REFERENCES variantes_producto(id)) ENGINE=InnoDB");
        if(!$db->query("SHOW COLUMNS FROM catalogo_pedidos LIKE 'cotizacion'")->fetch())$db->exec('ALTER TABLE catalogo_pedidos ADD cotizacion TINYINT(1) NOT NULL DEFAULT 0');
        $db->exec("CREATE TABLE IF NOT EXISTS pedidos (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,remoto_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,secuencia BIGINT UNSIGNED NOT NULL,folio VARCHAR(30) NOT NULL,nombre VARCHAR(200) NOT NULL,celular VARCHAR(20) NOT NULL,notas VARCHAR(500) NOT NULL,contenido JSON NOT NULL,total_estimado DECIMAL(16,2) NOT NULL,estado ENUM('nuevo','confirmado','listo','en_venta','vendido','cancelado') NOT NULL DEFAULT 'nuevo',version BIGINT UNSIGNED NOT NULL DEFAULT 1,sincronizado_version BIGINT UNSIGNED NOT NULL DEFAULT 0,borrador_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NULL UNIQUE,venta_id BIGINT UNSIGNED NULL UNIQUE,usuario_id BIGINT UNSIGNED NULL,motivo VARCHAR(300) NULL,creado_unix BIGINT UNSIGNED NOT NULL,actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY ix_pedido_estado(estado,id),FOREIGN KEY(borrador_id) REFERENCES borradores_venta(id),FOREIGN KEY(venta_id) REFERENCES ventas(id),FOREIGN KEY(usuario_id) REFERENCES usuarios(id)) ENGINE=InnoDB");
        if(!$db->query("SHOW COLUMNS FROM pedidos LIKE 'origen'")->fetch())$db->exec("ALTER TABLE pedidos ADD origen ENUM('portal','local') NOT NULL DEFAULT 'portal',ADD solicitud_huella CHAR(64) NULL");
        if(!$db->query("SHOW COLUMNS FROM conexion_pedidos LIKE 'modo'")->fetch())$db->exec("ALTER TABLE conexion_pedidos ADD modo ENUM('php','remoto') NOT NULL DEFAULT 'php'");
        $db->exec('CREATE TABLE IF NOT EXISTS limites_portal(clave CHAR(64) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,cantidad INT UNSIGNED NOT NULL,expira BIGINT UNSIGNED NOT NULL,KEY ix_limite_expira(expira)) ENGINE=InnoDB');
    }

    private function actor($id,$manage=false)
    {
        $q=$this->db->prepare('SELECT u.id,u.nombre,u.activo,r.permisos FROM usuarios u JOIN roles r ON r.id=u.rol_id WHERE u.id=?');$q->execute(array($id));$a=$q->fetch();
        if(!$a||!$a['activo'])throw new HttpError('Inicia sesión con una cuenta activa.',401);
        $a['permisos']=json_decode($a['permisos'],true)?:array();$p=$a['permisos'];
        if(empty($p['todo']))throw new HttpError('Solo el administrador puede consultar y gestionar pedidos.',403);
        return $a;
    }
    private function admin($id){$a=$this->actor($id);if(empty($a['permisos']['todo']))throw new HttpError('Solo el administrador configura el portal.',403);return $a;}
    public function conexion(){return $this->db->query('SELECT * FROM conexion_pedidos WHERE id=1')->fetch();}
    public function crearLocal(array $d,$actorId){return $this->guardarCaptura($d,$actorId,'local');}
    public function crearPublico(array $d,$ip)
    {
        if(!empty($d['website']))throw new HttpError('No se pudo aceptar el pedido.');
        if(!isset($d['catalog_version'])||!is_string($d['catalog_version'])||!preg_match('/^[a-f0-9]{64}$/D',$d['catalog_version']))throw new HttpError('Actualiza el catálogo antes de enviar.');
        $lines=array();if(!isset($d['items'])||!is_array($d['items']))throw new HttpError('Agrega productos al pedido.');foreach($d['items'] as $l){if(!is_array($l))throw new HttpError('Revisa los productos.');$lines[]=array('variante_id'=>isset($l['id'])?$l['id']:null,'cantidad'=>isset($l['quantity'])?$l['quantity']:null);}
        return $this->guardarCaptura(array('clave'=>isset($d['request_key'])?$d['request_key']:null,'nombre'=>isset($d['name'])?$d['name']:null,'celular'=>isset($d['phone'])?$d['phone']:null,'notas'=>isset($d['notes'])?$d['notes']:'','detalles'=>$lines),null,'portal',$d['catalog_version'],$ip);
    }
    private function reciboPublico($id)
    {
        $q=$this->db->prepare('SELECT remoto_id,folio,total_estimado,creado_unix,contenido FROM pedidos WHERE id=?');$q->execute(array($id));$r=$q->fetch();$content=json_decode($r['contenido'],true);
        return array('id'=>$r['remoto_id'],'folio'=>$r['folio'],'total_cents'=>(int)round((float)$r['total_estimado']*100),'requires_quote'=>!empty($content['requires_quote']),'created'=>(int)$r['creado_unix']);
    }
    private function guardarCaptura(array $d,$actorId,$origin,$catalogVersion=null,$ip=null)
    {
        if($origin==='local')$this->admin($actorId);$key=BorradorVenta::uuid(isset($d['clave'])?$d['clave']:null);
        $name=Producto::texto($d,'nombre',120);$phone=Producto::texto($d,'celular',30);$phone=preg_replace('/[\s()\-]/','',$phone);
        if(!preg_match('/^\+?[0-9]{10,15}$/D',$phone))throw new HttpError('Escribe un celular de 10 a 15 dígitos.');
        $notes=Producto::texto($d,'notas',500,false);$raw=isset($d['detalles'])?$d['detalles']:null;
        if(!is_array($raw)||!count($raw)||count($raw)>100)throw new HttpError('Agrega entre 1 y 100 productos al pedido.');
        $lines=array();foreach($raw as $l){if(!is_array($l))throw new HttpError('Revisa los productos del pedido.');$id=Producto::identificador(isset($l['variante_id'])?$l['variante_id']:null);$units=Inventario::unidades(isset($l['cantidad'])?$l['cantidad']:null);if($units<=0||$units>100000000||isset($lines[$id]))throw new HttpError('Revisa las cantidades; no repitas productos y usa como máximo 10000 unidades por renglón.');$lines[$id]=array('id'=>$id,'quantity'=>Inventario::decimal($units));}
        ksort($lines,SORT_STRING);$hash=hash('sha256',json_encode(array((string)$actorId,$name,$phone,$notes,array_values($lines))));if($origin==='portal')$hash=hash('sha256',$hash.'|'.$catalogVersion);
        $existing=function()use($key,$hash,$actorId,$origin){$q=$this->db->prepare('SELECT id,origen,solicitud_huella FROM pedidos WHERE remoto_id=?');$q->execute(array($key));$r=$q->fetch();if(!$r)return null;if($r['origen']!==$origin||!is_string($r['solicitud_huella'])||!hash_equals($r['solicitud_huella'],$hash))throw new HttpError('Esta solicitud ya corresponde a otro pedido. Revisa el pedido guardado.',409);return $origin==='portal'?$this->reciboPublico($r['id']):$this->obtener($r['id'],$actorId);};
        $found=$existing();if($found)return $found;
        $this->db->beginTransaction();try{
            if($origin==='local')$this->admin($actorId);$items=array();$total=0;$quote=false;
            $q=$this->db->prepare('SELECT v.precio,v.nombre variante,p.nombre producto,p.precio_incluye_impuesto,u.codigo unidad,u.permite_decimales,i.tasa,COALESCE(c.cotizacion,0) cotizacion FROM variantes_producto v JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id JOIN impuestos i ON i.id=p.impuesto_id LEFT JOIN catalogo_pedidos c ON c.variante_id=v.id WHERE v.id=? AND v.activo=1 AND p.activo=1 AND i.activo=1 FOR SHARE');
            foreach($lines as $line){$q->execute(array($line['id']));$p=$q->fetch();if(!$p)throw new HttpError('Un producto dejó de estar activo. Actualiza el catálogo y revisa tu pedido.',409);$units=Inventario::unidades($line['quantity']);if(!$p['permite_decimales']&&$units%10000)throw new HttpError('La unidad de '.$p['producto'].' solo admite cantidades enteras.');$isQuote=(bool)$p['cotizacion'];$quote=$quote||$isQuote;$price=$isQuote?null:(int)round(Inventario::unidades($p['precio'])/100*($p['precio_incluye_impuesto']?1:1+Inventario::unidades($p['tasa'])/10000));if($price!==null&&$price>10000000000)throw new HttpError('El precio supera el límite de pedidos.');$subtotal=$isQuote?null:intdiv($price*$units+5000,10000);if($subtotal!==null)$total+=$subtotal;if($total>10000000000)throw new HttpError('El total del pedido supera el límite permitido.');$items[]=array('id'=>$line['id'],'quantity'=>$line['quantity'],'name'=>$p['producto'],'variant'=>$p['variante'],'unit'=>$p['unidad'],'quote_only'=>$isQuote,'price_cents'=>$price,'total_cents'=>$subtotal);}
            if($origin==='portal'){if(!hash_equals($this->catalogoClientes()['version'],$catalogVersion))throw new HttpError('El catálogo cambió. Revisa productos y total antes de confirmar otra vez.',409);$bucket=intdiv(time(),3600);$rateKey=hash('sha256',($ip?:'local').'|'.$bucket);$r=$this->db->prepare('INSERT INTO limites_portal(clave,cantidad,expira) VALUES(?,1,?) ON DUPLICATE KEY UPDATE cantidad=cantidad+1');$r->execute(array($rateKey,time()+3600));$r=$this->db->prepare('SELECT cantidad FROM limites_portal WHERE clave=? FOR UPDATE');$r->execute(array($rateKey));if((int)$r->fetchColumn()>30)throw new HttpError('Se alcanzó el límite de pedidos desde esta conexión. Intenta más tarde.',429);$this->db->prepare('DELETE FROM limites_portal WHERE expira<?')->execute(array(time()-3600));}
            $folio=($origin==='local'?'PL-':'PC-').strtoupper(substr(str_replace('-','',$key),0,16));$created=time();$content=array('source'=>$origin,'items'=>$items,'requires_quote'=>$quote,'total_cents'=>$total);
            $q=$this->db->prepare("INSERT INTO pedidos(remoto_id,secuencia,folio,nombre,celular,notas,contenido,total_estimado,creado_unix,origen,solicitud_huella,usuario_id,sincronizado_version) VALUES(?,0,?,?,?,?,?,?,?,?,?,?,1)");$q->execute(array($key,$folio,$name,$phone,$notes,json_encode($content),Caja::dinero($total),$created,$origin,$hash,$actorId));$id=$this->db->lastInsertId();
            Auditoria::registrar($this->db,$actorId,'pedido_creado',$folio,$origin==='local'?'Se creó un pedido desde el catálogo privado':'Se recibió un pedido del portal de clientes',null,array('pedido_id'=>$id,'renglones'=>count($items),'total_estimado'=>Caja::dinero($total),'requiere_cotizacion'=>$quote));
            $this->db->commit();return $origin==='portal'?$this->reciboPublico($id):$this->obtener($id,$actorId);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();if($e instanceof PDOException&&isset($e->errorInfo[1])&&(int)$e->errorInfo[1]===1062){$found=$existing();if($found)return $found;}throw $e;}
    }
    public function catalogoInterno($actorId)
    {
        $this->admin($actorId);
        $locations=(new Inventario($this->db))->ubicaciones();
        $balances=array();foreach($this->db->query('SELECT variante_id,ubicacion_id,cantidad FROM existencias')->fetchAll() as $r)$balances[$r['variante_id']][$r['ubicacion_id']]=$r['cantidad'];
        $quotes=array();foreach($this->db->query('SELECT variante_id FROM catalogo_pedidos WHERE cotizacion=1')->fetchAll() as $r)$quotes[$r['variante_id']]=true;
        $items=array();foreach((new Producto($this->db))->listarActivos() as $p){foreach($p['variantes'] as $v){
            $stock=array();foreach($locations as $l)$stock[]=array('ubicacion_id'=>(string)$l['id'],'cantidad'=>isset($balances[$v['id']][$l['id']])?$balances[$v['id']][$l['id']]:'0.0000');
            $price=Inventario::unidades($v['precio']);$tax=Inventario::unidades($p['tasa_impuesto']);
            $items[]=array('id'=>(string)$v['id'],'producto_id'=>(string)$p['id'],'nombre'=>$p['nombre'],'variante'=>$v['nombre'],'sku'=>$v['sku'],'descripcion'=>$p['descripcion']?:'','categoria'=>$p['categoria_nombre']?:'Sin categoría','unidad'=>$p['unidad_codigo'],'permite_decimales'=>(bool)$p['permite_decimales'],'controla_inventario'=>(bool)$p['controla_inventario'],'tipo'=>$p['tipo_producto'],'caracteristicas'=>$p['datos_extra'],'precio_unitario'=>$v['precio'],'precio_final_centavos'=>(int)round($price/100*($p['precio_incluye_impuesto']?1:1+$tax/10000)),'cotizacion'=>isset($quotes[$v['id']]),'imagenes'=>$p['imagenes'],'existencias'=>$stock);
        }}
        return array('productos'=>$items,'ubicaciones'=>$locations,'contexto'=>$this->conexion()['instalacion']);
    }
    public function configuracion($actorId)
    {
        $this->admin($actorId);$cfg=$this->conexion();unset($cfg['clave']);
        $q=$this->db->query('SELECT v.id,v.sku,v.nombre variante,p.nombre producto,COALESCE(c.cotizacion,0) cotizacion,COALESCE(cat.nombre,\'Sin categoría\') categoria FROM variantes_producto v JOIN productos p ON p.id=v.producto_id LEFT JOIN catalogo_pedidos c ON c.variante_id=v.id LEFT JOIN categorias cat ON cat.id=p.categoria_id WHERE v.activo=1 AND p.activo=1 ORDER BY p.nombre,v.nombre LIMIT 1001');$cfg['productos']=$q->fetchAll();$cfg['portal_ruta']='/PuntoVenta/public/pedidos-clientes/';
        if(count($cfg['productos'])>1000)throw new HttpError('El portal admite hasta 1000 variantes activas en esta versión.',409);
        return $cfg;
    }
    public function configurar(array $d,$actorId)
    {
        $this->admin($actorId);$url=isset($d['url'])?PortalPedidos::url($d['url']):$this->conexion()['url'];$mode=isset($d['modo'])?$d['modo']:$this->conexion()['modo'];if(!in_array($mode,array('php','remoto'),true))throw new HttpError('Modo de portal inválido.');$key=isset($d['clave'])?$d['clave']:'';
        if(!is_string($key)||($key!==''&&!preg_match('/^[A-Za-z0-9_-]{32,128}$/D',$key)))throw new HttpError('La clave debe tener entre 32 y 128 letras, números, guiones o guiones bajos.');
        $quotes=isset($d['cotizaciones'])?$d['cotizaciones']:array();
        if(!is_array($quotes)||count($quotes)>1000)throw new HttpError('Revisa los productos para cotización.');
        $ids=array_values(array_unique(array_map(array('Producto','identificador'),$quotes)));
        $this->db->beginTransaction();try{
            $this->admin($actorId);$cfg=$this->db->query('SELECT * FROM conexion_pedidos WHERE id=1 FOR UPDATE')->fetch();
            if($cfg['url']!==''&&$cfg['url']!==$url&&(int)$this->db->query('SELECT COUNT(*) FROM pedidos')->fetchColumn()>0)throw new HttpError('Esta conexión ya tiene pedidos. Para migrar a otro portal conserva sus datos y solicita asistencia.',409);
            if($ids){$q=$this->db->prepare('SELECT v.id FROM variantes_producto v JOIN productos p ON p.id=v.producto_id WHERE v.activo=1 AND p.activo=1 AND v.id IN ('.implode(',',array_fill(0,count($ids),'?')).')');$q->execute($ids);if(count($q->fetchAll())!==count($ids))throw new HttpError('Alguna variante ya no está activa. Actualiza el catálogo.',409);}
            $this->db->prepare('UPDATE conexion_pedidos SET url=?,clave=?,modo=? WHERE id=1')->execute(array($url,$key===''?$cfg['clave']:$key,$mode));
            $this->db->exec('DELETE FROM catalogo_pedidos');$q=$this->db->prepare('INSERT INTO catalogo_pedidos(variante_id,cotizacion) VALUES(?,1)');foreach($ids as $id)$q->execute(array($id));
            Auditoria::registrar($this->db,$actorId,'portal_configurado','Portal de pedidos','Se configuró la conexión y los productos para cotizar',null,array('url'=>$url,'cotizaciones'=>$ids));
            $this->db->commit();return $this->configuracion($actorId);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function imagen($ruta)
    {
        if(!is_string($ruta)||!preg_match('#^uploads/(logos|productos)/[a-f0-9]{32}\.(png|jpg|webp)$#D',$ruta))return null;
        $file=dirname(__DIR__,2).'/storage/'.$ruta;if(!is_readable($file)||filesize($file)>2097152)return null;
        $raw=file_get_contents($file);$i=@getimagesizefromstring($raw);if(!$i)return null;
        if(strlen($raw)>80000&&function_exists('imagecreatefromstring')){$source=@imagecreatefromstring($raw);if(!$source)return null;$ratio=min(1,960/max($i[0],$i[1]));$scaled=imagecreatetruecolor(max(1,(int)round($i[0]*$ratio)),max(1,(int)round($i[1]*$ratio)));imagefill($scaled,0,0,imagecolorallocate($scaled,255,255,255));imagecopyresampled($scaled,$source,0,0,0,0,imagesx($scaled),imagesy($scaled),$i[0],$i[1]);ob_start();imagejpeg($scaled,null,70);$raw=ob_get_clean();imagedestroy($source);imagedestroy($scaled);$i['mime']='image/jpeg';}
        return strlen($raw)<=85000?'data:'.$i['mime'].';base64,'.base64_encode($raw):null;
    }
    public function catalogo($actorId){$this->admin($actorId);return $this->datosCatalogo(false);}
    public function catalogoClientes(){ $data=$this->datosCatalogo(true);unset($data['installation']);$data['version']=hash('sha256',json_encode($data));$data['updated']=time();return $data; }
    private function datosCatalogo($public=false)
    {
        $cfg=$this->conexion();
        $rows=$this->db->query('SELECT v.id,v.sku,v.nombre variante,v.precio,p.id producto_id,p.tipo_producto,p.datos_extra,p.nombre producto,p.descripcion,p.precio_incluye_impuesto,i.tasa,u.codigo unidad,u.permite_decimales,ip.ruta,COALESCE(c.cotizacion,0) cotizacion,COALESCE(cat.nombre,\'Sin categoría\') categoria FROM variantes_producto v JOIN productos p ON p.id=v.producto_id LEFT JOIN catalogo_pedidos c ON c.variante_id=v.id JOIN impuestos i ON i.id=p.impuesto_id JOIN unidades u ON u.id=p.unidad_base_id LEFT JOIN categorias cat ON cat.id=p.categoria_id LEFT JOIN imagenes_producto ip ON ip.producto_id=p.id AND ip.es_principal=1 WHERE v.activo=1 AND p.activo=1 AND i.activo=1 ORDER BY v.id LIMIT 1001')->fetchAll();
        if(count($rows)>1000)throw new HttpError('El catálogo supera las 1000 variantes admitidas. Solicita ampliar su capacidad.',409);
        $photos=array();$photoQuery=$this->db->query('SELECT f.id,f.producto_id,f.ruta FROM imagenes_producto f JOIN productos p ON p.id=f.producto_id WHERE p.activo=1 ORDER BY f.producto_id,f.es_principal DESC,f.orden,f.id');foreach($photoQuery->fetchAll() as $photo){$id=$photo['producto_id'];if(!isset($photos[$id]))$photos[$id]=array();if(count($photos[$id])<10){$image=$public?'/PuntoVenta/public/pedidos-clientes/imagenes/'.$photo['id']:$this->imagen($photo['ruta']);if($image)$photos[$id][]=$image;}}
        $items=array();foreach($rows as $r){$price=Inventario::unidades($r['precio']);$tax=Inventario::unidades($r['tasa']);$cents=(int)round($price/100*($r['precio_incluye_impuesto']?1:1+$tax/10000));$quote=(bool)$r['cotizacion'];$items[]=array('id'=>(string)$r['id'],'sku'=>$r['sku'],'name'=>$r['producto'],'variant'=>$r['variante'],'description'=>$r['descripcion']?:'','category'=>$r['categoria'],'type'=>$r['tipo_producto'],'attributes'=>$r['datos_extra']?json_decode($r['datos_extra'],true):array(),'unit'=>$r['unidad'],'decimal'=>(bool)$r['permite_decimales'],'quote_only'=>$quote,'price_cents'=>$quote?null:$cents,'images'=>isset($photos[$r['producto_id']])?$photos[$r['producto_id']]:array());}
        $e=$this->db->query('SELECT nombre_comercial,color_primario,color_secundario,color_acento,logo_ruta FROM empresa WHERE id=1')->fetch();
        return array('installation'=>$cfg['instalacion'],'name'=>$e['nombre_comercial'],'primary'=>$e['color_primario'],'secondary'=>$e['color_secundario'],'accent'=>$e['color_acento'],'logo'=>$public?($e['logo_ruta']?'/PuntoVenta/public/pedidos-clientes/logo?v='.hash('sha256',$e['logo_ruta']):null):$this->imagen($e['logo_ruta']),'products'=>$items);
    }
    public function publicar($actorId){$cfg=$this->conexion();if($cfg['modo']==='php'){$this->admin($actorId);$catalog=$this->catalogoClientes();return array('products'=>count($catalog['products']),'version'=>$catalog['version'],'integrado'=>true);}$data=$this->catalogo($actorId);$sent=array();foreach($data['products'] as &$p){foreach($p['images'] as &$image){$hash=hash('sha256',$image);if(!isset($sent[$hash])){PortalPedidos::request($cfg,'/bridge/media','POST',array('hash'=>$hash,'data'=>$image));$sent[$hash]=true;}$image='/media/'.$hash;}unset($image);}unset($p);return PortalPedidos::request($cfg,'/bridge/catalog','PUT',$data);}

    private function fila($id,$lock=false)
    {
        $q=$this->db->prepare('SELECT p.*,b.estado borrador_estado,b.usuario_id borrador_usuario,b.turno_id borrador_turno,v.folio venta_folio,v.total venta_total FROM pedidos p LEFT JOIN borradores_venta b ON b.id=p.borrador_id LEFT JOIN ventas v ON v.id=p.venta_id WHERE p.id=?'.($lock?' FOR UPDATE':''));$q->execute(array(Producto::identificador($id)));$r=$q->fetch();if(!$r)throw new HttpError('Pedido no encontrado.',404);$r['contenido']=json_decode($r['contenido'],true);return $r;
    }
    public function listar(array $f,$actorId)
    {
        $this->actor($actorId);$where='1=1';$args=array();
        if(!empty($f['estado'])){if(!in_array($f['estado'],array('nuevo','confirmado','listo','en_venta','vendido','cancelado'),true))throw new HttpError('Estado no válido.');$where.=' AND p.estado=?';$args[]=$f['estado'];}
        $search=Producto::texto($f,'buscar',100,false);if($search!==''){$where.=" AND (p.folio LIKE ? OR p.nombre LIKE ? OR p.celular LIKE ?)";array_push($args,'%'.$search.'%','%'.$search.'%','%'.$search.'%');}
        if(!empty($f['antes_de'])){$where.=' AND p.id<?';$args[]=Producto::identificador($f['antes_de']);}
        $q=$this->db->prepare('SELECT p.id,p.folio,p.origen,p.nombre,p.celular,p.total_estimado,JSON_EXTRACT(p.contenido,\'$.requires_quote\') requiere_cotizacion,p.estado,p.version,p.creado_unix,p.borrador_id,p.venta_id,b.estado borrador_estado,v.folio venta_folio,v.total venta_total FROM pedidos p LEFT JOIN borradores_venta b ON b.id=p.borrador_id LEFT JOIN ventas v ON v.id=p.venta_id WHERE '.$where.' ORDER BY p.id DESC LIMIT 31');$q->execute($args);$rows=$q->fetchAll();$more=count($rows)>30;if($more)array_pop($rows);
        $cfg=$this->conexion();return array('pedidos'=>$rows,'siguiente'=>$more?$rows[count($rows)-1]['id']:null,'ultima_sync'=>$cfg['ultima_sync'],'portal_url'=>$cfg['modo']==='php'?'/PuntoVenta/public/pedidos-clientes/':$cfg['url'],'modo'=>$cfg['modo']);
    }
    public function obtener($id,$actorId){$this->actor($actorId);return $this->fila($id);}
    public function estado($id,array $d,$actorId)
    {
        $this->db->beginTransaction();try{$this->actor($actorId,true);$r=$this->fila($id,true);$next=isset($d['estado'])?$d['estado']:null;
            if(!isset($d['version'])||(string)$r['version']!==(string)$d['version'])throw new HttpError('El pedido cambió. Actualízalo antes de continuar.',409);
            $allowed=array('nuevo'=>array('confirmado','cancelado'),'confirmado'=>array('listo','cancelado'),'listo'=>array('cancelado'),'en_venta'=>array('cancelado'));
            if(!isset($allowed[$r['estado']])||!in_array($next,$allowed[$r['estado']],true))throw new HttpError('Ese cambio de estado no está disponible.',409);
            if($r['borrador_id']&&$r['borrador_estado']!=='descartado')throw new HttpError('Primero resuelve o descarta el ticket asociado al pedido.',409);
            $reason=$next==='cancelado'?Producto::texto($d,'motivo',300,true):'';
            $this->db->prepare('UPDATE pedidos SET estado=?,motivo=?,version=version+1,usuario_id=? WHERE id=?')->execute(array($next,$reason,$actorId,$id));
            Auditoria::registrar($this->db,$actorId,'pedido_estado',$r['folio'],'Se actualizó el pedido',array('estado'=>$r['estado']),array('estado'=>$next),array('motivo'=>$reason));
            $this->db->commit();return $this->obtener($id,$actorId);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function sincronizar($actorId)
    {
        $this->actor($actorId,true);$cfg=$this->conexion();if($cfg['modo']==='php')return array('recibidos'=>0,'hay_mas'=>false,'integrado'=>true);$remote=PortalPedidos::request($cfg,'/bridge/orders?after='.(int)$cfg['cursor_remoto']);
        if(!isset($remote['orders'])||!is_array($remote['orders'])||count($remote['orders'])>100)throw new HttpError('El portal devolvió pedidos no válidos.',502);
        $this->db->beginTransaction();try{
            $this->actor($actorId,true);$current=$this->db->query('SELECT * FROM conexion_pedidos WHERE id=1 FOR UPDATE')->fetch();if($current['url']!==$cfg['url']||!hash_equals($current['clave'],$cfg['clave']))throw new HttpError('La conexión cambió. Sincroniza de nuevo.',409);
            $count=0;$cursor=(int)$cfg['cursor_remoto'];
            foreach($remote['orders'] as $o){
                if(!is_array($o)||!isset($o['id'],$o['seq'],$o['folio'],$o['name'],$o['phone'],$o['notes'],$o['items'],$o['total_cents'],$o['created'])||!is_int($o['seq'])||$o['seq']<1||!is_int($o['created'])||!is_int($o['total_cents'])||$o['total_cents']<0||$o['total_cents']>10000000000||!is_array($o['items'])||!count($o['items'])||count($o['items'])>100)throw new HttpError('Un pedido del portal no es válido.',502);
                BorradorVenta::uuid($o['id']);foreach(array('folio'=>30,'name'=>200,'phone'=>20,'notes'=>500) as $key=>$length)if(!is_string($o[$key])||preg_match('/^.{0,'.$length.'}$/usD',$o[$key])!==1)throw new HttpError('Los datos de contacto del pedido no son válidos.',502);
                if(!preg_match('/^[0-9+]{10,20}$/D',$o['phone']))throw new HttpError('El celular del pedido no es válido.',502);
                $seen=array();foreach($o['items'] as $line){$v=Producto::identificador(isset($line['id'])?$line['id']:null);$qty=Inventario::unidades(isset($line['quantity'])?$line['quantity']:null);if($qty<=0||isset($seen[$v]))throw new HttpError('Las cantidades del pedido no son válidas.',502);$seen[$v]=true;}
                $state=isset($o['status'])?$o['status']:'nuevo';if(!in_array($state,array('nuevo','confirmado','listo','en_venta','vendido','cancelado'),true))throw new HttpError('Estado remoto no válido.',502);if(isset($o['pos_version'])&&(!is_int($o['pos_version'])||$o['pos_version']<0))throw new HttpError('Versión remota no válida.',502);$version=isset($o['pos_version'])?max(1,$o['pos_version']):1;
                $q=$this->db->prepare('INSERT IGNORE INTO pedidos(remoto_id,secuencia,folio,nombre,celular,notas,contenido,total_estimado,creado_unix,estado,version,sincronizado_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$q->execute(array($o['id'],$o['seq'],$o['folio'],$o['name'],$o['phone'],$o['notes'],json_encode($o),Caja::dinero($o['total_cents']),$o['created'],$state,$version,isset($o['pos_version'])?$o['pos_version']:0));$count+=$q->rowCount();$cursor=max($cursor,$o['seq']);
            }
            $this->db->prepare('UPDATE conexion_pedidos SET cursor_remoto=GREATEST(cursor_remoto,?),ultima_sync=CURRENT_TIMESTAMP WHERE id=1')->execute(array($cursor));$this->db->commit();
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        $rows=$this->db->query("SELECT id,remoto_id,estado,version FROM pedidos WHERE origen='portal' AND version>sincronizado_version ORDER BY id LIMIT 100")->fetchAll();
        if($rows){$updates=array();foreach($rows as $r)$updates[]=array('id'=>$r['remoto_id'],'status'=>$r['estado'],'version'=>(int)$r['version']);PortalPedidos::request($cfg,'/bridge/status','POST',array('orders'=>$updates));$q=$this->db->prepare('UPDATE pedidos SET sincronizado_version=GREATEST(sincronizado_version,?) WHERE id=?');foreach($rows as $r)$q->execute(array($r['version'],$r['id']));}
        return array('recibidos'=>$count,'hay_mas'=>count($remote['orders'])===100);
    }

    public function preparar($id,array $d,$actorId)
    {
        $this->actor($actorId,true);$turn=Producto::identificador(isset($d['turno_id'])?$d['turno_id']:null);$old=$this->fila($id);
        $this->db->beginTransaction();try{
            $a=$this->actor($actorId,true);if(empty($a['permisos']['todo'])&&(empty($a['permisos']['vender'])||empty($a['permisos']['operar_caja'])))throw new HttpError('Necesitas permisos de venta y operación de caja.',403);
            $q=$this->db->prepare("SELECT t.id FROM turnos_caja t JOIN cajas c ON c.id=t.caja_id JOIN ubicaciones u ON u.id=c.ubicacion_id WHERE t.id=? AND t.abierto_por=? AND t.estatus='abierto' AND c.activo=1 AND u.activo=1 AND u.disponible_venta=1 FOR UPDATE");$q->execute(array($turn,$actorId));if(!$q->fetch())throw new HttpError('Abre tu propia caja para pasar el pedido a venta.',409);
            if($old['borrador_id']){$q=$this->db->prepare('SELECT id FROM borradores_venta WHERE id=? FOR UPDATE');$q->execute(array($old['borrador_id']));$q->fetch();}
            $r=$this->fila($id,true);if($r['borrador_id']!==$old['borrador_id'])throw new HttpError('Otro usuario tomó el pedido. Actualiza el listado.',409);
            if(in_array($r['estado'],array('cancelado','vendido'),true))throw new HttpError('Este pedido ya fue cancelado o vendido.',409);
            if($r['estado']==='en_venta'&&!$r['borrador_id'])throw new HttpError('El portal indica que este pedido ya estaba en venta. Concilia su ticket anterior antes de continuar.',409);
            if($r['borrador_id']&&$r['borrador_estado']!=='descartado'){
                if((string)$r['borrador_usuario']!==(string)$actorId||(string)$r['borrador_turno']!==(string)$turn)throw new HttpError('Este pedido ya está en un ticket de otra caja o usuario.',409);
                $this->db->commit();return array('borrador_id'=>$r['borrador_id'],'reutilizado'=>true);
            }
            $prices=isset($d['cotizaciones'])?$d['cotizaciones']:array();if(!is_array($prices)||count($prices)>100)throw new HttpError('Revisa los precios de cotización.');
            $lines=array();$quoted=array();foreach($r['contenido']['items'] as $l){$price=null;if(!empty($l['quote_only'])){if(!isset($prices[$l['id']])||Inventario::unidades($prices[$l['id']])<=0)throw new HttpError('Define un precio mayor que cero para cada producto que requiere cotización antes de pasarlo a venta.',409);$price=$prices[$l['id']];$quoted[$l['id']]=$price;}$lines[]=array('variante_id'=>$l['id'],'cantidad'=>$l['quantity'],'precio_unitario'=>$price,'descuento'=>'0.00');}
            preg_match('/^.{0,500}/us','Pedido '.$r['folio'].' · Cel. '.$r['celular'].' · '.$r['notas'],$note);$input=array('turno_id'=>$turn,'cliente_id'=>null,'cliente_nombre'=>$r['nombre'],'notas'=>$note[0],'detalles'=>$lines);
            // El ticket conserva los precios actuales; el POS cotiza y valida antes del cobro.
            $products=array();$q=$this->db->prepare('SELECT v.id,v.precio,p.nombre producto_nombre,v.nombre variante_nombre,v.sku,u.codigo unidad_codigo,u.permite_decimales,p.controla_inventario FROM variantes_producto v JOIN productos p ON p.id=v.producto_id JOIN unidades u ON u.id=p.unidad_base_id WHERE v.id=? AND v.activo=1 AND p.activo=1');
            foreach($input['detalles'] as &$l){$q->execute(array($l['variante_id']));$p=$q->fetch();if(!$p)throw new HttpError('Un producto ya no está disponible. Revisa el pedido antes de convertirlo.',409);if(!$p['permite_decimales']&&Inventario::unidades($l['cantidad'])%10000)throw new HttpError('La cantidad de un producto debe ser entera.',409);if($l['precio_unitario']===null)$l['precio_unitario']=$p['precio'];$p['existencia']='0';$products[]=$p;}unset($l);
            $draft=self::uuid();$input['pedido_id']=(string)$r['id'];$content=BorradorVenta::contenido(array('venta'=>$input,'productos'=>$products,'pagos'=>array(),'cliente_tocado'=>true));$json=json_encode($content,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $this->db->prepare("INSERT INTO borradores_venta(id,usuario_id,turno_id,estado,contenido,solicitud,huella) VALUES(?,?,?,'en_curso',?,?,?)")->execute(array($draft,$actorId,$turn,$json,self::uuid(),hash('sha256',$json.':en_curso')));
            $this->db->prepare("UPDATE pedidos SET estado='en_venta',borrador_id=?,usuario_id=?,version=version+1 WHERE id=?")->execute(array($draft,$actorId,$id));
            Auditoria::registrar($this->db,$actorId,'pedido_a_venta',$r['folio'],'Se preparó un ticket desde el pedido',null,array('borrador_id'=>$draft,'precios_cotizados'=>$quoted));
            $this->db->commit();return array('borrador_id'=>$draft,'reutilizado'=>false);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function uuid(){ $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }

    public static function finalizar(PDO $db,$draftId,$ventaId,$actorId)
    {
        if(!$draftId)return;
        $q=$db->prepare('SELECT id,estado FROM pedidos WHERE borrador_id=? FOR UPDATE');$q->execute(array($draftId));$p=$q->fetch();if(!$p)return;
        if($p['estado']!=='en_venta')throw new HttpError('El pedido ya no está disponible para cobrar.',409);
        $db->prepare("UPDATE pedidos SET estado='vendido',venta_id=?,usuario_id=?,version=version+1 WHERE id=?")->execute(array($ventaId,$actorId,$p['id']));
    }
}
