<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../Config/Database.php';$db=(new Database())->connect();
$db->exec("CREATE TABLE IF NOT EXISTS borradores_venta (
 id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,usuario_id BIGINT UNSIGNED NOT NULL,turno_id BIGINT UNSIGNED NOT NULL,
 estado ENUM('en_curso','espera','cobrando','cobrado','descartado') NOT NULL DEFAULT 'en_curso',version BIGINT UNSIGNED NOT NULL DEFAULT 1,
 contenido JSON NOT NULL,cobro JSON NULL,venta_id BIGINT UNSIGNED NULL,solicitud CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,huella CHAR(64) NOT NULL,
 creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_borrador_usuario(usuario_id,estado,actualizado_en,id),KEY ix_borrador_turno(turno_id,estado,id),
 FOREIGN KEY(usuario_id) REFERENCES usuarios(id),FOREIGN KEY(turno_id) REFERENCES turnos_caja(id),FOREIGN KEY(venta_id) REFERENCES ventas(id)
) ENGINE=InnoDB");echo "Guardado y recuperación de tickets preparados.\n";
