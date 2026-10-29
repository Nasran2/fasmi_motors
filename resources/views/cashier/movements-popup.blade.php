<header class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
    <div><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ \Carbon\Carbon::parse($date)->format('d M Y') }} · {{ ucfirst(session('shop_mode', 'workshop')) }}</p><h2 id="cashier-movements-title" class="mt-1 text-xl font-black">{{ $label }} · {{ $side === 'credit' ? 'Credits / money in' : 'Debits / money out' }}</h2><p class="mt-2 text-sm text-slate-600">{{ $rows->total() }} transaction(s) · Total <strong class="{{ $side === 'credit' ? 'text-emerald-700' : 'text-red-600' }}">Rs. {{ number_format($total, 2) }}</strong></p></div>
    <button type="button" data-close-cashier-movements class="btn-secondary" aria-label="Close transaction details">✕</button>
</header>
<p class="my-4 text-sm text-slate-500">Select an invoice, job card or source reference to open it in a new tab. Opening-balance corrections are excluded.</p>
<div class="overflow-x-auto rounded-xl border border-slate-200">
    <table class="w-full min-w-[720px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Time / transaction</th><th class="p-3">Description</th><th class="p-3">Invoice / job / bike</th><th class="p-3">Method</th><th class="p-3 text-right">Amount</th></tr></thead>
    <tbody class="divide-y divide-slate-200">
    @forelse($rows as $row)
        @php($source = $details[$row->id])
        <tr>
            <td class="p-3 align-top"><span class="block text-xs text-slate-500">{{ $row->transaction_date->format('h:i A') }}</span><a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="font-semibold text-blue-700 underline underline-offset-2">{{ $row->transaction_no }}</a><span class="mt-1 block text-xs text-slate-500">{{ str_replace('_', ' ', $row->transaction_type) }}</span></td>
            <td class="p-3 align-top">{{ $row->description }}@if($row->note)<span class="mt-1 block whitespace-pre-wrap text-xs text-slate-500">{{ $row->note }}</span>@endif</td>
            <td class="space-y-1 p-3 align-top">
                @if($source['invoice'])<div>@if($source['invoice_url'])<a href="{{ $source['invoice_url'] }}" target="_blank" rel="noopener" class="font-bold text-blue-700 underline underline-offset-2">{{ $source['invoice'] }}</a>@else<span class="font-bold">{{ $source['invoice'] }}</span>@endif</div>@endif
                @if($source['job'])<div>@if($source['job_url'])<a href="{{ $source['job_url'] }}" target="_blank" rel="noopener" class="text-blue-700 underline underline-offset-2">{{ $source['job'] }}</a>@else<span>{{ $source['job'] }}</span>@endif</div>@endif
                @if($source['bike'])<div class="font-semibold">Bike: {{ $source['bike'] }}</div>@endif
                @unless($source['invoice'] || $source['job'])<a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-blue-700 underline underline-offset-2">{{ $source['number'] ?: 'View transaction details' }}</a>@endunless
            </td>
            <td class="p-3 align-top">{{ $row->payment_method ?: '—' }}</td>
            <td class="p-3 text-right align-top font-bold {{ $side === 'credit' ? 'text-emerald-700' : 'text-red-600' }}">{{ number_format($row->{$side}, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="p-10 text-center text-slate-500">No {{ $side === 'credit' ? 'credits' : 'debits' }} for this account on this date.</td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4" data-movement-pagination>{{ $rows->links() }}</div>
