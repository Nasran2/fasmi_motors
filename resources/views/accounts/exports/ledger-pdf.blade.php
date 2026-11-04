@component('printing.document-layout', [
'title' => $title,
'period' => \Carbon\Carbon::parse($from)->format('d M Y').' - '.\Carbon\Carbon::parse($to)->format('d M Y'),
'pdfMode' => true, 'orientation' => 'landscape',
'summary' => [['label'=>'Opening balance','value'=>'Rs. '.number_format($totals['opening'],2)], ['label'=>'Money in','value'=>'Rs. '.number_format($totals['credit'],2)], ['label'=>'Money out','value'=>'Rs. '.number_format($totals['debit'],2)], ['label'=>'Closing balance','value'=>'Rs. '.number_format($totals['closing'],2)]],
])
<table class="document-table">
  <thead>
    <tr>
      <th>Date &amp; Time</th>
      <th>Type</th>
      <th>Method</th>
      <th>Description</th>
      <th>Reference</th>
      <th class="number">Money In (Rs.)</th>
      <th class="number">Money Out (Rs.)</th>
      <th class="number">Balance (Rs.)</th>
    </tr>
  </thead>
  <tbody>
    @forelse($transactions as $t)
    <tr>
      <td>{{ $t->display_date_time }}</td>
      <td>{{ ucwords(str_replace('_',' ',$t->transaction_type)) }}</td>
      <td>{{ $t->payment_method ?: '-' }}</td>
      <td>{{ $t->description }}</td>
      <td>{{ $t->transaction_no }}</td>
      <td class="number">{{ number_format($t->credit,2) }}</td>
      <td class="number">{{ number_format($t->debit,2) }}</td>
      <td class="number">{{ number_format($t->running_balance,2) }}</td>
    </tr>
    @empty<tr>
      <td colspan="8">No transactions match the selected period.</td>
    </tr>@endforelse
  </tbody>
  <tbody class="document-totals">
    <tr>
      <td colspan="5">Period totals / Closing balance</td>
      <td class="number">{{ number_format($totals['credit'],2) }}</td>
      <td class="number">{{ number_format($totals['debit'],2) }}</td>
      <td class="number">{{ number_format($totals['closing'],2) }}</td>
    </tr>
  </tbody>
</table>
@endcomponent