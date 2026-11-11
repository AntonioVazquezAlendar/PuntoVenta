CREATE TABLE IF NOT EXISTS intentos_acceso (
    clave CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    inicio BIGINT UNSIGNED NOT NULL,
    intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB COMMENT='Límite temporal de intentos de acceso por dirección de origen';
