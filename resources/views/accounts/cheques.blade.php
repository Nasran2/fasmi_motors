<x-layouts::app :title="__('Accounts')">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-black italic tracking-tight text-[#0b2447]">
                CHEQUE <span class="text-red-600">PAYMENTS</span>
            </h1>
        </div>

        <!-- Summary Cards -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Total Received</p>
                <p class="mt-2 text-2xl font-black text-slate-800">Rs. {{ number_format($summary['received'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Total Issued</p>
                <p class="mt-2 text-2xl font-black text-slate-800">Rs. {{ number_format($summary['issued'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-amber-600">Pending</p>
                <p class="mt-2 text-2xl font-black text-amber-700">Rs. {{ number_format($summary['pending'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-emerald-600">Cleared</p>
                <p class="mt-2 text-2xl font-black text-emerald-700">Rs. {{ number_format($summary['cleared'], 2) }}</p>
            </div>
            <div class="rounded-2xl border border-red-200 bg-red-50 p-5 shadow-sm">
                <p class="text-xs font-bold uppercase tracking-wider text-red-600">Returned</p>
                <p class="mt-2 text-2xl font-black text-red-700">Rs. {{ number_format($summary['returned'], 2) }}</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            <form method="GET" class="flex flex-wrap items-end gap-4">
                <div class="flex-1 min-w-[200px]">
                    <label class="mb-1 block text-xs font-bold text-slate-600">Type</label>
                    <select name="type" class="w-full rounded-xl border-slate-200 text-sm focus:border-red-500 focus:ring-red-500">
                        <option value="">All Types</option>
                        <option value="Received" {{ request('type') == 'Received' ? 'selected' : '' }}>Received (Customer)</option>
                        <option value="Issued" {{ request('type') == 'Issued' ? 'selected' : '' }}>Issued (Supplier)</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[200px]">
                    <label class="mb-1 block text-xs font-bold text-slate-600">Status</label>
                    <select name="status" class="w-full rounded-xl border-slate-200 text-sm focus:border-red-500 focus:ring-red-500">
                        <option value="">All Statuses</option>
                        <option value="Pending" {{ request('status') == 'Pending' ? 'selected' : '' }}>Pending</option>
                        <option value="Deposited" {{ request('status') == 'Deposited' ? 'selected' : '' }}>Deposited</option>
                        <option value="Cleared" {{ request('status') == 'Cleared' ? 'selected' : '' }}>Cleared</option>
                        <option value="Returned" {{ request('status') == 'Returned' ? 'selected' : '' }}>Returned</option>
                        <option value="Cancelled" {{ request('status') == 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                    </select>
                </div>
                <button type="submit" class="rounded-xl bg-[#0b2447] px-6 py-2.5 text-sm font-bold text-white hover:bg-slate-800">Filter</button>
            </form>
        </div>

        <!-- Table -->
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Type</th>
                            <th class="px-4 py-3">Cheque No</th>
                            <th class="px-4 py-3">Bank Details</th>
                            <th class="px-4 py-3">Cheque Date</th>
                            <th class="px-4 py-3">Amount</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($cheques as $cheque)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 font-bold {{ $cheque->cheque_type === 'Received' ? 'text-emerald-600' : 'text-slate-600' }}">
                                {{ $cheque->cheque_type }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 font-bold text-slate-800">{{ $cheque->cheque_number }}</td>
                            <td class="px-4 py-3">
                                <p class="font-bold text-slate-700">{{ $cheque->bank_name }}</p>
                                @if($cheque->branch) <p class="text-xs text-slate-500">{{ $cheque->branch }}</p> @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $cheque->cheque_date->format('d M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 font-black text-slate-900">Rs. {{ number_format($cheque->amount, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3">
                                @php
                                    $colors = [
                                        'Pending' => 'bg-amber-100 text-amber-700',
                                        'Deposited' => 'bg-blue-100 text-blue-700',
                                        'Cleared' => 'bg-emerald-100 text-emerald-700',
                                        'Returned' => 'bg-red-100 text-red-700',
                                        'Cancelled' => 'bg-slate-100 text-slate-700',
                                    ];
                                @endphp
                                <span class="rounded-lg px-2 py-1 text-xs font-bold uppercase {{ $colors[$cheque->status] ?? 'bg-slate-100 text-slate-600' }}">
                                    {{ $cheque->status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <form action="{{ route('accounts.cheques.status', $cheque) }}" method="POST" class="flex items-center gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <select name="status" onchange="this.form.submit()" class="rounded-lg border-slate-200 text-xs font-bold text-slate-600 focus:border-red-500 focus:ring-red-500">
                                        <option value="Pending" {{ $cheque->status == 'Pending' ? 'selected' : '' }}>Pending</option>
                                        <option value="Deposited" {{ $cheque->status == 'Deposited' ? 'selected' : '' }}>Deposited</option>
                                        <option value="Cleared" {{ $cheque->status == 'Cleared' ? 'selected' : '' }}>Cleared</option>
                                        <option value="Returned" {{ $cheque->status == 'Returned' ? 'selected' : '' }}>Returned</option>
                                        <option value="Cancelled" {{ $cheque->status == 'Cancelled' ? 'selected' : '' }}>Cancelled</option>
                                    </select>
                                    @if($cheque->status !== 'Cleared')
                                        <input type="date" name="realized_date" value="{{ date('Y-m-d') }}" class="rounded-lg border-slate-200 text-xs focus:border-red-500 focus:ring-red-500 hidden group-hover:block" title="Realized Date (Required for Cleared)">
                                    @else
                                        <span class="text-xs text-slate-400 block">{{ $cheque->realized_date?->format('d/m/Y') }}</span>
                                    @endif
                                </form>
                            </td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">No cheques found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($cheques->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $cheques->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
