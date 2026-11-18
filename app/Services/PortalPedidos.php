<?php
class PortalPedidos
{
    public static function url($value)
    {
        if (!is_string($value) || strlen($value)>300) throw new HttpError('Revisa la dirección del portal.');
        $value=rtrim(trim($value),'/'); $p=parse_url($value);
        if (!$p || !isset($p['scheme'],$p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (!empty($p['path']) && $p['path']!=='/')) throw new HttpError('Usa la dirección raíz del portal, sin rutas ni credenciales.');
        $local=in_array(strtolower($p['host']),array('localhost','127.0.0.1','[::1]'),true);
        if ($p['scheme']!=='https' && !($local && $p['scheme']==='http')) throw new HttpError('El portal público debe usar HTTPS. HTTP solo está permitido en localhost para pruebas.');
        return $value;
    }

    public static function request(array $cfg,$path,$method='GET',$data=null)
    {
        if (!$cfg['url']) throw new HttpError('Configura primero la conexión con el portal.',409);
        $url=self::url($cfg['url']).$path;
        $body=$data===null?'':json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($body===false || strlen($body)>2097152) throw new HttpError('El catálogo supera 2 MB. Publica menos productos o imágenes más pequeñas.',413);
        $context=stream_context_create(array('http'=>array('method'=>$method,'header'=>"Accept: application/json\r\nContent-Type: application/json\r\nAuthorization: Bearer ".$cfg['clave']."\r\n",'content'=>$body,'timeout'=>12,'ignore_errors'=>true,'follow_location'=>0),'ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true)));
        $raw=@file_get_contents($url,false,$context,0,4194305);
        if ($raw===false || strlen($raw)>4194304) throw new HttpError('No se pudo contactar al portal. Los pedidos locales se conservan; revisa la conexión y reintenta.',503);
        $status=0;foreach(isset($http_response_header)?$http_response_header:array() as $h)if(preg_match('#^HTTP/\S+ (\d+)#',$h,$m))$status=(int)$m[1];
        $result=json_decode($raw,true);
        if ($status<200 || $status>=300 || !is_array($result) || empty($result['ok'])) throw new HttpError($status===401?'El portal rechazó la clave de conexión. Revisa la configuración.':'El portal no pudo completar la sincronización. Revisa su dirección y disponibilidad.',502);
        return $result['data'];
    }
}
