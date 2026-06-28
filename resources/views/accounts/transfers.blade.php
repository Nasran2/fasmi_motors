<x-layouts::app :title="__('Accounts')">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-black italic tracking-tight text-[#0b2447]">
                ACCOUNT <span class="text-red-600">TRANSFERS</span>
            </h1>
            <button onclick="document.getElementById('transfer-modal').showModal()" class="inline-flex items-center gap-2 rounded-xl bg-red-600 px-4 py-2 text-sm font-bold text-white shadow-lg shadow-red-200 hover:bg-red-700">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14"/><path d="M12 5v14"/></svg>
                New Transfer
            </button>
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
                            <th class="px-4 py-3">Description</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($transfers as $trx)
                        <tr class="hover:bg-slate-50 {{ $trx->status === 'reversed' ? 'opacity-50 line-through' : '' }}">
                            <td class="whitespace-nowrap px-4 py-3">{{ $trx->transaction_date->format('d M Y h:i A') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-medium">{{ $trx->transaction_no }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-bold text-slate-700 uppercase">{{ str_replace('_', ' ', $trx->account_type) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @if($trx->transaction_type === 'transfer_in')
                                    <span class="rounded-lg bg-emerald-100 px-2 py-1 text-xs font-bold text-emerald-700">IN</span>
                                @else
                                    <span class="rounded-lg bg-red-100 px-2 py-1 text-xs font-bold text-red-700">OUT</span>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-bold {{ $trx->transaction_type === 'transfer_in' ? 'text-emerald-600' : 'text-red-600' }}">
                                Rs. {{ number_format($trx->amount, 2) }}
                            </td>
                            <td class="px-4 py-3">
                                <p class="text-slate-700">{{ $trx->description }}</p>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-slate-500">No transfers found for this period.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($transfers->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $transfers->links() }}
                </div>
            @endif
        </div>
    </div>

    <!-- Transfer Modal -->
    <dialog id="transfer-modal" class="w-full max-w-md rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 open:animate-in open:fade-in open:zoom-in-95">
        <form action="{{ route('accounts.transfers.store') }}" method="POST">
            @csrf
            <div class="border-b border-slate-100 bg-slate-50 p-4">
                <h3 class="text-lg font-black text-[#0b2447]">New Account Transfer</h3>
            </div>
            <div class="p-6 space-y-4">
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">From Account</label>
                    <select name="from_account" required class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                        <option value="main">Main Account</option>
                        <option value="spare_parts">Spare Parts Account</option>
                        <option value="bank">Bank Account</option>
                        <option value="cash">Cash Book</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">To Account</label>
                    <select name="to_account" required class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                        <option value="bank">Bank Account</option>
                        <option value="cash">Cash Book</option>
                        <option value="main">Main Account</option>
                        <option value="spare_parts">Spare Parts Account</option>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Amount (Rs)</label>
                    <input type="number" step="0.01" name="amount" required class="w-full rounded-xl border-slate-200 text-lg font-bold focus:border-red-500 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Date</label>
                    <input type="date" name="transfer_date" value="{{ date('Y-m-d') }}" required class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                </div>
                <div>
                    <label class="block text-sm font-bold text-slate-700 mb-1">Note / Description</label>
                    <input type="text" name="note" class="w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 border-t border-slate-100 bg-slate-50 p-4">
                <button type="button" onclick="document.getElementById('transfer-modal').close()" class="rounded-xl px-4 py-2 text-sm font-bold text-slate-600 hover:bg-slate-200">Cancel</button>
                <button type="submit" class="rounded-xl bg-red-600 px-6 py-2 text-sm font-bold text-white hover:bg-red-700">Submit Transfer</button>
            </div>
        </form>
    </dialog>
</x-layouts::app>
