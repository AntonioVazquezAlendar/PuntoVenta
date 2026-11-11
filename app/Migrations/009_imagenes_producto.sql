CREATE TABLE IF NOT EXISTS imagenes_producto (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    producto_id BIGINT UNSIGNED NOT NULL,
    ruta VARCHAR(100) NOT NULL,
    nombre_original VARCHAR(150) NOT NULL,
    es_principal TINYINT(1) NOT NULL DEFAULT 0,
    orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    principal_slot TINYINT GENERATED ALWAYS AS (IF(es_principal=1,1,NULL)) STORED,
    UNIQUE KEY uq_imagen_principal (producto_id,principal_slot),
    KEY idx_imagen_producto (producto_id,orden,id),
    FOREIGN KEY (producto_id) REFERENCES productos(id) ON DELETE CASCADE
) ENGINE=InnoDB;
