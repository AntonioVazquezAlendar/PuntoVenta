'use strict';
(() => {
    const $=id=>document.getElementById(id),app=window.PuntoVenta;
    let purchases=[],meta={proveedores:[],ubicaciones:[],variantes:[],impuestos:[]},current=null,supplierId=null,dirty=false,busy=false,requestKey='',lineIndex=0,action=null;
    const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;};
    const currency=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN',maximumFractionDigits:4}).format(Number(n));
    const quantity=n=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(n));
    const date=n=>new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(n)*1000));
    const uuid=()=>{const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=Array.from(b,x=>x.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;};
    const button=(text,fn)=>{const b=node('button',text,'edit-button');b.type='button';b.addEventListener('click',fn);return b;};
    const msg=(text,error=false)=>{$('purchase-message').textContent=text;$('purchase-message').classList.toggle('message',error);};
    const states={borrador:'Borrador',pedida:'Pedida',recibida:'Recibida',cancelada:'Cancelada'};
    function panels(id){['purchase-list-panel','purchase-editor','purchase-detail','suppliers-panel'].forEach(name=>{$(name).hidden=name!==id;});$('purchase-new').hidden=id!=='purchase-list-panel';$('purchase-suppliers').hidden=id!=='purchase-list-panel';}
    function leave(){if(busy){msg('Espera a que termine la operación.');return false;}if(dirty&&!window.confirm('Tienes cambios sin guardar en Compras. ¿Quieres descartarlos?'))return false;dirty=false;return true;}
    app.leavePurchases=leave;
    window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});
    async function load(){[purchases,meta]=await Promise.all([app.api('/compras'),app.api('/compras/catalogos')]);render();}
    async function open(){
        if(!leave()||(app.leaveCatalog&&!app.leaveCatalog())||!app.openCatalog())return;
        $('products-view').hidden=true;$('nav-products').removeAttribute('aria-current');$('purchases-view').hidden=false;$('nav-purchases').setAttribute('aria-current','page');
        panels('purchase-list-panel');$('purchase-rows').replaceChildren();msg('Cargando compras…');
        try{await load();msg('');app.status('');}catch(error){msg(error.message,true);}
    }
    $('nav-purchases').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{const p=event.detail.permisos;if(!p.todo&&!p.vender&&!p.ver_ventas&&!p.ver_productos&&!p.ver_inventario&&p.hacer_compras)open();});
    function render(){
        const search=$('purchase-search').value.trim().toLocaleLowerCase('es'),state=$('purchase-status-filter').value;
        const list=purchases.filter(p=>(!state||p.estatus===state)&&[p.folio,p.proveedor_nombre,p.factura_proveedor].join(' ').toLocaleLowerCase('es').includes(search));
        $('purchase-rows').replaceChildren();list.forEach(p=>{
            const tr=node('tr'),folio=node('td'),status=node('td'),actions=node('td');folio.append(node('strong',p.folio),node('small',date(p.creado_unix)));
            status.append(node('span',states[p.estatus],'badge'+(p.estatus==='cancelada'?' inactive':p.estatus==='borrador'?' purchase-draft':'')));
            const b=button('Abrir',()=>showPurchase(p.id));b.setAttribute('aria-label','Abrir compra '+p.folio);actions.append(b);
            tr.append(folio,node('td',p.proveedor_nombre),node('td',p.ubicacion_nombre),node('td',currency(p.total)),status,actions);$('purchase-rows').append(tr);
        });$('purchase-empty').hidden=list.length!==0;$('purchase-count').textContent=list.length+(list.length===1?' compra':' compras')+' · '+purchases.filter(p=>p.estatus==='pedida').length+' pendientes de recibir';
    }
    $('purchase-search').addEventListener('input',render);$('purchase-status-filter').addEventListener('change',render);
    function options(select,rows,selected='',blank=null){select.replaceChildren();if(blank!==null){const o=node('option',blank);o.value='';select.append(o);}rows.forEach(r=>{const o=node('option',r.nombre+(r.disponible_venta!==undefined&&!Number(r.disponible_venta)?' (resguardo)':'')+(r.activo!==undefined&&!Number(r.activo)?' (inactivo)':''));o.value=r.id;select.append(o);});select.value=selected==null?'':String(selected);}
    function eligible(rows,selected){return rows.filter(r=>Number(r.activo)||String(r.id)===String(selected));}
    function edit(p=null){
        if(!leave())return;current=p;requestKey=uuid();$('purchase-form').reset();$('purchase-error').textContent='';$('purchase-lines').replaceChildren();
        $('purchase-editor-title').textContent=p?'Editar '+p.folio:'Crear compra';
        options($('purchase-supplier'),eligible(meta.proveedores,p?p.proveedor_id:null),p?p.proveedor_id:'','Selecciona un proveedor');
        options($('purchase-location'),eligible(meta.ubicaciones,p?p.ubicacion_id:null),p?p.ubicacion_id:((meta.ubicaciones.find(l=>Number(l.activo))||{}).id),'Selecciona una ubicación');
        $('purchase-payment-condition').value=p?p.condicion_pago:'sin_definir';$('purchase-payment-due').value=p&&p.condicion_pago==='credito'?(p.vence||''):'';paymentCondition();
        $('purchase-invoice').value=p?(p.factura_proveedor||''):'';$('purchase-notes').value=p?(p.notas||''):'';$('purchase-status').value=p?p.estatus:'borrador';
        $('purchase-status').querySelector('option[value="borrador"]').disabled=!!(p&&p.estatus==='pedida');
        (p?p.detalles:[{}]).forEach(addLine);panels('purchase-editor');preview();dirty=false;msg('');$('purchase-supplier').focus();
        if(!meta.proveedores.some(s=>Number(s.activo)))$('purchase-error').textContent='Primero registra un proveedor desde el botón Proveedores.';
    }
    function paymentCondition(){const credit=$('purchase-payment-condition').value==='credito';$('purchase-payment-due').required=credit;$('purchase-payment-due').disabled=!credit;if(!credit)$('purchase-payment-due').value='';}
    $('purchase-payment-condition').addEventListener('change',paymentCondition);
    $('purchase-new').addEventListener('click',()=>edit());
    function addLine(line={}){
        const index=++lineIndex,fieldset=node('fieldset',undefined,'variant-fields');fieldset.append(node('legend','Producto de la compra'));const grid=node('div',undefined,'purchase-line-grid');
        const productWrap=node('div',undefined,'purchase-line-product'),select=node('select');select.dataset.field='variante_id';select.id='purchase-line-'+index+'-variant';select.required=true;
        const catalog=meta.variantes.map(v=>({id:v.id,nombre:v.producto_nombre+(v.variante_nombre?' · '+v.variante_nombre:'')+' · '+v.sku}));
        if(line.variante_id&&!catalog.some(v=>String(v.id)===String(line.variante_id)))catalog.push({id:line.variante_id,nombre:line.producto_nombre+' · '+line.sku+' (no disponible)'});
        options(select,catalog,line.variante_id||'','Selecciona producto / SKU');const label=node('label','Producto o variante');label.htmlFor=select.id;productWrap.append(label,select);const unit=node('p','Unidad base: —','hint');productWrap.append(unit);grid.append(productWrap);
        const inputs={};[['cantidad','Cantidad',line.cantidad||'1'],['costo_unitario','Costo unitario sin IVA',line.costo_unitario||'0']].forEach(([key,text,value])=>{
            const wrap=node('div'),lab=node('label',text),input=node('input');input.type='number';input.min=key==='cantidad'?'0.0001':'0';input.max='9999999999.9999';input.step='.0001';input.required=true;input.value=value;input.dataset.field=key;input.id='purchase-line-'+index+'-'+key;lab.htmlFor=input.id;wrap.append(lab,input);grid.append(wrap);inputs[key]=input;
        });
        const taxWrap=node('div'),taxLabel=node('label','Impuesto'),tax=node('select');tax.id='purchase-line-'+index+'-tax';tax.dataset.field='impuesto_id';tax.required=true;taxLabel.htmlFor=tax.id;
        options(tax,eligible(meta.impuestos,line.impuesto_id),line.impuesto_id||((meta.impuestos.find(i=>Number(i.activo))||{}).id));taxWrap.append(taxLabel,tax);grid.append(taxWrap);fieldset.append(grid);
        const rowSummary=node('p','','hint');fieldset.append(rowSummary);fieldset._summary=rowSummary;
        const updateVariant=(fill)=>{
            const v=meta.variantes.find(item=>String(item.id)===select.value)||(line.variante_id&&String(line.variante_id)===select.value?line:null);
            unit.textContent=v?'Unidad base: '+v.unidad_codigo:'Unidad base: —';inputs.cantidad.step=v&&!Number(v.permite_decimales)?'1':'.0001';inputs.cantidad.min=inputs.cantidad.step;
            if(fill&&v){inputs.costo_unitario.value=v.costo||'0';if(v.impuesto_id)tax.value=v.impuesto_id;}
        };
        select.addEventListener('change',()=>{updateVariant(true);preview();});updateVariant(false);
        fieldset.append(button('Quitar renglón',()=>{fieldset.remove();dirty=true;requestKey=uuid();preview();}));$('purchase-lines').append(fieldset);
    }
    const scaled=value=>{const m=String(value).match(/^(\d{1,10})(?:\.(\d{1,4}))?$/);return m?BigInt(m[1])*10000n+BigInt((m[2]||'').padEnd(4,'0')):null;};
    function preview(){
        let subtotal=0n,taxTotal=0n;
        Array.from($('purchase-lines').children).forEach(row=>{
            const data={};row.querySelectorAll('[data-field]').forEach(input=>{data[input.dataset.field]=input.value;});
            const qty=scaled(data.cantidad),cost=scaled(data.costo_unitario),tax=meta.impuestos.find(i=>String(i.id)===data.impuesto_id),rate=tax?scaled(tax.tasa):null;
            if(qty===null||cost===null||rate===null){row._summary.textContent='Completa las cantidades, costos e impuestos.';return;}
            const base=(qty*cost+500000n)/1000000n,iva=(base*rate+5000n)/10000n;subtotal+=base;taxTotal+=iva;
            row._summary.textContent='Subtotal '+currency(Number(base)/100)+' · Impuesto '+currency(Number(iva)/100)+' · Total '+currency(Number(base+iva)/100);
        });$('purchase-preview-subtotal').textContent=currency(Number(subtotal)/100);$('purchase-preview-tax').textContent=currency(Number(taxTotal)/100);$('purchase-preview-total').textContent=currency(Number(subtotal+taxTotal)/100);
    }
    $('purchase-add-line').addEventListener('click',()=>{if($('purchase-lines').children.length>=100){$('purchase-error').textContent='El máximo es de 100 renglones.';return;}addLine();dirty=true;requestKey=uuid();preview();});
    $('purchase-form').addEventListener('input',()=>{dirty=true;requestKey=uuid();preview();});$('purchase-form').addEventListener('change',()=>{dirty=true;requestKey=uuid();preview();});
    const back=()=>{if(leave()){panels('purchase-list-panel');msg('');}};
    $('purchase-editor-back').addEventListener('click',back);$('purchase-discard').addEventListener('click',back);$('purchase-detail-back').addEventListener('click',back);$('suppliers-back').addEventListener('click',back);
    $('purchase-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const payload=Object.fromEntries(new FormData(event.currentTarget));payload.detalles=Array.from($('purchase-lines').children).map(row=>{const data={};row.querySelectorAll('[data-field]').forEach(input=>{data[input.dataset.field]=input.value;});return data;});
        if(current)payload.version=current.version;else payload.clave=requestKey;
        busy=true;$('purchase-fields').disabled=true;$('purchase-save').disabled=true;$('purchase-error').textContent='';
        try{const saved=await app.api(current?'/compras/'+current.id:'/compras',current?'PUT':'POST',payload);dirty=false;renderDetail(saved);msg('Compra guardada. El inventario se actualizará cuando recibas la mercancía.');try{await load();}catch(error){msg('La compra se guardó. Vuelve a abrir Compras para actualizar la lista.',true);}}
        catch(error){$('purchase-error').textContent=error.message;}
        finally{busy=false;$('purchase-fields').disabled=false;$('purchase-save').disabled=false;}
    });
    async function showPurchase(id){if(!leave())return;msg('Cargando compra…');try{renderDetail(await app.api('/compras/'+id));msg('');}catch(error){msg(error.message,true);}}
    function renderDetail(p){
        current=p;action=null;panels('purchase-detail');$('purchase-action-panel').hidden=true;
        const perms=app.current().permisos;$('purchase-payable').hidden=p.estatus!=='recibida'||(!perms.todo&&!perms.ver_cuentas_pagar);$('purchase-detail-payment').textContent='Condición de pago: '+(p.condicion_pago==='credito'?'Crédito · Vence: '+p.vence:p.condicion_pago==='contado'?'Contado':'Por definir / conciliar')+'. Consulta el saldo y registra los pagos desde Cuentas por pagar.';
        $('purchase-detail-title').textContent=p.folio+' · '+states[p.estatus];$('purchase-detail-meta').textContent=p.proveedor_nombre+' · Destino: '+p.ubicacion_nombre;
        $('purchase-detail-reference').textContent=p.factura_proveedor?'Referencia del proveedor: '+p.factura_proveedor:'Sin referencia del proveedor.';
        $('purchase-detail-rows').replaceChildren();p.detalles.forEach(line=>{
            const row=node('tr'),product=node('td');product.append(node('strong',line.producto_nombre),node('small',line.sku+(line.variante_nombre?' · '+line.variante_nombre:'')));
            row.append(product,node('td',quantity(line.cantidad)+' '+line.unidad_codigo),node('td',currency(line.costo_unitario)),node('td',line.impuesto_nombre+' · '+currency(line.impuesto_linea)),node('td',currency(line.total_linea)));$('purchase-detail-rows').append(row);
        });$('purchase-detail-subtotal').textContent=currency(p.subtotal);$('purchase-detail-tax').textContent=currency(p.impuesto_total);$('purchase-detail-total').textContent=currency(p.total);$('purchase-detail-notes').textContent=p.notas||'';
        $('purchase-detail-status').textContent=p.estatus==='recibida'?'Recibida por '+p.recibido_por_nombre+' el '+date(p.recibido_unix)+'. Las existencias y costos ya fueron actualizados.':p.estatus==='cancelada'?'Compra cancelada. No genera entradas de inventario.':'El inventario todavía no se ha modificado. Revisa las cantidades y costos antes de recibir.';
        const editable=['borrador','pedida'].includes(p.estatus);['purchase-edit','purchase-receive','purchase-cancel-order'].forEach(id=>{$(id).hidden=!editable;});
    }
    $('purchase-edit').addEventListener('click',()=>edit(current));
    function confirmAction(type){
        action=type;$('purchase-action-panel').hidden=false;$('purchase-action-error').textContent='';
        $('purchase-action-title').textContent=type==='recibir'?'Recibir mercancía':'Cancelar compra';
        $('purchase-action-description').textContent=type==='recibir'?'Se recibirán todos los renglones en '+current.ubicacion_nombre+', se actualizará el costo promedio y la compra quedará cerrada para edición. Confirma que la mercancía ya llegó y coincide con lo capturado.':'La compra quedará cancelada y no generará movimientos de inventario.';
        $('purchase-action-confirm').textContent=type==='recibir'?'Confirmar recepción':'Confirmar cancelación';$('purchase-action-panel').scrollIntoView({block:'nearest'});
    }
    $('purchase-receive').addEventListener('click',()=>confirmAction('recibir'));$('purchase-cancel-order').addEventListener('click',()=>confirmAction('cancelar'));$('purchase-action-dismiss').addEventListener('click',()=>{$('purchase-action-panel').hidden=true;action=null;});
    $('purchase-action-confirm').addEventListener('click',async()=>{
        if(busy||!action)return;busy=true;$('purchase-action-confirm').disabled=true;$('purchase-action-error').textContent='';
        try{const type=action,saved=await app.api('/compras/'+current.id+'/'+type,'POST',{version:current.version});renderDetail(saved);msg(type==='recibir'?'Mercancía recibida. Inventario y costo promedio actualizados.':'Compra cancelada.');try{await load();}catch(error){msg('La operación se guardó. Vuelve a abrir Compras para actualizar la lista.',true);}}
        catch(error){$('purchase-action-error').textContent=error.message;}
        finally{busy=false;$('purchase-action-confirm').disabled=false;}
    });
    $('purchase-payable').addEventListener('click',()=>{if(current&&app.openPayable)app.openPayable(current.id);});
    function renderSuppliers(){
        const query=$('supplier-search').value.trim().toLocaleLowerCase('es'),rows=meta.proveedores.filter(s=>[s.nombre,s.contacto,s.telefono].join(' ').toLocaleLowerCase('es').includes(query));
        $('supplier-rows').replaceChildren();rows.forEach(s=>{const row=node('tr'),name=node('td'),actions=node('td');name.append(node('strong',s.nombre),node('small',[s.contacto,s.telefono].filter(Boolean).join(' · ')));actions.append(button('Editar',()=>editSupplier(s)));row.append(name,node('td',Number(s.activo)?'Activo':'Inactivo'),actions);$('supplier-rows').append(row);});$('suppliers-empty').hidden=rows.length!==0;
    }
    function editSupplier(s=null,afterSave=false){if(!afterSave&&!leave())return;supplierId=s?s.id:null;$('supplier-form').reset();$('supplier-error').textContent='';$('supplier-editor-title').textContent=s?'Editar proveedor':'Crear proveedor';['name','contact','phone','email','address','notes'].forEach((key,index)=>{$('supplier-'+key).value=s?(s[['nombre','contacto','telefono','correo','direccion','notas'][index]]||''):'';});$('supplier-active').value=s?s.activo:'1';dirty=false;}
    $('purchase-suppliers').addEventListener('click',()=>{if(!leave())return;panels('suppliers-panel');renderSuppliers();editSupplier();msg('');});$('supplier-search').addEventListener('input',renderSuppliers);$('supplier-new').addEventListener('click',()=>editSupplier());
    $('supplier-form').addEventListener('input',()=>{dirty=true;});$('supplier-form').addEventListener('change',()=>{dirty=true;});
    $('supplier-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;busy=true;$('supplier-error').textContent='';const payload=Object.fromEntries(new FormData(event.currentTarget));
        const controls=Array.from(event.currentTarget.querySelectorAll('input,select,textarea,button'));controls.forEach(el=>{el.disabled=true;});
        try{await app.api(supplierId?'/proveedores/'+supplierId:'/proveedores',supplierId?'PUT':'POST',payload);dirty=false;await load();renderSuppliers();editSupplier(null,true);msg('Proveedor guardado. Ya puedes seleccionarlo en una compra.');}
        catch(error){$('supplier-error').textContent=error.message;}finally{busy=false;controls.forEach(el=>{el.disabled=false;});}
    });
})();
