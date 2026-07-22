<x-layouts::app title="Stock Report">
  <div class="space-y-6">
    @component('reports._filter', [
      'title' => 'Stock Report',
      'subtitle' => 'View current stock, stock value, low stock, and batch-wise inventory',
    ])
    
    @slot('filters')
      <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
        <div>
            <label class="label block mb-1">Status</label>
            <select name="status" class="w-full">
                <option value="">All</option>
                <option value="in" @selected(request("status")=="in")>In Stock</option>
                <option value="low" @selected(request("status")=="low")>Low Stock</option>
                <option value="out" @selected(request("status")=="out")>Out of Stock</option>
            </select>
        </div>
        <div>
            <label class="label block mb-1">Rack No (Hold Ctrl to select multiple)</label>
            <select name="racks[]" multiple class="w-full p-2 border border-slate-200 rounded">
                @foreach($rackOptions as $rack)
                    <option value="{{ $rack }}" @selected(in_array($rack, request('racks', [])))>{{ $rack }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="label block mb-1">Search</label>
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Product name, code..." class="w-full">
        </div>
      </div>
    @endslot
    @endcomponent

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:grid-cols-4">
      @foreach($stats ?? [] as $label => $value)
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm relative overflow-hidden group">
        <div class="absolute right-0 top-0 h-full w-1 bg-red-600 opacity-0 transition-opacity group-hover:opacity-100"></div>
        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold mb-1.5">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
        <p class="text-2xl font-black {{ str_contains($label, 'expense') || str_contains($label, 'due') || str_contains($label, 'absent') ? 'text-red-600' : (str_contains($label, 'profit') || str_contains($label, 'income') || str_contains($label, 'sales') || str_contains($label, 'present') ? 'text-green-600' : 'text-slate-800') }}">
          @if(is_numeric($value) && (str_contains($label, 'total') || str_contains($label, 'amount') || str_contains($label, 'sales') || str_contains($label, 'cost') || str_contains($label, 'profit') || str_contains($label, 'income') || str_contains($label, 'balance') || str_contains($label, 'value') || str_contains($label, 'gross') || str_contains($label, 'net') || str_contains($label, 'epf') || str_contains($label, 'advances')))
            Rs. {{ number_format($value, 2) }}
          @else
            {{ is_numeric($value) ? number_format($value) : $value }}
          @endif
        </p>
      </div>
      @endforeach
    </div>

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
<td class="px-4 py-3">{{ $row->stocks_sum_quantity }}</td>
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