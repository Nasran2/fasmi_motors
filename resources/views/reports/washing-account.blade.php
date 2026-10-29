<x-layouts::app title="Washing Account Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Washing Account Report',
      'subtitle' => 'Washing charges billed, unpaid washing amounts, received payments, expenses and balance. Unpaid charges increase the received balance only after payment settles.',
    ])

    @include('reports._stats')
    <p class="text-xs text-slate-500">Opening and closing balances cover the full period. Transaction filters apply to the displayed movements and filtered totals.</p>

    <section class="panel space-y-4">
      <h2 class="text-lg font-black">Washing charges on bills</h2>
      <p class="text-xs text-slate-500">Billed washing is shown even before payment. Unpaid / held washing is the proportional share of the bill still awaiting settled payment. Charge totals cover all bills in the selected dates; payment and search filters apply to the lists.</p>
      <div class="overflow-auto"><table id="reportTable" class="w-full min-w-[850px] text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-3">Bill date</th><th class="p-3">Invoice / Job</th><th class="p-3">Customer / Bike</th><th class="p-3 text-right">Washing charge</th><th class="p-3 text-right">Unpaid / held washing</th><th class="p-3">Bill status</th></tr></thead>
        <tbody class="divide-y">@forelse($washingBills as $bill)<tr>
          <td class="p-3">{{ $bill->bill_date?->format('d M Y') }}</td>
          <td class="p-3 font-bold">{{ $bill->number }}@if($bill->jobCard)<a href="{{ route('jobs.show', $bill->jobCard) }}" class="mt-1 block text-xs text-blue-700">{{ $bill->jobCard->number }}</a>@endif</td>
          <td class="p-3">{{ $bill->customer?->name ?: $bill->jobCard?->customer?->name ?: 'Walk-in Customer' }}<span class="block text-xs text-slate-500">{{ $bill->bike?->number ?: $bill->jobCard?->bike?->number ?: '—' }}</span></td>
          <td class="p-3 text-right font-bold">Rs. {{ number_format($bill->washing_amount, 2) }}</td>
          <td class="p-3 text-right text-amber-700">Rs. {{ number_format(\App\Support\WashingCharges::unpaid($bill), 2) }}</td><td class="p-3">{{ $bill->status }}</td>
        </tr>@empty<tr><td colspan="6" class="p-8 text-center text-slate-500">No washing charges match these filters.</td></tr>@endforelse</tbody>
      </table></div>
      @if(method_exists($washingBills, 'links')){{ $washingBills->links() }}@endif
    </section>
    <h2 class="text-lg font-black">Received payments and account movements</h2>
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="washing-movements-table" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date</th>
<th class="px-4 py-3 font-bold">Ref</th>
<th class="px-4 py-3 font-bold">Type</th>
<th class="px-4 py-3 font-bold">Description</th>
<th class="px-4 py-3 font-bold">Money In</th>
<th class="px-4 py-3 font-bold">Money Out</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->transaction_date->format("d M Y") }}</td>
<td class="px-4 py-3"><a href="{{ route('reports.transaction-source', $row) }}" target="_blank" rel="noopener" class="font-bold text-blue-700 underline decoration-blue-200 underline-offset-2 hover:text-blue-900" aria-label="Open source for {{ $row->transaction_no }}">{{ $row->transaction_no }}</a></td>
<td class="px-4 py-3">{{ $row->transaction_type }}</td>
<td class="px-4 py-3"><a href="{{ route('reports.transaction-source', $row) }}" target="_blank" rel="noopener" class="text-blue-700 underline decoration-blue-200 underline-offset-2 hover:text-blue-900">{{ $row->description }}</a></td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->credit, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->debit, 2) }}</td>
            </tr>
            @empty
            <tr>
              <td colspan="100%" class="px-4 py-8 text-center text-slate-500">
                <p class="text-base font-bold text-slate-700">No records found for this period.</p>
                <p class="text-xs mt-1">Try changing the date range or filters.</p>
              </td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if(isset($rows) && method_exists($rows, 'links'))
        <div class="p-4 border-t border-slate-100 print:hidden bg-slate-50/50">
          {{ $rows->links() }}
        </div>
      @endif
    </div>
  </div>
</x-layouts::app>