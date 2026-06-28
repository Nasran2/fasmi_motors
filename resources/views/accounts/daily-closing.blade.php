<x-layouts::app :title="__('Accounts')">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-black italic tracking-tight text-[#0b2447]">
                DAILY <span class="text-red-600">CLOSING</span>
            </h1>
        </div>

        @if(!$isClosed)
        <div class="rounded-2xl border-2 border-emerald-500 bg-emerald-50 p-6 shadow-sm">
            <div class="mb-4 flex items-center justify-between border-b border-emerald-200 pb-4">
                <div>
                    <h2 class="text-xl font-black text-emerald-900">Close Today's Accounts</h2>
                    <p class="text-sm font-medium text-emerald-700">Date: {{ date('d M Y') }}</p>
                </div>
            </div>

            <form action="{{ route('accounts.daily-closing.store') }}" method="POST">
                @csrf
                <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                    <!-- Main Account Expected -->
                    <div class="rounded-xl border border-emerald-200 bg-white p-4">
                        <p class="text-xs font-bold uppercase text-emerald-600 mb-2">Main Account</p>
                        <div class="space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">Opening:</span><span class="font-bold">Rs. {{ number_format($expected['main']['opening'], 2) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Income:</span><span class="font-bold text-emerald-600">+ {{ number_format($expected['main']['income'], 2) }}</span></div>
                            <div class="flex justify-between border-b pb-1"><span class="text-slate-500">Expense:</span><span class="font-bold text-red-600">- {{ number_format($expected['main']['expense'], 2) }}</span></div>
                            <div class="flex justify-between pt-1"><span class="font-bold text-slate-700">Expected Closing:</span><span class="font-black text-slate-900">Rs. {{ number_format($expected['main']['closing'], 2) }}</span></div>
                        </div>
                    </div>
                    
                    <!-- Spare Parts Expected -->
                    <div class="rounded-xl border border-emerald-200 bg-white p-4">
                        <p class="text-xs font-bold uppercase text-emerald-600 mb-2">Spare Parts</p>
                        <div class="space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">Opening:</span><span class="font-bold">Rs. {{ number_format($expected['spare_parts']['opening'], 2) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Income:</span><span class="font-bold text-emerald-600">+ {{ number_format($expected['spare_parts']['income'], 2) }}</span></div>
                            <div class="flex justify-between border-b pb-1"><span class="text-slate-500">Expense:</span><span class="font-bold text-red-600">- {{ number_format($expected['spare_parts']['expense'], 2) }}</span></div>
                            <div class="flex justify-between pt-1"><span class="font-bold text-slate-700">Expected Closing:</span><span class="font-black text-slate-900">Rs. {{ number_format($expected['spare_parts']['closing'], 2) }}</span></div>
                        </div>
                    </div>

                    <!-- Bank Expected -->
                    <div class="rounded-xl border border-emerald-200 bg-white p-4">
                        <p class="text-xs font-bold uppercase text-emerald-600 mb-2">Bank</p>
                        <div class="space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">Opening:</span><span class="font-bold">Rs. {{ number_format($expected['bank']['opening'], 2) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Income:</span><span class="font-bold text-emerald-600">+ {{ number_format($expected['bank']['income'], 2) }}</span></div>
                            <div class="flex justify-between border-b pb-1"><span class="text-slate-500">Expense:</span><span class="font-bold text-red-600">- {{ number_format($expected['bank']['expense'], 2) }}</span></div>
                            <div class="flex justify-between pt-1"><span class="font-bold text-slate-700">Expected Closing:</span><span class="font-black text-slate-900">Rs. {{ number_format($expected['bank']['closing'], 2) }}</span></div>
                        </div>
                    </div>

                    <!-- Cash Expected -->
                    <div class="rounded-xl border border-emerald-200 bg-white p-4">
                        <p class="text-xs font-bold uppercase text-emerald-600 mb-2">Cash Book</p>
                        <div class="space-y-1 text-sm">
                            <div class="flex justify-between"><span class="text-slate-500">Opening:</span><span class="font-bold">Rs. {{ number_format($expected['cash']['opening'], 2) }}</span></div>
                            <div class="flex justify-between"><span class="text-slate-500">Income:</span><span class="font-bold text-emerald-600">+ {{ number_format($expected['cash']['income'], 2) }}</span></div>
                            <div class="flex justify-between border-b pb-1"><span class="text-slate-500">Expense:</span><span class="font-bold text-red-600">- {{ number_format($expected['cash']['expense'], 2) }}</span></div>
                            <div class="flex justify-between pt-1"><span class="font-bold text-slate-700">Expected Closing:</span><span class="font-black text-slate-900">Rs. {{ number_format($expected['cash']['closing'], 2) }}</span></div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex flex-col gap-4 rounded-xl bg-white p-4 lg:flex-row lg:items-end">
                    <div class="flex-1">
                        <label class="block text-sm font-bold text-slate-700">Physical Cash/Bank Difference (Rs)</label>
                        <input type="number" step="0.01" name="difference" value="0" required class="mt-1 w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                    </div>
                    <div class="flex-2 w-full lg:w-1/3">
                        <label class="block text-sm font-bold text-slate-700">Reason for Difference (If any)</label>
                        <input type="text" name="difference_reason" class="mt-1 w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                    </div>
                    <div class="flex-2 w-full lg:w-1/3">
                        <label class="block text-sm font-bold text-slate-700">Notes</label>
                        <input type="text" name="notes" class="mt-1 w-full rounded-xl border-slate-200 focus:border-red-500 focus:ring-red-500">
                    </div>
                    <button type="submit" onclick="return confirm('Are you sure you want to finalize today\'s closing? This cannot be undone.')" class="h-[42px] whitespace-nowrap rounded-xl bg-[#0b2447] px-8 font-bold text-white hover:bg-slate-800">
                        Finalize & Close Day
                    </button>
                </div>
            </form>
        </div>
        @else
        <div class="rounded-2xl bg-slate-100 p-8 text-center border-2 border-slate-200">
            <span class="mx-auto mb-4 grid size-16 place-items-center rounded-full bg-emerald-100 text-emerald-600">
                <svg class="size-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
            </span>
            <h2 class="text-2xl font-black text-slate-800">Today's Accounts are Closed</h2>
            <p class="text-slate-500 mt-2">All ledgers have been finalized for today.</p>
        </div>
        @endif

        <!-- Past Closings -->
        <h2 class="mt-8 text-lg font-black text-[#0b2447]">Past Closings History</h2>
        <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-bold uppercase text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Date</th>
                            <th class="px-4 py-3 text-right">Main Acc</th>
                            <th class="px-4 py-3 text-right">Spare Parts</th>
                            <th class="px-4 py-3 text-right">Bank</th>
                            <th class="px-4 py-3 text-right">Cash</th>
                            <th class="px-4 py-3 text-right">Difference</th>
                            <th class="px-4 py-3">Closed By</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse($closings as $closing)
                        <tr class="hover:bg-slate-50">
                            <td class="whitespace-nowrap px-4 py-3 font-bold">{{ $closing->closing_date->format('d M Y') }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">Rs. {{ number_format($closing->main_closing_balance, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">Rs. {{ number_format($closing->spare_parts_closing_balance, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">Rs. {{ number_format($closing->bank_closing_balance, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-medium">Rs. {{ number_format($closing->cash_closing_balance, 2) }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right font-bold {{ $closing->difference != 0 ? 'text-red-600' : 'text-emerald-600' }}">
                                {{ $closing->difference != 0 ? number_format($closing->difference, 2) : 'Matched' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $closing->closer?->name ?? 'System' }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-slate-500">No previous closings found.</td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($closings->hasPages())
                <div class="border-t border-slate-100 px-4 py-3">
                    {{ $closings->links() }}
                </div>
            @endif
        </div>
    </div>
</x-layouts::app>
