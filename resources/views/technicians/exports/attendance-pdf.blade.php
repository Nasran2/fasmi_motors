@component('printing.document-layout', [
  'title' => 'Staff Attendance Report',
  'period' => $from->format('d M Y') . ' - ' . $to->format('d M Y'),
  'pdfMode' => true,
  'orientation' => 'landscape',
  'compact' => true,
  'filters' => implode(' | ', array_filter([
    $technician ? 'Staff: ' . $technician->name : null,
    $status ? 'Status: ' . $status : null,
  ])),
  'summary' => [
    ['label' => 'Total Records', 'value' => $records->count()],
    ['label' => 'Present', 'value' => $records->whereIn('status', ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave', 'Auto Closed', 'Worked on Leave Day'])->count()],
    ['label' => 'Absent / Leave', 'value' => $records->whereIn('status', ['Absent', 'No Pay Leave', 'Leave', 'Paid Leave'])->count()],
    ['label' => 'Total OT Hours', 'value' => number_format((float) $records->sum('ot_hours'), 2) . ' hrs'],
    ['label' => 'Total OT Amount', 'value' => 'Rs. ' . number_format((float) $records->sum('ot_amount'), 2)],
    ['label' => 'Total Deductions', 'value' => 'Rs. ' . number_format((float) $records->sum('deduction_amount'), 2)],
  ],
])
<table class="document-table">
  <thead>
    <tr>
      <th style="width: 9%;">Date</th>
      <th style="width: 5%;">Day</th>
      <th style="width: 14%;">Technician</th>
      <th style="width: 9%;">In Time</th>
      <th style="width: 12%;">Out Time</th>
      <th class="number" style="width: 6%;">Hours</th>
      <th class="number" style="width: 5%;">Late</th>
      <th class="number" style="width: 5%;">Early</th>
      <th class="number" style="width: 9%;">OT Amount</th>
      <th class="number" style="width: 9%;">Deduction</th>
      <th style="width: 17%;">Status</th>
    </tr>
  </thead>
  <tbody>
    @forelse($records as $row)
    <tr>
      <td style="white-space: nowrap;">{{ $row->date?->format('d M Y') }}</td>
      <td>{{ $row->date?->format('D') }}</td>
      <td class="strong" style="white-space: nowrap;">{{ $row->technician?->name ?? '—' }}</td>
      <td style="white-space: nowrap;">{{ $row->in_time ? \Carbon\Carbon::parse($row->in_time)->format('h:i A') : '—' }}</td>
      <td style="white-space: nowrap;">
        {{ $row->out_time ? \Carbon\Carbon::parse($row->out_time)->format('h:i A') : '—' }}
        @if($row->is_auto_checkout) <small style="font-size: 5.5pt; color: #64748b;">(Auto)</small> @endif
      </td>
      <td class="number">{{ number_format((float) $row->working_hours, 2) }}</td>
      <td class="number">{{ (int) $row->late_minutes > 0 ? $row->late_minutes . 'm' : '—' }}</td>
      <td class="number">{{ (int) $row->early_leave_minutes > 0 ? $row->early_leave_minutes . 'm' : '—' }}</td>
      <td class="number">{{ (float) $row->ot_amount > 0 ? number_format((float) $row->ot_amount, 2) : '—' }}</td>
      <td class="number">{{ (float) $row->deduction_amount > 0 ? number_format((float) $row->deduction_amount, 2) : '—' }}</td>
      <td style="white-space: nowrap;">{{ $row->status }}</td>
    </tr>
    @empty
    <tr>
      <td colspan="11" style="text-align: center; padding: 12px 0; color: #64748b;">No attendance records found for this period.</td>
    </tr>
    @endforelse
  </tbody>
  <tbody class="document-totals">
    <tr>
      <td colspan="5" style="white-space: nowrap;">Period Totals</td>
      <td class="number">{{ number_format((float) $records->sum('working_hours'), 2) }}</td>
      <td class="number">{{ $records->sum('late_minutes') }}m</td>
      <td class="number">{{ $records->sum('early_leave_minutes') }}m</td>
      <td class="number">Rs. {{ number_format((float) $records->sum('ot_amount'), 2) }}</td>
      <td class="number">Rs. {{ number_format((float) $records->sum('deduction_amount'), 2) }}</td>
      <td></td>
    </tr>
  </tbody>
</table>
@endcomponent

