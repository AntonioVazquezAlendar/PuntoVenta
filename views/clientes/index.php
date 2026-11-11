<section id="clients-view" hidden aria-labelledby="clients-title">
    <div class="section-heading"><div><h1 id="clients-title">Clientes</h1><p>Datos de contacto y compras de tus clientes.</p></div><button id="client-new" class="primary" type="button" hidden>Crear cliente</button></div>
    <p id="clients-message" role="status" aria-live="polite"></p>
    <div id="clients-list-panel">
        <div class="filters"><div><label for="clients-search">Buscar cliente</label><input id="clients-search" type="search" maxlength="150" placeholder="Nombre, teléfono o correo"></div><div><label for="clients-state">Estado</label><select id="clients-state"><option value="1">Activos</option><option value="">Todos</option><option value="0">Inactivos</option></select></div></div>
        <div class="table-scroll clients-table"><table><thead><tr><th>Cliente</th><th>Contacto</th><th>Estado</th><th><span class="sr-only">Acciones</span></th></tr></thead><tbody id="clients-rows"></tbody></table></div>
        <p id="clients-empty" class="hint" hidden>No hay clientes que coincidan. Cambia la búsqueda o crea un cliente.</p><div class="form-actions"><button id="clients-more" class="secondary" type="button" hidden>Cargar más clientes</button></div>
    </div>
    <section id="client-editor" class="client-editor editor" hidden aria-labelledby="client-editor-title">
        <div class="section-heading"><h2 id="client-editor-title">Crear cliente</h2><button id="client-editor-back" class="secondary" type="button">Volver</button></div>
        <form id="client-form"><fieldset id="client-fields" class="adjust-fields"><div class="client-field-grid">
            <div class="full-width"><label for="client-name">Nombre del cliente</label><input id="client-name" name="nombre" required maxlength="200" autocomplete="name"></div>
            <div><label for="client-phone">Teléfono</label><input id="client-phone" name="telefono" type="tel" maxlength="30" autocomplete="tel"></div><div><label for="client-email">Correo electrónico</label><input id="client-email" name="correo" type="email" maxlength="150" autocomplete="email"></div>
            <div class="full-width"><label for="client-address">Dirección</label><textarea id="client-address" name="direccion" rows="2" maxlength="300" autocomplete="street-address"></textarea></div>
            <div class="full-width"><label for="client-notes">Notas</label><textarea id="client-notes" name="notas" rows="3" maxlength="500"></textarea></div>
            <div><label for="client-active">Estado del cliente</label><select id="client-active" name="activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
        <div class="full-width"><label class="check-label"><input id="client-default" type="checkbox"> Usar como cliente predeterminado para nuevas ventas</label><p class="hint">Solo puede haber uno para el negocio. Al elegir este cliente, reemplazará al predeterminado anterior.</p></div></div><p class="hint">Al desactivar un cliente se conserva su historial y deja de aparecer al vender.</p></fieldset><p id="client-error" class="message" role="alert"></p><div class="form-actions"><button id="client-save" class="primary" type="submit">Guardar cliente</button><button id="client-cancel" class="secondary" type="button">Cancelar</button></div></form>
    </section>
    <section id="client-detail" hidden aria-labelledby="client-detail-title">
        <div class="section-heading"><div><h2 id="client-detail-title"></h2><p id="client-detail-state" class="hint"></p></div><div class="form-actions catalog-actions"><button id="client-edit" class="secondary" type="button" hidden>Editar cliente</button><button id="client-detail-back" class="secondary" type="button">Volver a clientes</button></div></div>
        <dl id="client-contact" class="client-contact"></dl>
        <div class="client-history-heading"><h2>Historial de compras</h2><p id="client-history-summary"></p></div><p id="client-history-scope" class="hint"></p>
        <div class="table-scroll"><table class="client-history-table"><thead><tr><th>Ticket / fecha</th><th>Cajero</th><th>Estado</th><th>Total</th></tr></thead><tbody id="client-history-rows"></tbody></table></div><p id="client-history-empty" class="hint" hidden>No hay compras registradas en el historial que puedes consultar.</p><div class="form-actions"><button id="client-history-more" class="secondary" type="button" hidden>Cargar más compras</button></div>
    </section>
</section>
