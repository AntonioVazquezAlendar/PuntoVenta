<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios y acceso · PuntoVenta</title>
    <link rel="stylesheet" href="/PuntoVenta/public/assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../../public/assets/css/admin.css') ?>">
    <link id="brand-styles" rel="stylesheet" href="/PuntoVenta/public/api/empresa/tema.css">
    <script src="/PuntoVenta/public/assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/admin.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/configuracion.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/configuracion.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/catalogo.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/catalogo.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/inventario.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/inventario.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/alertas-inventario.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/alertas-inventario.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/compras.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/compras.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/caja.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/caja.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/clientes.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/clientes.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/reembolsos.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/reembolsos.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/gastos.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/gastos.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/cuentas-pagar.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/cuentas-pagar.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/creditos.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/creditos.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/reportes.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/reportes.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/respaldos.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/respaldos.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/inicio.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/inicio.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/bitacora.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/bitacora.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/pos-borradores.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/pos-borradores.js') ?>" defer></script>
    <script src="/PuntoVenta/public/assets/js/ventas.js?v=<?= filemtime(__DIR__ . '/../../public/assets/js/ventas.js') ?>" defer></script>
</head>
<body>
<header class="topbar">
    <button id="menu-toggle" data-icon="bars" class="secondary" type="button" aria-controls="app-sidebar" aria-expanded="false" hidden>Menú</button>
    <a class="brand" href="/PuntoVenta/public/"><img id="brand-logo" alt="" hidden><span id="brand-name">PuntoVenta</span></a>
    <div id="session-bar" hidden><span id="session-name"></span><button id="logout" data-icon="right-from-bracket" class="secondary" type="button">Cerrar sesión</button></div>
</header>
<aside id="app-sidebar" class="app-sidebar" hidden aria-label="Menú principal"><div class="sidebar-heading"><h2>Menú principal</h2><button id="menu-close" data-icon="xmark" class="secondary" type="button">Cerrar menú</button></div>
    <nav id="admin-nav" class="admin-nav" aria-label="Secciones del sistema" hidden><button id="nav-home" data-icon="store" type="button" hidden>Inicio</button><button id="nav-sales" data-icon="cash-register" type="button" hidden>Punto de venta</button><button id="nav-products" data-icon="box" type="button" hidden>Productos</button><button id="nav-inventory" data-icon="boxes-stacked" type="button" hidden>Inventario</button><button id="nav-purchases" data-icon="cart-shopping" type="button" hidden>Compras</button><button id="nav-cash" data-icon="wallet" type="button" hidden>Caja</button><button id="nav-clients" data-icon="users" type="button" hidden>Clientes</button><button id="nav-expenses" data-icon="money-bill-transfer" type="button" hidden>Gastos del negocio</button><button id="nav-payables" data-icon="cart-shopping" type="button" hidden>Cuentas por pagar</button><button id="nav-credits" data-icon="money-bill-transfer" type="button" hidden>Crédito y abonos</button><button id="nav-reports" data-icon="receipt" type="button" hidden>Reportes de ventas</button><button id="nav-users" data-icon="user-shield" type="button" aria-current="page">Usuarios y acceso</button><button id="nav-permissions" data-icon="shield-halved" type="button">Roles y permisos</button><button id="nav-company" data-icon="store" type="button">Configuración administrativa</button><button id="nav-backups" data-icon="floppy-disk" type="button" hidden>Respaldos</button><button id="nav-audit" data-icon="receipt" type="button" hidden>Bitácora de actividad</button></nav>
</aside>
<button id="menu-backdrop" class="menu-backdrop" type="button" aria-label="Cerrar menú lateral" hidden></button>
<main>
    <p id="global-status" role="status" aria-live="polite">Conectando con el sistema…</p>

    <section id="access-view" class="access" hidden aria-labelledby="access-title">
        <div class="intro">
            <h1>Tu equipo.<br>El acceso correcto.</h1>
            <p>Un espacio para administrar quién entra a tu punto de venta y qué puede hacer.</p>
            <div class="role-notes"><h2>Un rol para cada tarea</h2>
                <p><strong>Administrador</strong>Gestiona usuarios y tiene acceso completo.</p>
                <p><strong>Gerente</strong>Cuenta con permisos de operación y supervisión.</p>
                <p><strong>Cajero</strong>Tiene permiso para vender.</p>
            </div>
        </div>
        <div class="access-form">
            <h2 id="access-title">Iniciar sesión</h2>
            <p id="access-description">Escribe los datos de tu cuenta.</p>
            <form id="access-form">
                <div id="setup-name-field" hidden><label for="setup-name">Tu nombre</label><input id="setup-name" name="nombre" maxlength="150" autocomplete="name"></div>
                <label for="login-name">Usuario</label>
                <input id="login-name" name="nombre_usuario" required maxlength="60" autocomplete="username" autocapitalize="none" spellcheck="false">
                <label for="login-password">Contraseña</label>
                <div class="password-field"><input id="login-password" name="contrasena" type="password" required autocomplete="current-password"><button type="button" class="reveal" data-password="login-password" aria-pressed="false">Mostrar</button></div>
                <p id="password-help" class="hint" hidden>Usa al menos 8 caracteres. Puedes escribir una frase larga.</p>
                <div id="setup-confirm-field" hidden><label for="setup-confirm">Confirma la contraseña</label><input id="setup-confirm" type="password" autocomplete="new-password"></div>
                <p id="access-message" class="message" role="alert"></p>
                <button id="access-submit" class="primary" type="submit">Entrar</button>
            </form>
            <p id="session-note" class="hint">La sesión se cierra tras 30 minutos de inactividad.</p>
        </div>
    </section>

    <section id="users-view" hidden aria-labelledby="users-title">
        <div class="section-heading"><div><h1 id="users-title">Usuarios y acceso</h1><p>Crea cuentas y asigna el rol que necesita cada persona.</p></div><button id="new-user" data-icon="user-plus" class="primary" type="button">Crear usuario</button></div>
        <div class="workspace">
            <section class="directory" aria-label="Directorio de usuarios">
                <div class="filters"><div><label for="search-users">Buscar usuario</label><input id="search-users" type="search" placeholder="Nombre o usuario"></div><div><label for="filter-status">Estado</label><select id="filter-status"><option value="">Todos</option><option value="1">Activos</option><option value="0">Inactivos</option></select></div></div>
                <p id="users-count" class="hint" aria-live="polite"></p>
                <div class="table-scroll"><table><thead><tr><th scope="col">Persona</th><th scope="col">Rol</th><th scope="col">Estado</th><th scope="col"><span class="sr-only">Acciones</span></th></tr></thead><tbody id="users-body"></tbody></table></div>
                <p id="users-empty" hidden>No hay usuarios que coincidan con tu búsqueda.</p>
            </section>
            <aside id="editor" class="editor" hidden aria-labelledby="editor-title">
                <h2 id="editor-title">Crear usuario</h2>
                <form id="user-form">
                    <label for="user-name">Nombre completo</label><input id="user-name" name="nombre" required maxlength="150" autocomplete="off">
                    <label for="user-login">Usuario</label><input id="user-login" name="nombre_usuario" required minlength="3" maxlength="60" pattern="[a-zA-Z0-9._\-]{3,60}" autocomplete="off" autocapitalize="none" spellcheck="false">
                    <p class="hint">Letras sin acentos, números, puntos y guiones. Sin espacios.</p>
                    <label for="user-role">Rol</label><select id="user-role" name="rol_id" required></select>
                    <p id="role-description" class="hint"></p>
                    <label for="user-password" id="user-password-label">Contraseña</label><div class="password-field"><input id="user-password" name="contrasena" type="password" autocomplete="new-password"><button type="button" class="reveal" data-password="user-password" aria-pressed="false">Mostrar</button></div>
                    <p id="edit-password-help" class="hint">Usa al menos 8 caracteres.</p>
                    <label for="user-active">Estado</label><select id="user-active" name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select>
                    <p class="hint">Una cuenta inactiva ya no puede entrar. Su historial se conserva.</p>
                    <p id="user-message" class="message" role="alert"></p>
                    <div class="form-actions"><button id="save-user" data-icon="floppy-disk" class="primary" type="submit">Crear usuario</button><button id="cancel-edit" class="secondary" type="button">Cancelar</button></div>
                </form>
            </aside>
        </div>
    </section>

    <section id="permissions-view" hidden aria-labelledby="permissions-title">
        <div class="section-heading"><div><h1 id="permissions-title">Roles y permisos</h1><p>Decide qué puede ver y hacer cada rol. Solo el administrador puede cambiar estos permisos.</p></div><button id="new-role" data-icon="plus" type="button" class="primary">Crear rol</button></div>
        <div class="permissions-layout">
            <aside class="role-summary"><h2>Selecciona un rol</h2><label for="permission-role">Rol a configurar</label><select id="permission-role"></select><p id="permission-scope" class="hint"></p><p class="hint">Usuarios, asignación de roles, permisos y configuración del negocio siempre son exclusivos del administrador.</p></aside>
            <form id="permissions-form" class="permissions-form"><h2 id="permission-role-title">Permisos del rol</h2><div id="new-role-fields" hidden><label for="role-name">Nombre del nuevo rol</label><input id="role-name" name="nombre" maxlength="60" placeholder="Ej. Almacenista" autocomplete="off"><label for="role-template">Comenzar con los permisos de</label><select id="role-template"><option value="">Sin permisos</option></select><p class="hint">Puedes copiar otro rol y ajustar sus casillas. El nuevo rol nunca tendrá facultades de administrador.</p></div><p id="permission-admin-note" hidden>El administrador tiene acceso completo. Este rol está protegido y no se puede restringir desde aquí.</p><p class="hint">Los permisos marcados como “Próximamente” quedan preparados para cuando se construya ese módulo.</p><div id="permission-options"></div><p id="permission-message" class="message" role="alert"></p><p id="permission-dirty" class="hint" aria-live="polite"></p><div class="form-actions"><button id="permission-save" class="primary" type="submit">Guardar permisos</button><button id="permission-cancel" class="secondary" type="button">Descartar cambios</button></div></form>
        </div>
    </section>
    <section id="company-view" hidden aria-labelledby="company-title">
        <div class="section-heading"><div><h1 id="company-title">Configuración administrativa</h1><p>Configura tu negocio, los tickets y las formas de pago. Solo el administrador puede hacer cambios.</p></div></div>
        <?php require __DIR__.'/../configuracion/datos.php'; ?>
        <section id="config-identidad" class="config-block" aria-labelledby="config-identidad-title"><h2 id="config-identidad-title">Logo y colores</h2>
        <div class="company-layout">
            <form id="company-form" class="company-form">
                <h2>Nombre y logo</h2>
                <label for="company-name">Nombre del negocio</label><input id="company-name" name="nombre_comercial" maxlength="150" required>
                <label for="company-logo">Logo del negocio</label><input id="company-logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-help">
                <p id="logo-help" class="hint">PNG, JPG o WebP. Máximo 2 MB y 4096 × 4096 píxeles. Un PNG transparente funciona bien.</p>
                <label class="check-label"><input id="company-remove-logo" name="quitar_logo" type="checkbox" value="1"> Quitar el logo actual</label>
                <h2 class="colors-title">Colores</h2>
                <p class="hint">Elige con el selector o escribe el código hexadecimal.</p>
                <div class="color-field"><label for="company-primary">Principal <small>Botones principales</small></label><input id="company-primary-picker" type="color" aria-label="Seleccionar color principal"><input id="company-primary" name="color_primario" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required aria-label="Código del color principal"></div>
                <div class="color-field"><label for="company-secondary">Secundario <small>Encabezado y pie de página</small></label><input id="company-secondary-picker" type="color" aria-label="Seleccionar color secundario"><input id="company-secondary" name="color_secundario" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required aria-label="Código del color secundario"></div>
                <div class="color-field"><label for="company-accent">Acento <small>Detalles de la interfaz</small></label><input id="company-accent-picker" type="color" aria-label="Seleccionar color de acento"><input id="company-accent" name="color_acento" pattern="#[0-9A-Fa-f]{6}" maxlength="7" required aria-label="Código del color de acento"></div>
                <p id="company-message" class="message" role="alert"></p>
                <div class="form-actions"><button id="company-save" data-icon="floppy-disk" type="submit" class="primary">Guardar cambios</button><button id="company-cancel" type="button" class="secondary">Descartar cambios</button></div>
            </form>
            <aside class="brand-preview" aria-labelledby="preview-title"><h2 id="preview-title">Vista previa</h2><p class="hint">Prueba los colores antes de guardar.</p><div class="preview-window"><div id="preview-header" class="preview-header"><img id="preview-logo" alt="" hidden><strong id="preview-name">Tu negocio</strong></div><div class="preview-content"><h3>Bienvenido a tu negocio</h3><p>Así se verá tu identidad en el sistema.</p><span id="preview-button" class="preview-button">Botón principal</span><div id="preview-detail" class="preview-detail"></div></div></div><p class="hint">El texto de los botones y del encabezado se ajusta automáticamente para conservar el contraste.</p><p id="company-dirty" class="hint" aria-live="polite">Todos los cambios están guardados.</p></aside>
        </div>
        </section>
        <?php require __DIR__.'/../configuracion/administracion.php'; ?>
    </section>
    <?php require __DIR__ . '/../productos/index.php'; ?>
    <?php require __DIR__ . '/../inventario/index.php'; ?>
    <?php require __DIR__ . '/../compras/index.php'; ?>
    <?php require __DIR__ . '/../caja/index.php'; ?>
    <?php require __DIR__ . '/../ventas/index.php'; ?>
    <?php require __DIR__ . '/../clientes/index.php'; ?>
    <?php require __DIR__ . '/../reportes/index.php'; ?>
    <?php require __DIR__ . '/../creditos/index.php'; ?>
    <?php require __DIR__ . '/../cuentas-pagar/index.php'; ?>
    <?php require __DIR__ . '/../gastos/index.php'; ?>
    <?php require __DIR__ . '/../respaldos/index.php'; ?>
    <?php require __DIR__ . '/../inicio/index.php'; ?>
    <?php require __DIR__ . '/../bitacora/index.php'; ?>
    <section id="welcome-view" class="welcome" hidden><h1 id="welcome-title">Sesión iniciada</h1><p id="welcome-role"></p><p>Tu cuenta está lista. Las secciones disponibles dependen de los permisos de tu rol.</p></section>
    <noscript>Activa JavaScript para iniciar sesión y administrar usuarios.</noscript>
</main>
<footer><span id="footer-brand">PuntoVenta</span><span>Administración</span></footer>
</body>
</html>
