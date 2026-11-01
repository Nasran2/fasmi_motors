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
        source.disabled = ['loading', 'error'].includes(panel.dataset.fundingDateState);
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
        let dateVersion = 0;
        let dateAbort;
        let dateTimer;
        let requestedDate;
        let loadedDate;
        const dateAware = Boolean(panel.dataset.optionsUrl && panel.dataset.dateName);
        const dateValue = () => (form?.elements.namedItem(panel.dataset.dateName)?.value || '').slice(0, 10);
        async function updateFundingDate(force = false) {
            if (!dateAware) return;
            const date = dateValue();
            if (!force && date === requestedDate) return;
            requestedDate = date;
            const version = ++dateVersion;
            dateAbort?.abort();
            const status = panel.querySelector('[data-funding-date-status]');
            panel.dataset.fundingDateState = 'loading';
            status.textContent = 'Loading balances and cashier status for the selected date…';
            refreshFunding(panel);
            if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) {
                panel.dataset.fundingDateState = 'error';
                status.textContent = 'Select a valid Transfer Date to load payment sources.';
                refreshFunding(panel);
                return;
            }
            const controller = new AbortController();
            dateAbort = controller;
            try {
                const url = new URL(panel.dataset.optionsUrl, window.location.href);
                url.searchParams.set('date', date);
                const response = await fetch(url, {headers: {Accept: 'application/json'}, cache: 'no-store', signal: controller.signal});
                const data = await response.json();
                if (version !== dateVersion) return;
                if (!response.ok || !Array.isArray(data.options)) throw new Error(Object.values(data.errors || {}).flat().join(' ') || 'Could not load funding balances.');
                panel.querySelectorAll('[data-funding-source]').forEach(select => {
                    const value = select.value;
                    select.replaceChildren(new Option('Select payment source', ''), ...data.options.map(item => {
                        const option = new Option(`${item.label} · Rs. ${money(cents(item.balance))}${item.disabled ? ' · ' + (item.disabled_reason || 'Cashier closed') : ''}`, item.value);
                        option.dataset.account = item.account_id;
                        option.dataset.cashier = item.value.endsWith('_cashier') ? '1' : '0';
                        option.dataset.unavailable = item.disabled ? '1' : '0';
                        option.classList.toggle('bg-slate-200', item.disabled);
                        option.classList.toggle('text-slate-400', item.disabled);
                        return option;
                    }));
                    select.value = value;
                });
                loadedDate = date;
                panel.dataset.fundingDateState = 'ready';
                status.textContent = data.message;
            } catch (error) {
                if (version !== dateVersion || error.name === 'AbortError') return;
                panel.dataset.fundingDateState = 'error';
                status.textContent = (error.message || 'Could not load funding balances.') + ' Use Refresh balances to retry.';
            }
            refreshFunding(panel);
        }
        const dateChanged = event => {
            if (dateAware && event.target.name === panel.dataset.dateName) {
                clearTimeout(dateTimer);
                // Invalidate pending responses immediately; only the latest date may populate options.
                dateVersion++;
                dateAbort?.abort();
                requestedDate = undefined;
                panel.dataset.fundingDateState = 'loading';
                refreshFunding(panel);
                dateTimer = setTimeout(() => updateFundingDate(), 150);
            }
        };
        form?.addEventListener('input', event => {
            if (event.target.matches('[data-funding-amount]')) event.target.dataset.edited = '1';
            dateChanged(event);
            refreshFunding(panel);
        });
        form?.addEventListener('change', event => { dateChanged(event); refreshFunding(panel); });
        form?.addEventListener('focusin', () => refreshFunding(panel));
        form?.addEventListener('submit', event => {
            if (dateAware && (panel.dataset.fundingDateState !== 'ready' || loadedDate !== dateValue())) {
                event.preventDefault();
                updateFundingDate(true);
            }
            refreshFunding(panel);
        }, true);
        panel.addEventListener('click', event => {
            if (event.target.closest('[data-refresh-funding]')) updateFundingDate(true);
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
            queueMicrotask(() => {
                if (version === resetVersion) panel.dispatchEvent(new CustomEvent('funding-reset'));
                updateFundingDate(true);
            });
        });
        refreshFunding(panel);
        updateFundingDate();
    });
}
initializeFunding();
new MutationObserver(() => initializeFunding()).observe(document.documentElement, { childList: true, subtree: true });
