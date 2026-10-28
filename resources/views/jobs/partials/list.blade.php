@php
  $statusColors = [
      'Received'          => 'bg-slate-100 text-slate-700 border border-slate-200',
      'Assigned'          => 'bg-blue-100 text-blue-700 border border-blue-200',
      'In Progress'       => 'bg-cyan-100 text-cyan-700 border border-cyan-200',
      'Waiting for Parts' => 'bg-orange-100 text-orange-700 border border-orange-200',
      'On Hold'           => 'bg-amber-100 text-amber-700 border border-amber-200',
      'Repair Ready'      => 'bg-emerald-100 text-emerald-700 border border-emerald-200',
      'Completed'         => 'bg-green-100 text-green-700 border border-green-200',
      'Billed'            => 'bg-purple-100 text-purple-700 border border-purple-200',
      'Delivered'         => 'bg-teal-100 text-teal-700 border border-teal-200',
      'Cancelled'         => 'bg-rose-100 text-rose-700 border border-rose-200',
  ];
@endphp
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Job</th><th>Bike / customer</th><th>Complaint</th><th>Technician</th><th>Status</th><th>Total</th><th>Due Balance</th></tr></thead>
        <tbody>
        @forelse($jobs as $j)
            @php($collectible = $j->invoice && $j->invoice->status !== 'Cancelled' && $j->invoice->invoice_type !== 'Quotation')
            @php($olderBalances = $previousBalancesByJob->get($j->id, collect()))
            @php($combinedDue = ($collectible ? (float) $j->invoice->due_amount : 0) + App\Support\PreviousCustomerBalance::total($olderBalances))
            <tr>
                <td><a href="{{ route('jobs.show', $j) }}" class="font-semibold text-emerald-600">{{ $j->number }}</a><br><span class="text-xs">{{ $j->created_at->format('d M Y') }}
                    @if($j->is_quick_job) · Quick Job @endif
                </span></td>
                <td class="font-semibold">{{ $j->bike?->number ?: 'Not recorded' }}<br><span class="text-xs font-normal">{{ $j->customer?->name ?: 'Walk-in' }}</span></td>
                <td class="max-w-xs truncate">{{ $j->complaint }}</td>
                <td>{{ $j->technician?->name ?: 'Unassigned' }}</td>
                <td><span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $statusColors[$j->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $j->status }}</span></td>
                <td class="whitespace-nowrap">Rs. {{ number_format($j->total, 2) }}@if($j->invoice)<span class="mt-1 block text-xs font-semibold {{ $j->invoice->status === 'Paid' ? 'text-emerald-600' : 'text-slate-500' }}">@if($olderBalances->isNotEmpty())Current bill: @endif{{ $j->invoice->status === 'Paid' ? 'Completed' : $j->invoice->status }}</span>@endif</td>
                <td class="whitespace-nowrap">
                    @if($combinedDue > 0)
                        <span class="block font-bold text-red-600">Rs. {{ number_format($combinedDue, 2) }}</span>
                        <x-job-balances-payment-button :job="$j" :balances="$olderBalances" :due="$combinedDue"/>
                    @else<span class="text-slate-400">—</span>@endif
                </td>
            </tr>
        @empty<tr><td colspan="7" class="text-center">No jobs found.</td></tr>@endforelse
        </tbody>
    </table></div>
    <div data-job-pagination>{{ $jobs->links() }}</div>
