<x-layouts::app title="Daily Cash Book Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Daily Cash Book Report',
      'subtitle' => 'Cash received and paid from Main and Spare Parts, in the order entries were added. Each entry shows its running cash balance.',
    ])

    <div class="panel border border-slate-200 bg-white">
      <div class="flex flex-wrap items-start justify-between gap-4">
        <div data-print-summary>
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Main Account opening carried forward</p>
          <p class="mt-2 text-2xl font-black text-slate-900">Rs. {{ number_format($mainOpening, 2) }}</p>
        </div>
        <div data-print-summary class="text-right">
          <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Opening cash balance for this period</p>
          <p class="mt-2 text-2xl font-black text-slate-900">Rs. {{ number_format($opening, 2) }}</p>
        </div>
      </div>
      <p class="mt-3 text-sm text-slate-500">Opening cash = Main opening + earlier cash carried forward (Rs. {{ number_format($broughtForward, 2) }}). Card, bank, cheque and credit sales are excluded.</p>
    </div>

    @include('reports._stats')
    @if($filtered)
      <p role="status" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Showing matching entries. Opening, running and closing balances include all cash entries in this period, including entries hidden by these filters.</p>
    @endif

    <div class="panel p-0 overflow-hidden border border-slate-200 bg-white">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm">
          <thead class="border-b border-slate-200 bg-slate-50 text-slate-600">
            <tr>
              <th class="px-3 py-3">Entry</th>
              <th class="px-3 py-3">Payment / entry date</th>
              <th class="px-3 py-3">Reference / Description</th>
              <th class="px-3 py-3 text-right">Cash in (Rs.)</th>
              <th class="px-3 py-3 text-right">Cash out (Rs.)</th>
              <th class="px-3 py-3 text-right">Balance (Rs.)</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            <tr class="bg-slate-50 font-bold">
              <td class="px-3 py-3">Opening</td>
              <td class="px-3 py-3 whitespace-nowrap">{{ $range === 'all_time' ? 'All time' : $from->format('d M Y') }}</td>
              <td class="px-3 py-3">Main opening amount + earlier cash movements</td>
              <td></td><td></td><td class="px-3 py-3 text-right">{{ number_format($opening, 2) }}</td>
            </tr>
            @forelse($rows as $row)
              <tr class="hover:bg-slate-50">
                <td class="px-3 py-3">{{ $row->cash_book_sequence }}</td>
                <td class="px-3 py-3 whitespace-nowrap">
                  {{ $row->transaction_date->format('d M Y') }}<br><span class="text-xs text-slate-500">{{ $row->display_time }}</span>
                  @if($row->created_at?->toDateString() !== $row->transaction_date->toDateString())
                    <br><span class="text-xs text-slate-500">Recorded: {{ $row->created_at?->format('d M Y h:i A') }}</span>
                  @endif
                </td>
                <td class="px-3 py-3 max-w-sm break-words">
                  <p class="font-bold">{{ $row->source_number }}</p>
                  <p>{{ $row->description }}</p>
                  <p class="mt-1 text-xs text-slate-500">{{ $row->account?->name }} · {{ ucwords(str_replace('_', ' ', $row->transaction_type)) }}{{ $row->status === 'reversed' ? ' (reversed)' : '' }} · Added by {{ $row->creator?->name ?? '—' }}</p>
                  @if($row->source_number !== $row->transaction_no)<p class="text-xs text-slate-500">{{ $row->transaction_no }}</p>@endif
                </td>
                <td class="px-3 py-3 text-right whitespace-nowrap text-green-700">{{ number_format($row->credit, 2) }}</td>
                <td class="px-3 py-3 text-right whitespace-nowrap text-red-600">{{ number_format($row->debit, 2) }}</td>
                <td class="px-3 py-3 text-right whitespace-nowrap font-bold">{{ number_format($row->cash_book_balance, 2) }}</td>
              </tr>
            @empty
              <tr><td colspan="6" class="px-4 py-8 text-center text-slate-500">{{ $filtered ? 'No cash entries match these filters.' : 'No cash entries for this period. The closing balance equals the opening balance.' }}</td></tr>
            @endforelse
          </tbody>
          <tfoot class="border-t-2 border-slate-200 bg-slate-50 font-bold">
            <tr>
              <td class="px-3 py-3">Period totals</td><td></td><td class="px-3 py-3">All cash entries in this period</td>
              <td class="px-3 py-3 text-right whitespace-nowrap">{{ number_format($stats['cash_in_amount'], 2) }}</td>
              <td class="px-3 py-3 text-right whitespace-nowrap">{{ number_format($stats['cash_out_amount'], 2) }}</td><td></td>
            </tr>
            <tr>
              <td class="px-3 py-4">Closing</td><td class="px-3 py-4 whitespace-nowrap">{{ $range === 'all_time' ? 'All time' : $to->format('d M Y') }}</td>
              <td class="px-3 py-4">Opening + cash in − cash out</td><td></td><td></td>
              <td class="px-3 py-4 text-right whitespace-nowrap text-lg">{{ number_format($closing, 2) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <div class="panel flex flex-wrap items-center justify-between gap-3 border border-slate-200 bg-white">
      <span class="text-sm font-bold uppercase tracking-wide text-slate-500">Closing cash balance</span>
      <span class="text-2xl font-black {{ $closing < 0 ? 'text-red-600' : 'text-green-700' }}">Rs. {{ number_format($closing, 2) }}</span>
    </div>
    <p class="text-xs text-slate-500">This book shows posted cash ledger entries, including reversals and allocation corrections. Repair receipts may have separate Main and Spare Parts entries; their amounts add up to the receipt.</p>
  </div>
</x-layouts::app>
