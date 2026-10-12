@props(['invoiceId', 'due', 'number', 'paid' => 0, 'hold' => 0, 'label' => 'Pay'])
@if(auth()->user()->canAccess('sales.receive_payments') && $due > 0)
<button type="button" {{ $attributes->class(['btn-secondary text-xs']) }} onclick="openDuePaymentModal(this)"
    data-invoice-id="{{ $invoiceId }}" data-due="{{ $due }}" data-invoice-number="{{ $number }}" data-paid="{{ $paid }}" data-hold="{{ $hold }}">{{ $label }}</button>
@endif
