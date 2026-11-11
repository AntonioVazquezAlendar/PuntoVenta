'use strict';
(() => {
    const $=id=>document.getElementById(id),app=window.PuntoVenta;
    let user=null,context={turnos:[],productos:[],codigos:[],metodos:[]},cart=[],payments=[],quote=null,currentTicket=null,dirty=false,busy=false,key='',timer=null,revision=0,historyNext=null,paymentIndex=0,openingKey='',openingDirty=false,view='busqueda',defaultView='busqueda',searchTimer=null,searchVersion=0,searchNext=null,searchBusy=false,preferenceBusy=false,selectedCustomer=null,customerTimer=null,customerRequest=0,customerTouched=false,stockRequest=0,stockItem=null,stockTrigger=null;
    const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;};
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n));
    const qty=n=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(n));
    const date=n=>new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(n)*1000));
    const units=value=>{const m=String(value).match(/^(\d{1,10})(?:\.(\d{1,4}))?$/);return m?BigInt(m[1])*10000n+BigInt((m[2]||'').padEnd(4,'0')):null;};
    const decimal=n=>(n/10000n).toString()+'.'+(n%10000n).toString().padStart(4,'0');
    const cents=value=>{const m=String(value).match(/^(\d{1,12})(?:\.(\d{1,2}))?$/);return m?Number(m[1])*100+Number((m[2]||'').padEnd(2,'0')):null;};
    const uuid=()=>{const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=Array.from(b,x=>x.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;};
    const maySell=()=>user&&(user.permisos.todo||user.permisos.vender);
    const msg=(text,error=false)=>{$('sales-message').textContent=text;$('sales-message').classList.toggle('message',error);};
    const button=(text,fn)=>{const b=node('button',text,'edit-button');b.type='button';b.addEventListener('click',fn);return b;};
    function clear(){closeStock();clearTimeout(timer);revision++;cart=[];payments=[];quote=null;dirty=false;key=uuid();$('sales-notes').value='';$('sales-customer').value='';customerTouched=false;resetCustomer();$('sales-confirm').checked=false;openingDirty=false;}
    function leave(){if(app.leaveRefunds&&!app.leaveRefunds())return false;if(busy){msg('Espera a que termine el registro de la venta.');return false;}if((dirty||openingDirty)&&!window.confirm(openingDirty?'Tienes una apertura de caja sin guardar. ¿Quieres descartarla?':'El ticket actual aún no se ha cobrado. ¿Quieres descartarlo?'))return false;clearTimeout(searchTimer);searchVersion++;clear();return true;}
    app.leaveSales=leave;window.addEventListener('beforeunload',event=>{if(dirty||openingDirty||busy){event.preventDefault();event.returnValue='';}});
    function panel(id){$('sales-expense').hidden=id!=='sales-checkout-panel'||!context.turno_id||!user||!(user.permisos.todo||(user.permisos.ver_gastos&&user.permisos.registrar_gastos&&user.permisos.operar_caja));['sales-open-panel','sales-checkout-panel','sales-history-panel','sales-ticket-panel','sales-refund-panel'].forEach(name=>{$(name).hidden=name!==id;});$('sales-refresh').hidden=!maySell()||!['sales-open-panel','sales-checkout-panel'].includes(id);$('sales-new').hidden=!maySell()||['sales-open-panel','sales-checkout-panel'].includes(id);$('sales-history').hidden=id==='sales-history-panel';}
    function immersive(enabled){
        document.body.classList.toggle('pos-mode',enabled);
        if(!enabled&&document.fullscreenElement&&document.exitFullscreen)document.exitFullscreen().catch(()=>{});
    }
    function fullscreen(){
        if(document.fullscreenElement){document.exitFullscreen().catch(()=>{});return;}
        if(document.documentElement.requestFullscreen)document.documentElement.requestFullscreen().catch(()=>{ $('sales-fullscreen').title='El navegador mantiene la vista ampliada. Puedes usar F11 para ocultar sus barras.'; });
    }
    document.addEventListener('fullscreenchange',()=>{$('sales-fullscreen').textContent=document.fullscreenElement?'Salir de pantalla completa':'Pantalla completa';});
    $('sales-expense').addEventListener('click',()=>{if(busy){msg('Espera a que termine el cobro.');return;}if(!context.turno_id){msg('Abre tu propia caja para registrar un gasto.');return;}if(app.openPosExpense)app.openPosExpense(context.turno_id);});
    document.addEventListener('puntoventa:gasto-registrado',event=>{msg('Gasto '+event.detail.folio+' registrado por '+money(event.detail.monto)+'. Puedes continuar con tu venta.');});
    $('sales-fullscreen').addEventListener('click',()=>{immersive(true);fullscreen();});
    $('sales-exit').addEventListener('click',()=>{if(busy){msg('Espera a que termine la operación.');return;}immersive(false);$('nav-sales').focus();});
    new MutationObserver(()=>{if($('sales-view').hidden){closeStock();immersive(false);}}).observe($('sales-view'),{attributes:true,attributeFilter:['hidden']});
    async function open(event){
        if(!$('sales-view').hidden){immersive(true);if(event&&event.isTrusted&&!document.fullscreenElement)fullscreen();return;}
        if(!leave()||(app.leaveCatalog&&!app.leaveCatalog())||!app.openCatalog())return;
        $('products-view').hidden=true;$('nav-products').removeAttribute('aria-current');$('sales-view').hidden=false;$('nav-sales').setAttribute('aria-current','page');msg('Cargando ventas…');immersive(true);if(event&&event.isTrusted&&!document.fullscreenElement)fullscreen();
        try{const session=await app.api('/sesion');user=session.usuario;if(!user||(!maySell()&&!user.permisos.ver_ventas))throw new Error('No tienes permiso para consultar ventas.');
            $('sales-operator').textContent='Cajero: '+user.nombre+' · '+user.rol_nombre;
            if(maySell()){$('sales-open-form').reset();openingKey=uuid();await loadContext(true);checkoutPanel();renderCart();renderPayments();}else{panel('sales-history-panel');await loadHistory(false);}
            msg('');app.status('');
        }catch(error){msg(error.message,true);}
    }
    $('nav-sales').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{const p=event.detail.permisos;if(!p.todo&&(p.vender||p.ver_ventas))open();});
    app.openRefundLookup=()=>{if(!leave())return false;panel('sales-refund-panel');$('sales-ticket-print').hidden=false;return true;};
    function checkoutPanel(){panel(context.turno_id?'sales-checkout-panel':'sales-open-panel');}
    async function loadContext(useDefault=false){
        const previous=context.turno_id;
        context=await app.api('/ventas/contexto');defaultView=context.vista;if(useDefault){view=defaultView;$('sales-search').value='';}$('sales-turn').value=context.turno_id||'';
        if(previous&&String(previous)!==String(context.turno_id)){clear();renderCart();renderPayments();}
        const turno=context.turnos.find(t=>String(t.id)===String(context.turno_id));
        $('sales-current-box').textContent=turno?turno.caja_nombre+' · '+turno.ubicacion_nombre:'';
        $('sales-turn-help').textContent=turno?'Las existencias se descuentan de esta ubicación.':'';
        $('sales-open-user').textContent=user.nombre;
        const selected=$('sales-open-box').value;$('sales-open-box').replaceChildren();
        const blank=node('option','Selecciona una caja');blank.value='';$('sales-open-box').append(blank);
        context.cajas_disponibles.forEach(c=>{const o=node('option',c.nombre+' · '+c.ubicacion_nombre);o.value=c.id;$('sales-open-box').append(o);});
        if(context.cajas_disponibles.some(c=>String(c.id)===selected))$('sales-open-box').value=selected;
        const canOpen=context.puede_abrir_caja&&!context.mi_turno&&context.cajas_disponibles.length>0;
        $('sales-open-fields').disabled=!canOpen;$('sales-open-save').disabled=!canOpen;
        $('sales-open-title').textContent=context.mi_turno?'Tu caja necesita atención':'Abre tu caja para comenzar';
        $('sales-open-help').textContent=context.mi_turno?'Ya tienes abierta '+context.mi_turno.caja_nombre+', pero su caja o ubicación no está habilitada para vender. Revisa su estado en Caja con el administrador.':!context.puede_abrir_caja?'Necesitas el permiso para operar tus turnos de caja. Solicítalo al administrador.':!context.cajas_disponibles.length?'No hay cajas disponibles. Pulsa Actualizar cuando se libere una o consulta al administrador.':'';
        if(!customerTouched&&!cart.length&&!$('sales-customer').value.trim()&&context.cliente_predeterminado)customerPicked(context.cliente_predeterminado,true);
        updateView();await loadProducts();
    }
    async function refreshCartItems(){
        if(!cart.length||!context.turno_id)return;
        const data=await app.api('/ventas/productos?'+new URLSearchParams({turno_id:context.turno_id,ids:cart.map(l=>l.variante_id).join(',')}));
        cart.forEach(line=>{const fresh=data.productos.find(p=>String(p.id)===String(line.variante_id));if(fresh){if(units(line.precio_unitario)===units(line.item.precio))line.precio_unitario=fresh.precio;line.item=fresh;}});
    }
    $('sales-refresh').addEventListener('click',async()=>{if(busy)return;try{await loadContext();checkoutPanel();await refreshCartItems();renderCart();changed();msg(context.turno_id?'Catálogo actualizado. Revisa los importes del ticket.':'Disponibilidad de cajas actualizada.');}catch(error){msg(error.message,true);}});
    ['input','change'].forEach(event=>$('sales-open-form').addEventListener(event,()=>{openingDirty=true;openingKey=uuid();}));
    $('sales-open-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;
        const payload={caja_id:$('sales-open-box').value,monto_apertura:$('sales-open-amount').value,notas:$('sales-open-note').value,clave:openingKey||uuid()};openingKey=payload.clave;
        busy=true;$('sales-open-fields').disabled=true;$('sales-open-save').disabled=true;$('sales-open-error').textContent='';
        try{await app.api('/caja/turnos','POST',payload);openingDirty=false;
            try{await loadContext();checkoutPanel();renderCart();renderPayments();msg('Caja abierta a tu nombre. Ya puedes realizar ventas.');$('sales-search').focus();}
            catch(error){msg('La caja se abrió. Pulsa Actualizar para continuar a la venta.',true);}
        }catch(error){$('sales-open-error').textContent=error.message;}
        finally{busy=false;const enabled=context.puede_abrir_caja&&!context.mi_turno&&context.cajas_disponibles.length>0;$('sales-open-fields').disabled=!enabled;$('sales-open-save').disabled=!enabled;}
    });
    function updateView(){
        $('sales-layout').value=view;$('sales-view').classList.toggle('is-mosaic',view==='mosaico');
        $('sales-layout-default').disabled=preferenceBusy||view===defaultView;
        $('sales-layout-status').textContent=view===defaultView?'Vista predeterminada de tu usuario.':'Vista temporal. Puedes guardarla como predeterminada.';
    }
    function closeStock(){stockRequest++;if($('sales-stock-dialog').open)$('sales-stock-dialog').close();}
    function stockButton(p){const b=node('button','Ver existencias','sales-stock-button');b.type='button';b.setAttribute('aria-label','Ver existencias de '+p.sku);b.addEventListener('click',()=>showStock(p));return b;}
    async function showStock(p){
        if(busy||!context.turno_id)return;const dialog=$('sales-stock-dialog');if(!dialog.open)stockTrigger=document.activeElement;
        stockItem=p;const request=++stockRequest,turn=context.turno_id;$('sales-stock-product').textContent=p.producto_nombre+(p.variante_nombre?' · '+p.variante_nombre:'')+' · '+p.sku;
        $('sales-stock-rows').replaceChildren();$('sales-stock-status').textContent='Consultando existencias…';$('sales-stock-note').textContent='';$('sales-stock-refresh').disabled=true;if(!dialog.open)dialog.showModal();
        try{const data=await app.api('/ventas/existencias?'+new URLSearchParams({turno_id:turn,variante_id:p.id}));if(request!==stockRequest||!dialog.open||String(turn)!==String(context.turno_id))return;
            data.ubicaciones.forEach(l=>{const row=node('tr'),name=node('td'),amount=node('td',qty(l.cantidad)+' '+data.producto.unidad_codigo),availability=node('td');name.append(node('strong',l.nombre));
                if(String(l.id)===String(data.ubicacion_venta_id)){row.className='pos-stock-current';name.append(node('small','Ubicación de esta caja'));}
                amount.classList.toggle('negative',Number(l.cantidad)<0);availability.append(node('span',!Number(l.activo)?'Inactiva · no disponible':!Number(l.disponible_venta)?'Resguardo · no disponible para venta':'Habilitada para venta'));row.append(name,amount,availability);$('sales-stock-rows').append(row);
            });
            $('sales-stock-status').textContent=Number(data.producto.controla_inventario)?'Consulta actualizada: '+date(data.consultado_unix):'Este producto o servicio no controla inventario.';
            $('sales-stock-note').textContent='Tu caja sigue vendiendo desde '+data.ubicacion_venta_nombre+'. Para mover mercancía entre ubicaciones, utiliza un traspaso.';
        }catch(error){if(request===stockRequest&&dialog.open)$('sales-stock-status').textContent=error.message;}
        finally{if(request===stockRequest)$('sales-stock-refresh').disabled=false;}
    }
    $('sales-stock-close').addEventListener('click',closeStock);$('sales-stock-refresh').addEventListener('click',()=>{if(stockItem)showStock(stockItem);});
    $('sales-stock-dialog').addEventListener('close',()=>{stockRequest++;if(stockTrigger&&stockTrigger.isConnected)stockTrigger.focus();});
    function renderResults(){
        $('sales-results').replaceChildren();
        context.productos.forEach(p=>{const b=node('button',undefined,'sales-result');b.type='button';const name=node('span');name.append(node('strong',p.producto_nombre+(p.variante_nombre?' · '+p.variante_nombre:'')),node('small',p.sku+' · '+(Number(p.controla_inventario)?qty(p.existencia)+' '+p.unidad_codigo:'Servicio sin inventario')));
            if(p.imagen_url){const photo=node('img',undefined,'sales-product-photo');photo.src=p.imagen_url;photo.alt='';photo.loading='lazy';b.classList.add('with-photo');photo.addEventListener('error',()=>{photo.remove();b.classList.remove('with-photo');});b.append(photo);}else if(view==='mosaico')b.append(node('span','Sin imagen','pos-no-photo'));
            b.append(name,node('span',money(p.precio)+(Number(p.precio_incluye_impuesto)?'':' + imp.')));b.setAttribute('aria-label','Agregar '+p.sku);b.addEventListener('click',()=>add(p,p.cantidad_agregar||'1'));const result=node('div',undefined,'sales-product-result');result.append(b);if(Number(p.controla_inventario))result.append(stockButton(p));$('sales-results').append(result);
        });$('sales-results-more').hidden=!searchNext;$('sales-results-more').disabled=searchBusy;
        $('sales-results-help').textContent=searchBusy?'Buscando productos…':!context.turno_id?'Abre tu caja para consultar productos.':view==='busqueda'&&!$('sales-search').value.trim()?'Escribe un nombre o SKU, o escanea un código. Los productos se consultan al buscar.':context.productos.length?'Mostrando '+context.productos.length+(context.productos.length===1?' variante':' variantes')+(searchNext?'. Hay más disponibles.':'.'): 'No hay productos que coincidan.';
    }
    async function loadProducts(append=false){
        clearTimeout(searchTimer);const request=++searchVersion,turn=context.turno_id,text=$('sales-search').value.trim();
        if(!append){context.productos=[];searchNext=null;}
        if(!turn||(view==='busqueda'&&!text)){searchBusy=false;renderResults();return;}
        const params=new URLSearchParams({turno_id:turn,modo:view,buscar:text});if(append&&searchNext)params.set('despues_de',searchNext);
        searchBusy=true;renderResults();
        try{const data=await app.api('/ventas/productos?'+params);if(request!==searchVersion||String(turn)!==String(context.turno_id))return;context.productos=append?[...context.productos,...data.productos]:data.productos;searchNext=data.siguiente;}
        catch(error){if(request===searchVersion)msg(error.message,true);}
        finally{if(request===searchVersion){searchBusy=false;renderResults();}}
    }
    $('sales-layout').addEventListener('change',()=>{view=$('sales-layout').value;updateView();loadProducts();});
    $('sales-layout-default').addEventListener('click',async()=>{
        if(preferenceBusy)return;const selected=view;preferenceBusy=true;updateView();
        try{const result=await app.api('/ventas/preferencias','PUT',{vista:selected});defaultView=result.vista;msg('Vista '+(defaultView==='mosaico'?'mosaico':'de búsqueda')+' guardada como predeterminada para tu usuario.');}
        catch(error){msg(error.message,true);}finally{preferenceBusy=false;updateView();}
    });
    $('sales-results-more').addEventListener('click',()=>{if(!searchBusy&&searchNext)loadProducts(true);});
    $('sales-search').addEventListener('input',()=>{clearTimeout(searchTimer);searchVersion++;searchBusy=false;context.productos=[];searchNext=null;renderResults();searchTimer=setTimeout(()=>loadProducts(),300);});
    $('sales-search-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;clearTimeout(searchTimer);const text=$('sales-search').value.trim();if(!text||!context.turno_id)return;
        const request=++searchVersion,turn=context.turno_id;
        try{const data=await app.api('/ventas/productos?'+new URLSearchParams({turno_id:turn,codigo:text}));if(request!==searchVersion||String(turn)!==String(context.turno_id)||busy)return;
            if(data.productos.length){const p=data.productos[0];add(p,p.cantidad_agregar||'1');$('sales-search').value='';await loadProducts();$('sales-search').focus();}
            else{await loadProducts();msg('Selecciona un resultado o escanea un código registrado.');}
        }catch(error){if(request===searchVersion)msg(error.message,true);}
    });
    function add(p,amount){
        if(busy)return;const found=cart.find(line=>String(line.variante_id)===String(p.id)),factor=units(amount),old=found?units(found.cantidad):0n;
        if(factor===null||factor<=0n||old===null||old+factor>99999999999999n){msg('La cantidad no es válida.',true);return;}
        if(found)found.cantidad=decimal(old+factor);else{if(cart.length>=100){msg('El máximo es de 100 variantes por ticket.',true);return;}cart.push({variante_id:p.id,cantidad:decimal(factor),precio_unitario:p.precio,descuento:'0.00',item:p});}
        renderCart();changed();msg('');
    }
    function renderCart(){
        $('sales-cart-rows').replaceChildren();cart.forEach((line,index)=>{
            const p=line.item,row=node('tr'),name=node('td');name.append(node('strong',p.producto_nombre+(p.variante_nombre?' · '+p.variante_nombre:'')),node('small',p.sku+' · '+p.unidad_codigo));if(Number(p.controla_inventario))name.append(stockButton(p));row.append(name);
            [['cantidad','Cantidad',Number(p.permite_decimales)?'.0001':'1',false],['precio_unitario','Precio unitario','.0001',!context.puede_precio],['descuento','Descuento','.01',!context.puede_descuento]].forEach(([key,label,step,disabled])=>{
                const cell=node('td'),input=node('input');cell.className='pos-line-'+key;const caption=node('span',key==='precio_unitario'?'Precio':label,'pos-input-caption');cell.append(caption);input.type='number';input.min=key==='cantidad'?step:'0';input.step=step;input.max=key==='descuento'?'999999999999.99':'9999999999.9999';input.value=String(Number(line[key]));input.disabled=disabled;input.setAttribute('aria-label',label+' de '+p.sku);
                input.addEventListener('input',()=>{line[key]=input.value;changed();});cell.append(input);row.append(cell);
            });line.totalCell=node('td','—','pos-line-total');row.append(line.totalCell);const actions=node('td',undefined,'pos-line-remove');actions.append(button('Quitar',()=>{cart.splice(index,1);renderCart();changed();}));row.append(actions);$('sales-cart-rows').append(row);
        });$('sales-cart-count').textContent=cart.length+' '+(cart.length===1?'producto':'productos');$('sales-cart-empty').hidden=cart.length!==0;if(!cart.length){$('sales-subtotal').textContent=money(0);$('sales-tax').textContent=money(0);$('sales-total').textContent=money(0);$('sales-discount-info').textContent='';$('sales-quote-status').textContent='Agrega productos para calcular el total.';}
    }
    const inputData=()=>({turno_id:$('sales-turn').value,notas:$('sales-notes').value,cliente_nombre:$('sales-customer').value,cliente_id:selectedCustomer?selectedCustomer.id:null,detalles:cart.map(line=>({variante_id:line.variante_id,cantidad:line.cantidad,precio_unitario:line.precio_unitario,descuento:line.descuento}))});
    function changed(){
        clearTimeout(timer);revision++;key=uuid();quote=null;dirty=cart.length>0||(customerTouched&&$('sales-customer').value.trim()!=='');$('sales-confirm').checked=false;$('sales-charge').disabled=true;$('sales-payment-error').textContent='';
        if(!cart.length){payments=[];renderCart();renderPayments();return;}
        $('sales-quote-status').textContent='Calculando importes…';const version=revision;timer=setTimeout(()=>calculate(version),350);
    }
    async function calculate(version){
        if(version!==revision||busy)return;
        if(cart.some(l=>units(l.cantidad)===null||units(l.precio_unitario)===null||cents(l.descuento)===null)){ $('sales-quote-status').textContent='Completa las cantidades, precios y descuentos.';return; }
        try{const result=await app.api('/ventas/cotizar', 'POST',inputData());if(version!==revision)return;quote=result;
            if(selectedCustomer&&result.cliente&&String(result.cliente.id)===String(selectedCustomer.id)){selectedCustomer.nombre=result.cliente.nombre;$('sales-customer').value=result.cliente.nombre;$('sales-customer-name').textContent='Cliente registrado: '+result.cliente.nombre;}
            $('sales-subtotal').textContent=money(result.subtotal);$('sales-tax').textContent=money(result.impuesto_total);$('sales-total').textContent=money(result.total);$('sales-discount-info').textContent=Number(result.descuento_total)?'Descuento aplicado: '+money(result.descuento_total):'';
            cart.forEach(l=>{const line=result.detalles.find(d=>String(d.variante_id)===String(l.variante_id));l.totalCell.textContent=line?money(line.total_linea):'—';});
            const low=result.detalles.some(l=>Number(l.controla_inventario)&&Number(l.cantidad)>Number(l.existencia));$('sales-quote-status').textContent=low?'Hay cantidades superiores a las existencias. La venta puede dejar inventario negativo.':'Importes calculados. Revisa los pagos antes de confirmar.';
            if(Number(result.total)===0)payments=[];
            else if(!payments.length){payments=[newPayment()];payments[0].monto=result.total;}
            else if(payments.length===1&&payments[0].auto)payments[0].monto=result.total;
            renderPayments();
        }catch(error){if(version===revision){quote=null;$('sales-quote-status').textContent=error.message;paymentStatus();}}
    }
    $('sales-notes').addEventListener('input',()=>{dirty=cart.length>0||(customerTouched&&$('sales-customer').value.trim()!=='');key=uuid();$('sales-confirm').checked=false;});
    function resetCustomer(){
        clearTimeout(customerTimer);customerRequest++;selectedCustomer=null;$('sales-customer-selected').hidden=true;$('sales-customer-results').replaceChildren();$('sales-customer-help').textContent='Busca por nombre o teléfono. Un nombre sin seleccionar cliente se guarda solo en este ticket.';
    }
    function customerPicked(c,automatic=false){
        resetCustomer();customerTouched=!automatic;selectedCustomer=c;$('sales-customer').value=c.nombre;$('sales-customer-name').textContent='Cliente registrado: '+c.nombre;$('sales-customer-selected').hidden=false;$('sales-customer-help').textContent=automatic?'Cliente predeterminado. Puedes cambiarlo para esta venta.':'Esta compra se vinculará a su historial.';if(automatic){dirty=false;}else changed();
    }
    $('sales-customer-clear').addEventListener('click',()=>{if(busy)return;customerTouched=true;resetCustomer();$('sales-customer').value='';changed();$('sales-customer').focus();});
    $('sales-customer').addEventListener('input',()=>{
        customerTouched=true;const had=!!selectedCustomer;resetCustomer();if(had)changed();dirty=cart.length>0||(customerTouched&&$('sales-customer').value.trim()!=='');key=uuid();$('sales-confirm').checked=false;
        const term=$('sales-customer').value.trim();if(term.length<2)return;const request=customerRequest;
        $('sales-customer-help').textContent='Buscando clientes…';
        customerTimer=setTimeout(async()=>{try{const data=await app.api('/clientes/buscar?'+new URLSearchParams({buscar:term}));if(request!==customerRequest||busy)return;
            $('sales-customer-results').replaceChildren();data.clientes.forEach(c=>{const b=node('button',undefined,'pos-customer-option');b.type='button';b.append(node('strong',c.nombre),node('small',c.telefono||'Sin teléfono'));b.setAttribute('aria-label','Seleccionar cliente '+c.nombre);b.addEventListener('click',()=>customerPicked(c));$('sales-customer-results').append(b);});
            $('sales-customer-help').textContent=data.clientes.length?'Selecciona una ficha para vincular la compra a su historial.':'Sin coincidencias. Este nombre se guardará solo en el ticket.';
        }catch(error){if(request===customerRequest)$('sales-customer-help').textContent=error.message;}},300);
    });
    function newPayment(){const used=payments.map(p=>String(p.metodo_pago_id));const m=context.metodos.find(m=>!used.includes(String(m.id)));return {metodo_pago_id:m?m.id:'',monto:'0.00',recibido:'',referencia:'',auto:true};}
    function renderPayments(){
        $('sales-payment-rows').replaceChildren();payments.forEach((pay,index)=>{
            const box=node('div',undefined,'sales-payment-row'),select=node('select'),label=node('label','Método de pago');select.id='sale-pay-method-'+(++paymentIndex);label.htmlFor=select.id;
            context.metodos.forEach(m=>{const o=node('option',m.nombre);o.value=m.id;o.disabled=payments.some((p,i)=>i!==index&&String(p.metodo_pago_id)===String(m.id));select.append(o);});select.value=pay.metodo_pago_id;
            select.addEventListener('change',()=>{pay.metodo_pago_id=select.value;pay.recibido='';key=uuid();$('sales-confirm').checked=false;renderPayments();});box.append(label,select);
            const method=context.metodos.find(m=>String(m.id)===String(pay.metodo_pago_id));
            if(method&&method.tipo==='credito'){const c=quote&&quote.cliente&&quote.cliente.credito;box.append(node('p',c&&Number(c.habilitado)?'Disponible para crédito: '+money(c.disponible)+' · Plazo: '+c.dias+' días.':'Selecciona un cliente con crédito habilitado por el administrador.','hint'));}
            [['monto',method&&method.tipo==='credito'?'Importe a dejar fiado':'Importe aplicado',true],...(method&&method.tipo==='efectivo'?[['recibido','Efectivo recibido (opcional)',false]]:[['referencia','Referencia o autorización (opcional)',false]])].forEach(([keyName,text,required])=>{
                const lab=node('label',text),input=node('input');input.id='sale-pay-'+paymentIndex+'-'+keyName;lab.htmlFor=input.id;input.value=pay[keyName];input.type=keyName==='referencia'?'text':'number';input.required=required;
                if(input.type==='number'){input.min=keyName==='monto'?'.01':'0';input.step='.01';input.max='999999999999.99';}else input.maxLength=100;
                input.addEventListener('input',()=>{pay[keyName]=input.value;if(keyName==='monto')pay.auto=false;key=uuid();$('sales-confirm').checked=false;paymentStatus();});box.append(lab,input);if(keyName==='monto'&&method&&method.tipo==='credito'){input.setAttribute('aria-describedby','sales-credit-guidance');box.append(node('p','Esta cantidad es lo que el cliente quedará debiendo. No es un abono ni dinero recibido.','hint'));}
            });
            if(payments.length>1)box.append(button('Quitar método',()=>{payments.splice(index,1);if(payments.length===1&&quote){payments[0].auto=true;payments[0].monto=quote.total;}key=uuid();$('sales-confirm').checked=false;renderPayments();}));$('sales-payment-rows').append(box);
        });$('sales-add-payment').hidden=!quote||Number(quote.total)===0||payments.length>=context.metodos.length||payments.length>=8;paymentStatus();
    }
    function paymentStatus(){
        let sum=0,change=0,credit=0,hasCredit=false,valid=!!quote;
        payments.forEach(p=>{const method=context.metodos.find(m=>String(m.id)===String(p.metodo_pago_id)),amount=cents(p.monto);if(method&&method.tipo==='credito'){hasCredit=true;if(amount!==null)credit+=amount;}if(!method||amount===null||amount<=0){valid=false;return;}sum+=amount;if(method.tipo==='efectivo'){const received=p.recibido===''?amount:cents(p.recibido);if(received===null||received<amount)valid=false;else change+=received-amount;}});
        const total=quote?cents(quote.total):0;valid=valid&&sum===total;
        $('sales-payment-status').textContent=!quote?'Calcula el ticket para registrar pagos.':sum<total?'Falta aplicar '+money((total-sum)/100)+'.':sum>total?'Los pagos exceden el total por '+money((sum-total)/100)+'.':'Importes distribuidos: '+money(total/100)+'.';
        const guidance=$('sales-credit-guidance');guidance.hidden=!quote||!hasCredit;
        if(quote&&hasCredit){
            const remaining=Math.max(0,total-sum),creditForBalance=Math.max(0,total-(sum-credit));
            guidance.textContent='De la venta de '+money(total/100)+', '+money(credit/100)+' quedarán fiados. '+(sum<total?'Faltan '+money(remaining/100)+' por cubrir. Si quieres dejar fiado todo lo pendiente, cambia el importe fiado a '+money(creditForBalance/100)+'. Si solo fiarás '+money(credit/100)+', pulsa «Agregar otro método» y registra los '+money(remaining/100)+' restantes.':sum>total?'Reduce los importes: el crédito más los pagos deben sumar exactamente '+money(total/100)+'.':'El crédito y los pagos cubren el total. Revisa y marca la casilla de confirmación para completar la venta.');
        }
        $('sales-change').textContent=quote?'Cambio: '+money(change/100):'';$('sales-charge').disabled=!valid||busy||!cart.length;
    }
    $('sales-add-payment').addEventListener('click',()=>{if(!quote||busy)return;const p=newPayment();const sum=payments.reduce((n,p)=>n+(cents(p.monto)||0),0);p.monto=((Math.max(0,cents(quote.total)-sum))/100).toFixed(2);payments.push(p);key=uuid();$('sales-confirm').checked=false;renderPayments();});
    $('sales-payment-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!quote)return;clearTimeout(timer);const payload=inputData();payload.sello=quote.sello;payload.clave=key;payload.pagos=payments.map(p=>({metodo_pago_id:p.metodo_pago_id,monto:p.monto,recibido:p.recibido===''?null:p.recibido,referencia:p.referencia}));
        busy=true;$('sales-payment-fields').disabled=true;$('sales-charge').disabled=true;$('sales-payment-error').textContent='';$('sales-workspace').classList.add('sales-busy');$('sales-workspace').inert=true;
        try{const result=await app.api('/ventas','POST',payload);clear();renderCart();renderPayments();try{renderTicket(await app.api('/ventas/'+result.venta_id));msg('Venta '+result.folio+' completada. Cambio: '+money(result.cambio)+'.');}catch(error){msg('Venta '+result.folio+' completada. Abre Ventas y tickets para consultar el ticket.',true);}}
        catch(error){$('sales-payment-error').textContent=error.message;}
        finally{busy=false;$('sales-payment-fields').disabled=false;$('sales-workspace').classList.remove('sales-busy');$('sales-workspace').inert=false;paymentStatus();}
    });
    async function fresh(){if(!leave())return;try{await loadContext();checkoutPanel();renderCart();renderPayments();msg('');$('sales-search').focus();}catch(error){msg(error.message,true);}}
    $('sales-new').addEventListener('click',fresh);$('sales-history-back').addEventListener('click',fresh);
    async function loadHistory(append){
        const data=await app.api('/ventas'+(append&&historyNext?'?antes_de='+historyNext:''));historyNext=data.siguiente;if(!append)$('sales-history-rows').replaceChildren();
        data.ventas.forEach(v=>{const tr=node('tr'),folio=node('td'),actions=node('td');folio.append(node('strong',v.folio),node('small',date(v.creado_unix)));actions.append(button('Ver ticket',async()=>{try{renderTicket(await app.api('/ventas/'+v.id));msg('');}catch(error){msg(error.message,true);}}));const person=node('td');person.append(node('strong',v.usuario_nombre),node('small','Cliente: '+(v.cliente_nombre||'Público general')));const state=node('td',v.estatus),amount=node('td',money(v.total));if(Number(v.reembolsos_count)>0){if(v.estatus==='completada')state.append(node('small','Con devoluciones'));amount.append(node('small','Reembolsado: '+money(v.reembolsado)+' · Neto: '+money(Number(v.total)-Number(v.reembolsado))));}tr.append(folio,person,state,amount,actions);$('sales-history-rows').append(tr);});
        $('sales-history-empty').hidden=$('sales-history-rows').children.length!==0;$('sales-history-more').hidden=!historyNext;$('sales-history-back').hidden=!maySell();$('sales-history-scope').textContent=user.permisos.todo?'Puedes consultar las ventas de todos los usuarios.':'Aquí aparecen tus ventas y tickets.';
    }
    $('sales-history').addEventListener('click',async()=>{if(!leave())return;panel('sales-history-panel');try{await loadHistory(false);msg('');}catch(error){msg(error.message,true);}});
    $('sales-history-more').addEventListener('click',async()=>{$('sales-history-more').disabled=true;try{await loadHistory(true);}catch(error){msg(error.message,true);}finally{$('sales-history-more').disabled=false;}});
    function renderTicket(sale){
        currentTicket=sale;panel('sales-ticket-panel');const paper=$('sales-ticket-paper');paper.replaceChildren();const t=sale.ticket;
        if(t.logo_url){const img=node('img');img.src=t.logo_url;img.alt='';img.addEventListener('error',()=>{img.hidden=true;});paper.append(img);}
        paper.append(node('h3',t.nombre_comercial||'PuntoVenta'));[t.direccion,t.telefono,t.correo,t.encabezado_ticket].filter(Boolean).forEach(text=>paper.append(node('p',text)));
        paper.append(node('hr'),node('strong',sale.folio),node('p',date(sale.completado_unix)),node('p',(t.caja_nombre||'Caja')+' · '+(t.ubicacion_nombre||'')),node('p','Cajero: '+(t.usuario_nombre||'')),node('p','Cliente: '+(t.cliente_nombre||'Público general')));
        if(sale.estatus!=='completada')paper.append(node('strong',sale.estatus.toUpperCase()));
        const table=node('table'),tbody=node('tbody');sale.detalles.forEach(line=>{const tr=node('tr'),name=node('td');name.append(node('strong',line.descripcion),node('small',qty(line.cantidad)+' '+line.unidad_codigo+' × '+money(line.precio_unitario)+(Number(line.precio_incluye_impuesto)?' (imp. incluido)':' + impuesto')));if(Number(line.descuento))name.append(node('small','Descuento aplicado: '+money(line.descuento)));tr.append(name,node('td',money(line.total_linea)));tbody.append(tr);});table.append(tbody);paper.append(table);
        const dl=node('dl');[['Subtotal neto',sale.subtotal],['Impuestos',sale.impuesto_total],['TOTAL',sale.total]].forEach(([label,value])=>{const div=node('div');div.append(node('dt',label),node('dd',money(value)));dl.append(div);});paper.append(dl);
        sale.pagos.forEach(p=>{paper.append(node('p',p.nombre+': '+money(p.monto)));if(p.tipo==='efectivo')paper.append(node('p','Recibido: '+money(p.recibido)+' · Cambio: '+money(p.cambio)));if(p.referencia)paper.append(node('p','Referencia: '+p.referencia));});
        if(t.credito_original)paper.append(node('p','Crédito original: '+money(t.credito_original)),node('p','Vencimiento: '+t.credito_vence),node('p','El crédito es saldo por cobrar, no efectivo recibido.'));
        if(!sale.pagos.length)paper.append(node('p','Venta sin importe por cobrar.'));if(sale.notas)paper.append(node('p',sale.notas));paper.append(node('hr'),node('p',t.pie_ticket||'Gracias por su compra'));document.dispatchEvent(new CustomEvent('puntoventa:ticket',{detail:{venta:sale,usuario:user}}));
    }
    app.refreshSalesTicket=async id=>renderTicket(await app.api('/ventas/'+id));
    $('sales-ticket-back').addEventListener('click',async()=>{if(app.leaveRefunds&&!app.leaveRefunds())return;panel('sales-history-panel');try{await loadHistory(false);msg('');}catch(error){msg(error.message,true);}});
    $('sales-ticket-print').addEventListener('click',()=>{if(!currentTicket)return;document.body.classList.add('printing-ticket');window.print();});
    window.addEventListener('afterprint',()=>document.body.classList.remove('printing-ticket'));
})();
