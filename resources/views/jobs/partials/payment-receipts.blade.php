@if($paymentInvoices->contains(fn ($invoice) => $invoice->payments->isNotEmpty()))
<div class="panel" id="job-payment-receipts">
    <h2 class="mb-3 font-bold">Payments received</h2>
    <p class="mb-3 text-xs text-slate-500">Correct the method or amount with a reason. The original receipt and its replacement remain in the change history.</p>
    <div class="space-y-3">
        @foreach($paymentInvoices as $paymentInvoice)
        @foreach($paymentInvoice->payments as $receipt)
        <div class="rounded-xl border border-slate-200 p-3 text-xs">
            <div class="flex justify-between gap-3"><span class="font-semibold">{{ $receipt->number ?: 'Legacy receipt' }}</span><span class="font-bold">Rs. {{ number_format($receipt->received_amount ?? $receipt->amount, 2) }}</span></div>
            <p class="mt-1 text-slate-500">{{ $paymentInvoice->number }} · {{ $receipt->paid_at->format('d M Y') }}</p>
            <p class="mt-1">{{ $receipt->method }} · {{ $receipt->status }} · {{ $receipt->account?->name }}</p>
            @if($receipt->change_amount > 0)<p class="mt-1 text-violet-600">Applied: Rs. {{ number_format($receipt->amount,2) }} · Change: Rs. {{ number_format($receipt->change_amount,2) }}</p>@endif
            <x-cheque-reference :cheque="$receipt->cheque"/>
            @if(auth()->user()->canAccess('sales.receive_payments'))
                @if(!$receipt->correction_block_reason)
                @php($editData = [
                    'number' => $receipt->number ?: 'Legacy receipt', 'method' => $receipt->method,
                    'amount' => (float) ($receipt->received_amount ?? $receipt->amount), 'account_id' => $receipt->account_id,
                    'paid_at' => $receipt->paid_at->toDateString(), 'cheque_date' => $receipt->cheque?->cheque_date->toDateString() ?? '',
                    'cheque_number' => $receipt->cheque?->cheque_number ?? '', 'bank_name' => $receipt->cheque?->bank_name ?? '',
                    'available' => round((float) $paymentInvoice->due_amount + (float) $receipt->amount, 2),
                    'other_hold' => max(0, (float) $paymentInvoice->hold_amount - ($receipt->status === 'Hold' ? (float) $receipt->amount : 0)),
                    'other_paid' => max(0, (float) $paymentInvoice->paid_amount - ($receipt->status === 'Completed' ? (float) $receipt->amount : 0)),
                ])
                <button type="button" class="btn-secondary mt-2" data-edit-payment="{{ $receipt->id }}"
                    x-data="{details: @js($editData), action: @js(route('jobs.payments.update', [$job, $receipt]))}"
                    @click="$dispatch('edit-job-payment', {payment: details, action})">Edit payment</button>
                @elseif(in_array($receipt->status, ['Completed', 'Hold']))
                <p class="mt-2 text-amber-700">{{ $receipt->correction_block_reason }}</p>
                @endif
            @endif
        </div>
        @endforeach
        @endforeach
    </div>
</div>
@endif
