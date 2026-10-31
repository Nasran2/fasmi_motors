<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Salary Slip - {{ $sheet->number }} - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    
    @php
      $salarySetting = \App\Models\SalarySetting::firstOrCreate([]);
      $showAttendance = $salarySetting->slip_show_attendance ?? true;
      $paperSize = $salarySetting->slip_paper_size ?? 'A4';
      $salaryMonthLabel = \Carbon\Carbon::parse(data_get($sheet->calculation_snapshot, 'salary_cycle.salary_month', $sheet->period_start))->format('F Y');
      $paidLeaveDays = (float) data_get($sheet->calculation_snapshot, 'attendance_summary.approved_leave_days', $sheet->approved_leave_days ?? 0);
      $shopClosedDays = max(0, (float) $sheet->leave_days - $paidLeaveDays);
    @endphp

    <style>
        body { background: #f8fafc; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        .salary-document { width: 100%; max-width: 210mm; margin-inline: auto; }
        .salary-payslip { display: flex; flex-direction: column; box-sizing: border-box; padding: 28px 30px; font-size: 14px; line-height: 1.45; }
        .salary-slip-content { flex: 0 0 auto; min-width: 0; }
        .salary-payslip .mt-6 { margin-top: 20px; }
        .salary-payslip .mt-5 { margin-top: 18px; }
        .salary-payslip .mt-4, .salary-payslip .mt-3 { margin-top: 12px; }
        .salary-payslip .mt-2 { margin-top: 4px; }
        .salary-payslip .mt-1 { margin-top: 2px; }
        .salary-payslip .p-6, .salary-payslip .p-4 { padding: 14px; }
        .salary-payslip .p-3 { padding: 10px; }
        .salary-payslip .gap-8, .salary-payslip .gap-6 { gap: 24px; }
        .salary-payslip .gap-4, .salary-payslip .gap-3 { gap: 12px; }
        .salary-payslip .text-sm { font-size: 13px; }
        .salary-payslip .text-xs { font-size: 11px; }
        .salary-payslip .text-xl { font-size: 16px; }
        .salary-payslip .text-lg { font-size: 15px; }
        .salary-payslip .text-4xl { font-size: 24px; }
        .salary-payslip .salary-slip-header { padding-bottom: 16px; }
        .salary-payslip .salary-person-details { padding: 14px 16px; }
        .salary-payslip .salary-attendance-item { padding: 10px 6px; border-radius: 8px; }
        .salary-payslip .salary-attendance-item small { font-size: 10px; line-height: 1.3; }
        .salary-payslip .salary-attendance-item p { margin-top: 3px; }
        .salary-payslip h2 { padding-bottom: 4px; }
        .salary-payslip .space-y-2 > :not(:last-child) { margin-bottom: 7px; }
        .salary-payslip .py-2 { padding-block: 4px; }
        .salary-payslip .salary-earnings-grid dl > div { gap: 8px; }
        .salary-payslip .salary-earnings-grid dd { white-space: nowrap; }
        .salary-payslip .salary-net-pay { padding: 16px 18px; box-shadow: none; }
        .salary-payslip .salary-payment-summary { padding: 12px 16px; }
        .salary-payslip .salary-signatures { flex: 0 0 auto; margin-top: auto; padding-top: 36px; gap: 20px; }
        .salary-payslip .salary-signatures .pt-3 { padding-top: 7px; }
        .salary-payslip table { width: 100%; }
        .salary-payslip td, .salary-payslip th { overflow-wrap: anywhere; }
        @media screen and (min-width: 640px) {
            .salary-payslip { min-height: 297mm; }
            .salary-payslip .salary-earnings-grid, .salary-payslip .salary-reference-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
            .salary-payslip .salary-summary-grid { grid-template-columns: repeat(var(--salary-summary-columns), minmax(0, 1fr)); }
        }
        @media print {
            article { border: 0 !important; border-radius: 0 !important; box-shadow: none !important; }
            article [class*="bg-"] { background: #fff !important; color: #162337 !important; }
            article [class*="text-white"] { color: #162337 !important; }
            tr { break-inside: avoid; page-break-inside: avoid; }
            thead { display: table-header-group; }
            tfoot { display: table-row-group; }
            footer { break-inside: avoid; page-break-inside: avoid; }

            body { background: white; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            @if($paperSize === '80mm')
            @page { size: 80mm auto; margin: 0; }
            .salary-payslip { display: block; min-height: 0; }
            .salary-payslip .salary-signatures { margin-top: 16px; padding-top: 12px; }
            body { font-size: 11px !important; width: 80mm !important; overflow: hidden !important; }
            .grid { grid-template-columns: 1fr !important; gap: 0.5rem !important; }
            .text-3xl { font-size: 1.25rem !important; }
            .text-2xl { font-size: 1.1rem !important; }
            .p-8, .p-5, .p-4, .p-3 { padding: 0.5rem !important; }
            .gap-8, .gap-6, .gap-4, .gap-3 { gap: 0.5rem !important; }
            .mt-12, .mt-6, .mt-5 { margin-top: 1rem !important; }
            .bg-slate-50, .bg-blue-50, .bg-emerald-50, .bg-red-50, .bg-slate-950 { background: transparent !important; color: black !important; border: 1px dashed #ccc !important; box-shadow: none !important; }
            .text-white { color: black !important; }
            .text-emerald-700, .text-red-700, .text-blue-700, .text-red-600 { color: black !important; }
            .rounded-2xl, .rounded-3xl, .rounded-xl { border-radius: 0 !important; }
            @else
            @page { size: A4 portrait; margin: 12mm; }
            .salary-document { width: auto; max-width: none; margin: 0; }
            .salary-document > .salary-payslip { margin: 0 !important; }
            .print-border-0 { border: none !important; box-shadow: none !important; padding: 0 !important; }
            body, .salary-payslip { font-size: 12px !important; line-height: 1.4 !important; }
            .salary-payslip { min-height: 272mm; }
            .salary-payslip .mt-6, .salary-payslip .mt-5 { margin-top: 14px !important; }
            .salary-payslip .mt-4, .salary-payslip .mt-3 { margin-top: 9px !important; }
            .salary-payslip .mt-2 { margin-top: 3px !important; }
            .salary-payslip .mt-1 { margin-top: 2px !important; }
            .salary-payslip .p-6, .salary-payslip .p-4 { padding: 10px 12px !important; }
            .salary-payslip .p-3 { padding: 8px 6px !important; }
            .salary-payslip .gap-8, .salary-payslip .gap-6 { gap: 18px !important; }
            .salary-payslip .gap-4, .salary-payslip .gap-3 { gap: 8px !important; }
            .salary-payslip .text-4xl, .salary-payslip .text-3xl { font-size: 22px !important; }
            .salary-payslip .text-2xl { font-size: 22px !important; }
            .salary-payslip .text-xl, .salary-payslip .text-lg { font-size: 15px !important; }
            .salary-payslip .text-sm { font-size: 12px !important; }
            .salary-payslip .text-xs { font-size: 10.5px !important; }
            .salary-payslip .py-2 { padding-block: 5px !important; }
            .salary-payslip .pt-2 { padding-top: 4px !important; }
            .salary-payslip .salary-slip-header { padding-bottom: 12px !important; }
            .salary-payslip h2 { padding-bottom: 3px !important; break-after: avoid-page; }
            .salary-payslip .space-y-2 > :not(:last-child) { margin-bottom: 6px !important; }
            .salary-payslip .salary-person-details { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .salary-payslip .salary-attendance-grid { grid-template-columns: repeat(6, minmax(0, 1fr)) !important; }
            .salary-payslip .salary-attendance-item small { font-size: 9.5px !important; }
            .salary-payslip .salary-attendance-item { background: #f1f5f9 !important; border: 1px solid #e2e8f0; }
            .salary-payslip .salary-person-details { background: #f8fafc !important; }
            .salary-payslip .salary-earnings-grid, .salary-payslip .salary-reference-grid { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .salary-payslip .salary-summary-grid { grid-template-columns: repeat(var(--salary-summary-columns), minmax(0, 1fr)) !important; }
            .salary-payslip .salary-net-pay { flex-direction: row !important; padding: 12px 14px !important; background: #0b2447 !important; color: #fff !important; box-shadow: none !important; }
            .salary-payslip .salary-net-pay span { color: #dbeafe !important; }
            .salary-payslip .salary-net-pay strong { color: #34d399 !important; }
            .salary-payslip .salary-net-pay strong { margin-top: 0 !important; }
            .salary-payslip .salary-payment-summary { grid-template-columns: repeat(2, minmax(0, 1fr)) !important; }
            .salary-payslip .salary-signatures { margin-top: auto !important; padding-top: 12mm; grid-template-columns: repeat(3, minmax(0, 1fr)) !important; font-size: 10.5px !important; }
            .salary-payslip .salary-signatures .pt-3 { padding-top: 5px !important; }
            .salary-payslip .salary-attendance, .salary-payslip .salary-person-details,
            .salary-payslip .salary-summary-grid, .salary-payslip .salary-net-pay,
            .salary-payslip .salary-payment-summary { break-inside: avoid; page-break-inside: avoid; }
            .salary-payslip .salary-debt-summary { border: 2px solid #b91c1c !important; }
            .salary-payslip .salary-debt-summary strong { color: #991b1b !important; }
            .salary-payslip .text-slate-300 { color: #162337 !important; }

            @endif
        }
    </style>
</head>
<body class="text-slate-900 antialiased p-4 sm:p-8">

<div class="salary-document mx-auto max-w-4xl space-y-5">
  <div class="flex items-center justify-between no-print mb-6">
      <a href="{{ route('technicians.salaries') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-300 transition">← Back to Salaries</a>
      <button onclick="window.print()" class="inline-flex items-center justify-center rounded-xl bg-[#0b2447] px-4 py-2 text-sm font-bold text-white shadow-lg hover:bg-[#123665] transition">🖨️ Print / Save PDF</button>
  </div>

  @if($attendanceChanged ?? false)
      <p class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 no-print">Attendance changed after this draft was saved. <a class="font-bold underline" href="{{ route('technicians.salaries.show', $sheet) }}#refresh-salary-calculation">Refresh the draft calculation</a> to update attendance totals and salary together before printing.</p>
  @endif

  <article class="salary-payslip rounded-3xl border border-slate-200 bg-white p-8 shadow-sm print-border-0">
    <div class="salary-slip-content">
    <header class="salary-slip-header flex justify-between border-b-2 border-slate-900 pb-5">
        <div>
            <p class="text-2xl font-black italic"><span class="text-red-600">FASMIR</span> MOTORS</p>
            <p class="text-xs uppercase tracking-widest text-slate-500">Salary Slip</p>
        </div>
        <div class="text-right">
            <p class="font-black">{{ $sheet->number }}</p>
            <p class="text-sm text-slate-500">{{ $salaryMonthLabel }}</p>
        </div>
    </header>
    
    <div class="salary-person-details mt-5 grid grid-cols-2 gap-4 rounded-2xl bg-slate-50 p-4">
        <div>
            <small class="text-slate-500 font-bold uppercase text-[10px]">Name</small>
            <p class="font-black text-lg">{{ $sheet->technician->name }}</p>
            <p class="text-xs text-slate-500">{{ data_get($sheet->calculation_snapshot, 'technician.salary_type', $sheet->technician->salary_type) }} salary</p>
        </div>
        <div class="text-right">
            <small class="text-slate-500 font-bold uppercase text-[10px]">Status</small>
            <p class="font-black text-lg">{{ $sheet->status }}</p>
            <p class="text-xs text-slate-500">Payment: {{ $sheet->payment_method ?: '—' }}</p>
        </div>
    </div>

    @if(data_get($sheet->calculation_snapshot, 'earnings.pay_basis'))
        <p class="mt-3 text-xs text-slate-700">{{ data_get($sheet->calculation_snapshot, 'earnings.pay_basis') }}</p>
    @endif

    @if($showAttendance)
    <section class="salary-attendance mt-6">
        <h2 class="border-b pb-2 font-black text-blue-700 uppercase tracking-wider text-xs">Attendance & Activity</h2>
        <div class="salary-attendance-grid mt-3 grid grid-cols-3 gap-3 text-sm sm:grid-cols-6">
          @php
              $attendanceItems = [
                  'Working days' => 0 + (float) $sheet->total_working_days,
                  'Present' => 0 + (float) $sheet->total_present_days,
                  'Absent / unpaid' => 0 + (float) $sheet->total_absent_days,
                  'Leave days' => 0 + (float) $sheet->leave_days,
                  'Half days' => 0 + (float) $sheet->half_days,
                  'Late days' => (int) $sheet->late_days,
                  'Early leave' => (int) $sheet->early_leave_days,
                  'Worked on leave' => (int) $sheet->worked_on_leave_days,
                  'Worked hours' => 0 + (float) $sheet->total_worked_hours,
                  'OT hours' => 0 + (float) $sheet->total_ot_hours,
                  'Auto checkout' => (int) $sheet->auto_checkout_days,
              ];
              if ($paidLeaveDays > 0) {
                  $attendanceItems['Paid leave'] = 0 + (float) $paidLeaveDays;
              }
          @endphp
          @foreach($attendanceItems as $label => $value)
          <div class="salary-attendance-item rounded-xl bg-blue-50 p-3 flex flex-col items-center justify-center text-center">
              <small class="text-slate-500 text-[10px] uppercase font-bold">{{ $label }}</small>
              <p class="font-black text-blue-900 mt-1">{{ $value }}</p>
          </div>
          @endforeach
        </div>
    </section>
    @endif


    @if($sheet->status === 'Draft' && $sheet->uses_legacy_daily_salary)
        <p class="mt-3 rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 no-print">This draft uses the previous daily earnings calculation. Open <a href="{{ route('technicians.salaries.show', $sheet) }}#refresh-salary-calculation" class="font-bold underline">salary details → Refresh salary calculation</a> to use full Basic Salary with separate unpaid leave / absence deductions.</p>
    @endif
    @php
      $earningsList = array_filter([
          $sheet->base_earnings_label => $sheet->base_earnings,
          'OT amount' => $sheet->ot_amount,
          'Other allowances' => $sheet->allowances,
          'Manual additions' => $sheet->manual_additions,
          'Worked-on-leave pay' => $sheet->worked_on_leave_pay,
      ], fn ($amt) => (float) $amt > 0);

      $deductionsList = array_filter([
          'Late deduction' => $sheet->late_deduction,
          'Early leave deduction' => $sheet->early_leave_deduction,
          'Unpaid leave / absence' => $sheet->no_pay_deduction,
          'Half-day deduction' => $sheet->half_day_deduction,
          'Salary advances' => $sheet->total_advance_deduction,
          'Job / bike repair' => $sheet->total_job_deduction,
          'Employee EPF' => $sheet->epf_employee,
          'Other deduction' => $sheet->total_other_deduction,
          'Manual deduction' => $sheet->manual_deductions,
          'Old balance recovery' => $sheet->old_balance_deduction,
      ], fn ($amt) => (float) $amt > 0);

      $attendanceAdjustment = (float) data_get($sheet->calculation_snapshot, 'deductions.attendance_adjustment', 0);
    @endphp
    @if(data_get($sheet->calculation_snapshot, 'earnings.salary_basis') === 'full_basic' && (float) $sheet->no_pay_deduction > 0)
        <p class="mt-3 text-xs text-slate-600">Unpaid leave / absence: {{ data_get($sheet->calculation_snapshot, 'earnings.unpaid_leave_days', $sheet->total_absent_days) }} day(s) × Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.unpaid_leave_rate', 0), 2) }}.</p>
    @endif


    @if((float) data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_auto', 0) > 0)
        <p class="mt-3 text-xs text-emerald-800">Worked on shop-closed days: {{ data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_days') }} day(s) × Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_rate', 0), 2) }} = Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_auto', 0), 2) }} extra pay.@if((float) data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_manual', 0) > 0) Manual supplement: Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'earnings.worked_on_leave_manual', 0), 2) }}.@endif</p>
    @endif
    <div class="salary-earnings-grid mt-6 grid gap-8 md:grid-cols-2">
      <section>
          <h2 class="border-b pb-2 font-black text-emerald-700 uppercase tracking-wider text-xs">Earnings</h2>
          <dl class="mt-3 space-y-2 text-sm">
            @forelse($earningsList as $label => $amount)
                <div class="flex justify-between"><dt class="text-slate-600">{{ $label }}</dt><dd class="font-medium">Rs. {{ number_format($amount, 2) }}</dd></div>
            @empty
                <div class="text-xs text-slate-400 italic">No earnings recorded</div>
            @endforelse
            <div class="flex justify-between border-t border-emerald-200 pt-2 font-black text-emerald-700 mt-3">
                <dt>Gross salary</dt>
                <dd>Rs. {{ number_format($sheet->gross_salary, 2) }}</dd>
            </div>
          </dl>
      </section>
      <section>
          <h2 class="border-b pb-2 font-black text-red-700 uppercase tracking-wider text-xs">Deductions</h2>
          <dl class="mt-3 space-y-2 text-sm">
            @forelse($deductionsList as $label => $amount)
                <div class="flex justify-between"><dt class="text-slate-600">{{ $label }}</dt><dd class="font-medium">Rs. {{ number_format($amount, 2) }}</dd></div>
            @empty
                @if($attendanceAdjustment == 0.0)
                    <div class="text-xs text-slate-400 italic">No deductions</div>
                @endif
            @endforelse
            @if($attendanceAdjustment != 0.0)
                <div class="flex justify-between"><dt class="text-slate-600">Attendance override adjustment</dt><dd class="font-medium">Rs. {{ number_format($attendanceAdjustment, 2) }}</dd></div>
            @endif
            <div class="flex justify-between border-t border-red-200 pt-2 font-black text-red-700 mt-3">
                <dt>Total deductions</dt>
                <dd>Rs. {{ number_format($sheet->total_deductions, 2) }}</dd>
            </div>
          </dl>
      </section>
    </div>

    @if((float) $sheet->commission !== 0.0)<p class="mt-3 text-sm text-amber-800">Commission included in this earlier salary: Rs. {{ number_format($sheet->commission, 2) }}. This historical amount remains in its saved totals.</p>@endif
    @include('technicians.partials.salary-adjustment-lines')

    @include('technicians.partials.salary-debt-summary')
    @if($sheet->advancesUsed->isNotEmpty() || $sheet->sourceDeductions->isNotEmpty())
    <section class="salary-reference-grid mt-6 grid gap-6 md:grid-cols-2 text-sm bg-slate-50 p-4 rounded-2xl">
        <div>
            <h3 class="font-black uppercase text-[10px] text-slate-500 tracking-wider">Advance Details</h3>
            @forelse($sheet->advancesUsed as $advance)
                <p class="mt-2 flex justify-between"><span>{{ $advance->number }} <span class="text-slate-400">· {{ $advance->payment_date->format('d M') }}</span></span><strong class="text-slate-800">Rs. {{ number_format($advance->amount,2) }}</strong></p>
            @empty
                <p class="text-slate-400 mt-2 text-xs">None</p>
            @endforelse
        </div>
        <div>
            <h3 class="font-black uppercase text-[10px] text-slate-500 tracking-wider">Deduction References</h3>
            @forelse($sheet->sourceDeductions as $deduction)
                <p class="mt-2 flex justify-between"><span>{{ $deduction->deduction_name }} <span class="text-slate-400">· {{ $deduction->reference_no ?: $deduction->source_type }}</span></span><strong class="text-slate-800">Rs. {{ number_format($deduction->amount,2) }}</strong></p>
            @empty
                <p class="text-slate-400 mt-2 text-xs">See calculation snapshot for instalments.</p>
            @endforelse
        </div>
    </section>
    @endif

    @php
        $bottomCards = [];
        if ((float) $sheet->epf_company > 0) {
            $bottomCards[] = [
                'label' => 'Company EPF',
                'amount' => $sheet->epf_company,
                'bg' => 'bg-blue-50/50',
                'border' => 'border-blue-100',
                'title_color' => 'text-slate-500',
                'value_color' => 'text-slate-800',
            ];
        }
        if ((float) $sheet->etf_company > 0) {
            $bottomCards[] = [
                'label' => 'Company ETF',
                'amount' => $sheet->etf_company,
                'bg' => 'bg-blue-50/50',
                'border' => 'border-blue-100',
                'title_color' => 'text-slate-500',
                'value_color' => 'text-slate-800',
            ];
        }
        if ((float) $sheet->paid_amount > 0) {
            $bottomCards[] = [
                'label' => 'Paid amount',
                'amount' => $sheet->paid_amount,
                'bg' => 'bg-emerald-50',
                'border' => 'border-emerald-100',
                'title_color' => 'text-emerald-700',
                'value_color' => 'text-emerald-800',
            ];
        }
        if ((float) abs($sheet->balance_amount) > 0) {
            $bottomCards[] = [
                'label' => $sheet->balance_amount < 0 ? 'Overpaid' : 'Balance to pay',
                'amount' => abs($sheet->balance_amount),
                'bg' => 'bg-red-50',
                'border' => 'border-red-100',
                'title_color' => 'text-red-700',
                'value_color' => 'text-red-800',
            ];
        }
        $bCount = count($bottomCards);
    @endphp

    @if($bCount > 0)
    <div style="--salary-summary-columns: {{ $bCount }}" class="salary-summary-grid mt-6 grid gap-3 @if($bCount === 4) sm:grid-cols-4 @elseif($bCount === 3) sm:grid-cols-3 @elseif($bCount === 2) sm:grid-cols-2 @else sm:grid-cols-1 @endif">
        @foreach($bottomCards as $card)
        <div class="rounded-2xl {{ $card['bg'] }} p-4 border {{ $card['border'] }}">
            <small class="{{ $card['title_color'] }} font-bold text-[10px] uppercase tracking-wider">{{ $card['label'] }}</small>
            <p class="text-xl font-black {{ $card['value_color'] }} mt-1">Rs. {{ number_format($card['amount'], 2) }}</p>
        </div>
        @endforeach
    </div>
    @endif

    <div class="salary-net-pay mt-6 flex flex-col sm:flex-row items-center justify-between rounded-2xl bg-[#0b2447] p-6 text-white shadow-lg">
        <span class="text-sm font-bold uppercase tracking-widest text-slate-300">Net Salary Payable</span>
        <strong class="text-4xl tracking-tight mt-2 sm:mt-0 text-emerald-400">Rs. {{ number_format($sheet->net_payable,2) }}</strong>
    </div>

    <div class="salary-payment-summary mt-4 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 p-4 text-sm">
        <p>Paid so far <strong class="block">Rs. {{ number_format($sheet->paid_amount, 2) }}</strong></p>
        <p>{{ $sheet->balance_amount < 0 ? 'Overpaid' : 'Remaining balance' }} <strong class="block">Rs. {{ number_format(abs($sheet->balance_amount), 2) }}</strong></p>
    </div>
    @if($sheet->status === 'Draft')<p class="mt-3 text-sm font-bold text-amber-800">Draft payslip — calculation is not finalized and no payment is confirmed by this slip.</p>@elseif($sheet->status !== 'Paid')<p class="mt-3 text-sm font-bold text-amber-800">{{ $sheet->status }} — this payslip does not confirm full payment.</p>@endif

    @if($sheet->payment_note)
        <p class="mt-4 rounded-xl bg-amber-50 border border-amber-200 p-3 text-sm text-amber-900">
            <strong class="uppercase text-[10px] tracking-wider block mb-1">Payment note</strong> 
            {{ $sheet->payment_note }}
        </p>
    @endif

    </div>

    <footer class="salary-signatures mt-16 grid grid-cols-3 gap-8 text-center text-xs text-slate-500">
        <div>
            <div class="border-t border-slate-300 pt-3">Prepared by: {{ $sheet->preparedBy?->name ?? '—' }}</div>
        </div>
        <div>
            <div class="border-t border-slate-300 pt-3">Authorized signature</div>
        </div>
        <div>
            <div class="border-t border-slate-300 pt-3">Paid date: {{ $sheet->payment_date?->format('d M Y') ?? $sheet->paid_at?->format('d M Y') ?? '—' }}</div>
        </div>
    </footer>
  </article>
</div>

@if(request()->boolean('print'))<script>window.addEventListener('load', async () => { if (document.fonts) await document.fonts.ready; window.print(); }, {once:true});</script>@endif
</body>
</html>
