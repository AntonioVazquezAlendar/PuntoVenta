'use strict';
(() => {
    const app=window.PuntoVenta,$=id=>document.getElementById(id);
    const el=(tag,text,cls)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;if(cls)n.className=cls;return n;};
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(n/100);
    const qty=n=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(n));
    let data={productos:[],ubicaciones:[]},selected=null,dirty=false,busy=false,loading=false,uncertain=false,key=null,retryPayload=null,generation=0,lastRead=null;
    const uuid=()=>{const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=[...b].map(x=>x.toString(16).padStart(2,'0')).join('');return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);};
    const recoverySlot=()=>data.contexto&&app.current()?'pv-pedido-traspaso-'+data.contexto+'-'+app.current().id:null;
    const clearPending=slot=>{try{if(slot)sessionStorage.removeItem(slot);}catch{}};
    const notice=text=>$('orders-message').textContent=text;
    const stock=(p,id)=>Number(p.existencias.find(r=>String(r.ubicacion_id)===String(id))?.cantidad||0);
    function panel(name){if(app.orderPaneVisibility)app.orderPaneVisibility(name);['oc-list','oc-detail','oc-transfer'].forEach(id=>$(id).hidden=id!==name);}
    function option(value,text){const n=el('option',text);n.value=value;return n;}
    function button(text,action){const n=el('button',text,'secondary');n.type='button';n.addEventListener('click',action);return n;}
    app.leaveOrdersCatalog=(includeOrder=true)=>{
        if($('orders-catalog-panel').hidden)return true;
        if(includeOrder&&app.catalogOrderLocked?.()){notice('Primero verifica el guardado pendiente del pedido.');return false;}
        if(busy||uncertain){notice(uncertain?'Verifica el traspaso pendiente con Reintentar antes de salir.':'Espera a que termine el traspaso.');return false;}
        if((dirty||includeOrder&&app.hasCatalogOrderDraft?.())&&!window.confirm('Hay un pedido o traspaso sin guardar. ¿Quieres descartarlo y salir?'))return false;
        dirty=false;if(includeOrder&&app.hasCatalogOrderDraft?.())app.discardCatalogOrderDraft();return true;
    };
    function render(){
        const search=$('oc-search').value.trim().toLocaleLowerCase('es'),category=$('oc-category').value,location=$('oc-location').value;
        const rows=data.productos.filter(p=>(!category||p.categoria===category)&&(p.nombre+' '+p.variante+' '+p.sku).toLocaleLowerCase('es').includes(search));
        const host=$('oc-grid');host.replaceChildren();
        for(const p of rows){const card=el('article',null,'orders-live-card'),photo=button('',()=>detail(p.id));photo.className='orders-live-card-photo';photo.setAttribute('aria-label','Ver detalles de '+p.nombre+' '+p.variante);
            const image=p.imagenes.find(i=>i.principal)||p.imagenes[0];if(image){const img=el('img');img.src=image.url;img.alt=p.nombre;img.loading='lazy';photo.append(img);}else photo.append(el('span',p.nombre.slice(0,1).toUpperCase()));
            const body=el('div',null,'orders-live-card-body');body.append(el('h3',p.nombre),el('p',p.variante+' · '+p.sku,'hint'),el('p',money(p.precio_final_centavos)+' / '+p.unidad,'orders-live-price'));
            if(p.cotizacion)body.append(el('p','Solicitar cotización en el portal','hint'));
            const total=location?stock(p,location):p.existencias.reduce((n,r)=>n+Number(r.cantidad),0);
            body.append(el('p',p.controla_inventario?'Existencia: '+qty(total)+' '+p.unidad+(location?'':' en todas las ubicaciones'):'Servicio sin control de inventario','hint'),button('Ver más y ubicaciones',()=>detail(p.id)),button('Agregar al pedido',()=>app.addCatalogOrder(p)));card.append(photo,body);host.append(card);
        }
        $('oc-empty').hidden=rows.length>0;$('oc-updated').textContent=rows.length+(rows.length===1?' variante ·':' variantes ·')+' Datos del POS consultados a las '+(lastRead||new Date()).toLocaleTimeString('es-MX').replace(/\.$/,'')+'. Se actualizan al entrar y cada 30 segundos.';
    }
    function detail(id){
        selected=data.productos.find(p=>p.id===String(id));if(!selected){notice('El producto ya no está activo. Actualiza el catálogo.');panel('oc-list');return;}
        const p=selected;$('oc-title').textContent=p.nombre;$('oc-summary').textContent=p.variante+' · '+p.sku+' · '+p.categoria;$('oc-price').textContent=money(p.precio_final_centavos)+' / '+p.unidad+(p.cotizacion?' · Cotización en el portal':'');$('oc-description').textContent=p.descripcion||'Sin descripción.';
        const specs=$('oc-specs');specs.replaceChildren();for(const [name,value] of Object.entries(p.caracteristicas||{})){if(value==null||typeof value==='object')continue;specs.append(el('dt',name.replaceAll('_',' ')),el('dd',value===true?'Sí':value===false?'No':String(value)));}
        const photos=$('oc-thumbs');photos.replaceChildren();function image(i){const f=p.imagenes[i];$('oc-photo').hidden=!f;$('oc-no-photo').hidden=!!f;if(f){$('oc-photo').src=f.url;$('oc-photo').alt=p.nombre;}[...photos.children].forEach((n,j)=>n.setAttribute('aria-pressed',String(i===j)));}
        p.imagenes.forEach((f,i)=>{const b=button('',()=>image(i)),img=el('img');img.src=f.url;img.alt='Imagen '+(i+1)+' de '+p.nombre;b.setAttribute('aria-label',img.alt);b.append(img);photos.append(b);});image(Math.max(0,p.imagenes.findIndex(f=>f.principal)));
        const host=$('oc-stock');host.replaceChildren();for(const l of data.ubicaciones){const row=el('tr'),actions=el('td');if(Number(l.activo)&&p.controla_inventario&&stock(p,l.id)>0)actions.append(button('Traspasar',()=>transfer(l.id)));row.append(el('td',l.nombre+(!Number(l.activo)?' (inactiva)':'')),el('td',Number(l.disponible_venta)?'Venta':'Resguardo'),el('td',p.controla_inventario?qty(stock(p,l.id))+' '+p.unidad:'No aplica'),actions);host.append(row);}
        panel('oc-detail');
    }
    app.reloadOrdersCatalog=async()=>{
        if(busy||uncertain||loading||dirty)return;const current=++generation;loading=true;$('oc-refresh').disabled=true;
        try{const fresh=await app.api('/pedidos/catalogo');if(current!==generation)return;data=fresh;lastRead=new Date();const category=$('oc-category').value,location=$('oc-location').value;
            $('oc-category').replaceChildren(option('','Todas las categorías'));for(const name of [...new Set(data.productos.map(p=>p.categoria))].sort((a,b)=>a.localeCompare(b,'es')))$('oc-category').append(option(name,name));if([...$('oc-category').options].some(o=>o.value===category))$('oc-category').value=category;
            $('oc-location').replaceChildren(option('','Todas las ubicaciones'));data.ubicaciones.forEach(l=>$('oc-location').append(option(l.id,l.nombre+(!Number(l.activo)?' (inactiva)':''))));if([...$('oc-location').options].some(o=>o.value===location))$('oc-location').value=location;
            if(app.catalogOrderData)app.catalogOrderData(data);render();if(selected&&!$('oc-detail').hidden)detail(selected.id);else if(!$('oc-transfer').hidden)panel('oc-list');restorePending();
        }finally{loading=false;$('oc-refresh').disabled=false;}
    };
    function restorePending(){
        const slot=recoverySlot();if(!slot||busy||uncertain)return;let saved;try{saved=JSON.parse(sessionStorage.getItem(slot)||'null');}catch{return;}if(!saved?.payload)return;
        const payload=saved.payload;selected=data.productos.find(p=>p.id===String(payload.variante_id))||{id:String(payload.variante_id),nombre:saved.nombre||'Producto del traspaso',variante:'',sku:'',unidad:saved.unidad||'unidades',permite_decimales:true,existencias:[]};transfer(payload.origen_id);
        for(const [id,value]of [['oc-origin',payload.origen_id],['oc-destination',payload.destino_id]]){if(![...$(id).options].some(o=>o.value===String(value)))$(id).append(option(value,'Ubicación '+value));$(id).value=String(value);}
        $('oc-quantity').value=payload.cantidad;$('oc-reason').value=payload.motivo;$('oc-confirm').checked=true;key=payload.clave;retryPayload=payload;uncertain=true;dirty=true;$('oc-transfer-fields').disabled=true;$('oc-transfer-cancel').disabled=true;$('oc-transfer-save').textContent='Reintentar traspaso';$('oc-transfer-message').textContent='Hay un traspaso pendiente de verificar. Reintentar consulta el resultado de la misma solicitud sin duplicarla.';preview();
    }
    app.openOrdersCatalog=async()=>{selected=null;panel('oc-list');await app.reloadOrdersCatalog();};
    function transfer(origin){
        if(!selected||busy||app.catalogOrderLocked?.())return;generation++;key=uuid();retryPayload=null;uncertain=false;$('oc-transfer-form').reset();$('oc-transfer-message').textContent='';$('oc-transfer-save').textContent='Confirmar traspaso';$('oc-transfer-fields').disabled=false;$('oc-transfer-cancel').disabled=false;
        const locations=data.ubicaciones.filter(l=>Number(l.activo));$('oc-origin').replaceChildren(...locations.map(l=>option(l.id,l.nombre)));$('oc-origin').value=String(origin);destinations();$('oc-quantity').min=selected.permite_decimales?'0.0001':'1';$('oc-quantity').step=$('oc-quantity').min;$('oc-transfer-product').textContent=selected.nombre+' · '+selected.variante+' · '+selected.sku;panel('oc-transfer');dirty=false;preview();$('oc-quantity').focus();
    }
    function destinations(){const old=$('oc-destination').value;$('oc-destination').replaceChildren(option('','Selecciona un destino'),...data.ubicaciones.filter(l=>Number(l.activo)&&String(l.id)!==$('oc-origin').value).map(l=>option(l.id,l.nombre+(Number(l.disponible_venta)?' (venta)':' (resguardo)'))));if([...$('oc-destination').options].some(o=>o.value===old))$('oc-destination').value=old;}
    function preview(){if(!selected)return;const value=Number($('oc-quantity').value),origin=stock(selected,$('oc-origin').value),target=$('oc-destination').value;$('oc-quantity').max=String(Math.max(0,origin));$('oc-transfer-preview').textContent='Origen: '+qty(origin)+' → '+qty(origin-value)+' '+selected.unidad+(target?' · Destino: '+qty(stock(selected,target))+' → '+qty(stock(selected,target)+value)+' '+selected.unidad:'')+'. Se validará la existencia actual al confirmar.';}
    $('oc-detail-add').addEventListener('click',()=>{if(selected)app.addCatalogOrder(selected);});
    $('oc-filters').addEventListener('submit',e=>e.preventDefault());$('oc-search').addEventListener('input',render);['oc-category','oc-location'].forEach(id=>$(id).addEventListener('change',render));$('oc-refresh').addEventListener('click',()=>app.reloadOrdersCatalog().catch(e=>notice(e.message)));$('oc-back').addEventListener('click',()=>{selected=null;panel('oc-list');});
    $('oc-origin').addEventListener('change',destinations);$('oc-transfer-form').addEventListener('input',()=>{dirty=true;key=uuid();preview();});$('oc-transfer-form').addEventListener('change',()=>{dirty=true;key=uuid();preview();});
    $('oc-transfer-cancel').addEventListener('click',()=>{if(app.leaveOrdersCatalog(false))detail(selected.id);});
    $('oc-transfer-form').addEventListener('submit',async e=>{
        e.preventDefault();if(busy)return;const unit=selected.unidad;const wasRetry=!!retryPayload;const payload=retryPayload||{origen_id:$('oc-origin').value,destino_id:$('oc-destination').value,variante_id:selected.id,cantidad:$('oc-quantity').value,motivo:$('oc-reason').value,clave:key};
        const slot=recoverySlot();try{if(slot)sessionStorage.setItem(slot,JSON.stringify({payload,nombre:selected.nombre,unidad:unit}));}catch{}
        busy=true;$('oc-transfer-fields').disabled=true;$('oc-transfer-save').disabled=true;$('oc-transfer-cancel').disabled=true;$('oc-transfer-message').textContent='Registrando traspaso…';
        try{const result=await app.api('/almacen/traspasos','POST',payload);dirty=false;uncertain=false;retryPayload=null;clearPending(slot);busy=false;panel('oc-detail');await app.reloadOrdersCatalog().catch(()=>{});notice(result.folio+' registrado. Se movieron '+qty(result.cantidad)+' '+unit+'.');}
        catch(error){uncertain=!error.status||error.status>=500||(wasRetry&&[401,403].includes(error.status));if(uncertain){retryPayload=payload;$('oc-transfer-message').textContent='No se pudo confirmar el resultado. Pulsa Reintentar para verificar el mismo traspaso sin duplicarlo.';$('oc-transfer-save').textContent='Reintentar traspaso';}else{retryPayload=null;clearPending(slot);$('oc-transfer-message').textContent=error.message;}}
        finally{busy=false;$('oc-transfer-save').disabled=false;$('oc-transfer-fields').disabled=uncertain;$('oc-transfer-cancel').disabled=uncertain;}
    });
    window.addEventListener('beforeunload',e=>{if(busy||uncertain){e.preventDefault();e.returnValue='';}});
    setInterval(()=>{if(document.visibilityState==='visible'&&!$('orders-view').hidden&&!$('orders-catalog-panel').hidden&&$('oc-transfer').hidden)app.reloadOrdersCatalog().catch(()=>{});},30000);
    document.addEventListener('puntoventa:access',()=>{generation++;data={productos:[],ubicaciones:[]};selected=null;dirty=false;uncertain=false;retryPayload=null;$('oc-grid').replaceChildren();panel('oc-list');});
})();
