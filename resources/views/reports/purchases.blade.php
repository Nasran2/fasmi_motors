<x-layouts::app title="Purchase Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Purchase Report',
      'subtitle' => 'View spare parts purchases, supplier dues, and purchase payments',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date</th>
<th class="px-4 py-3 font-bold">Purchase No</th>
<th class="px-4 py-3 font-bold">Supplier</th>
<th class="px-4 py-3 font-bold">Net Amount</th>
<th class="px-4 py-3 font-bold">Paid Amount</th>
<th class="px-4 py-3 font-bold">Due Amount</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->purchase_date->format("d M Y") }}</td>
<td class="px-4 py-3">{{ $row->invoice_number }}</td>
<td class="px-4 py-3">{{ $row->supplier?->name }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->total_amount - $row->returned_amount, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->paid_amount, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->due_amount, 2) }}</td>
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