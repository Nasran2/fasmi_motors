<x-layouts::app :title="$title">
<div class="mx-auto max-w-[1650px] space-y-5 p-4 sm:p-6 lg:p-8">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <div><p class="text-xs font-bold uppercase tracking-widest text-red-500">Cheque Management</p><h1 class="mt-1 text-3xl font-black">{{ $title }}</h1><p class="mt-1 text-sm text-slate-500">Track customer, supplier and party cheques from hold to settlement.</p></div>
        <div class="flex gap-2"><a href="{{ route('accounts.cheques.reports') }}" class="btn-secondary">Reports</a><a href="{{ route('accounts.cheques.create') }}" class="btn-primary">+ Add Cheque</a></div>
    </header>
    @if(session('success'))<div class="rounded-xl bg-emerald-50 p-3 text-sm font-bold text-emerald-700">{{ session('success') }}</div>@endif
    @if($errors->any())<div class="rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>@endif
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
        @foreach(['total_amount' => ['Total', 'text-slate-900'], 'hold_amount' => ['On Hold', 'text-amber-600'], 'passed_amount' => ['Passed', 'text-emerald-600'], 'returned_amount' => ['Returned', 'text-red-600']] as $key => [$label, $color])
        <div class="panel !p-4"><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-xl font-black {{ $color }}">Rs. {{ number_format($totals->$key, 2) }}</p></div>
        @endforeach
        <div class="panel !p-4"><p class="text-xs font-bold text-slate-500">Cheques in this view</p><p class="mt-2 text-xl font-black">{{ $totals->total_count }}</p></div>
    </div>
    <section class="panel space-y-4">
        <nav class="flex gap-2 overflow-x-auto pb-1" aria-label="Cheque status">
            @foreach(['index' => 'All', 'pending' => 'Pending / Hold', 'passed' => 'Passed', 'returned' => 'Returned', 'cancelled' => 'Cancelled', 'deleted' => 'Deleted'] as $key => $label)
                <a class="whitespace-nowrap rounded-xl px-4 py-2 text-sm font-bold {{ request()->routeIs('accounts.cheques.'.$key) ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600' }}" href="{{ route('accounts.cheques.'.$key) }}">{{ $label }}</a>
            @endforeach
        </nav>
        <form method="GET" x-data="{range: @js($range)}" class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <label class="text-sm font-bold">Search<input name="search" value="{{ request('search') }}" placeholder="Cheque, party, bank or reference" class="field mt-1"></label>
            <label class="text-sm font-bold">Cheque type<select name="type" class="field mt-1" @disabled(request()->route('cheque_type'))>@foreach(['' => 'All types', 'Received' => 'Customer / Received', 'Issued' => 'Supplier / Issued', 'Forwarded' => 'Party / Forwarded'] as $value => $label)<option value="{{ $value }}" @selected($type === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-bold">Status<select name="status" class="field mt-1" @disabled(request()->route('cheque_status'))>@foreach(['' => 'All statuses', 'Pending' => 'Pending / Hold', 'Deposited' => 'Deposited', 'Cleared' => 'Passed', 'Returned' => 'Returned', 'Cancelled' => 'Cancelled', 'Deleted' => 'Deleted'] as $value => $label)<option value="{{ $value }}" @selected($status === $value)>{{ $label }}</option>@endforeach</select></label>
            <label class="text-sm font-bold">Cheque date<select name="date_range" x-model="range" class="field mt-1">@foreach(['all' => 'All dates', 'today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month', 'this_year' => 'This year', 'custom' => 'Custom dates'] as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
            <div class="flex gap-2"><button class="btn-primary">Apply</button><a href="{{ url()->current() }}" class="btn-secondary">Reset</a></div>
            <label x-show="range === 'custom'" x-cloak class="text-sm font-bold">From<input name="start_date" type="date" value="{{ request('start_date') }}" :required="range === 'custom'" class="field mt-1"></label>
            <label x-show="range === 'custom'" x-cloak class="text-sm font-bold">To<input name="end_date" type="date" value="{{ request('end_date') }}" :required="range === 'custom'" class="field mt-1"></label>
        </form>
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-3">
            <p class="text-xs text-slate-500">Totals reflect the filters above. Pending and deposited cheques stay on hold until passed.</p>
            <div class="flex gap-2"><a href="{{ route('accounts.cheques.print', array_merge(request()->query(), ['status' => $status, 'type' => $type])) }}" target="_blank" class="btn-secondary !text-xs">Print Report</a>@if(auth()->user()->canAccess('reports.export_excel'))<a class="btn-secondary !text-xs" href="{{ route('accounts.cheques.export', array_merge(request()->query(), ['status' => $status, 'type' => $type])) }}">Export CSV</a>@endif</div>
        </div>
    </section>
    <section class="panel !p-0 overflow-hidden">
        <div class="overflow-x-auto"><table class="w-full min-w-[900px] text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500"><tr><th class="p-4">Cheque / Bank</th><th class="p-4">Customer / Supplier</th><th class="p-4">Cheque date</th><th class="p-4 text-right">Amount</th><th class="p-4">Status</th><th class="p-4">Actions</th></tr></thead>
            <tbody class="divide-y divide-slate-100">@forelse($cheques as $c)
                <tr class="hover:bg-slate-50/70">
                    <td class="p-4"><a class="font-bold text-slate-900 hover:text-red-500" href="{{ route('accounts.cheques.show', $c) }}">{{ $c->cheque_number }}</a><div class="mt-1 text-xs text-slate-500">{{ $c->bank_name }} {{ $c->branch ? '· '.$c->branch : '' }}</div></td>
                    <td class="p-4"><div class="font-semibold">{{ $c->party_name }}</div><div class="mt-1 text-xs text-slate-500">{{ $c->cheque_type === 'Received' ? 'Customer · Received' : 'Supplier · Issued' }} @if($c->related_reference) · {{ $c->related_reference }} @endif</div>@if($c->forwarded_supplier_id)<small class="mt-1 block font-bold text-violet-600">{{ $c->party_name }} → {{ $c->forwardedSupplier?->name }} (Customer → Supplier)</small>@endif</td>
                    <td class="p-4 whitespace-nowrap">{{ $c->cheque_date->format('d M Y') }}@if(in_array($c->status, ['Pending','Deposited']) && $c->cheque_date->isPast() && !$c->cheque_date->isToday())<small class="block text-red-500">Overdue for review</small>@endif</td>
                    <td class="p-4 text-right font-bold whitespace-nowrap">Rs. {{ number_format($c->amount, 2) }}</td>
                    <td class="p-4"><x-cheque-status :cheque="$c"/></td>
                    <td class="p-4"><div class="flex gap-2"><a class="btn-secondary !px-3 !py-2 !text-xs" href="{{ route('accounts.cheques.show', $c) }}">View</a>@unless($c->trashed())<a class="btn-secondary !px-3 !py-2 !text-xs" href="{{ route('accounts.cheques.edit', $c) }}">Edit</a>@endunless</div></td>
                </tr>
            @empty<tr><td colspan="6" class="p-12 text-center"><p class="font-bold text-slate-600">No cheques found</p><p class="mt-1 text-sm text-slate-400">Change the filters or add a cheque to get started.</p></td></tr>@endforelse</tbody>
        </table></div>
        <div class="p-4">{{ $cheques->links() }}</div>
    </section>
</div>
</x-layouts::app>
