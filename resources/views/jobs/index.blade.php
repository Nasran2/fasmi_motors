<x-layouts::app title="Job Cards">

<div class="space-y-5">
    <div class="flex flex-wrap justify-between gap-3"><div><h1 class="page-title">Job Cards</h1><p class="page-subtitle">Technician assignments and workshop progress.</p></div><div class="flex gap-2"><a href="{{ route('jobs.quick.create') }}" class="btn-secondary">+ Quick Job</a><a href="{{ route('jobs.create') }}" class="btn-primary">+ New job card</a></div></div>
    <x-flash/>
    <form data-live-job-filter class="panel flex flex-wrap items-end gap-3" method="GET" action="{{ route('jobs.index') }}">
        <input type="hidden" name="bike_search" value="{{ request('bike_search') }}">
        <label class="w-full sm:w-72"><span class="label">Bike</span><select class="field tom-select" data-search-filter data-bike-search data-placeholder="Type bike letters or numbers…" name="bike_id" aria-label="Filter by bike"><option value="">All bikes</option>@foreach($bikes as $bike)<option value="{{ $bike->id }}" @selected((string) request('bike_id') === (string) $bike->id)>{{ $bike->number }}</option>@endforeach</select></label>
        <label class="w-full sm:w-72"><span class="label">Job status</span><select class="field" name="status"><option value="">All statuses</option>@foreach($statuses as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></label>
        <button class="btn-secondary">Filter</button>
        <a data-clear-job-filters href="{{ route('jobs.index') }}" class="btn-secondary" @if(!request()->filled('bike_id') && !request()->filled('status') && !request()->filled('bike_search')) hidden @endif>Clear filters</a>
    </form>
    <p class="text-sm text-slate-500" role="status" aria-live="polite" data-job-search-status>{{ $jobs->total() }} job cards</p>
    <div data-job-results aria-busy="false">
        @include('jobs.partials.list')
    </div>
</div>
<x-job-due-payment-dialog :accounts="$accounts" select-bills />
</x-layouts::app>
