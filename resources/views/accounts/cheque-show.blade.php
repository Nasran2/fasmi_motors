<x-layouts::app :title="'Cheque '.$cheque->cheque_number">
<div class="mx-auto max-w-6xl space-y-5 p-4 sm:p-6 lg:p-8">
    <header class="flex flex-wrap items-center justify-between gap-3"><div><p class="text-xs font-bold uppercase tracking-widest text-red-500">Cheque Management</p><h1 class="mt-1 text-3xl font-black">Cheque {{ $cheque->cheque_number }}</h1></div><div class="flex gap-2"><a class="btn-secondary" href="{{ route('accounts.cheques.index') }}">All Cheques</a>@unless($cheque->trashed())<a class="btn-primary" href="{{ route('accounts.cheques.edit',$cheque) }}">Edit Cheque</a>@endunless</div></header>
    @if(session('success'))<div class="rounded-xl bg-emerald-50 p-3 font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <section class="panel space-y-5">
        <div class="flex items-center justify-between border-b border-slate-100 pb-4"><p class="text-3xl font-black">Rs. {{ number_format($cheque->amount,2) }}</p><x-cheque-status :cheque="$cheque"/></div>
        <dl class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            @foreach(['Type' => $cheque->cheque_type, 'Customer / Supplier' => $cheque->party_name, 'Bank' => $cheque->bank_name, 'Branch' => $cheque->branch, 'Cheque date' => $cheque->cheque_date->format('d M Y'), 'Passed date' => $cheque->realized_date?->format('d M Y'), 'Bank account' => $cheque->bankAccount?->name ?? 'Default bank ledger', 'Reference' => $cheque->related_reference, 'Recorded by' => $cheque->creator?->name] as $label=>$value)<div><dt class="text-xs font-bold text-slate-500">{{ $label }}</dt><dd class="mt-1 text-sm font-semibold">{{ $value ?: '—' }}</dd></div>@endforeach
        </dl>
        @if($cheque->forwarded_supplier_id)<p class="rounded-xl bg-violet-50 p-3 text-sm font-bold text-violet-700">{{ $cheque->party_name }} → {{ $cheque->forwardedSupplier?->name }} (Customer → Supplier)</p>@endif
        @if($cheque->status_reason)<div class="rounded-xl bg-slate-50 p-3 text-sm"><b>Reason:</b> {{ $cheque->status_reason }}</div>@endif
        @if($cheque->note)<div class="text-sm whitespace-pre-line"><b>Notes:</b> {{ $cheque->note }}</div>@endif
    </section>
    @unless($cheque->trashed())
    <section class="panel space-y-4"><h2 class="text-lg font-bold">Manage Status</h2><p class="text-sm text-slate-500">Passing moves hold to paid. Returning or cancelling restores due and reverses any cleared funds.</p>
        @if(in_array($cheque->status,['Pending','Deposited']))
        <form method="POST" action="{{ route('accounts.cheques.status',$cheque) }}" class="flex flex-wrap items-end gap-3">@csrf @method('PATCH') <x-submission-token/><input type="hidden" name="status" value="Cleared"><label class="text-sm font-bold">Passed date<input type="date" name="realized_date" value="{{ today()->toDateString() }}" min="{{ $cheque->cheque_date->toDateString() }}" max="{{ today()->toDateString() }}" required class="field mt-1"></label><button class="btn-primary disabled:opacity-40" @disabled($cheque->cheque_date->isFuture())>Mark Passed</button>@if($cheque->cheque_date->isFuture())<p class="text-xs text-amber-700">Can pass on or after {{ $cheque->cheque_date->format('d M Y') }}.</p>@endif</form>
        @endif
        @if($cheque->status === 'Pending')<form method="POST" action="{{ route('accounts.cheques.status',$cheque) }}">@csrf @method('PATCH') <x-submission-token/><input type="hidden" name="status" value="Deposited"><button class="btn-secondary">Mark Deposited</button></form>@endif
        @if(in_array($cheque->status,['Pending','Deposited','Cleared']))
        <form method="POST" action="{{ route('accounts.cheques.status',$cheque) }}" class="flex flex-wrap items-end gap-3">@csrf @method('PATCH') <x-submission-token/><label class="text-sm font-bold">Action<select name="status" class="field mt-1"><option value="Returned">Return cheque</option><option value="Cancelled">Cancel cheque</option></select></label><label class="min-w-48 flex-1 text-sm font-bold">Reason<input name="reason" minlength="5" maxlength="500" required placeholder="Why was this cheque returned or cancelled?" class="field mt-1"></label><button class="btn-secondary !text-red-600">Update Status</button></form>
        @else<p class="text-sm text-slate-500">This cheque is {{ strtolower($cheque->status) }}. Its settlement is complete.</p>@endif
    </section>
    @endunless
    @if($cheque->payments->isNotEmpty() || $cheque->supplierPayments->isNotEmpty() || $cheque->jobAdvances->whereNull('payment_id')->isNotEmpty())
    <section class="panel space-y-3"><h2 class="text-lg font-bold">Linked Payments</h2><div class="grid gap-3 sm:grid-cols-2">
        @foreach($cheque->payments as $payment)<a href="{{ route('sales.invoices.show',$payment->invoice_id) }}" class="rounded-xl border border-slate-200 p-3 text-sm"><b>{{ $payment->invoice?->number }} · Customer payment</b><p class="mt-1 text-slate-500">Rs. {{ number_format($payment->amount,2) }} · {{ $payment->status }}</p></a>@endforeach
        @foreach($cheque->jobAdvances->whereNull('payment_id') as $advance)<a href="{{ route('jobs.show', $advance->job_card_id) }}" class="rounded-xl border border-slate-200 p-3 text-sm"><b>{{ $advance->jobCard?->number }} · Job advance</b><p class="mt-1 text-slate-500">Rs. {{ number_format($advance->amount,2) }} · {{ $advance->status }}</p></a>@endforeach
        @foreach($cheque->supplierPayments as $payment)<a href="{{ route('purchases.show',$payment->purchase_id) }}" class="rounded-xl border border-slate-200 p-3 text-sm"><b>{{ $payment->purchase?->number }} · Supplier payment</b><p class="mt-1 text-slate-500">Rs. {{ number_format($payment->amount,2) }} · {{ $payment->status }}</p></a>@endforeach
    </div></section>
    @endif
    <section class="panel space-y-3"><h2 class="text-lg font-bold">Cheque History</h2>
        @forelse($history as $entry)<div class="border-t border-slate-100 pt-3"><div class="flex flex-wrap justify-between gap-2 text-sm"><b>{{ ucfirst($entry->event) }} · {{ $users[$entry->user_id] ?? 'System' }}</b><time class="text-xs text-slate-500">{{ $entry->created_at->format('d M Y, h:i A') }}</time></div><div class="mt-2 grid gap-1 text-xs text-slate-500 sm:grid-cols-2">@foreach($entry->new_values ?? [] as $field=>$value)@if(in_array($field,['status','amount','cheque_number','cheque_date','bank_name','branch','bank_account_id','party_name','related_reference','realized_date','status_reason','note','forwarded_supplier_id']))<p><b>{{ ucfirst(str_replace('_',' ',$field)) }}:</b> @if($entry->event==='updated' && isset($entry->old_values[$field])){{ $entry->old_values[$field] }} → @endif{{ $field==='status' && $value==='Cleared' ? 'Passed' : $value }}</p>@endif@endforeach</div></div>@empty<p class="text-sm text-slate-500">No history recorded.</p>@endforelse
    </section>
    @unless($cheque->trashed())
    <section class="panel space-y-3 border-red-100"><h2 class="text-lg font-bold text-red-600">Delete Cheque</h2><p class="text-sm text-slate-500">This removes the cheque from active lists. Any linked hold or paid amount becomes due and bank entries are reversed. The cheque remains in Deleted Cheques with its history.</p>
        <form method="POST" action="{{ route('accounts.cheques.destroy',$cheque) }}" x-data @submit="if (!confirm('Delete this cheque and restore any linked dues?')) $event.preventDefault()" class="flex flex-wrap items-end gap-3">@csrf @method('DELETE') <x-submission-token/><label class="flex-1 text-sm font-bold">Deletion reason<input name="reason" minlength="5" maxlength="500" required class="field mt-1" placeholder="Enter reason for deletion"></label><button class="btn-primary">Delete Cheque</button></form>
    </section>
    @endunless
</div>
</x-layouts::app>
