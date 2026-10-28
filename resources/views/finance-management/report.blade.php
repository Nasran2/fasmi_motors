@component('printing.document-layout', ['title'=>ucfirst($module).' Report', 'period'=>$from.' - '.$to, 'pdfMode'=>true, 'orientation'=>'landscape'])
<table class="document-table"><thead><tr><th>Date</th><th>Number</th><th>Details</th><th>Account</th><th>Method</th><th class="number">Amount (Rs.)</th><th>Status</th></tr></thead><tbody>
@forelse($rows as $r)<tr><td>{{ $r->{$module.'_date'}->format('d M Y') }}</td><td>{{ $r->number }}</td><td>{{ $module==='expense' ? $r->category : $r->person_name }}</td><td>{{ $r->account?->name }}</td><td>{{ $r->payment_method }}</td><td class="number">{{ number_format($r->amount,2) }}</td><td>{{ $r->status }}</td></tr>
@empty<tr><td colspan="7">No records match these filters.</td></tr>@endforelse
</tbody></table>
@endcomponent
