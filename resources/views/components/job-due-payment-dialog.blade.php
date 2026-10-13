@props(['accounts', 'customerMode' => false])
<dialog id="due-payment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl" aria-labelledby="due-payment-title">
    <form method="POST" id="due-payment-form" class="p-6">
        @csrf<x-submission-token/>
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-red-600">Payment Collection</p>
                <h2 class="mt-1 text-xl font-black" id="due-payment-title">Receive Payment</h2>
            </div>
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="btn-secondary" aria-label="Close payment popup">×</button>
        </div>
        <div class="mt-4 grid items-center gap-4 sm:grid-cols-2">
            <div class="rounded-xl border border-red-100 bg-red-50 p-4">
                <p class="text-xs font-bold text-red-500">Outstanding Due</p>
                <p class="mt-1 text-2xl font-black text-red-700" id="due-payment-outstanding-label">Rs. 0.00</p>
            </div>
            <label class="block"><span class="label">Payment date</span><input type="date" name="paid_at" value="{{ today()->toDateString() }}" max="{{ today()->toDateString() }}" class="field" required></label>
        </div>
        <p class="mt-3 text-xs text-slate-500">Enter the amount received. You can pay part of the outstanding balance.</p>
        @if($customerMode)<p class="mt-2 text-xs text-slate-500">Payments cover the oldest outstanding balances first, including the opening balance.</p>@endif
        <div class="mt-4"><x-split-payments :accounts="$accounts" :change-total="0" :allow-cash-change="!$customerMode" due-preview /></div>
        <details class="mt-4"><summary class="cursor-pointer text-xs font-semibold text-slate-500">Add a note (optional)</summary><label class="mt-2 block"><span class="label">Note</span><textarea name="note" rows="2" maxlength="500" class="field"></textarea></label></details>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="btn-secondary">Cancel</button>
            <button class="btn-primary">Save Payment</button>
        </div>
    </form>
</dialog>
<script>
function openDuePaymentModal(button) {
    const form = document.getElementById('due-payment-form');
    const {invoiceId, due, invoiceNumber, paid, hold, customerId, customerName} = button.dataset;
    form.action = customerId ? @js(url('customers')) + '/' + customerId + '/payment' : @js(url('customer-dues')) + '/' + invoiceId + '/payment';
    document.getElementById('due-payment-title').textContent = customerId ? 'Pay Due - ' + customerName : 'Pay Bill ' + invoiceNumber;
    document.getElementById('due-payment-outstanding-label').textContent = 'Rs. ' + Number(due).toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    form.elements.namedItem('paid_at').value = @js(today()->toDateString());
    form.elements.namedItem('note').value = '';
    form.dispatchEvent(new CustomEvent('payment-due-selected', {bubbles: true, detail: {due: Number(due), paid: Number(paid), hold: Number(hold), reset: true}}));
    document.getElementById('due-payment-dialog').showModal();
}
</script>
