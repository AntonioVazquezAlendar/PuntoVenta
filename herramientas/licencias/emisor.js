#!/usr/bin/env node
'use strict';
// Herramienta del proveedor: NUNCA distribuir su directorio privado a los clientes.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');

function options(args) {
    const result = {};
    for (let i = 0; i < args.length; i += 2) {
        if (!/^--[a-z]+$/.test(args[i]) || !args[i + 1] || args[i + 1].startsWith('--') || result[args[i].slice(2)]) throw Error('Opciones inválidas o repetidas. Usa ayuda.');
        result[args[i].slice(2)] = args[i + 1];
    }
    return result;
}
function exclusiveFile(file, data) {
    fs.mkdirSync(path.dirname(file), {recursive: true});
    fs.writeFileSync(file, data, {flag: 'wx', mode: 0o600});
}
async function password() {
    if (process.env.PV_LICENCIA_PASSWORD) return process.env.PV_LICENCIA_PASSWORD;
    if (!process.stdin.isTTY) throw Error('Define PV_LICENCIA_PASSWORD o ejecuta en una terminal interactiva.');
    process.stdout.write('Contraseña privada del emisor (no se muestra): ');
    return new Promise((resolve, reject) => {
        let text = '';
        process.stdin.setRawMode(true); process.stdin.resume(); process.stdin.setEncoding('utf8');
        function done() { process.stdin.setRawMode(false); process.stdin.pause(); process.stdin.removeListener('data', read); process.stdout.write('\n'); }
        function read(input) {
            for (const c of input) {
                if (c === '\u0003') { done(); reject(Error('Cancelado.')); return; }
                if (c === '\r' || c === '\n') { done(); resolve(text); return; }
                if (c === '\u007f' || c === '\b') text = text.slice(0, -1);
                else if (c >= ' ') text += c;
            }
        }
        process.stdin.on('data', read);
    });
}
async function main() {
    const command = process.argv[2];
    if (!command || command === 'ayuda') {
        console.log('PuntoVenta: emisor privado de licencias\n\n' +
            'node emisor.js iniciar --directorio RUTA_PRIVADA --publica RUTA_PEM_PUBLICA\n' +
            'node emisor.js emitir --directorio RUTA_PRIVADA --instalacion PV-CODIGO --negocio "Nombre" --plan mensual|anual --salida CLAVE.txt\n\n' +
            'Mensual: 30 días. Anual: 365 días. Conserva el directorio privado y su contraseña.\n' +
            'Las renovaciones suman vigencia a la última clave emitida para esa instalación.');
        return;
    }
    if (!['iniciar', 'emitir'].includes(command)) throw Error('Comando desconocido. Usa ayuda.');
    const o = options(process.argv.slice(3));
    const allowed = command === 'iniciar' ? ['directorio', 'publica'] : ['directorio', 'instalacion', 'negocio', 'plan', 'salida'];
    if (Object.keys(o).some(k => !allowed.includes(k)) || allowed.some(k => !o[k])) throw Error('Faltan opciones o hay opciones desconocidas. Usa ayuda.');
    const dir = path.resolve(o.directorio);
    const privateFile = path.join(dir, 'privada.pem');
    const ledgerFile = path.join(dir, 'emisiones.json');
    if (command === 'emitir') {
        if (!/^PV-[A-F0-9]{32}$/.test(o.instalacion)) throw Error('Código de instalación inválido.');
        if (!['mensual', 'anual'].includes(o.plan)) throw Error('El plan debe ser mensual o anual.');
        if (!o.negocio.trim() || Buffer.byteLength(o.negocio.trim(), 'utf8') > 300) throw Error('Nombre de negocio inválido o demasiado largo.');
        if (fs.existsSync(path.resolve(o.salida))) throw Error('El archivo de salida ya existe; no se sobrescribió.');
    }
    const pass = await password();
    if (pass.length < 12) throw Error('Usa una contraseña privada de al menos 12 caracteres.');
    if (command === 'iniciar') {
        const publicFile = path.resolve(o.publica);
        if ([privateFile, ledgerFile, publicFile].some(f => fs.existsSync(f))) throw Error('Ya existen archivos de llaves o emisiones. No se reemplazaron.');
        if ([privateFile, ledgerFile].includes(publicFile)) throw Error('La llave pública necesita una ruta distinta.');
        const pair = crypto.generateKeyPairSync('rsa', {
            modulusLength: 3072,
            publicKeyEncoding: {type: 'spki', format: 'pem'},
            privateKeyEncoding: {type: 'pkcs8', format: 'pem', cipher: 'aes-256-cbc', passphrase: pass}
        });
        exclusiveFile(privateFile, pair.privateKey);
        exclusiveFile(ledgerFile, JSON.stringify({version: 1, emisiones: []}, null, 2));
        exclusiveFile(publicFile, pair.publicKey);
        console.log('Emisor creado. Llave privada cifrada: ' + privateFile + '\nLlave pública para las instalaciones: ' + publicFile);
        return;
    }
    const key = crypto.createPrivateKey({key: fs.readFileSync(privateFile), passphrase: pass});
    const lockPath = path.join(dir, 'emisiones.lock');
    let lock;
    try { lock = fs.openSync(lockPath, 'wx', 0o600); }
    catch (_) { throw Error('El emisor está ocupado o quedó un bloqueo tras una interrupción. Consulta LEEME.txt.'); }
    try {
        const ledger = JSON.parse(fs.readFileSync(ledgerFile, 'utf8'));
        if (ledger.version !== 1 || !Array.isArray(ledger.emisiones) || ledger.emisiones.some(p => !p || !Number.isSafeInteger(p.expires) || !/^PV-[A-F0-9]{32}$/.test(p.installation))) throw Error('El registro de emisiones no es válido. Conserva el archivo y recupera tu copia.');
        const now = Math.floor(Date.now() / 1000);
        const previous = ledger.emisiones.filter(p => p.installation === o.instalacion);
        const base = previous.reduce((end, p) => Math.max(end, p.expires), now);
        const p = {v: 1, installation: o.instalacion, business: o.negocio.trim(), plan: o.plan,
            issued: now, starts: now, expires: base + (o.plan === 'mensual' ? 30 : 365) * 86400, serial: crypto.randomBytes(16).toString('hex')};
        const body = Buffer.from(JSON.stringify(p), 'utf8').toString('base64url');
        const message = 'PV1.' + body;
        const token = message + '.' + crypto.sign('sha256', Buffer.from(message), key).toString('base64url');
        // El registro conserva la clave: permite recuperar una emisión si falla la salida.
        ledger.emisiones.push({...p, clave: token});
        const temp = ledgerFile + '.tmp';
        fs.writeFileSync(temp, JSON.stringify(ledger, null, 2), {flag: 'wx', mode: 0o600});
        fs.renameSync(temp, ledgerFile);
        exclusiveFile(path.resolve(o.salida), token + '\n');
        console.log('Clave generada para ' + p.business + '.\nVence: ' + new Date(p.expires * 1000).toLocaleString('es-MX', {timeZone: 'America/Mexico_City'}) + ' (Ciudad de México).\nArchivo: ' + path.resolve(o.salida));
    } finally { fs.closeSync(lock); fs.unlinkSync(lockPath); }
}
main().catch(e => { console.error('Error: ' + e.message); process.exitCode = 1; });
