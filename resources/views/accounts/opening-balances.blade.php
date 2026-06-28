<x-layouts::app :title="__('Accounts')">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-black italic tracking-tight text-[#0b2447]">
                OPENING <span class="text-red-600">BALANCES</span>
            </h1>
            <button onclick="document.getElementById('opening-balance-modal').showModal()" class="inline-flex items-center gap-2 rounded-xl bg-[#0b2447] px-4 py-2 text-sm font-bold text-white shadow-lg hover:bg-slate-800">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                Setup New Balance
            </button>
        </div>

        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <strong>Note:</strong> Opening balances should only be set once during initial system setup for each account to carry over existing real-world balances into the ERP.
        </div>

        <!-- Ledger Table -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3">TRX No</th>
                            <th class="px-4 py-3">Account</th>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3">Note</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($transactions as $trx)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3">{{ $trx->transaction_date->format('d M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $trx->transaction_no }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-bold text-slate-700 uppercase">{{ str_replace('_', ' ', $trx->account_type) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if($trx->credit > 0)
                                    <span class="rounded-lg bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-700">Positive (Credit)</span>
                                @else
                                    <span class="rounded-lg bg-red-100 px-2 py-1 text-xs font-bold text-red-700">Negative (Debit)</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-bold">
                                Rs. {{ number_format(max($trx->credit, $trx->debit), 2) }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-slate-700">{{ $trx->note }}</p>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No opening balances recorded yet.</td>
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

    <!-- Modal -->
    <dialog id="opening-balance-modal" class="w-full max-w-md rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 open:animate-in open:fade-in open:zoom-in-95">
        <form action="{{ route('accounts.opening-balances.store') }}" method="POST">
            @csrf
            <div class="border-b border-slate-100 bg-slate-50 p-4">
                <h3 class="text-lg font-black text-[#0b2447]">Setup Opening Balance</h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Account</label>
                    <select name="account_type" required class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                        <option value="main">Main Account</option>
                        <option value="spare_parts">Spare Parts Account</option>
                        <option value="bank">Bank Account</option>
                        <option value="cash">Cash Book</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Balance Type</label>
                    <select name="balance_type" required class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                        <option value="credit">Positive Balance (Asset/Cash on hand)</option>
                        <option value="debit">Negative Balance (Overdraft)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Amount (Rs)</label>
                    <input type="number" step="0.01" name="amount" required class="w-full rounded-xl border-slate-200 text-lg font-bold focus:border-red-500 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Date as of</label>
                    <input type="date" name="opening_date" value="{{ date('Y-m-d') }}" required class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Note / Description</label>
                    <input type="text" name="note" class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-100 bg-slate-50 p-4">
                <button type="button" onclick="document.getElementById('opening-balance-modal').close()" class="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                <button type="submit" onclick="return confirm('Confirm saving this opening balance?')" class="rounded-xl bg-[#0b2447] px-6 py-2 text-sm font-bold text-white hover:bg-slate-800">Save Balance</button>
            </div>
        </form>
    </dialog>
</x-layouts::app>
