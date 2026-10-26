@php
    $advanceMainAccount = $accounts->firstWhere('code', App\Models\Account::systemCode('main'))?->id;
    $advanceBankAccount = $accounts->firstWhere('code', App\Models\Account::systemCode('bank'))?->id;
    $advanceErrors = collect($errors->keys())->contains(fn($key) => str_starts_with($key, 'advance_') || $key === 'send_advance_sms');
@endphp
<dialog id="job-advance-dialog" class="m-auto w-[calc(100%-2rem)] max-w-2xl max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl" aria-labelledby="job-advance-title"
    x-data="{ method: @js(old('advance_method', 'Cash')), account: @js(old('advance_account_id', $advanceMainAccount)) }"
    @if($advanceErrors) x-init="$nextTick(() => $el.showModal())" @endif>
    <form method="POST" action="{{ route('jobs.advances.store', $job) }}" class="space-y-4 p-6">
        @csrf<x-submission-token/>
        <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-3">
            <div><h2 id="job-advance-title" class="text-xl font-bold">Add advance</h2><p class="mt-1 text-sm text-slate-500">{{ $job->number }} · {{ $job->customer?->name ?: 'Walk-in Customer' }}</p></div>
            <button type="button" class="btn-secondary" onclick="document.getElementById('job-advance-dialog').close()" aria-label="Close advance popup">×</button>
        </div>
        @if($advanceErrors)<div role="alert" class="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
        <p class="text-sm text-slate-500">Record money received now. This advance is deducted from the final job invoice.</p>
        <div class="grid gap-4 sm:grid-cols-2">
            <label><span class="label">Advance amount (LKR) *</span><input name="advance_amount" type="number" min="0.01" max="999999999999.99" step="0.01" value="{{ old('advance_amount') }}" placeholder="0.00" class="field" required></label>
            <label><span class="label">Payment date *</span><input name="advance_paid_at" type="date" value="{{ old('advance_paid_at', today()->toDateString()) }}" max="{{ today()->toDateString() }}" class="field" required></label>
            <label><span class="label">Payment method *</span><select name="advance_method" x-model="method" @change="account = method === 'Cash' ? @js($advanceMainAccount) : @js($advanceBankAccount)" class="field">@foreach(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR'] as $method)<option @selected(old('advance_method', 'Cash') === $method)>{{ $method }}</option>@endforeach</select></label>
            <label><span class="label">Receiving account *</span><select name="advance_account_id" x-model="account" class="field" required><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
        </div>
        <fieldset x-show="method === 'Cheque'" :disabled="method !== 'Cheque'" x-cloak class="space-y-3 rounded-xl border border-slate-200 p-3">
            <div class="grid gap-3 sm:grid-cols-2">
                <label><span class="label">Cheque date *</span><input name="advance_cheque_date" type="date" value="{{ old('advance_cheque_date') }}" class="field" :required="method === 'Cheque'"></label>
                <label><span class="label">Cheque number *</span><input name="advance_cheque_number" value="{{ old('advance_cheque_number') }}" maxlength="80" class="field" :required="method === 'Cheque'"></label>
                <label class="sm:col-span-2"><span class="label">Bank *</span><input name="advance_bank_name" value="{{ old('advance_bank_name') }}" maxlength="120" class="field" :required="method === 'Cheque'"></label>
            </div>
            <p class="text-xs text-amber-700">Cheque advances stay on hold until cleared.</p>
        </fieldset>
        <label class="block"><span class="label">Note (optional)</span><textarea name="advance_note" rows="2" maxlength="500" class="field">{{ old('advance_note') }}</textarea></label>
        <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
            <input type="hidden" name="send_advance_sms" value="0">
            <label class="flex items-center gap-2 font-semibold"><input type="checkbox" name="send_advance_sms" value="1" @checked(old('send_advance_sms', true))>Send SMS to customer</label>
            <p class="mt-2 text-xs text-slate-500">Includes the job card number and this advance amount. Uncheck to save without sending an SMS.</p>
            @if(!$job->customer?->phone)<p class="mt-2 text-xs text-amber-700">This customer has no phone number. Add one to their profile to receive SMS.</p>@endif
        </div>
        <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" onclick="document.getElementById('job-advance-dialog').close()">Cancel</button><button class="btn-primary">Save advance</button></div>
    </form>
</dialog>
