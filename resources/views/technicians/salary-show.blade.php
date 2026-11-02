<x-layouts::app title="Salary Details">
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-bold uppercase text-red-600">Salary payments / {{ $sheet->number }}</p><h1 class="page-title">{{ $sheet->technician->name }} · Salary Details</h1><p class="page-subtitle">{{ $sheet->period_start->format('d M Y') }} to {{ $sheet->period_end->format('d M Y') }} · {{ $sheet->status }}</p></div>
        <div class="flex flex-wrap gap-2"><a class="btn-secondary" href="{{ route('technicians.salaries') }}">Back to salaries</a><a class="btn-primary" target="_blank" href="{{ route('technicians.salaries.slip', $sheet) }}">View / Print Payslip</a></div>
    </div>
    <x-flash />
    @if($attendanceChanged ?? false)
        <p class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">Attendance changed after this draft was saved. <a class="font-bold underline" href="#refresh-salary-calculation">Refresh draft salary calculation</a> to update its attendance totals, deductions and earnings together.</p>
    @endif
    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">You can print this payslip before payment, after a partial payment or after full payment. The slip shows its status, paid amount and remaining balance.</p>
    @if($sheet->status === 'Draft' && $sheet->uses_legacy_daily_salary)
        <p class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950">This draft uses attendance-based daily earnings. Use <a href="#refresh-salary-calculation" class="font-bold underline">Refresh salary calculation</a> below to show the saved full Basic Salary and separate unpaid leave / absence deductions. A reason is required.</p>
    @endif

    @if((float) data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_auto', 0) > 0)
        <p class="mt-3 text-xs text-emerald-800">Worked on shop-closed days: {{ data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_days') }} day(s) × Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_rate', 0), 2) }} = Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_auto', 0), 2) }} extra pay.@if((float) data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_manual', 0) > 0) Manual supplement: Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_manual', 0), 2) }}.@endif</p>
    @endif
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach(['Gross earnings' => $sheet->gross_salary, 'Total deductions' => $sheet->total_deductions, 'Net payable' => $sheet->net_payable, 'Paid so far' => $sheet->paid_amount, 'Remaining balance' => $sheet->balance_amount] as $label => $amount)
            <div class="panel p-4"><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-xl font-black">Rs. {{ number_format($amount, 2) }}</p></div>
        @endforeach
    </div>
    @php
        $earningsList = array_filter([
            $sheet->base_earnings_label => $sheet->base_earnings,
            'Overtime' => $sheet->ot_amount,
            'Allowances' => $sheet->allowances,
            'Manual additions' => $sheet->manual_additions,
            'Worked-on-leave pay' => $sheet->worked_on_leave_pay,
        ], fn ($amt) => (float) $amt > 0);

        $deductionsList = array_filter([
            'Late' => $sheet->late_deduction,
            'Early leave' => $sheet->early_leave_deduction,
            'Half days' => $sheet->half_day_deduction,
            'Unpaid leave / absence' => $sheet->no_pay_deduction,
            'Advances' => $sheet->total_advance_deduction,
            'Job / bike repair' => $sheet->total_job_deduction,
            'Employee EPF' => $sheet->epf_employee,
            'Other deductions' => $sheet->total_other_deduction,
            'Manual deductions' => $sheet->manual_deductions, 'Old balance recovery' => $sheet->old_balance_deduction,
        ], fn ($amt) => (float) $amt > 0);

        $attendanceAdjustment = (float) data_get($sheet->calculation_snapshot, 'deductions.attendance_adjustment', 0);
    @endphp
    @if(data_get($sheet->calculation_snapshot, 'earnings.salary_basis') === 'full_basic')
        <p class="mt-3 text-xs text-slate-600">Paid leave and shop-closed days do not cause no-pay deductions.@if((float) $sheet->no_pay_deduction > 0) Unpaid leave / absence: {{ data_get($sheet->calculation_snapshot, 'earnings.unpaid_leave_days', $sheet->total_absent_days) }} day(s) × Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.unpaid_leave_rate', 0), 2) }}.@endif</p>
    @endif
    <section class="panel p-5">
        <div class="grid gap-6 sm:grid-cols-2">
            <div><h2 class="font-black text-emerald-700">Earnings</h2><dl class="mt-3 space-y-2 text-sm">
                @forelse($earningsList as $label => $amount)
                    <div class="flex justify-between gap-3"><dt>{{ $label }}</dt><dd class="font-bold">{{ number_format($amount, 2) }}</dd></div>
                @empty
                    <div class="text-xs text-slate-400 italic">No earnings recorded</div>
                @endforelse
            </dl></div>
            <div><h2 class="font-black text-red-700">Deductions</h2><dl class="mt-3 space-y-2 text-sm">
                @forelse($deductionsList as $label => $amount)
                    <div class="flex justify-between gap-3"><dt>{{ $label }}</dt><dd class="font-bold">{{ number_format($amount, 2) }}</dd></div>
                @empty
                    @if($attendanceAdjustment == 0.0)
                        <div class="text-xs text-slate-400 italic">No deductions</div>
                    @endif
                @endforelse
                @if($attendanceAdjustment != 0.0)
                    <div class="flex justify-between gap-3"><dt>Attendance override adjustment</dt><dd class="font-bold">{{ number_format($attendanceAdjustment, 2) }}</dd></div>
                @endif
            </dl></div>
        </div>
        @if((float) $sheet->commission !== 0.0)<p class="mt-3 text-sm text-amber-800">Commission included in this earlier salary: Rs. {{ number_format($sheet->commission, 2) }}. This historical amount remains in its saved totals.</p>@endif
    @if((float) data_get($sheet->calculation_snapshot, 'contributions.basic_salary') > 0)
        <p class="mt-3 text-sm text-slate-600">EPF / ETF salary basic: <strong>Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'contributions.basic_salary'), 2) }}</strong>. Used only for EPF and ETF contributions.</p>
    @endif
    @include('technicians.partials.salary-adjustment-lines')
    </section>
    @include('technicians.partials.salary-debt-summary')
    @if(in_array($sheet->status, ['Draft', 'Finalized', 'Partially Paid'], true))
    <form id="salary-adjustments" method="POST" action="{{ route('technicians.salaries.adjustments', $sheet) }}" class="panel space-y-4 p-5">
        @csrf<x-submission-token />
        <h2 class="font-black">Add an earning or deduction</h2><p class="text-sm text-slate-500">Add as many items as needed, one at a time. Each description and note appears on the payslip. Saving an adjustment updates salary due; it does not record a payment.</p>
        <div class="grid gap-4 sm:grid-cols-3">
            <label><span class="label">Type</span><select name="type" class="field" required><option value="addition" @selected(old('type') === 'addition')>Addition</option><option value="deduction" @selected(old('type') === 'deduction')>Deduction</option></select></label>
            <label><span class="label">Description</span><input name="description" value="{{ old('description') }}" maxlength="150" class="field" placeholder="e.g. Performance bonus or uniform" required></label>
            <label><span class="label">Amount · LKR</span><input type="number" name="amount" value="{{ old('amount') }}" min="0.01" max="999999999999.99" step="0.01" class="field" required></label>
        </div>
        <label class="block"><span class="label">Additional note (optional, printed on payslip)</span><input name="reason" value="{{ old('reason') }}" maxlength="500" class="field"></label>
        <button class="btn-primary">Add to Paysheet</button>
    </form>
    @endif
    <section class="panel overflow-hidden p-0">
        <div class="space-y-2 p-5"><h2 class="font-black">Daily attendance · Check-in &amp; check-out</h2><p class="text-sm text-slate-500">OT requires check-in at or before the staff’s scheduled start and an actual check-out after the scheduled closing grace period. For a 9:00 AM start, 9:00 is eligible; 9:01 is not. Payable OT rounds to the nearest 30 minutes; 15-minute ties round up.</p>
        <p class="text-xs text-slate-500">{{ data_get($sheet->calculation_snapshot, 'attendance_records') !== null ? 'Attendance captured when this paysheet was calculated.' : 'Legacy paysheet: attendance times below are from current records; the saved salary amounts are unchanged.' }}</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-3">Date</th><th class="p-3">Check-in</th><th class="p-3">Check-out</th><th class="p-3">Status</th><th class="p-3">Worked hours</th><th class="p-3">Payable OT minutes</th><th class="p-3">OT (LKR)</th><th class="p-3">Attendance deduction (LKR)</th></tr></thead><tbody>
        @foreach(\Carbon\CarbonPeriod::create($sheet->period_start, $sheet->period_end) as $day)
            @php($row = $attendanceRows->get($day->toDateString()))
            <tr class="border-t border-slate-100"><td class="p-3 whitespace-nowrap">{{ $day->format('d M Y') }}</td><td class="p-3 whitespace-nowrap">{{ !empty($row['in_time']) ? \Carbon\Carbon::parse($row['in_time'])->format('h:i A') : '—' }}</td><td class="p-3 whitespace-nowrap">{{ !empty($row['out_time']) ? \Carbon\Carbon::parse($row['out_time'])->format('h:i A') : '—' }}@if($row['is_auto_checkout'] ?? false)<span class="block text-xs text-blue-700">Auto checkout</span>@endif</td><td class="p-3">{{ $row['status'] ?? 'Not recorded' }}@if($row['is_manual_edit'] ?? false)<span class="block text-xs text-amber-700">Manual amount override</span>@endif</td><td class="p-3">{{ $row['working_hours'] ?? '—' }}</td><td class="p-3">{{ $row['ot_minutes'] ?? '—' }}@if(isset($row['actual_ot_minutes']))<span class="block text-xs text-slate-500">Actual: {{ $row['actual_ot_minutes'] }} min</span>@endif</td><td class="p-3">{{ $row ? number_format($row['ot_amount'], 2) : '—' }}</td><td class="p-3">{{ $row ? number_format($row['deduction_amount'], 2) : '—' }}</td></tr>
        @endforeach
        </tbody></table></div>
    </section>
    @if(in_array($sheet->status, ['Draft', 'Finalized', 'Partially Paid', 'Paid'], true))
    <form method="POST" action="{{ route('technicians.salaries.refresh-attendance', $sheet) }}" id="refresh-salary-calculation" class="panel space-y-3 p-5">
        @csrf<x-submission-token />
        <h2 class="font-black">Recalculate paysheet</h2><p class="text-sm text-slate-500">Apply current staff working times, salary, OT and contribution rules. Manual amounts and descriptions stay. Finalized sheets keep their applied advances, repair and old-balance recoveries. Recorded payments and dates stay; the remaining balance and payment status update. Recalculation does not pay or refund money.</p>
        <label class="block"><span class="label">Reason for recalculation</span><input name="reason" required minlength="5" maxlength="500" class="field" placeholder="e.g. Apply full basic salary and separate unpaid leave deductions"></label>
        <button class="btn-secondary">Recalculate Paysheet</button>
    </form>
    @endif
</div>
</x-layouts::app>
