{{--
  Shared report filter bar partial.
  Variables expected:
    $title       — Report title
    $subtitle    — Short description
    $from        — Carbon date
    $to          — Carbon date
    $range       — active quick range key
    $extraFilters — optional blade slot content
    $exportRoute  — optional named route string for print
--}}

{{-- Page Header --}}
<div class="flex flex-wrap items-start justify-between gap-4 print:hidden">
  <div>
    <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Reports</p>
    <h1 class="page-title">{{ $title }}</h1>
    <p class="page-subtitle">{{ $subtitle ?? '' }}</p>
  </div>
  <div class="flex flex-wrap gap-2">
    @if(auth()->user()->canAccess('reports.print'))
    <a href="{{ request()->fullUrlWithQuery(array_merge(request()->query(), ['print' => 1])) }}" target="_blank" class="btn-secondary flex items-center gap-1.5">
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
      Print / PDF
    </a>
    @if(request()->has('print'))
    <script>
      window.addEventListener('load', function() {
          window.print();
      });
    </script>
    @endif
    @endif
    @if(auth()->user()->canAccess('reports.export_excel'))
      @if(isset($exportRoute))
      <a href="{{ $exportRoute }}" class="btn-secondary flex items-center gap-1.5">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Export CSV
      </a>
      @else
      <button onclick="exportTable()" class="btn-secondary flex items-center gap-1.5">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Export CSV
      </button>
      @endif
    @endif
  </div>
</div>

{{-- Quick Date Tabs + Filter Form --}}
<div class="panel print:hidden">
  {{-- Quick tabs --}}
  <div class="mb-4 flex flex-wrap gap-1 rounded-xl bg-slate-100 p-1">
    @php
      $tabs = [
        'all_time'   => 'All Time',
        'today'      => 'Today',
        'yesterday'  => 'Yesterday',
        'this_week'  => 'This Week',
        'last_week'  => 'Last Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'this_year'  => 'This Year',
        'custom'     => 'Custom',
      ];
    @endphp
    @foreach($tabs as $key => $label)
    <a href="{{ request()->fullUrlWithQuery(['date_range' => $key, 'page' => null]) }}"
       class="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-bold transition
              {{ ($range ?? 'today') === $key ? 'bg-white text-slate-900 shadow' : 'text-slate-500 hover:bg-white/70' }}">
      {{ $label }}
    </a>
    @endforeach
  </div>

  <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
    <input type="hidden" name="date_range" value="custom">
    <div>
      <label class="label">From Date</label>
      <input type="date" name="from" value="{{ $from ? (is_string($from) ? $from : $from->toDateString()) : '' }}">
    </div>
    <div>
      <label class="label">To Date</label>
      <input type="date" name="to" value="{{ $to ? (is_string($to) ? $to : $to->toDateString()) : '' }}">
    </div>

    {{-- Extra filters injected by each report --}}
    {{ $filters ?? '' }}

    <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-1">
      <button type="submit" class="btn-primary flex-1">Apply</button>
      <a href="{{ request()->url() }}" class="btn-secondary">Reset</a>
    </div>
  </form>
</div>

{{-- Print header (only in print) --}}
<div class="hidden print:block mb-6">
  <div class="flex justify-between items-start border-b-2 border-slate-900 pb-3">
    <div>
      <h1 class="text-2xl font-black">FASMIR MOTORS</h1>
      <p class="text-sm text-slate-500">Bike Repair Center System</p>
    </div>
    <div class="text-right">
      <h2 class="text-xl font-bold">{{ $title }}</h2>
      <p class="text-sm text-slate-500">{{ $from ? $from->format('d M Y') : '' }} — {{ $to ? $to->format('d M Y') : '' }}</p>
      <p class="text-xs text-slate-400">Generated: {{ now()->format('d M Y h:i A') }} by {{ auth()->user()->name }}</p>
    </div>
  </div>
</div>

<script>
function exportTable() {
  const table = document.getElementById('reportTable');
  if (!table) { alert('No table found.'); return; }
  let csv = [];
  for (const row of table.querySelectorAll('tr')) {
    const cols = [...row.querySelectorAll('th,td')].map(c => window.csvCell(c.innerText));
    csv.push(cols.join(','));
  }
  const blob = new Blob(['\ufeff', csv.join('\n')], {type:'text/csv;charset=utf-8'});
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = '{{ Str::slug($title ?? "report") }}-{{ now()->format("Y-m-d") }}.csv';
  a.click();
}
</script>
