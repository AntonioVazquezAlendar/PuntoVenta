'use strict';
(() => {
    const enhanced = new WeakSet();
    let printClosed = [];

    function enhance(wrapper) {
        if (enhanced.has(wrapper) || !wrapper.querySelector('table') ||
            wrapper.closest('.ticket-paper, #sales-ticket-panel, .pos-cart-scroll')) return;
        enhanced.add(wrapper);
        const table = wrapper.querySelector('table');
        const section = wrapper.closest('section[aria-labelledby]');
        const heading = section && document.getElementById(section.getAttribute('aria-labelledby'));
        const name = table.getAttribute('aria-label') || wrapper.getAttribute('aria-label') ||
            (heading && heading.textContent.trim()) || 'Listado';
        const details = document.createElement('details');
        details.className = 'collapsible-grid';
        details.open = true;
        const summary = document.createElement('summary');
        const title = document.createElement('span');
        title.className = 'grid-title';
        title.textContent = name;
        const action = document.createElement('span');
        action.className = 'grid-toggle-label';
        action.textContent = 'Contraer tabla';
        summary.append(title, action);
        wrapper.before(details);
        details.append(summary, wrapper);
        details.addEventListener('toggle', () => {
            action.textContent = details.open ? 'Contraer tabla' : 'Expandir tabla';
        });
    }

    document.querySelectorAll('.table-scroll').forEach(enhance);
    // Incluye listados que se crean al abrir un detalle o recuperar un ticket.
    new MutationObserver(records => {
        for (const record of records) for (const node of record.addedNodes) {
            if (node.nodeType !== 1) continue;
            if (node.matches('.table-scroll')) enhance(node);
            node.querySelectorAll('.table-scroll').forEach(enhance);
        }
    }).observe(document.querySelector('main'), {childList: true, subtree: true});

    document.addEventListener('invalid', event => {
        const details = event.target.closest('.collapsible-grid');
        if (details) details.open = true;
    }, true);
    // Las tablas contraídas siguen formando parte de la impresión del módulo.
    window.addEventListener('beforeprint', () => {
        printClosed = [...document.querySelectorAll('.collapsible-grid:not([open])')];
        printClosed.forEach(details => details.open = true);
    });
    window.addEventListener('afterprint', () => {
        printClosed.forEach(details => details.open = false);
        printClosed = [];
    });
})();
