<x-layouts::app title="Customer Due Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Customer Due Report',
      'subtitle' => 'Current customer balances for invoices issued in the selected period, including opening balances.',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Customer</th>
<th class="px-4 py-3 font-bold">Phone</th>
<th class="px-4 py-3 font-bold">Invoices</th>
<th class="px-4 py-3 font-bold">Total Bill</th>
<th class="px-4 py-3 font-bold">Total Paid</th>
<th class="px-4 py-3 font-bold">Held</th>
<th class="px-4 py-3 font-bold">Total Due</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->name }}</td>
<td class="px-4 py-3">{{ $row->phone }}</td>
<td class="px-4 py-3">{{ $row->invoices_count }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->invoices_sum_total, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->invoices_sum_paid_amount, 2) }}</td>
<td class="px-4 py-3">Rs. {{ number_format($row->invoices_sum_hold_amount, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->invoices_sum_due_amount, 2) }}</td>
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