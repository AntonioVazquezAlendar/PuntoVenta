CREATE TABLE IF NOT EXISTS solicitudes_caja (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id BIGINT UNSIGNED NOT NULL,
    clave CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    accion ENUM('abrir','movimiento','cerrar') NOT NULL,
    huella CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    turno_id BIGINT UNSIGNED NULL,
    resultado JSON NULL,
    UNIQUE KEY uq_solicitud_caja (usuario_id,clave),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    FOREIGN KEY (turno_id) REFERENCES turnos_caja(id)
) ENGINE=InnoDB COMMENT='Evita duplicar aperturas, movimientos de efectivo y cierres por reintentos';
