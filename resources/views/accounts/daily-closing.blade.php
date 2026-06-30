<x-layouts::app title="Daily Closing">
@php 
$accountLabels=[
    'main'=>'Main Account',
    'spare_parts'=>'Spare Parts Account',
    'bank'=>'Bank Account',
    'cash'=>'Cash Book'
];
$ledgerRoutes=[
    'main'=>'accounts.main-ledger.index',
    'spare_parts'=>'accounts.spare-parts-ledger.index',
    'bank'=>'accounts.bank-ledger.index',
    'cash'=>'accounts.cash-book.index'
]; 
@endphp
<div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8 space-y-6">
    
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center gap-2 text-sm text-slate-500 mb-2">
            <a href="#" class="hover:text-[#0b2447] transition-colors">Accounts</a>
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
            <span class="text-slate-900 font-medium">Daily Closing</span>
        </div>
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
            <div>
                <h1 class="text-3xl font-bold text-slate-900 tracking-tight">Daily Closing</h1>
                <p class="text-slate-500 mt-1">Verify today’s transactions, match physical balances, and close the day.</p>
            </div>
            
            <form method="GET" class="flex flex-wrap items-center gap-3 bg-white p-2 rounded-2xl border border-slate-200 shadow-sm">
                <input type="date" name="date" max="{{ today()->toDateString() }}" value="{{ $date }}" class="h-10 rounded-xl border border-slate-200 px-3 text-sm text-slate-700 focus:ring-red-50 focus:border-red-400 outline-none">
                <button class="h-10 rounded-xl bg-[#0b2447] px-5 text-sm font-bold text-white shadow-sm hover:bg-[#133363] transition-all">Load Date</button>
                <div class="h-6 w-px bg-slate-200 mx-1"></div>
                <a href="{{ route('accounts.daily-closing.index') }}" class="px-4 py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900">Today</a>
                <a href="{{ route('accounts.daily-closing.index',['date'=>today()->subDay()->toDateString()]) }}" class="px-4 py-2 text-xs font-bold rounded-lg text-slate-600 hover:text-slate-900">Yesterday</a>
            </form>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 font-medium text-emerald-700 text-sm flex items-center gap-3">
            <svg class="size-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 font-medium text-rose-700 text-sm flex items-start gap-3">
            <svg class="size-5 text-rose-600 mt-0.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <div>Please check the highlighted information: {{ $errors->first() }}</div>
        </div>
    @endif

    <!-- Status Banner -->
    <div class="flex items-center justify-between p-4 rounded-2xl border {{ ($closing?->status) === 'closed' ? 'bg-emerald-50 border-emerald-200' : (($closing?->status) === 'reopened' ? 'bg-amber-50 border-amber-200' : 'bg-white border-slate-200 shadow-sm') }}">
        <div class="flex items-center gap-3">
            <div class="size-2 rounded-full {{ ($closing?->status) === 'closed' ? 'bg-emerald-500' : 'bg-amber-500 animate-pulse' }}"></div>
            <h2 class="font-bold text-slate-900">Status for {{ $date }}</h2>
        </div>
        <span class="px-3 py-1 text-xs font-bold rounded-full {{ ($closing?->status) === 'closed' ? 'bg-emerald-100 text-emerald-700' : (($closing?->status) === 'reopened' ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-700') }}">
            {{ $closing ? ucwords($closing->status.' day') : 'Open Day' }}
        </span>
    </div>

    <!-- Bills Summary Section -->
    <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
        <div class="flex flex-wrap items-center justify-between border-b border-slate-100 p-4 sm:p-5">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Today’s Bills & Income</h2>
                <p class="text-xs text-slate-500 mt-0.5">Summary of all jobs and parts invoices recorded.</p>
            </div>
            <button type="button" onclick="document.getElementById('all-bills').showModal()" class="rounded-lg border border-slate-200 bg-white px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-50 transition-colors shadow-sm">View All Bills</button>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm text-left whitespace-nowrap">
                <thead class="bg-slate-50 text-xs text-slate-500 uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3 font-semibold">Income Type</th>
                        <th class="px-5 py-3 font-semibold text-right">Total Amount</th>
                        <th class="px-5 py-3 font-semibold text-center">Bill Count</th>
                        <th class="px-5 py-3 font-semibold">Recent Bill Numbers</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($billGroups as $name => $group)
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-5 py-3 font-medium text-slate-900">{{ $name }}</td>
                        <td class="px-5 py-3 text-right font-bold text-[#0b2447]">Rs. {{ number_format($group->sum('total'), 2) }}</td>
                        <td class="px-5 py-3 text-center">
                            <span class="inline-flex items-center justify-center min-w-[24px] h-6 px-2 rounded-full bg-slate-100 text-xs font-bold text-slate-700">{{ $group->count() }}</span>
                        </td>
                        <td class="px-5 py-3 text-slate-500 text-xs">{{ $group->pluck('number')->take(4)->join(', ') ?: '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($bills->isEmpty())
        <div class="p-6 text-center text-sm text-slate-500 bg-slate-50">
            No bills found for {{ $date }}.
        </div>
        @endif
    </section>

    <!-- Balances & Closing Summary Layout -->
    <div class="flex flex-col xl:flex-row gap-6 items-start">
        
        <!-- Left: Account Balances -->
        <form method="POST" action="{{ route('accounts.daily-closing.store') }}" class="w-full xl:flex-1 space-y-6">
            @csrf
            <input type="hidden" name="closing_date" value="{{ $date }}">
            
            <div class="grid gap-6 md:grid-cols-2">
                @foreach($accountLabels as $type => $label)
                @php 
                    $actual = (float)old($type.'_actual', $closing?->{"{$type}_actual_balance"} ?? $expected[$type]['closing']);
                    $difference = $actual - $expected[$type]['closing'];
                @endphp
                
                <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm relative overflow-hidden group hover:border-[#0b2447]/20 transition-colors">
                    <!-- Subtle top accent -->
                    <div class="absolute top-0 left-0 right-0 h-1 bg-gradient-to-r from-[#0b2447] to-red-600 opacity-10"></div>
                    
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-base font-bold text-slate-900">{{ $label }}</h3>
                            <p class="text-[11px] font-medium uppercase tracking-wider text-slate-400 mt-0.5">{{ $expected[$type]['count'] }} records today</p>
                        </div>
                        <a href="{{ route($ledgerRoutes[$type], ['date_range' => 'custom', 'start_date' => $date, 'end_date' => $date]) }}" class="text-xs font-bold text-blue-600 hover:text-blue-800">Ledger →</a>
                    </div>
                    
                    <!-- Micro Summary Grid -->
                    <div class="mt-4 grid grid-cols-3 gap-2">
                        <div class="rounded-xl border border-slate-100 bg-slate-50/50 p-2.5">
                            <p class="text-[10px] uppercase font-bold text-slate-500">Opening</p>
                            <p class="mt-0.5 text-xs font-bold text-slate-700">{{ number_format($expected[$type]['opening'], 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-emerald-100 bg-emerald-50/30 p-2.5">
                            <p class="text-[10px] uppercase font-bold text-emerald-600">Money In</p>
                            <p class="mt-0.5 text-xs font-bold text-emerald-700">{{ number_format($expected[$type]['income'], 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-rose-100 bg-rose-50/30 p-2.5">
                            <p class="text-[10px] uppercase font-bold text-rose-600">Expenses</p>
                            <p class="mt-0.5 text-xs font-bold text-rose-700">{{ number_format($expected[$type]['expenses_only'], 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-orange-100 bg-orange-50/30 p-2.5">
                            <p class="text-[10px] uppercase font-bold text-orange-600">Drawings</p>
                            <p class="mt-0.5 text-xs font-bold text-orange-700">{{ number_format($expected[$type]['drawings'], 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-blue-100 bg-blue-50/30 p-2.5">
                            <p class="text-[10px] uppercase font-bold text-blue-600">Transfer In</p>
                            <p class="mt-0.5 text-xs font-bold text-blue-700">{{ number_format($expected[$type]['transfer_in'], 2) }}</p>
                        </div>
                        <div class="rounded-xl border border-slate-200 bg-slate-100/50 p-2.5">
                            <p class="text-[10px] uppercase font-bold text-slate-600">Transfer Out</p>
                            <p class="mt-0.5 text-xs font-bold text-slate-700">{{ number_format($expected[$type]['transfer_out'], 2) }}</p>
                        </div>
                    </div>
                    
                    <div class="mt-5 space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <p class="text-sm font-medium text-slate-500">Expected Balance</p>
                            <p class="text-lg font-bold text-slate-900">Rs. {{ number_format($expected[$type]['closing'], 2) }}</p>
                        </div>
                        
                        <div>
                            <label class="block text-xs font-bold text-slate-700 mb-1.5 uppercase tracking-wider">Actual Physical Balance</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 flex items-center pl-3 text-slate-500 font-medium">Rs.</span>
                                <input required step="0.01" type="number" name="{{ $type }}_actual" value="{{ $actual }}" 
                                    class="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-4 text-sm font-bold text-slate-900 focus:border-[#0b2447] focus:bg-white focus:ring-[#0b2447] transition-colors" 
                                    @disabled($closing?->status === 'closed')>
                            </div>
                        </div>
                        
                        <div class="flex items-center justify-between pt-1">
                            <div class="flex items-center gap-1.5">
                                <p class="text-xs font-medium text-slate-500">Difference:</p>
                                <p class="text-sm font-bold {{ $difference == 0 ? 'text-emerald-600' : 'text-rose-600' }}">Rs. {{ number_format($difference, 2) }}</p>
                            </div>
                            
                            @if(!$closing || $closing->status !== 'closed')
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="document.getElementById('deduction-{{$type}}').showModal()" class="text-[11px] font-bold text-rose-600 bg-rose-50 hover:bg-rose-100 px-2.5 py-1 rounded-lg transition-colors border border-rose-100">Deduct</button>
                                <button type="button" onclick="document.getElementById('adjustment-{{$type}}').showModal()" class="text-[11px] font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 px-2.5 py-1 rounded-lg transition-colors border border-blue-100">Adjust</button>
                            </div>
                            @endif
                        </div>
                    </div>
                </section>
                @endforeach
            </div>

            <!-- Closing Checklist & Submit -->
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm mt-6">
                <h2 class="text-base font-bold text-slate-900 mb-4">Finalizing Actions</h2>
                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                    @foreach(['All bills recorded', 'Expenses checked', 'Drawings checked', 'Bank/card checked', 'Cash counted', 'Adjustments reviewed'] as $check)
                    <label class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50/50 p-3 text-xs font-medium text-slate-700 hover:bg-slate-50 transition-colors cursor-pointer">
                        <input required type="checkbox" class="size-4 rounded border-slate-300 text-red-600 focus:ring-red-600">
                        {{ $check }}
                    </label>
                    @endforeach
                </div>
                
                <div class="mt-5 grid gap-4 sm:grid-cols-2">
                    <label class="block">
                        <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Reason for Any Difference</span>
                        <textarea name="difference_reason" class="mt-1.5 min-h-[80px] w-full rounded-xl border border-slate-200 p-3 text-sm focus:border-[#0b2447] focus:ring-[#0b2447]" placeholder="Required when actual balance differs from expected">{{ old('difference_reason', $closing?->difference_reason) }}</textarea>
                        @error('difference_reason') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                    </label>
                    <label class="block">
                        <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Closing Note</span>
                        <textarea name="notes" class="mt-1.5 min-h-[80px] w-full rounded-xl border border-slate-200 p-3 text-sm focus:border-[#0b2447] focus:ring-[#0b2447]" placeholder="Optional note for the owner or next shift">{{ old('notes', $closing?->notes) }}</textarea>
                    </label>
                </div>
                
                @if(!$closing || $closing->status !== 'closed')
                <div class="mt-6 pt-5 border-t border-slate-100 flex justify-end">
                    <button class="rounded-xl bg-red-600 hover:bg-red-700 px-8 py-3 text-sm font-bold text-white shadow-sm transition-colors flex items-center gap-2">
                        <svg class="size-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                        Close Day
                    </button>
                </div>
                @endif
            </section>
        </form>

        <!-- Right: Closing Summary Sticky Sidebar -->
        <aside class="w-full xl:w-[340px] shrink-0 xl:sticky xl:top-24 space-y-4">
            <div class="rounded-2xl border border-slate-200 bg-white shadow-sm overflow-hidden">
                <div class="bg-slate-50 border-b border-slate-200 p-4">
                    <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Summary</p>
                    <h2 class="text-lg font-bold text-slate-900 mt-0.5">{{ $date }}</h2>
                </div>
                
                <div class="p-4">
                    <dl class="space-y-2.5 text-xs">
                        @foreach([
                            'Total Job Income' => $summary['job_income'],
                            'Total Spare Parts' => $summary['parts_sales'],
                            'Total Other Income' => $summary['other_income'],
                            'Total Expenses' => $summary['expenses'],
                            'Total Drawings' => $summary['drawings'],
                            'Transfers In' => $summary['transfer_in'],
                            'Transfers Out' => $summary['transfer_out'],
                            'Total Deductions' => $summary['deductions'],
                            'Total Adjustments' => $summary['adjustments']
                        ] as $label => $value)
                        <div class="flex justify-between items-center py-1">
                            <dt class="text-slate-500 font-medium">{{ $label }}</dt>
                            <dd class="font-bold text-slate-900">{{ number_format($value, 2) }}</dd>
                        </div>
                        @endforeach
                    </dl>
                    
                    <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <p class="text-[10px] uppercase font-bold text-slate-500 tracking-wider">Final Expected Balance</p>
                        <p class="mt-1 text-xl font-bold text-[#0b2447]">Rs. {{ number_format($summary['closing'], 2) }}</p>
                        <p class="mt-1.5 text-[11px] font-medium text-slate-400">{{ $summary['bill_count'] }} bill(s) recorded</p>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-col gap-2">
                <a target="_blank" href="{{ route('accounts.daily-closing.export', ['date' => $date, 'print' => 1]) }}" class="flex items-center justify-center gap-2 rounded-xl bg-white border border-slate-200 px-4 py-2.5 text-sm font-bold text-slate-700 shadow-sm hover:bg-slate-50 transition-colors">
                    <svg class="size-4 text-slate-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    Print Report
                </a>
                <a href="{{ route('accounts.daily-closing.export', ['date' => $date]) }}" class="flex items-center justify-center gap-2 rounded-xl border border-red-200 bg-red-50 px-4 py-2.5 text-sm font-bold text-red-700 shadow-sm hover:bg-red-100 transition-colors">
                    <svg class="size-4 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Download PDF
                </a>
                <a href="{{ route('accounts.daily-closing.export', ['date' => $date, 'format' => 'excel']) }}" class="flex items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-2.5 text-sm font-bold text-emerald-700 shadow-sm hover:bg-emerald-100 transition-colors">
                    <svg class="size-4 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    Export Excel
                </a>
            </div>
        </aside>
    </div>

    <!-- Reopen Form -->
    @if($closing?->status === 'closed')
    <form method="POST" action="{{ route('accounts.daily-closing.reopen', $closing) }}" class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm max-w-2xl">
        @csrf
        <div class="flex items-start gap-4">
            <div class="p-2 bg-amber-100 rounded-xl text-amber-600">
                <svg class="size-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
            </div>
            <div class="flex-1">
                <h2 class="text-sm font-bold text-amber-900">Reopen Closed Day</h2>
                <p class="mt-0.5 text-xs text-amber-700">A reason is required and will remain in the audit history.</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <input name="reason" required minlength="5" placeholder="Reason for reopening" class="h-10 min-w-[240px] flex-1 rounded-xl border border-amber-200 px-3 text-sm focus:border-amber-400 focus:ring-amber-400 outline-none">
                    <button class="rounded-xl bg-amber-500 hover:bg-amber-600 transition-colors px-5 text-sm font-bold text-white shadow-sm">Reopen Day</button>
                </div>
            </div>
        </div>
    </form>
    @endif

    <!-- History Section -->
    <section class="rounded-2xl border border-slate-200 bg-white overflow-hidden shadow-sm">
        <div class="border-b border-slate-100 p-4 sm:p-5">
            <h2 class="text-lg font-bold text-slate-900">Daily Closing History</h2>
            <p class="text-xs text-slate-500 mt-0.5">Past closing records and summaries.</p>
        </div>
        
        <div class="overflow-x-auto">
            <table class="w-full min-w-[1000px] text-sm text-left whitespace-nowrap">
                <thead class="bg-slate-50 text-[11px] font-black text-slate-500 uppercase tracking-wider border-b border-slate-200">
                    <tr>
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">Main</th>
                        <th class="px-5 py-3">Parts</th>
                        <th class="px-5 py-3">Bank</th>
                        <th class="px-5 py-3">Cash</th>
                        <th class="px-5 py-3">Diff</th>
                        <th class="px-5 py-3">Closed By</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse($closings as $row)
                    <tr class="hover:bg-slate-50/50 transition-colors">
                        <td class="px-5 py-3 font-bold text-slate-900">{{ $row->closing_date->format('d M Y') }}</td>
                        @foreach(['main', 'spare_parts', 'bank', 'cash'] as $type)
                            <td class="px-5 py-3 text-slate-600">{{ number_format($row->{$type.'_actual_balance'}, 2) }}</td>
                        @endforeach
                        <td class="px-5 py-3 font-bold {{ $row->difference == 0 ? 'text-emerald-600' : 'text-rose-600' }}">{{ number_format($row->difference, 2) }}</td>
                        <td class="px-5 py-3 text-slate-600 text-xs">{{ $row->closer?->name }}</td>
                        <td class="px-5 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $row->status === 'closed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700' }}">
                                {{ $row->status }}
                            </span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <a target="_blank" href="{{ route('accounts.daily-closing.export', ['date' => $row->closing_date->toDateString(), 'print' => 1]) }}" class="text-xs font-bold text-blue-600 hover:text-blue-800">Print</a>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="px-5 py-8 text-center text-sm text-slate-500 bg-slate-50">
                            No closing saved yet. Complete the steps above to close today.
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>

<!-- Modals -->

<!-- All Bills Modal -->
<dialog id="all-bills" class="w-[min(900px,94vw)] rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40 bg-white m-auto">
    <div class="flex items-center justify-between border-b border-slate-100 p-5 bg-white rounded-t-2xl">
        <div>
            <h2 class="text-lg font-bold text-slate-900">Bills for {{ $date }}</h2>
            <p class="text-xs text-slate-500 mt-0.5">{{ $bills->count() }} bill(s) · Total: Rs. {{ number_format($bills->sum('total'), 2) }}</p>
        </div>
        <button onclick="document.getElementById('all-bills').close()" class="rounded-lg p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
            <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>
    <div class="max-h-[65vh] overflow-auto bg-white rounded-b-2xl">
        <table class="w-full min-w-[760px] text-sm text-left">
            <thead class="sticky top-0 bg-slate-50 text-xs font-black text-slate-500 uppercase tracking-wider border-b border-slate-200">
                <tr>
                    <th class="px-5 py-3">Bill Number</th>
                    <th class="px-5 py-3">Customer</th>
                    <th class="px-5 py-3">Bike</th>
                    <th class="px-5 py-3 text-right">Amount</th>
                    <th class="px-5 py-3">Method</th>
                    <th class="px-5 py-3 text-right">Invoice</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($bills as $bill)
                <tr class="hover:bg-slate-50 transition-colors">
                    <td class="px-5 py-3 font-medium text-slate-900">{{ $bill->number }}</td>
                    <td class="px-5 py-3 text-slate-600 text-xs">{{ $bill->customer?->name ?: $bill->jobCard?->intake?->bike?->customer?->name ?: 'Walk-in' }}</td>
                    <td class="px-5 py-3 text-slate-500 text-xs">{{ $bill->bike?->number ?: $bill->jobCard?->intake?->bike?->number ?: '—' }}</td>
                    <td class="px-5 py-3 font-bold text-slate-900 text-right">{{ number_format($bill->total, 2) }}</td>
                    <td class="px-5 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-slate-100 text-slate-700">{{ $bill->payment_method ?: 'Due' }}</span>
                    </td>
                    <td class="px-5 py-3 text-right">
                        <a href="{{ route('sales.invoices.show', $bill) }}" class="text-xs font-bold text-blue-600 hover:text-blue-800">View</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-5 py-12 text-center text-sm text-slate-500">No bills recorded for this date.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</dialog>

<!-- Deduction/Adjustment Modals -->
@foreach($accountLabels as $type => $label)
<dialog id="deduction-{{$type}}" class="w-[min(480px,94vw)] rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40 bg-white m-auto">
    <form method="POST" enctype="multipart/form-data" action="{{ route('accounts.daily-closing.adjustment') }}" class="flex flex-col bg-white rounded-2xl">
        @csrf
        <input type="hidden" name="account_type" value="{{ $type }}">
        <input type="hidden" name="action" value="deduction">
        
        <div class="flex items-start justify-between p-5 border-b border-slate-100">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Add Deduction</h2>
                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500 mt-1">{{ $label }}</p>
            </div>
            <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-slate-600 p-1">
                <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        
        <div class="p-5 space-y-4">
            <label class="block">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Amount</span>
                <input required type="number" min="0.01" step="0.01" name="amount" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-red-400 focus:ring-red-400 outline-none">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Reason</span>
                <input required minlength="5" name="reason" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-red-400 focus:ring-red-400 outline-none">
            </label>
            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Date</span>
                    <input required type="date" max="{{ today()->toDateString() }}" name="date" value="{{ $date }}" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-red-400 focus:ring-red-400 outline-none">
                </label>
                <label class="block">
                    <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Proof (opt)</span>
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" class="mt-1.5 block w-full text-[10px] text-slate-500 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
                </label>
            </div>
        </div>
        
        <div class="p-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex justify-end gap-2">
            <button type="button" onclick="this.closest('dialog').close()" class="rounded-xl px-5 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-200 transition-colors">Cancel</button>
            <button class="rounded-xl bg-red-600 hover:bg-red-700 px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-colors">Save Deduction</button>
        </div>
    </form>
</dialog>

<dialog id="adjustment-{{$type}}" class="w-[min(480px,94vw)] rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/40 bg-white m-auto">
    <form method="POST" enctype="multipart/form-data" action="{{ route('accounts.daily-closing.adjustment') }}" class="flex flex-col bg-white rounded-2xl">
        @csrf
        <input type="hidden" name="account_type" value="{{ $type }}">
        
        <div class="flex items-start justify-between p-5 border-b border-slate-100">
            <div>
                <h2 class="text-lg font-bold text-slate-900">Add Adjustment</h2>
                <p class="text-[11px] font-medium uppercase tracking-wider text-slate-500 mt-1">{{ $label }}</p>
            </div>
            <button type="button" onclick="this.closest('dialog').close()" class="text-slate-400 hover:text-slate-600 p-1">
                <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        
        <div class="p-5 space-y-4">
            <label class="block">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Adjustment Type</span>
                <select name="action" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-blue-400 focus:ring-blue-400 outline-none bg-white">
                    <option value="increase">Increase Balance (+)</option>
                    <option value="decrease">Reduce Balance (-)</option>
                </select>
            </label>
            <label class="block">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Amount</span>
                <input required type="number" min="0.01" step="0.01" name="amount" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-blue-400 focus:ring-blue-400 outline-none">
            </label>
            <label class="block">
                <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Reason</span>
                <input required minlength="5" name="reason" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-blue-400 focus:ring-blue-400 outline-none">
            </label>
            <div class="grid grid-cols-2 gap-4">
                <label class="block">
                    <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Date</span>
                    <input required type="date" max="{{ today()->toDateString() }}" name="date" value="{{ $date }}" class="mt-1.5 h-11 w-full rounded-xl border border-slate-200 px-3 text-sm focus:border-blue-400 focus:ring-blue-400 outline-none">
                </label>
                <label class="block">
                    <span class="text-xs font-bold text-slate-700 uppercase tracking-wider">Proof (opt)</span>
                    <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" class="mt-1.5 block w-full text-[10px] text-slate-500 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-slate-50 file:text-slate-700 hover:file:bg-slate-100">
                </label>
            </div>
        </div>
        
        <div class="p-4 border-t border-slate-100 bg-slate-50 rounded-b-2xl flex justify-end gap-2">
            <button type="button" onclick="this.closest('dialog').close()" class="rounded-xl px-5 py-2.5 text-sm font-bold text-slate-600 hover:bg-slate-200 transition-colors">Cancel</button>
            <button class="rounded-xl bg-[#0b2447] hover:bg-[#133363] px-6 py-2.5 text-sm font-bold text-white shadow-sm transition-colors">Save Adjustment</button>
        </div>
    </form>
</dialog>
@endforeach

</x-layouts::app>
