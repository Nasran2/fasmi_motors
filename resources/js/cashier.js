export function cashierClosing(expected, initialBalances = {}, initialWithdrawal = 0) {
    return {
        expected,
        withdrawal: initialWithdrawal,
        balances: Object.fromEntries(Object.entries(expected).map(([type, amount]) => [type, String(initialBalances[type] ?? ((Math.round(Number(amount) * 100) - (type === 'spare_parts' ? Math.round(Number(initialWithdrawal) * 100) : 0)) / 100))])),
        cents(value) { return Math.round((Number(value) || 0) * 100); },
        money(value) { return Number(value).toLocaleString('en-LK', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
        expectedBalance(type) {
            return (this.cents(this.expected[type]) - (type === 'spare_parts' ? this.cents(this.withdrawal) : 0)) / 100;
        },
        updateWithdrawal(value) {
            const change = this.cents(value) - this.cents(this.withdrawal);
            this.balances.spare_parts = ((this.cents(this.balances.spare_parts) - change) / 100).toFixed(2);
            this.withdrawal = value;
        },
        get expectedCashTotal() { return Object.keys(this.expected).reduce((sum, type) => sum + this.cents(this.expectedBalance(type)), 0) / 100; },
        get countedCashTotal() { return Object.values(this.balances).reduce((sum, amount) => sum + this.cents(amount), 0) / 100; },
        get hasDifference() {
            return Object.keys(this.expected).some(type => this.cents(this.balances[type]) !== this.cents(this.expectedBalance(type)));
        },
        get withdrawalTooHigh() {
            return this.cents(this.withdrawal) > 0 && (this.expectedBalance('spare_parts') < 0 || this.expectedCashTotal < 0);
        },
    };
}

if (typeof window !== 'undefined') window.cashierClosing = cashierClosing;

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
        const updateReason = () => { reason.required = Math.round(Number(opening.value) * 100) !== Math.round(Number(opening.dataset.suggested) * 100); };
        opening.addEventListener('input', updateReason);
        updateReason();
        dialog.addEventListener('close', () => {dialog.remove(); openingDialog = null;}, {once: true});
        const form = dialog.querySelector('[data-cashier-opening-form]');
        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (form.dataset.submitting === 'true') return;
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
                    alert.textContent = Object.values(data.errors ?? {}).flat().join(' ') || data.message || 'Unable to open the cashier. Please try again.';
                    alert.hidden = false;
                    alert.focus();
                    return;
                }
                window.location.assign(data.redirect);
            } catch {
                alert.textContent = 'Unable to open the cashier. Check your connection and try again.';
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
