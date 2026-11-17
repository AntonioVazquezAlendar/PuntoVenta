"""Licencias aisladas + emisor real Node/PHP. No cambia datos ni licencia del negocio."""
import concurrent.futures
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
BASE = ROOT / 'tests/tmp'
BASE.mkdir(parents=True, exist_ok=True)
fixture = Path(tempfile.mkdtemp(prefix='licencias-', dir=BASE)).resolve()
env = dict(os.environ, PV_LICENCIA_PASSWORD='QaLicencias-Only-2026!')
php = ['php', '-d', 'extension_dir=C:/AppServ/php7/ext', '-d', 'extension=php_openssl.dll'] if os.name == 'nt' else ['php']
emitter = ['node', str(ROOT/'herramientas/licencias/emisor.js')]
def run(cmd, ok=True, **kw):
    result = subprocess.run(cmd, cwd=ROOT, env=env, text=True, capture_output=True, **kw)
    if ok and result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    if not ok:
        assert result.returncode != 0, 'Se aceptó una operación inválida'
    return result
try:
    init = emitter + ['iniciar','--directorio',str(fixture/'emisor'),'--publica',str(fixture/'publica.pem')]
    run(init)
    original_key = (fixture/'publica.pem').read_bytes()
    run(init, ok=False)
    assert (fixture/'publica.pem').read_bytes() == original_key
    print(run(php + [str(ROOT/'tests/licencias.php'), str(fixture)]).stdout.strip())
    seed = 'a'*64
    identity = 'PV-' + hashlib.sha256((seed+'|qa-server').encode()).hexdigest()[:32].upper()
    commands = emitter + ['emitir','--directorio',str(fixture/'emisor'),'--instalacion',identity,'--negocio','QA Ñ negocio']
    run(commands+['--plan','mensual','--salida',str(fixture/'month.txt')])
    run(commands+['--plan','anual','--salida',str(fixture/'year.txt')])
    ledger = json.loads((fixture/'emisor/emisiones.json').read_text())['emisiones']
    assert len(ledger) == 2
    assert ledger[0]['expires'] - ledger[0]['issued'] == 30*86400
    assert ledger[1]['expires'] - ledger[0]['expires'] == 365*86400
    run(commands+['--plan','mensual','--salida',str(fixture/'year.txt')],ok=False)
    run(commands+['--plan','otro','--salida',str(fixture/'bad.txt')],ok=False)
    wrong = dict(env, PV_LICENCIA_PASSWORD='Wrong-Password-123')
    bad = subprocess.run(commands+['--plan','mensual','--salida',str(fixture/'bad.txt')],cwd=ROOT,env=wrong,capture_output=True)
    assert bad.returncode and not (fixture/'bad.txt').exists()
    assert len(json.loads((fixture/'emisor/emisiones.json').read_text())['emisiones']) == 2
    state_root = fixture/'interop'; state_root.mkdir()
    (state_root/'estado.json').write_text(json.dumps({'version':1,'seed':seed,'last_seen':ledger[1]['issued'],'trial_started':0,'token':None}))
    # Verifica en PHP las firmas producidas realmente por Node y su renovación.
    code = '''require 'app/Helpers/HttpError.php'; require 'app/Services/Licencia.php';
    $f=$argv[1];$m=new Licencia($f.'/interop',$f.'/publica.pem',null,'qa-server');
    $a=$m->activar(trim(file_get_contents($f.'/month.txt')));$b=$m->activar(trim(file_get_contents($f.'/year.txt')));
    if(!$a['operable']||!$b['operable']||$b['vencimiento']-$a['vencimiento']!==365*86400||$b['negocio']!=='QA Ñ negocio')exit(2);
    echo 'OK: interoperabilidad Node/PHP y renovación acumulada de 30 + 365 días.';'''
    print(run(php+['-r',code,str(fixture)]).stdout)
    trial_code = '''require 'app/Helpers/HttpError.php';require 'app/Services/Licencia.php';
    $m=new Licencia($argv[1].'/concurrent',$argv[1].'/publica.pem',null,'qa-server');
    try{$m->iniciarPrueba();echo 'started';}catch(HttpError $e){echo $e->getCode();}'''
    with concurrent.futures.ThreadPoolExecutor(max_workers=5) as pool:
        results = list(pool.map(lambda _:run(php+['-r',trial_code,str(fixture)]).stdout,range(5)))
    assert results.count('started') == 1 and results.count('409') == 4, results
    print('OK: cinco aperturas concurrentes solo inician una prueba; claves privadas y archivos existentes protegidos.')
finally:
    assert fixture.parent == BASE.resolve() and fixture.name.startswith('licencias-')
    shutil.rmtree(fixture)
