<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$db->exec('CREATE TABLE IF NOT EXISTS minimos_inventario (
 ubicacion_id BIGINT UNSIGNED NOT NULL,variante_id BIGINT UNSIGNED NOT NULL,
 minimo DECIMAL(14,4) NULL,version BIGINT UNSIGNED NOT NULL DEFAULT 1,usuario_id BIGINT UNSIGNED NULL,
 actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(ubicacion_id,variante_id),KEY ix_minimo_variante(variante_id),
 FOREIGN KEY(ubicacion_id) REFERENCES ubicaciones(id) ON DELETE CASCADE,
 FOREIGN KEY(variante_id) REFERENCES variantes_producto(id) ON DELETE CASCADE,
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB');
echo "Mínimos por ubicación preparados. Los roles existentes conservan sus permisos.",PHP_EOL;
