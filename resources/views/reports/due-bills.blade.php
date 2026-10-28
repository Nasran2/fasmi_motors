<x-layouts::app title="Due Bills Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Due Bills Report',
      'subtitle' => 'Current outstanding balances, filtered by invoice issue date. Held cheques are shown separately; opening balances are included.',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date</th>
<th class="px-4 py-3 font-bold">Invoice No</th>
<th class="px-4 py-3 font-bold">Customer</th>
<th class="px-4 py-3 font-bold">Phone</th>
<th class="px-4 py-3 font-bold">Total Bill</th>
<th class="px-4 py-3 font-bold">Paid</th>
<th class="px-4 py-3 font-bold">Held</th>
<th class="px-4 py-3 font-bold">Due</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->created_at->format("d M Y") }}</td>
<td class="px-4 py-3">{{ $row->number }}</td>
<td class="px-4 py-3">{{ $row->customer?->name ?? $row->jobCard?->customer?->name ?? 'Walk-in' }}</td>
<td class="px-4 py-3">{{ $row->customer?->phone ?? $row->jobCard?->customer?->phone }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->total, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->paid_amount, 2) }}</td>
<td class="px-4 py-3">Rs. {{ number_format($row->hold_amount, 2) }}</td>
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