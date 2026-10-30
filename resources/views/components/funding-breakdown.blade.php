@props(['record'])
@php
    $fundingEntries = \App\Models\AccountTransaction::where('reference_type', $record->getMorphClass())->where('reference_id', $record->getKey())->where('status', 'active')->where('transaction_type', '!=', 'reversal')->whereNotNull('funding_source')->where('debit', '>', 0)->get()->groupBy('funding_source');
@endphp
@if($fundingEntries->isNotEmpty())
<div class="mt-3 space-y-1 rounded-lg bg-slate-50 p-3 text-xs"><p class="font-bold">Payment sources</p>@foreach($fundingEntries as $source => $entries)<p>{{ \App\Services\OutgoingFundingService::SOURCES[$source] ?? $source }}: <b>Rs. {{ number_format($entries->sum('debit'), 2) }}</b></p>@endforeach</div>
@endif
