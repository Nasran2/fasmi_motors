<x-layouts::app title="Salary Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'from' => $from, 'to' => $to, 'range' => $range,
      'title' => 'Salary Report',
      'subtitle' => 'Whole salary sheets overlapping this period. Drafts are excluded unless selected.',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Period</th>
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">Gross Salary</th>
              <th class="px-4 py-3 font-bold">OT Payout</th>
              <th class="px-4 py-3 font-bold">Advances</th>
              <th class="px-4 py-3 font-bold">Deductions</th>
              <th class="px-4 py-3 font-bold">Net Salary</th>
              <th class="px-4 py-3 font-bold">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->period_start->format("d M Y")." - ".$row->period_end->format("d M Y") }}</td>
              <td class="px-4 py-3">{{ $row->technician?->name }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->gross_salary, 2) }}</td>
              <td class="px-4 py-3 text-green-600">Rs. {{ number_format($row->ot_amount, 2) }}</td>
              <td class="px-4 py-3 text-red-600">Rs. {{ number_format($row->advances, 2) }}</td>
              <td class="px-4 py-3 text-red-600">Rs. {{ number_format($row->deductions, 2) }}</td>
              <td class="px-4 py-3 font-bold">Rs. {{ number_format($row->net_payable, 2) }}</td>
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
