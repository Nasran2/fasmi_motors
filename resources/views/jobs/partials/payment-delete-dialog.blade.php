<dialog id="delete-payment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-md max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl" aria-labelledby="delete-payment-title"
    x-data="{
        payment: {number: '', invoice_number: '', received_amount: '0.00', applied_amount: '0.00', change_amount: 0, method: '', paid_at: ''}
    }"
    @delete-job-payment.window="payment = {...$event.detail.payment}; $refs.form.action = $event.detail.action; $el.showModal()">
    <form method="POST" x-ref="form" class="p-6 space-y-4">
        @csrf<x-submission-token/>@method('DELETE')
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-red-600">Remove Receipt</p>
                <h2 class="mt-0.5 text-lg font-black text-slate-900" id="delete-payment-title">Delete payment?</h2>
            </div>
            <button type="button" class="btn-secondary !px-2.5 !py-1" @click="$el.closest('dialog').close()" aria-label="Close delete payment dialog">×</button>
        </div>

        <div class="rounded-xl border border-red-100 bg-red-50/70 p-3.5 text-xs space-y-1">
            <div class="flex justify-between gap-2 font-bold text-slate-900">
                <span x-text="payment.number"></span>
                <span class="text-red-700" x-text="'Rs. ' + payment.received_amount"></span>
            </div>
            <p class="text-slate-600"><span x-text="payment.invoice_number"></span> · <span x-text="payment.paid_at"></span> · <span x-text="payment.method"></span></p>
            <template x-if="Number(payment.change_amount || 0) > 0">
                <p class="font-semibold text-violet-700">Applied: Rs. <span x-text="payment.applied_amount"></span></p>
            </template>
        </div>

        <p class="text-xs leading-relaxed text-slate-600">
            This permanently deletes the payment from all accounts, cashier balances, bills, and reports, and restores <b class="text-slate-900" x-text="'Rs. ' + payment.applied_amount"></b> to the invoice due balance.
        </p>

        <div class="flex justify-end gap-2 pt-1">
            <button type="button" class="btn-secondary" @click="$el.closest('dialog').close()">Cancel</button>
            <button type="submit" class="btn-primary !bg-red-600 hover:!bg-red-700">Delete payment</button>
        </div>
    </form>
</dialog>
