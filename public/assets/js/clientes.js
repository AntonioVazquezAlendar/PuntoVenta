'use strict';
(() => {
    const $=id=>document.getElementById(id),app=window.PuntoVenta;
    let canEdit=false,dirty=false,busy=false,editing=null,current=null,returnTo='clients-list-panel',next=null,historyNext=null,timer=null,request=0;
    const node=(tag,text,cls)=>{const e=document.createElement(tag);if(text!==undefined)e.textContent=text;if(cls)e.className=cls;return e;};
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n));
    const date=n=>n?new Intl.DateTimeFormat('es-MX',{dateStyle:'short',timeStyle:'short',timeZone:'America/Mexico_City'}).format(new Date(Number(n)*1000)):'—';
    const msg=(text,error=false)=>{$('clients-message').textContent=text;$('clients-message').classList.toggle('message',error);};
    function leave(){if(busy){msg('Espera a que termine de guardarse el cliente.',true);return false;}if(dirty&&!window.confirm('Tienes cambios de cliente sin guardar. ¿Quieres descartarlos?'))return false;dirty=false;clearTimeout(timer);request++;return true;}
    app.leaveClients=leave;window.addEventListener('beforeunload',event=>{if(dirty||busy){event.preventDefault();event.returnValue='';}});
    function panel(id){['clients-list-panel','client-editor','client-detail'].forEach(p=>{$(p).hidden=p!==id;});$('client-new').hidden=!canEdit||id!=='clients-list-panel';}
    async function load(append=false){
        const version=++request,params=new URLSearchParams({buscar:$('clients-search').value.trim(),estado:$('clients-state').value});if(append&&next)params.set('antes_de',next);
        $('clients-more').disabled=true;
        try{const data=await app.api('/clientes?'+params);if(version!==request)return;next=data.siguiente;if(!append)$('clients-rows').replaceChildren();
            data.clientes.forEach(c=>{const row=node('tr'),name=node('td'),contact=node('td'),state=node('td'),actions=node('td');name.append(node('strong',c.nombre));if(Number(c.predeterminado))name.append(node('small','Predeterminado','client-default-badge'));contact.append(node('span',c.telefono||'Sin teléfono'),node('small',c.correo||'Sin correo'));state.append(node('span',Number(c.activo)?'Activo':'Inactivo','badge'+(Number(c.activo)?'':' inactive')));
                const view=node('button','Ver ficha','edit-button');view.type='button';view.setAttribute('aria-label','Ver ficha de '+c.nombre);view.addEventListener('click',()=>detail(c.id));actions.append(view);row.append(name,contact,state,actions);$('clients-rows').append(row);});
            $('clients-empty').hidden=$('clients-rows').children.length>0;$('clients-more').hidden=!next;
        }finally{if(version===request)$('clients-more').disabled=false;}
    }
    async function open(){
        if(!leave()||(app.leaveCatalog&&!app.leaveCatalog())||!app.openCatalog())return;$('products-view').hidden=true;$('nav-products').removeAttribute('aria-current');$('clients-view').hidden=false;$('nav-clients').setAttribute('aria-current','page');msg('Cargando clientes…');
        try{const session=await app.api('/sesion'),u=session.usuario;if(!u||(!u.permisos.todo&&!u.permisos.ver_clientes))throw new Error('No tienes permiso para consultar clientes.');canEdit=!!(u.permisos.todo||u.permisos.editar_clientes);panel('clients-list-panel');await load();msg('');app.status('');}catch(error){msg(error.message,true);}
    }
    $('nav-clients').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{const p=event.detail.permisos;if(!p.todo&&!p.vender&&!p.ver_ventas&&!p.ver_productos&&!p.ver_inventario&&!p.hacer_compras&&!p.operar_caja&&p.ver_clientes)open();});
    $('clients-search').addEventListener('input',()=>{clearTimeout(timer);request++;timer=setTimeout(()=>load().then(()=>msg('')).catch(e=>msg(e.message,true)),300);});
    $('clients-state').addEventListener('change',()=>load().catch(e=>msg(e.message,true)));$('clients-more').addEventListener('click',()=>load(true).catch(e=>msg(e.message,true)));
    function edit(c=null,back='clients-list-panel'){
        if(!leave())return;editing=c;returnTo=back;$('client-form').reset();$('client-error').textContent='';$('client-editor-title').textContent=c?'Editar cliente':'Crear cliente';
        for(const [key,id] of Object.entries({nombre:'client-name',telefono:'client-phone',correo:'client-email',direccion:'client-address',notas:'client-notes'}))$(id).value=c&&c[key]||'';
        $('client-active').value=c?String(c.activo):'1';$('client-default').checked=!!(c&&Number(c.predeterminado));syncDefault();panel('client-editor');$('client-name').focus();
    }
    function syncDefault(){const inactive=$('client-active').value==='0';$('client-default').disabled=inactive;if(inactive)$('client-default').checked=false;}
    $('client-active').addEventListener('change',syncDefault);
    $('client-new').addEventListener('click',()=>edit());$('client-edit').addEventListener('click',()=>edit(current,'client-detail'));
    ['input','change'].forEach(e=>$('client-form').addEventListener(e,()=>{dirty=true;}));
    const cancel=()=>{if(leave())panel(returnTo);};$('client-cancel').addEventListener('click',cancel);$('client-editor-back').addEventListener('click',cancel);
    $('client-form').addEventListener('submit',async event=>{
        event.preventDefault();if(busy)return;const data=Object.fromEntries(new FormData(event.currentTarget));data.predeterminado=$('client-default').checked;if(editing)data.version=editing.version;busy=true;$('client-fields').disabled=true;$('client-save').disabled=true;$('client-error').textContent='';
        try{await app.api(editing?'/clientes/'+editing.id:'/clientes',editing?'PUT':'POST',data);dirty=false;panel('clients-list-panel');try{await load();msg('Cliente guardado.');}catch(error){msg('El cliente se guardó. Vuelve a entrar a Clientes para actualizar la lista.',true);}}
        catch(error){$('client-error').textContent=error.message;}finally{busy=false;$('client-fields').disabled=false;$('client-save').disabled=false;}
    });
    async function history(id,append=false){
        const data=await app.api('/clientes/'+id+'/historial'+(append&&historyNext?'?antes_de='+historyNext:''));if(!current||String(current.id)!==String(id))return;current=data.cliente;historyNext=data.siguiente;
        $('client-history-summary').textContent=data.resumen.compras+(Number(data.resumen.compras)===1?' compra · ':' compras · ')+money(data.resumen.total)+' netos en ventas completadas';$('client-history-scope').textContent=data.todas_las_ventas?'Compras vinculadas a este cliente, de todos los cajeros.':'Solo se muestran las ventas que tú registraste para este cliente.';
        if(!append)$('client-history-rows').replaceChildren();data.ventas.forEach(v=>{const tr=node('tr'),ticket=node('td');ticket.append(node('strong',v.folio),node('small',date(v.completado_unix)));const total=node('td',money(v.total));if(Number(v.reembolsado)>0)total.append(node('small','Reembolsado: '+money(v.reembolsado)+' · Neto: '+money(Number(v.total)-Number(v.reembolsado))));tr.append(ticket,node('td',v.cajero),node('td',v.estatus),total);$('client-history-rows').append(tr);});$('client-history-empty').hidden=$('client-history-rows').children.length>0;$('client-history-more').hidden=!historyNext;
    }
    async function detail(id){
        if(!leave())return;msg('Cargando ficha…');try{current=await app.api('/clientes/'+id);$('client-detail-title').textContent=current.nombre;$('client-detail-state').textContent=(Number(current.activo)?'Cliente activo':'Cliente inactivo')+(Number(current.predeterminado)?' · Predeterminado':'');$('client-edit').hidden=!canEdit;$('client-contact').replaceChildren();
            [['Teléfono',current.telefono],['Correo',current.correo],['Dirección',current.direccion],['Notas',current.notas]].forEach(([label,value])=>{const group=node('div');group.append(node('dt',label),node('dd',value||'Sin registrar'));$('client-contact').append(group);});
            panel('client-detail');await history(id);msg('');
        }catch(error){msg(error.message,true);}
    }
    $('client-detail-back').addEventListener('click',()=>{if(leave())panel('clients-list-panel');});
    $('client-history-more').addEventListener('click',async()=>{if(!current)return;$('client-history-more').disabled=true;try{await history(current.id,true);}catch(error){msg(error.message,true);}finally{$('client-history-more').disabled=false;}});
})();
