<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
    @foreach(['earned' => 'Commission earned', 'deducted' => 'Deductions', 'paid' => 'Paid separately', 'covered' => 'Earlier salary / settlement', 'balance' => 'Balance to pay'] as $key => $label)
    <div class="rounded-xl border border-slate-200 bg-slate-50 p-3"><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-1 text-xl font-black">Rs. {{ number_format($totals[$key], 2) }}</p></div>
    @endforeach
</div>
<p class="mt-3 text-xs text-slate-500">Bills are filtered by commission earned date. Paid and deducted amounts show settlements against those bills up to now. Earlier salary-linked commission is excluded from separate payment.</p>
<div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-100"><tr><th class="p-2">Earned date</th><th class="p-2">Bill / job</th><th class="p-2">Bike number</th><th class="p-2">Type</th><th class="p-2">Earned</th><th class="p-2">Deducted</th><th class="p-2">Paid</th><th class="p-2">Balance</th><th class="p-2">Status</th></tr></thead><tbody>
@forelse($rows as $row)
<tr class="border-b border-slate-200"><td class="p-2 whitespace-nowrap">{{ $row->earned_date->format('d M Y') }}</td><td class="p-2">{{ $row->invoice?->number ?: 'Manual commission' }}<span class="block text-xs text-slate-500">{{ $row->jobCard?->number }}</span></td><td class="p-2">{{ $row->invoice?->bike?->number ?: ($row->jobCard?->bike?->number ?: '—') }}</td><td class="p-2">{{ $row->commission_type }}@if($row->note)<span class="block text-xs text-slate-500">{{ $row->note }}</span>@endif</td><td class="p-2">{{ number_format($row->amount, 2) }}</td><td class="p-2 text-red-700">{{ number_format($row->deducted_amount, 2) }}</td><td class="p-2 text-emerald-700">{{ number_format($row->paid_amount, 2) }}</td><td class="p-2 font-bold">{{ number_format($row->separate_balance, 2) }}</td><td class="p-2">{{ $row->salary_sheet_id ? 'In earlier salary' : $row->status }}@if($row->invoice?->status === 'Cancelled' || $row->jobCard?->status === 'Cancelled')<span class="block text-red-700">Source cancelled · excluded</span>@endif</td></tr>
@empty<tr><td colspan="9" class="p-6 text-center text-slate-500">No commission for this period.</td></tr>@endforelse
</tbody></table></div>
@if($rows->flatMap->deductions->isNotEmpty())
<section class="mt-5"><h3 class="font-black text-red-700">Commission deductions</h3><table class="mt-2 w-full text-sm"><thead><tr class="border-b"><th class="p-2 text-left">Date</th><th class="p-2 text-left">Bill / description</th><th class="p-2 text-right">Amount</th></tr></thead><tbody>
@foreach($rows as $row)@foreach($row->deductions as $deduction)<tr class="border-b"><td class="p-2">{{ $deduction->deduction_date->format('d M Y') }}</td><td class="p-2">{{ $row->invoice?->number ?: 'Manual commission' }} · {{ $deduction->description }}</td><td class="p-2 text-right">{{ number_format($deduction->amount, 2) }}</td></tr>@endforeach@endforeach
</tbody></table></section>
@endif
@if($payments->isNotEmpty())
<section class="mt-5"><h3 class="font-black text-emerald-700">Commission payment history</h3><table class="mt-2 w-full text-sm"><thead><tr class="border-b"><th class="p-2 text-left">Date / reference</th><th class="p-2 text-left">Method / account</th><th class="p-2 text-right">Paid against these bills</th></tr></thead><tbody>
@foreach($payments as $entry)@php($payment = $entry['payment'])<tr class="border-b"><td class="p-2">{{ $payment->payment_date->format('d M Y') }} · {{ $payment->number }}@if($payment->note)<span class="block text-xs text-slate-500">{{ $payment->note }}</span>@endif</td><td class="p-2">{{ $payment->payment_method }} · {{ $payment->account?->name }}</td><td class="p-2 text-right">{{ number_format($entry['amount'], 2) }}@if($entry['amount'] != $payment->amount)<span class="block text-xs text-slate-500">Full payment: {{ number_format($payment->amount, 2) }}</span>@endif</td></tr>@endforeach
</tbody></table></section>
@endif
