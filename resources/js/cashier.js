export function cashierClosing(expected, initialBalance = '', initialWithdrawal = 0, initialBalances = {}, drawerExpected = null, autoFillCount = false, initialWithdrawalFunding = null, cashierFunds = null, allocationConfig = null) {
    const cents = value => Math.round((Number(value) || 0) * 100);
    const totals = {main:0, spare_parts:0, bank:0};
    if (initialWithdrawalFunding !== null) {
        for (const row of initialWithdrawalFunding) {
            const type = row.source.replace('_cashier', '');
            if (type in totals) totals[type] += cents(row.amount);
        }
    } else totals.spare_parts = cents(initialWithdrawal);
    const main = String(initialBalances.main ?? ((cents(expected.main) + cents(expected.washing) - totals.main) / 100));
    const spareParts = String(initialBalances.spare_parts ?? ((cents(expected.spare_parts) - totals.spare_parts) / 100));
    const totalBeforeSeparation = cents(expected.main) + cents(expected.washing) + cents(expected.spare_parts) - totals.main - totals.spare_parts;
    const pendingCashier = type => initialWithdrawalFunding === null
        ? (type === 'spare_parts' ? cents(initialWithdrawal) : 0)
        : initialWithdrawalFunding.filter(row => row.source === `${type}_cashier`).reduce((sum,row) => sum + cents(row.amount),0);
    const additions = Object.fromEntries(['main','spare_parts'].map(type => [type, String(allocationConfig?.additions?.[type] ?? Math.max(0, (cents(allocationConfig?.accounts?.[type]?.suggested_addition) - pendingCashier(type)) / 100))]));
    const firstCount = allocationConfig === null
        ? ((totalBeforeSeparation - cents(main) - cents(spareParts)) / 100).toFixed(2)
        : ((cents(cashierFunds?.total) - pendingCashier('main') - pendingCashier('spare_parts') - cents(additions.main) - cents(additions.spare_parts)) / 100).toFixed(2);
    return {
        expected,
        drawerExpected,
        cashierFunds,
        allocationConfig,
        dailyAdditionsMode: allocationConfig !== null,
        additions,
        savedAccountCorrections: {main:false, spare_parts:false},
        independent: drawerExpected !== null,
        withdrawal: initialWithdrawal,
        withdrawalFunding: initialWithdrawalFunding,
        closingBalance: autoFillCount && String(initialBalance).trim() === '' ? firstCount : initialBalance,
        balances: {
            bank: String(initialBalances.bank ?? ((cents(expected.bank) - totals.bank) / 100)),
            main,
            spare_parts: spareParts,
        },
        init() {
            this.$watch(this.dailyAdditionsMode ? 'additions.main' : 'balances.main', () => this.updateClosingBalance());
            this.$watch(this.dailyAdditionsMode ? 'additions.spare_parts' : 'balances.spare_parts', () => this.updateClosingBalance());
            if (this.dailyAdditionsMode) this.$watch('separationCashTotal', () => this.updateClosingBalance());
        },
        updateClosingBalance() {
            this.closingBalance = this.remainingCashTotal.toFixed(2);
        },
        cents(value) { return Math.round((Number(value) || 0) * 100); },
        money(value) { return Number(value).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        get withdrawalTotals() {
            const result = {main:0, spare_parts:0, bank:0};
            if (this.withdrawalFunding === null) result.spare_parts = this.cents(this.withdrawal);
            else for (const row of this.withdrawalFunding) {
                const type = row.source.replace('_cashier', '');
                if (type in result) result[type] += this.cents(row.amount);
            }
            return result;
        },
        get cashWithdrawal() {
            return this.withdrawalFunding === null ? Number(this.withdrawal) || 0 : this.withdrawalFunding.filter(row => row.source.endsWith('_cashier')).reduce((sum,row) => sum + this.cents(row.amount),0) / 100;
        },
        currentCashierAmount(type) {
            const pending = this.withdrawalFunding === null
                ? (type === 'spare_parts' ? this.cents(this.withdrawal) : 0)
                : this.withdrawalFunding.filter(row => row.source === `${type}_cashier`).reduce((sum,row) => sum + this.cents(row.amount),0);
            return (this.cents(this.cashierFunds?.[type]) - pending) / 100;
        },
        get currentCashInHand() {
            // Physical drawer cash includes washing receipts, credited to Main.
            // Carried account balances remain separate from the drawer.
            if (this.independent) return (this.cents(this.drawerExpected) - this.cents(this.cashWithdrawal)) / 100;
            return (this.cents(this.cashierFunds?.opening) + this.cents(this.currentCashierAmount('main')) + this.cents(this.currentCashierAmount('spare_parts'))) / 100;
        },
        pendingCashierWithdrawal(type, rows = this.withdrawalFunding) {
            return rows === null ? (type === 'spare_parts' ? this.cents(this.withdrawal) : 0)
                : rows.filter(row => row.source === `${type}_cashier`).reduce((sum,row) => sum + this.cents(row.amount),0);
        },
        accountAdditionBase(type) {
            return (this.cents(this.allocationConfig?.accounts?.[type]?.base) - this.withdrawalTotals[type] + this.pendingCashierWithdrawal(type)) / 100;
        },
        accountOtherMovements(type) {
            return (this.cents(this.accountAdditionBase(type)) - this.cents(this.allocationConfig?.accounts?.[type]?.opening)) / 100;
        },
        closingAccountBalance(type) {
            return (this.cents(this.accountAdditionBase(type)) + this.cents(this.additions[type])) / 100;
        },
        totalAddition(type) {
            const nonCash = this.cents(this.allocationConfig?.accounts?.[type]?.non_cash_income || 0);
            return (this.cents(this.additions[type]) + nonCash) / 100;
        },
        hasAdditionNote(type) {
            const meta = this.allocationConfig?.accounts?.[type] || {};
            return this.cents(meta.non_cash_income || 0) > 0;
        },
        additionNote(type) {
            const meta = this.allocationConfig?.accounts?.[type] || {};
            const nonCash = this.cents(meta.non_cash_income || 0) / 100;
            const cashAdded = this.cents(this.additions[type] || 0) / 100;
            if (nonCash > 0) {
                return `(Rs. ${this.money(cashAdded)} cash + Rs. ${this.money(nonCash)} bank transfer)`;
            }
            return '';
        },
        editAccountTotal(type, button) {
            const form = button.closest('form');
            const params = new URLSearchParams({account:type, account_addition:this.additions[type], owner_withdrawal:this.withdrawal || 0, balance_version:form.elements.namedItem('balance_version').value});
            if (this.cents(this.withdrawal) > 0) for (const [name, value] of new FormData(form)) {
                if (name.startsWith('funding[')) params.append(name, value);
            }
            openCashierAccountTotal(`${button.dataset.accountTotalUrl}?${params}`, form, button);
        },
        applyAccountTotal(data) {
            this.allocationConfig.accounts = data.allocation_accounts;
            this.expected = data.expected;
            this.cashierFunds = data.cashier_funds;
            this.drawerExpected = data.cash_in_hand;
            this.savedAccountCorrections[data.account] = true;
            this.$el.elements.namedItem('balance_version').value = data.balance_version;
            this.$el.querySelectorAll('[data-funding-source] option').forEach(option => {
                const source = data.funding_options.find(item => item.value === option.value);
                if (!source) return;
                option.dataset.unavailable = source.disabled ? '1' : '0';
                option.disabled = source.disabled;
                option.textContent = `${source.label} · Rs. ${this.money(source.balance)}${source.disabled ? ' · Cashier closed' : ''}`;
            });
        },
        expectedBalance(type) {
            return (this.cents(this.expected[type]) + (type === 'main' ? this.cents(this.expected.washing) : 0) - this.withdrawalTotals[type]) / 100;
        },
        updateWithdrawal(value) {
            if (this.withdrawalFunding === null) {
                const change = this.cents(value) - this.cents(this.withdrawal);
                this.balances.spare_parts = ((this.cents(this.balances.spare_parts) - change) / 100).toFixed(2);
            }
            this.withdrawal = value;
        },
        updateWithdrawalFunding(rows) {
            const previous = this.withdrawalTotals;
            const previousRows = this.withdrawalFunding;
            this.withdrawalFunding = rows;
            const next = this.withdrawalTotals;
            for (const type of ['main', 'spare_parts', 'bank']) {
                if (this.dailyAdditionsMode && type !== 'bank') {
                    const change = this.pendingCashierWithdrawal(type, rows) - this.pendingCashierWithdrawal(type, previousRows);
                    if (change) this.additions[type] = (Math.max(0, this.cents(this.additions[type]) - change) / 100).toFixed(2);
                } else if (next[type] !== previous[type]) this.balances[type] = ((this.cents(this.balances[type]) + previous[type] - next[type]) / 100).toFixed(2);
            }
        },
        get allocatedCashTotal() { return this.dailyAdditionsMode ? (this.cents(this.additions.main) + this.cents(this.additions.spare_parts)) / 100 : (this.cents(this.balances.main) + this.cents(this.balances.spare_parts)) / 100; },
        get separationCashTotal() { return this.dailyAdditionsMode ? (this.cents(this.cashierFunds?.opening) + this.cents(this.currentCashierAmount('main')) + this.cents(this.currentCashierAmount('spare_parts'))) / 100 : (this.cents(this.expectedBalance('main')) + this.cents(this.expectedBalance('spare_parts'))) / 100; },
        get remainingCashTotal() { return (this.cents(this.separationCashTotal) - this.cents(this.allocatedCashTotal)) / 100; },
        get additionsTooHigh() { return this.dailyAdditionsMode && this.cents(this.remainingCashTotal) < 0; },
        get cashMatchesAccounts() { return this.hasCount && (this.dailyAdditionsMode ? !this.additionsTooHigh : (this.independent || this.cents(this.closingBalance) === this.cents(this.allocatedCashTotal))); },
        get expectedCashTotal() { return this.dailyAdditionsMode ? this.remainingCashTotal : (this.independent ? (this.cents(this.drawerExpected) - this.cents(this.cashWithdrawal)) / 100 : (['main', 'washing', 'spare_parts'].reduce((sum, type) => sum + this.cents(this.expected[type]), 0) - this.cents(this.withdrawal)) / 100); },
        get countedCashTotal() { return this.cents(this.closingBalance) / 100; },
        get cashDifference() { return (this.cents(this.closingBalance) - this.cents(this.expectedCashTotal)) / 100; },
        get hasCount() { return String(this.closingBalance).trim() !== ''; },
        get hasDifference() { return (this.hasCount && this.cents(this.closingBalance) !== this.cents(this.expectedCashTotal)) || (this.dailyAdditionsMode ? this.cents(this.balances.bank) !== this.cents(this.expectedBalance('bank')) : Object.keys(this.balances).some(type => this.cents(this.balances[type]) !== this.cents(this.expectedBalance(type)))); },
        get withdrawalTooHigh() { return ['main','spare_parts','bank'].some(type => this.withdrawalTotals[type] > 0 && this.expectedBalance(type) < 0) || (this.cashWithdrawal > 0 && this.expectedCashTotal < 0); },
    };
}

if (typeof window !== 'undefined') window.cashierClosing = cashierClosing;

export function openingBalancesState(cash, amounts, suggestedCash, suggestedAmounts) {
    const valid = value => String(value).trim() !== '' && Number.isFinite(Number(value));
    const cents = value => Math.round(Number(value) * 100);
    return {
        valid: valid(cash) && Number(cash) >= 0 && amounts.every(valid),
        changed: cents(cash) !== cents(suggestedCash) || amounts.some((value, index) => cents(value) !== cents(suggestedAmounts[index])),
    };
}

let openingDialog;
export async function openCashierDialog(url) {
    if (openingDialog?.open) return;
    if (openingDialog?.dataset.loading === 'true') return;
    const dialog = document.createElement('dialog');
    openingDialog = dialog;
    dialog.dataset.loading = 'true';
    dialog.className = 'm-auto w-[min(94vw,640px)] max-h-[90vh] overflow-auto rounded-2xl border-0 bg-white p-6 shadow-2xl backdrop:bg-slate-950/60';
    dialog.setAttribute('aria-labelledby', 'cashier-opening-title');
    try {
        const response = await fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'}});
        if (!response.ok || response.redirected) {
            const message = response.status < 500 && response.headers.get('content-type')?.includes('text/plain') ? (await response.text()).trim() : '';
            throw new Error(message || 'The opening form is unavailable. Use Closing history to review an earlier open cashier, or refresh the page.');
        }
        const content = await response.text();
        if (content.includes('data-cashier-closing-content')) {
            openingDialog = null;
            await openCashierClosing(url, content);
            return;
        }
        dialog.innerHTML = content;
        document.body.append(dialog);
        dialog.querySelectorAll('[data-close-cashier-popup]').forEach(button => button.addEventListener('click', () => dialog.close()));
        const opening = dialog.querySelector('[name="opening_balance"]');
        const reason = dialog.querySelector('[name="opening_reason"]');
        const accounts = [...dialog.querySelectorAll('[data-opening-account]')];
        const allocationTotal = dialog.querySelector('[data-opening-total]');
        const updateAllocation = () => {
            const state = openingBalancesState(opening.value, accounts.map(input => input.value), opening.dataset.suggested, accounts.map(input => input.dataset.suggested));
            reason.required = reason.dataset.alwaysRequired === '1' || state.changed;
            opening.setCustomValidity(state.valid ? '' : 'Enter valid cashier cash and account balances.');
            if (allocationTotal) allocationTotal.textContent = 'Cash in the cashier is separate from Main, Spare Parts and Bank balances.';
            dialog.querySelectorAll('[data-opening-preview]').forEach(preview => {
                const input = dialog.querySelector('form').elements.namedItem(preview.dataset.openingPreview);
                preview.textContent = Number(input?.value || 0).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2});
            });
        };
        [opening, ...accounts].forEach(input => input.addEventListener('input', updateAllocation));
        updateAllocation();
        dialog.addEventListener('close', () => {dialog.remove(); openingDialog = null;}, {once: true});
        const form = dialog.querySelector('[data-cashier-opening-form]');
        form.addEventListener('submit', async event => {
            event.preventDefault();
            updateAllocation();
            if (!form.reportValidity() || form.dataset.submitting === 'true') return;
            form.dataset.submitting = 'true';
            const submit = form.querySelector('[type="submit"]');
            submit.disabled = true;
            let alert = form.querySelector('[data-opening-errors]');
            if (!alert) {
                alert = document.createElement('div');
                alert.dataset.openingErrors = 'true';
                alert.className = 'rounded-xl bg-red-50 p-3 text-sm text-red-700';
                alert.setAttribute('role', 'alert');
                alert.tabIndex = -1;
                form.prepend(alert);
            }
            alert.hidden = true;
            try {
                const result = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                const data = await result.json();
                if (!result.ok || !data.success) {
                    alert.textContent = Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Unable to save the cashier balances. Please try again.';
                    alert.hidden = false;
                    alert.focus();
                    return;
                }
                window.location.assign(data.redirect);
            } catch {
                alert.textContent = 'Unable to save the cashier balances. Check your connection and try again.';
                alert.hidden = false;
                alert.focus();
            } finally {
                delete form.dataset.submitting;
                submit.disabled = false;
            }
        });
        dialog.showModal();
        opening.focus();
    } catch (error) {
        dialog.remove(); openingDialog = null;
        if (window.workshopNotify) window.workshopNotify(error.message);
    } finally { delete dialog.dataset.loading; }
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-cashier-open]');
        if (!button) return;
        event.preventDefault();
        openCashierDialog(button.dataset.cashierOpen);
    });
    const auto = document.querySelector('[data-auto-open-cashier]');
    if (auto) openCashierDialog(auto.dataset.autoOpenCashier);
}

let movementsDialog;
export async function openCashierMovements(url) {
    if (movementsDialog?.open) return;
    const dialog = document.createElement('dialog');
    movementsDialog = dialog;
    dialog.className = 'm-auto w-[min(96vw,1100px)] max-h-[90vh] overflow-auto rounded-2xl border-0 bg-white p-5 sm:p-6 shadow-2xl backdrop:bg-slate-950/60';
    dialog.setAttribute('aria-labelledby', 'cashier-movements-title');
    let pending;
    const load = async address => {
        pending?.abort();
        const request = new AbortController();
        pending = request;
        dialog.innerHTML = '<div class="flex items-center justify-between gap-4"><h2 id="cashier-movements-title" class="text-xl font-bold">Transaction details</h2><button type="button" data-close-cashier-movements class="btn-secondary" aria-label="Close transaction details">✕</button></div><p role="status" class="mt-4">Loading transactions…</p>';
        dialog.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(address, {signal: request.signal, headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html'}});
            if (!response.ok || response.redirected) throw new Error('Unable to load transactions. Refresh the cashier page and try again.');
            const content = await response.text();
            if (!dialog.open || request.signal.aborted) return;
            dialog.innerHTML = content;
            dialog.scrollTop = 0;
            dialog.querySelector('[data-close-cashier-movements]')?.focus();
        } catch (error) {
            if (request.signal.aborted) return;
            const message = dialog.querySelector('[role="status"]');
            message.setAttribute('role', 'alert');
            message.textContent = error.message || 'Unable to load transactions. Please try again.';
        } finally {
            if (pending === request) dialog.removeAttribute('aria-busy');
        }
    };
    dialog.addEventListener('click', event => {
        if (event.target.closest('[data-close-cashier-movements]')) dialog.close();
        const summaryLink = event.target.closest('[data-cashier-summary-link]');
        if (summaryLink) { event.preventDefault(); load(summaryLink.href); }
        const page = event.target.closest('[data-movement-pagination] a');
        if (page) { event.preventDefault(); load(page.href); }
    });
    dialog.addEventListener('close', () => { pending?.abort(); dialog.remove(); movementsDialog = null; }, {once: true});
    document.body.append(dialog);
    dialog.showModal();
    await load(url);
}

if (typeof document !== 'undefined') {
    document.addEventListener('click', event => {
        const button = event.target.closest('[data-cashier-movements]');
        if (!button) return;
        event.preventDefault();
        openCashierMovements(button.dataset.cashierMovements);
    });
}


let closingDialog;
export async function openCashierClosing(url, initialContent = null) {
    if (closingDialog?.open) return closingDialog.loadCashierClosing(url, initialContent);
    const dialog = document.createElement('dialog');
    closingDialog = dialog;
    dialog.className = 'm-auto w-[min(96vw,1100px)] max-h-[92vh] overflow-auto rounded-2xl border-0 bg-white p-5 sm:p-6 shadow-2xl backdrop:bg-slate-950/60';
    dialog.setAttribute('aria-labelledby', 'cashier-closing-title');
    let pending;
    let address = url;
    const load = async (nextUrl, content = null) => {
        pending?.abort();
        const request = new AbortController();
        pending = request;
        address = nextUrl;
        dialog.innerHTML = '<header class="flex justify-between gap-4"><h2 id="cashier-closing-title" class="text-xl font-black">Cashier closing</h2><button type="button" data-close-cashier-closing class="btn-secondary">✕</button></header><p class="mt-4" role="status">Loading closing details…</p>';
        dialog.setAttribute('aria-busy', 'true');
        try {
            if (content === null) {
                const response = await fetch(nextUrl, {signal: request.signal, headers: {'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest'}});
                if (!response.ok || response.redirected) throw new Error('Unable to load this closing. Refresh the cashier page and try again.');
                content = await response.text();
            }
            if (request.signal.aborted || !dialog.open) return;
            dialog.innerHTML = content;
            window.Alpine?.initTree(dialog);
            dialog.scrollTop = 0;
            dialog.querySelector('[data-close-cashier-closing]')?.focus();
        } catch (error) {
            if (request.signal.aborted) return;
            const message = dialog.querySelector('[role="status"]');
            if (message) { message.setAttribute('role', 'alert'); message.textContent = error.message; }
        } finally { if (pending === request) dialog.removeAttribute('aria-busy'); }
    };
    dialog.loadCashierClosing = load;
    dialog.addEventListener('click', event => {
        if (event.target.closest('[data-close-cashier-closing]')) dialog.close();
        const page = event.target.closest('[data-closing-pagination] a');
        const reload = event.target.closest('[data-reload-cashier-closing]');
        if (page || reload) { event.preventDefault(); load(page?.href ?? address); }
    });
    dialog.addEventListener('input', event => {
        const input = event.target.closest('[data-closing-addition]');
        if (!input) return;
        const form = input.form;
        const cents = value => Math.round((Number(value) || 0) * 100);
        let total = 0;
        form.querySelectorAll('[data-closing-addition]').forEach(addition => {
            total += cents(addition.value);
            form.elements.namedItem(`balances[${addition.dataset.type}]`).value = ((cents(addition.dataset.base) + cents(addition.value)) / 100).toFixed(2);
        });
        const remaining = (cents(form.dataset.available) - total) / 100;
        form.elements.namedItem('closing_balance').value = remaining.toFixed(2);
        form.querySelector('[data-closing-edit-total]').textContent = `Cash left after these additions: Rs. ${remaining.toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
    });
    dialog.addEventListener('submit', async event => {
        const form = event.target.closest('[data-cashier-closing-form], [data-cashier-closing-correction]');
        if (!form) return;
        event.preventDefault();
        if (form.dataset.submitting === 'true' || !form.reportValidity()) return;
        form.dataset.submitting = 'true';
        const submit = form.querySelector('[type="submit"], button:not([type])');
        if (submit) submit.disabled = true;
        let alert = form.querySelector('[data-closing-errors]');
        if (!alert) {
            alert = document.createElement('div'); alert.dataset.closingErrors = 'true';
            alert.className = 'rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800';
            alert.setAttribute('role', 'alert'); alert.tabIndex = -1; form.prepend(alert);
        }
        alert.hidden = true;
        try {
            const response = await fetch(form.action, {method: 'POST', body: new FormData(form), headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
            const data = await response.json();
            if (!response.ok || !data.success) {
                alert.textContent = Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Unable to save this closing.';
                const reload = document.createElement('button'); reload.type = 'button'; reload.dataset.reloadCashierClosing = 'true';
                reload.className = 'ml-2 font-bold underline'; reload.textContent = 'Reload closing details'; alert.append(reload);
                alert.hidden = false; alert.focus(); return;
            }
            window.location.assign(data.redirect);
        } catch {
            alert.textContent = 'Unable to save. Check your connection or refresh the page and review the closing again.';
            alert.hidden = false; alert.focus();
        } finally { delete form.dataset.submitting; if (submit) submit.disabled = false; }
    });
    dialog.addEventListener('close', () => { pending?.abort(); window.Alpine?.destroyTree(dialog); dialog.remove(); closingDialog = null; }, {once: true});
    document.body.append(dialog); dialog.showModal(); await load(url, initialContent);
}

if (typeof document !== 'undefined') document.addEventListener('click', event => {
    const button = event.target.closest('[data-cashier-closing]');
    if (!button) return;
    event.preventDefault();
    openCashierClosing(button.dataset.cashierClosing);
});


let accountTotalDialog;
export async function openCashierAccountTotal(url, sourceForm, trigger) {
    if (accountTotalDialog?.open) return;
    const dialog = document.createElement('dialog');
    accountTotalDialog = dialog;
    dialog.className = 'm-auto w-[min(94vw,640px)] max-h-[90vh] overflow-auto rounded-2xl border-0 bg-white p-6 shadow-2xl backdrop:bg-slate-950/60';
    dialog.setAttribute('aria-labelledby', 'account-total-title');
    dialog.innerHTML = '<header class="flex justify-between gap-4"><h2 id="account-total-title" class="text-xl font-black">Edit account total</h2><button type="button" data-close-account-total class="btn-secondary">✕</button></header><p class="mt-4" role="status">Loading account balance…</p>';
    const request = new AbortController();
    dialog.addEventListener('click', event => { if (event.target.closest('[data-close-account-total]')) dialog.close(); });
    dialog.addEventListener('close', () => { request.abort(); dialog.remove(); accountTotalDialog = null; trigger?.focus(); }, {once:true});
    document.body.append(dialog); dialog.showModal();
    try {
        const response = await fetch(url, {signal:request.signal, headers:{'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}});
        if (!response.ok || response.redirected) {
            const data = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
            throw new Error(Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Unable to load this account balance. Refresh the cashier page and try again.');
        }
        const content = await response.text();
        if (!dialog.open) return;
        dialog.innerHTML = content;
        const form = dialog.querySelector('[data-account-total-form]');
        const amount = form.elements.namedItem('total_balance');
        const update = () => {
            const delta = (Math.round((Number(amount.value) || 0) * 100) - Math.round(Number(amount.dataset.previousTotal) * 100)) / 100;
            dialog.querySelector('[data-account-correction-delta]').textContent = `Account correction: ${delta > 0 ? '+' : ''}Rs. ${delta.toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2})}`;
        };
        amount.addEventListener('input', update); update(); amount.focus(); amount.select();
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (form.dataset.submitting === 'true' || !form.reportValidity()) return;
            form.dataset.submitting = 'true';
            const submit = form.querySelector('[type="submit"]'); submit.disabled = true;
            let alert = form.querySelector('[data-account-total-errors]');
            if (!alert) { alert = document.createElement('p'); alert.dataset.accountTotalErrors = 'true'; alert.className = 'rounded-xl bg-red-50 p-3 text-sm text-red-800'; alert.setAttribute('role','alert'); alert.tabIndex = -1; form.prepend(alert); }
            alert.hidden = true;
            try {
                const result = await fetch(form.action, {method:'POST', body:new FormData(form), headers:{'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest'}});
                const data = await result.json();
                if (!result.ok || !data.success) { alert.textContent = Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Unable to save the account total.'; alert.hidden = false; alert.focus(); return; }
                sourceForm.dispatchEvent(new CustomEvent('cashier-account-total-updated', {bubbles:true, detail:data}));
                dialog.close();
            } catch { alert.textContent = 'Unable to save. Refresh the cashier page to check the account balance before trying again.'; alert.hidden = false; alert.focus(); }
            finally { delete form.dataset.submitting; submit.disabled = false; }
        });
    } catch (error) {
        if (request.signal.aborted) return;
        const message = dialog.querySelector('[role="status"]');
        if (message) { message.setAttribute('role','alert'); message.textContent = error.message; }
    }
}
