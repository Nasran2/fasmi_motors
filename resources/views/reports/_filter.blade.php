{{--
  Shared report filter bar partial.
  Variables expected:
    $title       — Report title
    $subtitle    — Short description
    $from        — Carbon date
    $to          — Carbon date
    $range       — active quick range key
    $snapshot    — show current-stock filters without a date selector
--}}

@php
  $report = str_replace('reports.', '', request()->route()->getName());
  $dateBasis = match ($report) {
      'index', 'spare-parts', 'due-bills', 'customer-due' => 'Bill date',
      'profit-loss' => 'Bill / expense dates and salary period end',
      'collections' => 'Payment date',
      'main-account', 'spare-parts-account', 'bank-account', 'cash-book' => 'Payment / entry date',
      'purchases', 'supplier-dues' => 'Purchase date',
      'expenses' => 'Expense date', 'drawings' => 'Drawing date',
      'salary' => 'Salary period', 'epf', 'etf' => 'Contribution month',
      'attendance' => 'Attendance date', 'daily-closing' => 'Closing date',
      'service-reminders' => 'Reminder due date', 'job-cards' => 'Job creation date',
      'technicians' => 'Job / commission / attendance dates and salary period end',
      default => 'Record date',
  };
  $reportFields = \App\Support\ReportFilters::fields($report);
@endphp

{{-- Page Header --}}
<div class="flex flex-wrap items-start justify-between gap-4 print:hidden">
  <div>
    <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Reports</p>
    <h1 class="page-title">{{ $title }}</h1>
    <p class="page-subtitle">{{ $subtitle ?? '' }}</p>
    @unless($snapshot ?? false)<p class="mt-1 text-xs font-semibold text-slate-500">Date filter: {{ $dateBasis }}</p>@endunless
  </div>
  <div class="flex flex-wrap gap-2">
    @if(auth()->user()->canAccess('reports.print'))
    <a href="{{ request()->fullUrlWithQuery(array_merge(request()->query(), ['print' => 1, 'export_csv' => null, 'page' => null])) }}" target="_blank" class="btn-secondary flex items-center gap-1.5">
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
      Print / PDF
    </a>
    @endif
    @if(auth()->user()->canAccess('reports.export_excel'))
      <a href="{{ request()->fullUrlWithQuery(['export_csv' => 1, 'print' => null, 'page' => null]) }}" class="btn-secondary flex items-center gap-1.5">
        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2-2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></svg>
        Export CSV
      </a>
    @endif
  </div>
</div>

{{-- Quick Date Tabs + Filter Form --}}
<div class="panel print:hidden">
  @unless($snapshot ?? false)
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
        'last_year'  => 'Last Year',
        'custom'     => 'Custom',
      ];
    @endphp
    @foreach($tabs as $key => $label)
    <a href="{{ request()->fullUrlWithQuery(['date_range' => $key, 'from' => null, 'to' => null, 'page' => null, 'print' => null, 'export_csv' => null]) }}"
       class="whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-bold transition
              {{ ($range ?? 'today') === $key ? 'bg-white text-slate-900 shadow' : 'text-slate-500 hover:bg-white/70' }}">
      {{ $label }}
    </a>
    @endforeach
  </div>

  @endunless

  <form method="GET" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
    @unless($snapshot ?? false)
    <input type="hidden" name="date_range" value="{{ $range === 'all_time' ? 'all_time' : 'custom' }}">
    <div>
      <label class="label">From Date</label>
      <input type="date" name="from" value="{{ $range !== 'all_time' && $from ? $from->toDateString() : '' }}">
    </div>
    <div>
      <label class="label">To Date</label>
      <input type="date" name="to" value="{{ $range !== 'all_time' && $to ? $to->toDateString() : '' }}">
    </div>

    @endunless
    @include('reports._fields')

    <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-1">
      <button type="submit" class="btn-primary flex-1">Apply</button>
      <a href="{{ request()->url() }}" class="btn-secondary">Reset</a>
    </div>
  </form>
</div>

@if($errors->any())
<div role="alert" class="panel text-red-700">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif
@php
  $filterKeys = array_column($reportFields, 'name');
  if ($report !== 'profit-loss') $filterKeys[] = 'search';
  $activeFilters = collect(request()->only($filterKeys))->filter(fn($value) => $value !== null && $value !== '' && $value !== []);
@endphp
@if($activeFilters->isNotEmpty())
<p data-print-filters class="text-sm text-slate-600">Filters:
@foreach($reportFields as $field)
@if(request()->filled($field['name']))<span class="mr-3">{{ $field['label'] }}: {{ $field['options'][request($field['name'])] ?? request($field['name']) }}</span>@endif
@endforeach
@if($report !== 'profit-loss' && request()->filled('search'))<span>Search: {{ request('search') }}</span>@endif
</p>
@endif
{{-- Print header (only in print) --}}
<div class="hidden print:block mb-6">
  <div class="flex justify-between items-start border-b-2 border-slate-900 pb-3">
    <div>
      <h1 class="text-2xl font-black">FASMIR MOTORS</h1>
      <p class="text-sm text-slate-500">Bike Repair Center System</p>
    </div>
    <div class="text-right">
      <h2 class="text-xl font-bold">{{ $title }}</h2>
      <p class="text-sm text-slate-500">{{ ($snapshot ?? false) ? 'Current stock snapshot' : ($range === 'all_time' ? 'All time' : $from->format('d M Y').' — '.$to->format('d M Y')) }}@unless($snapshot ?? false) · {{ $dateBasis }}@endunless</p>
      <p class="text-xs text-slate-400">Generated: {{ now()->format('d M Y h:i A') }} by {{ auth()->user()->name }}</p>
    </div>
  </div>
</div>
