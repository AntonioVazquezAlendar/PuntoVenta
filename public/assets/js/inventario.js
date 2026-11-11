'use strict';
(() => {
    const $=id=>document.getElementById(id), app=window.PuntoVenta;
    let canAlerts=false;
    let rows=[],locations=[],canAdjust=false,selected=null,dirty=false,busy=false,key='',next=null,historyVariant=null;
    let canTransfer=false,canWaste=false,canLocations=false,operationType=null,destinationBalance=null,locationId=null,operationNext=null;
    const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;};
    const qty=value=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(value));
    const scaled=value=>{const match=String(value).match(/^(-?)(\d{1,10})(?:\.(\d{1,4}))?$/);return match?(match[1]? -1:1)*(Number(match[2])*10000+Number((match[3]||'').padEnd(4,'0'))):null;};
    const uuid=()=>{const bytes=crypto.getRandomValues(new Uint8Array(16));bytes[6]=(bytes[6]&15)|64;bytes[8]=(bytes[8]&63)|128;const h=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);};
    const msg=(text,error=false)=>{$('inventory-message').textContent=text;$('inventory-message').classList.toggle('message',error);};
    const location=()=>locations.find(l=>String(l.id)===$('inventory-location').value);
    const enabled=row=>Number(row.producto_activo)&&Number(row.variante_activo)&&Number(row.controla_inventario);
    const button=(text,action)=>{const b=node('button',text,'edit-button');b.type='button';b.addEventListener('click',action);return b;};
    const panels=id=>{['inventory-stock-panel','inventory-adjust-panel','inventory-history-panel','warehouse-operation-panel','warehouse-locations-panel','warehouse-ledger-panel','inventory-alerts-panel','inventory-minimum-panel'].forEach(name=>{$(name).hidden=name!==id;});$('inventory-alerts').hidden=!canAlerts||id!=='inventory-stock-panel';$('inventory-reload').hidden=['inventory-alerts-panel','inventory-minimum-panel'].includes(id);$('inventory-history').hidden=id!=='inventory-stock-panel';$('inventory-locations').hidden=!canLocations||id!=='inventory-stock-panel';$('inventory-operations').hidden=id!=='inventory-stock-panel';};

    app.inventoryPanel=panels;
    app.refreshInventory=()=>loadStock();

    function leave(){
        if(busy){msg('Espera a que termine el registro del ajuste.');return false;}
        if(dirty&&!window.confirm('Tienes un ajuste sin guardar. ¿Quieres descartarlo?'))return false;
        dirty=false;return true;
    }
    app.leaveInventory=leave;
    app.openInventory=()=>{
        if(app.leaveCatalog&&!app.leaveCatalog())return false;
        if(!app.openCatalog())return false;
        $('products-view').hidden=true;$('nav-products').removeAttribute('aria-current');
        $('inventory-view').hidden=false;$('nav-inventory').setAttribute('aria-current','page');return true;
    };
    window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});

    async function loadStock(){
        const id=$('inventory-location').value;
        if(!id){rows=[];render();return;}
        rows=await app.api('/inventario?ubicacion_id='+encodeURIComponent(id));
        const place=location();$('inventory-location-note').textContent=place?(!Number(place.activo)?'Ubicación inactiva: no se incluye en el disponible para venta.':Number(place.disponible_venta)?'Mercancía disponible para venta.':'Ubicación de resguardo: su mercancía no cuenta como disponible para venta.')+(place.descripcion?' '+place.descripcion:''):'';
        render();
    }
    async function loadLocations(){
        locations=await app.api('/inventario/ubicaciones');const previous=$('inventory-location').value;$('inventory-location').replaceChildren();
        locations.forEach(l=>{const o=node('option',l.nombre+(!Number(l.activo)?' (inactiva)':!Number(l.disponible_venta)?' (resguardo)':''));o.value=l.id;$('inventory-location').append(o);});
        if(locations.some(l=>String(l.id)===previous))$('inventory-location').value=previous;
    }
    async function open(){
        if(!leave()||!app.openInventory())return false;
        panels('inventory-stock-panel');msg('Cargando existencias…');$('inventory-rows').replaceChildren();
        try{
            const session=await app.api('/sesion'),user=session.usuario;
            if(!user||(!user.permisos.todo&&!user.permisos.ver_inventario))throw new Error('Tu cuenta no tiene permiso para consultar inventario.');
            canAlerts=!!(user.permisos.todo||user.permisos.ver_alertas_inventario);
            canAdjust=!!(user.permisos.todo||user.permisos.ajustar_inventario);
            canTransfer=!!(user.permisos.todo||user.permisos.realizar_traspasos);canWaste=!!(user.permisos.todo||user.permisos.registrar_mermas);canLocations=!!(user.permisos.todo||user.permisos.administrar_ubicaciones);
            panels('inventory-stock-panel');await loadLocations();
            await loadStock();msg(locations.length?'':'No hay ubicaciones registradas.');app.status('');return true;
        }catch(error){msg(error.message,true);return false;}
    }
    app.openInventoryScreen=open;
    $('nav-inventory').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{
        const p=event.detail.permisos;if(!p.todo&&!p.vender&&!p.ver_ventas&&!p.ver_productos&&p.ver_inventario)open();
    });

    function render(){
        const query=$('inventory-search').value.trim().toLocaleLowerCase('es'),filter=$('inventory-filter').value;
        const isLow=row=>scaled(row.stock_minimo)>0&&scaled(row.cantidad)<=scaled(row.stock_minimo);
        const filtered=rows.filter(row=>(filter==='all'||enabled(row))&&(filter!=='low'||isLow(row))&&(filter!=='negative'||scaled(row.cantidad)<0)
            &&[row.producto_nombre,row.variante_nombre,row.sku].join(' ').toLocaleLowerCase('es').includes(query));
        $('inventory-rows').replaceChildren();
        filtered.forEach(row=>{
            const tr=node('tr'),name=node('td');name.append(node('strong',row.producto_nombre),node('small',row.variante_nombre||'Variante única'));
            const status=node('td');let label='Disponible',cls='badge';
            if(!enabled(row)){label='Inactiva o sin control';cls+=' inactive';}
            else if(scaled(row.cantidad)<0){label='Negativo';cls+=' inventory-alert';}
            else if(location()&&!Number(location().disponible_venta)){label='En resguardo';cls+=' inactive';}
            else if(isLow(row)){label='En mínimo o bajo';cls+=' inventory-low';}
            status.append(node('span',label,cls));const actions=node('td');
            if(canAdjust&&enabled(row)&&location()&&Number(location().activo)){
                const b=button('Ajustar',()=>openAdjust(row));b.setAttribute('aria-label','Ajustar '+row.sku);actions.append(b);
            }
            if(Number(row.controla_inventario)&&scaled(row.cantidad)>0&&location()&&Number(location().activo)){
                if(canTransfer){const b=button('Traspasar',()=>openOperation(row,'traspaso'));b.setAttribute('aria-label','Traspasar '+row.sku);actions.append(b);}
                if(canWaste){const b=button('Merma',()=>openOperation(row,'merma'));b.setAttribute('aria-label','Merma de '+row.sku);actions.append(b);}
            }
            const history=button('Historial',()=>openHistory(row));history.setAttribute('aria-label','Historial de '+row.sku);actions.append(history);
            tr.append(name,node('td',row.sku),node('td',qty(row.cantidad)+' '+row.unidad_codigo),node('td',qty(row.stock_minimo)+' '+row.unidad_codigo),status,actions);$('inventory-rows').append(tr);
        });
        $('inventory-empty').hidden=filtered.length!==0;
        $('inventory-count').textContent=filtered.length+' variantes · '+rows.filter(r=>enabled(r)&&isLow(r)).length+' en mínimo o por debajo · '+rows.filter(r=>enabled(r)&&scaled(r.cantidad)<0).length+' con existencia negativa';
    }
    $('inventory-search').addEventListener('input',render);$('inventory-filter').addEventListener('change',render);
    $('inventory-location').addEventListener('change',async()=>{msg('Cargando existencias…');try{await loadStock();msg('');}catch(error){msg(error.message,true);}});
    $('inventory-reload').addEventListener('click',async()=>{
        if(!leave())return;panels('inventory-stock-panel');msg('Actualizando…');
        try{await loadStock();msg('Existencias actualizadas.');}catch(error){msg(error.message,true);}
    });

    function openAdjust(row){
        if(!leave())return;selected=row;key=uuid();$('inventory-adjust-form').reset();$('adjust-error').textContent='';
        const initial=!Number(row.tiene_movimientos)&&scaled(row.cantidad)===0;
        $('adjust-mode').querySelector('option[value="inicial"]').disabled=!initial;
        $('adjust-mode').value=initial?'inicial':'entrada';
        $('adjust-title').textContent='Ajustar '+row.sku;
        $('adjust-summary').textContent=row.producto_nombre+(row.variante_nombre?' · '+row.variante_nombre:'')+' · '+location().nombre;
        $('adjust-current').textContent=qty(row.cantidad)+' '+row.unidad_codigo;
        $('adjust-quantity').step=Number(row.permite_decimales)?'0.0001':'1';
        panels('inventory-adjust-panel');msg('');modeHelp();preview();dirty=false;$('adjust-quantity').focus();
    }
    function modeHelp(){
        const mode=$('adjust-mode').value;
        const help={inicial:'Registra el primer saldo de esta variante en la ubicación seleccionada. Solo puede hacerse si aún no tiene movimientos.',entrada:'Suma unidades al inventario. Úsalo para corregir diferencias; las compras se registrarán desde su propio módulo.',salida:'Resta unidades al inventario. Indica el motivo de la diferencia.',conteo:'Escribe cuántas unidades contaste físicamente. Se registrará la diferencia respecto al saldo actual.'};
        $('adjust-mode-help').textContent=help[mode];$('adjust-quantity-label').textContent=mode==='conteo'?'Cantidad contada físicamente':'Cantidad del movimiento';
        $('adjust-quantity').min=mode==='conteo'?'0':(Number(selected.permite_decimales)?'0.0001':'1');
    }
    function preview(){
        if(!selected)return;
        const amount=scaled($('adjust-quantity').value),current=scaled(selected.cantidad),mode=$('adjust-mode').value;
        if(amount===null||amount<0){$('adjust-change').textContent='—';$('adjust-result').textContent='—';$('adjust-warning').textContent='';return;}
        const delta=mode==='salida'?-amount:(mode==='conteo'?amount-current:amount),result=current+delta;
        $('adjust-change').textContent=(delta>0?'+':'')+qty(delta/10000)+' '+selected.unidad_codigo;
        $('adjust-result').textContent=qty(result/10000)+' '+selected.unidad_codigo;
        $('adjust-warning').textContent=result<0?'El ajuste dejará una existencia negativa. Verifica la cantidad y el motivo antes de registrarlo.':'';
    }
    $('adjust-mode').addEventListener('change',()=>{modeHelp();preview();});
    $('inventory-adjust-form').addEventListener('input',()=>{dirty=true;key=uuid();preview();});
    $('inventory-adjust-form').addEventListener('change',()=>{dirty=true;key=uuid();preview();});
    const closeAdjust=()=>{if(leave())panels('inventory-stock-panel');};
    $('adjust-back').addEventListener('click',closeAdjust);$('adjust-cancel').addEventListener('click',closeAdjust);
    $('inventory-adjust-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;$('adjust-error').textContent='';busy=true;
        const payload={ubicacion_id:$('inventory-location').value,variante_id:selected.variante_id,modo:$('adjust-mode').value,cantidad:$('adjust-quantity').value,motivo:$('adjust-reason').value,clave:key};
        if(payload.modo==='conteo')payload.saldo_esperado=selected.cantidad;
        $('adjust-fields').disabled=true;$('adjust-save').disabled=true;$('adjust-cancel').disabled=true;$('adjust-back').disabled=true;
        try{
            const result=await app.api('/inventario/ajustes','POST',payload);dirty=false;panels('inventory-stock-panel');
            msg('Movimiento registrado. Nueva existencia: '+qty(result.cantidad_nueva)+' '+selected.unidad_codigo+'.');
            try{await loadStock();}catch(error){msg('El ajuste se guardó. No se pudo actualizar la lista; pulsa Actualizar.',true);}
        }catch(error){$('adjust-error').textContent=error.message;}
        finally{busy=false;$('adjust-fields').disabled=false;$('adjust-save').disabled=false;$('adjust-cancel').disabled=false;$('adjust-back').disabled=false;}
    });

    async function openHistory(row=null){
        if(!leave())return;historyVariant=row;next=null;$('inventory-history-rows').replaceChildren();panels('inventory-history-panel');msg('Cargando movimientos…');
        $('history-description').textContent=(row?row.producto_nombre+' · '+row.sku+' · ':'')+(location()?location().nombre:'');
        try{await loadHistory(false);msg('');}catch(error){msg(error.message,true);}
    }
    async function loadHistory(append){
        const params=new URLSearchParams({ubicacion_id:$('inventory-location').value});
        if(historyVariant)params.set('variante_id',historyVariant.variante_id);if(append&&next)params.set('antes_de',next);
        const data=await app.api('/inventario/movimientos?'+params);next=data.siguiente;
        const labels={inventario_inicial:'Inventario inicial',ajuste_entrada:'Entrada por ajuste',ajuste_salida:'Salida por ajuste',ajuste_conteo:'Conteo físico',merma:'Merma definitiva'};
        data.movimientos.forEach(m=>{
            const row=node('tr'),product=node('td'),reason=node('td');product.append(node('strong',m.producto_nombre),node('small',m.sku+(m.variante_nombre?' · '+m.variante_nombre:'')));
            reason.append(node('strong',m.usuario_nombre||'Sistema'),node('small',m.notas||'Sin notas'));
            const date=new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(m.creado_unix)*1000));
            const movement=node('td',labels[m.tipo_referencia]||m.tipo_movimiento.replaceAll('_',' '));
            if(m.folio_documento)movement.append(node('small',m.folio_documento+(m.destino_nombre?' · '+m.origen_nombre+' → '+m.destino_nombre:'')));
            row.append(node('td',date),product,node('td',m.ubicacion_nombre),movement,node('td',(Number(m.cantidad)>0?'+':'')+qty(m.cantidad)+' '+m.unidad_codigo),reason);$('inventory-history-rows').append(row);
        });
        $('history-more').hidden=!next;$('history-empty').hidden=$('inventory-history-rows').children.length!==0;
    }
    $('inventory-history').addEventListener('click',()=>openHistory());
    $('history-back').addEventListener('click',()=>{panels('inventory-stock-panel');msg('');});
    $('history-more').addEventListener('click',async()=>{$('history-more').disabled=true;try{await loadHistory(true);}catch(error){msg(error.message,true);}finally{$('history-more').disabled=false;}});

    function openOperation(row,type){
        if(!leave())return;selected=row;operationType=type;key=uuid();destinationBalance=null;
        $('warehouse-operation-form').reset();$('warehouse-error').textContent='';
        const transfer=type==='traspaso';$('warehouse-operation-title').textContent=transfer?'Traspasar mercancía':'Registrar merma definitiva';
        $('warehouse-operation-summary').textContent=row.producto_nombre+(row.variante_nombre?' · '+row.variante_nombre:'')+' · '+row.sku+' · Origen: '+location().nombre;
        $('warehouse-destination-field').hidden=!transfer;$('warehouse-destination-preview').hidden=!transfer;$('warehouse-destination').required=transfer;
        $('warehouse-destination').replaceChildren();const blank=node('option','Selecciona un destino');blank.value='';$('warehouse-destination').append(blank);
        locations.filter(l=>Number(l.activo)&&String(l.id)!==$('inventory-location').value).forEach(l=>{const o=node('option',l.nombre+(Number(l.disponible_venta)?' (venta)':' (resguardo)'));o.value=l.id;$('warehouse-destination').append(o);});
        $('warehouse-quantity').step=Number(row.permite_decimales)?'.0001':'1';$('warehouse-quantity').min=$('warehouse-quantity').step;$('warehouse-quantity').max=row.cantidad;
        $('warehouse-origin-current').textContent=qty(row.cantidad)+' '+row.unidad_codigo;
        $('warehouse-confirm-label').textContent=transfer?'Confirmo que la mercancía ya se entregó al destino.':'Confirmo que estos productos se darán de baja definitivamente.';
        $('warehouse-save').textContent=transfer?'Confirmar traspaso':'Registrar merma';
        if(transfer&&$('warehouse-destination').options.length===1)$('warehouse-error').textContent='Primero crea otra ubicación desde Ubicaciones.';
        panels('warehouse-operation-panel');previewOperation();dirty=false;msg('');$('warehouse-quantity').focus();
    }
    function previewOperation(){
        if(!selected)return;const value=scaled($('warehouse-quantity').value),base=scaled(selected.cantidad),transfer=operationType==='traspaso';
        $('warehouse-origin-next').textContent=value!==null?qty((base-value)/10000)+' '+selected.unidad_codigo:'—';
        $('warehouse-destination-next').textContent=value!==null&&destinationBalance!==null?qty((destinationBalance+value)/10000)+' '+selected.unidad_codigo:'—';
        const target=locations.find(l=>String(l.id)===$('warehouse-destination').value);
        $('warehouse-destination-help').textContent=target?(Number(target.disponible_venta)?'El destino está habilitado para venta. Verifica que la mercancía esté en condiciones de venderse.':'La mercancía quedará en resguardo y no contará como disponible para venta.'):'';
        $('warehouse-impact').textContent=value!==null&&value>base?'La cantidad supera la existencia del origen.':transfer?'El traspaso conserva el inventario físico total y no cambia el costo ni el precio.':'La merma reduce el inventario físico total. Si conservarás el producto para revisión o devolución, utiliza un traspaso a resguardo.';
    }
    $('warehouse-destination').addEventListener('change',async()=>{
        const id=$('warehouse-destination').value;destinationBalance=null;previewOperation();if(!id)return;
        try{const list=await app.api('/inventario?ubicacion_id='+encodeURIComponent(id));if($('warehouse-destination').value===id){const row=list.find(r=>String(r.variante_id)===String(selected.variante_id));destinationBalance=row?scaled(row.cantidad):0;previewOperation();}}
        catch(error){$('warehouse-error').textContent=error.message;}
    });
    ['input','change'].forEach(event=>$('warehouse-operation-form').addEventListener(event,()=>{dirty=true;key=uuid();previewOperation();}));
    const closeOperation=()=>{if(leave())panels('inventory-stock-panel');};$('warehouse-operation-back').addEventListener('click',closeOperation);$('warehouse-cancel').addEventListener('click',closeOperation);
    $('warehouse-operation-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const payload={origen_id:$('inventory-location').value,variante_id:selected.variante_id,cantidad:$('warehouse-quantity').value,motivo:$('warehouse-reason').value,clave:key};
        if(operationType==='traspaso')payload.destino_id=$('warehouse-destination').value;
        busy=true;$('warehouse-operation-fields').disabled=true;['warehouse-save','warehouse-cancel','warehouse-operation-back'].forEach(id=>{$(id).disabled=true;});$('warehouse-error').textContent='';
        try{const result=await app.api('/almacen/'+(operationType==='traspaso'?'traspasos':'mermas'),'POST',payload);dirty=false;panels('inventory-stock-panel');
            msg(result.folio+' registrado. Existencia en origen: '+qty(result.origen_nuevo)+' '+selected.unidad_codigo+'.');
            try{await loadStock();}catch(error){msg('La operación se guardó. Pulsa Actualizar para recargar las existencias.',true);}
        }catch(error){$('warehouse-error').textContent=error.message;}
        finally{busy=false;$('warehouse-operation-fields').disabled=false;['warehouse-save','warehouse-cancel','warehouse-operation-back'].forEach(id=>{$(id).disabled=false;});}
    });

    function renderLocations(){
        $('warehouse-location-rows').replaceChildren();locations.forEach(l=>{const row=node('tr'),name=node('td'),actions=node('td');name.append(node('strong',l.nombre),node('small',l.descripcion||''));actions.append(button('Editar',()=>editLocation(l)));
            row.append(name,node('td',(Number(l.disponible_venta)?'Venta':'Resguardo')+' · '+(Number(l.activo)?'Activa':'Inactiva')),actions);$('warehouse-location-rows').append(row);
        });
    }
    function editLocation(l=null,afterSave=false){
        if(!afterSave&&!leave())return;locationId=l?l.id:null;$('warehouse-location-form').reset();$('warehouse-location-error').textContent='';
        $('warehouse-location-editor-title').textContent=l?'Editar ubicación':'Crear ubicación';$('warehouse-location-name').value=l?l.nombre:'';$('warehouse-location-description').value=l?(l.descripcion||''):'';
        $('warehouse-location-use').value=l?l.disponible_venta:'1';$('warehouse-location-active').value=l?l.activo:'1';dirty=false;
    }
    $('inventory-locations').addEventListener('click',()=>{if(!leave())return;panels('warehouse-locations-panel');renderLocations();editLocation();msg('');});
    $('warehouse-location-new').addEventListener('click',()=>editLocation());
    ['input','change'].forEach(event=>$('warehouse-location-form').addEventListener(event,()=>{dirty=true;}));
    $('warehouse-location-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const payload=Object.fromEntries(new FormData(event.currentTarget));busy=true;$('warehouse-location-fields').disabled=true;$('warehouse-location-save').disabled=true;$('warehouse-location-error').textContent='';
        try{await app.api(locationId?'/almacen/ubicaciones/'+locationId:'/almacen/ubicaciones',locationId?'PUT':'POST',payload);dirty=false;await loadLocations();await loadStock();renderLocations();editLocation(null,true);msg('Ubicación guardada. Ya puedes seleccionarla en inventario y compras.');}
        catch(error){$('warehouse-location-error').textContent=error.message;}
        finally{busy=false;$('warehouse-location-fields').disabled=false;$('warehouse-location-save').disabled=false;}
    });
    $('warehouse-locations-back').addEventListener('click',()=>{if(leave())panels('inventory-stock-panel');});

    async function loadOperations(append=false){
        const params=new URLSearchParams({ubicacion_id:$('inventory-location').value});if(append&&operationNext)params.set('antes_de',operationNext);
        const data=await app.api('/almacen/operaciones?'+params);operationNext=data.siguiente;
        data.operaciones.forEach(o=>{const row=node('tr'),folio=node('td'),product=node('td'),reason=node('td');
            const date=new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(o.creado_unix)*1000));
            folio.append(node('strong',o.folio),node('small',date));product.append(node('strong',o.producto_nombre),node('small',o.sku+(o.variante_nombre?' · '+o.variante_nombre:'')));reason.append(node('strong',o.usuario_nombre),node('small',o.motivo));
            row.append(folio,node('td',o.tipo==='traspaso'?'Traspaso':'Merma'),product,node('td',o.origen_nombre+' → '+(o.destino_nombre||'Baja definitiva')),node('td',qty(o.cantidad)+' '+o.unidad_codigo),reason);$('warehouse-ledger-rows').append(row);
        });$('warehouse-ledger-more').hidden=!operationNext;$('warehouse-ledger-empty').hidden=$('warehouse-ledger-rows').children.length!==0;
    }
    $('inventory-operations').addEventListener('click',async()=>{
        if(!leave())return;panels('warehouse-ledger-panel');$('warehouse-ledger-rows').replaceChildren();operationNext=null;$('warehouse-ledger-description').textContent='Operaciones que salen de o llegan a '+(location()?location().nombre:'esta ubicación')+'.';msg('Cargando operaciones…');
        try{await loadOperations();msg('');}catch(error){msg(error.message,true);}
    });
    $('warehouse-ledger-more').addEventListener('click',async()=>{$('warehouse-ledger-more').disabled=true;try{await loadOperations(true);}catch(error){msg(error.message,true);}finally{$('warehouse-ledger-more').disabled=false;}});
    $('warehouse-ledger-back').addEventListener('click',()=>{panels('inventory-stock-panel');msg('');});
})();
