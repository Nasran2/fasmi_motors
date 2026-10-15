@php
    $requestedMode = old('salary_period_mode', isset($technician) ? ($technician->salary_period_mode ?? 'system_default') : 'system_default');
    $selectedPeriodMode = in_array($requestedMode, ['system_default', 'normal_calendar', 'custom_start'], true) ? $requestedMode : 'system_default';
    $selectedStartDay = filter_var(old('salary_start_day', isset($technician) ? ($technician->salary_start_day ?? 1) : 1), FILTER_VALIDATE_INT) ?: 1;
    $cycleService = app(\App\Services\SalaryCycleService::class);
    $exampleMonth = today()->startOfMonth();
    $formatPeriod = fn ($period) => \Carbon\Carbon::parse($period['salary_period_start'])->format('d M Y').' to '.\Carbon\Carbon::parse($period['salary_period_end'])->format('d M Y').' ('.$period['salary_period_days'].' days)';
    $periodExamples = [
        'system_default' => $formatPeriod($cycleService->getSalaryPeriod($exampleMonth)),
        'normal_calendar' => $formatPeriod($cycleService->getNormalCalendarPeriod($exampleMonth)),
        'custom_start' => collect(range(1, 31))->mapWithKeys(fn ($day) => [$day => $formatPeriod($cycleService->getCustomStartPeriod($exampleMonth, $day))]),
    ];
    $selectedExample = $selectedPeriodMode === 'custom_start' ? ($periodExamples['custom_start']->get($selectedStartDay) ?? '') : $periodExamples[$selectedPeriodMode];
@endphp
<section class="panel" x-data="{ mode: @js($selectedPeriodMode), startDay: @js($selectedStartDay), examples: @js($periodExamples) }">
    <h2 class="text-lg font-extrabold">Salary Period</h2>
    <p class="mt-2 text-sm text-slate-500">Choose the attendance period used to calculate this staff member’s salary.</p>
    <div class="mt-4 grid gap-4 md:grid-cols-2">
        <label><span class="label">Salary Period Rule *</span>
            <select name="salary_period_mode" class="field" x-model="mode" required>
                <option value="system_default" @selected($selectedPeriodMode === 'system_default')>Use system default</option>
                <option value="normal_calendar" @selected($selectedPeriodMode === 'normal_calendar')>Calendar month (1st to last day)</option>
                <option value="custom_start" @selected($selectedPeriodMode === 'custom_start')>Custom monthly start day</option>
            </select>
        </label>
        <label x-show="mode === 'custom_start'" x-cloak><span class="label">Salary Start Day *</span>
            <input type="number" name="salary_start_day" min="1" max="31" step="1" class="field" x-model="startDay" :disabled="mode !== 'custom_start'" :required="mode === 'custom_start'" value="{{ $selectedStartDay }}" @disabled($selectedPeriodMode !== 'custom_start')>
        </label>
    </div>
    <div class="mt-4 rounded-xl border border-blue-100 bg-blue-50 p-4 text-sm">
        <p class="font-bold">Example for {{ $exampleMonth->format('F Y') }}</p>
        <p data-salary-period-example class="mt-1 text-blue-800" x-text="mode === 'custom_start' ? (examples.custom_start[startDay] || 'Choose a start day from 1 to 31.') : examples[mode]">{{ $selectedExample }}</p>
        <p class="mt-2 text-xs text-slate-500" x-show="mode === 'system_default'">Uses the existing system cycle, including its current cut-off and carry-forward rules.</p>
        <p class="mt-2 text-xs text-slate-500" x-show="mode === 'normal_calendar'">Always starts on the 1st and ends on the actual last day, including February and leap years.</p>
        <p class="mt-2 text-xs text-slate-500" x-show="mode === 'custom_start'" x-cloak>Day 1 uses the full calendar month. Other start days run from the previous month’s selected day to the day before that date in the salary month. If the day does not exist, the month’s last day is used.</p>
    </div>
</section>
