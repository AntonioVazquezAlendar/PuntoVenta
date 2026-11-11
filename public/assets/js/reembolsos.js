'use strict';
(() => {
    const $=id=>document.getElementById(id),app=window.PuntoVenta;
    let sale=null,context=null,documents=[],next=null,mode=null,quote=null,dirty=false,busy=false,request=0,key='',payments=[],flash=null,byFolio=false,lookupFolio='',pendingPayload=null;
    const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
    document.body.append($('refund-authorize-dialog'));
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n));
    const qty=n=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(n));
    const date=n=>new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(n)*1000));
    const cents=v=>/^\d{1,12}(?:\.\d{1,2})?$/.test(String(v))?Math.round(Number(v)*100):null;
    const uuid=()=>{const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=Array.from(b,v=>v.toString(16).padStart(2,'0')).join('');return h.slice(0,8)+'-'+h.slice(8,12)+'-'+h.slice(12,16)+'-'+h.slice(16,20)+'-'+h.slice(20);};
    function clearForm(){request++;dirty=false;mode=null;quote=null;payments=[];$('refund-form').hidden=true;$('sales-ticket-paper').hidden=false;$('refund-error').textContent='';}
    app.leaveRefunds=()=>{if(busy){$('refund-status').textContent='Espera a que termine el registro del reembolso.';return false;}if(dirty&&!window.confirm('Tienes una devolución sin registrar. ¿Quieres descartarla?'))return false;clearForm();return true;};
    window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});
    function buttons(){const hasTurn=context&&context.turnos.length>0;$('refund-start-return').hidden=!context||!context.puede_devolver;$('refund-start-cancel').hidden=!context||!context.puede_cancelar;$('refund-start-return').disabled=!hasTurn||busy;$('refund-start-cancel').disabled=!hasTurn||busy;}
    document.addEventListener('puntoventa:ticket',async event=>{
        clearForm();sale=event.detail.venta;byFolio=false;lookupFolio='';$('sales-ticket-panel').append($('refund-section'));if(flash&&String(flash.id)!==String(sale.id))flash=null;context=null;documents=[];next=null;$('refund-section').hidden=false;$('refund-status').textContent='Cargando devoluciones…';$('refund-documents').replaceChildren();buttons();const version=request;
        try{const [ctx,history]=await Promise.all([app.api('/ventas/'+sale.id+'/reembolsos/contexto'),app.api('/ventas/'+sale.id+'/reembolsos')]);if(version!==request)return;context=ctx;documents=history.documentos;next=history.siguiente;buttons();renderHistory();$('refund-net').textContent='Reembolsado: '+money(ctx.venta.reembolsado)+' · Importe neto: '+money(ctx.venta.neto);$('refund-status').textContent=flash?flash.text:!ctx.turnos.length&&(ctx.puede_devolver||ctx.puede_cancelar)?'Abre tu caja para registrar un reembolso.':'';flash=null;}
        catch(error){if(version===request)$('refund-status').textContent=error.message;}
    });
    $('sales-refund-lookup').addEventListener('click',()=>{
        if(!app.openRefundLookup())return;byFolio=true;lookupFolio='';sale=null;context=null;documents=[];next=null;
        $('refund-lookup-host').append($('refund-section'));$('refund-section').hidden=true;$('refund-lookup-message').textContent='';$('refund-lookup-folio').focus();
    });
    $('refund-lookup-back').addEventListener('click',()=>$('sales-history').click());
    async function loadFolio(folio){
        const ctx=await app.api('/reembolsos/folio','POST',{folio});context=ctx;sale=ctx.venta;lookupFolio=ctx.venta.folio;documents=[];next=null;
        $('refund-section').hidden=false;$('refund-net').textContent=sale.folio+' · Total original: '+money(sale.total)+' · Reembolsado: '+money(sale.reembolsado)+' · Neto: '+money(sale.neto);
        $('refund-documents').replaceChildren();$('refund-more').hidden=true;buttons();
        $('refund-status').textContent=!ctx.turnos.length?'Abre tu caja antes de registrar el reembolso.':!ctx.puede_devolver&&!ctx.puede_cancelar?'Este ticket no tiene operaciones disponibles.':'';
    }
    $('refund-lookup-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy||!app.leaveRefunds())return;context=null;buttons();$('refund-section').hidden=true;$('refund-lookup-search').disabled=true;$('refund-lookup-message').textContent='Buscando ticket…';
        try{await loadFolio($('refund-lookup-folio').value.trim());$('refund-lookup-message').textContent='Consulta limitada a la devolución o cancelación de este folio.';}
        catch(error){$('refund-lookup-message').textContent=error.message;}finally{$('refund-lookup-search').disabled=false;}
    });
    function renderHistory(){
        $('refund-documents').replaceChildren();$('refund-more').hidden=!next;
        const old=$('sales-ticket-paper').querySelector('.refund-ticket-summary');if(old)old.remove();const receipt=node('div',undefined,'refund-ticket-summary');
        if(!documents.length){$('refund-documents').append(node('p','Este ticket no tiene cancelaciones ni devoluciones.','hint'));return;}
        receipt.append(node('hr'),node('strong','Reembolsado: '+money(context.venta.reembolsado)),node('strong','Importe neto: '+money(context.venta.neto)));
        documents.forEach(d=>{const item=node('article',undefined,'refund-document');item.append(node('h3',(d.tipo==='cancelacion'?'Cancelación ':'Devolución ')+d.folio),node('p',date(d.creado_unix)+' · '+d.usuario_nombre+' · '+d.caja_nombre,'hint'),node('strong','Reembolso: '+money(d.total)),node('p','Motivo: '+d.motivo));
            if(d.autorizado_nombre){item.append(node('p','Autorizó: '+d.autorizado_nombre,'hint'));receipt.append(node('p','Autorizó: '+d.autorizado_nombre));}
            receipt.append(node('p',d.folio+' · '+date(d.creado_unix)+' · '+d.usuario_nombre),node('p','Reembolso: '+money(d.total)),node('p','Motivo: '+d.motivo));
            const list=node('ul');d.detalles.forEach(l=>{const description=qty(l.cantidad)+' × '+l.descripcion+' · '+(l.ubicacion_nombre?'Reingreso: '+l.ubicacion_nombre:'Sin reingreso al inventario');list.append(node('li',description));receipt.append(node('p',description));});item.append(list);
            d.pagos.forEach(p=>{const text=p.nombre_metodo+': '+money(p.monto)+(p.referencia?' · '+p.referencia:'');item.append(node('p',text,'hint'));receipt.append(node('p',text));});$('refund-documents').append(item);
        });$('sales-ticket-paper').append(receipt);
    }
    $('refund-more').addEventListener('click',async()=>{if(!next||busy)return;$('refund-more').disabled=true;const id=sale.id;try{const data=await app.api('/ventas/'+id+'/reembolsos?antes_de='+next);if(String(sale.id)!==String(id))return;documents.push(...data.documentos);next=data.siguiente;renderHistory();}catch(error){$('refund-status').textContent=error.message;}finally{$('refund-more').disabled=false;}});
    function invalidate(){request++;quote=null;key=uuid();dirty=true;$('refund-quote').hidden=true;$('refund-confirm').checked=false;$('refund-save').disabled=true;$('refund-error').textContent='';}
    function start(tipo){
        if(!context||busy||!app.leaveRefunds())return;mode=tipo;$('refund-form').reset();$('refund-form').hidden=false;$('sales-ticket-paper').hidden=true;$('refund-form-title').textContent=tipo==='cancelacion'?'Cancelar venta completa':'Devolver productos';$('refund-help').textContent=tipo==='cancelacion'?'Anula toda la venta y regresa su mercancía a la ubicación de esta caja. Requiere el turno original abierto y ninguna devolución previa.':'Indica las cantidades recibidas. Puedes devolver solo una parte y elegir dónde reingresa la mercancía.';$('refund-save').textContent=tipo==='cancelacion'?'Registrar cancelación y reembolso':'Registrar devolución';$('refund-quote').hidden=true;$('refund-save').disabled=true;$('refund-status').textContent='';$('refund-turn').replaceChildren();
        context.turnos.forEach(t=>{const o=node('option',t.caja_nombre+' · '+t.ubicacion_nombre);o.value=t.id;$('refund-turn').append(o);});$('refund-lines').replaceChildren();
        context.detalles.filter(l=>Number(l.pendiente)>0).forEach(l=>{const row=node('tr'),name=node('td'),quantity=node('td'),destination=node('td');row.dataset.line=l.id;name.append(node('strong',l.descripcion),node('small',l.unidad_codigo));const input=node('input');input.type='number';input.min='0';input.max=l.pendiente;input.step=l.permite_decimales?'.0001':'1';input.value=tipo==='cancelacion'?String(Number(l.pendiente)):'0';input.readOnly=tipo==='cancelacion';input.dataset.quantity='1';input.setAttribute('aria-label','Devolver '+l.descripcion);quantity.append(input);
            if(!l.controla_inventario)destination.append(node('span','Servicio · sin inventario','hint'));
            else{const select=node('select');select.dataset.destination='1';select.setAttribute('aria-label','Destino de '+l.descripcion);[['venta','Volver a ubicación de mi caja'],['resguardo','Enviar a resguardo'],['sin_reingreso','Sin reingreso (descartado)']].forEach(([value,label])=>{const o=node('option',label);o.value=value;o.disabled=value==='resguardo'&&!context.resguardos.length;select.append(o);});select.disabled=tipo==='cancelacion';destination.append(select);const location=node('select');location.dataset.location='1';location.setAttribute('aria-label','Resguardo para '+l.descripcion);location.hidden=true;context.resguardos.forEach(l=>{const o=node('option',l.nombre);o.value=l.id;location.append(o);});select.addEventListener('change',()=>{location.hidden=select.value!=='resguardo';});destination.append(location);}
            row.append(name,node('td',qty(l.pendiente)+' '+l.unidad_codigo),quantity,destination);$('refund-lines').append(row);
        });locationText();dirty=false;key=uuid();$('refund-reason').focus();
    }
    function locationText(){const t=context.turnos.find(t=>String(t.id)===$('refund-turn').value);$('refund-location').textContent=t?'El efectivo sale de esta caja. El reingreso a venta se registra en '+t.ubicacion_nombre+'.':'';}
    $('refund-start-return').addEventListener('click',()=>start('devolucion'));$('refund-start-cancel').addEventListener('click',()=>start('cancelacion'));$('refund-discard').addEventListener('click',()=>app.leaveRefunds());
    $('refund-turn').addEventListener('change',locationText);
    ['input','change'].forEach(event=>$('refund-form').addEventListener(event,e=>{if(e.target.closest('#refund-payments')||e.target.id==='refund-confirm'){key=uuid();$('refund-confirm').checked=e.target.id==='refund-confirm'?e.target.checked:false;paymentStatus();}else invalidate();}));
    function inputData(){return {...(byFolio?{folio:lookupFolio}:{}),tipo:mode,turno_id:$('refund-turn').value,motivo:$('refund-reason').value,detalles:mode==='cancelacion'?[]:Array.from($('refund-lines').children).filter(r=>Number(r.querySelector('[data-quantity]').value)>0).map(r=>{const dest=r.querySelector('[data-destination]');return {detalle_venta_id:r.dataset.line,cantidad:r.querySelector('[data-quantity]').value,destino:dest?dest.value:'sin_reingreso',ubicacion_id:dest&&dest.value==='resguardo'?r.querySelector('[data-location]').value:null};})};}
    $('refund-calculate').addEventListener('click',async()=>{
        if(busy||!$('refund-form').reportValidity())return;const data=inputData();if(mode==='devolucion'&&!data.detalles.length){$('refund-error').textContent='Selecciona al menos una cantidad para devolver.';return;}const version=++request;$('refund-calculate').disabled=true;
        try{const result=await app.api('/ventas/'+sale.id+'/reembolsos/cotizar','POST',data);if(version!==request)return;quote=result;key=uuid();$('refund-error').textContent='';$('refund-quote').hidden=false;$('refund-amounts').replaceChildren();[['Subtotal',result.subtotal],['Impuestos',result.impuesto_total],['Total a reembolsar',result.total]].forEach(([label,value])=>{const row=node('div');row.append(node('dt',label),node('dd',money(value)));$('refund-amounts').append(row);});$('refund-cash').textContent='Efectivo disponible en esta caja: '+money(result.efectivo_disponible);payments=[];let rest=cents(result.total);
            result.pagos.forEach(p=>{const available=cents(p.disponible);if(available<=0)return;const n=Math.min(rest,available);rest-=n;payments.push({...p,monto:(n/100).toFixed(2),referencia:''});});renderPayments();$('refund-confirm').checked=false;paymentStatus();
        }catch(error){if(version===request)$('refund-error').textContent=error.message;}finally{$('refund-calculate').disabled=false;}
    });
    function renderPayments(){
        $('refund-payments').replaceChildren();payments.forEach((p,i)=>{const group=node('div',undefined,'refund-payment'),label=node('label','Reembolsar por '+p.nombre),input=node('input');input.id='refund-pay-'+i;label.htmlFor=input.id;input.type='number';input.min='0';input.max=p.disponible;input.step='.01';input.value=p.monto;input.addEventListener('input',()=>{p.monto=input.value;});group.append(label,input,node('p','Máximo pendiente: '+money(p.disponible),'hint'));const ref=node('input'),lab=node('label','Referencia del reembolso (opcional)');ref.id='refund-ref-'+i;lab.htmlFor=ref.id;ref.maxLength=100;ref.value=p.referencia;ref.addEventListener('input',()=>{p.referencia=ref.value;});group.append(lab,ref);$('refund-payments').append(group);});
    }
    function paymentStatus(){let sum=0,cash=0,valid=!!quote;payments.forEach(p=>{const n=cents(p.monto);if(n===null||n>cents(p.disponible)){valid=false;return;}sum+=n;if(p.tipo==='efectivo')cash+=n;});const total=quote?cents(quote.total):0;valid=valid&&sum===total&&cash<=Math.max(0,quote?Math.round(Number(quote.efectivo_disponible)*100):0);$('refund-payment-status').textContent=!quote?'':sum!==total?'Los importes deben sumar '+money(total/100)+'.':cash>Math.max(0,Number(quote.efectivo_disponible)*100)?'No hay suficiente efectivo en esta caja.':'Reembolso distribuido: '+money(sum/100)+'.';$('refund-save').disabled=busy||!valid||!$('refund-confirm').checked;}
    function closeAuthorization(){if(busy)return;$('refund-authorize-dialog').close();$('refund-authorize-form').reset();pendingPayload=null;}
    $('refund-authorize-cancel').addEventListener('click',closeAuthorization);
    $('refund-authorize-dialog').addEventListener('cancel',event=>{if(busy){event.preventDefault();return;}$('refund-authorize-form').reset();pendingPayload=null;});
    $('refund-form').addEventListener('submit',event=>{
        event.preventDefault();if(busy||!quote||!$('refund-confirm').checked)return;
        pendingPayload={...inputData(),sello:quote.sello,clave:key,reembolsos:payments.filter(p=>cents(p.monto)>0).map(p=>({pago_venta_id:p.pago_venta_id,monto:p.monto,referencia:p.referencia}))};
        if(!context.requiere_autorizacion){registerRefund();return;}
        $('refund-authorize-form').reset();$('refund-authorize-error').textContent='';const summary=$('refund-authorize-summary');summary.replaceChildren();
        summary.append(node('strong',(mode==='cancelacion'?'Cancelar venta':'Devolver productos')+' · '+sale.folio),node('p','Total a reembolsar: '+money(quote.total)),node('p','Motivo: '+pendingPayload.motivo));
        quote.detalles.forEach(l=>summary.append(node('p',qty(l.cantidad)+' × '+l.descripcion+' · '+(l.ubicacion_nombre?'Reingreso: '+l.ubicacion_nombre:'Sin reingreso'))));
        const turn=context.turnos.find(t=>String(t.id)===pendingPayload.turno_id);if(turn)summary.append(node('p','Caja: '+turn.caja_nombre));
        payments.filter(p=>cents(p.monto)>0).forEach(p=>summary.append(node('p',p.nombre+': '+money(p.monto))));
        $('refund-authorize-dialog').showModal();$('refund-admin-user').focus();
    });
    $('refund-authorize-form').addEventListener('submit',event=>{event.preventDefault();if(busy||!pendingPayload)return;registerRefund({nombre_usuario:$('refund-admin-user').value,contrasena:$('refund-admin-password').value});});
    async function registerRefund(authorization){
        const id=sale.id,payload={...pendingPayload,...(authorization?{autorizacion:authorization}:{})};busy=true;$('refund-fields').disabled=true;$('refund-save').disabled=true;$('refund-authorize-fields').disabled=true;$('refund-authorize-save').disabled=true;$('refund-error').textContent='';$('refund-authorize-error').textContent='';buttons();
        try{
            const result=await app.api('/ventas/'+id+'/reembolsos','POST',payload);dirty=false;pendingPayload=null;$('refund-authorize-dialog').close();$('refund-authorize-form').reset();
            const text=result.folio+' registrado. Reembolso: '+money(result.total)+'.';flash={id,text};clearForm();
            if(byFolio){await loadFolio(lookupFolio);$('refund-status').textContent=text;flash=null;}
            else{try{await app.refreshSalesTicket(id);$('refund-status').textContent=text;}catch(error){$('refund-status').textContent=text+' Vuelve a abrir el ticket para actualizar su historial.';}}
        }catch(error){if($('refund-authorize-dialog').open)$('refund-authorize-error').textContent=error.message;else $('refund-error').textContent=error.message;}
        finally{$('refund-admin-password').value='';busy=false;$('refund-fields').disabled=false;$('refund-authorize-fields').disabled=false;$('refund-authorize-save').disabled=false;buttons();paymentStatus();}
    }
})();
