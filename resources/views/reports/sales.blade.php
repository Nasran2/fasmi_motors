<x-layouts::app title="Sales Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Sales Report',
      'subtitle' => 'View all service, job card, spare parts, and other sales',
    ])
    
    @slot('filters')
      <div class="grid grid-cols-2 gap-3"><label class="label">Type</label><select name="invoice_type"><option value="">All</option><option value="Job Card Bill" @selected(request("invoice_type")=="Job Card Bill")>Job Card Bill</option><option value="Parts Sale" @selected(request("invoice_type")=="Parts Sale")>Parts Sale</option></select><label class="label">Search</label><input type="text" name="search" value="{{ request('search') }}" placeholder="Inv no, customer..."></div>
    @endslot

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
              <th class="px-4 py-3 font-bold">Date</th>
<th class="px-4 py-3 font-bold">Invoice No</th>
<th class="px-4 py-3 font-bold">Invoice Type</th>
<th class="px-4 py-3 font-bold">Customer</th>
<th class="px-4 py-3 font-bold">Bike Number</th>
<th class="px-4 py-3 font-bold">Total Amount</th>
<th class="px-4 py-3 font-bold">Paid Amount</th>
<th class="px-4 py-3 font-bold">Due Amount</th>
<th class="px-4 py-3 font-bold">Payment Method</th>
<th class="px-4 py-3 font-bold">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->created_at->format("d M Y h:i A") }}</td>
<td class="px-4 py-3">{{ $row->number }}</td>
<td class="px-4 py-3">{{ $row->invoice_type }}</td>
<td class="px-4 py-3">{{ $row->bike?->customer?->name }}</td>
<td class="px-4 py-3">{{ $row->bike?->number ?? $row->jobCard?->intake?->bike?->number }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->total, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->paid_amount, 2) }}</td>
<td class="px-4 py-3">{{ "Rs. " . number_format($row->due_amount, 2) }}</td>
<td class="px-4 py-3">{{ $row->payment_method }}</td>
<td class="px-4 py-3">
  @php
    $statusVal = strtolower($row->status ?? '');
    $statusClass = match(true) {
      in_array($statusVal, ['sent', 'success', 'paid', 'completed', 'present', 'active', 'balanced', 'delivered']) => 'badge-sent',
      in_array($statusVal, ['failed', 'cancelled', 'due', 'absent', 'inactive', 'difference found', 'overdue', 'error']) => 'badge-failed',
      default => 'badge-pending'
    };
  @endphp
  <span class="{{ $statusClass }}">{{ $row->status }}</span>
</td>
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