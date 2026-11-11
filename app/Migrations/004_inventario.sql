CREATE TABLE IF NOT EXISTS ajustes_inventario (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    clave CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    resultado JSON NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ajuste_solicitud (usuario_id, clave),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB COMMENT='Solicitudes de ajuste: evita aplicar dos veces un reintento de la misma operación';

-- Quienes ya tenían permiso de ajustar mantienen acceso a la nueva consulta.
UPDATE roles
SET permisos = JSON_SET(permisos, '$.ver_inventario', CAST('true' AS JSON))
WHERE JSON_CONTAINS_PATH(COALESCE(permisos, JSON_OBJECT()), 'one', '$.ver_inventario') = 0
  AND JSON_EXTRACT(permisos, '$.ajustar_inventario') = TRUE;
