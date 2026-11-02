<header class="flex items-start justify-between gap-4 border-b border-slate-200 pb-4">
    <div><p class="text-xs font-bold uppercase tracking-wide text-slate-500">{{ \Carbon\Carbon::parse($date)->format('d M Y') }} · {{ ucfirst(session('shop_mode', 'workshop')) }}</p><h2 id="cashier-movements-title" class="mt-1 text-xl font-black">{{ $labels[$card] }}</h2><p class="mt-2 text-2xl font-black text-[#0b2447]">Rs. {{ number_format($totals[$card], 2) }}</p></div>
    <button type="button" data-close-cashier-movements class="btn-secondary" aria-label="Close transaction details">✕</button>
</header>
<nav aria-label="Cash breakdown" class="my-4 flex flex-wrap gap-2">
    @foreach($labels as $key => $label)
        <a href="{{ route('cashier.card-details', ['date' => $date, 'card' => $key]) }}" data-cashier-summary-link @if($key === $card) aria-current="page" @endif class="rounded-lg border px-3 py-2 text-xs font-bold {{ $key === $card ? 'border-blue-700 bg-blue-700 text-white' : 'border-slate-200 text-blue-700 hover:bg-blue-50' }}">{{ $label }}</a>
    @endforeach
</nav>
@if($card === 'balance')
    <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm">
        <p>Opening cash <b>Rs. {{ number_format($summary['cash_opening'], 2) }}</b> + cash collected <b>Rs. {{ number_format($summary['cash_received'], 2) }}</b> + other cash in <b>Rs. {{ number_format($summary['cash_other_in'], 2) }}</b> − cash paid out <b>Rs. {{ number_format($summary['cash_paid'], 2) }}</b>@if($summary['separate_cash_balance'] && $summary['drawer_adjustment'] != 0) + recorded cash count adjustment <b>Rs. {{ number_format($summary['drawer_adjustment'], 2) }}</b>@endif.</p>
        <p class="mt-2 font-black">Cash in hand: Rs. {{ number_format($totals['balance'], 2) }}</p>
    </div>
@endif
<p class="mb-4 text-sm text-slate-600">
    @if($card === 'opening') This is the recorded physical opening cash. Main, Spare Parts and Bank account balances are separate. Opening history below is an audit trail; its counts are not added together.
    @elseif($card === 'received') {{ $rows->total() }} cash receipt(s). Each receipt is counted once, including washing. Bank receipts are excluded.
    @elseif($card === 'paid') {{ $rows->total() }} cash payment allocation(s). Split payments show the amount taken from each cashier account. Reversed entries are netted; internal washing settlement is excluded.
    @else All physical cash movements for this date, including washing. Bank and carried account balances are excluded.
    @endif
    Select a source link to open it in a new tab.
</p>
<div class="overflow-x-auto rounded-xl border border-slate-200">
    <table class="w-full min-w-[680px] text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Time / reference</th><th class="p-3">Details / notes</th><th class="p-3">Invoice / job / source</th><th class="p-3 text-right">Amount</th>@if($card === 'balance')<th class="p-3 text-right">Cash balance</th>@endif</tr></thead>
        <tbody class="divide-y divide-slate-200">
        @forelse($rows as $row)
            <tr>
                <td class="p-3 align-top"><span class="block text-xs text-slate-500">{{ $row['date']->format('d M, h:i A') }}</span><span class="font-bold">{{ $row['number'] }}</span><span class="mt-1 block text-xs text-slate-500">{{ $row['account'] }}</span></td>
                <td class="p-3 align-top">{{ $row['description'] }}@if($row['note'])<p class="mt-1 whitespace-pre-wrap text-xs text-slate-600">{{ $row['note'] }}</p>@endif
                    @foreach(collect($row['sources'])->pluck('bike')->filter()->unique() as $bike)<p class="mt-2 font-bold text-blue-800">Bike number: {{ $bike }}</p>@endforeach
                </td>
                <td class="space-y-2 p-3 align-top">
                    @forelse($row['sources'] as $source)
                        <div>
                            @if($source['invoice'] ?? null)<div>@if($source['invoice_url'])<a href="{{ $source['invoice_url'] }}" target="_blank" rel="noopener" class="font-bold text-blue-700 underline">{{ $source['invoice'] }}</a>@else {{ $source['invoice'] }} @endif</div>@endif
                            @if($source['job'] ?? null)<div>@if($source['job_url'])<a href="{{ $source['job_url'] }}" target="_blank" rel="noopener" class="text-blue-700 underline">{{ $source['job'] }}</a>@else {{ $source['job'] }} @endif</div>@endif
                            @unless(($source['invoice'] ?? null) || ($source['job'] ?? null))<a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="text-blue-700 underline">{{ ($source['number'] ?? null) ?: 'View source details' }}</a>@endunless
                        </div>
                    @empty <span class="text-slate-400">—</span>
                    @endforelse
                </td>
                <td class="p-3 text-right align-top font-bold {{ $row['amount'] < 0 ? 'text-red-600' : 'text-emerald-700' }}">{{ $card === 'paid' ? '' : ($row['amount'] < 0 ? '− ' : '+ ') }}{{ number_format(abs($row['amount']), 2) }}</td>
                @if($card === 'balance')<td class="p-3 text-right align-top font-black">{{ number_format($row['running_balance'], 2) }}</td>@endif
            </tr>
        @empty
            <tr><td colspan="{{ $card === 'balance' ? 5 : 4 }}" class="p-8 text-center text-slate-500">No cash movements for this date.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4" data-movement-pagination>{{ $rows->links() }}</div>
@if($events->isNotEmpty())
    <section class="mt-5"><h3 class="font-black">Opening history</h3><div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-3">Recorded at / action</th><th class="p-3">Cash counted</th><th class="p-3">Recorded by / reason</th></tr></thead><tbody class="divide-y">
        @foreach($events as $event)<tr><td class="p-3">{{ $event->created_at->format('d M Y, h:i A') }}<span class="block text-xs text-slate-500">{{ ucfirst(str_replace('_', ' ', $event->action)) }}</span></td><td class="p-3 font-bold">Rs. {{ number_format($event->cash_amount, 2) }}</td><td class="p-3">{{ $event->creator?->name ?? '—' }}<p class="mt-1 whitespace-pre-wrap text-xs text-slate-600">{{ $event->details['reason'] ?? '—' }}</p></td></tr>@endforeach
    </tbody></table></div></section>
@endif
