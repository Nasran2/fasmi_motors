@props(['job', 'balances', 'due'])
@if(auth()->user()->canAccess('sales.receive_payments') && $due > 0)
@php
    $bills = $balances->map(fn ($balance) => $balance + ['is_current' => false])->values();
    $invoice = $job->invoice;
    if ($invoice && $invoice->status !== 'Cancelled' && $invoice->invoice_type !== 'Quotation' && $invoice->due_amount > 0) {
        $bills->push([
            'invoice_id' => $invoice->id, 'invoice_number' => $invoice->number, 'reference' => $job->number,
            'date' => $invoice->created_at->format('d M Y'), 'amount' => (float) $invoice->due_amount,
            'paid' => (float) $invoice->paid_amount, 'hold' => (float) $invoice->hold_amount, 'status' => $invoice->status,
            'is_current' => true,
        ]);
    }
@endphp
<button type="button" class="btn-secondary mt-2 text-xs" onclick="openDuePaymentModal(this)"
    data-job-id="{{ $job->id }}" data-job-number="{{ $job->number }}" data-due="{{ $due }}" data-bills="{{ json_encode($bills) }}">Pay</button>
@endif
