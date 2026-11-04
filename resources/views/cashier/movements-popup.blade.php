@php
    $isAdjustment = $side === 'adjustment';
    $movementLabel = ['credit' => 'Credits / money in', 'debit' => 'Debits / money out', 'adjustment' => 'Adjustments'][$side];
    $totalColor = $isAdjustment ? ($total > 0 ? 'text-emerald-700' : ($total < 0 ? 'text-red-600' : 'text-slate-500')) : ($side === 'credit' ? 'text-emerald-700' : 'text-red-600');
@endphp
<header class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
    <div><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ \Carbon\Carbon::parse($date)->format('d M Y') }} · {{ ucfirst(session('shop_mode', 'workshop')) }}</p><h2 id="cashier-movements-title" class="mt-1 text-xl font-black">{{ $label }} · {{ $movementLabel }}</h2><p class="mt-2 text-sm text-slate-600">{{ $rows->total() }} transaction(s){{ $reconciliation != 0 ? ' + opening reconciliation' : '' }} · Total <strong class="{{ $totalColor }}">Rs. {{ $isAdjustment && $total > 0 ? '+' : '' }}{{ number_format($total, 2) }}</strong></p></div>
    <button type="button" data-close-cashier-movements class="btn-secondary" aria-label="Close transaction details">✕</button>
</header>
<p class="my-4 text-sm text-slate-500">Select an invoice, job card or source reference to open it in a new tab. {{ $isAdjustment ? 'Adjustments change the account balance and are separate from credits and debits.' : 'Adjustments and opening-balance corrections are excluded.' }}</p>
@if($isAdjustment)
    <div class="mb-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-sm">
        <h3 class="font-bold">Where this adjustment comes from</h3>
        <dl class="mt-3 space-y-2">
            @if($recordedOpening !== null)
                <div class="flex justify-between gap-4"><dt>Ledger opening, including opening corrections</dt><dd class="font-semibold">Rs. {{ number_format($ledgerOpening, 2) }}</dd></div>
                <div class="flex justify-between gap-4"><dt>− Saved opening for this date</dt><dd class="font-semibold">Rs. {{ number_format($recordedOpening, 2) }}</dd></div>
                <div class="flex justify-between gap-4 border-t pt-2"><dt>Opening reconciliation</dt><dd class="font-semibold {{ $reconciliation > 0 ? 'text-emerald-700' : ($reconciliation < 0 ? 'text-red-600' : 'text-slate-500') }}">Rs. {{ $reconciliation > 0 ? '+' : '' }}{{ number_format($reconciliation, 2) }}</dd></div>
            @endif
            <div class="flex justify-between gap-4"><dt>+ Net adjustment transactions listed below (all pages)</dt><dd class="font-semibold {{ $postedAdjustments > 0 ? 'text-emerald-700' : ($postedAdjustments < 0 ? 'text-red-600' : 'text-slate-500') }}">Rs. {{ $postedAdjustments > 0 ? '+' : '' }}{{ number_format($postedAdjustments, 2) }}</dd></div>
            <div class="flex justify-between gap-4 border-t pt-2 font-bold"><dt>Total adjustments</dt><dd class="{{ $totalColor }}">Rs. {{ $total > 0 ? '+' : '' }}{{ number_format($total, 2) }}</dd></div>
        </dl>
        <p class="mt-3 text-slate-600">Recorded cents are kept in full. Opening reconciliation and balance corrections can explain cents in the final balance; they are not new receipts or payments. Transaction notes below show saved correction reasons. To correct an unverified Main or Spare Parts total, use Edit total in Close Cashier and enter the verified amount with a reason.</p>
    </div>
@endif
<div class="overflow-x-auto rounded-xl border border-slate-200">
    <table class="w-full min-w-[720px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Time / transaction</th><th class="p-3">Description</th><th class="p-3">Invoice / job / bike</th><th class="p-3">Method</th><th class="p-3 text-right">Amount</th></tr></thead>
    <tbody class="divide-y divide-slate-200">
    @forelse($rows as $row)
        @php
            $source = $details[$row->id];
            $amount = $isAdjustment ? round((float) $row->credit - (float) $row->debit, 2) : (float) $row->{$side};
            $amountColor = $isAdjustment ? ($amount > 0 ? 'text-emerald-700' : ($amount < 0 ? 'text-red-600' : 'text-slate-500')) : ($side === 'credit' ? 'text-emerald-700' : 'text-red-600');
        @endphp
        <tr>
            <td class="p-3 align-top"><span class="block text-xs text-slate-500">{{ $row->display_time }}</span><a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="font-semibold text-blue-700 underline underline-offset-2">{{ $row->transaction_no }}</a><span class="mt-1 block text-xs text-slate-500">{{ str_replace('_', ' ', $row->transaction_type) }}</span></td>
            <td class="p-3 align-top">{{ $row->description }}@if($row->note)<span class="mt-1 block whitespace-pre-wrap text-xs text-slate-500">{{ $row->note }}</span>@endif</td>
            <td class="space-y-1 p-3 align-top">
                @if($source['invoice'])<div>@if($source['invoice_url'])<a href="{{ $source['invoice_url'] }}" target="_blank" rel="noopener" class="font-bold text-blue-700 underline underline-offset-2">{{ $source['invoice'] }}</a>@else<span class="font-bold">{{ $source['invoice'] }}</span>@endif</div>@endif
                @if($source['job'])<div>@if($source['job_url'])<a href="{{ $source['job_url'] }}" target="_blank" rel="noopener" class="text-blue-700 underline underline-offset-2">{{ $source['job'] }}</a>@else<span>{{ $source['job'] }}</span>@endif</div>@endif
                @if($source['bike'] || ($source['bike_name'] ?? null))<div class="font-semibold">Bike: {{ $source['bike_name'] ?? '' }} {{ $source['bike'] }}</div>@endif
                @unless($source['invoice'] || $source['job'])<a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-blue-700 underline underline-offset-2">{{ $source['number'] ?: 'View transaction details' }}</a>@endunless
            </td>
            <td class="p-3 align-top">{{ $row->payment_method ?: '—' }}</td>
            <td class="p-3 text-right align-top font-bold {{ $amountColor }}">{{ $isAdjustment && $amount > 0 ? '+' : '' }}{{ number_format($amount, 2) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="p-10 text-center text-slate-500">No {{ $isAdjustment ? 'adjustment transactions' : ($side === 'credit' ? 'credits' : 'debits') }} for this account on this date.</td></tr>
    @endforelse
    </tbody></table>
</div>
<div class="mt-4" data-movement-pagination>{{ $rows->links() }}</div>
