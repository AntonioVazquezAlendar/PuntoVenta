-- DATOS FICTICIOS. MySQL 8. Esquema completo de PuntoVenta.
-- No elimina ni reemplaza bases existentes. Todas las cuentas: Admin1.1.
SET NAMES utf8mb4;
CREATE DATABASE `pos_demo_ropa` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
USE `pos_demo_ropa`;
SET FOREIGN_KEY_CHECKS=0;
CREATE TABLE `abonos_cliente` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL,
  `venta_id` bigint(20) unsigned NOT NULL,
  `cliente_id` bigint(20) unsigned NOT NULL,
  `turno_id` bigint(20) unsigned NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `metodo_pago_id` bigint(20) unsigned NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  `recibido` decimal(14,2) DEFAULT NULL,
  `cambio` decimal(14,2) NOT NULL DEFAULT '0.00',
  `referencia` varchar(100) DEFAULT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) NOT NULL,
  `datos` json NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  UNIQUE KEY `uq_abono_solicitud` (`usuario_id`,`clave`),
  KEY `venta_id` (`venta_id`),
  KEY `cliente_id` (`cliente_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  KEY `ix_abono_turno` (`turno_id`),
  KEY `ix_abono_fecha` (`creado_en`),
  CONSTRAINT `abonos_cliente_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `abonos_cliente_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `abonos_cliente_ibfk_3` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `abonos_cliente_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `abonos_cliente_ibfk_5` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `ajustes_inventario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `resultado` json DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ajuste_solicitud` (`usuario_id`,`clave`),
  CONSTRAINT `ajustes_inventario_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Solicitudes de ajuste: evita aplicar dos veces un reintento de la misma operaciÃ³n';
CREATE TABLE `bitacora_actividad` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned DEFAULT NULL,
  `usuario_nombre` varchar(150) NOT NULL,
  `usuario_login` varchar(60) DEFAULT NULL,
  `tipo` varchar(40) NOT NULL,
  `referencia` varchar(100) NOT NULL,
  `resumen` varchar(500) NOT NULL,
  `detalle` json NOT NULL,
  `origen` varchar(20) NOT NULL DEFAULT 'cambio',
  `origen_id` bigint(20) unsigned DEFAULT NULL,
  `historico` tinyint(1) NOT NULL DEFAULT '0',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bitacora_origen` (`origen`,`origen_id`),
  KEY `ix_bitacora_fecha` (`creado_en`,`id`),
  KEY `ix_bitacora_usuario` (`usuario_id`,`creado_en`,`id`),
  KEY `ix_bitacora_tipo` (`tipo`,`creado_en`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `borradores_venta` (
  `id` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `turno_id` bigint(20) unsigned NOT NULL,
  `estado` enum('en_curso','espera','cobrando','cobrado','descartado') NOT NULL DEFAULT 'en_curso',
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `contenido` json NOT NULL,
  `cobro` json DEFAULT NULL,
  `venta_id` bigint(20) unsigned DEFAULT NULL,
  `solicitud` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_borrador_usuario` (`usuario_id`,`estado`,`actualizado_en`,`id`),
  KEY `ix_borrador_turno` (`turno_id`,`estado`,`id`),
  KEY `venta_id` (`venta_id`),
  CONSTRAINT `borradores_venta_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `borradores_venta_ibfk_2` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `borradores_venta_ibfk_3` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `cajas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ubicacion_id` bigint(20) unsigned NOT NULL,
  `nombre` varchar(100) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`),
  KEY `ubicacion_id` (`ubicacion_id`),
  CONSTRAINT `cajas_ibfk_1` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Cajas registradoras fÃ­sicas';
CREATE TABLE `categorias` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `categoria_padre_id` bigint(20) unsigned DEFAULT NULL COMMENT 'CategorÃ­a superior; NULL si es principal',
  `nombre` varchar(120) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = visible, 0 = oculta (no se borra)',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categoria_nombre` (`categoria_padre_id`,`nombre`),
  CONSTRAINT `categorias_ibfk_1` FOREIGN KEY (`categoria_padre_id`) REFERENCES `categorias` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='CategorÃ­as de productos, con subcategorÃ­as';
CREATE TABLE `categorias_gasto` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `activo` tinyint(4) NOT NULL DEFAULT '1',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `clientes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `limite_credito` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT '0 = sin crÃ©dito',
  `saldo` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Lo que debe ahora; 0 = al corriente',
  `notas` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_clientes_nombre` (`nombre`),
  KEY `idx_clientes_telefono` (`telefono`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Clientes registrados (las ventas de mostrador no necesitan cliente)';
CREATE TABLE `codigos_barras` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `variante_id` bigint(20) unsigned NOT NULL,
  `codigo` varchar(64) NOT NULL COMMENT 'Texto, para no perder ceros a la izquierda',
  `unidad_id` bigint(20) unsigned DEFAULT NULL COMMENT 'PresentaciÃ³n: pieza, paquete, caja',
  `cantidad` decimal(14,4) NOT NULL DEFAULT '1.0000' COMMENT 'Unidades base que equivale (caja de 12 = 12)',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`),
  KEY `variante_id` (`variante_id`),
  KEY `unidad_id` (`unidad_id`),
  CONSTRAINT `codigos_barras_ibfk_1` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`) ON DELETE CASCADE,
  CONSTRAINT `codigos_barras_ibfk_2` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='CÃ³digos de barras; una variante puede tener varios';
CREATE TABLE `compras` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL,
  `proveedor_id` bigint(20) unsigned NOT NULL,
  `ubicacion_id` bigint(20) unsigned NOT NULL COMMENT 'DÃ³nde entra la mercancÃ­a',
  `estatus` enum('borrador','pedida','recibida','cancelada') NOT NULL DEFAULT 'borrador' COMMENT 'El inventario solo se mueve al pasar a recibida',
  `factura_proveedor` varchar(60) DEFAULT NULL,
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `impuesto_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `notas` varchar(500) DEFAULT NULL,
  `creado_por` bigint(20) unsigned NOT NULL,
  `recibido_por` bigint(20) unsigned DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recibido_en` timestamp NULL DEFAULT NULL,
  `condicion_pago` enum('sin_definir','contado','credito') NOT NULL DEFAULT 'sin_definir',
  `vence` date DEFAULT NULL,
  `saldo_base` decimal(14,2) DEFAULT NULL,
  `conciliacion` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `idx_compras_proveedor` (`proveedor_id`,`creado_en`),
  KEY `idx_compras_estatus` (`estatus`),
  KEY `ubicacion_id` (`ubicacion_id`),
  KEY `creado_por` (`creado_por`),
  KEY `recibido_por` (`recibido_por`),
  CONSTRAINT `compras_ibfk_1` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`),
  CONSTRAINT `compras_ibfk_2` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `compras_ibfk_3` FOREIGN KEY (`creado_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `compras_ibfk_4` FOREIGN KEY (`recibido_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Encabezado de compras a proveedores';
CREATE TABLE `configuracion_clientes` (
  `id` tinyint(3) unsigned NOT NULL,
  `cliente_predeterminado_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cliente_predeterminado_id` (`cliente_predeterminado_id`),
  CONSTRAINT `configuracion_clientes_ibfk_1` FOREIGN KEY (`cliente_predeterminado_id`) REFERENCES `clientes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `configuracion_credito_cliente` (
  `cliente_id` bigint(20) unsigned NOT NULL,
  `habilitado` tinyint(4) NOT NULL DEFAULT '0',
  `limite` decimal(14,2) NOT NULL DEFAULT '0.00',
  `dias` smallint(5) unsigned NOT NULL DEFAULT '30',
  PRIMARY KEY (`cliente_id`),
  CONSTRAINT `configuracion_credito_cliente_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `creditos_venta` (
  `venta_id` bigint(20) unsigned NOT NULL,
  `cliente_id` bigint(20) unsigned NOT NULL,
  `monto_original` decimal(14,2) NOT NULL,
  `vence` date NOT NULL,
  PRIMARY KEY (`venta_id`),
  KEY `ix_credito_cliente` (`cliente_id`),
  CONSTRAINT `creditos_venta_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `creditos_venta_ibfk_2` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `detalle_compra` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `compra_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned NOT NULL,
  `cantidad` decimal(14,4) NOT NULL COMMENT 'En unidades base',
  `costo_unitario` decimal(14,4) NOT NULL COMMENT 'Sin IVA',
  `impuesto_id` bigint(20) unsigned NOT NULL,
  `tasa_impuesto` decimal(6,4) NOT NULL,
  `subtotal_linea` decimal(14,2) NOT NULL,
  `impuesto_linea` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_linea` decimal(14,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_detcompra_compra` (`compra_id`),
  KEY `idx_detcompra_variante` (`variante_id`),
  KEY `impuesto_id` (`impuesto_id`),
  CONSTRAINT `detalle_compra_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_compra_ibfk_2` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`),
  CONSTRAINT `detalle_compra_ibfk_3` FOREIGN KEY (`impuesto_id`) REFERENCES `impuestos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Renglones de cada compra';
CREATE TABLE `detalle_devolucion` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `devolucion_id` bigint(20) unsigned NOT NULL,
  `detalle_venta_id` bigint(20) unsigned NOT NULL COMMENT 'RenglÃ³n original que se devuelve',
  `cantidad` decimal(14,4) NOT NULL,
  `regresa_inventario` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 si el producto llegÃ³ daÃ±ado',
  `subtotal_linea` decimal(14,2) NOT NULL,
  `impuesto_linea` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_linea` decimal(14,2) NOT NULL,
  `ubicacion_id` bigint(20) unsigned DEFAULT NULL,
  `destino` varchar(20) NOT NULL DEFAULT 'venta',
  `ubicacion_nombre` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_detdev_detventa` (`detalle_venta_id`),
  KEY `devolucion_id` (`devolucion_id`),
  CONSTRAINT `detalle_devolucion_ibfk_1` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones_venta` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_devolucion_ibfk_2` FOREIGN KEY (`detalle_venta_id`) REFERENCES `detalle_venta` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Renglones devueltos de cada devoluciÃ³n';
CREATE TABLE `detalle_venta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `venta_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned NOT NULL,
  `descripcion` varchar(250) NOT NULL COMMENT 'Nombre al momento de vender',
  `cantidad` decimal(14,4) NOT NULL COMMENT 'En unidades base',
  `unidad_id` bigint(20) unsigned DEFAULT NULL,
  `precio_unitario` decimal(14,4) NOT NULL COMMENT 'Precio cobrado',
  `costo_unitario` decimal(14,4) NOT NULL DEFAULT '0.0000' COMMENT 'Costo al vender; sirve para la utilidad',
  `descuento` decimal(14,2) NOT NULL DEFAULT '0.00',
  `precio_incluye_impuesto` tinyint(1) NOT NULL DEFAULT '1',
  `impuesto_id` bigint(20) unsigned NOT NULL,
  `tasa_impuesto` decimal(6,4) NOT NULL COMMENT 'Tasa vigente al vender',
  `subtotal_linea` decimal(14,2) NOT NULL,
  `impuesto_linea` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total_linea` decimal(14,2) NOT NULL,
  `controla_inventario` tinyint(4) DEFAULT NULL,
  `permite_decimales` tinyint(4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_detventa_venta` (`venta_id`),
  KEY `idx_detventa_variante` (`variante_id`),
  KEY `unidad_id` (`unidad_id`),
  KEY `impuesto_id` (`impuesto_id`),
  CONSTRAINT `detalle_venta_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `detalle_venta_ibfk_2` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`),
  CONSTRAINT `detalle_venta_ibfk_3` FOREIGN KEY (`unidad_id`) REFERENCES `unidades` (`id`),
  CONSTRAINT `detalle_venta_ibfk_4` FOREIGN KEY (`impuesto_id`) REFERENCES `impuestos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Renglones del ticket; guardan precio, costo e IVA del momento';
CREATE TABLE `devoluciones_venta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL,
  `venta_id` bigint(20) unsigned NOT NULL COMMENT 'Venta original (no se modifica)',
  `turno_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Turno en que se hizo la devoluciÃ³n',
  `usuario_id` bigint(20) unsigned NOT NULL,
  `metodo_pago_id` bigint(20) unsigned NOT NULL COMMENT 'CÃ³mo se regresa el dinero',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `impuesto_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `motivo` varchar(300) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tipo` enum('devolucion','cancelacion') NOT NULL DEFAULT 'devolucion',
  `datos` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `idx_dev_venta` (`venta_id`),
  KEY `idx_dev_fecha` (`creado_en`),
  KEY `turno_id` (`turno_id`),
  KEY `usuario_id` (`usuario_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  CONSTRAINT `devoluciones_venta_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `devoluciones_venta_ibfk_2` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `devoluciones_venta_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `devoluciones_venta_ibfk_4` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Devoluciones de clientes sobre ventas ya completadas';
CREATE TABLE `empresa` (
  `id` tinyint(3) unsigned NOT NULL DEFAULT '1',
  `nombre_comercial` varchar(150) NOT NULL,
  `razon_social` varchar(200) DEFAULT NULL,
  `rfc` varchar(13) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `sitio_web` varchar(200) DEFAULT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `codigo_postal` char(5) DEFAULT NULL,
  `logo_ruta` varchar(500) DEFAULT NULL COMMENT 'Ruta relativa en storage, nunca una URL externa',
  `color_primario` char(7) NOT NULL DEFAULT '#176549',
  `color_secundario` char(7) NOT NULL DEFAULT '#FFFFFF',
  `color_acento` char(7) NOT NULL DEFAULT '#AA5A17',
  `encabezado_ticket` varchar(300) DEFAULT NULL,
  `pie_ticket` varchar(300) DEFAULT NULL,
  `moneda` char(3) NOT NULL DEFAULT 'MXN',
  `zona_horaria` varchar(50) NOT NULL DEFAULT 'America/Mexico_City',
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  CONSTRAINT `ck_color_acento` CHECK (regexp_like(`color_acento`,_utf8mb4'^#[0-9A-Fa-f]{6}$')),
  CONSTRAINT `ck_color_primario` CHECK (regexp_like(`color_primario`,_utf8mb4'^#[0-9A-Fa-f]{6}$')),
  CONSTRAINT `ck_color_secundario` CHECK (regexp_like(`color_secundario`,_utf8mb4'^#[0-9A-Fa-f]{6}$')),
  CONSTRAINT `ck_empresa_unica` CHECK ((`id` = 1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Datos y colores del negocio; una sola configuraciÃ³n';
CREATE TABLE `existencias` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ubicacion_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned NOT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT '0.0000' COMMENT 'Saldo actual; puede ser negativo',
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_existencia` (`ubicacion_id`,`variante_id`),
  KEY `idx_existencia_variante` (`variante_id`),
  CONSTRAINT `existencias_ibfk_1` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `existencias_ibfk_2` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='CuÃ¡nto hay AHORA de cada variante en cada ubicaciÃ³n';
CREATE TABLE `gastos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL,
  `categoria_id` bigint(20) unsigned NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `turno_id` bigint(20) unsigned DEFAULT NULL,
  `movimiento_id` bigint(20) unsigned DEFAULT NULL,
  `metodo_pago_id` bigint(20) unsigned NOT NULL,
  `tipo_metodo` varchar(20) NOT NULL,
  `origen` enum('caja','externo') NOT NULL,
  `fecha` date NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  `cuenta` varchar(100) DEFAULT NULL,
  `beneficiario` varchar(150) DEFAULT NULL,
  `descripcion` varchar(300) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) NOT NULL,
  `datos` json NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  UNIQUE KEY `uq_gasto_solicitud` (`usuario_id`,`clave`),
  UNIQUE KEY `movimiento_id` (`movimiento_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  KEY `ix_gasto_fecha` (`fecha`),
  KEY `ix_gasto_usuario_fecha` (`usuario_id`,`fecha`),
  KEY `ix_gasto_categoria_fecha` (`categoria_id`,`fecha`),
  KEY `ix_gasto_turno` (`turno_id`),
  CONSTRAINT `gastos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias_gasto` (`id`),
  CONSTRAINT `gastos_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `gastos_ibfk_3` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `gastos_ibfk_4` FOREIGN KEY (`movimiento_id`) REFERENCES `movimientos_efectivo` (`id`),
  CONSTRAINT `gastos_ibfk_5` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `imagenes_producto` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Si se llena, la imagen es de esa variante',
  `ruta` varchar(500) NOT NULL COMMENT 'Ruta relativa del archivo, no el archivo',
  `es_principal` tinyint(1) NOT NULL DEFAULT '0',
  `orden` smallint(6) NOT NULL DEFAULT '0',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `nombre_original` varchar(150) NOT NULL DEFAULT 'Imagen',
  `principal_slot` tinyint(4) GENERATED ALWAYS AS (if((`es_principal` = 1),1,NULL)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_imagen_principal` (`producto_id`,`principal_slot`),
  KEY `idx_imagenes_producto` (`producto_id`,`orden`),
  KEY `variante_id` (`variante_id`),
  CONSTRAINT `imagenes_producto_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `imagenes_producto_ibfk_2` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Fotos de productos (solo la ruta; el archivo vive en disco)';
CREATE TABLE `impuestos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `tasa` decimal(6,4) NOT NULL COMMENT 'FracciÃ³n: 0.1600 = 16%',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Impuestos (IVA 16%, 8%, 0%, exento)';
CREATE TABLE `intentos_acceso` (
  `clave` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `inicio` bigint(20) unsigned NOT NULL,
  `intentos` smallint(5) unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='LÃ­mite temporal de intentos de acceso por direcciÃ³n de origen';
CREATE TABLE `metodos_pago` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(80) NOT NULL,
  `tipo` enum('efectivo','tarjeta','transferencia','credito','vales','otro') NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Formas de pago aceptadas';
CREATE TABLE `minimos_inventario` (
  `ubicacion_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned NOT NULL,
  `minimo` decimal(14,4) DEFAULT NULL,
  `version` bigint(20) unsigned NOT NULL DEFAULT '1',
  `usuario_id` bigint(20) unsigned DEFAULT NULL,
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`ubicacion_id`,`variante_id`),
  KEY `ix_minimo_variante` (`variante_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `minimos_inventario_ibfk_1` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`) ON DELETE CASCADE,
  CONSTRAINT `minimos_inventario_ibfk_2` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`) ON DELETE CASCADE,
  CONSTRAINT `minimos_inventario_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `movimientos_cuenta_cliente` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cliente_id` bigint(20) unsigned NOT NULL,
  `tipo` enum('cargo','abono','ajuste','devolucion') NOT NULL,
  `monto` decimal(14,2) NOT NULL COMMENT 'Positivo = debe mÃ¡s, negativo = debe menos',
  `venta_id` bigint(20) unsigned DEFAULT NULL,
  `metodo_pago_id` bigint(20) unsigned DEFAULT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `notas` varchar(300) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cuenta_cliente` (`cliente_id`,`creado_en`),
  KEY `venta_id` (`venta_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `movimientos_cuenta_cliente_ibfk_1` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`),
  CONSTRAINT `movimientos_cuenta_cliente_ibfk_2` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`),
  CONSTRAINT `movimientos_cuenta_cliente_ibfk_3` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`),
  CONSTRAINT `movimientos_cuenta_cliente_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Estado de cuenta del cliente (crÃ©dito / fiado)';
CREATE TABLE `movimientos_efectivo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `turno_id` bigint(20) unsigned NOT NULL,
  `tipo` enum('entrada','salida') NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  `motivo` varchar(200) NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_movefec_turno` (`turno_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `movimientos_efectivo_ibfk_1` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `movimientos_efectivo_ibfk_2` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Entradas y salidas de efectivo del cajÃ³n fuera de las ventas';
CREATE TABLE `movimientos_inventario` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ubicacion_id` bigint(20) unsigned NOT NULL,
  `variante_id` bigint(20) unsigned NOT NULL,
  `tipo_movimiento` enum('compra','venta','devolucion_cliente','devolucion_proveedor','ajuste','traspaso_entrada','traspaso_salida','merma') NOT NULL,
  `cantidad` decimal(14,4) NOT NULL COMMENT 'Positivo = entra, negativo = sale',
  `costo_unitario` decimal(14,4) DEFAULT NULL,
  `tipo_referencia` varchar(30) DEFAULT NULL COMMENT 'Documento origen: venta, compra, devolucion_venta',
  `referencia_id` bigint(20) unsigned DEFAULT NULL COMMENT 'Id del documento origen',
  `usuario_id` bigint(20) unsigned DEFAULT NULL,
  `notas` varchar(300) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_mov_variante` (`variante_id`,`creado_en`),
  KEY `idx_mov_referencia` (`tipo_referencia`,`referencia_id`),
  KEY `ubicacion_id` (`ubicacion_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `movimientos_inventario_ibfk_1` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `movimientos_inventario_ibfk_2` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`),
  CONSTRAINT `movimientos_inventario_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Historial de inventario. Solo se agregan filas, nunca se editan';
CREATE TABLE `operaciones_almacen` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) DEFAULT NULL,
  `tipo` enum('traspaso','merma') NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `origen_id` bigint(20) unsigned DEFAULT NULL,
  `destino_id` bigint(20) unsigned DEFAULT NULL,
  `variante_id` bigint(20) unsigned DEFAULT NULL,
  `cantidad` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `costo_unitario` decimal(14,4) DEFAULT NULL,
  `motivo` varchar(300) NOT NULL,
  `resultado` json DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_operacion_almacen` (`usuario_id`,`clave`),
  UNIQUE KEY `folio` (`folio`),
  KEY `idx_almacen_origen` (`origen_id`,`id`),
  KEY `idx_almacen_destino` (`destino_id`,`id`),
  KEY `variante_id` (`variante_id`),
  CONSTRAINT `operaciones_almacen_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `operaciones_almacen_ibfk_2` FOREIGN KEY (`origen_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `operaciones_almacen_ibfk_3` FOREIGN KEY (`destino_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `operaciones_almacen_ibfk_4` FOREIGN KEY (`variante_id`) REFERENCES `variantes_producto` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Traspasos inmediatos y mermas definitivas; documentos inmutables al confirmar';
CREATE TABLE `pagos_devolucion` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `devolucion_id` bigint(20) unsigned NOT NULL,
  `pago_venta_id` bigint(20) unsigned NOT NULL,
  `monto` decimal(14,2) NOT NULL,
  `tipo_metodo` varchar(20) NOT NULL,
  `nombre_metodo` varchar(80) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reembolso_pago` (`devolucion_id`,`pago_venta_id`),
  KEY `pago_venta_id` (`pago_venta_id`),
  CONSTRAINT `pagos_devolucion_ibfk_1` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones_venta` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pagos_devolucion_ibfk_2` FOREIGN KEY (`pago_venta_id`) REFERENCES `pagos_venta` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `pagos_proveedor` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL,
  `compra_id` bigint(20) unsigned NOT NULL,
  `proveedor_id` bigint(20) unsigned NOT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `turno_id` bigint(20) unsigned DEFAULT NULL,
  `metodo_pago_id` bigint(20) unsigned NOT NULL,
  `tipo_metodo` varchar(20) NOT NULL,
  `origen` enum('caja','externo') NOT NULL,
  `cuenta` varchar(100) DEFAULT NULL,
  `monto` decimal(14,2) NOT NULL,
  `referencia` varchar(100) DEFAULT NULL,
  `notas` varchar(300) NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) NOT NULL,
  `datos` json NOT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  UNIQUE KEY `uq_pago_proveedor` (`usuario_id`,`clave`),
  KEY `compra_id` (`compra_id`),
  KEY `proveedor_id` (`proveedor_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  KEY `ix_pp_turno` (`turno_id`),
  KEY `ix_pp_fecha` (`creado_en`),
  CONSTRAINT `pagos_proveedor_ibfk_1` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pagos_proveedor_ibfk_2` FOREIGN KEY (`proveedor_id`) REFERENCES `proveedores` (`id`),
  CONSTRAINT `pagos_proveedor_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `pagos_proveedor_ibfk_4` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `pagos_proveedor_ibfk_5` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `pagos_venta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `venta_id` bigint(20) unsigned NOT NULL,
  `metodo_pago_id` bigint(20) unsigned NOT NULL,
  `monto` decimal(14,2) NOT NULL COMMENT 'Aplicado a la venta, sin el cambio',
  `recibido` decimal(14,2) DEFAULT NULL COMMENT 'Lo que entregÃ³ el cliente',
  `cambio` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Lo que se le devolviÃ³',
  `referencia` varchar(100) DEFAULT NULL COMMENT 'AutorizaciÃ³n de tarjeta o folio de transferencia',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `tipo_metodo` varchar(20) DEFAULT NULL,
  `nombre_metodo` varchar(80) DEFAULT NULL,
  `abono_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pago_abono` (`abono_id`),
  KEY `idx_pagos_venta` (`venta_id`),
  KEY `metodo_pago_id` (`metodo_pago_id`),
  CONSTRAINT `pagos_venta_ibfk_1` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `pagos_venta_ibfk_2` FOREIGN KEY (`metodo_pago_id`) REFERENCES `metodos_pago` (`id`),
  CONSTRAINT `pagos_venta_ibfk_3` FOREIGN KEY (`abono_id`) REFERENCES `abonos_cliente` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Pagos de una venta; permite pago mixto';
CREATE TABLE `preferencias_pdv` (
  `usuario_id` bigint(20) unsigned NOT NULL,
  `vista` enum('busqueda','mosaico') NOT NULL DEFAULT 'busqueda',
  PRIMARY KEY (`usuario_id`),
  CONSTRAINT `preferencias_pdv_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `productos` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `descripcion` text,
  `categoria_id` bigint(20) unsigned DEFAULT NULL,
  `unidad_base_id` bigint(20) unsigned NOT NULL COMMENT 'Unidad en que se lleva el inventario',
  `impuesto_id` bigint(20) unsigned NOT NULL,
  `tipo_producto` enum('estandar','granel','servicio','kit') NOT NULL DEFAULT 'estandar' COMMENT 'granel = se vende por peso o medida; kit = combo',
  `precio_incluye_impuesto` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = el precio de etiqueta ya trae IVA',
  `controla_inventario` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 para servicios',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `datos_extra` json DEFAULT NULL COMMENT 'Campos propios del giro (talla, graduaciÃ³n, etc.)',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_productos_categoria` (`categoria_id`),
  KEY `unidad_base_id` (`unidad_base_id`),
  KEY `impuesto_id` (`impuesto_id`),
  FULLTEXT KEY `ft_productos_nombre` (`nombre`),
  CONSTRAINT `productos_ibfk_1` FOREIGN KEY (`categoria_id`) REFERENCES `categorias` (`id`),
  CONSTRAINT `productos_ibfk_2` FOREIGN KEY (`unidad_base_id`) REFERENCES `unidades` (`id`),
  CONSTRAINT `productos_ibfk_3` FOREIGN KEY (`impuesto_id`) REFERENCES `impuestos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Ficha general del producto o servicio';
CREATE TABLE `proveedores` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(200) NOT NULL,
  `contacto` varchar(150) DEFAULT NULL,
  `telefono` varchar(30) DEFAULT NULL,
  `correo` varchar(150) DEFAULT NULL,
  `direccion` varchar(300) DEFAULT NULL,
  `notas` varchar(500) DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_proveedores_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Proveedores de mercancÃ­a';
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(60) NOT NULL,
  `permisos` json DEFAULT NULL COMMENT 'Acciones permitidas al rol',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Roles de usuario y sus permisos';
CREATE TABLE `sistema_recuperacion` (
  `id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `confirmado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `solicitudes_caja` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `accion` enum('abrir','movimiento','cerrar') NOT NULL,
  `huella` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `turno_id` bigint(20) unsigned DEFAULT NULL,
  `resultado` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_solicitud_caja` (`usuario_id`,`clave`),
  KEY `turno_id` (`turno_id`),
  CONSTRAINT `solicitudes_caja_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `solicitudes_caja_ibfk_2` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Evita duplicar aperturas, movimientos de efectivo y cierres por reintentos';
CREATE TABLE `solicitudes_compra` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `compra_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_solicitud_compra` (`usuario_id`,`clave`),
  KEY `compra_id` (`compra_id`),
  CONSTRAINT `solicitudes_compra_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `solicitudes_compra_ibfk_2` FOREIGN KEY (`compra_id`) REFERENCES `compras` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Evita crear compras duplicadas al reintentar una solicitud';
CREATE TABLE `solicitudes_reembolso` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) NOT NULL,
  `devolucion_id` bigint(20) unsigned DEFAULT NULL,
  `resultado` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_solicitud_reembolso` (`usuario_id`,`clave`),
  KEY `devolucion_id` (`devolucion_id`),
  CONSTRAINT `solicitudes_reembolso_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `solicitudes_reembolso_ibfk_2` FOREIGN KEY (`devolucion_id`) REFERENCES `devoluciones_venta` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
CREATE TABLE `solicitudes_venta` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usuario_id` bigint(20) unsigned NOT NULL,
  `clave` char(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `huella` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `venta_id` bigint(20) unsigned DEFAULT NULL,
  `resultado` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_solicitud_venta` (`usuario_id`,`clave`),
  KEY `venta_id` (`venta_id`),
  CONSTRAINT `solicitudes_venta_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `solicitudes_venta_ibfk_2` FOREIGN KEY (`venta_id`) REFERENCES `ventas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Evita cobrar o descontar inventario dos veces al reintentar una venta';
CREATE TABLE `turnos_caja` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `caja_id` bigint(20) unsigned NOT NULL,
  `abierto_por` bigint(20) unsigned NOT NULL,
  `cerrado_por` bigint(20) unsigned DEFAULT NULL,
  `estatus` enum('abierto','cerrado') NOT NULL DEFAULT 'abierto',
  `monto_apertura` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Fondo inicial (cambio)',
  `monto_esperado` decimal(14,2) DEFAULT NULL COMMENT 'Lo que deberÃ­a haber en el cajÃ³n',
  `monto_cierre` decimal(14,2) DEFAULT NULL COMMENT 'Lo que el cajero contÃ³',
  `diferencia` decimal(14,2) GENERATED ALWAYS AS ((`monto_cierre` - `monto_esperado`)) STORED COMMENT 'Negativa = faltante, positiva = sobrante',
  `notas` varchar(300) DEFAULT NULL,
  `abierto_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `cerrado_en` timestamp NULL DEFAULT NULL,
  `clave_abierto` bigint(20) unsigned GENERATED ALWAYS AS (if((`estatus` = _utf8mb4'abierto'),`caja_id`,NULL)) STORED COMMENT 'Auxiliar: impide dos turnos abiertos en la misma caja',
  `ubicacion_id` bigint(20) unsigned DEFAULT NULL,
  `notas_cierre` varchar(300) DEFAULT NULL,
  `resumen_cierre` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_un_turno_abierto_por_caja` (`clave_abierto`),
  KEY `idx_turno_caja` (`caja_id`,`abierto_en`),
  KEY `abierto_por` (`abierto_por`),
  KEY `cerrado_por` (`cerrado_por`),
  KEY `idx_turno_ubicacion` (`ubicacion_id`),
  CONSTRAINT `fk_turno_ubicacion` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `turnos_caja_ibfk_1` FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`id`),
  CONSTRAINT `turnos_caja_ibfk_2` FOREIGN KEY (`abierto_por`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `turnos_caja_ibfk_3` FOREIGN KEY (`cerrado_por`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Turnos de caja: apertura con fondo y corte al cerrar';
CREATE TABLE `ubicaciones` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `disponible_venta` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0 = resguardo, daÃ±ado o pendiente de revisiÃ³n; no cuenta como disponible para venta',
  `descripcion` varchar(300) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Lugares donde se guarda inventario (tienda, bodega)';
CREATE TABLE `unidades` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(20) NOT NULL COMMENT 'Abreviatura: pza, kg, lt',
  `nombre` varchar(60) NOT NULL,
  `permite_decimales` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = se puede vender 1.250 kg',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Unidades de medida';
CREATE TABLE `usuarios` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `rol_id` bigint(20) unsigned NOT NULL,
  `nombre` varchar(150) NOT NULL,
  `nombre_usuario` varchar(60) NOT NULL,
  `contrasena_hash` varchar(255) NOT NULL COMMENT 'Hash (bcrypt/argon2), nunca la contraseÃ±a',
  `pin_hash` varchar(255) DEFAULT NULL COMMENT 'Hash del PIN de acceso rÃ¡pido en caja',
  `activo` tinyint(1) NOT NULL DEFAULT '1' COMMENT 'Los usuarios no se borran, se desactivan',
  `ultimo_acceso` timestamp NULL DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nombre_usuario` (`nombre_usuario`),
  KEY `rol_id` (`rol_id`),
  CONSTRAINT `usuarios_ibfk_1` FOREIGN KEY (`rol_id`) REFERENCES `roles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Empleados que usan el sistema';
CREATE TABLE `variantes_producto` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `producto_id` bigint(20) unsigned NOT NULL,
  `sku` varchar(64) NOT NULL COMMENT 'CÃ³digo interno',
  `nombre` varchar(200) DEFAULT NULL COMMENT 'Ej. Playera azul / M; vacÃ­o si no hay variantes',
  `costo` decimal(14,4) NOT NULL DEFAULT '0.0000' COMMENT 'Costo promedio, sin IVA',
  `precio` decimal(14,4) NOT NULL DEFAULT '0.0000' COMMENT 'Precio de venta por unidad base',
  `stock_minimo` decimal(14,4) NOT NULL DEFAULT '0.0000' COMMENT 'Para alertas de reabastecimiento',
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  KEY `producto_id` (`producto_id`),
  CONSTRAINT `variantes_producto_ibfk_1` FOREIGN KEY (`producto_id`) REFERENCES `productos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='PresentaciÃ³n concreta que se vende y se cuenta (el SKU)';
CREATE TABLE `ventas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(30) NOT NULL COMMENT 'NÃºmero del ticket',
  `ubicacion_id` bigint(20) unsigned NOT NULL,
  `caja_id` bigint(20) unsigned DEFAULT NULL,
  `turno_id` bigint(20) unsigned DEFAULT NULL,
  `usuario_id` bigint(20) unsigned NOT NULL COMMENT 'QuiÃ©n vendiÃ³',
  `cliente_id` bigint(20) unsigned DEFAULT NULL,
  `estatus` enum('abierta','en_espera','completada','cancelada') NOT NULL DEFAULT 'abierta',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00' COMMENT 'Antes de impuestos',
  `descuento_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `impuesto_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `notas` varchar(500) DEFAULT NULL,
  `creado_en` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completado_en` timestamp NULL DEFAULT NULL,
  `ticket_datos` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `folio` (`folio`),
  KEY `idx_ventas_fecha` (`creado_en`),
  KEY `idx_ventas_turno` (`turno_id`),
  KEY `idx_ventas_estatus` (`estatus`,`creado_en`),
  KEY `idx_ventas_cliente` (`cliente_id`),
  KEY `ubicacion_id` (`ubicacion_id`),
  KEY `caja_id` (`caja_id`),
  KEY `usuario_id` (`usuario_id`),
  CONSTRAINT `ventas_ibfk_1` FOREIGN KEY (`ubicacion_id`) REFERENCES `ubicaciones` (`id`),
  CONSTRAINT `ventas_ibfk_2` FOREIGN KEY (`caja_id`) REFERENCES `cajas` (`id`),
  CONSTRAINT `ventas_ibfk_3` FOREIGN KEY (`turno_id`) REFERENCES `turnos_caja` (`id`),
  CONSTRAINT `ventas_ibfk_4` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`),
  CONSTRAINT `ventas_ibfk_5` FOREIGN KEY (`cliente_id`) REFERENCES `clientes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Encabezado de la venta (el ticket)';
SET FOREIGN_KEY_CHECKS=1;
START TRANSACTION;
INSERT INTO `roles` (`id`,`nombre`,`permisos`) VALUES (1,'Administrador','{"todo":true}');
INSERT INTO `roles` (`id`,`nombre`,`permisos`) VALUES (2,'Gerente','{"ver_productos":true,"editar_productos":true,"vender":true,"ver_ventas":true,"dar_descuento":true,"cambiar_precio":true,"cancelar_venta":true,"hacer_devoluciones":true,"ver_clientes":true,"editar_clientes":true,"ver_inventario":true,"ver_alertas_inventario":true,"configurar_minimos":true,"ajustar_inventario":true,"administrar_ubicaciones":true,"realizar_traspasos":true,"registrar_mermas":true,"hacer_compras":true,"operar_caja":true,"supervisar_caja":true,"administrar_cajas":true,"ver_gastos":true,"registrar_gastos":true,"ver_cuentas_pagar":true,"pagar_proveedores":true,"vender_credito":true,"ver_creditos":true,"cobrar_abonos":true,"ver_reportes":true}');
INSERT INTO `roles` (`id`,`nombre`,`permisos`) VALUES (3,'Cajero','{"ver_productos":false,"editar_productos":false,"vender":true,"ver_ventas":true,"dar_descuento":false,"cambiar_precio":false,"cancelar_venta":true,"hacer_devoluciones":true,"ver_clientes":true,"editar_clientes":false,"ver_inventario":false,"ver_alertas_inventario":false,"configurar_minimos":false,"ajustar_inventario":false,"administrar_ubicaciones":false,"realizar_traspasos":false,"registrar_mermas":false,"hacer_compras":false,"operar_caja":true,"supervisar_caja":false,"administrar_cajas":false,"ver_gastos":true,"registrar_gastos":true,"ver_cuentas_pagar":false,"pagar_proveedores":false,"vender_credito":true,"ver_creditos":true,"cobrar_abonos":true,"ver_reportes":false}');
INSERT INTO `roles` (`id`,`nombre`,`permisos`) VALUES (4,'Almacenista','{"ver_productos":true,"editar_productos":false,"vender":false,"ver_ventas":false,"dar_descuento":false,"cambiar_precio":false,"cancelar_venta":false,"hacer_devoluciones":false,"ver_clientes":false,"editar_clientes":false,"ver_inventario":true,"ver_alertas_inventario":true,"configurar_minimos":true,"ajustar_inventario":true,"administrar_ubicaciones":true,"realizar_traspasos":true,"registrar_mermas":true,"hacer_compras":false,"operar_caja":false,"supervisar_caja":false,"administrar_cajas":false,"ver_gastos":false,"registrar_gastos":false,"ver_cuentas_pagar":false,"pagar_proveedores":false,"vender_credito":false,"ver_creditos":false,"cobrar_abonos":false,"ver_reportes":false}');
INSERT INTO `roles` (`id`,`nombre`,`permisos`) VALUES (5,'Compras','{"ver_productos":true,"editar_productos":false,"vender":false,"ver_ventas":false,"dar_descuento":false,"cambiar_precio":false,"cancelar_venta":false,"hacer_devoluciones":false,"ver_clientes":false,"editar_clientes":false,"ver_inventario":true,"ver_alertas_inventario":true,"configurar_minimos":false,"ajustar_inventario":false,"administrar_ubicaciones":false,"realizar_traspasos":false,"registrar_mermas":false,"hacer_compras":true,"operar_caja":false,"supervisar_caja":false,"administrar_cajas":false,"ver_gastos":false,"registrar_gastos":false,"ver_cuentas_pagar":true,"pagar_proveedores":true,"vender_credito":false,"ver_creditos":false,"cobrar_abonos":false,"ver_reportes":false}');
INSERT INTO `roles` (`id`,`nombre`,`permisos`) VALUES (6,'Consulta','{"ver_productos":true,"editar_productos":false,"vender":false,"ver_ventas":false,"dar_descuento":false,"cambiar_precio":false,"cancelar_venta":false,"hacer_devoluciones":false,"ver_clientes":false,"editar_clientes":false,"ver_inventario":true,"ver_alertas_inventario":true,"configurar_minimos":false,"ajustar_inventario":false,"administrar_ubicaciones":false,"realizar_traspasos":false,"registrar_mermas":false,"hacer_compras":false,"operar_caja":false,"supervisar_caja":false,"administrar_cajas":false,"ver_gastos":false,"registrar_gastos":false,"ver_cuentas_pagar":false,"pagar_proveedores":false,"vender_credito":false,"ver_creditos":false,"cobrar_abonos":false,"ver_reportes":true}');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (1,1,'Administrador DEMO','admin','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (1,'busqueda');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (2,2,'Mariana Robles DEMO','gerente','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (2,'busqueda');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (3,3,'Luis Medina DEMO','cajero','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (3,'busqueda');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (4,3,'Ana Torres DEMO','cajero2','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (4,'busqueda');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (5,4,'Diego Campos DEMO','almacen','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (5,'busqueda');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (6,5,'Elena Soto DEMO','compras','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (6,'busqueda');
INSERT INTO `usuarios` (`id`,`rol_id`,`nombre`,`nombre_usuario`,`contrasena_hash`) VALUES (7,6,'Pablo Luna DEMO','consulta','$2y$10$4xbFUYRcGvtWCohZnFgKHuj8XNosj.IZvH93FbjeHTvkR3eT5Sq4i');
INSERT INTO `preferencias_pdv` (`usuario_id`,`vista`) VALUES (7,'busqueda');
INSERT INTO `empresa` (`id`,`nombre_comercial`,`razon_social`,`telefono`,`correo`,`direccion`,`codigo_postal`,`color_primario`,`color_secundario`,`color_acento`,`encabezado_ticket`,`pie_ticket`,`moneda`,`zona_horaria`) VALUES (1,'Moda Nube y Accesorios DEMO','Comercio Ficticio de Pruebas','5550000000','contacto@example.test','Calle de Prueba 123, Colonia Ejemplo','01000','#73405C','#73405C','#D5A64A','DEMO - Datos ficticios, sin valor comercial','Gracias por probar Moda Nube y Accesorios DEMO','MXN','America/Mexico_City');
INSERT INTO `unidades` (`id`,`codigo`,`nombre`,`permite_decimales`) VALUES (1,'pza','Pieza',0);
INSERT INTO `unidades` (`id`,`codigo`,`nombre`,`permite_decimales`) VALUES (2,'kg','Kilogramo',1);
INSERT INTO `unidades` (`id`,`codigo`,`nombre`,`permite_decimales`) VALUES (3,'lt','Litro',1);
INSERT INTO `unidades` (`id`,`codigo`,`nombre`,`permite_decimales`) VALUES (4,'m','Metro',1);
INSERT INTO `unidades` (`id`,`codigo`,`nombre`,`permite_decimales`) VALUES (5,'serv','Servicio',0);
INSERT INTO `impuestos` (`id`,`nombre`,`tasa`) VALUES (1,'IVA 16% DEMO','0.1600');
INSERT INTO `impuestos` (`id`,`nombre`,`tasa`) VALUES (2,'Tasa cero DEMO','0.0000');
INSERT INTO `metodos_pago` (`id`,`nombre`,`tipo`) VALUES (1,'Efectivo','efectivo');
INSERT INTO `metodos_pago` (`id`,`nombre`,`tipo`) VALUES (2,'Tarjeta de débito','tarjeta');
INSERT INTO `metodos_pago` (`id`,`nombre`,`tipo`) VALUES (3,'Tarjeta de crédito','tarjeta');
INSERT INTO `metodos_pago` (`id`,`nombre`,`tipo`) VALUES (4,'Transferencia','transferencia');
INSERT INTO `metodos_pago` (`id`,`nombre`,`tipo`) VALUES (5,'Crédito del cliente','credito');
INSERT INTO `metodos_pago` (`id`,`nombre`,`tipo`) VALUES (6,'Vales','vales');
INSERT INTO `ubicaciones` (`id`,`nombre`,`disponible_venta`,`descripcion`) VALUES (1,'Tienda',1,'Ubicación ficticia del escenario ropa');
INSERT INTO `ubicaciones` (`id`,`nombre`,`disponible_venta`,`descripcion`) VALUES (2,'Bodega',1,'Ubicación ficticia del escenario ropa');
INSERT INTO `ubicaciones` (`id`,`nombre`,`disponible_venta`,`descripcion`) VALUES (3,'Sucursal Norte',1,'Ubicación ficticia del escenario ropa');
INSERT INTO `ubicaciones` (`id`,`nombre`,`disponible_venta`,`descripcion`) VALUES (4,'Dañados y revisión',0,'Ubicación ficticia del escenario ropa');
INSERT INTO `cajas` (`id`,`ubicacion_id`,`nombre`) VALUES (1,1,'Caja 1');
INSERT INTO `cajas` (`id`,`ubicacion_id`,`nombre`) VALUES (2,1,'Caja 2');
INSERT INTO `cajas` (`id`,`ubicacion_id`,`nombre`) VALUES (3,3,'Caja 3');
INSERT INTO `clientes` (`id`,`nombre`,`notas`) VALUES (1,'Público en general DEMO','Cliente predeterminado de pruebas');
INSERT INTO `configuracion_clientes` (`id`,`cliente_predeterminado_id`) VALUES (1,1);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (2,'Sofía Castillo DEMO','5551000002','cliente2@example.test','Calle Ficticia 2','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (2,1,'5000.00',15);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (3,'Jorge Mendoza DEMO','5551000003','cliente3@example.test','Calle Ficticia 3','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (3,1,'5000.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (4,'Valeria Cruz DEMO','5551000004','cliente4@example.test','Calle Ficticia 4','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (4,1,'5000.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (5,'Carlos Pineda DEMO','5551000005','cliente5@example.test','Calle Ficticia 5','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (5,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (6,'Daniela Vega DEMO','5551000006','cliente6@example.test','Calle Ficticia 6','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (6,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (7,'Miguel Flores DEMO','5551000007','cliente7@example.test','Calle Ficticia 7','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (7,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (8,'Paola Reyes DEMO','5551000008','cliente8@example.test','Calle Ficticia 8','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (8,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (9,'Andrés Salas DEMO','5551000009','cliente9@example.test','Calle Ficticia 9','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (9,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (10,'Lucía Ríos DEMO','5551000010','cliente10@example.test','Calle Ficticia 10','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (10,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (11,'Roberto Navas DEMO','5551000011','cliente11@example.test','Calle Ficticia 11','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (11,0,'0.00',30);
INSERT INTO `clientes` (`id`,`nombre`,`telefono`,`correo`,`direccion`,`notas`) VALUES (12,'Empresa Jardín Ejemplo DEMO','5551000012','cliente12@example.test','Calle Ficticia 12','Cliente inventado para probar ventas y crédito');
INSERT INTO `configuracion_credito_cliente` (`cliente_id`,`habilitado`,`limite`,`dias`) VALUES (12,0,'0.00',30);
INSERT INTO `proveedores` (`id`,`nombre`,`contacto`,`telefono`,`correo`,`direccion`,`notas`) VALUES (1,'Distribuidora Horizonte ropa DEMO','Contacto ficticio 1','5552000001','proveedor1@example.test','Parque Comercial de Prueba 1','Proveedor ficticio, sin operaciones reales');
INSERT INTO `proveedores` (`id`,`nombre`,`contacto`,`telefono`,`correo`,`direccion`,`notas`) VALUES (2,'Suministros del Valle ropa DEMO','Contacto ficticio 2','5552000002','proveedor2@example.test','Parque Comercial de Prueba 2','Proveedor ficticio, sin operaciones reales');
INSERT INTO `proveedores` (`id`,`nombre`,`contacto`,`telefono`,`correo`,`direccion`,`notas`) VALUES (3,'Mayoreo Estrella ropa DEMO','Contacto ficticio 3','5552000003','proveedor3@example.test','Parque Comercial de Prueba 3','Proveedor ficticio, sin operaciones reales');
INSERT INTO `proveedores` (`id`,`nombre`,`contacto`,`telefono`,`correo`,`direccion`,`notas`) VALUES (4,'Comercial del Centro ropa DEMO','Contacto ficticio 4','5552000004','proveedor4@example.test','Parque Comercial de Prueba 4','Proveedor ficticio, sin operaciones reales');
INSERT INTO `proveedores` (`id`,`nombre`,`contacto`,`telefono`,`correo`,`direccion`,`notas`) VALUES (5,'Abasto Regional ropa DEMO','Contacto ficticio 5','5552000005','proveedor5@example.test','Parque Comercial de Prueba 5','Proveedor ficticio, sin operaciones reales');
INSERT INTO `categorias_gasto` (`id`,`nombre`) VALUES (1,'Agua y servicios');
INSERT INTO `categorias_gasto` (`id`,`nombre`) VALUES (2,'Transporte');
INSERT INTO `categorias_gasto` (`id`,`nombre`) VALUES (3,'Limpieza');
INSERT INTO `categorias_gasto` (`id`,`nombre`) VALUES (4,'Mantenimiento');
INSERT INTO `categorias_gasto` (`id`,`nombre`) VALUES (5,'Otros gastos');
INSERT INTO `categorias` (`id`,`nombre`) VALUES (1,'Dama');
INSERT INTO `categorias` (`id`,`nombre`) VALUES (2,'Caballero');
INSERT INTO `categorias` (`id`,`nombre`) VALUES (3,'Infantil');
INSERT INTO `categorias` (`id`,`nombre`) VALUES (4,'Calzado');
INSERT INTO `categorias` (`id`,`nombre`) VALUES (5,'Accesorios');
INSERT INTO `categorias` (`id`,`nombre`) VALUES (6,'Servicios');
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (1,'Blusa lino','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',1,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (1,1,'ROP-0001','Negro / CH','145','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (1,'9902000000015',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,1,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,1,'ajuste',16,'145','demo_inicial',1,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,1,41);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,1,'ajuste',41,'145','demo_inicial',1,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,1,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,1,'ajuste',9,'145','demo_inicial',1,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (4,1,1);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (4,1,'ajuste',1,'145','demo_inicial',1,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,1,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (2,1,'ROP-0002','Negro / M','145','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (2,'9902000000022',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,2,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,2,'ajuste',17,'145','demo_inicial',2,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,2,42);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,2,'ajuste',42,'145','demo_inicial',2,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,2,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,2,'ajuste',10,'145','demo_inicial',2,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,2,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (3,1,'ROP-0003','Negro / G','145','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (3,'9902000000039',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,3,18);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,3,'ajuste',18,'145','demo_inicial',3,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,3,43);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,3,'ajuste',43,'145','demo_inicial',3,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,3,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,3,'ajuste',11,'145','demo_inicial',3,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,3,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (4,1,'ROP-0004','Azul / CH','145','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (4,'9902000000046',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,4,19);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,4,'ajuste',19,'145','demo_inicial',4,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,4,44);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,4,'ajuste',44,'145','demo_inicial',4,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,4,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,4,'ajuste',12,'145','demo_inicial',4,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,4,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (5,1,'ROP-0005','Azul / M','145','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (5,'9902000000053',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,5,20);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,5,'ajuste',20,'145','demo_inicial',5,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,5,45);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,5,'ajuste',45,'145','demo_inicial',5,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,5,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,5,'ajuste',13,'145','demo_inicial',5,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,5,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (6,1,'ROP-0006','Azul / G','145','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (6,'9902000000060',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,6,21);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,6,'ajuste',21,'145','demo_inicial',6,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,6,46);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,6,'ajuste',46,'145','demo_inicial',6,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,6,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,6,'ajuste',14,'145','demo_inicial',6,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,6,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (2,'Vestido casual','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',1,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (7,2,'ROP-0007','Negro / CH','280','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (7,'9902000000077',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,7,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,7,'ajuste',2,'280','demo_inicial',7,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,7,47);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,7,'ajuste',47,'280','demo_inicial',7,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,7,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,7,'ajuste',15,'280','demo_inicial',7,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,7,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (8,2,'ROP-0008','Negro / M','280','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (8,'9902000000084',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,8,23);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,8,'ajuste',23,'280','demo_inicial',8,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,8,48);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,8,'ajuste',48,'280','demo_inicial',8,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,8,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,8,'ajuste',16,'280','demo_inicial',8,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,8,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (9,2,'ROP-0009','Negro / G','280','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (9,'9902000000091',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,9,24);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,9,'ajuste',24,'280','demo_inicial',9,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,9,49);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,9,'ajuste',49,'280','demo_inicial',9,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,9,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,9,'ajuste',17,'280','demo_inicial',9,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,9,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (10,2,'ROP-0010','Azul / CH','280','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (10,'9902000000107',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,10,25);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,10,'ajuste',25,'280','demo_inicial',10,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,10,50);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,10,'ajuste',50,'280','demo_inicial',10,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,10,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,10,'ajuste',8,'280','demo_inicial',10,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,10,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (11,2,'ROP-0011','Azul / M','280','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (11,'9902000000114',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,11,26);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,11,'ajuste',26,'280','demo_inicial',11,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,11,51);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,11,'ajuste',51,'280','demo_inicial',11,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,11,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,11,'ajuste',9,'280','demo_inicial',11,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,11,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (12,2,'ROP-0012','Azul / G','280','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (12,'9902000000121',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,12,27);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,12,'ajuste',27,'280','demo_inicial',12,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,12,52);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,12,'ajuste',52,'280','demo_inicial',12,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,12,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,12,'ajuste',10,'280','demo_inicial',12,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,12,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (3,'Pantalón mezclilla dama','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',1,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (13,3,'ROP-0013','Negro / CH','260','549.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (13,'9902000000138',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,13,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,13,53);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,13,'ajuste',53,'260','demo_inicial',13,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,13,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,13,'ajuste',11,'260','demo_inicial',13,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,13,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (14,3,'ROP-0014','Negro / M','260','549.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (14,'9902000000145',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,14,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,14,'ajuste',2,'260','demo_inicial',14,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,14,54);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,14,'ajuste',54,'260','demo_inicial',14,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,14,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,14,'ajuste',12,'260','demo_inicial',14,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,14,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (15,3,'ROP-0015','Negro / G','260','549.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (15,'9902000000152',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,15,30);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,15,'ajuste',30,'260','demo_inicial',15,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,15,55);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,15,'ajuste',55,'260','demo_inicial',15,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,15,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,15,'ajuste',13,'260','demo_inicial',15,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,15,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (16,3,'ROP-0016','Azul / CH','260','549.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (16,'9902000000169',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,16,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,16,'ajuste',15,'260','demo_inicial',16,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,16,56);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,16,'ajuste',56,'260','demo_inicial',16,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,16,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,16,'ajuste',14,'260','demo_inicial',16,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,16,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (17,3,'ROP-0017','Azul / M','260','549.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (17,'9902000000176',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,17,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,17,'ajuste',16,'260','demo_inicial',17,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,17,57);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,17,'ajuste',57,'260','demo_inicial',17,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,17,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,17,'ajuste',15,'260','demo_inicial',17,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,17,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (18,3,'ROP-0018','Azul / G','260','549.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (18,'9902000000183',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,18,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,18,'ajuste',17,'260','demo_inicial',18,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,18,58);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,18,'ajuste',58,'260','demo_inicial',18,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,18,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,18,'ajuste',16,'260','demo_inicial',18,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,18,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (4,'Falda midi','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',1,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (19,4,'ROP-0019','Negro / CH','180','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (19,'9902000000190',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,19,18);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,19,'ajuste',18,'180','demo_inicial',19,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,19,59);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,19,'ajuste',59,'180','demo_inicial',19,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,19,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,19,'ajuste',17,'180','demo_inicial',19,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,19,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (20,4,'ROP-0020','Negro / M','180','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (20,'9902000000206',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,20,19);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,20,'ajuste',19,'180','demo_inicial',20,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,20,60);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,20,'ajuste',60,'180','demo_inicial',20,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,20,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,20,'ajuste',8,'180','demo_inicial',20,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,20,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (21,4,'ROP-0021','Negro / G','180','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (21,'9902000000213',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,21,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,21,'ajuste',2,'180','demo_inicial',21,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,21,61);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,21,'ajuste',61,'180','demo_inicial',21,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,21,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,21,'ajuste',9,'180','demo_inicial',21,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,21,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (22,4,'ROP-0022','Azul / CH','180','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (22,'9902000000220',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,22,21);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,22,'ajuste',21,'180','demo_inicial',22,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,22,62);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,22,'ajuste',62,'180','demo_inicial',22,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,22,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,22,'ajuste',10,'180','demo_inicial',22,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,22,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (23,4,'ROP-0023','Azul / M','180','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (23,'9902000000237',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,23,22);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,23,'ajuste',22,'180','demo_inicial',23,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,23,63);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,23,'ajuste',63,'180','demo_inicial',23,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,23,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,23,'ajuste',11,'180','demo_inicial',23,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,23,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (24,4,'ROP-0024','Azul / G','180','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (24,'9902000000244',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,24,23);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,24,'ajuste',23,'180','demo_inicial',24,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,24,64);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,24,'ajuste',64,'180','demo_inicial',24,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,24,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,24,'ajuste',12,'180','demo_inicial',24,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,24,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (5,'Playera básica dama','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',1,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (25,5,'ROP-0025','Negro / CH','75','179.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (25,'9902000000251',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,25,24);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,25,'ajuste',24,'75','demo_inicial',25,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,25,65);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,25,'ajuste',65,'75','demo_inicial',25,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,25,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,25,'ajuste',13,'75','demo_inicial',25,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,25,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (26,5,'ROP-0026','Negro / M','75','179.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (26,'9902000000268',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,26,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,26,66);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,26,'ajuste',66,'75','demo_inicial',26,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,26,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,26,'ajuste',14,'75','demo_inicial',26,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,26,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (27,5,'ROP-0027','Negro / G','75','179.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (27,'9902000000275',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,27,26);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,27,'ajuste',26,'75','demo_inicial',27,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,27,67);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,27,'ajuste',67,'75','demo_inicial',27,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,27,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,27,'ajuste',15,'75','demo_inicial',27,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,27,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (28,5,'ROP-0028','Azul / CH','75','179.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (28,'9902000000282',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,28,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,28,'ajuste',2,'75','demo_inicial',28,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,28,68);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,28,'ajuste',68,'75','demo_inicial',28,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,28,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,28,'ajuste',16,'75','demo_inicial',28,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,28,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (29,5,'ROP-0029','Azul / M','75','179.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (29,'9902000000299',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,29,28);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,29,'ajuste',28,'75','demo_inicial',29,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,29,69);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,29,'ajuste',69,'75','demo_inicial',29,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,29,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,29,'ajuste',17,'75','demo_inicial',29,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,29,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (30,5,'ROP-0030','Azul / G','75','179.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (30,'9902000000305',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,30,29);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,30,'ajuste',29,'75','demo_inicial',30,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,30,40);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,30,'ajuste',40,'75','demo_inicial',30,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,30,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,30,'ajuste',8,'75','demo_inicial',30,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,30,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (6,'Suéter tejido','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',1,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (31,6,'ROP-0031','Negro / CH','210','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (31,'9902000000312',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,31,30);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,31,'ajuste',30,'210','demo_inicial',31,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,31,41);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,31,'ajuste',41,'210','demo_inicial',31,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,31,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,31,'ajuste',9,'210','demo_inicial',31,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,31,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (32,6,'ROP-0032','Negro / M','210','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (32,'9902000000329',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,32,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,32,'ajuste',15,'210','demo_inicial',32,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,32,42);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,32,'ajuste',42,'210','demo_inicial',32,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,32,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,32,'ajuste',10,'210','demo_inicial',32,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,32,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (33,6,'ROP-0033','Negro / G','210','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (33,'9902000000336',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,33,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,33,'ajuste',16,'210','demo_inicial',33,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,33,43);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,33,'ajuste',43,'210','demo_inicial',33,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,33,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,33,'ajuste',11,'210','demo_inicial',33,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,33,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (34,6,'ROP-0034','Azul / CH','210','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (34,'9902000000343',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,34,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,34,'ajuste',17,'210','demo_inicial',34,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,34,44);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,34,'ajuste',44,'210','demo_inicial',34,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,34,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,34,'ajuste',12,'210','demo_inicial',34,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,34,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (35,6,'ROP-0035','Azul / M','210','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (35,'9902000000350',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,35,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,35,'ajuste',2,'210','demo_inicial',35,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,35,45);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,35,'ajuste',45,'210','demo_inicial',35,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,35,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,35,'ajuste',13,'210','demo_inicial',35,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,35,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (36,6,'ROP-0036','Azul / G','210','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (36,'9902000000367',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,36,19);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,36,'ajuste',19,'210','demo_inicial',36,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,36,46);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,36,'ajuste',46,'210','demo_inicial',36,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,36,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,36,'ajuste',14,'210','demo_inicial',36,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,36,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (7,'Camisa manga larga','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',2,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (37,7,'ROP-0037','Negro / CH','190','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (37,'9902000000374',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,37,20);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,37,'ajuste',20,'190','demo_inicial',37,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,37,47);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,37,'ajuste',47,'190','demo_inicial',37,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,37,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,37,'ajuste',15,'190','demo_inicial',37,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,37,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (38,7,'ROP-0038','Negro / M','190','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (38,'9902000000381',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,38,21);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,38,'ajuste',21,'190','demo_inicial',38,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,38,48);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,38,'ajuste',48,'190','demo_inicial',38,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,38,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,38,'ajuste',16,'190','demo_inicial',38,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,38,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (39,7,'ROP-0039','Negro / G','190','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (39,'9902000000398',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,39,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,39,49);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,39,'ajuste',49,'190','demo_inicial',39,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,39,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,39,'ajuste',17,'190','demo_inicial',39,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,39,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (40,7,'ROP-0040','Azul / CH','190','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (40,'9902000000404',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,40,23);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,40,'ajuste',23,'190','demo_inicial',40,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,40,50);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,40,'ajuste',50,'190','demo_inicial',40,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,40,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,40,'ajuste',8,'190','demo_inicial',40,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,40,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (41,7,'ROP-0041','Azul / M','190','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (41,'9902000000411',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,41,24);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,41,'ajuste',24,'190','demo_inicial',41,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,41,51);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,41,'ajuste',51,'190','demo_inicial',41,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,41,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,41,'ajuste',9,'190','demo_inicial',41,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,41,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (42,7,'ROP-0042','Azul / G','190','399.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (42,'9902000000428',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,42,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,42,'ajuste',2,'190','demo_inicial',42,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,42,52);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,42,'ajuste',52,'190','demo_inicial',42,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,42,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,42,'ajuste',10,'190','demo_inicial',42,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,42,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (8,'Pantalón mezclilla caballero','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',2,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (43,8,'ROP-0043','Negro / CH','275','579.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (43,'9902000000435',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,43,26);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,43,'ajuste',26,'275','demo_inicial',43,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,43,53);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,43,'ajuste',53,'275','demo_inicial',43,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,43,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,43,'ajuste',11,'275','demo_inicial',43,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,43,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (44,8,'ROP-0044','Negro / M','275','579.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (44,'9902000000442',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,44,27);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,44,'ajuste',27,'275','demo_inicial',44,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,44,54);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,44,'ajuste',54,'275','demo_inicial',44,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,44,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,44,'ajuste',12,'275','demo_inicial',44,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,44,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (45,8,'ROP-0045','Negro / G','275','579.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (45,'9902000000459',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,45,28);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,45,'ajuste',28,'275','demo_inicial',45,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,45,55);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,45,'ajuste',55,'275','demo_inicial',45,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,45,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,45,'ajuste',13,'275','demo_inicial',45,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,45,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (46,8,'ROP-0046','Azul / CH','275','579.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (46,'9902000000466',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,46,29);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,46,'ajuste',29,'275','demo_inicial',46,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,46,56);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,46,'ajuste',56,'275','demo_inicial',46,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,46,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,46,'ajuste',14,'275','demo_inicial',46,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,46,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (47,8,'ROP-0047','Azul / M','275','579.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (47,'9902000000473',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,47,30);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,47,'ajuste',30,'275','demo_inicial',47,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,47,57);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,47,'ajuste',57,'275','demo_inicial',47,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,47,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,47,'ajuste',15,'275','demo_inicial',47,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,47,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (48,8,'ROP-0048','Azul / G','275','579.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (48,'9902000000480',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,48,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,48,'ajuste',15,'275','demo_inicial',48,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,48,58);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,48,'ajuste',58,'275','demo_inicial',48,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,48,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,48,'ajuste',16,'275','demo_inicial',48,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,48,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (9,'Playera polo','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',2,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (49,9,'ROP-0049','Negro / CH','135','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (49,'9902000000497',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,49,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,49,'ajuste',2,'135','demo_inicial',49,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,49,59);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,49,'ajuste',59,'135','demo_inicial',49,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,49,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,49,'ajuste',17,'135','demo_inicial',49,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,49,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (50,9,'ROP-0050','Negro / M','135','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (50,'9902000000503',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,50,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,50,'ajuste',17,'135','demo_inicial',50,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,50,60);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,50,'ajuste',60,'135','demo_inicial',50,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,50,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,50,'ajuste',8,'135','demo_inicial',50,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,50,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (51,9,'ROP-0051','Negro / G','135','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (51,'9902000000510',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,51,18);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,51,'ajuste',18,'135','demo_inicial',51,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,51,61);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,51,'ajuste',61,'135','demo_inicial',51,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,51,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,51,'ajuste',9,'135','demo_inicial',51,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,51,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (52,9,'ROP-0052','Azul / CH','135','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (52,'9902000000527',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,52,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,52,62);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,52,'ajuste',62,'135','demo_inicial',52,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,52,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,52,'ajuste',10,'135','demo_inicial',52,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,52,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (53,9,'ROP-0053','Azul / M','135','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (53,'9902000000534',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,53,20);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,53,'ajuste',20,'135','demo_inicial',53,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,53,63);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,53,'ajuste',63,'135','demo_inicial',53,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,53,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,53,'ajuste',11,'135','demo_inicial',53,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,53,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (54,9,'ROP-0054','Azul / G','135','299.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (54,'9902000000541',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,54,21);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,54,'ajuste',21,'135','demo_inicial',54,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,54,64);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,54,'ajuste',64,'135','demo_inicial',54,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,54,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,54,'ajuste',12,'135','demo_inicial',54,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,54,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (10,'Sudadera ligera','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',2,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (55,10,'ROP-0055','Negro / CH','230','499.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (55,'9902000000558',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,55,22);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,55,'ajuste',22,'230','demo_inicial',55,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,55,65);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,55,'ajuste',65,'230','demo_inicial',55,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,55,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,55,'ajuste',13,'230','demo_inicial',55,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,55,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (56,10,'ROP-0056','Negro / M','230','499.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (56,'9902000000565',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,56,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,56,'ajuste',2,'230','demo_inicial',56,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,56,66);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,56,'ajuste',66,'230','demo_inicial',56,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,56,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,56,'ajuste',14,'230','demo_inicial',56,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,56,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (57,10,'ROP-0057','Negro / G','230','499.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (57,'9902000000572',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,57,24);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,57,'ajuste',24,'230','demo_inicial',57,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,57,67);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,57,'ajuste',67,'230','demo_inicial',57,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,57,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,57,'ajuste',15,'230','demo_inicial',57,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,57,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (58,10,'ROP-0058','Azul / CH','230','499.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (58,'9902000000589',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,58,25);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,58,'ajuste',25,'230','demo_inicial',58,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,58,68);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,58,'ajuste',68,'230','demo_inicial',58,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,58,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,58,'ajuste',16,'230','demo_inicial',58,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,58,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (59,10,'ROP-0059','Azul / M','230','499.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (59,'9902000000596',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,59,26);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,59,'ajuste',26,'230','demo_inicial',59,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,59,69);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,59,'ajuste',69,'230','demo_inicial',59,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,59,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,59,'ajuste',17,'230','demo_inicial',59,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,59,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (60,10,'ROP-0060','Azul / G','230','499.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (60,'9902000000602',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,60,27);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,60,'ajuste',27,'230','demo_inicial',60,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,60,40);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,60,'ajuste',40,'230','demo_inicial',60,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,60,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,60,'ajuste',8,'230','demo_inicial',60,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,60,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (11,'Bermuda casual','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',2,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (61,11,'ROP-0061','Negro / CH','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (61,'9902000000619',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,61,28);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,61,'ajuste',28,'160','demo_inicial',61,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,61,41);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,61,'ajuste',41,'160','demo_inicial',61,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,61,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,61,'ajuste',9,'160','demo_inicial',61,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,61,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (62,11,'ROP-0062','Negro / M','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (62,'9902000000626',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,62,29);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,62,'ajuste',29,'160','demo_inicial',62,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,62,42);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,62,'ajuste',42,'160','demo_inicial',62,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,62,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,62,'ajuste',10,'160','demo_inicial',62,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,62,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (63,11,'ROP-0063','Negro / G','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (63,'9902000000633',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,63,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,63,'ajuste',2,'160','demo_inicial',63,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,63,43);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,63,'ajuste',43,'160','demo_inicial',63,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,63,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,63,'ajuste',11,'160','demo_inicial',63,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,63,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (64,11,'ROP-0064','Azul / CH','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (64,'9902000000640',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,64,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,64,'ajuste',15,'160','demo_inicial',64,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,64,44);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,64,'ajuste',44,'160','demo_inicial',64,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,64,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,64,'ajuste',12,'160','demo_inicial',64,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,64,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (65,11,'ROP-0065','Azul / M','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (65,'9902000000657',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,65,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,65,45);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,65,'ajuste',45,'160','demo_inicial',65,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,65,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,65,'ajuste',13,'160','demo_inicial',65,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,65,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (66,11,'ROP-0066','Azul / G','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (66,'9902000000664',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,66,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,66,'ajuste',17,'160','demo_inicial',66,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,66,46);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,66,'ajuste',46,'160','demo_inicial',66,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,66,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,66,'ajuste',14,'160','demo_inicial',66,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,66,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (12,'Playera infantil','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',3,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (67,12,'ROP-0067','Rojo / 6','60','149.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (67,'9902000000671',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,67,18);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,67,'ajuste',18,'60','demo_inicial',67,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,67,47);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,67,'ajuste',47,'60','demo_inicial',67,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,67,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,67,'ajuste',15,'60','demo_inicial',67,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,67,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (68,12,'ROP-0068','Azul / 8','60','149.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (68,'9902000000688',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,68,19);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,68,'ajuste',19,'60','demo_inicial',68,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,68,48);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,68,'ajuste',48,'60','demo_inicial',68,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,68,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,68,'ajuste',16,'60','demo_inicial',68,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,68,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (69,12,'ROP-0069','Verde / 10','60','149.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (69,'9902000000695',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,69,20);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,69,'ajuste',20,'60','demo_inicial',69,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,69,49);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,69,'ajuste',49,'60','demo_inicial',69,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,69,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,69,'ajuste',17,'60','demo_inicial',69,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,69,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (13,'Pantalón infantil','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',3,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (70,13,'ROP-0070','Rojo / 6','120','279.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (70,'9902000000701',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,70,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,70,'ajuste',2,'120','demo_inicial',70,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,70,50);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,70,'ajuste',50,'120','demo_inicial',70,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,70,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,70,'ajuste',8,'120','demo_inicial',70,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,70,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (71,13,'ROP-0071','Azul / 8','120','279.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (71,'9902000000718',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,71,22);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,71,'ajuste',22,'120','demo_inicial',71,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,71,51);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,71,'ajuste',51,'120','demo_inicial',71,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,71,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,71,'ajuste',9,'120','demo_inicial',71,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,71,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (72,13,'ROP-0072','Verde / 10','120','279.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (72,'9902000000725',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,72,23);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,72,'ajuste',23,'120','demo_inicial',72,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,72,52);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,72,'ajuste',52,'120','demo_inicial',72,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,72,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,72,'ajuste',10,'120','demo_inicial',72,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,72,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (14,'Vestido infantil','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',3,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (73,14,'ROP-0073','Rojo / 6','145','329.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (73,'9902000000732',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,73,24);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,73,'ajuste',24,'145','demo_inicial',73,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,73,53);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,73,'ajuste',53,'145','demo_inicial',73,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,73,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,73,'ajuste',11,'145','demo_inicial',73,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,73,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (74,14,'ROP-0074','Azul / 8','145','329.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (74,'9902000000749',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,74,25);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,74,'ajuste',25,'145','demo_inicial',74,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,74,54);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,74,'ajuste',54,'145','demo_inicial',74,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,74,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,74,'ajuste',12,'145','demo_inicial',74,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,74,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (75,14,'ROP-0075','Verde / 10','145','329.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (75,'9902000000756',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,75,26);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,75,'ajuste',26,'145','demo_inicial',75,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,75,55);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,75,'ajuste',55,'145','demo_inicial',75,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,75,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,75,'ajuste',13,'145','demo_inicial',75,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,75,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (15,'Tenis urbano','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',4,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (76,15,'ROP-0076','Negro / 24','340','699.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (76,'9902000000763',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,76,27);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,76,'ajuste',27,'340','demo_inicial',76,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,76,56);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,76,'ajuste',56,'340','demo_inicial',76,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,76,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,76,'ajuste',14,'340','demo_inicial',76,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,76,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (77,15,'ROP-0077','Negro / 25','340','699.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (77,'9902000000770',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,77,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,77,'ajuste',2,'340','demo_inicial',77,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,77,57);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,77,'ajuste',57,'340','demo_inicial',77,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,77,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,77,'ajuste',15,'340','demo_inicial',77,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,77,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (78,15,'ROP-0078','Negro / 26','340','699.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (78,'9902000000787',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,78,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,78,58);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,78,'ajuste',58,'340','demo_inicial',78,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,78,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,78,'ajuste',16,'340','demo_inicial',78,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,78,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (79,15,'ROP-0079','Negro / 27','340','699.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (79,'9902000000794',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,79,30);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,79,'ajuste',30,'340','demo_inicial',79,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,79,59);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,79,'ajuste',59,'340','demo_inicial',79,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,79,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,79,'ajuste',17,'340','demo_inicial',79,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,79,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (16,'Sandalia casual','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',4,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (80,16,'ROP-0080','Negro / 24','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (80,'9902000000800',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,80,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,80,'ajuste',15,'160','demo_inicial',80,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,80,60);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,80,'ajuste',60,'160','demo_inicial',80,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,80,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,80,'ajuste',8,'160','demo_inicial',80,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,80,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (81,16,'ROP-0081','Negro / 25','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (81,'9902000000817',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,81,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,81,'ajuste',16,'160','demo_inicial',81,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,81,61);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,81,'ajuste',61,'160','demo_inicial',81,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,81,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,81,'ajuste',9,'160','demo_inicial',81,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,81,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (82,16,'ROP-0082','Negro / 26','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (82,'9902000000824',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,82,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,82,'ajuste',17,'160','demo_inicial',82,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,82,62);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,82,'ajuste',62,'160','demo_inicial',82,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,82,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,82,'ajuste',10,'160','demo_inicial',82,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,82,5,1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (83,16,'ROP-0083','Negro / 27','160','349.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (83,'9902000000831',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,83,18);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,83,'ajuste',18,'160','demo_inicial',83,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,83,63);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,83,'ajuste',63,'160','demo_inicial',83,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,83,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,83,'ajuste',11,'160','demo_inicial',83,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,83,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (17,'Bolso de mano','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (84,17,'ROP-0084','Única','205','449.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (84,'9902000000848',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,84,2);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,84,'ajuste',2,'205','demo_inicial',84,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,84,64);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,84,'ajuste',64,'205','demo_inicial',84,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,84,12);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,84,'ajuste',12,'205','demo_inicial',84,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,84,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (18,'Mochila urbana','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (85,18,'ROP-0085','Única','285','599.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (85,'9902000000855',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,85,20);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,85,'ajuste',20,'285','demo_inicial',85,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,85,65);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,85,'ajuste',65,'285','demo_inicial',85,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,85,13);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,85,'ajuste',13,'285','demo_inicial',85,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,85,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (19,'Cartera compacta','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (86,19,'ROP-0086','Única','80','199.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (86,'9902000000862',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,86,21);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,86,'ajuste',21,'80','demo_inicial',86,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,86,66);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,86,'ajuste',66,'80','demo_inicial',86,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,86,14);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,86,'ajuste',14,'80','demo_inicial',86,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,86,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (20,'Cinturón sintético','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (87,20,'ROP-0087','Única','65','169.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (87,'9902000000879',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,87,22);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,87,'ajuste',22,'65','demo_inicial',87,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,87,67);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,87,'ajuste',67,'65','demo_inicial',87,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,87,15);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,87,'ajuste',15,'65','demo_inicial',87,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,87,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (21,'Gorra lisa','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (88,21,'ROP-0088','Única','60','159.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (88,'9902000000886',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,88,23);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,88,'ajuste',23,'60','demo_inicial',88,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,88,68);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,88,'ajuste',68,'60','demo_inicial',88,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,88,16);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,88,'ajuste',16,'60','demo_inicial',88,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,88,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (22,'Lentes de sol de moda','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (89,22,'ROP-0089','Única','45','129.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (89,'9902000000893',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,89,24);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,89,'ajuste',24,'45','demo_inicial',89,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,89,69);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,89,'ajuste',69,'45','demo_inicial',89,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,89,17);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,89,'ajuste',17,'45','demo_inicial',89,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,89,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (23,'Collar fantasía','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (90,23,'ROP-0090','Única','28','89.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (90,'9902000000909',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,90,25);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,90,'ajuste',25,'28','demo_inicial',90,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,90,40);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,90,'ajuste',40,'28','demo_inicial',90,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,90,8);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,90,'ajuste',8,'28','demo_inicial',90,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,90,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (24,'Aretes fantasía','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (91,24,'ROP-0091','Única','20','69.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (91,'9902000000916',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,91,0);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,91,41);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,91,'ajuste',41,'20','demo_inicial',91,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,91,9);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,91,'ajuste',9,'20','demo_inicial',91,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,91,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (25,'Pulsera textil','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (92,25,'ROP-0092','Única','12','49.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (92,'9902000000923',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,92,27);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,92,'ajuste',27,'12','demo_inicial',92,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,92,42);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,92,'ajuste',42,'12','demo_inicial',92,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,92,10);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,92,'ajuste',10,'12','demo_inicial',92,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,92,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (26,'Calcetines paquete de 3','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',5,1,1,'estandar',1);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (93,26,'ROP-0093','Única','40','99.0000',5);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (93,'9902000000930',1,1);
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (1,93,28);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (1,93,'ajuste',28,'40','demo_inicial',93,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (2,93,43);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (2,93,'ajuste',43,'40','demo_inicial',93,1,'Inventario inicial ficticio ropa');
INSERT INTO `existencias` (`ubicacion_id`,`variante_id`,`cantidad`) VALUES (3,93,11);
INSERT INTO `movimientos_inventario` (`ubicacion_id`,`variante_id`,`tipo_movimiento`,`cantidad`,`costo_unitario`,`tipo_referencia`,`referencia_id`,`usuario_id`,`notas`) VALUES (3,93,'ajuste',11,'40','demo_inicial',93,1,'Inventario inicial ficticio ropa');
INSERT INTO `minimos_inventario` (`ubicacion_id`,`variante_id`,`minimo`,`usuario_id`) VALUES (1,93,5,1);
INSERT INTO `productos` (`id`,`nombre`,`descripcion`,`categoria_id`,`unidad_base_id`,`impuesto_id`,`tipo_producto`,`controla_inventario`) VALUES (27,'Ajuste de bastilla','Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',6,5,1,'servicio',0);
INSERT INTO `variantes_producto` (`id`,`producto_id`,`sku`,`nombre`,`costo`,`precio`,`stock_minimo`) VALUES (94,27,'ROP-0094','Única','20','60.0000',0);
INSERT INTO `codigos_barras` (`variante_id`,`codigo`,`unidad_id`,`cantidad`) VALUES (94,'9902000000947',5,1);
COMMIT;
SELECT 'DEMO lista: pos_demo_ropa. Usuario admin / Admin1.1' AS resultado;
