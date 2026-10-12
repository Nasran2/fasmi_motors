<x-layouts::app title="Job Cards">
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
<div class="space-y-5">
    <div class="flex justify-between gap-3"><div><h1 class="page-title">Job Cards</h1><p class="page-subtitle">Technician assignments and workshop progress.</p></div><a href="{{ route('jobs.create') }}" class="btn-primary">+ New job card</a></div>
    <x-flash/>
    <form class="panel flex flex-wrap items-end gap-3" method="GET" action="{{ route('jobs.index') }}">
        <label class="w-full sm:w-72"><span class="label">Bike</span><select class="field" name="bike_id" aria-label="Filter by bike"><option value="">All bikes</option>@foreach($bikes as $bike)<option value="{{ $bike->id }}" @selected((string) request('bike_id') === (string) $bike->id)>{{ $bike->number }}</option>@endforeach</select></label>
        <label class="w-full sm:w-72"><span class="label">Job status</span><select class="field" name="status"><option value="">All statuses</option>@foreach($statuses as $s)<option @selected(request('status') === $s)>{{ $s }}</option>@endforeach</select></label>
        <button class="btn-secondary">Filter</button>
        @if(request()->filled('bike_id') || request()->filled('status'))<a href="{{ route('jobs.index') }}" class="btn-secondary">Clear filters</a>@endif
    </form>
    <div class="table-wrap"><table class="data-table">
        <thead><tr><th>Job</th><th>Bike / customer</th><th>Complaint</th><th>Technician</th><th>Status</th><th>Total</th><th>Due Balance</th></tr></thead>
        <tbody>
        @forelse($jobs as $j)
            @php($collectible = $j->invoice && $j->invoice->status !== 'Cancelled' && $j->invoice->invoice_type !== 'Quotation')
            @php($olderBalances = $previousBalancesByJob->get($j->id, collect()))
            @php($combinedDue = ($collectible ? (float) $j->invoice->due_amount : 0) + App\Support\PreviousCustomerBalance::total($olderBalances))
            <tr>
                <td><a href="{{ route('jobs.show', $j) }}" class="font-semibold text-emerald-600">{{ $j->number }}</a><br><span class="text-xs">{{ $j->created_at->format('d M Y') }}</span></td>
                <td class="font-semibold">{{ $j->intake->bike->number }}<br><span class="text-xs font-normal">{{ $j->intake->bike->customer->name ?: 'Walk-in' }}</span></td>
                <td class="max-w-xs truncate">{{ $j->intake->complaint }}</td>
                <td>{{ $j->technician?->name ?: 'Unassigned' }}</td>
                <td><span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $statusColors[$j->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $j->status }}</span></td>
                <td class="whitespace-nowrap">Rs. {{ number_format($j->total, 2) }}@if($j->invoice)<span class="mt-1 block text-xs font-semibold {{ $j->invoice->status === 'Paid' ? 'text-emerald-600' : 'text-slate-500' }}">@if($olderBalances->isNotEmpty())Current bill: @endif{{ $j->invoice->status === 'Paid' ? 'Completed' : $j->invoice->status }}</span>@endif</td>
                <td class="whitespace-nowrap">
                    @if($combinedDue > 0)
                        <span class="block font-bold text-red-600">Rs. {{ number_format($combinedDue, 2) }}</span>
                        @if($collectible && $j->invoice->due_amount > 0)
                        @if($olderBalances->isNotEmpty())<span class="mt-2 block text-xs text-slate-500">Current bill: Rs. {{ number_format($j->invoice->due_amount, 2) }}</span>@endif
                        <x-due-payment-button :invoice-id="$j->invoice->id" :due="$j->invoice->due_amount" :number="$j->invoice->number" :paid="$j->invoice->paid_amount" :hold="$j->invoice->hold_amount" class="mt-2"/>
                        @endif
                        @foreach($olderBalances as $balance)
                        <div class="mt-2 border-t border-slate-100 pt-2 text-xs">
                            <span class="block text-slate-500">Previous balance · {{ $balance['reference'] }}</span>
                            <span class="block text-slate-500">{{ $balance['date'] }} · Rs. {{ number_format($balance['amount'], 2) }}</span>
                            <x-due-payment-button :invoice-id="$balance['invoice_id']" :due="$balance['amount']" :number="$balance['invoice_number']" :paid="$balance['paid']" :hold="$balance['hold']" class="mt-1"/>
                        </div>
                        @endforeach
                    @else<span class="text-slate-400">—</span>@endif
                </td>
            </tr>
        @empty<tr><td colspan="7" class="text-center">No jobs found.</td></tr>@endforelse
        </tbody>
    </table></div>
    {{ $jobs->links() }}
</div>
<x-job-due-payment-dialog :accounts="$accounts"/>
</x-layouts::app>
