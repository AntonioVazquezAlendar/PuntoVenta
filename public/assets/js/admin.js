'use strict';
(() => {
    const base = '/PuntoVenta/public/api';
    const $ = id => document.getElementById(id);
    let csrf = '', current = null, setup = false, users = [], roles = [], editingId = null;
    let company = null, logoPreview = null, companyDirty = false;
    let permissionCatalog = [], permissionsDirty = false, permissionRoleId = null;
    let roleDraft = null;

    function closeMenu(focus=false){
        document.body.classList.remove('sidebar-open');$('menu-toggle').setAttribute('aria-expanded','false');$('menu-backdrop').hidden=true;
        if(focus)$('menu-toggle').focus();
    }
    $('menu-toggle').addEventListener('click',()=>{const opening=!document.body.classList.contains('sidebar-open');document.body.classList.toggle('sidebar-open',opening);$('menu-toggle').setAttribute('aria-expanded',String(opening));$('menu-backdrop').hidden=!opening;if(opening)$('menu-close').focus();});
    $('menu-close').addEventListener('click',()=>closeMenu(true));$('menu-backdrop').addEventListener('click',()=>closeMenu(true));
    $('admin-nav').addEventListener('click',event=>{if(event.target.closest('button'))closeMenu();});
    document.addEventListener('keydown',event=>{if(event.key==='Escape'&&document.body.classList.contains('sidebar-open'))closeMenu(true);});

    async function api(path, method = 'GET', data) {
        const options = {method, credentials: 'same-origin', headers: {'Accept': 'application/json'}};
        if (method !== 'GET') {
            options.headers['X-CSRF-Token'] = csrf;
            if (data instanceof FormData) { options.body = data; }
            else {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(data || {});
            }
        }
        let response, result;
        try { response = await fetch(base + path, options); result = await response.json(); }
        catch (_) { throw new Error('No se pudo conectar. Revisa tu conexión y vuelve a intentar.'); }
        if (!response.ok || !result.success) {
            if (response.status === 401 && current) {
                current = null;
                showAccess(false);
                $('global-status').textContent = 'Tu sesión terminó. Inicia sesión de nuevo.';
            }
            const error=new Error(result.message || 'No se pudo completar la operación.');error.status=response.status;throw error;
        }
        return result.data;
    }

    function status(message, error = false) {
        $('global-status').textContent = message;
        $('global-status').classList.toggle('error', error);
    }

    function showAccess(initial) {
        document.dispatchEvent(new CustomEvent('puntoventa:access'));
        closeMenu();document.body.classList.remove('has-navigation');$('app-sidebar').hidden=true;$('menu-toggle').hidden=true;
        setup = initial;
        $('access-view').hidden = false;
        $('users-view').hidden = true;
        $('company-view').hidden = true;
        $('permissions-view').hidden = true;
        $('products-view').hidden = true;
        $('inventory-view').hidden = true;
        $('purchases-view').hidden = true;
        $('cash-view').hidden = true;
        $('sales-view').hidden = true;$('clients-view').hidden=true;$('reports-view').hidden=true;$('credits-view').hidden=true;$('payables-view').hidden=true;$('expenses-view').hidden=true;$('backups-view').hidden=true;$('home-view').hidden=true;$('audit-view').hidden=true;$('nav-audit').removeAttribute('aria-current');$('nav-home').removeAttribute('aria-current');$('nav-backups').removeAttribute('aria-current');$('nav-expenses').removeAttribute('aria-current');$('nav-payables').removeAttribute('aria-current');$('nav-credits').removeAttribute('aria-current');$('nav-reports').removeAttribute('aria-current');
        $('admin-nav').hidden = true;
        $('welcome-view').hidden = true;
        $('session-bar').hidden = true;
        $('access-form').reset();
        $('access-message').textContent = '';
        $('setup-name-field').hidden = !initial;
        $('setup-confirm-field').hidden = !initial;
        $('password-help').hidden = !initial;
        $('setup-name').required = initial;
        $('setup-confirm').required = initial;
        $('login-password').minLength = initial ? 8 : 1;
        $('login-password').autocomplete = initial ? 'new-password' : 'current-password';
        $('access-title').textContent = initial ? 'Crea tu administrador' : 'Iniciar sesión';
        $('access-description').textContent = initial ? 'Es el primer acceso. Elige los datos de tu cuenta de administrador.' : 'Escribe los datos de tu cuenta.';
        $('access-submit').textContent = initial ? 'Crear administrador' : 'Entrar';
    }

    async function showSession() {
        $('access-view').hidden = true;
        $('access-form').reset();
        $('session-bar').hidden = false;
        $('session-name').textContent = current.nombre + ' · ' + current.rol_nombre;
        const admin = !!current.permisos.todo;
        const catalog = admin || !!current.permisos.ver_productos;
        const inventory = admin || !!current.permisos.ver_inventario;
        const purchases = admin || !!current.permisos.hacer_compras;
        const cash = admin || !!current.permisos.operar_caja;
        const clients=admin||!!current.permisos.ver_clientes;
        const expenses=admin||!!current.permisos.ver_gastos;$('nav-expenses').hidden=!expenses;
        const payables=admin||!!current.permisos.ver_cuentas_pagar;$('nav-payables').hidden=!payables;
        const credits=admin||!!current.permisos.ver_creditos;$('nav-credits').hidden=!credits;
        const reports=admin||!!current.permisos.ver_reportes;$('nav-reports').hidden=!reports;
        const sales = admin || !!current.permisos.vender || !!current.permisos.ver_ventas;
        $('admin-nav').hidden = !admin && !catalog && !inventory && !purchases && !cash && !sales && !clients && !reports && !credits && !payables && !expenses;
        const navigation=!$('admin-nav').hidden;document.body.classList.toggle('has-navigation',navigation);$('app-sidebar').hidden=!navigation;$('menu-toggle').hidden=!navigation;closeMenu();
        $('nav-clients').hidden=!clients;$('nav-clients').removeAttribute('aria-current');$('clients-view').hidden=true;$('reports-view').hidden=true;$('credits-view').hidden=true;$('payables-view').hidden=true;$('expenses-view').hidden=true;$('backups-view').hidden=true;$('home-view').hidden=true;$('audit-view').hidden=true;$('nav-audit').removeAttribute('aria-current');$('nav-home').removeAttribute('aria-current');$('nav-backups').removeAttribute('aria-current');$('nav-expenses').removeAttribute('aria-current');$('nav-payables').removeAttribute('aria-current');$('nav-credits').removeAttribute('aria-current');$('nav-reports').removeAttribute('aria-current');
        $('nav-sales').hidden=!sales;$('nav-sales').removeAttribute('aria-current');$('sales-view').hidden=true;
        $('nav-cash').hidden = !cash;$('nav-cash').removeAttribute('aria-current');$('cash-view').hidden = true;
        $('nav-purchases').hidden = !purchases;
        $('nav-purchases').removeAttribute('aria-current');
        $('purchases-view').hidden = true;
        ['nav-users','nav-permissions','nav-company','nav-backups','nav-home','nav-audit'].forEach(id => { $(id).hidden = !admin; });
        $('nav-products').hidden = !catalog;
        $('nav-inventory').hidden = !inventory;
        $('nav-inventory').removeAttribute('aria-current');
        $('inventory-view').hidden = true;
        $('nav-products').removeAttribute('aria-current');
        $('products-view').hidden = true;
        $('company-view').hidden = true;
        $('permissions-view').hidden = true;
        $('nav-permissions').removeAttribute('aria-current');
        $('nav-users').setAttribute('aria-current', 'page');
        $('nav-company').removeAttribute('aria-current');
        $('users-view').hidden = true;$('nav-users').removeAttribute('aria-current');$('home-view').hidden=!admin;if(admin)$('nav-home').setAttribute('aria-current','page');
        $('welcome-view').hidden = admin;
        status('');
        if (!admin) {
            $('welcome-title').textContent = 'Hola, ' + current.nombre;
            $('welcome-role').textContent = 'Entraste con el rol ' + current.rol_nombre + '.';
        }
        document.dispatchEvent(new CustomEvent('puntoventa:session', {detail:current}));
    }

    async function loadUsers() {
        status('Cargando usuarios…');
        const results = await Promise.all([api('/usuarios'), api('/roles'), api('/permisos')]);
        users = results[0]; roles = results[1]; permissionCatalog = results[2];
        syncUserRoles();
        renderUsers(); status('');
    }

    function renderUsers() {
        const query = $('search-users').value.trim().toLocaleLowerCase('es');
        const active = $('filter-status').value;
        const filtered = users.filter(user => (user.nombre + ' ' + user.nombre_usuario).toLocaleLowerCase('es').includes(query)
            && (active === '' || String(user.activo) === active));
        $('users-body').replaceChildren();
        filtered.forEach(user => {
            const row = document.createElement('tr');
            const person = document.createElement('td');
            const name = document.createElement('strong'); name.textContent = user.nombre;
            const login = document.createElement('small'); login.textContent = user.nombre_usuario;
            person.append(name, login);
            const role = document.createElement('td'); role.textContent = user.rol_nombre;
            const state = document.createElement('td');
            const badge = document.createElement('span'); badge.className = 'badge' + (Number(user.activo) ? '' : ' inactive');
            badge.textContent = Number(user.activo) ? 'Activo' : 'Inactivo'; state.append(badge);
            const action = document.createElement('td');
            const button = document.createElement('button'); button.type = 'button'; button.className = 'edit-button';
            button.textContent = 'Editar'; button.setAttribute('aria-label', 'Editar a ' + user.nombre);
            button.addEventListener('click', () => openEditor(user)); action.append(button);
            row.append(person, role, state, action); $('users-body').append(row);
        });
        $('users-empty').hidden = filtered.length !== 0;
        $('users-count').textContent = filtered.length + (filtered.length === 1 ? ' usuario' : ' usuarios') + ' · ' + users.filter(u => Number(u.activo)).length + ' activos en total';
    }

    function roleDescription() {
        const role = roles.find(r => String(r.id) === $('user-role').value);
        if (!role) { $('role-description').textContent = ''; return; }
        const labels = {ver_inventario:'consultar inventario',editar_productos:'editar catálogo',ver_productos:'consultar productos', vender:'vender', dar_descuento:'dar descuentos', cambiar_precio:'cambiar precios', cancelar_venta:'cancelar ventas', ajustar_inventario:'ajustar inventario', ver_reportes:'ver reportes', hacer_devoluciones:'hacer devoluciones', hacer_compras:'hacer compras'};
        Object.assign(labels,{ver_gastos:'consultar gastos propios',registrar_gastos:'registrar gastos',ver_cuentas_pagar:'consultar cuentas por pagar',pagar_proveedores:'registrar pagos a proveedores',vender_credito:'vender a crédito',ver_creditos:'consultar créditos propios',cobrar_abonos:'recibir abonos',ver_clientes:'consultar clientes',editar_clientes:'editar clientes',ver_ventas:'consultar sus propias ventas y tickets',operar_caja:'operar sus turnos de caja',supervisar_caja:'supervisar otros turnos',administrar_cajas:'configurar cajas',administrar_ubicaciones:'administrar ubicaciones',realizar_traspasos:'realizar traspasos',registrar_mermas:'registrar mermas'});
        $('role-description').textContent = role.permisos.todo ? 'Acceso completo, incluida la administración de usuarios.'
            : 'Permisos: ' + Object.keys(role.permisos).filter(key => role.permisos[key]).map(key => labels[key] || key).join(', ') + '.';
    }

    function openEditor(user = null) {
        editingId = user ? user.id : null;
        $('user-form').reset(); $('user-message').textContent = '';
        $('editor').hidden = false; document.querySelector('.workspace').classList.add('editing');
        $('editor-title').textContent = user ? 'Editar usuario' : 'Crear usuario';
        $('save-user').textContent = user ? 'Guardar cambios' : 'Crear usuario';
        $('user-name').value = user ? user.nombre : '';
        $('user-login').value = user ? user.nombre_usuario : '';
        const cashier = roles.find(r => r.nombre === 'Cajero');
        $('user-role').value = user ? user.rol_id : (cashier ? cashier.id : roles[0].id);
        $('user-active').value = user ? String(user.activo) : '1';
        const self = user && String(user.id) === String(current.id);
        $('user-active').disabled = !!self; $('user-role').disabled = !!self;
        $('user-password').required = !user; $('user-password').minLength = 8;
        $('user-password-label').textContent = user ? 'Nueva contraseña (opcional)' : 'Contraseña';
        $('edit-password-help').textContent = user ? 'Déjala vacía para conservar la actual. Si la cambias, usa al menos 8 caracteres.' : 'Usa al menos 8 caracteres.';
        roleDescription(); $('user-name').focus();
    }

    function closeEditor() {
        $('editor').hidden = true; document.querySelector('.workspace').classList.remove('editing');
        $('user-form').reset(); editingId = null;
    }

    $('access-form').addEventListener('submit', async event => {
        event.preventDefault(); $('access-message').textContent = '';
        if (setup && $('login-password').value !== $('setup-confirm').value) {
            $('access-message').textContent = 'Las contraseñas no coinciden.'; return;
        }
        const data = Object.fromEntries(new FormData(event.currentTarget));
        $('access-submit').disabled = true;
        try {
            if (setup) {
                await api('/configuracion-inicial', 'POST', data);
                showAccess(false); $('login-name').value = data.nombre_usuario;
                status('Administrador creado. Inicia sesión con la contraseña que elegiste.');
                $('login-password').focus();
            } else {
                const session = await api('/login', 'POST', data);
                current = session.usuario; csrf = session.csrf;
                closeEditor(); await showSession();
            }
        } catch (error) { $('access-message').textContent = error.message; }
        finally { $('access-submit').disabled = false; }
    });

    $('user-form').addEventListener('submit', async event => {
        event.preventDefault(); $('user-message').textContent = ''; $('save-user').disabled = true;
        const data = Object.fromEntries(new FormData(event.currentTarget));
        if ($('user-role').disabled) { data.rol_id = $('user-role').value; data.activo = $('user-active').value; }
        try {
            const saved = await api(editingId ? '/usuarios/' + editingId : '/usuarios', editingId ? 'PUT' : 'POST', data);
            if (String(saved.id) === String(current.id)) { current = saved; $('session-name').textContent = current.nombre + ' · ' + current.rol_nombre; }
            const edited = editingId !== null;
            closeEditor(); await loadUsers(); status(edited ? 'Cambios guardados.' : 'Usuario creado. Ya puede iniciar sesión.');
            $('new-user').focus();
        } catch (error) { $('user-message').textContent = error.message; }
        finally { $('save-user').disabled = false; }
    });

    $('logout').addEventListener('click', async () => {
        if(window.PuntoVenta.leaveBackups&&!window.PuntoVenta.leaveBackups()){return false;}if(window.PuntoVenta.leaveExpenses&&!window.PuntoVenta.leaveExpenses()){return;}if(window.PuntoVenta.leavePayables&&!window.PuntoVenta.leavePayables()){return;}if(window.PuntoVenta.leaveCredits&&!window.PuntoVenta.leaveCredits())return;
        $('logout').disabled = true;
        try { await api('/logout', 'POST'); current = null; closeEditor(); await init(); status('Sesión cerrada.'); }
        catch (error) { status(error.message, true); }
        finally { $('logout').disabled = false; }
    });
    $('new-user').addEventListener('click', () => openEditor());
    $('cancel-edit').addEventListener('click', () => { closeEditor(); $('new-user').focus(); });
    $('search-users').addEventListener('input', renderUsers);
    $('filter-status').addEventListener('change', renderUsers);
    $('user-role').addEventListener('change', roleDescription);
    document.querySelectorAll('[data-password]').forEach(button => button.addEventListener('click', () => {
        const input = $(button.dataset.password); const show = input.type === 'password';
        input.type = show ? 'text' : 'password'; button.textContent = show ? 'Ocultar' : 'Mostrar';
        button.setAttribute('aria-pressed', String(show));
    }));

    async function init() {
        try {
            const [session, brand] = await Promise.all([api('/sesion'), api('/empresa')]);
            company = brand; applyBrand(brand);
            csrf = session.csrf; current = session.usuario;
            if (current) { await showSession(); }
            else {
                showAccess(session.configuracion_inicial);
                if (session.configuracion_inicial && !session.configuracion_local) {
                    $('access-form').hidden = true;
                    status('Abre esta página desde el equipo del servidor para crear el primer administrador.');
                } else { $('access-form').hidden = false; status(''); }
            }
        } catch (error) { status(error.message + ' Recarga la página para reintentar.', true); }
    }

    function applyBrand(brand) {
        $('brand-name').textContent = brand.nombre_comercial;
        $('footer-brand').textContent = brand.nombre_comercial;
        document.title = brand.nombre_comercial + ' · Administración';
        $('brand-logo').hidden = !brand.logo_url;
        if (brand.logo_url) { $('brand-logo').src = brand.logo_url; }
        else { $('brand-logo').removeAttribute('src'); }
        $('brand-styles').href = base + '/empresa/tema.css?v=' + Date.now();
    }

    function contrast(color) {
        const rgb = color.match(/[a-f0-9]{2}/gi).map(value => parseInt(value, 16) / 255)
            .map(value => value <= .04045 ? value / 12.92 : Math.pow((value + .055) / 1.055, 2.4));
        const luminance = rgb[0] * .2126 + rgb[1] * .7152 + rgb[2] * .0722;
        return 1.05 / (luminance + .05) >= (luminance + .05) / .05 ? '#ffffff' : '#000000';
    }

    function previewCompany() {
        $('preview-name').textContent = $('company-name').value.trim() || 'Tu negocio';
        const primary = $('company-primary').value, secondary = $('company-secondary').value, accent = $('company-accent').value;
        if (/^#[a-f0-9]{6}$/i.test(primary)) {
            $('preview-button').style.backgroundColor = primary;
            $('preview-button').style.color = contrast(primary);
        }
        if (/^#[a-f0-9]{6}$/i.test(secondary)) {
            $('preview-header').style.backgroundColor = secondary;
            $('preview-header').style.color = contrast(secondary);
        }
        if (/^#[a-f0-9]{6}$/i.test(accent)) { $('preview-detail').style.backgroundColor = accent; }
        const logo = $('company-remove-logo').checked ? null : (logoPreview || company.logo_url);
        $('preview-logo').hidden = !logo;
        if (logo) { $('preview-logo').src = logo; } else { $('preview-logo').removeAttribute('src'); }
        $('company-dirty').textContent = companyDirty ? 'Tienes cambios sin guardar.' : 'Todos los cambios están guardados.';
    }

    function fillCompany() {
        if (logoPreview) { URL.revokeObjectURL(logoPreview); logoPreview = null; }
        $('company-form').reset();
        $('company-name').value = company.nombre_comercial;
        [['primary', 'primario'], ['secondary', 'secundario'], ['accent', 'acento']].forEach(([id, key]) => {
            $('company-' + id).value = company['color_' + key];
            $('company-' + id + '-picker').value = company['color_' + key];
        });
        $('company-message').textContent = ''; companyDirty = false; previewCompany();
    }

    $('nav-company').addEventListener('click', () => {
        if(window.PuntoVenta.leaveBackups&&!window.PuntoVenta.leaveBackups()){return false;}if(window.PuntoVenta.leaveExpenses&&!window.PuntoVenta.leaveExpenses()){return;}if(window.PuntoVenta.leavePayables&&!window.PuntoVenta.leavePayables()){return;}if(window.PuntoVenta.leaveCredits&&!window.PuntoVenta.leaveCredits()){return;}if(window.PuntoVenta.leaveClients&&!window.PuntoVenta.leaveClients()){return;}$('clients-view').hidden=true;$('reports-view').hidden=true;$('credits-view').hidden=true;$('payables-view').hidden=true;$('expenses-view').hidden=true;$('backups-view').hidden=true;$('home-view').hidden=true;$('audit-view').hidden=true;$('nav-audit').removeAttribute('aria-current');$('nav-home').removeAttribute('aria-current');$('nav-backups').removeAttribute('aria-current');$('nav-expenses').removeAttribute('aria-current');$('nav-payables').removeAttribute('aria-current');$('nav-credits').removeAttribute('aria-current');$('nav-reports').removeAttribute('aria-current');$('nav-clients').removeAttribute('aria-current');
        if(window.PuntoVenta.leaveSales&&!window.PuntoVenta.leaveSales()){return;}$('sales-view').hidden=true;$('nav-sales').removeAttribute('aria-current');
        if(window.PuntoVenta.leaveCash&&!window.PuntoVenta.leaveCash()){return;}
        $('cash-view').hidden=true;$('nav-cash').removeAttribute('aria-current');
        if (window.PuntoVenta.leavePurchases && !window.PuntoVenta.leavePurchases()) { return; }
        $('purchases-view').hidden = true; $('nav-purchases').removeAttribute('aria-current');
        if (window.PuntoVenta.leaveInventory && !window.PuntoVenta.leaveInventory()) { return; }
        $('inventory-view').hidden = true; $('nav-inventory').removeAttribute('aria-current');
        if (window.PuntoVenta.leaveCatalog && !window.PuntoVenta.leaveCatalog()) { return; }
        $('products-view').hidden = true; $('nav-products').removeAttribute('aria-current');
        if (!$('company-view').hidden) { return; }
        if (!leavePermissions()) { return; }
        $('permissions-view').hidden = true; $('nav-permissions').removeAttribute('aria-current');
        $('users-view').hidden = true; $('company-view').hidden = false;
        $('nav-company').setAttribute('aria-current', 'page'); $('nav-users').removeAttribute('aria-current');
        fillCompany(); status('');
    });
    $('nav-users').addEventListener('click', async () => {
        if(window.PuntoVenta.leaveBackups&&!window.PuntoVenta.leaveBackups()){return false;}if(window.PuntoVenta.leaveExpenses&&!window.PuntoVenta.leaveExpenses()){return;}if(window.PuntoVenta.leavePayables&&!window.PuntoVenta.leavePayables()){return;}if(window.PuntoVenta.leaveCredits&&!window.PuntoVenta.leaveCredits()){return;}if(window.PuntoVenta.leaveClients&&!window.PuntoVenta.leaveClients()){return;}$('clients-view').hidden=true;$('reports-view').hidden=true;$('credits-view').hidden=true;$('payables-view').hidden=true;$('expenses-view').hidden=true;$('backups-view').hidden=true;$('home-view').hidden=true;$('audit-view').hidden=true;$('nav-audit').removeAttribute('aria-current');$('nav-home').removeAttribute('aria-current');$('nav-backups').removeAttribute('aria-current');$('nav-expenses').removeAttribute('aria-current');$('nav-payables').removeAttribute('aria-current');$('nav-credits').removeAttribute('aria-current');$('nav-reports').removeAttribute('aria-current');$('nav-clients').removeAttribute('aria-current');
        if(window.PuntoVenta.leaveSales&&!window.PuntoVenta.leaveSales()){return;}$('sales-view').hidden=true;$('nav-sales').removeAttribute('aria-current');
        if(window.PuntoVenta.leaveCash&&!window.PuntoVenta.leaveCash()){return;}
        $('cash-view').hidden=true;$('nav-cash').removeAttribute('aria-current');
        if (window.PuntoVenta.leavePurchases && !window.PuntoVenta.leavePurchases()) { return; }
        $('purchases-view').hidden = true; $('nav-purchases').removeAttribute('aria-current');
        if (window.PuntoVenta.leaveInventory && !window.PuntoVenta.leaveInventory()) { return; }
        $('inventory-view').hidden = true; $('nav-inventory').removeAttribute('aria-current');
        if (window.PuntoVenta.leaveCatalog && !window.PuntoVenta.leaveCatalog()) { return; }
        $('products-view').hidden = true; $('nav-products').removeAttribute('aria-current');
        if (!leavePermissions()) { return; }
        if (companyDirty && !window.confirm('Tienes cambios sin guardar en Mi negocio. ¿Quieres descartarlos?')) { return; }
        $('permissions-view').hidden = true; $('nav-permissions').removeAttribute('aria-current');
        companyDirty = false; $('company-view').hidden = true; $('users-view').hidden = false;
        $('nav-users').setAttribute('aria-current', 'page'); $('nav-company').removeAttribute('aria-current');
        try{await loadUsers();}catch(e){status(e.message,true);}
    });
    $('company-cancel').addEventListener('click', fillCompany);
    ['primary', 'secondary', 'accent'].forEach(id => {
        const text = $('company-' + id), picker = $('company-' + id + '-picker');
        picker.addEventListener('input', () => { text.value = picker.value.toUpperCase(); companyDirty = true; previewCompany(); });
        text.addEventListener('input', () => {
            if (/^#[a-f0-9]{6}$/i.test(text.value)) { picker.value = text.value; }
            companyDirty = true; previewCompany();
        });
    });
    $('company-name').addEventListener('input', () => { companyDirty = true; previewCompany(); });
    $('company-remove-logo').addEventListener('change', () => {
        if ($('company-remove-logo').checked) {
            $('company-logo').value = '';
            if (logoPreview) { URL.revokeObjectURL(logoPreview); logoPreview = null; }
        }
        companyDirty = true; previewCompany();
    });
    $('company-logo').addEventListener('change', () => {
        const file = $('company-logo').files[0]; $('company-message').textContent = '';
        if (file && (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024)) {
            $('company-message').textContent = 'Selecciona un logo PNG, JPG o WebP de hasta 2 MB.';
            $('company-logo').value = ''; return;
        }
        if (logoPreview) { URL.revokeObjectURL(logoPreview); }
        logoPreview = file ? URL.createObjectURL(file) : null;
        if (file) { $('company-remove-logo').checked = false; }
        companyDirty = true; previewCompany();
    });
    $('company-form').addEventListener('submit', async event => {
        event.preventDefault(); $('company-save').disabled = true; $('company-message').textContent = '';
        try {
            company = await api('/empresa', 'POST', new FormData(event.currentTarget));
            applyBrand(company); fillCompany(); status('Nombre, logo y colores guardados.');
        } catch (error) { $('company-message').textContent = error.message; }
        finally { $('company-save').disabled = false; }
    });
    window.addEventListener('beforeunload', event => {
        if (companyDirty || permissionsDirty) { event.preventDefault(); event.returnValue = ''; }
    });

    function leavePermissions() {
        if (permissionsDirty && !window.confirm('Tienes permisos sin guardar. ¿Quieres descartar los cambios?')) { return false; }
        permissionsDirty = false; return true;
    }

    function renderPermissions() {
        const creating = permissionRoleId === null && roleDraft !== null;
        const role = creating ? roleDraft : roles.find(item => String(item.id) === String(permissionRoleId));
        if (!role) { return; }
        const protectedRole = !!role.permisos.todo || role.nombre === 'Administrador';
        $('new-role-fields').hidden = !creating;
        $('role-name').required = creating;
        $('permission-save').textContent = creating ? 'Crear rol' : 'Guardar permisos';
        $('permission-cancel').textContent = creating ? 'Cancelar creación' : 'Descartar cambios';
        $('permission-role-title').textContent = creating ? 'Crear rol personalizado' : 'Permisos de ' + role.nombre;
        $('permission-scope').textContent = creating ? 'Después de crearlo, podrás asignarlo a una cuenta desde Usuarios y acceso.' : 'Los cambios se aplican a todas las cuentas con el rol ' + role.nombre + ', incluso si ya iniciaron sesión.';
        $('permission-admin-note').hidden = !protectedRole;
        $('permission-save').disabled = protectedRole;
        $('permission-cancel').disabled = protectedRole;
        $('permission-options').replaceChildren();
        const groups = new Map();
        permissionCatalog.forEach(permission => {
            if (!groups.has(permission.grupo)) {
                const fieldset = document.createElement('fieldset');
                const legend = document.createElement('legend'); legend.textContent = permission.grupo;
                fieldset.append(legend); groups.set(permission.grupo, fieldset);
                $('permission-options').append(fieldset);
            }
            const label = document.createElement('label'); label.className = 'permission-option';
            const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.name = permission.clave;
            checkbox.checked = protectedRole || !!role.permisos[permission.clave]; checkbox.disabled = protectedRole;
            const text = document.createElement('span'); text.textContent = permission.nombre;
            if (!permission.disponible) {
                const tag = document.createElement('small'); tag.textContent = 'Próximamente'; text.append(tag);
            }
            checkbox.addEventListener('change', () => {
                if (checkbox.name === 'vender' && !checkbox.checked) {
                    ['dar_descuento', 'cambiar_precio', 'cancelar_venta','hacer_devoluciones'].forEach(key => {
                        $('permissions-form').elements.namedItem(key).checked = false;
                    });
                }
                if(checkbox.name==='ver_clientes'&&!checkbox.checked)$('permissions-form').elements.namedItem('editar_clientes').checked=false;
                if(checkbox.name==='editar_clientes'&&checkbox.checked)$('permissions-form').elements.namedItem('ver_clientes').checked=true;
                if (checkbox.name === 'ver_productos' && !checkbox.checked) { $('permissions-form').elements.namedItem('editar_productos').checked = false; }
                if (checkbox.name === 'editar_productos' && checkbox.checked) { $('permissions-form').elements.namedItem('ver_productos').checked = true; }
                if (checkbox.name === 'ver_inventario' && !checkbox.checked) { $('permissions-form').elements.namedItem('ajustar_inventario').checked = false; }
                if (checkbox.name === 'ver_inventario' && !checkbox.checked) { ['administrar_ubicaciones','realizar_traspasos','registrar_mermas','ver_alertas_inventario','configurar_minimos'].forEach(key => { $('permissions-form').elements.namedItem(key).checked = false; }); }
                if (['administrar_ubicaciones','realizar_traspasos','registrar_mermas'].includes(checkbox.name) && checkbox.checked) { $('permissions-form').elements.namedItem('ver_inventario').checked = true; }
                if(['ver_alertas_inventario','configurar_minimos'].includes(checkbox.name)&&checkbox.checked)$('permissions-form').elements.namedItem('ver_inventario').checked=true;
                if(checkbox.name==='configurar_minimos'&&checkbox.checked)$('permissions-form').elements.namedItem('ver_alertas_inventario').checked=true;
                if(checkbox.name==='ver_alertas_inventario'&&!checkbox.checked)$('permissions-form').elements.namedItem('configurar_minimos').checked=false;
                if (checkbox.name === 'ajustar_inventario' && checkbox.checked) { $('permissions-form').elements.namedItem('ver_inventario').checked = true; }
                if(checkbox.name==='operar_caja'&&!checkbox.checked){['vender_credito','cobrar_abonos','supervisar_caja','administrar_cajas','cancelar_venta','hacer_devoluciones'].forEach(key=>{$('permissions-form').elements.namedItem(key).checked=false;});}
                if(['supervisar_caja','administrar_cajas'].includes(checkbox.name)&&checkbox.checked){$('permissions-form').elements.namedItem('operar_caja').checked=true;}
                if(checkbox.name==='registrar_gastos'&&checkbox.checked)$('permissions-form').elements.namedItem('ver_gastos').checked=true;
                if(checkbox.name==='ver_gastos'&&!checkbox.checked)$('permissions-form').elements.namedItem('registrar_gastos').checked=false;
                if(checkbox.name==='pagar_proveedores'&&checkbox.checked)$('permissions-form').elements.namedItem('ver_cuentas_pagar').checked=true;
                if(checkbox.name==='ver_cuentas_pagar'&&!checkbox.checked)$('permissions-form').elements.namedItem('pagar_proveedores').checked=false;
                if(checkbox.name==='vender_credito'&&checkbox.checked){$('permissions-form').elements.namedItem('vender').checked=true;$('permissions-form').elements.namedItem('operar_caja').checked=true;}
                if(checkbox.name==='cobrar_abonos'&&checkbox.checked){$('permissions-form').elements.namedItem('ver_creditos').checked=true;$('permissions-form').elements.namedItem('operar_caja').checked=true;}
                if(checkbox.name==='ver_creditos'&&!checkbox.checked)$('permissions-form').elements.namedItem('cobrar_abonos').checked=false;
                if(checkbox.name==='vender'&&!checkbox.checked)$('permissions-form').elements.namedItem('vender_credito').checked=false;
                if(['cancelar_venta','hacer_devoluciones'].includes(checkbox.name)&&checkbox.checked){$('permissions-form').elements.namedItem('vender').checked=true;$('permissions-form').elements.namedItem('operar_caja').checked=true;}
                if (['dar_descuento', 'cambiar_precio', 'cancelar_venta'].includes(checkbox.name) && checkbox.checked) {
                    $('permissions-form').elements.namedItem('vender').checked = true;
                }
                permissionsDirty = true; $('permission-dirty').textContent = 'Tienes cambios sin guardar.';
            });
            label.append(checkbox, text); groups.get(permission.grupo).append(label);
        });
        permissionsDirty = creating;
        $('permission-dirty').textContent = creating ? 'El rol aún no se ha creado.' : (protectedRole ? '' : 'Todos los cambios están guardados.');
        $('permission-message').textContent = '';
    }

    function syncUserRoles() {
        const selected = $('user-role').value;
        $('user-role').replaceChildren();
        roles.forEach(role => {
            const option = document.createElement('option'); option.value = role.id; option.textContent = role.nombre;
            $('user-role').append(option);
        });
        if (roles.some(role => String(role.id) === selected)) { $('user-role').value = selected; }
    }

    function syncPermissionRoles() {
        $('permission-role').replaceChildren();
        roles.forEach(role => {
            const option = document.createElement('option'); option.value = role.id; option.textContent = role.nombre;
            $('permission-role').append(option);
        });
        $('permission-role').value = permissionRoleId;
    }

    $('new-role').addEventListener('click', () => {
        if (!leavePermissions()) { return; }
        roleDraft = {nombre: '', permisos: Object.fromEntries(permissionCatalog.map(item => [item.clave, false]))};
        permissionRoleId = null;
        $('role-name').value = '';
        $('role-template').replaceChildren();
        const blank = document.createElement('option'); blank.value = ''; blank.textContent = 'Sin permisos';
        $('role-template').append(blank);
        roles.filter(role => !role.permisos.todo && role.nombre !== 'Administrador').forEach(role => {
            const option = document.createElement('option'); option.value = role.id; option.textContent = role.nombre;
            $('role-template').append(option);
        });
        syncPermissionRoles();
        const draftOption = document.createElement('option'); draftOption.value = ''; draftOption.textContent = 'Nuevo rol (sin guardar)';
        $('permission-role').prepend(draftOption); $('permission-role').value = '';
        renderPermissions(); status(''); $('role-name').focus();
    });
    $('role-name').addEventListener('input', () => { permissionsDirty = true; });
    $('role-template').addEventListener('change', () => {
        const source = roles.find(role => String(role.id) === $('role-template').value);
        roleDraft.permisos = Object.fromEntries(permissionCatalog.map(item => [item.clave, !!(source && source.permisos[item.clave])]));
        renderPermissions();
    });

    $('nav-permissions').addEventListener('click', async () => {
        if(window.PuntoVenta.leaveBackups&&!window.PuntoVenta.leaveBackups()){return false;}if(window.PuntoVenta.leaveExpenses&&!window.PuntoVenta.leaveExpenses()){return;}if(window.PuntoVenta.leavePayables&&!window.PuntoVenta.leavePayables()){return;}if(window.PuntoVenta.leaveCredits&&!window.PuntoVenta.leaveCredits()){return;}if(window.PuntoVenta.leaveClients&&!window.PuntoVenta.leaveClients()){return;}$('clients-view').hidden=true;$('reports-view').hidden=true;$('credits-view').hidden=true;$('payables-view').hidden=true;$('expenses-view').hidden=true;$('backups-view').hidden=true;$('home-view').hidden=true;$('audit-view').hidden=true;$('nav-audit').removeAttribute('aria-current');$('nav-home').removeAttribute('aria-current');$('nav-backups').removeAttribute('aria-current');$('nav-expenses').removeAttribute('aria-current');$('nav-payables').removeAttribute('aria-current');$('nav-credits').removeAttribute('aria-current');$('nav-reports').removeAttribute('aria-current');$('nav-clients').removeAttribute('aria-current');
        if(window.PuntoVenta.leaveSales&&!window.PuntoVenta.leaveSales()){return;}$('sales-view').hidden=true;$('nav-sales').removeAttribute('aria-current');
        if(window.PuntoVenta.leaveCash&&!window.PuntoVenta.leaveCash()){return;}
        $('cash-view').hidden=true;$('nav-cash').removeAttribute('aria-current');
        if (window.PuntoVenta.leavePurchases && !window.PuntoVenta.leavePurchases()) { return; }
        $('purchases-view').hidden = true; $('nav-purchases').removeAttribute('aria-current');
        if (window.PuntoVenta.leaveInventory && !window.PuntoVenta.leaveInventory()) { return; }
        $('inventory-view').hidden = true; $('nav-inventory').removeAttribute('aria-current');
        if (window.PuntoVenta.leaveCatalog && !window.PuntoVenta.leaveCatalog()) { return; }
        $('products-view').hidden = true; $('nav-products').removeAttribute('aria-current');
        if (!$('permissions-view').hidden) { return; }
        if (companyDirty && !window.confirm('Tienes cambios sin guardar en Mi negocio. ¿Quieres descartarlos?')) { return; }
        companyDirty = false;
        try {
            const [updatedRoles, catalog] = await Promise.all([api('/roles'), api('/permisos')]);
            roles = updatedRoles; permissionCatalog = catalog;
            roleDraft = null;
            const editable = roles.find(role => !role.permisos.todo && role.nombre !== 'Administrador');
            permissionRoleId = editable ? editable.id : roles[0].id;
            syncPermissionRoles();
            renderPermissions();
            $('users-view').hidden = true; $('company-view').hidden = true; $('permissions-view').hidden = false;
            $('nav-users').removeAttribute('aria-current'); $('nav-company').removeAttribute('aria-current');
            $('nav-permissions').setAttribute('aria-current', 'page'); status('');
        } catch (error) { status(error.message, true); }
    });
    $('permission-role').addEventListener('change', () => {
        if (!leavePermissions()) { $('permission-role').value = permissionRoleId; return; }
        const selected = $('permission-role').value;
        if (selected === '' && roleDraft) { permissionRoleId = null; renderPermissions(); return; }
        permissionRoleId = selected; roleDraft = null; syncPermissionRoles(); renderPermissions();
    });
    $('permission-cancel').addEventListener('click', () => {
        if (roleDraft) {
            roleDraft = null;
            const editable = roles.find(role => !role.permisos.todo && role.nombre !== 'Administrador');
            permissionRoleId = editable ? editable.id : roles[0].id; syncPermissionRoles();
        }
        renderPermissions();
    });
    $('permissions-form').addEventListener('submit', async event => {
        event.preventDefault(); $('permission-save').disabled = true; $('permission-message').textContent = '';
        const permissions = {};
        permissionCatalog.forEach(permission => { permissions[permission.clave] = event.currentTarget.elements.namedItem(permission.clave).checked; });
        try {
            const creating = permissionRoleId === null && roleDraft !== null;
            const payload = {permisos: permissions};
            if (creating) { payload.nombre = $('role-name').value.trim(); }
            const role = await api(creating ? '/roles' : '/roles/' + permissionRoleId + '/permisos', creating ? 'POST' : 'PUT', payload);
            roles = creating ? [...roles, role] : roles.map(item => String(item.id) === String(role.id) ? role : item);
            permissionRoleId = role.id; roleDraft = null; syncPermissionRoles(); syncUserRoles();
            renderPermissions(); roleDescription();
            status(creating ? 'Rol ' + role.nombre + ' creado. Ya puedes asignarlo desde Usuarios y acceso.' : 'Permisos guardados. Se aplican a todas las cuentas de ' + role.nombre + '.');
        } catch (error) { $('permission-message').textContent = error.message; }
        finally { $('permission-save').disabled = false; }
    });
    window.PuntoVenta = {
        api, status, current: () => current,
        apiBytes: async(path,body,offset)=>{const response=await fetch(base+path,{method:'POST',credentials:'same-origin',headers:{'X-CSRF-Token':csrf,'X-Backup-Offset':String(offset),'Content-Type':'application/octet-stream'},body});const result=await response.json();if(!response.ok||!result.success)throw new Error(result.message||'No se pudo cargar el archivo.');return result.data;},
        openCatalog: () => {
            if(window.PuntoVenta.leaveConfiguration&&!window.PuntoVenta.leaveConfiguration()){return false;}
            if(window.PuntoVenta.leaveBackups&&!window.PuntoVenta.leaveBackups()){return false;}if(window.PuntoVenta.leaveExpenses&&!window.PuntoVenta.leaveExpenses()){return false;}if(window.PuntoVenta.leavePayables&&!window.PuntoVenta.leavePayables()){return false;}if(window.PuntoVenta.leaveCredits&&!window.PuntoVenta.leaveCredits()){return false;}if(window.PuntoVenta.leaveClients&&!window.PuntoVenta.leaveClients()){return false;}
            if(window.PuntoVenta.leaveSales&&!window.PuntoVenta.leaveSales()){return false;}
            if(window.PuntoVenta.leaveCash&&!window.PuntoVenta.leaveCash()){return false;}
            if (window.PuntoVenta.leavePurchases && !window.PuntoVenta.leavePurchases()) { return false; }
            if (window.PuntoVenta.leaveInventory && !window.PuntoVenta.leaveInventory()) { return false; }
            if (!leavePermissions()) { return false; }
            if (companyDirty && !window.confirm('Tienes cambios sin guardar en Mi negocio. ¿Quieres descartarlos?')) { return false; }
            companyDirty = false;
            ['users-view','company-view','permissions-view','welcome-view','inventory-view','purchases-view','cash-view','sales-view','clients-view','reports-view','credits-view','payables-view','expenses-view','backups-view','home-view','audit-view'].forEach(id => { $(id).hidden = true; });
            ['nav-users','nav-company','nav-permissions','nav-inventory','nav-purchases','nav-cash','nav-sales','nav-clients','nav-reports','nav-credits','nav-payables','nav-expenses','nav-backups','nav-home','nav-audit'].forEach(id => $(id).removeAttribute('aria-current'));
            $('products-view').hidden = false; $('nav-products').setAttribute('aria-current','page');
            return true;
        }
    };
    init();
})();
