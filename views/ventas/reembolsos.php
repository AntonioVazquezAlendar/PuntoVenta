<section id="refund-section" class="refund-section ticket-controls" hidden aria-labelledby="refund-title">
    <div class="section-heading"><div><h2 id="refund-title">Cancelaciones y devoluciones</h2><p id="refund-net" class="hint"></p></div><div class="form-actions catalog-actions"><button id="refund-start-return" data-icon="rotate-left" class="secondary" type="button" hidden>Devolver productos</button><button id="refund-start-cancel" data-icon="ban" class="secondary" type="button" hidden>Cancelar venta</button></div></div>
    <p id="refund-status" role="status" aria-live="polite"></p>
    <form id="refund-form" class="refund-form" hidden>
        <h3 id="refund-form-title"></h3><p id="refund-help" class="hint"></p>
        <fieldset id="refund-fields" class="adjust-fields">
            <label for="refund-turn">Caja que registra el reembolso</label><select id="refund-turn" required></select><p id="refund-location" class="hint"></p>
            <div class="table-scroll"><table class="refund-lines-table"><thead><tr><th>Producto</th><th>Pendiente</th><th>Devolver</th><th>Destino de la mercancía</th></tr></thead><tbody id="refund-lines"></tbody></table></div>
            <label for="refund-reason">Motivo</label><textarea id="refund-reason" maxlength="300" rows="2" required placeholder="Explica la cancelación o devolución"></textarea>
            <button id="refund-calculate" data-icon="money-bill-transfer" class="secondary" type="button">Calcular reembolso</button>
            <section id="refund-quote" class="refund-quote" hidden aria-labelledby="refund-quote-title"><h4 id="refund-quote-title">Revisa el reembolso</h4><dl id="refund-amounts"></dl><p id="refund-cash" class="hint"></p><p class="hint">El importe se calcula con los precios, descuentos e impuestos del ticket original. Los redondeos se completan al devolver todas las unidades.</p><div id="refund-payments"></div><p id="refund-payment-status" class="hint" role="status"></p><p class="hint">Registra la devolución del dinero que ya realizaste. El sistema no procesa reembolsos de tarjeta ni transferencias.</p><label class="check-label"><input id="refund-confirm" type="checkbox"> Confirmo la recepción de mercancía indicada y el reembolso realizado.</label></section>
        </fieldset><p id="refund-error" class="message" role="alert"></p><div class="form-actions"><button id="refund-save" data-icon="check" class="primary" type="submit" disabled>Registrar devolución</button><button id="refund-discard" data-icon="arrow-left" class="secondary" type="button">Volver al ticket</button></div>
    </form>
    <div id="refund-documents" class="refund-documents"></div><button id="refund-more" class="secondary" type="button" hidden>Cargar más registros</button>
</section>

<dialog id="refund-authorize-dialog" aria-labelledby="refund-authorize-title" class="refund-authorize-dialog">
    <form id="refund-authorize-form" autocomplete="off">
        <h2 id="refund-authorize-title">Autorización del administrador</h2><p>El administrador debe revisar esta operación e introducir sus datos para autorizarla. La sesión del cajero se conserva.</p>
        <div id="refund-authorize-summary" class="refund-authorization-summary"></div>
        <fieldset id="refund-authorize-fields" class="adjust-fields"><label for="refund-admin-user">Usuario del administrador</label><input id="refund-admin-user" required maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false"><label for="refund-admin-password">Contraseña del administrador</label><input id="refund-admin-password" type="password" required maxlength="72" autocomplete="off"></fieldset>
        <p id="refund-authorize-error" class="message" role="alert"></p><div class="form-actions"><button id="refund-authorize-save" data-icon="shield-halved" type="submit" class="primary">Autorizar y registrar</button><button id="refund-authorize-cancel" data-icon="arrow-left" type="button" class="secondary">Volver sin autorizar</button></div>
    </form>
</dialog>
