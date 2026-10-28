@props(['job', 'statusClass'])

<section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Job card overview">
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm font-extrabold text-slate-600">{{ $job->is_quick_job ? 'Quick Job' : 'Job Card' }} · {{ $job->number }}@if($job->repair_staff)<span class="ml-2 text-blue-700">Staff bike · {{ $job->repair_staff->name }}</span>@endif</p>
        <span class="rounded-full px-3 py-1 text-sm font-extrabold {{ $statusClass }}">{{ $job->status }}</span>
    </div>
    <div class="grid gap-4 md:grid-cols-2">
        <div class="min-w-0 rounded-xl bg-[#0b2447] px-5 py-4 text-white">
            <p class="text-xs font-bold uppercase tracking-wider text-slate-300">Bike Number</p>
            <h1 class="mt-2 break-words text-3xl font-black leading-tight tracking-wide sm:text-4xl">{{ $job->bike?->number ?: 'Not recorded' }}</h1>
            <p class="mt-2 text-sm text-slate-200">{{ $job->bike?->model }}</p>
            @if($job->bike?->exists && auth()->user()->canAccess('job_card.edit'))
            <details class="mt-3" @if($errors->bikeNumber->any()) open @endif>
                <summary class="cursor-pointer text-sm font-semibold text-white underline">Edit bike number</summary>
                <form method="POST" action="{{ route('jobs.bike-number', $job) }}" class="mt-3 space-y-3 rounded-xl bg-white p-4 text-slate-900">
                    @csrf<x-submission-token/>@method('PUT')
                    <p class="text-sm text-slate-600">Correct this bike’s full registration number. It will update every linked intake, job card, bill and report.</p>
                    <label class="block"><span class="label">Bike number</span><input type="text" name="bike_number" value="{{ old('bike_number', $job->bike->number) }}" class="field uppercase" maxlength="30" autocomplete="off" required></label>
                    @error('bike_number', 'bikeNumber')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <label class="block"><span class="label">Reason for correction</span><input name="bike_number_reason" value="{{ old('bike_number_reason') }}" class="field" minlength="5" maxlength="500" placeholder="Explain the registration correction" required></label>
                    @error('bike_number_reason', 'bikeNumber')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                    <button class="btn-secondary">Save bike number</button>
                </form>
            </details>
            @endif
        </div>
        <div class="min-w-0 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4">
            <p class="text-xs font-bold uppercase tracking-wider text-blue-700">Main Technician</p>
            <p class="mt-2 break-words text-2xl font-black leading-tight text-[#0b2447] sm:text-3xl">{{ $job->technician?->name ?: 'Unassigned' }}</p>
            <p class="mt-2 text-sm text-blue-700">{{ $job->technician ? 'Assigned to this job' : 'Select a technician in Work details below' }}</p>
        </div>
    </div>
    <p class="mt-4 break-words text-sm text-slate-500">{{ $job->customer?->name ?: 'Walk-in customer' }} · {{ $job->customer?->phone }}</p>
    @if($billDate = $job->invoice?->bill_date ?? $job->bill_date)
    <p class="mt-2 text-sm font-semibold text-slate-600">Bill date: {{ $billDate->format('d M Y') }}</p>
    @endif
</section>
