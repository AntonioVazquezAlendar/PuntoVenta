'use strict';
(() => {
    if (!document.getElementById('license-section')) return;
    const $ = id => document.getElementById(id);
    let actor = null, timer = null, busy = false, loading = false, epoch = 0;
    const names = {sin_activar: 'Sin activar', activa: 'Activa', gracia: 'En gracia', vencida: 'Vencida', invalida: 'Revisar clave', reloj_incorrecto: 'Revisar fecha', aun_no_vigente: 'Aún no vigente'};
    const plans = {prueba: 'Prueba de 15 días', mensual: 'Mensual · 30 días', anual: 'Anual · 365 días'};
    function date(value) {
        return value ? new Intl.DateTimeFormat('es-MX', {dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Mexico_City'}).format(new Date(value * 1000)) : '—';
    }
    function render(s) {
        $('license-badge').textContent = names[s.estado] || 'Revisar licencia';
        $('license-section').classList.toggle('license-attention', !s.operable || s.estado === 'gracia');
        $('license-description').textContent = s.mensaje + (s.estado === 'activa' ? ' Días restantes: ' + s.dias_restantes + '.' : '');
        const posNotice = $('sales-license-status');
        if (posNotice) {
            posNotice.hidden = s.operable && s.estado !== 'gracia' && s.dias_restantes > 5;
            posNotice.textContent = s.mensaje + (s.estado === 'activa' ? ' Días restantes: ' + s.dias_restantes + '.' : '') + ' Usa Volver al menú para abrir Licencia y activación.';
        }
        $('license-plan').textContent = plans[s.plan] || 'Sin licencia';
        $('license-business').textContent = s.negocio || '—';
        $('license-expires').textContent = date(s.vencimiento);
        $('license-installation').value = s.instalacion;
        $('license-grace-row').hidden = !s.fin_gracia || s.plan === 'prueba';
        $('license-grace').textContent = date(s.fin_gracia);
        $('license-trial-area').hidden = !s.prueba_disponible;
        const admin = !!actor?.permisos?.todo;
        $('license-admin').hidden = !admin;
        $('license-operator-note').hidden = admin;
        if (!s.operable || s.estado === 'gracia' || s.dias_restantes <= 5) $('license-details').open = true;
        document.dispatchEvent(new CustomEvent('puntoventa:license', {detail: s}));
    }
    async function request(path, method = 'GET', data) {
        let csrf;
        if (method !== 'GET') {
            const session = await fetch('/PuntoVenta/public/api/sesion', {credentials: 'same-origin', cache: 'no-store'});
            const json = await session.json();
            if (!session.ok || !json.success || !json.data.usuario) throw Error('Inicia sesión nuevamente para actualizar la licencia.');
            csrf = json.data.csrf;
        }
        const response = await fetch('/PuntoVenta/public/api/licencia' + path, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: method === 'GET' ? {'Accept': 'application/json'} : {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
            ...(method === 'GET' ? {} : {body: JSON.stringify(data || {})})
        });
        const json = await response.json();
        if (!response.ok || !json.success) throw Error(json.message || 'No se pudo consultar la licencia.');
        return json.data;
    }
    async function refresh() {
        if (!actor || busy || loading) return;
        const version = epoch;
        loading = true;
        try {
            const s = await request('');
            if (version !== epoch || !actor) return;
            render(s); $('license-message').textContent = '';
        } catch (e) {
            if (version !== epoch || !actor) return;
            $('license-badge').textContent = 'Sin verificar'; $('license-details').open = true;
            $('license-description').textContent = 'No se pudo verificar la licencia.';
            $('license-message').textContent = e.message || 'Revisa la conexión con el servidor y pulsa Actualizar licencia.';
        } finally { loading = false; }
    }
    async function change(path, data) {
        if (busy || !actor) return;
        const version = epoch;
        busy = true;
        ['license-activate', 'license-trial', 'license-refresh'].forEach(id => $(id).disabled = true);
        $('license-message').textContent = 'Validando licencia…';
        try {
            const s = await request(path, 'POST', data);
            if (version !== epoch || !actor) return;
            render(s); $('license-key').value = '';
            $('license-message').textContent = 'Licencia actualizada. ' + (s.operable ? 'Ya puedes continuar en el sistema.' : s.mensaje);
        } catch (e) {
            if (version === epoch && actor) $('license-message').textContent = e.message || 'Revisa tu conexión y vuelve a intentar.';
        } finally {
            busy = false; ['license-activate', 'license-trial', 'license-refresh'].forEach(id => $(id).disabled = false);
        }
    }
    $('license-form').addEventListener('submit', event => { event.preventDefault(); change('/activar', {clave: $('license-key').value.trim()}); });
    $('license-trial').addEventListener('click', () => change('/prueba'));
    $('license-refresh').addEventListener('click', refresh);
    $('license-copy').addEventListener('click', async () => {
        const code = $('license-installation').value;
        if (!code) return;
        try { await navigator.clipboard.writeText(code); $('license-message').textContent = 'Código copiado. Compártelo con tu proveedor.'; }
        catch (_) { $('license-installation').focus(); $('license-installation').select(); $('license-message').textContent = 'El código está seleccionado. Usa Copiar en tu dispositivo o Ctrl+C.'; }
    });
    document.addEventListener('puntoventa:session', event => {
        actor = event.detail; epoch++; clearInterval(timer);
        $('license-section').hidden = false; $('license-details').open = false;
        $('license-admin').hidden = !actor?.permisos?.todo;
        refresh(); timer = setInterval(refresh, 60000);
    });
    document.addEventListener('puntoventa:access', () => {
        actor = null; epoch++; clearInterval(timer); $('license-section').hidden = true; $('license-key').value = '';
        $('license-installation').value = ''; $('license-message').textContent = '';
    });
})();
