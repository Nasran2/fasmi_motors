<x-layouts::app :title="$capital->exists ? 'Edit Owner Capital' : 'Add Owner Capital'">
<div class="mx-auto max-w-4xl space-y-5">
    <header><p class="text-xs font-bold uppercase text-red-600">Expenses &amp; Drawings</p><h1 class="page-title">{{ $capital->exists ? 'Edit Owner Capital' : 'Add Owner Capital' }}</h1><p class="page-subtitle">Record money the owner contributes to the selected business account.</p></header>
    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-red-700"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <form method="POST" action="{{ $capital->exists ? route('owner-capital.update', $capital) : route('owner-capital.store') }}" class="panel space-y-5 p-5">
        @csrf<x-submission-token />
        @if($capital->exists)@method('PUT')<input type="hidden" name="record_version" value="{{ $capital->version() }}">@endif
        <div class="grid gap-5 sm:grid-cols-2">
            <label><span class="label">Date *</span><input type="date" name="capital_date" required max="{{ today()->toDateString() }}" value="{{ old('capital_date', $capital->capital_date?->toDateString()) }}" class="field"></label>
            <label><span class="label">Owner name *</span><input name="owner_name" required maxlength="150" value="{{ old('owner_name', $capital->owner_name) }}" class="field"></label>
            <label><span class="label">Amount · LKR *</span><input type="number" name="amount" required min="0.01" max="999999999999.99" step="0.01" value="{{ old('amount', $capital->amount) }}" class="field"></label>
            <label><span class="label">Add to account *</span><select name="account_id" required class="field">@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string) old('account_id', $capital->account_id) === (string) $account->id)>{{ $account->name }}</option>@endforeach</select></label>
            <label><span class="label">Payment method *</span><select name="payment_method" required class="field">@foreach(['Cash', 'Bank Transfer'] as $method)<option @selected(old('payment_method', $capital->payment_method) === $method)>{{ $method }}</option>@endforeach</select><span class="mt-1 block text-xs text-slate-500">For a bank transfer, select Bank Account above.</span></label>
            <label><span class="label">Reference number</span><input name="reference_number" maxlength="100" value="{{ old('reference_number', $capital->reference_number) }}" class="field"></label>
        </div>
        <label class="block"><span class="label">Notes</span><textarea name="note" maxlength="1000" rows="3" class="field">{{ old('note', $capital->note) }}</textarea></label>
        <p class="rounded-xl bg-blue-50 p-4 text-sm text-blue-800">The amount increases the selected account directly. It is separate from sales income and the cash collected in the cashier.</p>
        @if($capital->exists)<label class="block"><span class="label">Reason for editing *</span><textarea name="reason" required minlength="5" maxlength="500" rows="2" class="field">{{ old('reason') }}</textarea></label>@endif
        <div class="flex flex-wrap gap-3"><button class="btn-primary">{{ $capital->exists ? 'Save Changes' : 'Add Owner Capital' }}</button><a href="{{ $capital->exists ? route('owner-capital.show', $capital) : route('owner-capital.index') }}" class="btn-secondary">Cancel</a></div>
    </form>
</div>
</x-layouts::app>
