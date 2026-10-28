@props(['job' => null, 'invoice' => null])
@php
    $invoice = $invoice ?? $job?->invoice;
    $date = $invoice?->bill_date ?? $job?->bill_date ?? today();
    $editable = auth()->user()->canAccess('job_card.edit') && ($job?->status !== 'Cancelled') && !in_array($invoice?->status, ['Cancelled', 'Converted']) && !$invoice?->isOpeningDue();
@endphp
@if($editable)
<details class="panel" @if($errors->hasAny(['bill_date', 'reason'])) open @endif>
    <summary class="cursor-pointer font-bold text-blue-700">Edit bill date</summary>
    <p class="mt-2 text-sm text-slate-500">Correct this bill’s date. Payment dates are edited separately in payment history.</p>
    <form method="POST" action="{{ $job ? route('jobs.bill-details', $job) : route('sales.invoices.details', $invoice) }}" class="mt-4 grid gap-4 sm:grid-cols-2">
        @csrf<x-submission-token/>@method('PUT')
        <label><span class="label">Bill date</span><input type="date" name="bill_date" value="{{ old('bill_date', $date->toDateString()) }}" min="1900-01-01" max="{{ today()->toDateString() }}" class="field" required></label>
        <label><span class="label">Reason for correction</span><input name="reason" value="{{ old('reason') }}" minlength="5" maxlength="500" class="field" required placeholder="Explain the date correction"></label>
        <div><button class="btn-secondary">Save bill details</button></div>
    </form>
</details>
@endif
