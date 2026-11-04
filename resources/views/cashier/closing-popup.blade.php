<div data-cashier-closing-content class="space-y-5">
    <header class="flex items-start justify-between gap-4 border-b pb-4">
        <div>
            <p class="text-xs font-black uppercase tracking-widest text-amber-700">{{ \Carbon\Carbon::parse($date)->format('l, d M Y') }} · {{ ucfirst(session('shop_mode', 'workshop')) }}</p>
            <h2 id="cashier-closing-title" class="mt-2 text-2xl font-black">{{ $liveClosing ? 'Close Cashier' : 'Closing details & history' }}</h2>
            <p class="mt-2 text-sm text-slate-500">{{ $liveClosing ? 'Review this cash date and close it manually. No automatic closing count is entered.' : 'Saved closing figures, account allocations and the transactions behind this cash date.' }}</p>
        </div>
        <button type="button" class="btn-secondary" data-close-cashier-closing aria-label="Close cashier details">✕</button>
    </header>
    @if($liveClosing)
    @unless($isToday)<p role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 font-semibold text-amber-950">This cashier was left open on {{ \Carbon\Carbon::parse($date)->format('l, d M Y') }}. Enter the amounts for that date, then close it below. Today can be opened afterwards. New payments outside this closing still require today’s cashier to be open.</p>@endunless
    @include('cashier.close-form')
    @else
    @if($event)<div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Historical record: {{ $event->created_at->format('d M Y, h:i A') }} · {{ $event->creator?->name ?? '—' }}. This original event is preserved. <button type="button" data-cashier-closing="{{ route('cashier.closing-popup', $session) }}" class="font-bold underline">View latest closing / edit</button></div>@endif
    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4">
            <p class="label">Saved cash left in cashier</p>
            <p class="text-2xl font-black text-emerald-800">{{ $savedCash !== null ? 'Rs. '.number_format($savedCash, 2) : 'Not recorded' }}</p>
        </div>
        <div class="rounded-xl border p-4">
            <p class="label">Withdrawal at this close</p>
            <p class="text-xl font-bold">Rs. {{ number_format($event?->details['owner_withdrawal'] ?? $summary['closing_withdrawal'] ?? 0, 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">Total withdrawn for date: Rs. {{ number_format($session->owner_withdrawal, 2) }}</p>
        </div>
        <div class="rounded-xl border p-4">
            <p class="label">Washing transferred to Main</p>
            <p class="text-xl font-bold">Rs. {{ number_format($event?->details['washing_to_main'] ?? $summary['closing_washing_transfer'] ?? 0, 2) }}</p>
            <p class="mt-1 text-xs text-slate-500">{{ $event?->washingPerson?->name ?? $session->washingPerson?->name ?? 'Unassigned' }} · {{ ($event?->washing_commission_amount ?? $session->washing_commission_amount) !== null ? 'Rs. '.number_format($event?->washing_commission_amount ?? $session->washing_commission_amount, 2).' per job' : 'Legacy commission' }}</p>
        </div>
    </div>
    @if(isset($summary['closing_washing_cash_transfer']))<p class="text-xs text-slate-600">Washing settlement: cash Rs. {{ number_format($summary['closing_washing_cash_transfer'], 2) }} · non-cash Rs. {{ number_format($summary['closing_washing_non_cash_transfer'] ?? 0, 2) }}. Bank balance is unchanged by this settlement.</p>@endif
    <p class="rounded-xl bg-slate-50 p-3 text-sm">Cash received for this date: <strong>{{ isset($summary['cash_received']) ? 'Rs. '.number_format($summary['cash_received'], 2) : 'Not recorded' }}</strong> · Bank received: <strong>{{ isset($summary['bank_received']) ? 'Rs. '.number_format($summary['bank_received'], 2) : 'Not recorded' }}</strong>. {{ $event ? 'Recorded at '.$event->created_at->format('d M Y, h:i A') : 'Closed at '.($session->closed_at?->format('d M Y, h:i A') ?? 'Not recorded') }}</p>
    @if(isset($summary['closing_expected_cash']))<p class="rounded-xl bg-slate-50 p-3 text-sm">Expected cash remaining: <strong>Rs. {{ number_format($summary['closing_expected_cash'], 2) }}</strong> · Count difference: <strong>Rs. {{ number_format($summary['closing_cash_difference'] ?? 0, 2) }}</strong>.</p>@endif
    <div class="overflow-x-auto rounded-xl border">
        <table class="w-full min-w-[650px] text-right text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="p-3 text-left">Account</th>
                    <th class="p-3">Opening</th>
                    <th class="p-3">Credits</th>
                    <th class="p-3">Debits</th>
                    <th class="p-3">Saved balance</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach(\App\Services\CashierService::ACCOUNTS as $type => $label)<tr>
                    <th class="p-3 text-left">{{ $label }}</th>@foreach(['opening', 'income', 'outgoing'] as $column)<td class="p-3">{{ isset($summary['closing_account_totals'][$type][$column]) ? number_format($summary['closing_account_totals'][$type][$column], 2) : (isset($summary['accounts'][$type][$column]) ? number_format($summary['accounts'][$type][$column], 2) : 'Not recorded') }}</td>@endforeach<td class="p-3 font-black">{{ isset($savedBalances[$type]) ? number_format($savedBalances[$type], 2) : 'Not recorded' }}</td>
                </tr>@endforeach
            </tbody>
        </table>
    </div>
    @if($summary['closing_withdrawal_funding'] ?? [])<div class="rounded-xl bg-slate-50 p-3 text-sm">
        <p class="font-bold">Withdrawal sources</p>@foreach($summary['closing_withdrawal_funding'] as $fund)<p>{{ \App\Services\OutgoingFundingService::SOURCES[$fund['source']] ?? $fund['source'] }}: Rs. {{ number_format($fund['amount'], 2) }}</p>@endforeach
    </div>@endif
    @if($summary['closing_correction_reason'] ?? $event?->details['reason'] ?? $session->notes)<p class="rounded-xl bg-amber-50 p-3 text-sm"><strong>Reason / note:</strong> {{ $summary['closing_correction_reason'] ?? $event?->details['reason'] ?? $session->notes }}</p>@endif
    @if($canEdit)
    @php($allocationMode = is_array($summary['closing_account_additions'] ?? null))
    <details class="rounded-xl border-2 border-amber-300 p-4" @if($savedCash===null) open @endif>
        <summary class="cursor-pointer font-black text-amber-900">Edit saved closing balances</summary>
        <form method="POST" action="{{ route('cashier.closing-balances', $session) }}" data-cashier-closing-correction class="mt-4 space-y-4" data-available="{{ ($summary['cashier_funds']['total'] ?? 0) - ($summary['closing_cash_withdrawal'] ?? 0) }}">
            @csrf<x-submission-token /><input type="hidden" name="closing_version" value="{{ $closingVersion }}">
            <p class="text-sm text-slate-600">A reason is required. Original closings and later recorded opening/closing counts stay in history. Payments and withdrawals already posted are listed below; this form corrects counts and account allocations without paying them again.</p>
            <label class="block rounded-xl bg-blue-50 p-4"><span class="label">Cash left in cashier at closing · LKR</span><input name="closing_balance" class="field text-xl font-black" type="number" min="0" max="999999999999.99" step="0.01" required value="{{ $savedCash }}"></label>
            <div class="grid gap-4 sm:grid-cols-2">
                @foreach(\App\Services\CashierService::ACCOUNTS as $type => $label)
                <label class="rounded-xl border p-3">
                    @if($allocationMode && in_array($type, ['main', 'spare_parts']))
                    <span class="label">Amount added to {{ $label }} at this close · LKR</span><input name="account_additions[{{ $type }}]" data-closing-addition data-type="{{ $type }}" data-base="{{ $summary['closing_allocation_bases'][$type] ?? 0 }}" type="number" min="0" max="999999999999.99" step="0.01" required value="{{ $summary['closing_account_additions'][$type] }}" class="field">
                    <span class="mt-2 block text-xs text-slate-500">Carried account balance / other movements: Rs. {{ number_format($summary['closing_allocation_bases'][$type] ?? 0, 2) }}</span>
                    <span class="mt-2 block text-sm font-bold">Final account balance · LKR</span><input name="balances[{{ $type }}]" type="number" step="0.01" readonly required value="{{ $savedBalances[$type] ?? '' }}" class="field mt-1 bg-slate-50">
                    @else
                    <span class="label">{{ $label }} saved closing balance · LKR</span><input name="balances[{{ $type }}]" type="number" min="-999999999999.99" max="999999999999.99" step="0.01" required value="{{ $savedBalances[$type] ?? '' }}" class="field">
                    @endif
                </label>
                @endforeach
            </div>
            <p class="text-sm font-bold text-amber-900" data-closing-edit-total aria-live="polite"></p>
            <label class="block"><span class="label">Reason for correcting this closing</span><textarea name="reason" rows="2" minlength="3" maxlength="1000" required class="field"></textarea></label>
            <button type="submit" class="btn-primary">Save closing correction</button>
        </form>
    </details>
    @endif
    @endif
    <section class="space-y-3">
        <h3 class="text-lg font-black">{{ isset($summary['closing_transaction_ids']) ? 'Transactions saved with this closing' : 'Transactions for this cash date' }}</h3>
        <p class="text-xs text-slate-500">Bank and account movements are shown separately. Select a source to open its invoice, job card or payment in a new tab.</p>
        <div class="max-h-[430px] overflow-auto rounded-xl border">
            <table class="w-full min-w-[750px] text-left text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="p-3">Account / reference</th>
                        <th class="p-3">Description / invoice / bike</th>
                        <th class="p-3">Method / source</th>
                        <th class="p-3 text-right">In</th>
                        <th class="p-3 text-right">Out</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse($rows as $row) @php($source = $details[$row->id])<tr>
                        <td class="p-3"><span class="block text-xs text-slate-500">{{ $row->account?->name }} · {{ $row->display_time }}</span><a href="{{ $source['url'] }}" target="_blank" rel="noopener" class="font-bold text-blue-700 underline">{{ $row->transaction_no }}</a></td>
                        <td class="p-3">{{ $row->description }}@if($source['invoice'])<a href="{{ $source['invoice_url'] ?? $source['url'] }}" target="_blank" rel="noopener" class="block font-bold text-blue-700 underline">{{ $source['invoice'] }}</a>@endif @if($source['job'])<a href="{{ $source['job_url'] ?? $source['url'] }}" target="_blank" rel="noopener" class="block text-blue-700 underline">{{ $source['job'] }}</a>@endif @if($source['bike'])<span class="block font-bold">Bike: {{ $source['bike'] }}</span>@endif</td>
                        <td class="p-3">{{ $row->payment_method }}<span class="block text-xs text-slate-500">{{ \App\Services\OutgoingFundingService::SOURCES[$row->funding_source] ?? '' }}</span></td>
                        <td class="p-3 text-right text-emerald-700">{{ number_format($row->credit, 2) }}</td>
                        <td class="p-3 text-right text-red-600">{{ number_format($row->debit, 2) }}</td>
                    </tr>@empty<tr>
                        <td colspan="5" class="p-6 text-center text-slate-500">No recorded transactions for this cash date.</td>
                    </tr>@endforelse
                </tbody>
            </table>
        </div>
        <div data-closing-pagination>{{ $rows->links() }}</div>
    </section>
    @if($audit->isNotEmpty())<section>
        <h3 class="text-lg font-black">Closing audit history</h3>
        <div class="mt-3 max-h-60 space-y-2 overflow-auto">@foreach($audit as $item)<div class="rounded-xl border p-3 text-sm">@if($item->action === 'account_balance_corrected')<p class="font-bold text-amber-800">{{ $item->details['label'] }} total corrected · {{ $item->created_at->format('d M Y, h:i A') }}</p>
                <p>Rs. {{ number_format($item->details['previous_total'], 2) }} → Rs. {{ number_format($item->details['corrected_total'], 2) }}</p>@else<button type="button" data-cashier-closing="{{ route('cashier.closing-popup', ['cashier' => $session, 'event' => $item->id]) }}" class="font-bold text-blue-700 underline">{{ $item->action === 'closed' ? 'Closed' : 'Closing corrected' }} · {{ $item->created_at->format('d M Y, h:i A') }}</button>@endif<p>Rs. {{ number_format($item->cash_amount, 2) }} · {{ $item->creator?->name ?? '—' }}</p>@if($item->details['reason'] ?? null)<p class="text-slate-500">{{ $item->details['reason'] }}</p>@endif @if($item->details['later_recorded_opening_preserved'] ?? null)<p class="text-xs text-amber-800">Recorded opening on {{ $item->details['later_recorded_opening_preserved'] }} preserved.</p>@endif
            </div>@endforeach</div>
    </section>@endif
    <footer class="flex flex-wrap justify-end gap-3 border-t pt-4"><a class="btn-secondary" href="{{ route('cashier.index', ['date' => $date]) }}">Open full cash day</a><button type="button" data-close-cashier-closing class="btn-secondary">Close details</button></footer>
</div>