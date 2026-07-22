<x-layouts::app title="EPF Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'EPF Report',
      'subtitle' => 'View employee and company EPF contribution',
    ])
    
    @slot('filters')
      <div>
        <label class="label">Technician</label>
        <select name="technician">
          <option value="">All</option>
          @foreach($technicians as $t)
            <option value="{{ $t->id }}" @selected(request("technician")==$t->id)>{{ $t->name }}</option>
          @endforeach
        </select>
      </div>
    @endslot

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
      @foreach($stats ?? [] as $label => $value)
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm relative overflow-hidden group">
        <div class="absolute right-0 top-0 h-full w-1 bg-red-600 opacity-0 transition-opacity group-hover:opacity-100"></div>
        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold mb-1.5">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
        <p class="text-2xl font-black text-slate-800">
          @if(is_numeric($value))
            Rs. {{ number_format($value, 2) }}
          @else
            {{ $value }}
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
              <th class="px-4 py-3 font-bold">Month</th>
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">Basic Salary</th>
              <th class="px-4 py-3 font-bold">Employee EPF %</th>
              <th class="px-4 py-3 font-bold">Employee EPF Amount</th>
              <th class="px-4 py-3 font-bold">Company EPF %</th>
              <th class="px-4 py-3 font-bold">Company EPF Amount</th>
              <th class="px-4 py-3 font-bold">Total EPF</th>
              <th class="px-4 py-3 font-bold">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->month }}</td>
              <td class="px-4 py-3">{{ $row->technician?->name }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->basic_salary, 2) }}</td>
              <td class="px-4 py-3">{{ $row->employee_epf_percentage }}%</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->employee_epf_amount, 2) }}</td>
              <td class="px-4 py-3">{{ $row->company_epf_percentage }}%</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->company_epf_amount, 2) }}</td>
              <td class="px-4 py-3 font-bold">Rs. {{ number_format($row->employee_epf_amount + $row->company_epf_amount, 2) }}</td>
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