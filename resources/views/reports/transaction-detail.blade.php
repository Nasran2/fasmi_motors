<x-layouts::app title="Transaction · {{ $transaction->transaction_no }}">
<div class="mx-auto max-w-4xl space-y-5"><div><p class="text-xs font-bold uppercase text-red-600">Account transaction details</p><h1 class="page-title">{{ $transaction->transaction_no }}</h1></div>
<section class="panel p-5"><dl class="grid gap-5 sm:grid-cols-2">
@foreach(['Date' => $transaction->display_date_time, 'Account' => $transaction->account?->name,
    'Type' => $transaction->transaction_type, 'Description' => $transaction->description, 'Money in' => 'Rs. '.number_format($transaction->credit,2),
    'Money out' => 'Rs. '.number_format($transaction->debit,2), 'Method' => $transaction->payment_method, 'Note' => $transaction->note,
    'Status' => $transaction->status, 'Recorded by' => $transaction->creator?->name] as $label => $value)
    @if($value !== null && $value !== '')<div><dt class="text-xs font-bold uppercase text-slate-500">{{ $label }}</dt><dd class="mt-1 whitespace-pre-wrap font-semibold">{{ $value }}</dd></div>@endif
@endforeach
</dl></section></div>
</x-layouts::app>
