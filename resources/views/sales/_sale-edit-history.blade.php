@php($adjustments = ($saleEdits ?? collect())->filter(fn ($entry) => !empty($entry->new_values['removed']) || ($entry->new_values['refund'] ?? 0) > 0))
@if($adjustments->isNotEmpty())
<section class="panel space-y-4">
    <h2 class="font-extrabold">Sale Edit Adjustments</h2>
    @foreach($adjustments as $entry)
        @php($change = $entry->new_values)
        <article class="rounded-xl border border-slate-200 bg-slate-50 p-4">
            <div class="flex flex-wrap justify-between gap-2"><b>Sale correction</b><small>{{ $entry->created_at->format('d M Y, h:i A') }}</small></div>
            <p class="mt-2 text-sm">{{ $change['reason'] }}</p>
            @foreach($change['removed'] ?? [] as $line)
                <p class="mt-2 text-sm">{{ $line['name'] }} × {{ $line['quantity'] }} — {{ $line['disposition'] === 'expense' ? 'Not restocked; cost recorded as expense: Rs. '.number_format($line['cost'],2) : 'Returned to stock' }}</p>
            @endforeach
            <p class="mt-3 text-sm font-bold">Refund to customer: Rs. {{ number_format($change['customer_refund'] ?? 0,2) }} · Applied to customer dues: Rs. {{ number_format($change['due_credit'] ?? 0,2) }}</p>
            @foreach($change['due_allocations'] ?? [] as $allocation)
                <p class="mt-1 text-sm"><a class="font-bold text-red-600" href="{{ route('sales.invoices.show', $allocation['invoice_id']) }}">{{ $allocation['invoice_number'] }}</a>: Rs. {{ number_format($allocation['amount'],2) }} deducted from due</p>
            @endforeach
        </article>
    @endforeach
</section>
@endif
