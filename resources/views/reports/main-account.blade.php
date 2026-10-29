<x-layouts::app title="Main Account Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Main Account Report',
      'subtitle' => 'View service income, expenses, drawings, transfers, and balance',
    ])

    @include('reports._stats')
    <p class="text-xs text-slate-500">Opening and closing balances cover the full period. Transaction filters apply to the displayed movements and filtered totals.</p>

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
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