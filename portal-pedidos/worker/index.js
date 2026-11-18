import { HTML, CSS, JS } from './web.js';

const states = new Set(['nuevo','confirmado','listo','en_venta','vendido','cancelado']);
const uuid = value => typeof value === 'string' && /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/.test(value);
const json = (data,status=200) => new Response(JSON.stringify({ok:status<400,...(status<400?{data}:{message:data})}),{status,headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'no-store','X-Content-Type-Options':'nosniff'}});
function fail(message,status=422){throw Object.assign(new Error(message),{status});}
async function digest(text){return [...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(text)))].map(x=>x.toString(16).padStart(2,'0')).join('');}
async function read(req,max=32768){
    const reader=req.body?.getReader();if(!reader)fail('Envía los datos del pedido.');let size=0,chunks=[];
    for(;;){const {value,done}=await reader.read();if(done)break;size+=value.byteLength;if(size>max){await reader.cancel();fail('El contenido supera el tamaño permitido.',413);}chunks.push(value);}
    const bytes=new Uint8Array(size);let offset=0;for(const c of chunks){bytes.set(c,offset);offset+=c.byteLength;}
    let d;try{d=JSON.parse(new TextDecoder().decode(bytes));}catch{fail('Envía un objeto JSON válido.');}
    if(!d||typeof d!=='object'||Array.isArray(d))fail('Revisa los datos enviados.');return d;
}
function text(value,max,required=true){if(typeof value!=='string'||value.length>max||/[\x00-\x08\x0b\x0c\x0e-\x1f]/.test(value))fail('Revisa los campos de texto.');value=value.trim();if(required&&!value)fail('Completa los campos obligatorios.');return value;}
function image(value){if(value==null)return null;if(typeof value!=='string'||value.length>120000||!/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$/.test(value))fail('Imagen del catálogo no válida.');return value;}
function gallery(p){if(p.image)image(p.image);const images=p.images??(p.image?[p.image]:[]);if(!Array.isArray(images)||images.length>10)fail('Galería inválida.');return images.map(src=>{if(typeof src==='string'&&/^\/media\/[a-f0-9]{64}$/.test(src))return src;const valid=image(src);if(!valid)fail('Imagen inválida.');return valid;});}
function attributes(value){if(value==null||Array.isArray(value)&&!value.length)return {};if(typeof value!=='object'||Array.isArray(value))fail('Características inválidas.');const entries=Object.entries(value);if(entries.length>40)fail('Demasiadas características.');return Object.fromEntries(entries.filter(([,v])=>['string','number','boolean'].includes(typeof v)).map(([k,v])=>[text(k,100),text(String(v),2000,false)]));}
function quantity(value,decimal=true){const m=String(value).match(/^(\d{1,5})(?:\.(\d{1,4}))?$/);if(!m)fail('Revisa las cantidades.');const units=Number(m[1])*10000+Number((m[2]||'').padEnd(4,'0'));if(units<=0||units>100000000||(!decimal&&units%10000))fail('Revisa la cantidad y unidad del producto.');return units;}
function canonicalOrder(d){
    if(!uuid(d.request_key))fail('La referencia del pedido no es válida.');
    const name=text(d.name,120),phone=text(d.phone,30).replace(/[\s()-]/g,''),notes=text(d.notes||'',400,false);
    if(!/^\+?[0-9]{10,15}$/.test(phone))fail('Escribe un celular de 10 a 15 dígitos, con código de país si corresponde.');
    if(d.website)fail('No se pudo aceptar el pedido.');if(!Array.isArray(d.items)||!d.items.length||d.items.length>100)fail('Agrega entre 1 y 100 productos.');
    const seen=new Set();const items=d.items.map(l=>{if(!l||!/^[1-9][0-9]{0,18}$/.test(String(l.id))||seen.has(String(l.id)))fail('Revisa los productos del pedido.');seen.add(String(l.id));const units=quantity(l.quantity);return {id:String(l.id),quantity:(units/10000).toFixed(4)};}).sort((a,b)=>a.id.localeCompare(b.id));
    if(typeof d.catalog_version!=='string'||!/^[a-f0-9]{64}$/.test(d.catalog_version))fail('Actualiza el catálogo antes de enviar.');
    return {name,phone,notes,items,catalog_version:d.catalog_version};
}
function needsQuote(items){return items.some(l=>l.quote_only===true);}
function receipt(o){return {id:o.id,folio:o.folio,total_cents:o.total_cents,requires_quote:needsQuote(JSON.parse(o.items)),created:o.created};}
async function bridge(req,env,url){
    if(!env.PEDIDOS_BRIDGE_KEY||env.PEDIDOS_BRIDGE_KEY.length<32)fail('La conexión privada del portal todavía no está configurada.',503);
    const given=req.headers.get('Authorization')||'';const expected='Bearer '+env.PEDIDOS_BRIDGE_KEY;
    const a=await digest(given),b=await digest(expected);let different=0;for(let i=0;i<a.length;i++)different|=a.charCodeAt(i)^b.charCodeAt(i);if(different)fail('Acceso no autorizado.',401);
    if(url.pathname==='/bridge/media'&&req.method==='POST'){
        const d=await read(req,125000),data=image(d.data);if(!data||!/^[a-f0-9]{64}$/.test(d.hash)||await digest(data)!==d.hash)fail('Imagen inválida.');
        await env.DB.prepare('INSERT INTO media(hash,data) VALUES(?,?) ON CONFLICT(hash) DO NOTHING').bind(d.hash,data).run();return json({path:'/media/'+d.hash});
    }
    if(url.pathname==='/bridge/catalog'&&req.method==='PUT'){
        const d=await read(req,2097152);if(!/^[a-f0-9]{32}$/.test(d.installation)||!Array.isArray(d.products)||d.products.length>1000)fail('Catálogo inválido.');
        const old=await env.DB.prepare('SELECT installation FROM catalog WHERE id=1').first();if(old&&old.installation!==d.installation)fail('Este portal pertenece a otra instalación.',409);
        const ids=new Set();const products=d.products.map(p=>{const id=String(p.id),quote=p.quote_only===true;if(!/^[1-9][0-9]{0,18}$/.test(id)||ids.has(id)||(quote?p.price_cents!==null:(!Number.isSafeInteger(p.price_cents)||p.price_cents<0||p.price_cents>10000000000)))fail('Producto o precio inválido.');ids.add(id);return {id,name:text(p.name,200),variant:text(p.variant||'',150,false),description:text((p.description||'').slice(0,20000),20000,false),type:text(p.type||'estandar',20),attributes:attributes(p.attributes),category:text(p.category||'Sin categoría',200),sku:text(p.sku,150),unit:text(p.unit,20),decimal:!!p.decimal,quote_only:quote,price_cents:quote?null:p.price_cents,images:gallery(p)};});
        const theme={};for(const k of ['primary','secondary','accent']){if(!/^#[a-f0-9]{6}$/i.test(d[k]))fail('Color inválido.');theme[k]=d[k];}
        const catalog={name:text(d.name,150),...theme,logo:image(d.logo),products};const data=JSON.stringify(catalog),version=await digest(data);
        await env.DB.prepare('INSERT INTO catalog(id,installation,version,data,updated) VALUES(1,?,?,?,?) ON CONFLICT(id) DO UPDATE SET version=excluded.version,data=excluded.data,updated=excluded.updated').bind(d.installation,version,data,Math.floor(Date.now()/1000)).run();
        return json({version,products:products.length});
    }
    if(url.pathname==='/bridge/orders'&&req.method==='GET'){
        const after=url.searchParams.get('after')||'0';if(!/^[0-9]{1,15}$/.test(after))fail('Página inválida.');
        const rows=await env.DB.prepare('SELECT seq,id,folio,name,phone,notes,items,total_cents,created,status,pos_version FROM orders WHERE seq>? ORDER BY seq LIMIT 100').bind(Number(after)).all();
        return json({orders:rows.results.map(o=>{const items=JSON.parse(o.items);return {...o,items,requires_quote:needsQuote(items)};})});
    }
    if(url.pathname==='/bridge/status'&&req.method==='POST'){
        const d=await read(req);if(!Array.isArray(d.orders)||d.orders.length>100)fail('Estados inválidos.');
        const statements=d.orders.map(o=>{if(!uuid(o.id)||!states.has(o.status)||!Number.isSafeInteger(o.version)||o.version<1)fail('Estado inválido.');return env.DB.prepare('UPDATE orders SET status=?,pos_version=? WHERE id=? AND pos_version<?').bind(o.status,o.version,o.id,o.version);});
        if(statements.length)await env.DB.batch(statements);return json({updated:statements.length});
    }
    return json('Ruta no encontrada.',404);
}

export default {
    async fetch(req,env){
        try{
            const url=new URL(req.url);
            if(url.pathname==='/'&&req.method==='GET')return new Response(HTML,{headers:{'Content-Type':'text/html; charset=utf-8','Content-Security-Policy':"default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'",'X-Content-Type-Options':'nosniff','Referrer-Policy':'same-origin','Cache-Control':'no-store'}});
            if(url.pathname==='/app.css'&&req.method==='GET')return new Response(CSS,{headers:{'Content-Type':'text/css; charset=utf-8'}});
            if(url.pathname==='/app.js'&&req.method==='GET')return new Response(JS,{headers:{'Content-Type':'application/javascript; charset=utf-8'}});
            if(!env.DB)fail('El portal todavía no está preparado.',503);
            if(/^\/media\/[a-f0-9]{64}$/.test(url.pathname)&&req.method==='GET'){
                const media=await env.DB.prepare('SELECT data FROM media WHERE hash=?').bind(url.pathname.slice(7)).first();if(!media)return json('Imagen no encontrada.',404);const [meta,body]=media.data.split(',');return new Response(Uint8Array.from(atob(body),c=>c.charCodeAt(0)),{headers:{'Content-Type':meta.slice(5).split(';')[0],'Cache-Control':'public, max-age=31536000, immutable','X-Content-Type-Options':'nosniff'}});
            }
            if(url.pathname.startsWith('/bridge/'))return await bridge(req,env,url);
            if(url.pathname==='/api/catalog'&&req.method==='GET'){
                const c=await env.DB.prepare('SELECT version,data,updated FROM catalog WHERE id=1').first();return json(c?{...JSON.parse(c.data),version:c.version,updated:c.updated}:{name:'Pedidos',products:[],version:null});
            }
            if(url.pathname==='/api/orders'&&req.method==='POST'){
                const origin=req.headers.get('Origin');if(origin&&origin!==url.origin)fail('Envía el pedido desde el portal del negocio.',403);
                const raw=await read(req),d=canonicalOrder(raw),hash=await digest(JSON.stringify(d));
                const existing=await env.DB.prepare('SELECT * FROM orders WHERE request_key=?').bind(raw.request_key).first();
                if(existing){if(existing.payload_hash!==hash)fail('La referencia ya corresponde a otro pedido. Actualiza la página.',409);return json(receipt(existing));}
                const c=await env.DB.prepare('SELECT version,data FROM catalog WHERE id=1').first();if(!c||c.version!==d.catalog_version)fail('El catálogo cambió. Revisa los productos y el total antes de confirmar otra vez.',409);
                const products=JSON.parse(c.data).products;let total=0;
                const items=d.items.map(l=>{const p=products.find(p=>p.id===l.id);if(!p)fail('Un producto dejó de estar disponible. Revisa tu pedido.',409);const units=quantity(l.quantity,p.decimal),quote=p.quote_only===true,subtotal=quote?null:Math.round(p.price_cents*units/10000);if(subtotal!==null)total+=subtotal;return {...l,name:p.name,variant:p.variant,unit:p.unit,quote_only:quote,price_cents:quote?null:p.price_cents,total_cents:subtotal};});
                if(!Number.isSafeInteger(total)||total>10000000000)fail('El total supera el límite permitido.');
                const now=Math.floor(Date.now()/1000),bucket=Math.floor(now/3600),ip=req.headers.get('CF-Connecting-IP')||'local';
                const rate=await env.DB.prepare('INSERT INTO rate_limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1 RETURNING count').bind(await digest(ip+'|'+bucket),now+3600).first();
                if(rate.count>30)fail('Se alcanzó el límite de pedidos desde esta conexión. Intenta más tarde.',429);
                await env.DB.prepare('DELETE FROM rate_limits WHERE expires<?').bind(now-3600).run();
                const id=crypto.randomUUID(),folio='P-'+id.replaceAll('-','').slice(0,10).toUpperCase();
                await env.DB.prepare('INSERT INTO orders(id,request_key,payload_hash,folio,name,phone,notes,items,total_cents,created) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(request_key) DO NOTHING').bind(id,raw.request_key,hash,folio,d.name,d.phone,d.notes,JSON.stringify(items),total,now).run();
                const saved=await env.DB.prepare('SELECT * FROM orders WHERE request_key=?').bind(raw.request_key).first();if(saved.payload_hash!==hash)fail('La referencia se utilizó con otros datos.',409);return json(receipt(saved),201);
            }
            return json('Ruta no encontrada.',404);
        }catch(e){return json(e.status?e.message:'No se pudo completar la solicitud. Intenta nuevamente.',e.status||500);}
    }
};
