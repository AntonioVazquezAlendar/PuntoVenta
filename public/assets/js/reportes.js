'use strict';
(() => {
    const $=id=>document.getElementById(id),app=window.PuntoVenta;
    let active=null,result=null,busy=false,revision=0;
    const node=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=text;return n;};
    const money=n=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN'}).format(Number(n));
    const qty=n=>new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(n));
    const method={efectivo:'Efectivo',tarjeta:'Tarjeta',transferencia:'Transferencia',vale:'Vale',otro:'Otro',credito:'Crédito otorgado (por cobrar)'};
    function controls(){const ready=!!result&&!busy;['reports-print','reports-export'].forEach(id=>$(id).disabled=!ready);$('reports-prev').disabled=!ready||result.pagina<=1;$('reports-next').disabled=!ready||result.pagina>=result.paginas;$('reports-search').disabled=busy;}
    function table(id,rows,columns){const body=$(id);body.replaceChildren();rows.forEach(row=>{const tr=node('tr');columns.forEach(([key,format])=>tr.append(node('td',format?format(row[key],row):row[key])));body.append(tr);});if(!rows.length){const td=node('td','Sin operaciones para estos filtros.');td.colSpan=columns.length;const tr=node('tr');tr.append(td);body.append(tr);}}
    function render(r){
        $('reports-content').hidden=false;$('reports-summary').replaceChildren();
        [['Tickets vendidos',r.resumen.tickets,false],['Ventas con impuestos',r.resumen.ventas,true],['Reembolsado',r.resumen.devuelto,true],['Venta neta con impuestos',r.resumen.neto,true],['Impuestos netos',r.resumen.impuestos,true],['Utilidad estimada',r.resumen.utilidad,true],['Gastos registrados',r.resumen.gastos||'0.00',true],['Utilidad menos gastos',r.resumen.resultado_despues_gastos||'0.00',true]].forEach(([label,value,currency])=>{const group=node('div');group.append(node('dt',label),node('dd',currency?money(value):qty(value)));$('reports-summary').append(group);});
        const user=$('reports-user').selectedOptions[0],box=$('reports-box').selectedOptions[0];$('reports-period').textContent=r.filtros.desde+' al '+r.filtros.hasta+' · '+(user?user.textContent:'')+' · '+(box?box.textContent:'');
        const totals=[['tickets',qty],['ventas',money],['devuelto',money],['neto',money],['utilidad',money]];
        table('reports-days',r.dias,[['dia'],...totals]);table('reports-users',r.cajeros,[['nombre'],...totals]);table('reports-boxes',r.cajas,[['nombre'],...totals]);
        table('reports-payments',r.pagos,[['tipo_metodo',v=>method[v]||v],['cobrado',money],['devuelto',money],['neto',money]]);
        table('reports-installments',r.abonos||[],[['tipo',v=>method[v]||v],['recibos',qty],['cobrado',money]]);
        table('reports-provider-payments',r.pagos_proveedores||[],[['origen',v=>v==='caja'?'Efectivo de caja':'Fondos externos'],['tipo',v=>method[v]||v],['recibos',qty],['pagado',money]]);
        table('reports-expenses',r.gastos||[],[['categoria'],['origen',v=>v==='caja'?'Efectivo de caja':'Fondos externos'],['registros',qty],['total',money]]);
        table('reports-products',r.productos,[['nombre'],['unidad'],['vendidas',qty],['devueltas',qty],['cantidad_neta',qty],['neto',money]]);
        table('reports-movements',r.movimientos,[['folio'],['tipo',v=>v==='venta'?'Venta':v==='cancelacion'?'Cancelación':'Devolución'],['fecha'],['cajero'],['caja'],['total',money]]);
        $('reports-page').textContent='Página '+r.pagina+' de '+r.paginas+' · '+r.total_movimientos+' movimientos';
    }
    function params(){return {desde:$('reports-from').value,hasta:$('reports-to').value,usuario_id:$('reports-user').value,caja_id:$('reports-box').value};}
    async function load(filters,page=1){
        const version=++revision;busy=true;result=null;$('reports-content').hidden=true;$('reports-message').textContent='Calculando reporte…';controls();
        try{const r=await app.api('/reportes/ventas?'+new URLSearchParams({...filters,pagina:page}));if(version!==revision)return;active=filters;result=r;render(r);$('reports-message').textContent=r.total_movimientos?'Reporte actualizado.':'No hay operaciones en el periodo seleccionado.';}
        catch(error){if(version===revision)$('reports-message').textContent=error.message;}finally{if(version===revision){busy=false;controls();}}
    }
    async function open(){
        if((app.leaveCatalog&&!app.leaveCatalog())||!app.openCatalog())return;$('products-view').hidden=true;$('nav-products').removeAttribute('aria-current');$('reports-view').hidden=false;$('nav-reports').setAttribute('aria-current','page');
        const version=++revision;busy=true;result=null;$('reports-content').hidden=true;$('reports-message').textContent='Cargando filtros…';controls();
        try{const meta=await app.api('/reportes/catalogos');if(version!==revision)return;$('reports-scope').textContent=meta.todos?'Administrador: consulta todas las operaciones o filtra por responsable.':'Aquí aparecen únicamente las operaciones que registraste.';
            [['reports-user',meta.usuarios,meta.todos?'Todos los cajeros':null],['reports-box',meta.cajas,'Todas las cajas']].forEach(([id,items,blank])=>{const select=$(id);select.replaceChildren();if(blank){const option=node('option',blank);option.value='';select.append(option);}items.forEach(item=>{const option=node('option',item.nombre+(Number(item.activo)?'':' (inactivo)'));option.value=item.id;select.append(option);});});$('reports-user').disabled=!meta.todos;$('reports-from').value=meta.hoy;$('reports-to').value=meta.hoy;busy=false;await load(params());
        }catch(error){if(version===revision){busy=false;$('reports-message').textContent=error.message;controls();}}
    }
    $('nav-reports').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{const p=event.detail.permisos;if(!p.todo&&p.ver_reportes&&!p.vender&&!p.ver_ventas&&!p.ver_productos&&!p.ver_inventario&&!p.hacer_compras&&!p.operar_caja&&!p.ver_clientes)open();});
    $('reports-filter').addEventListener('submit',event=>{event.preventDefault();if(!busy)load(params());});
    ['input','change'].forEach(event=>$('reports-filter').addEventListener(event,()=>{revision++;busy=false;result=null;$('reports-content').hidden=true;$('reports-message').textContent='Pulsa Consultar para aplicar estos filtros.';controls();}));
    $('reports-prev').addEventListener('click',()=>{if(result&&!busy)load(active,result.pagina-1);});$('reports-next').addEventListener('click',()=>{if(result&&!busy)load(active,result.pagina+1);});
    $('reports-print').addEventListener('click',()=>{if(!result)return;document.body.classList.add('printing-report');window.print();});window.addEventListener('afterprint',()=>document.body.classList.remove('printing-report'));
    $('reports-export').addEventListener('click',async()=>{
        if(!result||busy)return;const exportFilters={...active};busy=true;controls();$('reports-message').textContent='Preparando Excel…';
        try{const response=await fetch('/PuntoVenta/public/api/reportes/ventas/excel?'+new URLSearchParams(exportFilters),{credentials:'same-origin'});if(!response.ok){const error=await response.json();throw new Error(error.message||'No se pudo exportar el reporte.');}const blob=await response.blob(),url=URL.createObjectURL(blob),link=node('a');link.href=url;link.download='ventas-'+exportFilters.desde+'-a-'+exportFilters.hasta+'.xlsx';document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),60000);$('reports-message').textContent='Archivo Excel descargado.';}
        catch(error){$('reports-message').textContent=error.message;}finally{busy=false;controls();}
    });
})();
