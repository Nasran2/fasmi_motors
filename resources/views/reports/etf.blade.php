<x-layouts::app title="ETF Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'from' => $from, 'to' => $to, 'range' => $range,
      'title' => 'ETF Report',
      'subtitle' => 'View company ETF contribution',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Month</th>
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">EPF / ETF salary basic</th>
              <th class="px-4 py-3 font-bold">Company ETF %</th>
              <th class="px-4 py-3 font-bold">Company ETF Amount</th>
              <th class="px-4 py-3 font-bold">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->month }}</td>
              <td class="px-4 py-3">{{ $row->technician?->name }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->basic_salary, 2) }}</td>
              <td class="px-4 py-3">{{ $row->company_etf_percentage }}%</td>
              <td class="px-4 py-3 font-bold">Rs. {{ number_format($row->company_etf_amount, 2) }}</td>
              <td class="px-4 py-3"><span class="badge-sent">{{ $row->status }}</span></td>
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
