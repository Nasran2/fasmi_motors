<dialog id="edit-payment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl" aria-labelledby="edit-payment-title"
    x-data="{
        edit: {number:'',method:'Cash',amount:0,account_id:'',paid_at:'',cheque_date:'',cheque_number:'',bank_name:'',available:0,other_paid:0,other_hold:0},
        reason: '',
        remaining() { return Math.max(0, Math.round((this.edit.available - Number(this.edit.amount || 0)) * 100) / 100); },
        change() { return this.edit.method === 'Cash' ? Math.max(0, Math.round((Number(this.edit.amount || 0) - this.edit.available) * 100) / 100) : 0; },
        statusAfter() {
            if (this.edit.other_hold > 0 || this.edit.method === 'Cheque') return this.remaining() > 0 ? 'Partially Paid / Hold' : 'Hold';
            if (this.remaining() <= 0) return 'Completed';
            return this.edit.other_paid + Number(this.edit.amount || 0) > 0 ? 'Partially Paid' : 'Unpaid';
        },
        money(value) { return Number(value).toLocaleString('en-LK', {minimumFractionDigits:2, maximumFractionDigits:2}); }
    }"
    @edit-job-payment.window="edit = {...$event.detail.payment}; reason = ''; $refs.form.action = $event.detail.action; $el.showModal()">
    <form method="POST" x-ref="form" class="p-6 space-y-4">
        @csrf<x-submission-token/>@method('PUT')
        <div class="flex justify-between items-center gap-3"><h2 class="text-lg font-bold" id="edit-payment-title">Edit payment <span class="block text-xs text-slate-500" x-text="edit.number"></span></h2><button type="button" class="btn-secondary" @click="$el.closest('dialog').close()" aria-label="Close payment editor">×</button></div>
        <label class="block"><span class="label">Reason for correction *</span><textarea class="field" name="reason" x-model="reason" minlength="5" maxlength="500" rows="2" required placeholder="Explain why this payment needs to change"></textarea></label>
        <div class="grid gap-3 sm:grid-cols-2">
            <label><span class="label">Payment method</span><select name="method" x-model="edit.method" class="field">@foreach(['Cash','Card','Bank Transfer','Cheque','QR'] as $method)<option>{{ $method }}</option>@endforeach</select></label>
            <label><span class="label" x-text="edit.method === 'Cash' ? 'Cash received (LKR)' : 'Amount (LKR)'">Amount (LKR)</span><input type="number" name="amount" x-model.number="edit.amount" min="0.01" max="999999999999.99" step="0.01" class="field" required></label>
            <label><span class="label">Receiving account</span><select name="account_id" x-model="edit.account_id" class="field" required><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
            <label><span class="label">Payment date</span><input type="date" name="paid_at" x-model="edit.paid_at" max="{{ today()->toDateString() }}" class="field" required></label>
        </div>
        <template x-if="edit.method === 'Cheque'">
            <div class="grid gap-3 sm:grid-cols-3">
                <label><span class="label">Cheque date *</span><input type="date" name="cheque_date" x-model="edit.cheque_date" class="field" required></label>
                <label><span class="label">Cheque number *</span><input name="cheque_number" x-model="edit.cheque_number" maxlength="80" class="field" required></label>
                <label><span class="label">Bank *</span><input name="bank_name" x-model="edit.bank_name" maxlength="120" class="field" required></label>
                <p class="sm:col-span-3 text-xs text-amber-700">The replacement cheque stays on hold until passed. The original pending cheque is cancelled.</p>
            </div>
        </template>
        <div class="rounded-xl bg-slate-50 border border-slate-200 p-3 text-sm space-y-2" aria-live="polite">
            <div class="flex justify-between font-bold"><span>Remaining Due</span><span id="edit-payment-remaining" x-text="'Rs. ' + money(remaining())"></span></div>
            <div class="flex justify-between"><span>Change to return</span><span x-text="'Rs. ' + money(change())"></span></div>
            <div class="flex justify-between"><span>Payment status after saving</span><span class="font-bold" x-text="statusAfter()"></span></div>
            <p class="text-red-600 text-xs" x-show="edit.method !== 'Cash' && edit.amount > edit.available" x-cloak>Non-cash amounts cannot exceed the available balance.</p>
        </div>
        <p class="text-xs text-slate-500">Saving records your reason, the original values, the corrected values, your name and the time in Status History & Change Logs.</p>
        <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" @click="$el.closest('dialog').close()">Cancel</button><button class="btn-primary" :disabled="reason.trim().length < 5 || edit.amount <= 0 || (edit.method !== 'Cash' && edit.amount > edit.available)">Save correction</button></div>
    </form>
</dialog>
