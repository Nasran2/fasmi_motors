<x-layouts::app :title="__('Accounts')">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-black italic tracking-tight text-[#0b2447]">
                CASH <span class="text-red-600">BOOK</span>
            </h1>
            <div class="flex gap-2">
                <a href="{{ route('accounts.export', ['type' => 'cash', 'format' => 'pdf', 'print' => 1] + request()->query()) }}" target="_blank" class="inline-flex items-center gap-2 rounded-xl bg-white px-4 py-2 text-sm font-bold text-slate-700 shadow-sm ring-1 ring-inset ring-slate-200 hover:bg-slate-50">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                    Print
                </a>
                <a href="{{ route('accounts.export', ['type' => 'cash', 'format' => 'pdf'] + request()->query()) }}" class="inline-flex items-center gap-2 rounded-xl bg-rose-500 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-rose-600">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="12" y1="18" x2="12" y2="12"></line><line x1="9" y1="15" x2="15" y2="15"></line></svg>
                    PDF
                </a>
                <a href="{{ route('accounts.export', ['type' => 'cash', 'format' => 'excel'] + request()->query()) }}" class="inline-flex items-center gap-2 rounded-xl bg-emerald-500 px-4 py-2 text-sm font-bold text-white shadow-sm hover:bg-emerald-600">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
                    Excel
                </a>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Opening Balance</p>
                <p class="mt-2 text-2xl font-black text-slate-800">Rs. {{ number_format($summary['opening_balance'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Cash Collections</p>
                <p class="mt-2 text-2xl font-black text-emerald-600">Rs. {{ number_format($summary['cash_collections'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Cash Expenses</p>
                <p class="mt-2 text-2xl font-black text-red-600">Rs. {{ number_format($summary['cash_expenses'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm ring-1 ring-inset ring-emerald-500/20">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-800">Closing Balance</p>
                <p class="mt-2 text-2xl font-black text-emerald-700">Rs. {{ number_format($summary['closing_balance'], 2) }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="mb-1 block text-xs font-bold text-slate-600">Date Range</label>
                    <select name="date_range" class="w-full rounded-xl border-slate-200 text-sm focus:border-red-500 focus:ring-red-500">
                        <option value="today" {{ request('date_range') == 'today' ? 'selected' : '' }}>Today</option>
                        <option value="yesterday" {{ request('date_range') == 'yesterday' ? 'selected' : '' }}>Yesterday</option>
                        <option value="this_week" {{ request('date_range') == 'this_week' ? 'selected' : '' }}>This Week</option>
                        <option value="this_month" {{ request('date_range') == 'this_month' ? 'selected' : '' }}>This Month</option>
                        <option value="this_year" {{ request('date_range') == 'this_year' ? 'selected' : '' }}>This Year</option>
                        <option value="all" {{ request('date_range') == 'all' ? 'selected' : '' }}>All Time</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="mb-1 block text-xs font-bold text-slate-600">Transaction Type</label>
                    <select name="transaction_type" class="w-full rounded-xl border-slate-200 text-sm focus:border-red-500 focus:ring-red-500">
                        <option value="">All Types</option>
                        <option value="income" {{ request('transaction_type') == 'income' ? 'selected' : '' }}>Income (In)</option>
                        <option value="expense" {{ request('transaction_type') == 'expense' ? 'selected' : '' }}>Expense (Out)</option>
                        <option value="drawing" {{ request('transaction_type') == 'drawing' ? 'selected' : '' }}>Drawing (Out)</option>
                        <option value="transfer_in" {{ request('transaction_type') == 'transfer_in' ? 'selected' : '' }}>Transfer In</option>
                        <option value="transfer_out" {{ request('transaction_type') == 'transfer_out' ? 'selected' : '' }}>Transfer Out</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="mb-1 block text-xs font-bold text-slate-600">Search</label>
                    <input type="text" name="search" value="{{ request('search') }}" placeholder="TRX No or Description" class="w-full rounded-xl border-slate-200 text-sm focus:border-red-500 focus:ring-red-500">
                </div>
                <button type="submit" class="rounded-xl bg-[#0b2447] px-6 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Filter</button>
            </form>
        </div>

        <!-- Ledger Table -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">TRX No</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3 text-right">Cash Out</th>
                            <th class="px-4 py-3 text-right">Cash In</th>
                            <th class="px-4 py-3 text-right">Balance</th>
                            <th class="px-4 py-3 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($transactions as $trx)
                        <tr class="hover:bg-slate-50 {{ $trx->status === 'reversed' ? 'opacity-50 line-through' : '' }}">
                            <td class="whitespace-nowrap px-4 py-3">{{ $trx->transaction_date->format('d M Y h:i A') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $trx->transaction_no }}</td>
                            <td class="px-4 py-3">
                                <p class="font-bold text-slate-700">{{ $trx->description }}</p>
                                @if($trx->note) <p class="text-xs text-slate-500">{{ $trx->note }}</p> @endif
                                @if($trx->status === 'reversed') <p class="text-xs font-bold text-red-500">Reversed: {{ $trx->reversal_reason }}</p> @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-red-600">
                                {{ $trx->debit > 0 ? number_format($trx->debit, 2) : '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium text-emerald-600">
                                {{ $trx->credit > 0 ? number_format($trx->credit, 2) : '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-bold text-slate-800">
                                {{ number_format($trx->running_balance, 2) }}
                            </td>
                            <td class="px-4 py-3 text-center">
                                @if($trx->status === 'active')
                                    <form action="{{ route('accounts.cash-book.reverse', $trx) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to reverse this transaction?')">
                                        @csrf
                                        <input type="hidden" name="reason" value="Manual Reversal">
                                        <button type="submit" class="text-red-500 hover:text-red-700" title="Reverse Transaction">
                                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">No transactions found for this period.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($transactions->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $transactions->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
