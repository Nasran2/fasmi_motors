<x-layouts::app title="Salary Details">
<div class="space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-bold uppercase text-red-600">Salary payments / {{ $sheet->number }}</p><h1 class="page-title">{{ $sheet->technician->name }} · Salary Details</h1><p class="page-subtitle">{{ $sheet->period_start->format('d M Y') }} to {{ $sheet->period_end->format('d M Y') }} · {{ $sheet->status }}</p></div>
        <div class="flex flex-wrap gap-2"><a class="btn-secondary" href="{{ route('technicians.salaries') }}">Back to salaries</a><a class="btn-primary" target="_blank" href="{{ route('technicians.salaries.slip', $sheet) }}">View / Print Payslip</a></div>
    </div>
    <x-flash />
    <p class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">You can print this payslip before payment, after a partial payment or after full payment. The slip shows its status, paid amount and remaining balance.</p>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach(['Gross earnings' => $sheet->gross_salary, 'Total deductions' => $sheet->total_deductions, 'Net payable' => $sheet->net_payable, 'Paid so far' => $sheet->paid_amount, 'Remaining balance' => $sheet->balance_amount] as $label => $amount)
            <div class="panel p-4"><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-xl font-black">Rs. {{ number_format($amount, 2) }}</p></div>
        @endforeach
    </div>
    <section class="panel p-5">
        <div class="grid gap-6 sm:grid-cols-2">
            <div><h2 class="font-black text-emerald-700">Earnings</h2><dl class="mt-3 space-y-2 text-sm">
                @foreach(['Base earnings' => $sheet->base_earnings, 'Overtime' => $sheet->ot_amount, 'Allowances' => $sheet->allowances, 'Manual additions' => $sheet->manual_additions, 'Worked-on-leave pay' => $sheet->worked_on_leave_pay] as $label => $amount)
                <div class="flex justify-between gap-3"><dt>{{ $label }}</dt><dd class="font-bold">{{ number_format($amount, 2) }}</dd></div>
                @endforeach
            </dl></div>
            <div><h2 class="font-black text-red-700">Deductions</h2><dl class="mt-3 space-y-2 text-sm">
                @foreach(['Late' => $sheet->late_deduction, 'Early leave' => $sheet->early_leave_deduction, 'Half days' => $sheet->half_day_deduction, 'No pay' => $sheet->no_pay_deduction, 'Advances' => $sheet->total_advance_deduction, 'Job / bike repair' => $sheet->total_job_deduction, 'Employee EPF' => $sheet->epf_employee, 'Other deductions' => $sheet->total_other_deduction, 'Manual deductions' => $sheet->manual_deductions, 'Attendance override adjustment' => data_get($sheet->calculation_snapshot, 'deductions.attendance_adjustment', 0)] as $label => $amount)
                <div class="flex justify-between gap-3"><dt>{{ $label }}</dt><dd class="font-bold">{{ number_format($amount, 2) }}</dd></div>
                @endforeach
            </dl></div>
        </div>
        @if((float) $sheet->commission !== 0.0)<p class="mt-3 text-sm text-amber-800">Commission included in this earlier salary: Rs. {{ number_format($sheet->commission, 2) }}. This historical amount remains in its saved totals.</p>@endif
    @if(data_get($sheet->calculation_snapshot, 'contributions.basic_salary') !== null)
        <p class="mt-3 text-sm text-slate-600">EPF / ETF salary basic: <strong>Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'contributions.basic_salary'), 2) }}</strong>. Used only for EPF and ETF contributions.</p>
    @endif
    @include('technicians.partials.salary-adjustment-lines')
    </section>
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
        <div class="space-y-2 p-5"><h2 class="font-black">Daily attendance · Check-in &amp; check-out</h2><p class="text-sm text-slate-500">OT requires check-in at or before workshop opening time and an actual check-out after the closing grace period. With an 8:00 AM opening, 8:00 is eligible; 8:01 is not. Payable OT rounds to the nearest 30 minutes; 15-minute ties round up.</p>
        <p class="text-xs text-slate-500">{{ data_get($sheet->calculation_snapshot, 'attendance_records') !== null ? 'Attendance captured when this paysheet was calculated.' : 'Legacy paysheet: attendance times below are from current records; the saved salary amounts are unchanged.' }}</p></div>
        <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-3">Date</th><th class="p-3">Check-in</th><th class="p-3">Check-out</th><th class="p-3">Status</th><th class="p-3">Worked hours</th><th class="p-3">Payable OT minutes</th><th class="p-3">OT (LKR)</th><th class="p-3">Attendance deduction (LKR)</th></tr></thead><tbody>
        @foreach(\Carbon\CarbonPeriod::create($sheet->period_start, $sheet->period_end) as $day)
            @php($row = $attendanceRows->get($day->toDateString()))
            <tr class="border-t border-slate-100"><td class="p-3 whitespace-nowrap">{{ $day->format('d M Y') }}</td><td class="p-3 whitespace-nowrap">{{ !empty($row['in_time']) ? \Carbon\Carbon::parse($row['in_time'])->format('h:i A') : '—' }}</td><td class="p-3 whitespace-nowrap">{{ !empty($row['out_time']) ? \Carbon\Carbon::parse($row['out_time'])->format('h:i A') : '—' }}@if($row['is_auto_checkout'] ?? false)<span class="block text-xs text-blue-700">Auto checkout</span>@endif</td><td class="p-3">{{ $row['status'] ?? 'Not recorded' }}@if($row['is_manual_edit'] ?? false)<span class="block text-xs text-amber-700">Manual amount override</span>@endif</td><td class="p-3">{{ $row['working_hours'] ?? '—' }}</td><td class="p-3">{{ $row['ot_minutes'] ?? '—' }}@if(isset($row['actual_ot_minutes']))<span class="block text-xs text-slate-500">Actual: {{ $row['actual_ot_minutes'] }} min</span>@endif</td><td class="p-3">{{ $row ? number_format($row['ot_amount'], 2) : '—' }}</td><td class="p-3">{{ $row ? number_format($row['deduction_amount'], 2) : '—' }}</td></tr>
        @endforeach
        </tbody></table></div>
    </section>
    @if($sheet->status === 'Draft')
    <form method="POST" action="{{ route('technicians.salaries.refresh-attendance', $sheet) }}" class="panel space-y-3 p-5">
        @csrf<x-submission-token />
        <h2 class="font-black">Refresh draft attendance &amp; OT</h2><p class="text-sm text-slate-500">Apply current OT rules to this period and refresh the salary calculation. Manual amount overrides and adjustment descriptions are retained. Finalized salary attendance stays locked.</p>
        <label class="block"><span class="label">Reason for recalculation</span><input name="reason" required minlength="5" maxlength="500" class="field" placeholder="e.g. Apply the corrected 8:00 AM OT eligibility rule"></label>
        <button class="btn-secondary">Refresh Attendance &amp; OT</button>
    </form>
    @endif
</div>
</x-layouts::app>
