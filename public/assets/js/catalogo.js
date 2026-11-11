'use strict';
(() => {
    const $ = id => document.getElementById(id);
    const app = window.PuntoVenta;
    let products = [], catalogs = {categorias:[], unidades:[], impuestos:[]}, canEdit = false;
    let productId = null, categoryId = null, dirty = false, variantCounter = 0, photos=[], primaryPhoto='', photoVersion='', savingProduct=false;
    const money = value => new Intl.NumberFormat('es-MX',{style:'currency',currency:'MXN',maximumFractionDigits:4}).format(Number(value));
    const node = (tag,text,cls) => { const el = document.createElement(tag); if (text !== undefined) el.textContent = text; if (cls) el.className = cls; return el; };
    const message = (text,error=false) => { $('catalog-message').textContent = text; $('catalog-message').classList.toggle('message',error); };
    const active = value => Number(value) === 1;
    const button = (label,action) => { const b=node('button',label,'edit-button'); b.type='button'; b.addEventListener('click',action); return b; };

    function options(id, rows, selected='', blank=null) {
        const select=$(id); select.replaceChildren();
        if (blank !== null) { const o=node('option',blank); o.value=''; select.append(o); }
        rows.forEach(row => { const o=node('option',row.nombre + (active(row.activo) ? '' : ' (inactiva)')); o.value=row.id; select.append(o); });
        select.value=selected == null ? '' : String(selected);
        if (!select.value && blank === null && select.options.length) { select.selectedIndex=0; }
    }

    function panels(name) {
        ['product-list-panel','product-editor','product-detail','categories-panel'].forEach(id => { $(id).hidden=id!==name; });
        $('new-product').hidden=!canEdit || name!=='product-list-panel';
        $('manage-categories').hidden=!canEdit || name!=='product-list-panel';
    }

    function resetPhotos(p=null){
        photos.forEach(f=>{if(f.file)URL.revokeObjectURL(f.url);});photos=(p&&p.imagenes||[]).map(f=>({...f,key:'id:'+f.id}));
        primaryPhoto=(photos.find(f=>f.principal)||photos[0]||{}).key||'';photoVersion=p?p.imagenes_version:catalogs.imagenes_version_vacia;
        $('product-photo-files').value='';$('product-photo-error').textContent='';renderPhotos();
    }
    function renderPhotos(){
        $('product-photo-gallery').replaceChildren();$('product-photo-count').textContent=photos.length+' / 10';$('product-photo-empty').hidden=photos.length>0;
        photos.forEach((f,index)=>{
            const card=node('article',undefined,'product-photo'+(f.key===primaryPhoto?' is-primary':''));const img=node('img');img.src=f.url;img.alt=f.nombre||'Imagen del producto';img.loading='lazy';
            const label=node('label',undefined,'photo-primary-choice'),radio=node('input');radio.type='radio';radio.name='imagen_predeterminada';radio.value=f.key;radio.checked=f.key===primaryPhoto;radio.setAttribute('aria-label','Usar imagen '+(index+1)+' como predeterminada');radio.addEventListener('change',()=>{primaryPhoto=f.key;dirty=true;renderPhotos();});
            label.append(radio,node('span',f.key===primaryPhoto?'Predeterminada':'Usar como principal'));const name=node('p',f.nombre||'Imagen '+(index+1),'photo-name');
            const remove=button('Quitar',()=>{if(f.file)URL.revokeObjectURL(f.url);photos=photos.filter(p=>p.key!==f.key);if(primaryPhoto===f.key)primaryPhoto=photos.length?photos[0].key:'';dirty=true;renderPhotos();});remove.setAttribute('aria-label','Quitar imagen '+(index+1));card.append(img,name,label,remove);$('product-photo-gallery').append(card);
        });
    }
    $('product-photo-files').addEventListener('change',()=>{
        const files=Array.from($('product-photo-files').files);$('product-photo-files').value='';$('product-photo-error').textContent='';if(!files.length)return;
        if(photos.length+files.length>10){$('product-photo-error').textContent='Puedes guardar hasta 10 imágenes por producto.';return;}
        if(files.some(f=>!['image/jpeg','image/png','image/webp'].includes(f.type)||f.size<1||f.size>2097152)){$('product-photo-error').textContent='Usa imágenes JPG, PNG o WebP de hasta 2 MB cada una.';return;}
        if(files.reduce((n,f)=>n+f.size,photos.reduce((n,f)=>n+(f.file?f.file.size:0),0))>6291456){$('product-photo-error').textContent='Puedes subir hasta 6 MB de imágenes nuevas por guardado.';return;}
        files.forEach(file=>{const key=Array.from(crypto.getRandomValues(new Uint8Array(16)),n=>n.toString(16).padStart(2,'0')).join('');photos.push({archivo:key,key:'nuevo:'+key,nombre:file.name,file,url:URL.createObjectURL(file)});});
        if(!primaryPhoto)primaryPhoto=photos[0].key;dirty=true;renderPhotos();
    });
    function leave() {
        if(savingProduct){message('Espera a que termine de guardarse el producto.',true);return false;}
        if (dirty && !window.confirm('Tienes cambios sin guardar en el catálogo. ¿Quieres descartarlos?')) return false;
        dirty=false;resetPhotos(); return true;
    }
    app.leaveCatalog=leave;
    window.addEventListener('beforeunload',event => { if(dirty||savingProduct){event.preventDefault();event.returnValue='';} });

    async function load() {
        const [list,lookups]=await Promise.all([app.api('/productos'+(canEdit?'?todos=1':'')),app.api('/catalogos')]);
        products=list; catalogs=lookups;
        const selected=$('product-category-filter').value;
        options('product-category-filter',catalogs.categorias,selected,'Todas'); render();
    }

    async function open() {
        if(!leave() || !app.openCatalog())return;
        message('Cargando catálogo…'); $('product-rows').replaceChildren(); panels('product-list-panel');
        try {
            const session=await app.api('/sesion'); const user=session.usuario;
            if(!user || (!user.permisos.todo && !user.permisos.ver_productos)) throw new Error('Tu cuenta no tiene permiso para consultar productos.');
            canEdit=!!(user.permisos.todo || user.permisos.editar_productos);
            $('product-status-filter-field').hidden=!canEdit;
            if(!canEdit)$('product-status-filter').value='1';
            panels('product-list-panel'); await load(); message(''); app.status('');
        }catch(error){message(error.message,true);}
    }
    $('nav-products').addEventListener('click',open);
    document.addEventListener('puntoventa:session',event=>{ const p=event.detail.permisos;if(!p.todo&&!p.vender&&!p.ver_ventas&&p.ver_productos)open(); });

    function render() {
        const query=$('product-search').value.trim().toLocaleLowerCase('es');
        const category=$('product-category-filter').value, state=$('product-status-filter').value;
        const filtered=products.filter(p=>(!category || String(p.categoria_id)===category)
            && (state==='' || String(p.activo)===state)
            && [p.nombre,p.descripcion,...p.variantes.map(v=>v.sku+' '+(v.nombre||''))].join(' ').toLocaleLowerCase('es').includes(query));
        $('product-rows').replaceChildren();
        filtered.forEach(p=>{
            const row=node('tr'); const name=node('td'); if(p.imagen_url){const thumb=node('img',undefined,'product-list-photo');thumb.src=p.imagen_url;thumb.alt='';thumb.loading='lazy';thumb.addEventListener('error',()=>thumb.remove());name.append(thumb);}name.append(node('strong',p.nombre),node('small',p.unidad_nombre+' · '+p.tipo_producto));
            const visible=p.variantes.filter(v=>active(v.activo));
            const prices=visible.map(v=>Number(v.precio)); const price=prices.length?(Math.min(...prices)===Math.max(...prices)?money(prices[0]):money(Math.min(...prices))+' – '+money(Math.max(...prices))):'Sin variantes activas';
            const stateCell=node('td');stateCell.append(node('span',active(p.activo)?'Activo':'Inactivo','badge'+(active(p.activo)?'':' inactive')));
            const actions=node('td'); const action=button(canEdit?'Editar':'Ver',()=>canEdit?editProduct(p):detail(p));action.setAttribute('aria-label',(canEdit?'Editar ':'Ver ')+p.nombre);actions.append(action);
            row.append(name,node('td',p.categoria_nombre||'Sin categoría'),node('td',visible.length+' activas'),node('td',price),stateCell,actions);$('product-rows').append(row);
        });
        $('products-empty').hidden=filtered.length!==0;
        $('product-count').textContent=filtered.length+(filtered.length===1?' producto':' productos')+' · '+products.reduce((sum,p)=>sum+p.variantes.filter(v=>active(v.activo)).length,0)+' variantes activas en el catálogo';
    }
    ['product-search','product-category-filter','product-status-filter'].forEach(id=>$(id).addEventListener(id==='product-search'?'input':'change',render));

    function detail(p){
        panels('product-detail');message('');$('product-detail-gallery').replaceChildren();(p.imagenes||[]).forEach(f=>{const figure=node('figure'),img=node('img');img.src=f.url;img.alt=p.nombre;img.loading='lazy';figure.append(img);if(f.principal)figure.append(node('figcaption','Predeterminada'));$('product-detail-gallery').append(figure);});$('product-detail-title').textContent=p.nombre;
        $('product-detail-description').textContent=p.descripcion||'Sin descripción.';
        $('product-detail-meta').textContent=(p.categoria_nombre||'Sin categoría')+' · '+p.unidad_nombre+' · '+p.impuesto_nombre+' · '+(active(p.precio_incluye_impuesto)?'Precios con impuesto incluido':'El impuesto se agrega al precio');
        $('product-detail-rows').replaceChildren();
        p.variantes.forEach(v=>{const row=node('tr');row.append(node('td',v.sku),node('td',v.nombre||'Única'),node('td',money(v.precio)),node('td',p.unidad_codigo),node('td',new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(v.existencia_disponible))));$('product-detail-rows').append(row);});
    }
    $('close-detail').addEventListener('click',()=>panels('product-list-panel'));

    function addVariant(v={}){
        const index=++variantCounter;const fieldset=node('fieldset',undefined,'variant-fields');fieldset.dataset.id=v.id||'';
        const legend=node('legend',v.id?'Variante existente':'Nueva variante');fieldset.append(legend);
        if(v.id&&v.existencia_disponible!==undefined)fieldset.append(node('p','Disponible para venta: '+new Intl.NumberFormat('es-MX',{maximumFractionDigits:4}).format(Number(v.existencia_disponible))+' unidades base. No incluye el stock en resguardo.','hint'));
        const grid=node('div',undefined,'variant-grid');
        const specs=[['sku','SKU único','text',v.sku||'',64],['nombre','Nombre de variante','text',v.nombre||'',200],['costo','Costo sin IVA','number',v.costo||'0',null],['precio','Precio de venta','number',v.precio||'0',null],['stock_minimo','Stock mínimo','number',v.stock_minimo||'0',null]];
        specs.forEach(([key,label,type,value,max])=>{
            const wrap=node('div');const input=node('input');input.id='variant-'+index+'-'+key;input.dataset.field=key;input.type=type;input.value=value;input.required=key!=='nombre';
            if(max)input.maxLength=max;
            if(type==='number'){input.min='0';input.max='9999999999.9999';input.step='.0001';}
            const lab=node('label',label);lab.htmlFor=input.id;wrap.append(lab,input);grid.append(wrap);
        });
        const state=node('div');const lab=node('label','Estado');const select=node('select');select.id='variant-'+index+'-active';select.dataset.field='activo';lab.htmlFor=select.id;
        [['1','Activa'],['0','Inactiva']].forEach(([value,text])=>{const option=node('option',text);option.value=value;select.append(option);});select.value=v.activo===undefined?'1':String(v.activo);state.append(lab,select);grid.append(state);fieldset.append(grid);
        if(!v.id){const remove=button('Quitar esta variante',()=>{fieldset.remove();dirty=true;});fieldset.append(remove);}
        $('variant-editors').append(fieldset);
    }

    function editProduct(p=null){
        if(!leave())return;productId=p?p.id:null;panels('product-editor');message('');$('product-form').reset();$('product-error').textContent='';
        $('product-editor-title').textContent=p?'Editar producto':'Crear producto';
        $('product-name').value=p?p.nombre:'';$('product-description').value=p?(p.descripcion||''):'';
        options('product-category',catalogs.categorias.filter(c=>active(c.activo)||(p&&String(c.id)===String(p.categoria_id))),p?p.categoria_id:'','Sin categoría');
        options('product-unit',catalogs.unidades.filter(u=>active(u.activo)||(p&&String(u.id)===String(p.unidad_base_id))),p?p.unidad_base_id:(catalogs.unidades.find(u=>u.codigo==='pza')||{}).id);
        options('product-tax',catalogs.impuestos.filter(i=>active(i.activo)||(p&&String(i.id)===String(p.impuesto_id))),p?p.impuesto_id:(catalogs.impuestos[0]||{}).id);
        $('product-type').value=p?p.tipo_producto:'estandar';$('product-active').value=p?p.activo:'1';
        $('product-tax-included').checked=p?active(p.precio_incluye_impuesto):true;$('product-inventory').checked=p?active(p.controla_inventario):true;
        resetPhotos(p);serviceType();$('variant-editors').replaceChildren();(p&&p.variantes.length?p.variantes:[{}]).forEach(addVariant);dirty=false;$('product-name').focus();
    }
    function serviceType(){const service=$('product-type').value==='servicio';$('product-inventory').disabled=service;if(service)$('product-inventory').checked=false;}
    $('product-type').addEventListener('change',serviceType);
    $('new-product').addEventListener('click',()=>editProduct());
    $('add-variant').addEventListener('click',()=>{if($('variant-editors').children.length>=100){$('product-error').textContent='El máximo es de 100 variantes.';return;}addVariant();dirty=true;});
    $('product-form').addEventListener('input',()=>{dirty=true;});
    $('product-form').addEventListener('change',()=>{dirty=true;});
    const closeProduct=()=>{if(leave())panels('product-list-panel');};
    $('close-product').addEventListener('click',closeProduct);$('cancel-product').addEventListener('click',closeProduct);
    $('product-form').addEventListener('submit',async event=>{
        event.preventDefault();if(savingProduct)return;savingProduct=true;$('product-form').inert=true;$('save-product').disabled=true;$('product-error').textContent='';
        const data=Object.fromEntries(new FormData(event.currentTarget));
        data.precio_incluye_impuesto=$('product-tax-included').checked?1:0;data.controla_inventario=$('product-inventory').checked?1:0;
        data.variantes=Array.from($('variant-editors').children).map(row=>{
            const v={};if(row.dataset.id)v.id=row.dataset.id;
            row.querySelectorAll('[data-field]').forEach(input=>{v[input.dataset.field]=input.value;});return v;
        });
        data.imagenes=photos.map(f=>f.id?{id:f.id}:{archivo:f.archivo});data.imagen_principal=primaryPhoto;data.imagenes_version=photoVersion;
        const form=new FormData();form.append('datos',JSON.stringify(data));photos.filter(f=>f.file).forEach(f=>form.append('imagenes['+f.archivo+']',f.file,f.nombre));
        try{const editing=productId!==null;const result=await app.api(editing?'/productos/'+productId:'/productos','POST',form);productId=result.id;dirty=false;resetPhotos();panels('product-list-panel');
            try{await load();message(editing?'Producto e imágenes actualizados.':'Producto creado con sus imágenes.');}catch(error){message('El producto se guardó. Vuelve a entrar a Productos para actualizar el catálogo.',true);}$('new-product').focus();
        }catch(error){$('product-error').textContent=error.message;}
        finally{savingProduct=false;$('product-form').inert=false;$('save-product').disabled=false;}

    });

    function categoryLabel(c){const parent=catalogs.categorias.find(item=>String(item.id)===String(c.categoria_padre_id));return c.nombre+(parent?' · '+parent.nombre:'');}
    function renderCategories(){
        $('category-rows').replaceChildren();
        catalogs.categorias.forEach(c=>{const row=node('tr'),action=node('td');action.append(button('Editar',()=>editCategory(c)));row.append(node('td',categoryLabel(c)),node('td',active(c.activo)?'Activa':'Inactiva'),action);$('category-rows').append(row);});
    }
    function editCategory(c=null){
        if(!leave())return;categoryId=c?c.id:null;$('category-form').reset();$('category-error').textContent='';
        $('category-editor-title').textContent=c?'Editar categoría':'Crear categoría';$('category-name').value=c?c.nombre:'';$('category-active').value=c?c.activo:'1';
        options('category-parent',catalogs.categorias.filter(item=>String(item.id)!==String(categoryId)),c?c.categoria_padre_id:'','Principal (sin categoría superior)');dirty=false;
    }
    $('manage-categories').addEventListener('click',()=>{if(!leave())return;panels('categories-panel');message('');renderCategories();editCategory();});
    $('new-category').addEventListener('click',()=>editCategory());
    $('category-form').addEventListener('input',()=>{dirty=true;});$('category-form').addEventListener('change',()=>{dirty=true;});
    $('close-categories').addEventListener('click',()=>{if(leave())panels('product-list-panel');});
    $('category-form').addEventListener('submit',async event=>{
        event.preventDefault();$('save-category').disabled=true;$('category-error').textContent='';
        try{await app.api(categoryId?'/categorias/'+categoryId:'/categorias',categoryId?'PUT':'POST',Object.fromEntries(new FormData(event.currentTarget)));dirty=false;await load();renderCategories();editCategory();message('Categoría guardada.');}
        catch(error){$('category-error').textContent=error.message;}
        finally{$('save-category').disabled=false;}
    });
})();
