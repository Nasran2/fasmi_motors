<x-layouts::app title="Activity Audit Logs">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">User Management</p>
        <h1 class="page-title">Activity Logs</h1>
        <p class="page-subtitle">Track important actions done by users</p>
      </div>
      <div class="flex flex-wrap gap-2 print:hidden">
        <button onclick="window.workshopPrint(event)" class="btn-secondary flex items-center gap-1.5">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
          Print / PDF
        </button>
        <button onclick="exportTable()" class="btn-secondary flex items-center gap-1.5">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Export CSV
        </button>
      </div>
    </div>

    {{-- Help Box --}}
    <div class="rounded-xl border border-blue-100 bg-blue-50/50 p-4 text-sm text-blue-700 leading-relaxed print:hidden">
      ℹ️ <strong>Activity Tracking:</strong> This page logs modifications, additions, deletions, password resets, and critical config updates. Log records cannot be erased.
    </div>

    {{-- Summary Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5 print:grid-cols-5">
      @foreach($stats as $label => $value)
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm relative overflow-hidden group">
        <div class="absolute right-0 top-0 h-full w-1 bg-red-600 opacity-0 transition-opacity group-hover:opacity-100"></div>
        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold mb-1">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
        <p class="text-2xl font-black text-slate-800">
          {{ number_format($value) }}
        </p>
      </div>
      @endforeach
    </div>

    {{-- Filters --}}
    <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm p-4 print:hidden">
      <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
        <div>
          <label class="label">From Date</label>
          <input type="date" name="from" value="{{ $from ? $from->toDateString() : '' }}" class="w-full">
        </div>
        <div>
          <label class="label">To Date</label>
          <input type="date" name="to" value="{{ $to ? $to->toDateString() : '' }}" class="w-full">
        </div>
        <div>
          <label class="label">User</label>
          <select name="user_id" class="w-full">
            <option value="">All Users</option>
            @foreach($users as $user)
            <option value="{{ $user->id }}" @selected(request('user_id') == $user->id)>{{ $user->username }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="label">Module</label>
          <select name="module_name" class="w-full">
            <option value="">All Modules</option>
            @foreach($modules as $mod)
            <option value="{{ $mod }}" @selected(request('module_name') == $mod)>{{ $mod }}</option>
            @endforeach
          </select>
        </div>
        <div>
          <label class="label">Action</label>
          <select name="action_type" class="w-full">
            <option value="">All Actions</option>
            @foreach($actions as $act)
            <option value="{{ $act }}" @selected(request('action_type') == $act)>{{ $act }}</option>
            @endforeach
          </select>
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary flex-1">Apply</button>
          <a href="{{ route('user-management.activity-logs.index') }}" class="btn-secondary">Reset</a>
        </div>
      </form>
    </div>

    {{-- Print Header --}}
    <div class="hidden print:block mb-6 border-b-2 border-slate-900 pb-3">
      <h1 class="text-2xl font-black">FASMIR MOTORS</h1>
      <p class="text-sm text-slate-500">Activity Logs Report ({{ $from->format('d M Y') }} — {{ $to->format('d M Y') }})</p>
    </div>

    {{-- Table --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date / Time</th>
              <th class="px-4 py-3 font-bold">User</th>
              <th class="px-4 py-3 font-bold">Module</th>
              <th class="px-4 py-3 font-bold">Action</th>
              <th class="px-4 py-3 font-bold">Description</th>
              <th class="px-4 py-3 font-bold">IP Address</th>
              <th class="px-4 py-3 font-bold text-right print:hidden">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 text-xs font-mono text-slate-600">{{ $row->created_at->format('d M Y h:i A') }}</td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ $row->user?->username ?: 'System' }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">{{ $row->module }}</td>
              <td class="px-4 py-3">
                @php
                  $color = match(strtolower($row->action)) {
                    'created' => 'badge-sent',
                    'updated' => 'badge-pending',
                    'deleted', 'deactivated' => 'badge-failed',
                    default => 'badge-pending'
                  };
                @endphp
                <span class="{{ $color }}">{{ $row->action }}</span>
              </td>
              <td class="px-4 py-3 text-slate-700 text-xs max-w-sm truncate">{{ $row->description }}</td>
              <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $row->ip_address }}</td>
              <td class="px-4 py-3 text-right print:hidden">
                @if($row->old_values || $row->new_values)
                <button onclick="viewLogDetail('{{ $row->id }}')" class="btn-secondary !py-0.5 !px-2 text-xs">View Values</button>
                <div class="hidden" id="log-old-{{ $row->id }}">{!! json_encode($row->old_values, JSON_PRETTY_PRINT) !!}</div>
                <div class="hidden" id="log-new-{{ $row->id }}">{!! json_encode($row->new_values, JSON_PRETTY_PRINT) !!}</div>
                @else
                <span class="text-xs text-slate-400">—</span>
                @endif
              </td>
            </tr>
            @empty
            <tr>
              <td colspan="100%" class="px-4 py-8 text-center text-slate-500">No logs found for this filter combination.</td>
            </tr>
            @endforelse
          </tbody>
        </table>
      </div>
      @if(method_exists($rows, 'links'))
      <div class="p-4 border-t border-slate-100 bg-slate-50/50 print:hidden">
        {{ $rows->links() }}
      </div>
      @endif
    </div>

    {{-- Detail Modal --}}
    <dialog id="logModal" class="rounded-xl border border-slate-200 bg-white p-6 shadow-2xl w-full max-w-2xl">
      <h3 class="text-lg font-black text-slate-800 mb-4">🔍 Audit Value Comparison</h3>
      
      <div class="grid gap-4 md:grid-cols-2">
        <div>
          <label class="label">Old Value</label>
          <pre id="oldJson" class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-mono overflow-auto max-h-80 whitespace-pre-wrap"></pre>
        </div>
        <div>
          <label class="label">New Value</label>
          <pre id="newJson" class="p-3 bg-slate-50 border border-slate-200 rounded-lg text-[10px] font-mono overflow-auto max-h-80 whitespace-pre-wrap"></pre>
        </div>
      </div>
      
      <div class="flex justify-end pt-4">
        <button type="button" onclick="closeLogModal()" class="btn-secondary">Close</button>
      </div>
    </dialog>

  </div>

  <script>
    function viewLogDetail(id) {
      const oldVal = document.getElementById('log-old-' + id).textContent;
      const newVal = document.getElementById('log-new-' + id).textContent;
      
      document.getElementById('oldJson').textContent = oldVal ? JSON.stringify(JSON.parse(oldVal), null, 2) : 'No original values';
      document.getElementById('newJson').textContent = newVal ? JSON.stringify(JSON.parse(newVal), null, 2) : 'No changes';
      
      document.getElementById('logModal').showModal();
    }

    function closeLogModal() {
      document.getElementById('logModal').close();
    }

    function exportTable() {
      const table = document.getElementById('reportTable');
      if (!table) return;
      let csv = [];
      for (const row of table.querySelectorAll('tr')) {
        const cols = [...row.querySelectorAll('th,td')].map(c => window.csvCell(c.innerText));
        csv.push(cols.join(','));
      }
      const blob = new Blob(['\ufeff', csv.join('\n')], {type:'text/csv;charset=utf-8'});
      const a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = 'activity-logs-{{ now()->format("Y-m-d") }}.csv';
      a.click();
    }
  </script>
</x-layouts::app>
