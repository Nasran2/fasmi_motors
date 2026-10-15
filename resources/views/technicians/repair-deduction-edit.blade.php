<x-layouts::app title="Correct Salary Repair Deduction">
<div class="mx-auto max-w-3xl space-y-5">
    <div><p class="text-xs font-bold uppercase text-emerald-700">Staff bike / salary deduction</p><h1 class="page-title">Correct Salary Deduction</h1><p class="page-subtitle">{{ $sheet->technician->name }} · {{ $plan->jobCard?->number }} · {{ $invoice->number }}</p></div>
    <x-flash/>
    <section class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-5">
        <p class="font-bold text-emerald-900">Deducted from <a class="underline" href="{{ route('technicians.salaries.slip',$sheet) }}">{{ $sheet->number }}</a> · {{ \Carbon\Carbon::parse(data_get($sheet->calculation_snapshot,'salary_cycle.salary_month',$sheet->period_end))->format('F Y') }}</p>
        <div class="mt-4 grid gap-3 sm:grid-cols-3">@foreach(['Current deduction' => $payment->amount,'Salary already paid' => $sheet->paid_amount,'Repair balance' => $invoice->due_amount] as $label => $value)<div class="rounded-xl bg-white p-3"><p class="text-xs text-slate-500">{{ $label }}</p><strong>Rs. {{ number_format($value,2) }}</strong></div>@endforeach</div>
    </section>
    <form method="POST" action="{{ route('technicians.salaries.repairs.update',$payment) }}" class="panel space-y-4" x-data="{amount: @js(old('amount',(float)$payment->amount)), original: {{ (float)$payment->amount }}, net: {{ (float)$sheet->net_payable }}, paid: {{ (float)$sheet->paid_amount }}, due: {{ (float)$invoice->due_amount }}, money(value) { return Number(value).toLocaleString('en-LK',{minimumFractionDigits:2,maximumFractionDigits:2}); }}">
        @csrf<x-submission-token/>@method('PUT')
        <input type="hidden" name="expected_amount" value="{{ $payment->amount }}">
        <label class="block"><span class="label">Corrected deduction amount (LKR) *</span><input name="amount" type="number" min="0" max="{{ round($max,2) }}" step="0.01" class="field text-lg font-black" x-model.number="amount" required></label>
        <p class="text-xs text-slate-500">Enter the total amount that should have been deducted from this paysheet. Enter 0 to reverse this deduction.</p>
        <div class="grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-3">
            <div><p class="text-xs text-slate-500">Repair balance after correction</p><b x-text="'Rs. '+money(due + original - Number(amount || 0))"></b></div>
            <div><p class="text-xs text-slate-500">Revised net salary</p><b x-text="'Rs. '+money(net + original - Number(amount || 0))"></b></div>
            <div><p class="text-xs text-slate-500" x-text="net + original - Number(amount || 0) - paid < 0 ? 'Salary overpayment' : 'Salary still payable'"></p><b x-text="'Rs. '+money(Math.abs(net + original - Number(amount || 0) - paid))"></b></div>
        </div>
        <label class="block"><span class="label">Reason for correction *</span><textarea name="reason" rows="3" class="field" minlength="5" maxlength="1000" required>{{ old('reason') }}</textarea></label>
        <p class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">This updates the repair bill and salary sheet. Cash already paid remains recorded. Any extra salary payable or overpayment is shown separately, and the correction reason is saved in history.</p>
        <div class="flex flex-wrap justify-end gap-3"><a href="{{ $plan->job_card_id ? route('technicians.bike-repairs.show',$plan->job_card_id) : route('technicians.salaries') }}" class="btn-secondary">Cancel</a><button class="btn-primary">Save Deduction Correction</button></div>
    </form>
</div>
</x-layouts::app>
