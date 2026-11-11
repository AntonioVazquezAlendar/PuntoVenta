# Bases de prueba por giro

Listas para esta instalación de PuntoVenta en AppServ / MySQL 8. Todos los datos son ficticios. Esquema completo de 47 tablas, incluidas las migraciones hasta 019 y la configuración administrativa actual.

## Ejecutar con doble clic

Desde esta carpeta, ejecuta el archivo del giro:

| Archivo | Base independiente | Productos | Variantes |
| --- | --- | ---: | ---: |
| abarrotes.cmd | pos_demo_abarrotes | 33 | 33 |
| ropa.cmd | pos_demo_ropa | 27 | 94 |
| agroquimica.cmd | pos_demo_agroquimica | 26 | 26 |
| restaurante.cmd | pos_demo_restaurante | 29 | 39 |
| ferreteria.cmd | pos_demo_ferreteria | 33 | 33 |

El archivo crea la base si falta y la selecciona para esta instalación. Si ya existe, conserva tus pruebas y solo la selecciona; no reinicia ni borra datos. No elimina bases ante errores. Una importación parcial se conserva para revisar; no se activa si no coincide con el esquema esperado.

Después abre [PuntoVenta](http://localhost:8080/PuntoVenta/public/), recarga con Ctrl + F5 e inicia sesión. La selección aplica a toda esta instalación, no a una sola pestaña. El cambio invalida las sesiones anteriores; no cambies de giro durante una operación de caja. El nombre del negocio incluye DEMO para reconocerlo.

Para regresar a tu base original, ejecuta **volver_original.cmd**. Las demos se conservan para retomarlas. La base original configurada en Database.php no se reemplaza ni se cambia su nombre.

## Usuarios

**Contraseña de todas las cuentas, en todas las bases: `Admin1.1`** (8 caracteres).

| Usuario | Rol | Qué probar |
| --- | --- | --- |
| admin | Administrador | Configuración, usuarios, permisos, bitácora y operación completa |
| gerente | Gerente | Operación y supervisión; sin facultades de administrador |
| cajero | Cajero | Venta, caja propia, crédito, abonos y gastos; solicita autorización administrativa para devoluciones/cancelaciones |
| cajero2 | Cajero | Segunda caja y comparación de ventas entre usuarios |
| almacen | Almacenista | Ajustes, existencias, mínimos, traspasos y mermas |
| compras | Compras | Proveedores, compras, recepción y pagos externos |
| consulta | Consulta | Productos, inventario y reportes permitidos, sin cambios |

Los reportes y las ventas conservan los alcances reales del sistema: solo el administrador consulta todas las ventas; el permiso de reportes de otros roles no concede esa vista global. Estas contraseñas compartidas se usan únicamente para los escenarios ficticios de prueba.

## Qué incluyen

Cada base contiene 7 cuentas, 6 roles, 12 clientes, 5 proveedores, 4 ubicaciones (Tienda, Bodega, Sucursal Norte y Dañados y revisión), 3 cajas, 6 métodos de pago y categorías de gastos. Público en general es el cliente predeterminado; los clientes 2, 3 y 4 tienen crédito habilitado, límite de $5,000 y plazo de 15/30 días, sin deuda inicial.

Inventario inicial con movimientos coincidentes, stock repartido, casos de agotado/bajo mínimo, mínimos locales y un ejemplo en resguardo. Las cajas inician cerradas: entra con cajero y abre Caja 1; cajero2 puede abrir Caja 2. Los servicios no llevan existencias. No se precargan compras, ventas, deudas ni cortes: así puedes seguir el ciclo completo desde cero.

Ropa incluye tallas y colores; restaurante incluye pizzas de distintos tamaños y vista Mosaico; los demás giros usan Búsqueda. Hay artículos por kilo y metro para probar decimales y códigos sintéticos EAN-13 para las variantes, sin imágenes precargadas.

Los precios, costos y tasas son ilustrativos (IVA DEMO 16%); no representan una clasificación fiscal real. Los insumos agroquímicos son nombres ficticios, sin recomendaciones de aplicación. El sistema actual no administra lotes/caducidades, recetas, consumo automático de ingredientes, comandas ni mesas: el restaurante prueba venta directa y existencias del artículo vendido.

## Archivos SQL y terminal

Cada archivo .sql es autónomo: crea su base, las 47 tablas y todos los datos. Puede importarse desde MySQL o phpMyAdmin con permisos para crear bases. No lo importes encima de una base existente. Importar SQL no cambia la selección de la aplicación: usa el .cmd correspondiente para seleccionarla después.

Desde la raíz del proyecto, también puedes ejecutar:

```powershell
php -d extension_dir=C:/AppServ/php7/ext -d extension=php_pdo_mysql.dll scripts/demos/instalar.php abarrotes --activar
```

Cambia `abarrotes` por `ropa`, `agroquimica`, `restaurante` o `ferreteria`. Sin `--activar` solo crea/verifica la base, manteniendo la selección actual.

```powershell
php -d extension_dir=C:/AppServ/php7/ext -d extension=php_pdo_mysql.dll scripts/demos/instalar.php --original
```

La conexión conserva el servidor y las credenciales ya configuradas. La selección local se guarda en storage/demo_database.json (excluido de Git); no se sobrescribe Database.php ni se copian registros del negocio original. El bloqueo existente de operaciones impide cambiar de base durante una operación en curso o una recuperación pendiente de respaldos.

## Validación y regeneración

`tests/demos.php` importa los cinco SQL bajo nombres qa_demoseed_* aleatorios y comprueba contraseñas, permisos, configuración, existencias contra movimientos, referencias internas, una venta idempotente y corte. Elimina únicamente esas bases temporales. `tests/demos_instalador.py` prueba activación y regreso con una copia temporal de la configuración; no cambia la selección ni las sesiones reales. Requiere pos_demo_abarrotes instalada sin activar.

`generar.py` regenera los cinco SQL desde el esquema sin datos de esquema.json y los catálogos ficticios contenidos en el generador. Necesita Python y PHP en PATH. Los scripts entregados ya están generados: no necesitas ejecutar el generador para probar.
