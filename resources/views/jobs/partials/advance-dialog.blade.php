@php
    $editingAdvance = $editingAdvance ?? null;
    $dialogId = $editingAdvance ? 'job-advance-edit-'.$editingAdvance->id : 'job-advance-dialog';
    $isThisAdvanceForm = (string) old('_advance_id', '') === (string) ($editingAdvance?->id ?? '') && !old('_advance_delete_id');
    $advanceValue = fn($field, $fallback = null) => $isThisAdvanceForm ? old($field, $fallback) : $fallback;
    $advanceCashAccount = $accounts->firstWhere('code', App\Models\Account::systemCode('spare_parts'))?->id;
    $advanceBankAccount = $accounts->firstWhere('code', App\Models\Account::systemCode('bank'))?->id;
    $advanceErrors = $isThisAdvanceForm && collect($errors->keys())->contains(fn($key) => str_starts_with($key, 'advance_') || $key === 'send_advance_sms');
@endphp
<dialog id="{{ $dialogId }}" class="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl" aria-labelledby="{{ $dialogId }}-title"
    x-data="{
        method: @js($advanceValue('advance_method', $editingAdvance?->method ?? 'Cash')),
        amount: @js((float) ($advanceValue('advance_amount', $editingAdvance?->amount ?? 0))),
        chargeConfig: @js(\App\Support\PaymentMethodCharges::configForUi()),
        chargeRate() { return Number(this.chargeConfig?.rates?.[this.method] || 0); },
        chargeAccount() { return this.chargeConfig?.accounts?.[this.method] || 'Bank Account'; },
        chargeAmount() { return this.chargeRate() > 0 && Number(this.amount || 0) > 0 ? Math.round(Number(this.amount) * this.chargeRate()) / 100 : 0; }
    }"
    @if($advanceErrors) x-init="$nextTick(() => $el.showModal())" @endif>
    <form method="POST" action="{{ $editingAdvance ? route('jobs.advances.update', [$job, $editingAdvance]) : route('jobs.advances.store', $job) }}" class="space-y-4 p-6">
        @csrf<x-submission-token/>
        @if($editingAdvance) @method('PUT')<input type="hidden" name="_advance_id" value="{{ $editingAdvance->id }}">@endif
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div><h2 id="{{ $dialogId }}-title" class="text-xl font-bold">{{ $editingAdvance ? 'Edit advance' : 'Add advance' }}</h2><p class="mt-1 text-sm text-slate-500">{{ $job->number }} · {{ $job->customer?->name ?: 'Walk-in Customer' }}</p></div>
            <button type="button" class="btn-secondary" onclick="document.getElementById('{{ $dialogId }}').close()" aria-label="Close advance popup">×</button>
        </div>
        @if($advanceErrors)<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p class="text-sm text-slate-500">{{ $editingAdvance ? 'Correct this advance. Its original values and your reason remain in the change history.' : 'The advance covers Spare Parts first, then Main when this job is billed. Any excess can cover earlier balances.' }}</p>
        @if($editingAdvance)<label class="block"><span class="label">Reason for correction *</span><textarea name="advance_reason" rows="2" minlength="5" maxlength="500" class="field" required>{{ $advanceValue('advance_reason') }}</textarea></label>@endif
        <div class="grid gap-4 sm:grid-cols-2">
            <label><span class="label">Advance amount (LKR) *</span><input name="advance_amount" type="number" min="0.01" max="999999999999.99" step="0.01" x-model.number="amount" value="{{ $advanceValue('advance_amount', $editingAdvance?->amount) }}" placeholder="0.00" class="field" required></label>
            <label><span class="label">Payment date *</span><input name="advance_paid_at" type="date" value="{{ $advanceValue('advance_paid_at', $editingAdvance?->paid_at->toDateString() ?? today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="field" required></label>
            <label><span class="label">Payment method *</span><select name="advance_method" x-model="method" class="field">@foreach(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR'] as $method)<option @selected($advanceValue('advance_method', $editingAdvance?->method ?? 'Cash') === $method)>{{ $method }}</option>@endforeach</select></label>
            <div><span class="label">Receiving account</span><p class="field" x-text="method === 'Cash' ? 'Spare Parts Cash' : 'Bank Account'"></p><input type="hidden" name="advance_account_id" :value="method === 'Cash' ? @js($advanceCashAccount) : @js($advanceBankAccount)"></div>
        </div>
        <div x-show="chargeRate() > 0" x-cloak class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200 bg-amber-50/80 px-3.5 py-2.5 text-xs font-semibold text-amber-900">
            <span><span x-text="method"></span> charge (<span x-text="chargeRate()"></span>% · <span x-text="chargeAccount()"></span> expense)</span>
            <span>Charge: <b class="text-red-700" x-text="'Rs. ' + chargeAmount().toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2})"></b></span>
        </div>
        <fieldset x-show="method === 'Cheque'" :disabled="method !== 'Cheque'" x-cloak class="space-y-3 rounded-xl border border-slate-200 p-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="label">Cheque date *</span><input name="advance_cheque_date" type="date" value="{{ $advanceValue('advance_cheque_date', $editingAdvance?->cheque?->cheque_date->toDateString()) }}" class="field" :required="method === 'Cheque'"></label>
                <label><span class="label">Cheque number *</span><input name="advance_cheque_number" value="{{ $advanceValue('advance_cheque_number', $editingAdvance?->cheque?->cheque_number) }}" maxlength="80" class="field" :required="method === 'Cheque'"></label>
                <label class="sm:col-span-2"><span class="label">Bank *</span><input name="advance_bank_name" value="{{ $advanceValue('advance_bank_name', $editingAdvance?->cheque?->bank_name) }}" maxlength="120" class="field" :required="method === 'Cheque'"></label>
            </div>
            <p class="text-xs text-amber-700">Cheque advances stay on hold until cleared.</p>
        </fieldset>
        <label class="block"><span class="label">Note (optional)</span><textarea name="advance_note" rows="2" maxlength="500" class="field">{{ $advanceValue('advance_note', $editingAdvance?->note) }}</textarea></label>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <input type="hidden" name="send_advance_sms" value="0">
            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="send_advance_sms" value="1" @checked($advanceValue('send_advance_sms', true))>Send SMS to customer</label>
            <p class="mt-2 text-xs text-slate-500">Includes the job card number and this advance amount. Uncheck to save without sending an SMS.</p>
            @if(!$job->customer?->phone)<p class="mt-2 text-xs text-amber-700">This customer has no phone number. Add one to their profile to receive SMS.</p>@endif
        </div>
        <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" onclick="document.getElementById('{{ $dialogId }}').close()">Cancel</button><button class="btn-primary">{{ $editingAdvance ? 'Save correction' : 'Save advance' }}</button></div>
    </form>
</dialog>
