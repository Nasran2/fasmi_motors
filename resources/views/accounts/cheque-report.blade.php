@component('printing.document-layout', [
  'title' => 'Cheque Report', 'orientation' => 'landscape',
  'period' => $range === 'all' ? 'All cheque dates' : $from->format('d M Y').' - '.$to->format('d M Y'),
  'filters' => ($status === 'Cleared' ? 'Passed' : ($status ?: 'All statuses')).' | '.($type ?: 'All types').(request('search') ? ' | Search: '.request('search') : ''),
  'summary' => [
    ['label'=>'Total','value'=>'Rs. '.number_format($totals->total_amount,2)],
    ['label'=>'On hold','value'=>'Rs. '.number_format($totals->hold_amount,2)],
    ['label'=>'Passed','value'=>'Rs. '.number_format($totals->passed_amount,2)],
    ['label'=>'Returned','value'=>'Rs. '.number_format($totals->returned_amount,2)],
    ['label'=>'Cheque count','value'=>$totals->total_count],
  ],
])
<table class="document-table"><thead><tr><th>Cheque / Bank</th><th>Party</th><th>Type</th><th>Cheque date</th><th class="number">Amount</th><th>Status</th><th>Passed date</th><th>Reference / Reason</th></tr></thead><tbody>
@forelse($cheques as $cheque)<tr><td><b>{{ $cheque->cheque_number }}</b><small>{{ $cheque->bank_name }} · {{ $cheque->branch }}</small></td><td>{{ $cheque->party_name }}@if($cheque->forwarded_supplier_id)<small>{{ $cheque->party_name }} → {{ $cheque->forwardedSupplier?->name }} (Customer → Supplier)</small>@endif</td><td>{{ $cheque->cheque_type }}</td><td>{{ $cheque->cheque_date->format('d M Y') }}</td><td class="number">{{ number_format($cheque->amount,2) }}</td><td>{{ $cheque->trashed() ? 'Deleted' : ($cheque->status === 'Cleared' ? 'Passed' : $cheque->status) }}</td><td>{{ $cheque->realized_date?->format('d M Y') ?: '—' }}</td><td>{{ $cheque->related_reference }}<small>{{ $cheque->status_reason }}</small></td></tr>@empty<tr><td colspan="8">No cheques match these filters.</td></tr>@endforelse
</tbody></table>
@endcomponent
