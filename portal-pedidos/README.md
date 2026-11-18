# Portal de clientes en PHP / AppServ

El portal actual se atiende con PHP y MySQL, en la misma instalación del POS.
No necesita Node, npm, SQLite, una clave de conexión ni publicar el catálogo.

- Clientes: http://localhost:8080/PuntoVenta/public/pedidos-clientes/
- Administración y empleados: http://localhost:8080/PuntoVenta/public/

`abrir-portal.cmd` ahora solamente abre la dirección de AppServ. El código de
worker/, scripts/, drizzle/ y package.json corresponde al prototipo anterior y
se conserva como referencia; no se instala ni se ejecuta para usar el portal PHP.

## Cómo funciona

El catálogo consulta los productos activos y precios actuales de MySQL, incluso
sin existencias. Mantiene mosaicos, categorías, búsqueda, galería y cotizaciones.
Las fotos se sirven desde una ruta pública limitada a imágenes de productos
activos; no se exponen costos, saldos, existencias por ubicación ni claves.

El cliente envía nombre, celular, notas y artículos. Se valida el catálogo vigente
y se recalculan los importes en servidor. Los pedidos llegan directamente a
Pedidos guardados del POS, sin sincronización por otro puerto. Un cambio de precio
obliga a revisar el catálogo antes de confirmar un envío nuevo; el reintento de
una solicitud ya guardada recupera su mismo folio. El recibo público contiene solo
folio, identificador, fecha e importe conocido, sin listado ni consulta de contactos.

Las cotizaciones se marcan en Pedidos > Pedidos guardados > Cotizaciones del portal.
Guardar refleja el cambio al actualizar la página del cliente. No hay botón de
publicar. Antes de pasar a venta se exige un precio acordado para cada artículo
por cotizar. Pedir no reserva inventario ni mueve caja; la venta conserva las
validaciones habituales y se completa en la caja del administrador.

El celular permite contactar al cliente por medios habituales; no tiene verificación
SMS ni notificaciones automáticas. Esta versión contempla recogida y pago en tienda.

## Actualizar otra máquina

Consulta ACTUALIZAR_OTRA_MAQUINA.txt en la raíz. Después de respaldar y hacer git
pull, ejecuta desde la raíz:

```powershell
php scripts/pedidos/migrar-a-php.php
```

El script instala las tablas y columnas de las migraciones 020 a 023. Si existe
portal-pedidos/.local/portal.sqlite, verifica que pertenezca a la instalación y
conserva los pedidos faltantes, sin duplicar ni sobrescribir los que ya están en
MySQL. No borra la base anterior. Importar SQLite antiguo requiere PDO SQLite en
PHP CLI; el portal PHP en funcionamiento usa solamente PDO MySQL.

En AppServ puedes usar scripts/pedidos/actualizar-appserv.cmd, que localiza PHP y
habilita las extensiones de AppServ para ejecutar la migración. En este equipo
la migración ya está aplicada, el pedido anterior está conservado y Node se detuvo.

No copies .dev.vars, .local ni credenciales a Git. Para trasladar datos reales del
negocio utiliza Respaldos; Git transporta el código y las migraciones, no las ventas.
Los nuevos pedidos web se guardan en MySQL y forman parte del respaldo normal del
POS. Conserva la copia SQLite antigua hasta verificar la migración.

## Acceso desde celular y publicación

En el mismo Wi-Fi, cambia localhost por la IPv4 de la PC:
http://IP-DE-LA-PC:8080/PuntoVenta/public/pedidos-clientes/
AppServ debe estar iniciado y Windows permitir ese puerto en la red privada.
No es un enlace de internet.

Al publicar en un servidor PHP/MySQL con HTTPS, manteniendo la ruta actual:

- Clientes: https://tu-dominio.example/PuntoVenta/public/pedidos-clientes/
- Personal: https://tu-dominio.example/PuntoVenta/public/

Estos dominios son ejemplos. Comparte el enlace de clientes por WhatsApp, redes o
QR. Administración y empleados usan cuentas individuales y permisos de rol; el
portal público no concede acceso al POS. El esquema de rutas sigue siendo
/PuntoVenta/public; cambiar la carpeta base requiere adaptar las rutas del proyecto.
No expongas app/, storage/, scripts/, .git ni archivos privados: el .htaccess de la
raíz bloquea lo que está fuera de public y debe mantenerse activo en Apache.
Configura la base de datos del servidor y verifica la instalación con una prueba
antes de compartir enlaces. Al publicar no hay que abrir ni usar el puerto 8788.
Todavía no se ha publicado una instalación de internet desde este proyecto.

## Pruebas sin Node

```powershell
python tests/portal_php_http.py
```

Prueba una base MySQL temporal y un servidor PHP temporal, elimina ambos al terminar.
Verifica 51 solicitudes HTTP y 8 comprobaciones de pedido a venta: privacidad,
permisos de rutas, totales, cotizaciones, reintentos, versiones, validación de origen,
límite por IP, presencia inmediata en el POS y cobro único sin mover stock al pedir.
Con --ui deja la base temporal para revisión hasta pulsar Enter. Las pruebas del
prototipo Node continúan disponibles por separado como comprobación de compatibilidad.
