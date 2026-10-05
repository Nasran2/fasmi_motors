@props(['cheques'])
<section class="panel space-y-4">
    <div class="flex flex-wrap justify-between gap-2"><div><h2 class="text-lg font-extrabold">Cheques to Review</h2><p class="text-xs text-slate-500">Cheques due within two days and overdue cheques. Hold becomes paid when passed; returned amounts become due.</p></div><a class="btn-secondary" href="{{ route('accounts.cheques.index') }}">Cheque Register</a></div>
    <div class="grid gap-4 xl:grid-cols-2">
    @foreach(['customer'=>'Customer Cheques','supplier'=>'Supplier Cheques'] as $group=>$title)
        @php $list=$cheques->filter(fn($c)=>$group==='customer' ? $c->cheque_type==='Received' : ($c->cheque_type==='Issued' || $c->forwarded_supplier_id)); @endphp
        <div class="rounded-xl border border-slate-200 p-4"><h3 class="font-bold mb-3">{{ $title }} <span class="text-amber-600">({{ $list->count() }})</span></h3>
            @forelse($list as $cheque)
                <div class="border-t border-slate-100 py-3 space-y-2"><div class="flex flex-wrap justify-between gap-2"><div><b>{{ $cheque->party_name }} · {{ $cheque->cheque_number }}</b><small class="block text-slate-500">{{ $cheque->bank_name }} · {{ $cheque->cheque_date->format('d M Y') }} · {{ $cheque->related_reference }}</small>@if($cheque->forwarded_supplier_id)<small class="block font-bold text-violet-600">{{ $cheque->party_name }} → {{ $cheque->forwardedSupplier?->name }} (Customer → Supplier)</small>@endif</div><b class="text-amber-600">Rs. {{ number_format($cheque->amount,2) }} Hold</b></div>
                <div class="flex flex-wrap gap-2">
                    <form method="POST" action="{{ route('accounts.cheques.status',$cheque) }}">@csrf @method('PATCH')<x-submission-token/><input type="hidden" name="status" value="Cleared"><input type="hidden" name="realized_date" value="{{ today()->toDateString() }}"><button class="btn-secondary !text-emerald-700 disabled:opacity-40" @disabled($cheque->cheque_date->isFuture())>Pass</button></form>
                    <form method="POST" action="{{ route('accounts.cheques.status',$cheque) }}" class="flex gap-2">@csrf @method('PATCH')<x-submission-token/><input type="hidden" name="status" value="Returned"><input class="field !w-40 !text-xs" name="reason" placeholder="Return reason" minlength="5" maxlength="500" required><button class="btn-secondary !text-red-600">Return</button></form>
                </div></div>
            @empty<p class="text-sm text-slate-400">No cheques due for review.</p>@endforelse
        </div>
    @endforeach
    </div>
</section>
