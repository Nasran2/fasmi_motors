@props(['accounts', 'customerMode' => false, 'selectBills' => false])
<dialog id="due-payment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl" aria-labelledby="due-payment-title">
    <form method="POST" id="due-payment-form" class="p-6" @if($selectBills) x-data="dueBillSelection()" @payment-bills-selected="loadBills($event.detail.bills, $event.detail.hasJob)" @submit="if (!selectedBills().length) $event.preventDefault()" @endif>
        @csrf<x-submission-token/>
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-red-600">Payment Collection</p>
                <h2 class="mt-1 text-xl font-black" id="due-payment-title">Receive Payment</h2>
            </div>
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="btn-secondary" aria-label="Close payment popup">×</button>
        </div>
        @if($selectBills)
        <div class="mt-4" x-show="hasJob" x-cloak>
            <input type="hidden" name="include_previous_balance" value="0" :disabled="!hasJob">
            <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" name="include_previous_balance" value="1" x-model="includePrevious" :disabled="!hasJob" @change="togglePrevious()">Include old balances in this payment</label>
        </div>
        <div class="mt-4 rounded-xl border border-slate-200" id="due-payment-bills">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-3">
                <label class="flex items-center gap-2 text-sm font-bold"><input type="checkbox" :checked="bills.length > 0 && selectedBills().length === bills.length" @change="selectAll($event.target.checked)">Select all bills</label>
                <span class="text-sm font-bold">Selected due: <span id="due-payment-selected-total" x-text="money(selectedTotal())"></span></span>
            </div>
            <div class="max-h-52 overflow-y-auto divide-y divide-slate-100">
                <template x-for="bill in bills" :key="bill.invoice_id">
                    <label class="flex cursor-pointer items-center justify-between gap-3 p-3">
                        <div class="flex items-center gap-3">
                            <input type="checkbox" name="invoice_ids[]" :value="bill.invoice_id" x-model="selectedIds" :disabled="hasJob && !includePrevious && !bill.is_current" @change="$nextTick(() => updateDue())">
                            <div><span class="block text-sm font-bold" x-text="bill.reference"></span><span class="block text-xs text-slate-500" x-text="(bill.invoice_number !== bill.reference ? bill.invoice_number + ' · ' : '') + bill.date"></span><span class="block text-xs text-slate-500" x-text="bill.status"></span></div>
                        </div>
                        <span class="shrink-0 text-sm font-bold text-red-600" x-text="money(bill.amount)"></span>
                    </label>
                </template>
            </div>
        </div>
        <p class="mt-2 text-xs text-slate-500">Payments apply to selected bills only, oldest first. Remaining Due below is for the selected bills.</p>
        <p x-show="!selectedBills().length" x-cloak class="mt-2 text-xs font-bold text-red-600">Select at least one bill to continue.</p>
        @endif
        <div class="mt-4 grid items-center gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-red-100 bg-red-50 p-4">
                <p class="text-xs font-bold text-red-500">Outstanding Due</p>
                <p class="mt-1 text-2xl font-black text-red-700" id="due-payment-outstanding-label">Rs. 0.00</p>
            </div>
            <label class="block"><span class="label">Payment date</span><input type="date" name="paid_at" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="field" required></label>
        </div>
        <p class="mt-3 text-xs text-slate-500">Enter the amount received. You can pay part of the outstanding balance.</p>
        @if($customerMode)<p class="mt-2 text-xs text-slate-500">Payments cover the oldest outstanding balances first, including the opening balance.</p>@endif
        <div class="mt-4"><x-split-payments :accounts="$accounts" :change-total="0" :allow-cash-change="!$customerMode && !$selectBills" due-preview /></div>
        <details class="mt-4"><summary class="cursor-pointer text-xs font-semibold text-slate-500">Add a note (optional)</summary><label class="mt-2 block"><span class="label">Note</span><textarea name="note" rows="2" maxlength="500" class="field"></textarea></label></details>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="btn-secondary">Cancel</button>
            <button class="btn-primary" @if($selectBills) :disabled="!selectedBills().length" @endif>Save Payment</button>
        </div>
    </form>
</dialog>
<script>
@if($selectBills)
function dueBillSelection() {
    return {
        bills: [], selectedIds: [], includePrevious: true, hasJob: false,
        money(value) { return 'Rs. ' + Number(value).toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
        selectedBills() { return this.bills.filter(bill => this.selectedIds.map(Number).includes(Number(bill.invoice_id))); },
        selectedTotal() { return Math.round(this.selectedBills().reduce((total, bill) => total + Number(bill.amount), 0) * 100) / 100; },
        loadBills(bills, hasJob = false) { this.bills = bills; this.hasJob = hasJob; this.includePrevious = true; this.selectAll(true); },
        selectAll(checked) { this.selectedIds = checked ? this.bills.filter(bill => !this.hasJob || this.includePrevious || bill.is_current).map(bill => String(bill.invoice_id)) : []; this.updateDue(); },
        togglePrevious() { this.selectedIds = this.selectedIds.filter(id => this.bills.find(bill => String(bill.invoice_id) === String(id))?.is_current); if (this.includePrevious) this.selectedIds.push(...this.bills.filter(bill => !bill.is_current).map(bill => String(bill.invoice_id))); this.updateDue(); },
        updateDue() {
            this.$el.closest('form').dispatchEvent(new CustomEvent('payment-due-selected', {bubbles: true, detail: {
                due: this.selectedTotal(),
                paid: this.selectedBills().reduce((total, bill) => total + Number(bill.paid), 0),
                hold: this.selectedBills().reduce((total, bill) => total + Number(bill.hold), 0)
            }}));
        }
    };
}
@endif
function openDuePaymentModal(button) {
    const form = document.getElementById('due-payment-form');
    const {invoiceId, due, invoiceNumber, paid, hold, customerId, customerName, jobId, jobNumber, bills} = button.dataset;
    form.action = jobId ? @js(url('job-cards')) + '/' + jobId + '/due-payment' : (customerId ? @js(url('customers')) + '/' + customerId + '/payment' : @js(url('customer-dues')) + '/' + invoiceId + '/payment');
    document.getElementById('due-payment-title').textContent = jobId ? 'Pay Due - ' + jobNumber : (customerId ? 'Pay Due - ' + customerName : 'Pay Bill ' + invoiceNumber);
    document.getElementById('due-payment-outstanding-label').textContent = 'Rs. ' + Number(due).toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    form.elements.namedItem('paid_at').value = @js(today()->toDateString());
    form.elements.namedItem('note').value = '';
    form.dispatchEvent(new CustomEvent('payment-due-selected', {bubbles: true, detail: {due: Number(due), paid: Number(paid), hold: Number(hold), reset: true}}));
    @if($selectBills)
    form.dispatchEvent(new CustomEvent('payment-bills-selected', {detail: {bills: jobId ? JSON.parse(bills) : [{invoice_id:Number(invoiceId), invoice_number:invoiceNumber, reference:invoiceNumber, date:'', amount:Number(due), paid:Number(paid), hold:Number(hold), status:'', is_current:true}], hasJob:Boolean(jobId)}}));
    @endif
    document.getElementById('due-payment-dialog').showModal();
}
</script>
