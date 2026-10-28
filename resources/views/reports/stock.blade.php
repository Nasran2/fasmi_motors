<x-layouts::app title="Stock Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Stock Report',
      'subtitle' => 'Current stock snapshot. Values use current product cost and selling prices; select a store to see its stock.',
      'from' => $from ?? null,
      'to' => $to ?? null,
      'range' => $range ?? null,
      'snapshot' => true
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Product</th>
<th class="px-4 py-3 font-bold">Rack No</th>
<th class="px-4 py-3 font-bold">Category</th>
<th class="px-4 py-3 font-bold">In Stock</th>
<th class="px-4 py-3 font-bold">Min Stock</th>
<th class="px-4 py-3 font-bold">Cost Price</th>
<th class="px-4 py-3 font-bold">Selling Price</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->name }}</td>
<td class="px-4 py-3">{{ $row->rack_number }}</td>
<td class="px-4 py-3">{{ $row->category?->name }}</td>
<td class="px-4 py-3">{{ $row->stocks_sum_quantity ?? 0 }}</td>
<td class="px-4 py-3">{{ $row->minimum_stock }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->cost_price, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->selling_price, 2) }}</td>
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