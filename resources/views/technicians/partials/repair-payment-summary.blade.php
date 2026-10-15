@if($job->invoice && $staffRepairSummary)
@php($recoveryPlan = $staffRepairSummary['plan'])
<section id="staff-repair-payment" class="mt-4 space-y-4 rounded-2xl border-2 {{ $staffRepairSummary['deducted'] > 0 ? 'border-emerald-300 bg-emerald-50' : ($recoveryPlan ? 'border-amber-300 bg-amber-50' : 'border-blue-200 bg-blue-50') }} p-4">
    <div class="flex flex-wrap items-center justify-between gap-2">
        <h3 class="text-base font-black">Staff bike payment</h3>
        <span class="rounded-full bg-white px-3 py-1 text-xs font-extrabold">{{ $recoveryPlan ? 'Deduct from salary' : 'Pay normally' }}</span>
    </div>
    @if($recoveryPlan)
        <p class="text-sm font-bold {{ $staffRepairSummary['deducted'] > 0 ? 'text-emerald-800' : 'text-amber-900' }}">{{ $staffRepairSummary['deducted'] > 0 ? 'Salary deductions applied' : 'Salary deduction scheduled — not yet deducted' }}</p>
        <p class="text-sm">{{ $recoveryPlan->deduction_type }} · Rs. {{ number_format(min($recoveryPlan->installment_amount, $staffRepairSummary['balance']),2) }} suggested · Next salary month {{ $recoveryPlan->next_deduction_month?->format('M Y') ?: 'Completed' }}</p>
    @else
        <p class="text-sm font-semibold">{{ $staffRepairSummary['deducted'] > 0 ? 'Remaining balance is payable normally. Previous salary deductions are shown below.' : 'This repair uses normal customer payment methods.' }}</p>
    @endif
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl bg-white p-3"><span class="text-xs font-bold text-emerald-700">Deducted from salary</span><strong class="mt-1 block text-xl text-emerald-800">Rs. {{ number_format($staffRepairSummary['deducted'],2) }}</strong></div>
        <div class="rounded-xl bg-white p-3"><span class="text-xs font-bold text-red-700">Repair balance remaining</span><strong class="mt-1 block text-xl text-red-700">Rs. {{ number_format($staffRepairSummary['balance'],2) }}</strong></div>
    </div>
    @foreach($staffRepairSummary['payments']->where('status','Completed') as $salaryPayment)
        <div class="rounded-xl border border-emerald-200 bg-white p-3 text-sm">
            <p class="font-extrabold text-emerald-800">Rs. {{ number_format($salaryPayment->amount,2) }} deducted from salary</p>
            @if($salaryPayment->salarySheet)
                <p class="mt-1">From <a class="font-bold text-blue-700 underline" href="{{ route('technicians.salaries.slip', $salaryPayment->salarySheet) }}#staff-bike-repair-deductions">{{ $salaryPayment->salarySheet->number }}</a> · {{ \Carbon\Carbon::parse(data_get($salaryPayment->salarySheet->calculation_snapshot,'salary_cycle.salary_month',$salaryPayment->salarySheet->period_end))->format('F Y') }}</p>
            @else<p class="mt-1">{{ $salaryPayment->note ?: 'Earlier salary recovery' }}</p>@endif
            @if($salaryPayment->salarySheet && auth()->user()->canAccess('technician.salaries'))<a href="{{ route('technicians.salaries.repairs.edit',$salaryPayment) }}" class="mt-2 inline-block font-bold text-blue-700 underline">Correct deduction with reason</a>@endif
        </div>
    @endforeach
    @if(auth()->user()->canAccess('technician.bike_deductions') && $job->invoice->status !== 'Cancelled' && $job->status !== 'Cancelled')
    <details class="rounded-xl border border-slate-200 bg-white p-3" @if($errors->hasAny(['reason','payment_mode','installment_amount','start_month'])) open @endif>
        <summary class="cursor-pointer text-sm font-extrabold text-blue-700">Edit payment arrangement</summary>
        <form method="POST" action="{{ route('technicians.bike-repairs.update',$job) }}" class="mt-4 space-y-4" x-data="{paymentMode: @js(old('payment_mode', $staffRepairSummary['mode']))}">
            @csrf<x-submission-token/>@method('PUT')
            <label class="block"><span class="label">Payment arrangement</span><select name="payment_mode" class="field" x-model="paymentMode"><option value="normal">Pay normally</option><option value="salary">Deduct from salary</option></select></label>
            <fieldset x-show="paymentMode === 'salary'" :disabled="paymentMode !== 'salary'">@include('technicians.partials.repair-plan-fields', ['prefix' => '', 'plan' => $recoveryPlan])</fieldset>
            <label class="block"><span class="label">Reason for change {{ $staffRepairSummary['requires_reason'] ? '*' : '(optional)' }}</span><textarea name="reason" rows="2" class="field" minlength="5" maxlength="1000" @required($staffRepairSummary['requires_reason'])>{{ old('reason') }}</textarea></label>
            @if($staffRepairSummary['requires_reason'])<p class="text-xs text-slate-600">Past deductions stay linked to their salary sheets. To change an amount already deducted, use “Correct deduction with reason” above.</p>@endif
            <button class="btn-primary">Save Payment Arrangement</button>
        </form>
    </details>
    @endif
</section>
@endif
