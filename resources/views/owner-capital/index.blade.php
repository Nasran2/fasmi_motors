<x-layouts::app title="Owner Capital">
<div class="mx-auto max-w-7xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-xs font-bold uppercase tracking-wider text-red-600">Expenses &amp; Drawings</p><h1 class="page-title">Owner Capital</h1><p class="page-subtitle">Money the owner adds to the business.</p></div>
        @if(auth()->user()->canAccess('capital.add'))<a href="{{ route('owner-capital.create') }}" class="btn-primary">+ Add Owner Capital</a>@endif
    </header>
    @if(session('success'))<p class="rounded-xl bg-emerald-50 p-4 text-emerald-800">{{ session('success') }}</p>@endif
    @if($errors->any())<p class="rounded-xl bg-red-50 p-4 text-red-700">{{ $errors->first() }}</p>@endif
    <section class="panel p-5"><p class="text-sm font-bold text-slate-500">Capital added · selected filters · excludes deleted entries</p><p class="mt-1 text-2xl font-black text-emerald-700">Rs. {{ number_format($total, 2) }}</p><p class="mt-2 text-sm text-slate-600">Spare Parts Account is the default. You can choose Main Account or Bank Account. Capital is separate from sales and cashier collections.</p></section>
    <form method="GET" class="panel grid gap-3 p-5 md:grid-cols-3">
        <label><span class="label">From date</span><input type="date" name="from" value="{{ request('from') }}" class="field"></label>
        <label><span class="label">To date</span><input type="date" name="to" value="{{ request('to') }}" class="field"></label>
        <label><span class="label">Search</span><input name="q" value="{{ request('q') }}" maxlength="150" placeholder="Owner, number, reference or note" class="field"></label>
        <label><span class="label">Account</span><select name="account_id" class="field"><option value="">All accounts</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string) request('account_id') === (string) $account->id)>{{ $account->name }}</option>@endforeach</select></label>
        <label><span class="label">Status</span><select name="status" class="field">@foreach(['active' => 'Active entries', 'deleted' => 'Deleted entries', 'all' => 'All entries'] as $value => $label)<option value="{{ $value }}" @selected(request('status', 'active') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="flex items-end gap-2"><button class="btn-secondary">Apply filters</button><a href="{{ route('owner-capital.index') }}" class="btn-secondary">Reset</a></div>
    </form>
    <section class="panel overflow-hidden"><div class="overflow-x-auto"><table class="w-full text-left text-sm">
        <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="p-4">Date / Number</th><th class="p-4">Owner</th><th class="p-4">Account</th><th class="p-4">Method / Reference</th><th class="p-4 text-right">Amount</th><th class="p-4">Status</th><th class="p-4">Actions</th></tr></thead>
        <tbody>@forelse($records as $record)<tr class="border-t border-slate-100">
            <td class="p-4 whitespace-nowrap">{{ $record->capital_date->format('d M Y') }}<a href="{{ route('owner-capital.show', $record) }}" class="block font-bold text-blue-700">{{ $record->number }}</a></td>
            <td class="p-4">{{ $record->owner_name }}</td><td class="p-4">{{ $record->account?->name }}</td><td class="p-4">{{ $record->payment_method }}<span class="block text-xs text-slate-500">{{ $record->reference_number }}</span></td>
            <td class="p-4 text-right font-bold whitespace-nowrap">Rs. {{ number_format($record->amount, 2) }}</td><td class="p-4 {{ $record->trashed() ? 'text-red-700' : 'text-emerald-700' }}">{{ $record->trashed() ? 'Deleted' : 'Active' }}</td>
            <td class="p-4"><div class="flex gap-3"><a class="font-bold text-blue-700" href="{{ route('owner-capital.show', $record) }}">View</a>@if(!$record->trashed() && auth()->user()->canAccess('capital.edit'))<a class="font-bold text-blue-700" href="{{ route('owner-capital.edit', $record) }}">Edit</a>@endif @if(!$record->trashed() && auth()->user()->canAccess('capital.delete'))<a class="font-bold text-red-700" href="{{ route('owner-capital.show', $record) }}#delete-capital">Delete</a>@endif</div></td>
        </tr>@empty<tr><td colspan="7" class="p-10 text-center text-slate-500">No owner capital entries match these filters.</td></tr>@endforelse</tbody>
    </table></div><div class="p-4">{{ $records->links() }}</div></section>
</div>
</x-layouts::app>
