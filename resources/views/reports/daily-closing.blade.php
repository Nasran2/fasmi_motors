<x-layouts::app title="Daily Closing Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Daily Closing Report',
      'subtitle' => 'Saved daily ledger totals. Main, spare parts and washing include bank/cash movements; physical ledgers are not added again.',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date</th>
              <th class="px-4 py-3 font-bold">Main Income</th>
              <th class="px-4 py-3 font-bold">Spare Parts Income</th>
              <th class="px-4 py-3 font-bold">Washing Income</th><th class="px-4 py-3 font-bold">Bank Income</th><th class="px-4 py-3 font-bold">Total Money Out</th>
              <th class="px-4 py-3 font-bold">Closed By</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->closing_date->format("d M Y") }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->main_income, 2) }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->spare_parts_income, 2) }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->washing_income, 2) }}</td><td class="px-4 py-3">Rs. {{ number_format($row->bank_income, 2) }}</td><td class="px-4 py-3">Rs. {{ number_format($row->main_expense + $row->spare_parts_expense + $row->washing_expense, 2) }}</td>
              <td class="px-4 py-3">{{ $row->closer?->name }}</td>
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