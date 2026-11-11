'use strict';
(() => {
    const $=id=>document.getElementById(id),app=window.PuntoVenta;
    let meta={cajas:[],ubicaciones:[]},turns=[],next=null,user=null,current=null,ownTurn=null,configId=null,mode=null,dirty=false,busy=false,key='';
    const node=(tag,text,cls)=>{const el=document.createElement(tag);if(text!==undefined)el.textContent=text;if(cls)el.className=cls;return el;};
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n));
    const date=n=>new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(n)*1000));
    const cents=value=>{const m=String(value).match(/^(-?)(\d{1,12})(?:\.(\d{1,2}))?$/);return m?(m[1]?-1:1)*(Number(m[2])*100+Number((m[3]||'').padEnd(2,'0'))):null;};
    const uuid=()=>{const b=crypto.getRandomValues(new Uint8Array(16));b[6]=(b[6]&15)|64;b[8]=(b[8]&63)|128;const h=Array.from(b,x=>x.toString(16).padStart(2,'0')).join('');return `${h.slice(0,8)}-${h.slice(8,12)}-${h.slice(12,16)}-${h.slice(16,20)}-${h.slice(20)}`;};
    const button=(text,fn)=>{const b=node('button',text,'edit-button');b.type='button';b.addEventListener('click',fn);return b;};
    const msg=(text,error=false)=>{$('cash-message').textContent=text;$('cash-message').classList.toggle('message',error);};
    const supervisor=()=>user&&(user.permisos.todo||user.permisos.supervisar_caja);
    const manages=()=>user&&(user.permisos.todo||user.permisos.administrar_cajas);
    function panels(id){['cash-list-panel','cash-open-panel','cash-detail-panel','cash-config-panel'].forEach(name=>{$(name).hidden=name!==id;});$('cash-open').hidden=id!=='cash-list-panel';$('cash-manage').hidden=!manages()||id!=='cash-list-panel';$('cash-own').hidden=!ownTurn||id!=='cash-list-panel';}
    function leave(){if(busy){msg('Espera a que termine la operación de caja.');return false;}if(dirty&&!window.confirm('Tienes datos de caja sin guardar. ¿Quieres descartarlos?'))return false;dirty=false;return true;}
    app.leaveCash=leave;window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});
    async function metadata(){
        meta=await app.api('/caja/catalogos');ownTurn=(meta.cajas.find(c=>c.turno_abierto_id&&String(c.abierto_por)===String(user.id))||{}).turno_abierto_id||null;
        const selected=$('cash-box-filter').value;$('cash-box-filter').replaceChildren();const blank=node('option','Todas');blank.value='';$('cash-box-filter').append(blank);
        meta.cajas.forEach(c=>{const o=node('option',c.nombre);o.value=c.id;$('cash-box-filter').append(o);});$('cash-box-filter').value=selected;
    }
    async function loadTurns(append=false){
        const params=new URLSearchParams({caja_id:$('cash-box-filter').value,estatus:$('cash-status-filter').value});if(append&&next)params.set('antes_de',next);
        const data=await app.api('/caja/turnos?'+params);next=data.siguiente;turns=append?[...turns,...data.turnos]:data.turnos;render();
    }
    async function open(){
        if(!leave()||(app.leaveCatalog&&!app.leaveCatalog())||!app.openCatalog())return;
        $('products-view').hidden=true;$('nav-products').removeAttribute('aria-current');$('cash-view').hidden=false;$('nav-cash').setAttribute('aria-current','page');
        msg('Cargando caja…');$('cash-turn-rows').replaceChildren();
        try{const session=await app.api('/sesion');user=session.usuario;if(!user||(!user.permisos.todo&&!user.permisos.operar_caja))throw new Error('No tienes permiso para operar caja.');
            await metadata();await loadTurns();panels('cash-list-panel');$('cash-scope').textContent=supervisor()?'Puedes consultar tus turnos y supervisar los de otros usuarios.':'Aquí aparecen únicamente los turnos que abriste.';msg('');app.status('');
        }catch(error){msg(error.message,true);}
    }
    $('nav-cash').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{const p=event.detail.permisos;if(!p.todo&&!p.vender&&!p.ver_ventas&&!p.ver_productos&&!p.ver_inventario&&!p.hacer_compras&&p.operar_caja)open();});
    function render(){
        $('cash-turn-rows').replaceChildren();turns.forEach(t=>{
            const row=node('tr'),id=node('td'),box=node('td'),status=node('td'),action=node('td');id.append(node('strong','Turno #'+t.id),node('small',date(t.abierto_unix)));box.append(node('strong',t.caja_nombre),node('small',t.ubicacion_nombre));status.append(node('span',t.estatus==='abierto'?'Abierto':'Cerrado','badge'+(t.estatus==='cerrado'?' inactive':'')));
            const b=button('Abrir',()=>show(t.id));b.setAttribute('aria-label','Abrir turno '+t.id);action.append(b);row.append(id,box,node('td',t.responsable),status,node('td',t.estatus==='cerrado'?money(t.monto_cierre)+' · '+difference(t.diferencia):'Pendiente'),action);$('cash-turn-rows').append(row);
        });$('cash-turns-empty').hidden=turns.length!==0;$('cash-more').hidden=!next;
    }
    const difference=value=>Number(value)===0?'Sin diferencia':(Number(value)<0?'Faltante ':'Sobrante ')+money(Math.abs(Number(value)));
    ['cash-box-filter','cash-status-filter'].forEach(id=>$(id).addEventListener('change',async()=>{try{await loadTurns();msg('');}catch(error){msg(error.message,true);}}));
    $('cash-more').addEventListener('click',async()=>{$('cash-more').disabled=true;try{await loadTurns(true);}catch(error){msg(error.message,true);}finally{$('cash-more').disabled=false;}});
    $('cash-own').addEventListener('click',()=>show(ownTurn));
    $('cash-open').addEventListener('click',()=>{
        if(!leave())return;if(ownTurn){show(ownTurn);msg('Ya tienes un turno abierto.');return;}
        $('cash-open-form').reset();$('cash-open-error').textContent='';$('cash-open-box').replaceChildren();
        const blank=node('option','Selecciona una caja');blank.value='';$('cash-open-box').append(blank);
        meta.cajas.filter(c=>Number(c.activo)&&Number(c.ubicacion_activa)&&Number(c.disponible_venta)).forEach(c=>{const o=node('option',c.nombre+' · '+c.ubicacion_nombre+(c.turno_abierto_id?' (ocupada por '+c.responsable+')':''));o.value=c.id;o.disabled=!!c.turno_abierto_id;$('cash-open-box').append(o);});
        key=uuid();dirty=false;panels('cash-open-panel');$('cash-open-location').textContent='Solo se permite un turno abierto por caja y por usuario.';msg('');
        if(!meta.cajas.some(c=>Number(c.activo)&&Number(c.ubicacion_activa)&&Number(c.disponible_venta)&&!c.turno_abierto_id))$('cash-open-error').textContent='No hay cajas libres para abrir un turno. Revisa los turnos abiertos o consulta a quien administre las cajas.';
    });
    ['input','change'].forEach(event=>$('cash-open-form').addEventListener(event,()=>{dirty=true;key=uuid();}));
    $('cash-open-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const payload={caja_id:$('cash-open-box').value,monto_apertura:$('cash-open-amount').value,notas:$('cash-open-note').value,clave:key};
        busy=true;$('cash-open-fields').disabled=true;$('cash-open-save').disabled=true;$('cash-open-error').textContent='';
        try{const result=await app.api('/caja/turnos','POST',payload);dirty=false;await metadata();await loadTurns();renderDetail(await app.api('/caja/turnos/'+result.turno_id));msg('Turno abierto. Fondo inicial registrado.');}
        catch(error){$('cash-open-error').textContent=error.message;}
        finally{busy=false;$('cash-open-fields').disabled=false;$('cash-open-save').disabled=false;}
    });
    const back=()=>{if(leave()){panels('cash-list-panel');msg('');}};['cash-open-back','cash-open-cancel','cash-detail-back','cash-config-back'].forEach(id=>$(id).addEventListener('click',back));
    async function show(id){if(!leave())return;msg('Cargando turno…');try{renderDetail(await app.api('/caja/turnos/'+id));msg('');}catch(error){msg(error.message,true);}}
    function renderDetail(t){
        current=t;mode=null;dirty=false;panels('cash-detail-panel');$('cash-action-panel').hidden=true;$('cash-action-form').reset();const closed=t.estatus==='cerrado',r=t.resumen;
        $('cash-detail-title').textContent='Turno #'+t.id+' · '+(closed?'Cerrado':'Abierto');$('cash-detail-meta').textContent=t.caja_nombre+' · '+t.ubicacion_nombre+' · Responsable: '+t.responsable+' · Apertura: '+date(t.abierto_unix);
        [['opening',r.fondo_inicial],['sales',r.ventas_efectivo],['in',r.entradas],['out',r.salidas],['expected',r.esperado]].forEach(([key,value])=>{$('cash-report-'+key).textContent=money(value);});
        $('cash-report-expenses').textContent=money(r.gastos_incluidos_salidas||0);
        $('cash-report-providers').textContent='−'+money(r.pagos_proveedores||0);
        $('cash-report-installments').textContent=money(r.abonos_efectivo||0);
        $('cash-report-refunds').textContent='−'+money(r.reembolsos_efectivo||0);
        $('cash-counted-field').hidden=!closed;$('cash-difference-field').hidden=!closed;$('cash-report-counted').textContent=closed?money(t.monto_cierre):'';$('cash-report-difference').textContent=closed?difference(t.diferencia):'';
        $('cash-detail-status').textContent=closed?'Cerrado por '+t.cerrado_por_nombre+' el '+date(t.cerrado_unix)+'. El corte queda conservado.':r.ventas_pendientes>0?'Hay '+r.ventas_pendientes+' ventas abiertas o en espera. Deben resolverse antes del cierre.':'El turno está abierto. Los importes se actualizan al registrar operaciones.';
        $('cash-detail-notes').textContent=[t.notas?'Apertura: '+t.notas:'',t.notas_cierre?'Cierre: '+t.notas_cierre:''].filter(Boolean).join(' · ');$('cash-detail-actions').hidden=closed;
        $('cash-movement-rows').replaceChildren();t.movimientos.forEach(m=>{const row=node('tr');row.append(node('td',date(m.creado_unix)),node('td',m.tipo==='entrada'?'Entrada':'Salida'),node('td',(m.tipo==='entrada'?'+':'−')+money(m.monto)),node('td',m.usuario_nombre),node('td',m.motivo));$('cash-movement-rows').append(row);});$('cash-movements-empty').hidden=t.movimientos.length!==0;
    }
    $('cash-detail-reload').addEventListener('click',()=>show(current.id));
    function action(type){
        if(!leave())return;mode=type;key=uuid();$('cash-action-form').reset();$('cash-action-error').textContent='';$('cash-action-panel').hidden=false;const close=type==='cerrar';
        $('cash-action-title').textContent=close?'Cerrar turno y hacer corte':type==='entrada'?'Entrada de efectivo':'Salida de efectivo';
        $('cash-action-help').textContent=close?'Cuenta el efectivo físico del cajón. El sistema calculará la diferencia respecto al importe esperado.':'Registra únicamente efectivo que entra o sale fuera de las ventas, indicando el motivo.';
        $('cash-action-amount-label').textContent=close?'Efectivo contado':'Monto del movimiento';$('cash-action-amount').min=close?'0':'.01';
        $('cash-action-note-label').textContent=close?'Notas de cierre':'Motivo';$('cash-action-note').maxLength=close?300:200;
        $('cash-close-confirm-field').hidden=!close;$('cash-close-confirm').required=close;$('cash-action-save').textContent=close?'Confirmar cierre':'Registrar movimiento';
        preview();dirty=false;$('cash-action-amount').focus();
    }
    function preview(){
        const value=cents($('cash-action-amount').value),expected=cents(current.resumen.esperado);$('cash-action-note').required=mode!=='cerrar'||(value!==null&&value!==expected);
        if(value===null){$('cash-action-preview').textContent='Efectivo esperado actual: '+money(current.resumen.esperado);return;}
        if(mode==='cerrar'){$('cash-action-preview').textContent=difference((value-expected)/100)+'. Efectivo esperado: '+money(current.resumen.esperado)+'.';}
        else{$('cash-action-preview').textContent='Efectivo esperado después: '+money((expected+(mode==='entrada'?value:-value))/100);}
    }
    $('cash-entry').addEventListener('click',()=>action('entrada'));$('cash-exit').addEventListener('click',()=>action('salida'));$('cash-close').addEventListener('click',()=>action('cerrar'));
    ['input','change'].forEach(event=>$('cash-action-form').addEventListener(event,()=>{dirty=true;key=uuid();preview();}));
    $('cash-action-cancel').addEventListener('click',()=>{if(leave()){$('cash-action-panel').hidden=true;mode=null;}});
    $('cash-action-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const close=mode==='cerrar';const payload=close?{monto_cierre:$('cash-action-amount').value,notas_cierre:$('cash-action-note').value,version:current.resumen.version,clave:key}:{tipo:mode,monto:$('cash-action-amount').value,motivo:$('cash-action-note').value,clave:key};
        busy=true;$('cash-action-fields').disabled=true;$('cash-action-save').disabled=true;$('cash-action-error').textContent='';
        try{const result=await app.api('/caja/turnos/'+current.id+'/'+(close?'cerrar':'movimientos'),'POST',payload);dirty=false;await metadata();await loadTurns();renderDetail(await app.api('/caja/turnos/'+result.turno_id));msg(close?'Turno cerrado. Corte guardado.':'Movimiento de efectivo registrado.');}
        catch(error){$('cash-action-error').textContent=error.message;}
        finally{busy=false;$('cash-action-fields').disabled=false;$('cash-action-save').disabled=false;}
    });
    function renderConfig(){
        $('cash-config-rows').replaceChildren();meta.cajas.forEach(c=>{const row=node('tr'),name=node('td'),actions=node('td');name.append(node('strong',c.nombre),node('small',c.ubicacion_nombre));actions.append(button('Editar',()=>editBox(c)));row.append(name,node('td',(Number(c.activo)?'Activa':'Inactiva')+(c.turno_abierto_id?' · turno abierto':'')),actions);$('cash-config-rows').append(row);});
    }
    function editBox(c=null,afterSave=false){
        if(!afterSave&&!leave())return;configId=c?c.id:null;$('cash-config-form').reset();$('cash-config-error').textContent='';$('cash-config-editor-title').textContent=c?'Editar caja':'Crear caja';$('cash-config-name').value=c?c.nombre:'';$('cash-config-active').value=c?c.activo:'1';$('cash-config-location').replaceChildren();
        meta.ubicaciones.filter(l=>(Number(l.activo)&&Number(l.disponible_venta))||(c&&String(l.id)===String(c.ubicacion_id))).forEach(l=>{const o=node('option',l.nombre+(!Number(l.activo)||!Number(l.disponible_venta)?' (no disponible)':''));o.value=l.id;$('cash-config-location').append(o);});if(c)$('cash-config-location').value=c.ubicacion_id;dirty=false;
    }
    $('cash-manage').addEventListener('click',()=>{if(!leave())return;panels('cash-config-panel');renderConfig();editBox();msg('');});$('cash-config-new').addEventListener('click',()=>editBox());
    ['input','change'].forEach(event=>$('cash-config-form').addEventListener(event,()=>{dirty=true;}));
    $('cash-config-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const payload=Object.fromEntries(new FormData(event.currentTarget));busy=true;$('cash-config-fields').disabled=true;$('cash-config-save').disabled=true;$('cash-config-error').textContent='';
        try{await app.api(configId?'/caja/cajas/'+configId:'/caja/cajas',configId?'PUT':'POST',payload);dirty=false;await metadata();renderConfig();editBox(null,true);msg('Caja guardada.');}
        catch(error){$('cash-config-error').textContent=error.message;}finally{busy=false;$('cash-config-fields').disabled=false;$('cash-config-save').disabled=false;}
    });
})();
