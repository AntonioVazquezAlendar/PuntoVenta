<?php

class ProductoImagen
{
    private $db;
    private $nuevos=array();
    private $retirados=array();
    public function __construct(PDO $db){$this->db=$db;}
    public static function url($id){return '/PuntoVenta/public/api/productos/imagenes/'.$id;}
    private static function version(array $rows){return hash('sha256',json_encode(array_map(function($r){return array((string)$r['id'],(int)$r['es_principal'],(int)$r['orden']);},$rows)));}
    public function adjuntar(array $productos)
    {
        if(!$productos)return $productos;
        $q=$this->db->prepare('SELECT id,producto_id,nombre_original,es_principal,orden FROM imagenes_producto WHERE producto_id IN ('.implode(',',array_fill(0,count($productos),'?')).') ORDER BY producto_id,orden,id');$q->execute(array_column($productos,'id'));$grupos=array();
        foreach($q->fetchAll() as $row){$grupos[$row['producto_id']][]=$row;}
        foreach($productos as &$p){$rows=isset($grupos[$p['id']])?$grupos[$p['id']]:array();$p['imagenes_version']=self::version($rows);$p['imagen_url']=null;$p['imagenes']=array();
            foreach($rows as $r){$foto=array('id'=>$r['id'],'nombre'=>$r['nombre_original'],'principal'=>(bool)$r['es_principal'],'url'=>self::url($r['id']));$p['imagenes'][]=$foto;if($foto['principal'])$p['imagen_url']=$foto['url'];}
        }unset($p);return $productos;
    }
    public function guardar($productoId,array $datos,array $archivos)
    {
        if(!is_array($datos['imagenes'])||count($datos['imagenes'])>10){throw new HttpError('Puedes guardar hasta 10 imágenes por producto.');}
        $q=$this->db->prepare('SELECT id,producto_id,ruta,nombre_original,es_principal,orden FROM imagenes_producto WHERE producto_id=? ORDER BY orden,id FOR UPDATE');$q->execute(array($productoId));$actual=$q->fetchAll();
        if(!isset($datos['imagenes_version'])||!is_string($datos['imagenes_version'])||!hash_equals(self::version($actual),$datos['imagenes_version'])){throw new HttpError('Las imágenes cambiaron. Vuelve a abrir el producto antes de guardar.',409);}
        $existentes=array_column($actual,null,'id');$seleccion=array();$usados=array();$total=0;
        $files=isset($archivos['imagenes'])?$archivos['imagenes']:array();
        foreach($datos['imagenes'] as $foto){
            if(!is_array($foto)){throw new HttpError('Revisa las imágenes del producto.');}
            if(isset($foto['id'])){$id=Producto::identificador($foto['id']);if(!isset($existentes[$id])){throw new HttpError('Una imagen no pertenece a este producto.',403);}$clave='id:'.$id;$r=$existentes[$id];}
            else{
                $archivo=isset($foto['archivo'])?$foto['archivo']:null;
                if(!is_string($archivo)||!preg_match('/^[a-f0-9]{32}$/D',$archivo)||!isset($files['error'][$archivo])||is_array($files['error'][$archivo])){throw new HttpError('No se recibió una de las imágenes. Vuelve a seleccionarla.');}
                $clave='nuevo:'.$archivo;
                if((int)$files['error'][$archivo]!==UPLOAD_ERR_OK){throw new HttpError('No se pudo cargar una imagen. Usa archivos de hasta 2 MB.');}
                $tmp=$files['tmp_name'][$archivo];$bytes=is_string($tmp)&&is_uploaded_file($tmp)?filesize($tmp):false;
                if(!$bytes||$bytes>2097152){throw new HttpError('Cada imagen debe pesar como máximo 2 MB.');}$total+=$bytes;
                if($total>6291456){throw new HttpError('Sube hasta 6 MB de imágenes nuevas por guardado.');}
                $size=@getimagesize($tmp);$mime=$size&&isset($size['mime'])?$size['mime']:'';$formatos=array('image/png'=>'png','image/jpeg'=>'jpg','image/webp'=>'webp');
                if(class_exists('finfo')){$mime=(new finfo(FILEINFO_MIME_TYPE))->file($tmp);}
                if(!$size||!isset($formatos[$mime])||$size['mime']!==$mime||$size[0]<1||$size[1]<1||$size[0]>4096||$size[1]>4096){throw new HttpError('Usa imágenes JPG, PNG o WebP de hasta 4096 × 4096 píxeles.');}
                $nombre=basename(str_replace('\\','/',(string)$files['name'][$archivo]));if(!preg_match('/^.{1,150}/us',$nombre,$m)){$m=array('Imagen');}
                $r=array('tmp'=>$tmp,'ruta'=>'uploads/productos/'.bin2hex(random_bytes(16)).'.'.$formatos[$mime],'nombre_original'=>$m[0]);
            }
            if(isset($usados[$clave])){throw new HttpError('Una imagen está repetida.');}$usados[$clave]=true;$r['clave']=$clave;$seleccion[]=$r;
        }
        if(isset($files['error'])&&(!is_array($files['error'])||count($files['error'])!==count(array_filter($seleccion,function($r){return isset($r['tmp']);})))){throw new HttpError('Revisa los archivos seleccionados.');}
        $principal=isset($datos['imagen_principal'])?$datos['imagen_principal']:null;
        if($seleccion&&($principal===null||$principal===''))$principal=$seleccion[0]['clave'];
        if($seleccion&&(!is_string($principal)||!isset($usados[$principal]))){throw new HttpError('Selecciona una imagen predeterminada de la galería.');}
        $this->db->prepare('UPDATE imagenes_producto SET es_principal=0 WHERE producto_id=?')->execute(array($productoId));$mantener=array();
        foreach($seleccion as $orden=>$r){
            if(isset($r['tmp'])){
                $dir=__DIR__.'/../../storage/uploads/productos';if(!is_dir($dir)&&!mkdir($dir,0755,true)&&!is_dir($dir)){throw new RuntimeException('No se pudo crear el directorio de imágenes.');}
                $path=__DIR__.'/../../storage/'.$r['ruta'];if(!move_uploaded_file($r['tmp'],$path)){throw new RuntimeException('No se pudo guardar la imagen.');}$this->nuevos[]=$path;
                $this->db->prepare('INSERT INTO imagenes_producto (producto_id,ruta,nombre_original,es_principal,orden) VALUES (?,?,?,?,?)')->execute(array($productoId,$r['ruta'],$r['nombre_original'],$r['clave']===$principal?1:0,$orden));$mantener[]=$this->db->lastInsertId();
            }else{$this->db->prepare('UPDATE imagenes_producto SET es_principal=?,orden=? WHERE id=? AND producto_id=?')->execute(array($r['clave']===$principal?1:0,$orden,$r['id'],$productoId));$mantener[]=$r['id'];}
        }
        foreach($actual as $r){if(!in_array($r['id'],$mantener,true)){$this->db->prepare('DELETE FROM imagenes_producto WHERE id=? AND producto_id=?')->execute(array($r['id'],$productoId));$this->retirados[]=$r['ruta'];}}
    }
    public function confirmar(){foreach($this->retirados as $ruta){if(preg_match('#^uploads/productos/[a-f0-9]{32}\.(png|jpg|webp)$#D',$ruta))@unlink(__DIR__.'/../../storage/'.$ruta);}$this->nuevos=array();}
    public function cancelar(){foreach($this->nuevos as $path)@unlink($path);$this->nuevos=array();}
    public function mostrar($id,array $actor)
    {
        $q=$this->db->prepare('SELECT f.ruta,p.activo FROM imagenes_producto f JOIN productos p ON p.id=f.producto_id WHERE f.id=?');$q->execute(array($id));$r=$q->fetch();
        if(!$r||(!$r['activo']&&empty($actor['permisos']['todo'])&&empty($actor['permisos']['editar_productos']))||!preg_match('#^uploads/productos/[a-f0-9]{32}\.(png|jpg|webp)$#D',$r['ruta'],$m)){throw new HttpError('Imagen no encontrada.',404);}
        $path=__DIR__.'/../../storage/'.$r['ruta'];if(!is_file($path)){throw new HttpError('Imagen no encontrada.',404);}
        $types=array('png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp');header('Content-Type: '.$types[$m[1]]);header('Content-Length: '.filesize($path));readfile($path);exit;
    }
}
