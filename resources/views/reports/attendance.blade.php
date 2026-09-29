<x-layouts::app title="Attendance Report">
  <div class="space-y-6">
    @component('reports._filter', [
      'from' => $from, 'to' => $to, 'range' => $range,
      'title' => 'Attendance Report',
      'subtitle' => 'View technician attendance, working hours, OT, late, and leave',
    ])
    
    @slot('filters')
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="label">Technician</label>
          <select name="technician">
            <option value="">All</option>
            @foreach($technicians as $t)
            <option value="{{ $t->id }}" @selected(request("technician")==$t->id)>{{ $t->name }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="label">Status</label>
          <select name="status">
            <option value="">All</option>
            @foreach(['Present','Absent','Late','Half Day','Morning Half Day','Evening Half Day','Leave','Paid Leave','No Pay Leave','Holiday','Early Leave'] as $status)
            <option value="{{ $status }}" @selected(request("status")==$status)>{{ $status }}</option>
            @endforeach
          </select>
        </div>
      </div>
    @endslot
    @endcomponent

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
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
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">Status</th>
              <th class="px-4 py-3 font-bold">In Time</th>
              <th class="px-4 py-3 font-bold">Out Time</th>
              <th class="px-4 py-3 font-bold">Late Mins</th>
              <th class="px-4 py-3 font-bold">Early Leave</th>
              <th class="px-4 py-3 font-bold">OT Payout</th>
              <th class="px-4 py-3 font-bold">Deductions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            @php
              $statusVal = strtolower($row->status ?? '');
              $statusClass = match(true) {
                in_array($statusVal, ['present', 'active', 'paid', 'completed']) => 'badge-sent',
                in_array($statusVal, ['absent', 'inactive', 'failed', 'due']) => 'badge-failed',
                default => 'badge-pending'
              };
            @endphp
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 font-mono">{{ $row->date->format("d M Y") }}</td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ $row->technician?->name }}</td>
              <td class="px-4 py-3">
                <span class="{{ $statusClass }}">{{ $row->status }}</span>
              </td>
              <td class="px-4 py-3 font-mono">{{ $row->in_time ? Carbon\Carbon::parse($row->in_time)->format("h:i A") : '—' }}</td>
              <td class="px-4 py-3 font-mono">{{ $row->out_time ? Carbon\Carbon::parse($row->out_time)->format("h:i A") : '—' }}</td>
              <td class="px-4 py-3">
                @if($row->late_minutes > 0)
                <span class="text-orange-600 font-bold font-mono">{{ $row->late_minutes }} min</span>
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3">
                @if($row->early_leave_minutes > 0)
                <span class="text-orange-500 font-bold font-mono">{{ $row->early_leave_minutes }} min</span>
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3 text-green-600 font-bold">
                @if($row->ot_amount > 0)
                Rs. {{ number_format($row->ot_amount, 2) }}
                @else
                —
                @endif
              </td>
              <td class="px-4 py-3 text-red-600 font-bold">
                @if($row->deduction_amount > 0)
                Rs. {{ number_format($row->deduction_amount, 2) }}
                @else
                —
                @endif
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
