import {readFileSync,writeFileSync,mkdirSync,copyFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
const root=fileURLToPath(new URL('../',import.meta.url));
const web=readFileSync(root+'worker/web.js','utf8').replace(/export const /g,'const ');
const worker=readFileSync(root+'worker/index.js','utf8').replace("import { HTML, CSS, JS } from './web.js';",'');
mkdirSync(root+'dist/server',{recursive:true});mkdirSync(root+'dist/.openai',{recursive:true});
writeFileSync(root+'dist/server/index.js',web+'\n'+worker);
copyFileSync(root+'.openai/hosting.json',root+'dist/.openai/hosting.json');
console.log('Portal construido en dist/server/index.js');
