<x-layouts::app title="Login History">
  <div class="space-y-6">
    
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">User Management</p>
        <h1 class="page-title">Login History</h1>
        <p class="page-subtitle">View user login attempts, devices, and security status</p>
      </div>
      <div class="flex flex-wrap gap-2 print:hidden">
        <button onclick="window.print()" class="btn-secondary flex items-center gap-1.5">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
          Print / PDF
        </button>
        <button onclick="exportTable()" class="btn-secondary flex items-center gap-1.5">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
          Export CSV
        </button>
      </div>
    </div>

    {{-- Summary Cards --}}
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5 print:grid-cols-5">
      @foreach($stats as $label => $value)
      <div class="panel bg-white border border-slate-200 rounded-xl p-4 shadow-sm relative overflow-hidden group">
        <div class="absolute right-0 top-0 h-full w-1 bg-red-600 opacity-0 transition-opacity group-hover:opacity-100"></div>
        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-extrabold mb-1">{{ ucwords(str_replace('_', ' ', $label)) }}</p>
        <p class="text-2xl font-black {{ str_contains($label, 'failed') || str_contains($label, 'locked') ? 'text-red-600' : (str_contains($label, 'success') || str_contains($label, 'active') ? 'text-green-600' : 'text-slate-800') }}">
          {{ number_format($value) }}
        </p>
      </div>
      @endforeach
    </div>

    {{-- Filters --}}
    <div class="panel bg-white border border-slate-200 rounded-xl shadow-sm p-4 print:hidden">
      <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 items-end">
        <div>
          <label class="label">From Date</label>
          <input type="date" name="from" value="{{ $from ? $from->toDateString() : '' }}" class="w-full">
        </div>
        <div>
          <label class="label">To Date</label>
          <input type="date" name="to" value="{{ $to ? $to->toDateString() : '' }}" class="w-full">
        </div>
        <div>
          <label class="label">Status</label>
          <select name="status" class="w-full">
            <option value="">All Statuses</option>
            <option value="Success" @selected(request('status') === 'Success')>Success</option>
            <option value="Failed" @selected(request('status') === 'Failed')>Failed</option>
            <option value="Locked" @selected(request('status') === 'Locked')>Locked</option>
          </select>
        </div>
        <div>
          <label class="label">Search Keyword</label>
          <input type="text" name="search" value="{{ request('search') }}" placeholder="Search IP, device, browser..." class="w-full">
        </div>
        <div class="flex gap-2">
          <button type="submit" class="btn-primary flex-1">Apply</button>
          <a href="{{ route('user-management.login-history.index') }}" class="btn-secondary">Reset</a>
        </div>
      </form>
    </div>

    {{-- Print Header --}}
    <div class="hidden print:block mb-6 border-b-2 border-slate-900 pb-3">
      <h1 class="text-2xl font-black">FASMIR MOTORS</h1>
      <p class="text-sm text-slate-500">Login Security History Report ({{ $from->format('d M Y') }} — {{ $to->format('d M Y') }})</p>
    </div>

    {{-- Table --}}
    <div class="panel p-0 overflow-hidden border border-slate-200 rounded-xl shadow-sm bg-white print:border-none print:shadow-none">
      <div class="overflow-x-auto">
        <table id="reportTable" class="w-full text-left text-sm whitespace-nowrap">
          <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
            <tr>
              <th class="px-4 py-3 font-bold">Date / Time</th>
              <th class="px-4 py-3 font-bold">User</th>
              <th class="px-4 py-3 font-bold">Username / Email</th>
              <th class="px-4 py-3 font-bold">IP Address</th>
              <th class="px-4 py-3 font-bold">Device</th>
              <th class="px-4 py-3 font-bold">Browser</th>
              <th class="px-4 py-3 font-bold">Login Status</th>
              <th class="px-4 py-3 font-bold">Failure Reason</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-slate-100">
            @forelse($rows as $row)
            <tr class="hover:bg-slate-50/50 transition">
              <td class="px-4 py-3 text-xs font-mono text-slate-600">{{ $row->logged_in_at->format('d M Y h:i A') }}</td>
              <td class="px-4 py-3 font-bold text-slate-900">{{ $row->user?->name ?: '—' }}</td>
              <td class="px-4 py-3 text-slate-700 font-mono text-xs">{{ $row->username_or_email }}</td>
              <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $row->ip_address }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">{{ $row->device }}</td>
              <td class="px-4 py-3 text-slate-600 text-xs">{{ $row->browser }}</td>
              <td class="px-4 py-3">
                @php
                  $color = match($row->status) {
                    'Success' => 'badge-sent',
                    'Failed'  => 'badge-failed',
                    'Locked'  => 'badge-pending',
                    default   => 'badge-pending'
                  };
                @endphp
                <span class="{{ $color }}">{{ $row->status }}</span>
              </td>
              <td class="px-4 py-3 text-slate-500 text-xs truncate max-w-xs">{{ $row->failure_reason ?: '—' }}</td>
            </tr>
            @empty
            <tr>
              <td colspan="100%" class="px-4 py-8 text-center text-slate-500">No login attempts recorded.</td>
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

  </div>

  <script>
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
      a.download = 'login-history-{{ now()->format("Y-m-d") }}.csv';
      a.click();
    }
  </script>
</x-layouts::app>
