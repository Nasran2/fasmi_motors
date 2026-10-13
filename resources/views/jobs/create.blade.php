<x-layouts::app title="New Job Card">
    @php($selectedIntake = $intakes->firstWhere('id', (int) $selected))
    <div class="mx-auto max-w-4xl space-y-5">
        <div>
            <p class="mb-1 text-[10px] font-bold uppercase tracking-[.18em] text-red-600">Workshop Operations</p>
            <h1 class="page-title">Create Job Card</h1>
            <p class="page-subtitle">Find an unassigned bike intake and send it to the workshop.</p>
            <a href="{{ route('jobs.quick.create') }}" class="btn-secondary mt-3">+ Quick Job</a>
        </div>

        <x-flash/>

        <form method="POST" action="{{ route('jobs.store') }}" class="panel space-y-5">
            @csrf<x-submission-token/>
            <div>
                <label id="intake-search-label" class="label">Bike intake <span class="text-red-500">*</span></label>
                <div id="intake-picker" class="relative" data-intake-picker>
                    <input id="bike_intake_id" type="hidden" name="bike_intake_id" value="{{ old('bike_intake_id', $selected) }}">

                    <button
                        id="intake-picker-trigger"
                        type="button"
                        aria-labelledby="intake-search-label intake-picker-value"
                        aria-haspopup="listbox"
                        aria-expanded="false"
                        class="field flex w-full items-center gap-3 text-left"
                        @disabled($intakes->isEmpty())
                    >
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-red-50 text-red-600"><svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="5" cy="16" r="3"/><circle cx="19" cy="16" r="3"/><path d="M5 16 9 9h5l5 7M9 9 7 7m7 2 3-3m-6 10h8"/></svg></span>
                        <span id="intake-picker-value" class="min-w-0 flex-1 truncate {{ $selectedIntake ? 'font-semibold text-slate-800' : 'text-slate-400' }}">
                            @if($selectedIntake)
                                {{ $selectedIntake->intake_number }} · {{ $selectedIntake->bike->number }} · {{ $selectedIntake->bike->customer->name ?: $selectedIntake->bike->customer->phone }}
                            @elseif($intakes->isEmpty())
                                No unassigned bike intakes available
                            @else
                                Search by intake, bike, customer, phone or model...
                            @endif
                        </span>
                        <svg class="size-5 shrink-0 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="m8 10 4 4 4-4"/></svg>
                    </button>

                    @if($intakes->isNotEmpty())
                        <div id="intake-picker-panel" class="absolute z-40 mt-2 hidden w-full overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_22px_60px_rgba(15,35,65,.18)]">
                            <div class="border-b border-slate-100 p-3">
                                <div class="relative">
                                    <svg class="pointer-events-none absolute left-3 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
                                    <input id="intake-search" type="search" class="field !pl-10" placeholder="Type bike no, customer, phone, model..." autocomplete="off">
                                </div>
                            </div>
                            <div id="intake-options" role="listbox" aria-labelledby="intake-search-label" class="max-h-72 overflow-y-auto p-2">
                                @foreach($intakes as $i)
                                    @php($intakeLabel = $i->intake_number.' · '.$i->bike->number.' · '.($i->bike->customer->name ?: $i->bike->customer->phone))
                                    <button
                                        type="button"
                                        role="option"
                                        aria-selected="{{ (string) old('bike_intake_id', $selected) === (string) $i->id ? 'true' : 'false' }}"
                                        data-intake-option
                                        data-id="{{ $i->id }}"
                                        data-label="{{ $intakeLabel }}"
                                        data-search="{{ Str::lower($i->intake_number.' '.$i->bike->number.' '.$i->bike->model.' '.$i->bike->customer->name.' '.$i->bike->customer->phone) }}"
                                        class="flex w-full items-center gap-3 rounded-xl px-3 py-3 text-left transition hover:bg-red-50 focus:bg-red-50 focus:outline-none"
                                    >
                                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-slate-100 text-[#0b2447]"><x-dashboard-icon name="bike" class="size-5"/></span>
                                        <span class="min-w-0 flex-1"><span class="block truncate text-sm font-bold text-slate-800">{{ $i->bike->number }} <span class="font-medium text-slate-400">· {{ $i->intake_number }}</span></span><span class="mt-1 block truncate text-[11px] text-slate-500">{{ $i->bike->model }} · {{ $i->bike->customer->name ?: 'Walk-in customer' }} · {{ $i->bike->customer->phone }}</span></span>
                                        <svg class="option-check size-5 shrink-0 {{ (string) old('bike_intake_id', $selected) === (string) $i->id ? 'text-emerald-500' : 'hidden' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m5 12 4 4L19 6"/></svg>
                                    </button>
                                @endforeach
                                <div id="intake-no-results" class="hidden px-4 py-10 text-center"><span class="mx-auto grid size-11 place-items-center rounded-xl bg-slate-50 text-slate-400"><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4M8.5 11h5"/></svg></span><p class="mt-3 text-sm font-bold text-slate-700">No active intake found</p><p class="mt-1 text-xs text-slate-500 max-w-sm mx-auto">Job cards can only be created for active intakes. If this is a returning bike, you must record a new visit first.</p><a href="{{ route('intakes.create') }}" class="btn-primary mt-4 inline-flex"><svg class="size-4 mr-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg> Create New Bike Intake</a></div>
                            </div>
                            <div class="border-t border-slate-100 bg-slate-50 px-4 py-2.5 text-[10px] text-slate-500"><strong>{{ $intakes->count() }}</strong> unassigned intake(s) available</div>
                        </div>
                    @endif
                </div>
                @error('bike_intake_id')<p class="mt-2 text-xs font-semibold text-red-600">{{ $message }}</p>@enderror
                @if($intakes->isEmpty())<div class="mt-3 flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-800"><span>Create a bike intake before opening a job card.</span><a href="{{ route('intakes.create') }}" class="font-bold underline">New Bike Intake</a></div>@endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label><span class="label">Main technician</span><select name="main_technician_id" class="field"><option value="">Assign later</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected(old('main_technician_id')==$t->id)>{{ $t->name }}</option>@endforeach</select></label>
                <label><span class="label">Priority <span class="text-red-500">*</span></span><select name="priority" class="field">@foreach(['Normal','Low','High','Urgent'] as $priority)<option @selected(old('priority','Normal')===$priority)>{{ $priority }}</option>@endforeach</select></label>
                <label><span class="label">Estimated amount</span><input name="estimated_amount" value="{{ old('estimated_amount') }}" type="number" step=".01" class="field" placeholder="0.00"></label>
            </div>
            <label><span class="label">Internal notes</span><textarea name="internal_notes" class="field min-h-28" rows="3" placeholder="Workshop instructions or internal notes...">{{ old('internal_notes') }}</textarea></label>
            <div class="flex flex-col-reverse justify-end gap-3 border-t border-slate-100 pt-5 sm:flex-row"><a href="{{ route('jobs.index') }}" class="btn-secondary">Cancel</a><button class="btn-primary" @disabled($intakes->isEmpty())>Create Job Card</button></div>
        </form>
    </div>

    @if($intakes->isNotEmpty())
        <script>
            (() => {
                const picker = document.querySelector('[data-intake-picker]');
                const trigger = document.getElementById('intake-picker-trigger');
                const panel = document.getElementById('intake-picker-panel');
                const search = document.getElementById('intake-search');
                const hidden = document.getElementById('bike_intake_id');
                const value = document.getElementById('intake-picker-value');
                const options = [...document.querySelectorAll('[data-intake-option]')];
                const noResults = document.getElementById('intake-no-results');

                const closePicker = () => { panel.classList.add('hidden'); trigger.setAttribute('aria-expanded', 'false'); };
                const openPicker = () => { panel.classList.remove('hidden'); trigger.setAttribute('aria-expanded', 'true'); search.focus(); };

                trigger.addEventListener('click', () => panel.classList.contains('hidden') ? openPicker() : closePicker());
                search.addEventListener('input', () => {
                    const term = search.value.trim().toLowerCase();
                    let visible = 0;
                    options.forEach(option => {
                        const matches = option.dataset.search.includes(term);
                        option.classList.toggle('hidden', !matches);
                        if (matches) visible++;
                    });
                    noResults.classList.toggle('hidden', visible !== 0);
                });
                options.forEach(option => option.addEventListener('click', () => {
                    hidden.value = option.dataset.id;
                    value.textContent = option.dataset.label;
                    value.classList.remove('text-slate-400');
                    value.classList.add('font-semibold', 'text-slate-800');
                    options.forEach(item => { item.setAttribute('aria-selected', 'false'); item.querySelector('.option-check').classList.add('hidden'); });
                    option.setAttribute('aria-selected', 'true');
                    option.querySelector('.option-check').classList.remove('hidden');
                    closePicker();
                }));
                document.addEventListener('click', event => { if (!picker.contains(event.target)) closePicker(); });
                document.addEventListener('keydown', event => { if (event.key === 'Escape') closePicker(); });
            })();
        </script>
    @endif
</x-layouts::app>
