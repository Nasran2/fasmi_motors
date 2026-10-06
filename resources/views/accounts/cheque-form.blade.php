<x-layouts::app :title="$cheque->exists ? 'Edit Cheque' : 'Add Cheque'">
<div class="mx-auto max-w-5xl space-y-5 p-4 sm:p-6 lg:p-8">
    <header class="flex items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-widest text-red-500">Cheque Management</p><h1 class="mt-1 text-3xl font-black">{{ $cheque->exists ? 'Edit Cheque' : 'Add Cheque' }}</h1></div><a class="btn-secondary" href="{{ $cheque->exists ? route('accounts.cheques.show', $cheque) : route('accounts.cheques.index') }}">Back</a></header>
    @if($errors->any())<div class="rounded-xl bg-red-50 p-4 text-sm text-red-700"><ul class="list-disc pl-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($locked)<p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-800">The amount, party, type and reference are linked to a payment or settled cheque. To replace them, delete/cancel this cheque and record a new payment. You can still edit its cheque details and notes.</p>@endif
    <form method="POST" action="{{ $cheque->exists ? route('accounts.cheques.update', $cheque) : route('accounts.cheques.store') }}" class="panel space-y-5">
        @csrf @if($cheque->exists) @method('PUT') @endif <x-submission-token/>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <label class="text-sm font-bold">Type *<select name="cheque_type" class="field mt-1" required @disabled($locked)>@foreach(['Received' => 'Customer / Received', 'Issued' => 'Supplier / Issued'] as $value => $label)<option value="{{ $value }}" @selected(old('cheque_type',$cheque->cheque_type)===$value)>{{ $label }}</option>@endforeach</select>@if($locked)<input type="hidden" name="cheque_type" value="{{ $cheque->cheque_type }}">@endif</label>
            @foreach(['party_name' => 'Customer / supplier *', 'related_reference' => 'Reference', 'cheque_number' => 'Cheque number *', 'bank_name' => 'Bank name *', 'branch' => 'Branch'] as $field => $label)
            <label class="text-sm font-bold">{{ $label }}<input name="{{ $field }}" value="{{ old($field,$cheque->$field) }}" class="field mt-1" maxlength="{{ $field==='cheque_number' ? 80 : 120 }}" @required(in_array($field,['party_name','cheque_number','bank_name'])) @readonly($locked && in_array($field,['party_name','related_reference']))></label>
            @endforeach
            <label class="text-sm font-bold">Cheque date *<input type="date" name="cheque_date" value="{{ old('cheque_date',$cheque->cheque_date?->toDateString()) }}" required class="field mt-1" @readonly($cheque->exists && !in_array($cheque->status,['Pending','Deposited']))></label>
            <label class="text-sm font-bold">Amount *<input type="number" name="amount" value="{{ old('amount',$cheque->amount) }}" min="0.01" step="0.01" required class="field mt-1" @readonly($locked)></label>
            @php $accountLocked = $cheque->exists && !in_array($cheque->status,['Pending','Deposited']); @endphp
            <label class="text-sm font-bold">Bank account<select name="bank_account_id" class="field mt-1" @disabled($accountLocked)><option value="">Default bank ledger</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('bank_account_id',$cheque->bank_account_id)===(string)$account->id)>{{ $account->name }}</option>@endforeach</select>@if($accountLocked)<input type="hidden" name="bank_account_id" value="{{ $cheque->bank_account_id }}">@endif</label>
            @unless($cheque->exists)
            <label class="text-sm font-bold">Initial status<select name="status" class="field mt-1">@foreach(['Pending'=>'Pending / Hold','Deposited'=>'Deposited','Cleared'=>'Passed'] as $value=>$label)<option value="{{ $value }}" @selected(old('status','Pending')===$value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-bold">Passed date (if passed)<input type="date" name="realized_date" value="{{ old('realized_date') }}" max="{{ today()->toDateString() }}" class="field mt-1"></label>
            @endunless
        </div>
        <label class="block text-sm font-bold">Notes<textarea name="note" rows="3" maxlength="1000" class="field mt-1">{{ old('note',$cheque->note) }}</textarea></label>
        <div class="flex justify-end gap-2 border-t border-slate-100 pt-4"><a class="btn-secondary" href="{{ route('accounts.cheques.index') }}">Cancel</a><button class="btn-primary">{{ $cheque->exists ? 'Save Changes' : 'Save Cheque' }}</button></div>
    </form>
</div>
</x-layouts::app>
