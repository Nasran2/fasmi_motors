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
      $cycleLabel = data_get($sheet->calculation_snapshot, 'salary_cycle.salary_cycle_label') ?? match ($sheet->salary_cycle_mode) { 'custom_cutoff' => 'Custom Cut-Off Salary Cycle', 'custom_start' => 'Custom Start Day Salary Cycle', default => 'Calendar Month Salary' };
    @endphp

    <style>
        body { background: #f8fafc; -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
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
            @page { size: A4 portrait; margin: 1cm; }
            .print-border-0 { border: none !important; box-shadow: none !important; padding: 0 !important; }
            @endif
        }
    </style>
</head>
<body class="text-slate-900 antialiased p-4 sm:p-8">

<div class="mx-auto max-w-4xl space-y-5">
  <div class="flex items-center justify-between no-print mb-6">
      <a href="{{ route('technicians.salaries') }}" class="inline-flex items-center justify-center rounded-xl bg-slate-200 px-4 py-2 text-sm font-bold text-slate-700 hover:bg-slate-300 transition">← Back to Salaries</a>
      <button onclick="window.print()" class="inline-flex items-center justify-center rounded-xl bg-[#0b2447] px-4 py-2 text-sm font-bold text-white shadow-lg hover:bg-[#123665] transition">🖨️ Print / Save PDF</button>
  </div>

  <article class="rounded-3xl border border-slate-200 bg-white p-8 shadow-sm print-border-0">
    <header class="flex justify-between border-b-2 border-slate-900 pb-5">
        <div>
            <p class="text-2xl font-black italic"><span class="text-red-600">FASMIR</span> MOTORS</p>
            <p class="text-xs uppercase tracking-widest text-slate-500">Technician Salary Slip</p>
        </div>
        <div class="text-right">
            <p class="font-black">{{ $sheet->number }}</p>
            <p class="text-sm text-slate-500">{{ $salaryMonthLabel }}</p>
        </div>
    </header>
    
    <div class="mt-5 grid grid-cols-2 gap-4 rounded-2xl bg-slate-50 p-4">
        <div>
            <small class="text-slate-500 font-bold uppercase text-[10px]">Technician</small>
            <p class="font-black text-lg">{{ $sheet->technician->name }}</p>
            <p class="text-xs text-slate-500">{{ data_get($sheet->calculation_snapshot, 'technician.salary_type', $sheet->technician->salary_type) }} salary</p>
        </div>
        <div class="text-right">
            <small class="text-slate-500 font-bold uppercase text-[10px]">Status</small>
            <p class="font-black text-lg">{{ $sheet->status }}</p>
            <p class="text-xs text-slate-500">Payment: {{ $sheet->payment_method ?: '—' }}</p>
        </div>
    </div>

    <section class="mt-5 rounded-2xl border border-blue-100 bg-blue-50/60 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <small class="text-blue-700 font-bold uppercase text-[10px] tracking-wider">Salary Cycle</small>
                <p class="font-black text-slate-900">{{ $cycleLabel }}</p>
                <p class="text-xs text-slate-600">Salary Month: {{ $salaryMonthLabel }} · Period: {{ $sheet->period_start->format('d M Y') }} to {{ $sheet->period_end->format('d M Y') }}</p>
            </div>
            <div class="text-right text-xs text-slate-600">
                <p><strong>{{ $sheet->salary_period_days }}</strong> period days</p>
                <p><strong>{{ $sheet->carried_from_previous_month_days }}</strong> previous month days included</p>
                <p><strong>{{ $sheet->carried_to_next_month_days }}</strong> carried forward</p>
            </div>
        </div>
        <p class="mt-2 text-xs text-slate-700">{{ data_get($sheet->calculation_snapshot, 'earnings.pay_basis') }}</p>
        @if(! empty($sheet->excluded_dates))
            <p class="mt-2 text-xs text-red-700"><strong>Excluded / carried forward:</strong> {{ collect($sheet->excluded_dates)->map(fn ($date) => \Carbon\Carbon::parse($date)->format('d M Y'))->join(', ') }}</p>
        @endif
    </section>

    @if(data_get($sheet->calculation_snapshot, 'contributions.basic_salary') !== null)
        <p class="mt-3 text-sm text-slate-600">EPF / ETF salary basic: <strong>Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'contributions.basic_salary'), 2) }}</strong>. Used only for EPF and ETF contributions.</p>
    @endif

    @if($showAttendance)
    <section class="mt-6">
        <h2 class="border-b pb-2 font-black text-blue-700 uppercase tracking-wider text-xs">Attendance & Activity</h2>
        <div class="mt-3 grid grid-cols-3 gap-3 text-sm sm:grid-cols-6">
          @foreach(['Period days'=>$sheet->salary_period_days,'Working days'=>$sheet->total_working_days,'Present'=>$sheet->total_present_days,'Absent'=>$sheet->total_absent_days,'Leave days'=>$sheet->leave_days,'Half days'=>$sheet->half_days,'Late days'=>$sheet->late_days,'Early leave'=>$sheet->early_leave_days,'Worked on leave'=>$sheet->worked_on_leave_days,'Worked hours'=>$sheet->total_worked_hours,'OT hours'=>$sheet->total_ot_hours,'Auto checkout'=>$sheet->auto_checkout_days] as $label=>$value)
          <div class="rounded-xl bg-blue-50 p-3 flex flex-col items-center justify-center text-center">
              <small class="text-slate-500 text-[10px] uppercase font-bold">{{ $label }}</small>
              <p class="font-black text-blue-900 mt-1">{{ $value }}</p>
          </div>
          @endforeach
        </div>
    </section>
    @endif


    <div class="mt-6 grid gap-8 md:grid-cols-2">
      <section>
          <h2 class="border-b pb-2 font-black text-emerald-700 uppercase tracking-wider text-xs">Earnings</h2>
          <dl class="mt-3 space-y-2 text-sm">
            @foreach(['Base earnings'=>$sheet->base_earnings,'OT amount'=>$sheet->ot_amount,'Other allowances'=>$sheet->allowances,'Manual additions'=>$sheet->manual_additions,'Worked-on-leave pay'=>$sheet->worked_on_leave_pay] as $label=>$amount)
                <div class="flex justify-between"><dt class="text-slate-600">{{ $label }}</dt><dd class="font-medium">Rs. {{ number_format($amount,2) }}</dd></div>
            @endforeach
            <div class="flex justify-between border-t border-emerald-200 pt-2 font-black text-emerald-700 mt-3">
                <dt>Gross salary</dt>
                <dd>Rs. {{ number_format($sheet->gross_salary,2) }}</dd>
            </div>
          </dl>
      </section>
      <section>
          <h2 class="border-b pb-2 font-black text-red-700 uppercase tracking-wider text-xs">Deductions</h2>
          <dl class="mt-3 space-y-2 text-sm">
            @foreach(['Late deduction'=>$sheet->late_deduction,'Early leave deduction'=>$sheet->early_leave_deduction,'No-pay deduction'=>$sheet->no_pay_deduction,'Half-day deduction'=>$sheet->half_day_deduction,'Salary advances'=>$sheet->total_advance_deduction,'Job / bike repair'=>$sheet->total_job_deduction,'Employee EPF'=>$sheet->epf_employee,'Other deduction'=>$sheet->total_other_deduction,'Manual deduction'=>$sheet->manual_deductions] as $label=>$amount)
                <div class="flex justify-between"><dt class="text-slate-600">{{ $label }}</dt><dd class="font-medium">Rs. {{ number_format($amount,2) }}</dd></div>
            @endforeach
            @if(data_get($sheet->calculation_snapshot, 'deductions.attendance_adjustment', 0) != 0)
                <div class="flex justify-between"><dt class="text-slate-600">Attendance override adjustment</dt><dd class="font-medium">Rs. {{ number_format(data_get($sheet->calculation_snapshot, 'deductions.attendance_adjustment'), 2) }}</dd></div>
            @endif
            <div class="flex justify-between border-t border-red-200 pt-2 font-black text-red-700 mt-3">
                <dt>Total deductions</dt>
                <dd>Rs. {{ number_format($sheet->total_deductions,2) }}</dd>
            </div>
          </dl>
      </section>
    </div>

    @if((float) $sheet->commission !== 0.0)<p class="mt-3 text-sm text-amber-800">Commission included in this earlier salary: Rs. {{ number_format($sheet->commission, 2) }}. This historical amount remains in its saved totals.</p>@endif
    @include('technicians.partials.salary-adjustment-lines')

    @php($repairDeductions = collect(data_get($sheet->calculation_snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment'))
    @if($repairDeductions->isNotEmpty())
    <section id="staff-bike-repair-deductions" class="mt-6 rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-4 text-sm">
        <h3 class="font-black text-emerald-900">Staff bike repair deductions</h3>
        <p class="mt-1 font-bold text-emerald-800">{{ $sheet->status === 'Draft' ? 'Planned in' : 'Deducted from' }} {{ $sheet->number }}: Rs. {{ number_format($sheet->repair_deduction_amount,2) }}</p>
        <table class="mt-3 w-full"><thead><tr><th class="text-left">Repair job / bike</th><th class="text-right">Balance before</th><th class="text-right">This paysheet</th><th class="text-right">Balance after</th></tr></thead><tbody>
        @foreach($repairDeductions as $repair)<tr><td class="py-2">{{ $repair['job_number'] ?? $repair['reference_no'] }} · {{ $repair['bike_number'] ?? 'Staff bike' }}@php($repairPayment = $sheet->repairPayments->firstWhere('technician_bike_deduction_id',$repair['id']))@if($repairPayment && $sheet->status !== 'Draft' && auth()->user()->canAccess('technician.salaries'))<a class="mt-1 block font-bold text-blue-700 underline print:hidden" href="{{ route('technicians.salaries.repairs.edit',$repairPayment) }}">Correct deduction with reason</a>@endif</td><td class="text-right">{{ number_format($repair['remaining_balance'] ?? 0, 2) }}</td><td class="text-right font-extrabold text-emerald-900">{{ number_format($repair['amount'],2) }}</td><td class="text-right">{{ number_format(max(0, ($repair['remaining_balance'] ?? 0) - $repair['amount']),2) }}</td></tr>@endforeach
        </tbody></table>
        @foreach(data_get($sheet->calculation_snapshot,'repair_corrections',[]) as $correction)<p class="mt-3 rounded-xl bg-white p-3 text-xs">Correction: Rs. {{ number_format($correction['old_amount'],2) }} → Rs. {{ number_format($correction['amount'],2) }} · {{ \Carbon\Carbon::parse($correction['at'])->format('d M Y, h:i A') }}<br>Reason: {{ $correction['reason'] }}</p>@endforeach
    </section>
    @endif
    @if($sheet->advancesUsed->isNotEmpty() || $sheet->sourceDeductions->isNotEmpty())
    <section class="mt-6 grid gap-6 md:grid-cols-2 text-sm bg-slate-50 p-4 rounded-2xl">
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

    <div class="mt-6 grid gap-3 sm:grid-cols-4">
        <div class="rounded-2xl bg-blue-50/50 p-4 border border-blue-100">
            <small class="text-slate-500 font-bold text-[10px] uppercase tracking-wider">Company EPF</small>
            <p class="text-lg font-black text-slate-800 mt-1">Rs. {{ number_format($sheet->epf_company,2) }}</p>
        </div>
        <div class="rounded-2xl bg-blue-50/50 p-4 border border-blue-100">
            <small class="text-slate-500 font-bold text-[10px] uppercase tracking-wider">Company ETF</small>
            <p class="text-lg font-black text-slate-800 mt-1">Rs. {{ number_format($sheet->etf_company,2) }}</p>
        </div>
        <div class="rounded-2xl bg-emerald-50 p-4 border border-emerald-100">
            <small class="text-emerald-700 font-bold text-[10px] uppercase tracking-wider">Paid amount</small>
            <p class="text-xl font-black text-emerald-800 mt-1">Rs. {{ number_format($sheet->paid_amount,2) }}</p>
        </div>
        <div class="rounded-2xl bg-red-50 p-4 border border-red-100">
            <small class="text-red-700 font-bold text-[10px] uppercase tracking-wider">{{ $sheet->balance_amount < 0 ? 'Overpaid' : 'Balance to pay' }}</small>
            <p class="text-xl font-black text-red-800 mt-1">Rs. {{ number_format(abs($sheet->balance_amount),2) }}</p>
        </div>
    </div>

    <div class="mt-6 flex flex-col sm:flex-row items-center justify-between rounded-2xl bg-[#0b2447] p-6 text-white shadow-lg">
        <span class="text-sm font-bold uppercase tracking-widest text-slate-300">Net Salary Payable</span>
        <strong class="text-4xl tracking-tight mt-2 sm:mt-0 text-emerald-400">Rs. {{ number_format($sheet->net_payable,2) }}</strong>
    </div>

    <div class="mt-4 grid grid-cols-2 gap-4 rounded-xl border border-slate-200 p-4 text-sm">
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

    <footer class="mt-16 grid grid-cols-3 gap-8 text-center text-xs text-slate-500">
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
