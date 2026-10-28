<x-layouts::app title="SMS Report">
  <div class="space-y-6">
    @include('reports._filter', [
      'title' => 'SMS Report',
      'subtitle' => 'View SMS sent, failed, delivered, and module-wise SMS activity',
    ])

    @include('reports._stats')

    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date</th>
<th class="px-4 py-3 font-bold">Phone</th>
<th class="px-4 py-3 font-bold">Recipient</th>
<th class="px-4 py-3 font-bold">Module</th>
<th class="px-4 py-3 font-bold">Status</th>
<th class="px-4 py-3 font-bold">Response</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3">{{ $row->created_at->format("d M Y h:i A") }}</td>
<td class="px-4 py-3">{{ $row->phone }}</td>
<td class="px-4 py-3">{{ $row->recipient_name }}</td>
<td class="px-4 py-3">{{ $row->module }}</td>
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
<td class="px-4 py-3">{{ $row->api_response }}</td>
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