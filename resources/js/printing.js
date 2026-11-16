import documentStyles from '../css/print-document.css?raw';

const text = (node) => (node?.textContent || '').replace(/\s+/gu, ' ').trim();
const escape = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;'}[c]));
const blocked = new Set(['SCRIPT', 'STYLE', 'BUTTON', 'FORM', 'INPUT', 'SELECT', 'TEXTAREA', 'SVG', 'DIALOG', 'NAV']);
const configuration = () => JSON.parse(document.getElementById('workshop-print-config')?.textContent || '{}');
const displayed = (node) => !node.closest('dialog:not([open]), [hidden], [x-cloak]') && node.getClientRects().length > 0;

function copyContent(source, target, owner) {
    for (const node of source.childNodes) {
        if (node.nodeType === Node.TEXT_NODE) target.append(owner.createTextNode(node.textContent.replace(/[−–—‑]/gu, '-')));
        else if (node.nodeType === Node.ELEMENT_NODE && !blocked.has(node.tagName) && !node.classList.contains('print:hidden')) {
            const tag = ['STRONG', 'B', 'BR', 'SMALL', 'P', 'DIV', 'H2', 'H3', 'H4'].includes(node.tagName) ? node.tagName.toLowerCase() : 'span';
            const clean = owner.createElement(tag === 'div' ? 'p' : tag);
            if (node.classList.contains('text-xs')) clean.classList.add('muted');
            if (node.classList.contains('font-bold') || node.classList.contains('font-black')) clean.classList.add('strong');
            copyContent(node, clean, owner);
            target.append(clean);
        }
    }
}

export function printTable(source, selectedRow = null) {
    const owner = document.implementation.createHTMLDocument('');
    const table = owner.createElement('table');
    table.className = 'document-table';
    const headers = Array.from(source.tHead?.rows[0]?.cells || []);
    const excluded = new Set(headers.flatMap((cell, index) => /^(actions?|options?|manage)$/iu.test(text(cell)) ? [index] : []));
    if (selectedRow) {
        const body = owner.createElement('tbody');
        Array.from(selectedRow.cells).forEach((cell, index) => {
            if (excluded.has(index)) return;
            const row = body.insertRow();
            const label = owner.createElement('th');
            label.textContent = text(headers[index]) || 'Details';
            row.append(label);
            copyContent(cell, row.insertCell(), owner);
        });
        table.append(body);
        return table.outerHTML;
    }
    const labels = headers.filter((_, index) => !excluded.has(index)).map(text);
    if (labels.length > 7) table.classList.add('document-wide');
    const weights = labels.map((label) => /^(entry|#)$/iu.test(label) ? 0.6 : /description|details/iu.test(label) ? 4 : /customer|supplier|product|technician/iu.test(label) ? 2.6 : /reference|invoice no|number/iu.test(label) ? 2.2 : /date|recorded/iu.test(label) ? 1.7 : /amount|total|balance|cost|price|paid|held|due|salary|epf|etf|cash (in|out)/iu.test(label) ? 2 : 1.4);
    if (weights.length) {
        const columns = owner.createElement('colgroup');
        const total = weights.reduce((sum, weight) => sum + weight, 0);
        for (const weight of weights) {
            const column = owner.createElement('col');
            column.style.width = `${100 * weight / total}%`;
            columns.append(column);
        }
        table.append(columns);
    }
    for (const group of [...source.children].filter((node) => ['THEAD', 'TBODY', 'TFOOT'].includes(node.tagName))) {
        const output = owner.createElement(group.tagName === 'TFOOT' ? 'tbody' : group.tagName.toLowerCase());
        if (group.tagName === 'TFOOT') output.className = 'document-totals';
        for (const row of group.rows) {
            const cleanRow = owner.createElement('tr');
            Array.from(row.cells).forEach((cell, index) => {
                if (excluded.has(index)) return;
                const clean = owner.createElement(cell.tagName.toLowerCase());
                for (const name of ['colspan', 'rowspan']) if (cell.hasAttribute(name)) clean.setAttribute(name, String(Math.max(1, Math.min(100, Number(cell.getAttribute(name))))));
                if (cell.classList.contains('text-right') || /^(?:Rs\.\s*)?-?[\d,]+(?:\.\d+)?%?$/u.test(text(cell))) clean.className = 'number';
                copyContent(cell, clean, owner);
                cleanRow.append(clean);
            });
            output.append(cleanRow);
        }
        table.append(output);
    }
    return table.outerHTML;
}

export function collectPrintDocument(trigger = null, nativePrint = false) {
    const config = configuration();
    const modalSelector = 'dialog[open]:not(.workshop-sidebar, .workshop-search-dialog)';
    const modal = trigger?.closest(modalSelector) || document.querySelector(modalSelector);
    const visible = nativePrint ? (node) => !node.closest('dialog:not([open]), [hidden], [x-cloak], .hidden, [style*="display: none"]') : displayed;
    const source = modal || document.querySelector('[data-flux-main]') || document.querySelector('main') || document.body;
    const row = trigger?.closest('tr');
    const title = trigger?.dataset.printTitle || (modal ? text(modal.querySelector('h1,h2,h3')) : text(source.querySelector('h1'))) || config.title || 'Report';
    const summary = [...source.querySelectorAll('[data-print-summary]')].filter(visible).map((node) => ({label: text(node.children[0]), value: `${node.dataset.printCurrency ? node.dataset.printCurrency + ' ' : ''}${text(node.children[1])}`}));
    let tables = row ? [row.closest('table')] : [...source.querySelectorAll('table')].filter((table) => visible(table) && !table.parentElement.closest('table'));
    const reportTable = source.querySelector('#reportTable');
    if (!row && reportTable) tables = [reportTable];
    const maxColumns = row ? 2 : Math.max(0, ...tables.map((table) => table.tHead?.rows[0]?.cells.length || table.rows[0]?.cells.length || 0));
    const sections = tables.map((table) => {
        const heading = text(table.parentElement.querySelector(':scope > h2, :scope > h3, :scope > h4') || table.closest('section')?.querySelector('h2,h3')).replace(/^[^\p{L}\p{N}]+/u, '');
        return `${heading && heading !== title ? `<h2 class="document-section-title">${escape(heading)}</h2>` : ''}${printTable(table, row)}`;
    });
    if (!sections.length) {
        for (const node of source.querySelectorAll('[data-print-content]')) {
            const clean = document.createElement('section');
            copyContent(node, clean, document);
            sections.push(clean.outerHTML);
        }
    }
    if (!sections.length) sections.push('<p class="document-note">No printable records in the selected view.</p>');
    const filters = (row ? [] : [...source.querySelectorAll('form:not([method]) input, form:not([method]) select, form[method="GET" i] input, form[method="GET" i] select, [data-print-filter]')])
        .filter((input) => input.name && input.type !== 'hidden' && input.value)
        .map((input) => `${input.closest('label')?.querySelector('.label')?.textContent || input.name.replace(/_/g, ' ')}: ${input.tagName === 'SELECT' ? [...input.selectedOptions].map(text).join(', ') : input.value}`);
    const hasPages = !row && !!source.querySelector('nav[aria-label*="Pagination" i], nav[aria-label*="pagination" i]');
    return {...config, title, summary: row ? [] : summary, sections, filters: filters.join(' · '), scope: hasPages ? 'Displayed records on the current page. Use the module export for a complete filtered report.' : '', orientation: maxColumns > 7 ? 'landscape' : 'portrait'};
}

function bodyMarkup(data) {
    const chunkSize = data.summary.length === 6 ? 3 : 4;
    const rows = data.summary.length === 5 ? [data.summary.slice(0, 2), data.summary.slice(2)] : Array.from({length: Math.ceil(data.summary.length / chunkSize)}, (_, index) => data.summary.slice(index * chunkSize, index * chunkSize + chunkSize));
    const summary = rows.map((row) => `<table class="document-summary" role="presentation"><tbody><tr>${row.map((item) => `<td><div class="summary-label">${escape(item.label)}</div><div class="summary-value">${escape(item.value)}</div></td>`).join('')}</tr></tbody></table>`).join('');
    return `<main class="document" data-print-document><table class="document-header" role="presentation"><tbody><tr><td class="identity"><div class="business-name">${escape(data.business)}</div><div class="business-detail">${escape(data.shop)}</div><div class="business-detail">${escape(data.address)}</div><div class="business-detail">${escape(data.contact)}</div></td><td class="document-heading"><h1 class="document-title">${escape(data.title)}</h1><div class="document-meta">Generated: ${escape(data.generated)}</div><div class="document-meta">Prepared by: ${escape(data.preparedBy)}</div></td></tr></tbody></table>${data.filters ? `<p class="document-filters">${escape(data.filters)}</p>` : ''}${data.scope ? `<p class="document-note">${escape(data.scope)}</p>` : ''}${summary}${data.sections.join('')}<footer class="document-footer">${escape(data.business)} · ${escape(data.title)} · ${escape(data.generated)}</footer></main>`;
}

function styles(data) {
    return `${documentStyles}\n@page { size: A4 ${data.orientation}; @bottom-right { content: "Page " counter(page) " of " counter(pages); font: 7pt Arial; color:#647184; } }`;
}

window.workshopPrint = (event) => {
    const trigger = event?.currentTarget instanceof Element ? event.currentTarget : null;
    const config = configuration();
    if (config.reportUrl && !trigger?.closest('tr,dialog')) {
        window.open(config.reportUrl, '_blank', 'noopener');
        return;
    }
    const data = collectPrintDocument(trigger);
    const preview = window.open('', '_blank');
    if (!preview) {
        window.workshopNotify?.('Allow the print-preview window to open, then try again.');
        return;
    }
    preview.opener = null;
    preview.document.open();
    preview.document.write(`<!doctype html><html lang="en"><head><meta charset="utf-8"><title>${escape(data.title)} - ${escape(data.business)}</title><style>${styles(data)}</style></head><body><div class="document-toolbar"><span>Print preview · A4 ${data.orientation}</span><button class="primary" onclick="window.print()">Print / Save PDF</button></div>${bodyMarkup(data)}<script>window.addEventListener('load',async()=>{if(document.fonts)await document.fonts.ready;${data.autoPrint === false ? '' : 'window.print();'}},{once:true});<\/script></body></html>`);
    preview.document.close();
};

// Browser-menu Print / Ctrl+P also receives a document layout, rather than the application shell.
window.addEventListener('beforeprint', () => {
    if (!document.body.classList.contains('workshop-shell')) return;
    const data = collectPrintDocument(null, true);
    document.getElementById('workshop-print-root')?.remove();
    const root = document.createElement('section');
    root.id = 'workshop-print-root';
    const style = document.createElement('style');
    style.media = 'print';
    style.textContent = styles(data);
    root.append(style);
    const content = document.createElement('div');
    content.innerHTML = bodyMarkup(data);
    root.append(content);
    document.body.append(root);
});
