export function cashierClosing(expected, initialBalance = '', initialWithdrawal = 0, initialBalances = {}) {
    return {
        expected,
        withdrawal: initialWithdrawal,
        closingBalance: initialBalance,
        balances: {
            bank: String(initialBalances.bank ?? expected.bank ?? 0),
            main: String(initialBalances.main ?? ((Math.round(Number(expected.main) * 100) + Math.round(Number(expected.washing || 0) * 100)) / 100)),
            spare_parts: String(initialBalances.spare_parts ?? ((Math.round(Number(expected.spare_parts) * 100) - Math.round(Number(initialWithdrawal) * 100)) / 100)),
        },
        cents(value) { return Math.round((Number(value) || 0) * 100); },
        money(value) { return Number(value).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        expectedBalance(type) {
            if (type === 'bank') return Number(this.expected.bank) || 0;
            return type === 'main' ? (this.cents(this.expected.main) + this.cents(this.expected.washing)) / 100 : (this.cents(this.expected.spare_parts) - this.cents(this.withdrawal)) / 100;
        },
        updateWithdrawal(value) {
            const change = this.cents(value) - this.cents(this.withdrawal);
            this.balances.spare_parts = ((this.cents(this.balances.spare_parts) - change) / 100).toFixed(2);
            this.withdrawal = value;
        },
        get allocatedCashTotal() { return (this.cents(this.balances.main) + this.cents(this.balances.spare_parts)) / 100; },
        get cashMatchesAccounts() { return this.hasCount && this.cents(this.closingBalance) === this.cents(this.allocatedCashTotal); },
        get expectedCashTotal() { return (['main', 'washing', 'spare_parts'].reduce((sum, type) => sum + this.cents(this.expected[type]), 0) - this.cents(this.withdrawal)) / 100; },
        get countedCashTotal() { return this.cents(this.closingBalance) / 100; },
        get cashDifference() { return (this.cents(this.closingBalance) - this.cents(this.expectedCashTotal)) / 100; },
        get hasCount() { return String(this.closingBalance).trim() !== ''; },
        get hasDifference() { return (this.hasCount && this.cents(this.closingBalance) !== this.cents(this.expectedCashTotal)) || Object.keys(this.balances).some(type => this.cents(this.balances[type]) !== this.cents(this.expectedBalance(type))); },
        get withdrawalTooHigh() { return this.cents(this.withdrawal) > 0 && (this.cents(this.expected.spare_parts) < this.cents(this.withdrawal) || this.expectedCashTotal < 0); },
    };
}

if (typeof window !== 'undefined') window.cashierClosing = cashierClosing;

export function openingAllocation(total, amounts, suggestedTotal, suggestedAmounts) {
    const cents = value => Math.round(Number(value) * 100);
    const valid = String(total).trim() !== '' && Number.isFinite(Number(total)) && Number(total) >= 0
        && amounts.every(value => String(value).trim() !== '' && Number.isFinite(Number(value)) && Number(value) >= 0);
    const allocated = amounts.reduce((sum, value) => sum + cents(value), 0);
    return {
        allocated: allocated / 100,
        matches: valid && cents(total) === allocated,
        changed: cents(total) !== cents(suggestedTotal) || amounts.some((value, index) => cents(value) !== cents(suggestedAmounts[index])),
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
        if (!response.ok) throw new Error('The opening form is unavailable. Refresh the cashier page and try again.');
        dialog.innerHTML = await response.text();
        document.body.append(dialog);
        dialog.querySelectorAll('[data-close-cashier-popup]').forEach(button => button.addEventListener('click', () => dialog.close()));
        const opening = dialog.querySelector('[name="opening_balance"]');
        const reason = dialog.querySelector('[name="opening_reason"]');
        const accounts = [...dialog.querySelectorAll('[data-opening-account]')];
        const allocationTotal = dialog.querySelector('[data-opening-total]');
        const updateAllocation = () => {
            const state = openingAllocation(opening.value, accounts.map(input => input.value), opening.dataset.suggested, accounts.map(input => input.dataset.suggested));
            reason.required = reason.dataset.alwaysRequired === '1' || state.changed;
            opening.setCustomValidity(state.matches ? '' : 'The account amounts must be nonnegative and add up to the opening cash in hand.');
            allocationTotal.textContent = `Account total: Rs. ${state.allocated.toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2})}${state.matches ? '' : ' — must match opening cash.'}`;
            allocationTotal.classList.toggle('text-red-700', !state.matches);
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
