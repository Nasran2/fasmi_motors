@props(['job', 'statusClass'])

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Job card overview">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-extrabold text-slate-600">{{ $job->is_quick_job ? 'Quick Job' : 'Job Card' }} · {{ $job->number }}@if($job->is_staff_bike)<span class="ml-2 text-blue-700">Staff bike · {{ $job->staffBikeOwner?->name }}</span>@endif</p>
        <span class="rounded-full px-3 py-1 text-sm font-extrabold {{ $statusClass }}">{{ $job->status }}</span>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        <div class="min-w-0 rounded-xl bg-[#0b2447] px-5 py-4 text-white">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-300">Bike Number</p>
            <h1 class="mt-2 break-words text-3xl font-black leading-tight tracking-wide sm:text-4xl">{{ $job->bike?->number ?: 'Not recorded' }}</h1>
            <p class="mt-2 text-sm text-slate-200">{{ $job->bike?->model }}</p>
        </div>
        <div class="min-w-0 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4">
            <p class="text-xs font-bold uppercase tracking-wider text-blue-700">Main Technician</p>
            <p class="mt-2 break-words text-2xl font-black leading-tight text-[#0b2447] sm:text-3xl">{{ $job->technician?->name ?: 'Unassigned' }}</p>
            <p class="mt-2 text-sm text-blue-700">{{ $job->technician ? 'Assigned to this job' : 'Select a technician in Work details below' }}</p>
        </div>
    </div>
    <p class="mt-4 break-words text-sm text-slate-500">{{ $job->customer?->name ?: 'Walk-in customer' }} · {{ $job->customer?->phone }}</p>
</section>
