'use strict';
(() => {
    const app=window.PuntoVenta,$=id=>document.getElementById(id);
    const fields=['razon_social','rfc','telefono','correo','sitio_web','direccion','codigo_postal','encabezado_ticket','pie_ticket'];
    const types={efectivo:'Efectivo',tarjeta:'Tarjeta',transferencia:'Transferencia',credito:'Crédito del cliente',vales:'Vales',otro:'Otro'};
    let data=null,dirty=false,methodDirty=false,busy=false,loading=false,revision=0,editing=null;
    const node=(tag,text)=>{const e=document.createElement(tag);e.textContent=text;return e;};
    const message=text=>$('config-message').textContent=text;
    function preview(){
        $('config-preview-name').textContent=$('company-name').value||$('brand-name').textContent;
        $('config-preview-contact').textContent=['direccion','telefono','correo'].map(k=>$('config-'+k).value.trim()).filter(Boolean).join('\n');
        $('config-preview-header').textContent=$('config-encabezado_ticket').value;
        $('config-preview-footer').textContent=$('config-pie_ticket').value.trim()||'Gracias por su compra';
        $('config-dirty').textContent=dirty?'Tienes cambios sin guardar en los datos y el ticket.':'Datos y ticket guardados.';
    }
    function fill(){if(!data)return;fields.forEach(k=>$('config-'+k).value=data.negocio[k]||'');dirty=false;preview();}
    function methodForm(m=null){
        editing=m;$('config-method-form').reset();$('config-method-title').textContent=m?'Editar método de pago':'Nuevo método';
        $('config-method-name').value=m?m.nombre:'';$('config-method-type').value=m?m.tipo:'efectivo';$('config-method-type').disabled=!!m;
        $('config-method-active').checked=!m||!!Number(m.activo);methodDirty=false;$('config-method-message').textContent='';
    }
    function methodRows(){
        $('config-method-rows').replaceChildren();for(const m of data.metodos){
            const tr=document.createElement('tr'),action=document.createElement('td'),b=node('button','Editar');b.type='button';b.className='edit-button';b.setAttribute('aria-label','Editar método '+m.nombre);
            b.addEventListener('click',()=>{if(busy)return;if(methodDirty&&!window.confirm('Hay cambios sin guardar en un método. ¿Quieres descartarlos?'))return;methodForm(m);$('config-method-name').focus();});
            action.append(b);tr.append(node('td',m.nombre),node('td',types[m.tipo]||m.tipo),node('td',Number(m.activo)?'Activo':'Inactivo'),action);$('config-method-rows').append(tr);
        }
    }
    function controls(){
        $('config-fields').disabled=busy||loading||!data;$('config-method-fields').disabled=busy||loading||!data;
        $('config-reload').disabled=busy||loading;$('config-method-new').disabled=busy||loading||!data;
        $('config-form').setAttribute('aria-busy',String(busy||loading));
    }
    function leave(){
        if(busy||loading){message('Espera a que termine la operación de configuración.');return false;}
        if((dirty||methodDirty)&&!window.confirm('Tienes cambios sin guardar en la configuración administrativa. ¿Quieres descartarlos?'))return false;
        if(dirty)fill();if(methodDirty)methodForm(editing);return true;
    }
    async function load(){
        if(loading||busy||!app.current()?.permisos.todo||$('company-view').hidden)return;
        if((dirty||methodDirty)&&!leave())return;const request=++revision;loading=true;controls();message('Consultando configuración…');
        try{const result=await app.api('/configuracion');if(request!==revision||!app.current()?.permisos.todo)return;data=result;fill();methodRows();methodForm();$('config-currency').textContent=data.sistema.moneda;$('config-timezone').textContent=data.sistema.zona_horaria;message('');}
        catch(e){if(request===revision)message(e.message+' Pulsa Recargar configuración para volver a intentar.');}
        finally{if(request===revision){loading=false;controls();}}
    }
    // Existing modules share the catalog navigation guard; direct menu actions use the same guard.
    app.leaveConfiguration=leave;
    document.querySelectorAll('#admin-nav button,#logout').forEach(b=>b.addEventListener('click',e=>{if($('company-view').hidden||b.id==='nav-company')return;if(!leave()){e.preventDefault();e.stopImmediatePropagation();}},true));
    $('nav-company').addEventListener('click',()=>{if(!dirty&&!methodDirty&&!$('company-view').hidden)load();});
    $('config-reload').addEventListener('click',load);
    $('config-form').addEventListener('input',()=>{dirty=true;preview();});$('company-name').addEventListener('input',preview);
    $('config-cancel').addEventListener('click',()=>{fill();message('Cambios de datos y ticket descartados.');});
    $('company-cancel').addEventListener('click',preview);
    $('config-form').addEventListener('submit',async e=>{
        e.preventDefault();if(busy||!data)return;const payload={version:data.version};fields.forEach(k=>payload[k]=$('config-'+k).value);
        busy=true;controls();message('Guardando datos y ticket…');const request=revision;
        try{const result=await app.api('/configuracion','PUT',payload);if(request!==revision)return;data=result;fill();methodRows();message('Datos del negocio y ticket guardados. Se aplicarán a las nuevas ventas.');}
        catch(error){if(request===revision)message(error.message);}
        finally{if(request===revision){busy=false;controls();}}
    });
    $('config-method-form').addEventListener('input',()=>{methodDirty=true;$('config-method-message').textContent='Método con cambios sin guardar.';});
    $('config-method-new').addEventListener('click',()=>{if(methodDirty&&!window.confirm('¿Descartar los cambios del método actual?'))return;methodForm();$('config-method-name').focus();});
    $('config-method-cancel').addEventListener('click',()=>methodForm(editing));
    $('config-method-form').addEventListener('submit',async e=>{
        e.preventDefault();if(busy||!data)return;const m=editing,payload={nombre:$('config-method-name').value,tipo:$('config-method-type').value,activo:$('config-method-active').checked?1:0};if(m)payload.version=m.version;
        busy=true;controls();$('config-method-message').textContent='Guardando método…';const request=revision;
        try{const result=await app.api('/configuracion/metodos'+(m?'/'+m.id:''),m?'PUT':'POST',payload);if(request!==revision)return;data.metodos=result;methodRows();methodForm(m?result.find(r=>String(r.id)===String(m.id)):null);$('config-method-message').textContent='Método guardado. Disponible al actualizar los cobros de nuevas operaciones.';}
        catch(error){if(request===revision)$('config-method-message').textContent=error.message;}
        finally{if(request===revision){busy=false;controls();}}
    });
    document.querySelectorAll('[data-config-nav]').forEach(b=>b.addEventListener('click',()=>$(b.dataset.configNav).click()));
    window.addEventListener('beforeunload',e=>{if(dirty||methodDirty||busy){e.preventDefault();e.returnValue='';}});
    document.addEventListener('puntoventa:access',()=>{revision++;data=null;dirty=false;methodDirty=false;busy=false;loading=false;editing=null;$('config-form').reset();$('config-method-form').reset();$('config-method-rows').replaceChildren();['config-preview-name','config-preview-contact','config-preview-header','config-preview-footer','config-message','config-method-message','config-dirty','config-currency','config-timezone'].forEach(id=>$(id).textContent='');controls();});
})();
