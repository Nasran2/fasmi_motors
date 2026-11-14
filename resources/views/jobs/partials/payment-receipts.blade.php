@if($paymentInvoices->contains(fn ($invoice) => $invoice->payments->isNotEmpty()))
<div class="panel" id="job-payment-receipts">
    <h2 class="mb-3 font-bold">Payments received</h2>
    <p class="mb-3 text-xs text-slate-500">Correct the method or amount with a reason. The original receipt and its replacement remain in the change history.</p>
    <div class="space-y-3">
        @foreach($receiptGroups as $receiptGroup)
        @php
            $receipt = $receiptGroup->first();
            $paymentInvoice = $receipt->invoice;
            $snapshot = app(App\Services\PaymentCorrectionService::class)->receiptSnapshot($receiptGroup);
            $blockedReason = $receiptGroup->map(fn ($item) => $item->correction_block_reason)->filter()->first();
            $primaryInvoice = $receipt->source_job_card_id === $job->id ? ($job->invoice ?? $paymentInvoice) : $paymentInvoice;
            $eligibleIds = App\Support\PreviousCustomerBalance::forJob($job, includeSettled: true)->pluck('invoice_id')->push($primaryInvoice->id)->unique();
            $eligibleInvoices = $paymentInvoices->whereIn('id', $eligibleIds);
            $currentAvailable = (float) $primaryInvoice->due_amount + $receiptGroup->where('invoice_id', $primaryInvoice->id)->sum('amount');
            $previousAvailable = $eligibleInvoices->where('id', '!=', $primaryInvoice->id)->sum('due_amount') + $receiptGroup->where('invoice_id', '!=', $primaryInvoice->id)->sum('amount');
        @endphp
        <div class="rounded-xl border border-slate-200 p-3 text-xs">
            <div class="flex justify-between gap-3"><span class="font-semibold">{{ $receipt->number ?: 'Legacy receipt' }}</span><span class="font-bold">Rs. {{ number_format($receipt->method === 'Salary Deduction' ? $snapshot['amount'] : $snapshot['received_amount'], 2) }}</span></div>
            <p class="mt-1 text-slate-500">{{ $paymentInvoice->number }} · {{ $receipt->paid_at->format('d M Y') }}</p>
            <p class="mt-1">{{ $receipt->method }} · {{ $receipt->status }} · {{ $receipt->receiving_account_label }}</p>
            @if($receipt->method === 'Salary Deduction' && $receipt->salarySheet)
            <p class="mt-2 rounded-lg bg-emerald-50 p-2 font-bold text-emerald-800">Rs. {{ number_format($receipt->amount,2) }} deducted from salary <a href="{{ route('technicians.salaries.slip',$receipt->salarySheet) }}#staff-bike-repair-deductions" class="underline">{{ $receipt->salarySheet->number }}</a></p>
            @if($receipt->status === 'Completed' && auth()->user()->canAccess('technician.salaries'))<a class="mt-2 inline-block font-bold text-blue-700 underline" href="{{ route('technicians.salaries.repairs.edit',$receipt) }}">Correct deduction with reason</a>@endif
            @endif
            @if($snapshot['change_amount'] > 0)<p class="mt-1 text-violet-600">Applied: Rs. {{ number_format($snapshot['amount'],2) }} · Change: Rs. {{ number_format($snapshot['change_amount'],2) }}</p>@endif
            @if($receiptGroup->count() > 1)
            @foreach($receiptGroup as $allocation)
            <p class="mt-1 text-slate-500">{{ $allocation->invoice->number }} · Applied: Rs. {{ number_format($allocation->amount,2) }}</p>
            @endforeach
            @endif
            <x-cheque-reference :cheque="$receipt->cheque"/>
            @if(auth()->user()->canAccess('sales.receive_payments'))
                @if(!$blockedReason)
                @php($editData = [
                    'number' => $receipt->number ?: 'Legacy receipt', 'method' => $receipt->method,
                    'amount' => $snapshot['received_amount'], 'account_id' => $receipt->account_id, 'receiving_account' => $receipt->receiving_account ?? '',
                    'paid_at' => $receipt->paid_at->toDateString(), 'cheque_date' => $receipt->cheque?->cheque_date->toDateString() ?? '',
                    'cheque_number' => $receipt->cheque?->cheque_number ?? '', 'bank_name' => $receipt->cheque?->bank_name ?? '',
                    'available' => round($currentAvailable, 2), 'previous_available' => round($previousAvailable, 2),
                    'other_hold' => max(0, (float) $primaryInvoice->hold_amount - $receiptGroup->where('invoice_id', $primaryInvoice->id)->where('status', 'Hold')->sum('amount')),
                    'other_paid' => max(0, (float) $primaryInvoice->paid_amount - $receiptGroup->where('invoice_id', $primaryInvoice->id)->where('status', 'Completed')->sum('amount')),
                    'previous_hold' => max(0, (float) $eligibleInvoices->where('id', '!=', $primaryInvoice->id)->sum('hold_amount') - $receiptGroup->where('invoice_id', '!=', $primaryInvoice->id)->where('status', 'Hold')->sum('amount')),
                    'previous_paid' => max(0, (float) $eligibleInvoices->where('id', '!=', $primaryInvoice->id)->sum('paid_amount') - $receiptGroup->where('invoice_id', '!=', $primaryInvoice->id)->where('status', 'Completed')->sum('amount')),
                ])
                <button type="button" class="btn-secondary mt-2" data-edit-payment="{{ $receipt->id }}"
                    x-data="{details: @js($editData), action: @js(route('jobs.payments.update', [$job, $receipt]))}"
                    @click="$dispatch('edit-job-payment', {payment: details, action})">Edit payment</button>
                @elseif(in_array($receipt->status, ['Completed', 'Hold']) && $receipt->method !== 'Salary Deduction')
                <p class="mt-2 text-amber-700">{{ $blockedReason }}</p>
                @endif
            @endif
        </div>
        @endforeach
    </div>
</div>
@endif
