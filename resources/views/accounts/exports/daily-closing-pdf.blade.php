@component('printing.document-layout', ['title'=>'Daily Closing Report', 'period'=>\Carbon\Carbon::parse($date)->format('d F Y'), 'pdfMode'=>true, 'orientation'=>'landscape'])
<table class="document-table"><thead><tr><th>Account</th><th class="number">Opening</th><th class="number">Money In</th><th class="number">Money Out</th><th class="number">Expected</th><th class="number">Actual</th><th class="number">Difference</th></tr></thead><tbody>
@foreach($rows as $row)<tr>@foreach($row as $index=>$value)<td @class(['number'=>$index!==0])>{{ $index===0 ? $value : ($value === null ? 'Not recorded' : number_format((float)$value,2)) }}</td>@endforeach</tr>@endforeach
</tbody></table>
<p class="document-note"><strong>Status:</strong> {{ $closing ? ucfirst($closing->status) : 'Open day - preview report' }}<br><strong>Closing note:</strong> {{ $closing?->notes ?: '-' }}<br><strong>Difference reason:</strong> {{ $closing?->difference_reason ?: '-' }}</p>
@endcomponent
