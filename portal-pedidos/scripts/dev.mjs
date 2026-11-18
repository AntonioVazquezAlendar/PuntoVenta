import http from 'node:http';
import {mkdirSync,readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {webcrypto} from 'node:crypto';
import worker from '../worker/index.js';
import {openDatabase} from './local-db.mjs';
globalThis.crypto??=webcrypto;
const root=fileURLToPath(new URL('../',import.meta.url));
let values={};try{for(const line of readFileSync(root+'.dev.vars','utf8').split(/\r?\n/)){const m=line.match(/^([A-Z_]+)=(.*)$/);if(m)values[m[1]]=m[2];}}catch{}
const key=process.env.PEDIDOS_BRIDGE_KEY||values.PEDIDOS_BRIDGE_KEY;
if(!key||key.length<32)throw Error('Prepara primero la conexión con scripts/pedidos/preparar-local.php.');
mkdirSync(root+'.local',{recursive:true});
const db=openDatabase(process.env.PEDIDOS_DB||root+'.local/portal.sqlite');const port=Number(process.env.PORT||8788);
const server=http.createServer(async(req,res)=>{
    try{
        const chunks=[];let bytes=0;for await(const c of req){bytes+=c.length;if(bytes>2097152){res.writeHead(413);res.end('Contenido demasiado grande.');return;}chunks.push(c);}
        const headers=new Headers(req.headers);headers.set('CF-Connecting-IP',req.socket.remoteAddress||'local');
        const request=new Request('http://'+req.headers.host+req.url,{method:req.method,headers,...(['GET','HEAD'].includes(req.method)?{}:{body:Buffer.concat(chunks),duplex:'half'})});
        const response=await worker.fetch(request,{DB:db,PEDIDOS_BRIDGE_KEY:key});res.writeHead(response.status,Object.fromEntries(response.headers));res.end(Buffer.from(await response.arrayBuffer()));
    }catch{res.writeHead(500,{'Content-Type':'application/json'});res.end(JSON.stringify({ok:false,message:'No se pudo completar la solicitud.'}));}
});
server.listen(port,()=>console.log('Portal local: http://localhost:'+port+' (solo red local; no está publicado en internet).'));
for(const signal of ['SIGTERM','SIGINT'])process.on(signal,()=>server.close(()=>{db.close();process.exit(0);}));
