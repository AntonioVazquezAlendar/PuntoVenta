CREATE TABLE IF NOT EXISTS empresa (
    id TINYINT UNSIGNED NOT NULL DEFAULT 1 PRIMARY KEY,
    nombre_comercial VARCHAR(150) NOT NULL,
    razon_social VARCHAR(200) NULL,
    rfc VARCHAR(13) NULL,
    telefono VARCHAR(30) NULL,
    correo VARCHAR(150) NULL,
    sitio_web VARCHAR(200) NULL,
    direccion VARCHAR(300) NULL,
    codigo_postal CHAR(5) NULL,
    logo_ruta VARCHAR(500) NULL COMMENT 'Ruta relativa en storage, nunca una URL externa',
    color_primario CHAR(7) NOT NULL DEFAULT '#176549',
    color_secundario CHAR(7) NOT NULL DEFAULT '#FFFFFF',
    color_acento CHAR(7) NOT NULL DEFAULT '#AA5A17',
    encabezado_ticket VARCHAR(300) NULL,
    pie_ticket VARCHAR(300) NULL,
    moneda CHAR(3) NOT NULL DEFAULT 'MXN',
    zona_horaria VARCHAR(50) NOT NULL DEFAULT 'America/Mexico_City',
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT ck_empresa_unica CHECK (id = 1),
    CONSTRAINT ck_color_primario CHECK (color_primario REGEXP '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT ck_color_secundario CHECK (color_secundario REGEXP '^#[0-9A-Fa-f]{6}$'),
    CONSTRAINT ck_color_acento CHECK (color_acento REGEXP '^#[0-9A-Fa-f]{6}$')
) ENGINE=InnoDB COMMENT='Datos y colores del negocio; una sola configuración';

INSERT INTO empresa (id, nombre_comercial, pie_ticket)
SELECT 1, 'PuntoVenta', 'Gracias por su compra'
WHERE NOT EXISTS (SELECT 1 FROM empresa WHERE id = 1);
