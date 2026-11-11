<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
foreach(array('detalle_venta'=>array('controla_inventario'=>'TINYINT NULL','permite_decimales'=>'TINYINT NULL'),
    'devoluciones_venta'=>array('tipo'=>"ENUM('devolucion','cancelacion') NOT NULL DEFAULT 'devolucion'",'datos'=>'JSON NULL'),
    'detalle_devolucion'=>array('ubicacion_id'=>'BIGINT UNSIGNED NULL','ubicacion_nombre'=>'VARCHAR(100) NULL','destino'=>"VARCHAR(20) NOT NULL DEFAULT 'venta'")) as $tabla=>$campos){
    $cols=$db->query('SHOW COLUMNS FROM '.$tabla)->fetchAll(PDO::FETCH_COLUMN);foreach($campos as $col=>$def)if(!in_array($col,$cols,true))$db->exec('ALTER TABLE '.$tabla.' ADD COLUMN '.$col.' '.$def);
}
$db->exec('CREATE TABLE IF NOT EXISTS pagos_devolucion (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    devolucion_id BIGINT UNSIGNED NOT NULL,
    pago_venta_id BIGINT UNSIGNED NOT NULL,
    monto DECIMAL(14,2) NOT NULL,
    tipo_metodo VARCHAR(20) NOT NULL,
    nombre_metodo VARCHAR(80) NOT NULL,
    referencia VARCHAR(100) NULL,
    FOREIGN KEY (devolucion_id) REFERENCES devoluciones_venta(id) ON DELETE CASCADE,
    FOREIGN KEY (pago_venta_id) REFERENCES pagos_venta(id),
    UNIQUE KEY uq_reembolso_pago (devolucion_id,pago_venta_id)
) ENGINE=InnoDB');
$db->exec('CREATE TABLE IF NOT EXISTS solicitudes_reembolso (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    clave CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    huella CHAR(64) NOT NULL,
    devolucion_id BIGINT UNSIGNED NULL,
    resultado JSON NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (devolucion_id) REFERENCES devoluciones_venta(id),
    UNIQUE KEY uq_solicitud_reembolso (usuario_id,clave)
) ENGINE=InnoDB');
echo "Cancelaciones y devoluciones preparadas.",PHP_EOL;
