import {DatabaseSync} from 'node:sqlite';
import {readFileSync} from 'node:fs';
export function openDatabase(file){
    const db=new DatabaseSync(file);db.exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;');
    if(db.prepare('PRAGMA user_version').get().user_version===0){db.exec('BEGIN;'+readFileSync(new URL('../drizzle/0000_pedidos.sql',import.meta.url),'utf8')+'; PRAGMA user_version=1; COMMIT;');}
    if(db.prepare('PRAGMA user_version').get().user_version===1){db.exec('BEGIN;'+readFileSync(new URL('../drizzle/0001_galeria.sql',import.meta.url),'utf8')+'; PRAGMA user_version=2; COMMIT;');}
    const prepare=(sql,args=[])=>({bind:(...values)=>prepare(sql,values),first:async()=>db.prepare(sql).get(...args)||null,all:async()=>({results:db.prepare(sql).all(...args)}),run:async()=>{const r=db.prepare(sql).run(...args);return {success:true,meta:{changes:Number(r.changes)}};},_sql:sql,_args:args});
    return {prepare,batch:async list=>{db.exec('BEGIN IMMEDIATE');try{const result=list.map(s=>{const r=db.prepare(s._sql).run(...s._args);return {success:true,meta:{changes:Number(r.changes)}};});db.exec('COMMIT');return result;}catch(e){db.exec('ROLLBACK');throw e;}},close:()=>db.close()};
}
