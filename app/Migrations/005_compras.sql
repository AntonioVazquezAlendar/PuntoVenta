CREATE TABLE IF NOT EXISTS solicitudes_compra (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    clave CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    compra_id BIGINT UNSIGNED NULL,
    UNIQUE KEY uq_solicitud_compra (usuario_id,clave),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (compra_id) REFERENCES compras(id)
) ENGINE=InnoDB COMMENT='Evita crear compras duplicadas al reintentar una solicitud';
