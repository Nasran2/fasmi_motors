<x-layouts::app title="Open / Close Cashier">
    @php
    $independent = $summary['separate_cash_balance'];
    $labels = \App\Services\CashPositionService::ACCOUNTS;
    $expectedBalances = collect($summary['cash_accounts'])->map(fn ($account) => $account['closing'])->all();
    $expectedBalances['main'] = $summary['accounts']['main']['closing'];
    $expectedBalances['spare_parts'] = $summary['accounts']['spare_parts']['closing'];
    $expectedBalances['bank'] = $summary['accounts']['bank']['closing'];
    $expectedBalances['washing'] = $summary['accounts']['washing']['closing'];
    $isToday = $date === today()->toDateString();
    @endphp
    <div class="mx-auto max-w-7xl space-y-6 p-4 sm:p-6 lg:p-8">
        <section class="rounded-2xl border-2 {{ $session?->status === 'open' ? ($session->session_date->isToday() ? 'border-emerald-300' : 'border-amber-400') : 'border-amber-300' }} bg-[#0b2447] p-6 text-white shadow-lg">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <p class="text-xs font-bold uppercase tracking-widest text-slate-300">{{ ucfirst(session('shop_mode', 'workshop')) }} · {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</p>
                    <h1 class="mt-2 text-3xl font-black">Open / Close Cashier</h1>
                    <p class="mt-2 text-sm text-slate-200">Cash collections and cash in hand. Bike intake remains available when closed.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3"><x-collection-control /><a href="#cashier-history" class="rounded-xl bg-white px-4 py-3 text-sm font-bold text-blue-900">Closing history</a>@if($session?->status === 'open')@if($session->session_date->isToday())<span class="rounded-xl bg-emerald-400 px-5 py-3 text-sm font-black text-emerald-950">● CASHIER OPEN</span>@else<span class="rounded-xl bg-amber-400 px-5 py-3 text-sm font-black text-amber-950">● OPEN ({{ $session->session_date->format('d M') }} — PREVIOUS DAY)</span>@endif @else<span class="rounded-xl bg-amber-300 px-5 py-3 text-sm font-black text-amber-950">● CASHIER CLOSED</span>@endif</div>
            </div>
        </section>
        <x-flash />
        @if($canCorrectOpening || $canRecordOpening)
        <div class="flex justify-end"><button type="button" data-cashier-open="{{ route('cashier.opening-popup', ['edit' => 1, 'date' => $date]) }}" class="btn-primary">{{ $canRecordOpening ? 'Record Opening Balances' : 'Edit Opening Balances' }}</button></div>
        @endif
        @if($canCorrectOpening && ! $independent && collect($summary['cash_accounts'])->contains(fn ($account) => $account['opening'] < 0))
            <p role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">An opening cash account is negative. Use Edit Opening Balances to enter the verified cash held at the start of this date and explain the correction.</p>
            @endif
            @if($closing && $closing->cash_actual_balance !== null && $events->contains('action', 'opening_corrected'))
            <section class="panel border border-amber-300">
                <h2 class="text-lg font-black">Closing reconciliation after opening correction</h2>
                <p class="mt-2 text-sm text-slate-600">Recorded closing cash: Rs. {{ number_format($closing->cash_actual_balance, 2) }} · Expected closing: Rs. {{ number_format($closing->cash_closing_balance, 2) }} · Difference: Rs. {{ number_format($closing->cash_difference, 2) }}. Recorded cash counts and receipts are preserved.</p>
                <div class="mt-3 flex flex-wrap gap-4 text-sm">@foreach($labels as $type => $label)<span>{{ $label }} difference: Rs. {{ number_format($closing->{$type.'_difference'}, 2) }}</span>@endforeach</div>
            </section>
            @endif
            @if($active && !$active->session_date->isToday())
            <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <span>The cashier for {{ $active->session_date->format('d M Y') }} is still open. You can complete yesterday’s pending bills and sales now, and then close this session when finished.</span>
                    <div class="flex items-center gap-2">
                        <a href="{{ route('sales.job-billing') }}" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-slate-800 border shadow-sm hover:bg-slate-50">Go to Job Billing</a>
                        <button type="button" class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-amber-700" data-cashier-closing="{{ route('cashier.closing-popup', $active) }}">Close {{ $active->session_date->format('d M') }} Session</button>
                    </div>
                </div>
            </div>
            @endif
            @if($isToday && !$active)
            <section class="panel flex flex-wrap items-center justify-between gap-4 border-2 border-amber-300">
                <div>
                    <h2 class="text-xl font-black">Open Cashier</h2>
                    <p class="mt-1 text-sm text-slate-500">Enter the opening cash and select today’s washing person. Open and close as many times as needed today.</p>
                </div><button type="button" data-cashier-open="{{ route('cashier.opening-popup') }}" class="btn-primary">Open Cashier & Start Day</button>
            </section>
            @if($errors->hasAny(['opening_balance', 'opening_reason', 'washing_person_id', 'washing_commission_amount']))<span data-auto-open-cashier="{{ route('cashier.opening-popup') }}"></span>@endif
            @endif
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
                @if($session?->washingPerson)<p class="font-semibold">Today’s washing person: {{ $session->washingPerson->name }} · Washing commission earned: Rs. {{ number_format($session->washing_commission_amount, 2) }} per job</p>@endif
                <p class="{{ $session?->washingPerson ? 'mt-2' : '' }}">Job and washing commissions are not deducted automatically from cash or account balances. Money is deducted only when you manually record a commission payment or expense.</p>
            </div>
            <form method="GET" class="flex flex-wrap items-end gap-3"><label><span class="label">Cash day</span><input type="date" name="date" value="{{ $date }}" max="{{ today()->toDateString() }}" class="field"></label><button class="btn-secondary">Load date</button></form>
            @if($independent && $summary['drawer_adjustment'] != 0)<p class="rounded-xl bg-blue-50 p-4 text-sm text-blue-900">Cash count adjustment: Rs. {{ number_format($summary['drawer_adjustment'], 2) }}. This reflects recorded drawer counts and is separate from collections and account balances.</p>@endif
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                @foreach(['opening' => ['Opening cash in hand', $summary['cash_opening']], 'received' => ['Cash collected today', $summary['cash_received']], 'paid' => ['Cash paid out today', $summary['cash_paid']], 'balance' => ['Cash in hand now', $summary['cash_in_hand']]] as $card => [$label, $value])
                <button type="button" data-cashier-movements="{{ route('cashier.card-details', ['date' => $date, 'card' => $card]) }}" aria-label="View {{ $label }} breakdown" class="panel cursor-pointer text-left transition hover:border-blue-300 hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-blue-600"><span class="block text-xs font-bold uppercase tracking-wide text-slate-500">{{ $label }}</span><span class="mt-2 block text-2xl font-black text-[#0b2447]">Rs. {{ number_format($value, 2) }}</span><span class="mt-3 block text-xs font-semibold text-blue-700">View breakdown →</span></button>
                @endforeach
            </div>
            <p class="text-sm text-slate-600">Cash in hand includes cash received for washing. Bank payments and carried Main / Spare Parts account balances are excluded.@if($summary['cash_other_in'] != 0) Other cash in: Rs. {{ number_format($summary['cash_other_in'], 2) }}.@endif</p>
            @if($independent)
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b p-5">
                    <h2 class="text-lg font-black">Account balances · separate from cashier cash</h2>
                    <p class="mt-1 text-sm text-slate-500">Opening balance + credits − debits + adjustments = balance. Opening balances are the saved amounts for this date. Select an adjustment to see its entries and opening reconciliation. New washing receipts go directly to Main; the Washing row shows receipts for reference and any earlier Washing ledger funds separately.</p>
                </div>
                <div class="overflow-auto">
                    <table class="w-full text-right text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="p-4 text-left">Account</th>
                                <th class="p-4">Opening balance · {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</th>
                                <th class="p-4">Credits</th>
                                <th class="p-4">Debits</th>
                                <th class="p-4">Adjustments (+ / −)</th>
                                <th class="p-4">Balance</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach(\App\Services\CashierService::ACCOUNTS as $type => $label)
                            @php
                            $account = $summary['display_accounts'][$type];
                            $adjustmentColor = $account['adjustments'] > 0 ? 'text-emerald-700' : ($account['adjustments'] < 0 ? 'text-red-600' : 'text-slate-500' );
                                @endphp
                                @if($type==='washing' )
                                <tr class="bg-blue-50/60">
                                <th class="p-4 text-left">
                                    <span class="font-bold text-slate-800">Washing</span>
                                    @if($summary['washing_received'] > 0)
                                    <button type="button" data-cashier-movements="{{ route('cashier.card-details', ['date' => $date, 'card' => 'washing']) }}" class="mt-1 block text-xs font-semibold text-blue-700 underline underline-offset-4">Today’s collection: Rs. {{ number_format($summary['washing_received'], 2) }} · View receipts</button>
                                    @else
                                    <span class="mt-1 block text-xs text-slate-400">No washing receipts today</span>
                                    @endif
                                    <span class="mt-0.5 block text-[11px] text-slate-500">Credited to Main Account instantly</span>
                                </th>
                                <td class="p-4 text-slate-500">0.00</td>
                                <td class="p-4 font-bold text-emerald-700">
                                    @if($summary['washing_received'] > 0)
                                    <button type="button" data-cashier-movements="{{ route('cashier.card-details', ['date' => $date, 'card' => 'washing']) }}" class="rounded px-1 font-bold underline decoration-emerald-300 underline-offset-4 hover:text-emerald-900" aria-label="View washing receipts">
                                        {{ number_format($summary['washing_received'], 2) }}
                                    </button>
                                    @else
                                    0.00
                                    @endif
                                </td>
                                <td class="p-4 text-slate-400">—</td>
                                <td class="p-4 text-slate-400">—</td>
                                <td class="p-4 text-xs font-bold text-slate-600">Included in Main</td>
                                </tr>
                                @else
                                <tr>
                                    <th class="p-4 text-left">{{ $label }}</th>
                                    <td class="p-4">{{ number_format($account['opening'], 2) }}</td>
                                    <td class="p-4 text-emerald-700"><button type="button" data-cashier-movements="{{ route('cashier.movements', ['date' => $date, 'account' => $type, 'side' => 'credit']) }}" class="rounded px-1 font-semibold underline decoration-emerald-200 underline-offset-4 hover:text-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-4" aria-label="View {{ $label }} credits">{{ number_format($account['income'], 2) }}</button></td>
                                    <td class="p-4 text-red-600">
                                        <button type="button" data-cashier-movements="{{ route('cashier.movements', ['date' => $date, 'account' => $type, 'side' => 'debit']) }}" class="rounded px-1 font-semibold underline decoration-red-200 underline-offset-4 hover:text-red-800 focus-visible:outline-2 focus-visible:outline-offset-4" aria-label="View {{ $label }} debits">{{ number_format($account['outgoing'], 2) }}</button>
                                        @if(($summary['allocation_accounts'][$type]['reversed_debits'] ?? 0) > 0)
                                        <span class="block text-[11px] font-normal text-slate-500">Net: Rs. {{ number_format($summary['allocation_accounts'][$type]['net_debits'], 2) }}</span>
                                        @endif
                                    </td>
                                    <td class="p-4 {{ $adjustmentColor }}"><button type="button" data-cashier-movements="{{ route('cashier.movements', ['date' => $date, 'account' => $type, 'side' => 'adjustment']) }}" class="rounded px-1 font-semibold underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-offset-4" aria-label="View {{ $label }} adjustments">{{ $account['adjustments'] > 0 ? '+' : '' }}{{ number_format($account['adjustments'], 2) }}</button></td>
                                    <td class="p-4 font-black">{{ number_format($account['closing'], 2) }}</td>
                                </tr>
                                @endif
                                @endforeach
                        </tbody>
                        <tfoot class="border-t bg-slate-50 font-black">
                            <tr>
                                <th scope="row" colspan="5" class="p-4 text-left">Total account balance</th>
                                <td class="p-4">Rs. {{ number_format(collect($summary['accounts'])->sum('closing'), 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
            @else
            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
                <div class="border-b p-5">
                    <h2 class="text-lg font-black">Cash in hand by account</h2>
                    <p class="mt-1 text-sm text-slate-500">Only received cash and cash movements are included. Partial collections settle Service → Labour → Washing → Other → Spare Parts.</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full min-w-[800px] text-right text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                            <tr>
                                <th class="p-4 text-left">Cash account</th>
                                <th class="p-4">Opening cash</th>
                                <th class="p-4">Cash received</th>
                                <th class="p-4">Other cash in</th>
                                <th class="p-4">Cash paid out</th>
                                <th class="p-4">Cash in hand</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">@foreach($labels as $type => $label) @php($account = $summary['cash_accounts'][$type])<tr>
                                <th class="p-4 text-left">{{ $label }}</th>
                                <td class="p-4">{{ number_format($account['opening'], 2) }}</td>
                                <td class="p-4 text-emerald-700">{{ number_format($account['income'], 2) }}</td>
                                <td class="p-4">{{ number_format($account['other_in'], 2) }}</td>
                                <td class="p-4 text-red-600">{{ number_format($account['outgoing'], 2) }}</td>
                                <td class="p-4 font-black">{{ number_format($account['closing'], 2) }}</td>
                            </tr>@endforeach</tbody>
                        <tfoot class="border-t bg-slate-50 font-black">
                            <tr>
                                <th class="p-4 text-left">Total cash in hand</th>
                                <td colspan="4"></td>
                                <td class="p-4">Rs. {{ number_format($summary['cash_in_hand'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
            @endif
            @if($session?->status === 'open')
            @include('cashier.close-form')
            @elseif($session?->status === 'closed')
            <section class="panel border-emerald-200">
                <h2 class="text-lg font-black">Cashier closed at {{ $session->closed_at?->format('d M Y, h:i A') ?? '—' }}</h2>
                <p class="mt-2 text-sm">Owner cash withdrawal: Rs. {{ number_format($session->owner_withdrawal, 2) }}</p>@if($session->drawer_closing_balance !== null)<p class="mt-4 text-xl font-black">Verified cash in cashier: Rs. {{ number_format($session->drawer_closing_balance, 2) }}</p>
                <p class="mt-2 text-sm text-slate-500">Account balances are recorded separately.</p>@if(isset($session->closing_summary['closing_bank_balance']))<p class="mt-3 text-sm font-bold text-blue-900">Verified Bank Account balance: Rs. {{ number_format($session->closing_summary['closing_bank_balance'], 2) }}</p>@endif @elseif($session->cash_balances)<p class="mt-4 text-xl font-black">Verified closing balance: Rs. {{ number_format(array_sum($session->cash_balances), 2) }}</p>
                <p class="mt-2 text-sm text-slate-500">Washing cash was included in Main Account at closing.</p>@if(isset($session->closing_summary['closing_bank_balance']))<p class="mt-3 text-sm font-bold text-blue-900">Verified Bank Account balance: Rs. {{ number_format($session->closing_summary['closing_bank_balance'], 2) }}</p>@endif@else<p class="mt-3 text-sm text-slate-500">This session was recorded before cash-only closing.</p>@endif
            </section>

            @endif
            @if($session?->status === 'closed' && is_array($session->closing_summary['closing_account_additions'] ?? null))
            <section class="panel">
                <h2 class="text-lg font-black">Today’s account additions saved at closing</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach(['main' => 'Main Account', 'spare_parts' => 'Spare Parts Account'] as $type => $accountLabel)
                    <div class="rounded-xl border p-4">
                        <h3 class="font-bold">{{ $accountLabel }}</h3>
                        <p class="mt-2 text-sm text-slate-600">Opening balance: Rs. {{ number_format($session->closing_summary['allocation_accounts'][$type]['opening'] ?? 0, 2) }}</p>@php($otherMovements = ($session->closing_summary['closing_allocation_bases'][$type] ?? 0) - ($session->closing_summary['allocation_accounts'][$type]['opening'] ?? 0))@if(round($otherMovements, 2) != 0)<p class="mt-1 text-sm text-slate-600">Other account movements / expenses: Rs. {{ number_format($otherMovements, 2) }}</p>@endif<p class="mt-1 text-sm text-slate-600">Added today: Rs. {{ number_format($session->closing_summary['closing_account_additions'][$type], 2) }}</p>
                        <p class="mt-3 font-black text-emerald-800">Saved account balance: Rs. {{ number_format($session->actual_balances[$type] ?? 0, 2) }}</p>
                    </div>
                    @endforeach
                </div>
            </section>
            @endif
            <section class="overflow-hidden rounded-2xl border bg-white">
                <div class="border-b p-5">
                    <h2 class="text-lg font-black">Cash collections received</h2>
                    <p class="mt-1 text-xs text-slate-500">{{ $transactions->count() }} cash receipt(s) · Rs. {{ number_format($summary['cash_received'], 2) }} collected.</p>
                </div>
                <div class="max-h-[500px] overflow-auto">
                    <table class="w-full min-w-[700px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="p-4">Time</th>
                                <th class="p-4">Receipt / Invoice</th>
                                <th class="p-4">Details</th>
                                <th class="p-4 text-right">Cash received</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">@forelse($transactions as $row)<tr>
                                <td class="p-4">{{ $row['date']->format('h:i A') }}</td>
                                <td class="p-4 font-bold">{{ $row['number'] }}<span class="block text-xs text-slate-500">{{ $row['invoice'] }}</span></td>
                                <td class="p-4">{{ $row['description'] }}</td>
                                <td class="p-4 text-right font-black text-emerald-700">{{ number_format($row['amount'], 2) }}</td>
                            </tr>@empty<tr>
                                <td colspan="4" class="p-10 text-center text-slate-500">No cash collections received for this day.</td>
                            </tr>@endforelse</tbody>
                    </table>
                </div>
            </section>
            @if($events->isNotEmpty())
            <section class="panel">
                <h2 class="text-lg font-black">Opening & Closing History · {{ \Carbon\Carbon::parse($date)->format('d M Y') }}</h2>
                <p class="mt-1 text-xs text-slate-500">Daily collections remain cumulative when the cashier is reopened.</p>
                <div class="mt-4 overflow-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th class="p-3">Time / action</th>
                                <th class="p-3">Cash in hand</th>
                                <th class="p-3">Washing person / commission</th>
                                <th class="p-3">Recorded by</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">@foreach($events as $event)<tr>
                                <td class="p-3">{{ $event->created_at->format('d M Y h:i A') }} · {{ match($event->action) { 'opening_corrected' => 'Opening corrected', 'opening_recorded' => 'Opening recorded', 'closing_corrected' => 'Closing corrected', 'account_balance_corrected' => 'Account total corrected', default => ucfirst($event->action) } }}</td>
                                <td class="p-3 font-bold">Rs. {{ number_format($event->cash_amount, 2) }}@if($event->details['recalculate_today'] ?? false)<span class="block text-xs font-normal text-emerald-700">Today recalculated from corrected opening</span>@endif @if(isset($event->details['cash_balances']))<span class="block text-xs font-normal text-slate-500">Main: Rs. {{ number_format($event->details['cash_balances']['main'], 2) }} · Spare Parts: Rs. {{ number_format($event->details['cash_balances']['spare_parts'], 2) }}</span>@endif @if(isset($event->details['bank_balance']))<span class="block text-xs text-blue-700">Bank: Rs. {{ number_format($event->details['bank_balance'], 2) }}</span>@endif @if(isset($event->details['checkpoint_reconciliation']))<span class="block text-xs font-normal text-amber-800">Count reconciliation · Main: Rs. {{ number_format($event->details['checkpoint_reconciliation']['main'], 2) }} · Spare Parts: Rs. {{ number_format($event->details['checkpoint_reconciliation']['spare_parts'], 2) }}</span>@endif @if(($event->details['washing_to_main'] ?? 0) != 0)<span class="block text-xs font-normal text-emerald-700">Washing transferred to Main: Rs. {{ number_format($event->details['washing_to_main'], 2) }}</span>@endif @if(($event->details['owner_withdrawal'] ?? 0) > 0)<span class="block text-xs text-slate-500">Withdrawal: Rs. {{ number_format($event->details['owner_withdrawal'], 2) }}</span>@endif</td>
                                <td class="p-3">{{ $event->washingPerson?->name ?? 'Unassigned' }} · @if($event->washing_commission_amount !== null) Rs. {{ number_format($event->washing_commission_amount, 2) }} per job @else Legacy {{ number_format($event->washing_commission_percentage, 2) }}% @endif</td>
                                <td class="p-3">{{ $event->creator?->name }}@if($event->action === 'account_balance_corrected')<span class="mt-1 block text-xs font-bold text-amber-800">{{ $event->details['label'] }}: Rs. {{ number_format($event->details['previous_total'], 2) }} → Rs. {{ number_format($event->details['corrected_total'], 2) }}</span>@endif @if(in_array($event->action, ['closed', 'closing_corrected']))<button type="button" class="mt-2 block font-bold text-blue-700 underline" data-cashier-closing="{{ route('cashier.closing-popup', ['cashier' => $session, 'event' => $event->id]) }}">View closing details</button>@endif @if(isset($event->details['previous_opening']))<span class="block text-xs text-slate-500">Previous Main: Rs. {{ number_format($event->details['previous_opening']['main'], 2) }} · Spare Parts: Rs. {{ number_format($event->details['previous_opening']['spare_parts'], 2) }}@if(isset($event->details['previous_opening']['bank'])) · Bank: Rs. {{ number_format($event->details['previous_opening']['bank'], 2) }}@endif</span>@endif @if($event->details['reason'] ?? null)<span class="block text-xs text-slate-500">{{ $event->details['reason'] }}</span>@endif</td>
                            </tr>@endforeach</tbody>
                    </table>
                </div>
            </section>
            @endif
            <section id="cashier-history" class="overflow-hidden rounded-2xl border bg-white">
                <div class="flex flex-wrap items-center justify-between gap-3 border-b p-5">
                    <h2 class="text-lg font-black">Cashier history</h2>@if(auth()->user()->canAccess('account.daily_closing'))<a href="{{ route('accounts.daily-closing.export', ['date' => $date]) }}" class="text-sm font-bold text-blue-700">Download closing PDF</a><a href="{{ route('accounts.daily-closing.export', ['date' => $date, 'format' => 'excel']) }}" class="text-sm font-bold text-emerald-700">Export Excel</a>@endif
                </div>
                <div class="overflow-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="p-4">Date</th>
                                <th class="p-4">Opening cash</th>
                                <th class="p-4">Cash withdrawn</th>
                                <th class="p-4">Verified cash</th>
                                <th class="p-4">Status</th>
                                <th class="p-4">Review</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">@forelse($history as $row)<tr>
                                <td class="p-4">{{ $row->session_date->format('d M Y') }}</td>
                                <td class="p-4">{{ number_format($row->opening_balance, 2) }}</td>
                                <td class="p-4">{{ number_format($row->owner_withdrawal, 2) }}</td>
                                <td class="p-4">{{ $row->drawer_closing_balance !== null ? number_format($row->drawer_closing_balance, 2) : ($row->cash_balances ? number_format(array_sum($row->cash_balances), 2) : 'Not recorded') }}</td>
                                <td class="p-4 font-bold">{{ ucfirst($row->status) }}</td>
                                <td class="p-4"><a class="font-bold text-blue-700" href="{{ route('cashier.index', ['date' => $row->session_date->toDateString()]) }}">View day</a> <button type="button" class="ml-3 font-bold text-blue-700 underline" data-cashier-closing="{{ route('cashier.closing-popup', $row) }}">{{ $row->status === 'open' ? 'Close cashier' : 'View / edit closing' }}</button> <button type="button" class="ml-3 font-bold text-amber-700 underline" data-cashier-open="{{ route('cashier.opening-popup', ['edit' => 1, 'date' => $row->session_date->toDateString()]) }}">Edit opening</button></td>
                            </tr>@empty<tr>
                                <td colspan="6" class="p-8 text-center text-slate-500">No cashier sessions yet.</td>
                            </tr>@endforelse</tbody>
                    </table>
                </div>
                <div class="p-4">{{ $history->links() }}</div>
            </section>
    </div>
</x-layouts::app>