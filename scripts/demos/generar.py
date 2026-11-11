"""Genera demos MySQL 8 autónomas desde un esquema sin datos y catálogos ficticios."""
from pathlib import Path
import json,subprocess,re
ROOT=Path(__file__).resolve().parents[2];HERE=Path(__file__).resolve().parent
SCHEMA=json.loads((HERE/'esquema.json').read_text(encoding='utf-8'))
def rows(text):
    return [line.split('|') for line in text.strip().splitlines()]
CATALOGOS={
'abarrotes':('Minisúper La Esquina DEMO','#226344','busqueda',rows('''Despensa|Arroz campo 1 kg|pza|32|21
Despensa|Frijol negro 1 kg|pza|42|29
Despensa|Azúcar 1 kg|pza|30|22
Despensa|Aceite vegetal 1 L|pza|48|35
Despensa|Pasta corta 200 g|pza|12|8
Despensa|Harina de trigo 1 kg|pza|25|17
Despensa|Sal de mesa 1 kg|pza|16|10
Despensa|Atún en agua 140 g|pza|23|16
Despensa|Sopa instantánea|pza|18|12
Bebidas|Agua natural 600 ml|pza|12|6
Bebidas|Agua natural 1.5 L|pza|19|11
Bebidas|Refresco naranja 2 L|pza|34|24
Bebidas|Jugo de manzana 1 L|pza|29|19
Bebidas|Café soluble 100 g|pza|68|45
Lácteos|Leche entera 1 L|pza|28|21
Lácteos|Yogur natural 900 g|pza|44|30
Lácteos|Queso fresco a granel|kg|145|100
Lácteos|Crema 250 ml|pza|29|19
Botanas|Papas saladas 45 g|pza|18|11
Botanas|Galletas de avena 160 g|pza|24|16
Botanas|Chocolate de mesa 90 g|pza|22|14
Botanas|Cacahuate salado 100 g|pza|20|12
Limpieza|Detergente en polvo 1 kg|pza|43|29
Limpieza|Cloro 1 L|pza|18|10
Limpieza|Jabón para trastes 750 ml|pza|32|21
Limpieza|Papel higiénico 4 rollos|pza|39|26
Higiene|Jabón de tocador 120 g|pza|18|11
Higiene|Pasta dental 100 ml|pza|38|25
Granel|Huevo a granel|kg|48|34
Granel|Lenteja a granel|kg|44|29
Granel|Alimento para perro|kg|52|35
Granel|Croqueta para gato|kg|65|43
Servicios|Entrega local|serv|20|8''')),
'ropa':('Moda Nube y Accesorios DEMO','#73405C','busqueda',rows('''Dama|Blusa lino|pza|299|145
Dama|Vestido casual|pza|599|280
Dama|Pantalón mezclilla dama|pza|549|260
Dama|Falda midi|pza|399|180
Dama|Playera básica dama|pza|179|75
Dama|Suéter tejido|pza|449|210
Caballero|Camisa manga larga|pza|399|190
Caballero|Pantalón mezclilla caballero|pza|579|275
Caballero|Playera polo|pza|299|135
Caballero|Sudadera ligera|pza|499|230
Caballero|Bermuda casual|pza|349|160
Infantil|Playera infantil|pza|149|60
Infantil|Pantalón infantil|pza|279|120
Infantil|Vestido infantil|pza|329|145
Calzado|Tenis urbano|pza|699|340
Calzado|Sandalia casual|pza|349|160
Accesorios|Bolso de mano|pza|449|205
Accesorios|Mochila urbana|pza|599|285
Accesorios|Cartera compacta|pza|199|80
Accesorios|Cinturón sintético|pza|169|65
Accesorios|Gorra lisa|pza|159|60
Accesorios|Lentes de sol de moda|pza|129|45
Accesorios|Collar fantasía|pza|89|28
Accesorios|Aretes fantasía|pza|69|20
Accesorios|Pulsera textil|pza|49|12
Accesorios|Calcetines paquete de 3|pza|99|40
Servicios|Ajuste de bastilla|serv|60|20''')),
'agroquimica':('Agroinsumos Campo Claro DEMO','#526B27','busqueda',rows('''Fertilizantes|Fertilizante mezcla A saco 25 kg|pza|620|440
Fertilizantes|Fertilizante mezcla B saco 25 kg|pza|710|510
Fertilizantes|Nutriente granular saco 50 kg|pza|980|720
Fertilizantes|Nutriente foliar muestra 1 L|pza|210|140
Fertilizantes|Calcio agrícola saco 20 kg|pza|260|170
Fertilizantes|Magnesio agrícola saco 20 kg|pza|320|215
Fertilizantes|Nutriente granular a granel|kg|24|15
Fertilizantes|Sustrato enriquecido a granel|kg|16|9
Tratamientos DEMO|Tratamiento vegetal ficticio A 1 L|pza|390|260
Tratamientos DEMO|Tratamiento vegetal ficticio B 500 ml|pza|245|155
Tratamientos DEMO|Coadyuvante ficticio 1 L|pza|150|95
Tratamientos DEMO|Protector vegetal ficticio 1 kg|pza|340|220
Semillas|Semilla hortícola sobre chico|pza|35|18
Semillas|Semilla de maíz bolsa DEMO|pza|1850|1390
Semillas|Semilla de pasto a granel|kg|135|90
Riego|Manguera de riego|m|18|10
Riego|Conector para riego 1/2|pza|12|5
Riego|Gotero ajustable|pza|7|2
Riego|Cinta de riego rollo|pza|850|610
Equipo|Aspersor manual 5 L|pza|490|320
Equipo|Bomba de mochila 20 L|pza|1290|880
Equipo|Boquilla de repuesto|pza|45|20
Protección|Guante de trabajo agrícola|pza|85|42
Protección|Lente de protección|pza|75|35
Protección|Overol de trabajo|pza|390|230
Servicios|Entrega de insumos|serv|150|70''')),
'restaurante':('Café y Pizzería La Terraza DEMO','#9A482C','mosaico',rows('''Pizzas|Pizza margarita|pza|129|55
Pizzas|Pizza de champiñones|pza|149|65
Pizzas|Pizza vegetariana|pza|149|62
Pizzas|Pizza cuatro quesos|pza|179|82
Pizzas|Pizza de la casa|pza|189|88
Cafetería|Café americano chico|pza|32|9
Cafetería|Café americano grande|pza|42|12
Cafetería|Cappuccino|pza|49|17
Cafetería|Latte|pza|52|19
Cafetería|Chocolate caliente|pza|48|18
Cafetería|Té de manzanilla|pza|29|7
Bebidas frías|Agua natural botella|pza|20|8
Bebidas frías|Limonada 500 ml|pza|39|12
Bebidas frías|Té helado 500 ml|pza|35|10
Bebidas frías|Frappé de vainilla|pza|65|24
Alimentos|Hamburguesa clásica|pza|99|42
Alimentos|Sándwich de pollo|pza|79|31
Alimentos|Ensalada de la casa|pza|89|34
Alimentos|Papas a la francesa|pza|49|18
Alimentos|Pasta con tomate|pza|109|41
Desayunos|Molletes|pza|69|24
Desayunos|Huevos con frijoles|pza|85|30
Desayunos|Hot cakes|pza|79|27
Postres|Panqué de naranja|pza|35|12
Postres|Rebanada de pastel|pza|59|22
Postres|Galleta de avena|pza|19|5
Insumos|Café en grano para venta|kg|320|220
Insumos|Vaso térmico paquete 25|pza|69|42
Servicios|Entrega a domicilio|serv|35|15''')),
'ferreteria':('Ferretería Tornillo Firme DEMO','#425A78','busqueda',rows('''Herramienta|Martillo uña 16 oz|pza|185|110
Herramienta|Desarmador plano 1/4|pza|55|27
Herramienta|Desarmador de cruz|pza|59|29
Herramienta|Pinza universal 8 pulgadas|pza|149|83
Herramienta|Llave ajustable 10 pulgadas|pza|199|115
Herramienta|Flexómetro 5 m|pza|99|49
Herramienta|Taladro doméstico 500 W|pza|899|610
Herramienta|Broca concreto 1/4|pza|35|16
Tornillería|Tornillo madera a granel|kg|110|68
Tornillería|Clavo estándar a granel|kg|65|38
Tornillería|Tuerca hexagonal 1/4|pza|3|1
Tornillería|Taquete plástico paquete 50|pza|39|18
Plomería|Tubo PVC 1/2 por metro|m|22|12
Plomería|Codo PVC 1/2|pza|6|2
Plomería|Cople PVC 1/2|pza|5|2
Plomería|Llave para lavabo|pza|249|145
Plomería|Cinta selladora|pza|12|5
Eléctrico|Cable calibre 12|m|19|11
Eléctrico|Cable calibre 14|m|14|8
Eléctrico|Contacto doble|pza|35|17
Eléctrico|Apagador sencillo|pza|29|14
Eléctrico|Foco LED 9 W|pza|39|21
Eléctrico|Cinta aislante|pza|19|8
Pintura|Pintura vinílica blanca 1 L|pza|119|75
Pintura|Pintura vinílica blanca 19 L|pza|1399|980
Pintura|Brocha 2 pulgadas|pza|39|18
Pintura|Rodillo 9 pulgadas|pza|89|45
Construcción|Cemento saco 25 kg DEMO|pza|139|104
Construcción|Yeso saco 20 kg|pza|99|67
Seguridad|Guantes de trabajo|pza|59|29
Seguridad|Lentes de seguridad|pza|69|32
Seguridad|Candado 40 mm|pza|95|49
Servicios|Corte de material|serv|25|8''')),
}
def q(v):
    if v is None:return 'NULL'
    if isinstance(v,(int,float)):return str(v)
    if isinstance(v,(list,dict)):v=json.dumps(v,ensure_ascii=False,separators=(',',':'))
    return "'"+str(v).replace('\\','\\\\').replace("'","''").replace('\n','\\n')+"'"
def build(giro,info,index,password_hash,permissions):
    name,color,view,products=info;db='pos_demo_'+giro
    out=['-- DATOS FICTICIOS. MySQL 8. Esquema completo de PuntoVenta.','-- No elimina ni reemplaza bases existentes. Todas las cuentas: Admin1.1.','SET NAMES utf8mb4;','CREATE DATABASE `'+db+'` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;','USE `'+db+'`;','SET FOREIGN_KEY_CHECKS=0;']
    out.extend(ddl+';' for ddl in SCHEMA.values());out+=['SET FOREIGN_KEY_CHECKS=1;','START TRANSACTION;']
    def ins(t,**d):out.append('INSERT INTO `'+t+'` (`'+'`,`'.join(d)+'`) VALUES ('+','.join(q(v) for v in d.values())+');')
    cashier={'vender','ver_ventas','operar_caja','hacer_devoluciones','cancelar_venta','ver_clientes','vender_credito','ver_creditos','cobrar_abonos','ver_gastos','registrar_gastos'}
    warehouse={'ver_productos','ver_inventario','ver_alertas_inventario','configurar_minimos','ajustar_inventario','administrar_ubicaciones','realizar_traspasos','registrar_mermas'}
    buyer={'ver_productos','ver_inventario','ver_alertas_inventario','hacer_compras','ver_cuentas_pagar','pagar_proveedores'}
    roles=[('Administrador',None),('Gerente',set(permissions)),('Cajero',cashier),('Almacenista',warehouse),('Compras',buyer),('Consulta',{'ver_productos','ver_inventario','ver_alertas_inventario','ver_reportes'})]
    for i,(label,allowed) in enumerate(roles,1):ins('roles',id=i,nombre=label,permisos={'todo':True} if allowed is None else {k:k in allowed for k in permissions})
    accounts=[('admin','Administrador DEMO',1),('gerente','Mariana Robles DEMO',2),('cajero','Luis Medina DEMO',3),('cajero2','Ana Torres DEMO',3),('almacen','Diego Campos DEMO',4),('compras','Elena Soto DEMO',5),('consulta','Pablo Luna DEMO',6)]
    for i,(login,person,role) in enumerate(accounts,1):ins('usuarios',id=i,rol_id=role,nombre=person,nombre_usuario=login,contrasena_hash=password_hash);ins('preferencias_pdv',usuario_id=i,vista=view)
    ins('empresa',id=1,nombre_comercial=name,razon_social='Comercio Ficticio de Pruebas',telefono='5550000000',correo='contacto@example.test',direccion='Calle de Prueba 123, Colonia Ejemplo',codigo_postal='01000',color_primario=color,color_secundario=color,color_acento='#D5A64A',encabezado_ticket='DEMO - Datos ficticios, sin valor comercial',pie_ticket='Gracias por probar '+name,moneda='MXN',zona_horaria='America/Mexico_City')
    units={'pza':1,'kg':2,'lt':3,'m':4,'serv':5}
    for code,i in units.items():ins('unidades',id=i,codigo=code,nombre={'pza':'Pieza','kg':'Kilogramo','lt':'Litro','m':'Metro','serv':'Servicio'}[code],permite_decimales=int(code in ('kg','lt','m')))
    ins('impuestos',id=1,nombre='IVA 16% DEMO',tasa='0.1600');ins('impuestos',id=2,nombre='Tasa cero DEMO',tasa='0.0000')
    for i,(label,kind) in enumerate([('Efectivo','efectivo'),('Tarjeta de débito','tarjeta'),('Tarjeta de crédito','tarjeta'),('Transferencia','transferencia'),('Crédito del cliente','credito'),('Vales','vales')],1):ins('metodos_pago',id=i,nombre=label,tipo=kind)
    for i,(label,sellable) in enumerate([('Tienda',1),('Bodega',1),('Sucursal Norte',1),('Dañados y revisión',0)],1):ins('ubicaciones',id=i,nombre=label,disponible_venta=sellable,descripcion='Ubicación ficticia del escenario '+giro)
    for i,loc in [(1,1),(2,1),(3,3)]:ins('cajas',id=i,ubicacion_id=loc,nombre='Caja '+str(i))
    ins('clientes',id=1,nombre='Público en general DEMO',notas='Cliente predeterminado de pruebas')
    ins('configuracion_clientes',id=1,cliente_predeterminado_id=1)
    for i,person in enumerate(['Sofía Castillo','Jorge Mendoza','Valeria Cruz','Carlos Pineda','Daniela Vega','Miguel Flores','Paola Reyes','Andrés Salas','Lucía Ríos','Roberto Navas','Empresa Jardín Ejemplo'],2):
        ins('clientes',id=i,nombre=person+' DEMO',telefono='555100'+str(i).zfill(4),correo='cliente'+str(i)+'@example.test',direccion='Calle Ficticia '+str(i),notas='Cliente inventado para probar ventas y crédito')
        ins('configuracion_credito_cliente',cliente_id=i,habilitado=int(i in (2,3,4)),limite='5000.00' if i in (2,3,4) else '0.00',dias=15 if i==2 else 30)
    for i,label in enumerate(['Distribuidora Horizonte','Suministros del Valle','Mayoreo Estrella','Comercial del Centro','Abasto Regional'],1):ins('proveedores',id=i,nombre=label+' '+giro+' DEMO',contacto='Contacto ficticio '+str(i),telefono='555200'+str(i).zfill(4),correo='proveedor'+str(i)+'@example.test',direccion='Parque Comercial de Prueba '+str(i),notas='Proveedor ficticio, sin operaciones reales')
    for i,label in enumerate(['Agua y servicios','Transporte','Limpieza','Mantenimiento','Otros gastos'],1):ins('categorias_gasto',id=i,nombre=label)
    cats={c:i for i,c in enumerate(dict.fromkeys(p[0] for p in products),1)}
    for c,i in cats.items():ins('categorias',id=i,nombre=c)
    vid=0
    for pid,(category,product,unit,price,cost) in enumerate(products,1):
        service=unit=='serv';ins('productos',id=pid,nombre=product,descripcion='Producto ficticio de prueba. Precios, costos e impuestos ilustrativos.',categoria_id=cats[category],unidad_base_id=units[unit],impuesto_id=1,tipo_producto='servicio' if service else 'granel' if unit in ('kg','lt','m') else 'estandar',controla_inventario=0 if service else 1)
        variants=['Única']
        if giro=='ropa' and category in ('Dama','Caballero'):variants=[c+' / '+s for c in ('Negro','Azul') for s in ('CH','M','G')]
        elif giro=='ropa' and category=='Infantil':variants=['Rojo / 6','Azul / 8','Verde / 10']
        elif giro=='ropa' and category=='Calzado':variants=['Negro / '+s for s in ('24','25','26','27')]
        elif giro=='restaurante' and category=='Pizzas':variants=['Chica','Mediana','Familiar']
        for vindex,label in enumerate(variants):
            vid+=1;minimum=5 if unit=='pza' else 2;vp=float(price)+(vindex*30 if giro=='restaurante' else 0)
            ins('variantes_producto',id=vid,producto_id=pid,sku=giro[:3].upper()+'-'+str(vid).zfill(4),nombre=label,costo=cost,precio=f'{vp:.4f}',stock_minimo=0 if service else minimum)
            stem=f'99{index:02}{vid:08}';check=(10-sum(int(n)*(1 if i%2==0 else 3) for i,n in enumerate(stem))%10)%10
            ins('codigos_barras',variante_id=vid,codigo=stem+str(check),unidad_id=units[unit],cantidad=1)
            if service:continue
            store=0 if vid%13==0 else 2 if vid%7==0 else 15+vid%16
            if unit in ('kg','lt','m'):store=float(store)+.5 if store else 0
            stocks={1:store,2:40+vid%30,3:8+vid%10}
            if vid==1:stocks[4]=1
            for loc,stock in stocks.items():
                ins('existencias',ubicacion_id=loc,variante_id=vid,cantidad=stock)
                if stock:ins('movimientos_inventario',ubicacion_id=loc,variante_id=vid,tipo_movimiento='ajuste',cantidad=stock,costo_unitario=cost,tipo_referencia='demo_inicial',referencia_id=vid,usuario_id=1,notas='Inventario inicial ficticio '+giro)
            ins('minimos_inventario',ubicacion_id=1,variante_id=vid,minimo=minimum,usuario_id=1)
    out+=['COMMIT;',"SELECT 'DEMO lista: "+db+". Usuario admin / Admin1.1' AS resultado;"]
    (HERE/(giro+'.sql')).write_text('\n'.join(out)+'\n',encoding='utf-8')
    return {'base':db,'nombre':name,'productos':len(products),'variantes':vid,'usuarios':len(accounts),'tablas':len(SCHEMA)}
if __name__=='__main__':
    php='<?php require "app/Models/Rol.php";echo json_encode(array("hash"=>password_hash("Admin1.1",PASSWORD_BCRYPT),"permisos"=>array_column(Rol::catalogo(),"clave")));'
    r=subprocess.run(['php'],input=php,cwd=ROOT,text=True,capture_output=True,check=True);meta=json.loads(r.stdout)
    result={k:build(k,v,i,meta['hash'],meta['permisos']) for i,(k,v) in enumerate(CATALOGOS.items(),1)}
    (HERE/'catalogo.json').write_text(json.dumps(result,ensure_ascii=False,indent=2)+'\n',encoding='utf-8')
    print(json.dumps(result,ensure_ascii=False,indent=2))
