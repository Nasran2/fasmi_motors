<x-layouts::app title="Technician Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'Technician Report',
      'subtitle' => 'Primary assigned jobs, earned commissions, attendance, and amounts paid on salary sheets ending in this period.',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Technician</th>
              <th class="px-4 py-3 font-bold">Status</th>
              <th class="px-4 py-3 font-bold">Total Jobs</th>
              <th class="px-4 py-3 font-bold">Completed</th>
              <th class="px-4 py-3 font-bold">Commissions</th>
              <th class="px-4 py-3 font-bold">OT Payouts</th>
              <th class="px-4 py-3 font-bold">Attendance Deductions</th>
              <th class="px-4 py-3 font-bold">Paid on Period Sheets</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 font-bold">{{ $row->name }}</td>
              <td class="px-4 py-3"><span class="{{ $row->active ? 'badge-sent' : 'badge-failed' }}">{{ $row->active ? 'Active' : 'Inactive' }}</span></td>
              <td class="px-4 py-3">{{ $row->jobs_count }}</td>
              <td class="px-4 py-3">{{ $row->completed_jobs }}</td>
              <td class="px-4 py-3">Rs. {{ number_format($row->commissions_sum_amount, 2) }}</td>
              <td class="px-4 py-3 text-green-600">Rs. {{ number_format($row->total_ot_amount, 2) }}</td>
              <td class="px-4 py-3 text-red-600">Rs. {{ number_format($row->total_attendance_deductions, 2) }}</td>
              <td class="px-4 py-3 font-black text-slate-800">Rs. {{ number_format($row->total_salary_paid, 2) }}</td>
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