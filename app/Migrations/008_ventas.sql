CREATE TABLE IF NOT EXISTS solicitudes_venta (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    clave CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    venta_id BIGINT UNSIGNED NULL,
    resultado JSON NULL,
    UNIQUE KEY uq_solicitud_venta (usuario_id,clave),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (venta_id) REFERENCES ventas(id)
) ENGINE=InnoDB COMMENT='Evita cobrar o descontar inventario dos veces al reintentar una venta';
