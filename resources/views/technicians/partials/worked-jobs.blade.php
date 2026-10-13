@php($historyFilters = array_filter(request()->only(['technician', 'month', 'from', 'to'])))
<section id="worked-jobs" class="table-wrap scroll-mt-24 xl:col-span-2">
    <div class="space-y-3 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h3 class="font-extrabold">Worked Jobs</h3>
                <p class="mt-1 text-xs text-slate-500">Filter by assigned date. Commission shows recorded earnings for each job.</p>
            </div>
            <p class="text-sm font-bold text-slate-700">{{ number_format($workedJobs->count()) }} jobs · Commission: Rs. {{ number_format($jobCommissions->sum(), 2) }}</p>
        </div>
        <form action="{{ route('technicians.history') }}#worked-jobs" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="technician" value="{{ $technician->id }}">
            @foreach(['month', 'from', 'to'] as $filter)
                @if(request()->filled($filter))<input type="hidden" name="{{ $filter }}" value="{{ request($filter) }}">@endif
            @endforeach
            <label><span class="label">Jobs from</span><input type="date" name="jobs_from" value="{{ $workedJobsFrom?->toDateString() }}" class="field"></label>
            <label><span class="label">Jobs to</span><input type="date" name="jobs_to" value="{{ $workedJobsTo?->toDateString() }}" class="field"></label>
            <button class="btn-primary">Filter Jobs</button>
            <a href="{{ route('technicians.history', array_merge($historyFilters, ['jobs_from' => today()->toDateString(), 'jobs_to' => today()->toDateString()])) }}#worked-jobs" class="btn-secondary">Today</a>
            <a href="{{ route('technicians.history', $historyFilters) }}#worked-jobs" class="btn-secondary">Reset</a>
        </form>
    </div>
    <table class="data-table min-w-[1000px]">
        <thead><tr><th>Job</th><th>Commission</th><th>Bike</th><th>Customer</th><th>Assigned</th><th>Bill</th><th>Status</th></tr></thead>
        <tbody>
            @forelse($workedJobs as $job)
                <tr>
                    <td class="whitespace-nowrap"><a href="{{ route('jobs.show', $job) }}" class="text-red-600">{{ $job->number }}</a></td>
                    <td class="whitespace-nowrap font-bold">Rs. {{ number_format($jobCommissions->get($job->id, 0), 2) }}</td>
                    <td>{{ $job->bike?->number }}</td>
                    <td>{{ $job->customer?->name }}</td>
                    <td class="whitespace-nowrap">{{ $job->created_at->format('d M Y') }}</td>
                    <td class="whitespace-nowrap">Rs. {{ number_format($job->invoice?->total ?? 0, 2) }}</td>
                    <td>{{ $job->status }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center">No assigned jobs in this date range.</td></tr>
            @endforelse
        </tbody>
    </table>
</section>
