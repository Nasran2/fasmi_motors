@php
    $oldBalanceRows = collect(data_get($sheet->calculation_snapshot, 'old_balances', []));
    $repairBalanceRows = collect(data_get($sheet->calculation_snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment');
    $debtTotals = data_get($sheet->calculation_snapshot, 'debt_summary');
@endphp
@if($oldBalanceRows->isNotEmpty() || $repairBalanceRows->isNotEmpty())
<section id="staff-bike-repair-deductions" class="salary-debt-summary mt-6 rounded-xl border-2 border-red-300 bg-red-50 p-4 text-sm">
    <h3 class="font-black text-red-900">Old balances &amp; repairs</h3>
    @if($debtTotals)
    <div class="mt-3 grid grid-cols-3 gap-3">
        <div><p class="text-xs font-bold">Total owed before</p><strong class="text-xl">Rs. {{ number_format($debtTotals['total_owed'], 2) }}</strong></div>
        <div><p class="text-xs font-bold">{{ $sheet->status === 'Draft' ? 'Planned recovery' : 'Recovered this salary' }}</p><strong class="text-xl">Rs. {{ number_format($debtTotals['deducted_this_month'], 2) }}</strong></div>
        <div><p class="text-xs font-bold text-red-800">Balance carried to next month</p><strong class="text-2xl text-red-800">Rs. {{ number_format($debtTotals['balance_carried_forward'], 2) }}</strong></div>
    </div>
    @if($debtTotals['shortfall'] > 0)<p class="mt-2 font-bold text-red-800">Salary after requested deductions: Rs. {{ number_format($debtTotals['requested_salary_balance'], 2) }}. Unrecovered debt remains outstanding; cash salary payable is Rs. {{ number_format($sheet->net_payable, 2) }}.</p>@endif
    @endif
    <table class="mt-3 w-full text-xs"><thead><tr class="border-b border-red-200"><th class="py-2 text-left">Debt / reference</th><th class="text-right">Balance before</th><th class="text-right">This salary</th><th class="text-right">Remaining</th></tr></thead><tbody>
    @foreach($oldBalanceRows as $debt)
    <tr><td class="py-2"><strong>{{ $debt['description'] }}</strong><span class="block">{{ $debt['reference'] ?? '' }}</span>@if($debt['note'])<span class="block">{{ $debt['note'] }}</span>@endif</td><td class="text-right">{{ number_format($debt['remaining_balance'], 2) }}</td><td class="text-right font-bold">{{ number_format($debt['amount'], 2) }}</td><td class="text-right font-bold">{{ number_format($debt['remaining_balance'] - $debt['amount'], 2) }}</td></tr>
    @endforeach
    @if($repairBalanceRows->isNotEmpty())<tr><th colspan="4" class="pt-2 text-left">Staff bike repair deductions</th></tr>@endif
    @foreach($repairBalanceRows as $repair)
    <tr><td class="py-2">{{ $repair['job_number'] ?? $repair['reference_no'] }} · {{ $repair['bike_number'] ?? 'Staff bike' }}<span class="block">{{ $repair['invoice_number'] ?? '' }}</span><?php $repairPayment = $sheet->repairPayments->firstWhere('technician_bike_deduction_id', $repair['id']); ?>@if($repairPayment && $sheet->status !== 'Draft' && auth()->user()->canAccess('technician.salaries'))<a class="block text-blue-700 underline print:hidden" href="{{ route('technicians.salaries.repairs.edit', $repairPayment) }}">Correct deduction with reason</a>@endif</td><td class="text-right">{{ number_format($repair['remaining_balance'] ?? 0, 2) }}</td><td class="text-right font-bold">{{ number_format($repair['amount'], 2) }}</td><td class="text-right font-bold">{{ number_format(max(0, ($repair['remaining_balance'] ?? 0) - $repair['amount']), 2) }}</td></tr>
    @endforeach
    </tbody></table>
    <p class="mt-2 text-xs font-bold">Remaining old and repair balances are offered next month. Choose how much to deduct then. {{ $sheet->status === 'Draft' ? 'Draft amounts are planned only.' : '' }}</p>
    @foreach(data_get($sheet->calculation_snapshot, 'repair_corrections', []) as $correction)<p class="mt-2 text-xs">Correction: {{ number_format($correction['old_amount'], 2) }} → {{ number_format($correction['amount'], 2) }} · {{ $correction['reason'] }}</p>@endforeach
</section>
@endif
