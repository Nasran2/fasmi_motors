const cents = value => Math.round((Number(value) || 0) * 100);
const money = value => (value / 100).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
function refreshFunding(panel, force = false) {
    const form = panel.closest('form');
    if (!form) return;
    const fields = name => form.elements.namedItem(name);
    const total = cents((Number(fields(panel.dataset.amountName)?.value) || 0) * (panel.dataset.quantityName ? (Number(fields(panel.dataset.quantityName)?.value) || 0) : 1));
    const rows = [...panel.querySelectorAll('[data-funding-row]')];
    const method = fields('payment_method')?.value || 'Cash';
    for (const [index, row] of rows.entries()) {
        const source = row.querySelector('[data-funding-source]');
        const amount = row.querySelector('[data-funding-amount]');
        source.name = `funding[${index}][source]`;
        amount.name = `funding[${index}][amount]`;
        for (const option of source.options) option.disabled = option.dataset.unavailable === '1' || (method !== 'Cash' && option.value !== 'bank');
        if (method !== 'Cash' && source.value !== 'bank') source.value = 'bank';
        if (source.selectedOptions[0]?.disabled) source.value = '';
        if (rows.length === 1 && (force || !amount.dataset.edited)) amount.value = (total / 100).toFixed(2);
        row.querySelector('[data-remove-funding]').hidden = rows.length === 1;
    }
    const first = (rows.find(row => cents(row.querySelector('[data-funding-amount]').value) > 0) || rows[0])?.querySelector('[data-funding-source]');
    panel.querySelector('[data-funding-account]').value = first?.selectedOptions[0]?.dataset.account || '';
    const allocated = rows.reduce((sum, row) => sum + cents(row.querySelector('[data-funding-amount]').value), 0);
    const label = panel.querySelector('[data-funding-total]');
    label.textContent = `Allocated: Rs. ${money(allocated)} / Total: Rs. ${money(total)}${allocated !== total ? ' — amounts must match' : ''}`;
    label.classList.toggle('text-red-600', allocated !== total);
    panel.dispatchEvent(new CustomEvent('outgoing-funding-change', {bubbles:true, detail:rows.map(row => ({source:row.querySelector('[data-funding-source]').value, amount:row.querySelector('[data-funding-amount]').value}))}));
}
function initializeFunding(root = document) {
    root.querySelectorAll('[data-outgoing-funding]').forEach(panel => {
        if (panel.dataset.ready) return;
        panel.dataset.ready = '1';
        const form = panel.closest('form');
        let resetVersion = 0;
        form?.addEventListener('input', event => {
            if (event.target.matches('[data-funding-amount]')) event.target.dataset.edited = '1';
            refreshFunding(panel);
        });
        form?.addEventListener('change', () => refreshFunding(panel));
        form?.addEventListener('focusin', () => refreshFunding(panel));
        form?.addEventListener('submit', () => refreshFunding(panel), true);
        panel.addEventListener('click', event => {
            if (event.target.closest('[data-add-funding]')) {
                const rows = panel.querySelector('[data-funding-rows]');
                if (rows.children.length >= 5) return;
                const copy = rows.firstElementChild.cloneNode(true);
                copy.querySelector('[data-funding-amount]').value = '0.00';
                copy.querySelector('[data-funding-amount]').dataset.edited = '1';
                rows.append(copy);
                refreshFunding(panel);
            }
            if (event.target.closest('[data-remove-funding]')) {
                event.target.closest('[data-funding-row]').remove();
                refreshFunding(panel);
            }
        });
        panel.addEventListener('funding-reset', event => {
            resetVersion++;
            const container = panel.querySelector('[data-funding-rows]');
            const template = container.firstElementChild.cloneNode(true);
            const selectedAccount = panel.querySelector('[data-funding-account]').value;
            const defaultSource = event.detail === null
                ? ([...template.querySelector('[data-funding-source]').options].find(option => option.dataset.account === selectedAccount && !option.value.endsWith('_cashier'))?.value || panel.dataset.defaultSource)
                : panel.dataset.defaultSource;
            const allocations = event.detail?.length ? event.detail : [{source:defaultSource, amount:''}];
            container.replaceChildren(...allocations.map(allocation => {
                const row = template.cloneNode(true);
                row.querySelector('[data-funding-source]').value = allocation.source;
                const amount = row.querySelector('[data-funding-amount]');
                amount.value = allocation.amount;
                delete amount.dataset.edited;
                if (allocations.length > 1) amount.dataset.edited = '1';
                return row;
            }));
            refreshFunding(panel);
        });
        form?.addEventListener('reset', () => {
            const version = resetVersion;
            queueMicrotask(() => { if (version === resetVersion) panel.dispatchEvent(new CustomEvent('funding-reset')); });
        });
        refreshFunding(panel);
    });
}
initializeFunding();
new MutationObserver(() => initializeFunding()).observe(document.documentElement, { childList: true, subtree: true });
