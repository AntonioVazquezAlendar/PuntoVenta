'use strict';
(() => {
    const app=window.PuntoVenta,$=id=>document.getElementById(id);
    const node=(tag,text)=>{const n=document.createElement(tag);if(text!=null)n.textContent=text;return n;};
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(n/100);
    const uuid=()=>{const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=[...b].map(v=>v.toString(16).padStart(2,'0')).join('');return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);};
    let cart=new Map(),context=null,owner=null,requestKey=uuid(),busy=false,uncertain=false,retry=null,version=0,pane='oc-list';
    const message=text=>$('oc-order-message').textContent=text;
    const slot=()=>owner?'pv-pedido-local-'+owner:null;
    const removeSaved=key=>{try{if(key)sessionStorage.removeItem(key);}catch{}};
    const contact=()=>['oc-order-name','oc-order-phone','oc-order-notes'].some(id=>$(id).value.trim());
    app.catalogOrderLocked=()=>busy||uncertain;
    app.hasCatalogOrderDraft=()=>!!cart.size||contact();
    function quantity(value,p){if(!/^(?:0|[1-9][0-9]{0,4})(?:\.[0-9]{1,4})?$/.test(value))return false;const n=Number(value);return n>0&&n<=10000&&(p.permite_decimales||Number.isInteger(n));}
    function changed(){requestKey=uuid();message('');}
    function totals(){let total=0,quote=false;for(const line of cart.values()){if(line.p.cotizacion)quote=true;else total+=Math.round(line.p.precio_final_centavos*Number(line.cantidad));}const estimate=quote?(total?money(total)+' + por cotizar':'Por cotizar'):money(total);$('oc-order-total').textContent=(quote?'Importe conocido / cotización: ':'Total estimado: ')+estimate;$('oc-order-summary').textContent='Revisar pedido · '+cart.size+(cart.size===1?' producto · ':' productos · ')+estimate;}
    function render(){
        const host=$('oc-order-lines');host.replaceChildren();let total=0,quote=false;
        for(const [id,line]of cart){const p=line.p,tr=node('tr'),name=node('td'),cell=node('td'),input=node('input'),action=node('td'),remove=node('button','Quitar');name.append(node('strong',p.nombre),node('small',p.variante+' · '+p.sku));
            input.type='number';input.min=p.permite_decimales?'0.0001':'1';input.step=input.min;input.max='10000';input.required=true;input.value=line.cantidad;input.setAttribute('aria-label','Cantidad para pedido de '+p.nombre+' '+p.variante);
            input.addEventListener('input',()=>{const valid=quantity(input.value,p);input.setCustomValidity(valid?'':'Revisa la cantidad y la unidad del producto.');if(valid&&!app.catalogOrderLocked()){line.cantidad=input.value;changed();totals();}});
            input.addEventListener('change',()=>{if(app.catalogOrderLocked()||!quantity(input.value,p))return;line.cantidad=input.value;changed();render();});cell.append(input);remove.type='button';remove.className='secondary';remove.setAttribute('aria-label','Quitar del pedido '+p.nombre+' '+p.variante);remove.addEventListener('click',()=>{if(app.catalogOrderLocked())return;cart.delete(id);changed();render();});action.append(remove);
            const subtotal=p.cotizacion?null:Math.round(p.precio_final_centavos*Number(line.cantidad));if(subtotal==null)quote=true;else total+=subtotal;
            tr.append(name,cell,node('td',p.cotizacion?'Por cotizar':money(p.precio_final_centavos)),node('td',subtotal==null?'Por cotizar':money(subtotal)),action);host.append(tr);
        }
        const estimate=quote?(total?money(total)+' + por cotizar':'Por cotizar'):money(total);
        $('oc-order-total').textContent=(quote?'Importe conocido / cotización: ':'Total estimado: ')+estimate;
        $('oc-order').hidden=pane==='oc-transfer'||!app.hasCatalogOrderDraft();$('oc-order-summary').hidden=pane==='oc-transfer'||!cart.size;
        $('oc-order-summary').textContent='Revisar pedido · '+cart.size+(cart.size===1?' producto · ':' productos · ')+estimate;
        $('oc-order-fields').disabled=app.catalogOrderLocked();$('oc-order-clear').disabled=app.catalogOrderLocked();$('oc-order-save').disabled=busy||!cart.size;$('oc-order-save').textContent=busy?'Guardando…':uncertain?'Reintentar guardado':'Guardar pedido';
    }
    app.orderPaneVisibility=name=>{pane=name;render();};
    app.discardCatalogOrderDraft=()=>{cart.clear();requestKey=uuid();retry=null;uncertain=false;$('oc-order-form').reset();message('');render();};
    app.addCatalogOrder=p=>{
        if(app.catalogOrderLocked()){ $('orders-message').textContent='Primero verifica el guardado pendiente del pedido.';return;}
        const current=cart.get(p.id);if(!current&&cart.size>=100){message('El pedido admite hasta 100 productos distintos.');return;}
        const amount=current?Number(current.cantidad)+1:1;if(amount>10000){message('El máximo por producto es 10000 unidades.');return;}
        cart.set(p.id,{p,cantidad:String(amount)});changed();render();$('orders-message').textContent=p.nombre+' agregado al pedido. Revisa el resumen para capturar los datos del cliente.';
    };
    app.catalogOrderData=fresh=>{
        context=fresh;const next=fresh.contexto+'-'+app.current().id;
        if(next!==owner){version++;owner=next;app.discardCatalogOrderDraft();restore();}
        if(app.catalogOrderLocked()||$('oc-order').contains(document.activeElement))return;
        for(const [id,line]of cart){const p=fresh.productos.find(p=>p.id===id);if(p)line.p=p;}
        render();
    };
    function restore(){
        let saved;try{saved=JSON.parse(sessionStorage.getItem(slot())||'null');}catch{return;}if(!saved?.payload||!Array.isArray(saved.productos))return;
        cart.clear();for(const l of saved.payload.detalles){const p=context.productos.find(p=>p.id===l.variante_id)||saved.productos.find(p=>p.id===l.variante_id);if(p)cart.set(p.id,{p,cantidad:l.cantidad});}
        $('oc-order-name').value=saved.payload.nombre;$('oc-order-phone').value=saved.payload.celular;$('oc-order-notes').value=saved.payload.notas||'';requestKey=saved.payload.clave;retry=saved.payload;uncertain=true;render();message('Hay un pedido pendiente de verificar. Reintentar guardado recupera el mismo pedido sin duplicarlo.');
    }
    $('oc-order-summary').addEventListener('click',()=>{$('oc-order').scrollIntoView({behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth',block:'start'});$('oc-order-name').focus({preventScroll:true});});
    $('oc-order-clear').addEventListener('click',()=>{if(app.catalogOrderLocked())return;if(window.confirm('¿Quieres quitar los productos y datos de este pedido sin guardar?'))app.discardCatalogOrderDraft();});
    ['oc-order-name','oc-order-phone','oc-order-notes'].forEach(id=>$(id).addEventListener('input',()=>{if(!app.catalogOrderLocked())changed();}));
    $('oc-order-form').addEventListener('submit',async e=>{
        e.preventDefault();if(busy||!cart.size)return;
        const payload=retry||{clave:requestKey,nombre:$('oc-order-name').value.trim(),celular:$('oc-order-phone').value.trim(),notas:$('oc-order-notes').value.trim(),detalles:[...cart].map(([id,line])=>({variante_id:id,cantidad:line.cantidad}))};
        const wasRetry=!!retry,recoveryKey=slot(),epoch=version;
        try{sessionStorage.setItem(recoveryKey,JSON.stringify({payload,productos:[...cart.values()].map(l=>l.p)}));}catch{}
        busy=true;render();message('Guardando pedido…');let saved;
        try{saved=await app.api('/pedidos','POST',payload);removeSaved(recoveryKey);}
        catch(error){if(epoch!==version)return;uncertain=!error.status||error.status>=500||(wasRetry&&[401,403].includes(error.status));if(uncertain){retry=payload;message('No se pudo confirmar el resultado. Reintenta para verificar el mismo pedido sin duplicarlo.');}else{retry=null;removeSaved(recoveryKey);message(error.message);}}
        finally{busy=false;if(epoch===version)render();}
        if(!saved||epoch!==version)return;
        app.discardCatalogOrderDraft();await app.showSavedOrder(saved.id);$('orders-message').textContent='Pedido '+saved.folio+' guardado. Puedes pasarlo a venta cuando tengas tu caja abierta.';
    });
    window.addEventListener('beforeunload',e=>{if(app.hasCatalogOrderDraft()||app.catalogOrderLocked()){e.preventDefault();e.returnValue='';}});
    document.addEventListener('puntoventa:access',()=>{version++;owner=null;context=null;busy=false;app.discardCatalogOrderDraft();});
})();
