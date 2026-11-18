<x-layouts::app title="Extra Money">
<div class="mx-auto max-w-7xl space-y-5">
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-xs font-bold uppercase tracking-wider text-red-600">Expenses &amp; Drawings</p>
            <h1 class="page-title">Extra Money (Income)</h1>
            <p class="page-subtitle">Record extra money received as business income into Main Account, Spare Parts Account, Spare Parts Cashier, or Bank Account.</p>
        </div>
        @if(auth()->user()->canAccess('extra_income.add'))
            <a href="{{ route('extra-money.create') }}" class="btn-primary">+ Add Extra Money</a>
        @endif
    </header>

    @if(session('success'))
        <p class="rounded-xl bg-emerald-50 p-4 text-emerald-800" role="status">{{ session('success') }}</p>
    @endif
    @if($errors->any())
        <p class="rounded-xl bg-red-50 p-4 text-red-700" role="alert">{{ $errors->first() }}</p>
    @endif

    <section class="panel grid gap-4 p-5 sm:grid-cols-2">
        <div>
            <p class="text-sm font-bold text-slate-500">Cleared / Active extra money income · selected filters</p>
            <p class="mt-1 text-2xl font-black text-emerald-700">Rs. {{ number_format($total, 2) }}</p>
            <p class="mt-2 text-sm text-slate-600">Every entry is saved as <strong>Income</strong> (not expense) in the selected account ledger and Profit &amp; Loss report. Spare Parts Cashier is only available when the cashier is open.</p>
        </div>
        <div class="sm:border-l sm:border-slate-100 sm:pl-5">
            <p class="text-sm font-bold text-slate-500">Cheque Hold (Pending / Deposited) · selected filters</p>
            <p class="mt-1 text-2xl font-black text-amber-700">Rs. {{ number_format($holdTotal, 2) }}</p>
            <p class="mt-2 text-sm text-slate-600">Held cheques are tracked in <strong>Cheque Management</strong> and automatically credit Bank Account income once marked <strong>Passed</strong>.</p>
        </div>
    </section>

    <search>
        <form method="GET" action="{{ route('extra-money.index') }}" class="panel grid gap-3 p-5 md:grid-cols-3">
            <div>
                <label for="filter-from" class="label">From date</label>
                <input id="filter-from" type="date" name="from" value="{{ request('from') }}" class="field">
            </div>
            <div>
                <label for="filter-to" class="label">To date</label>
                <input id="filter-to" type="date" name="to" value="{{ request('to') }}" class="field">
            </div>
            <div>
                <label for="filter-q" class="label">Search</label>
                <input id="filter-q" type="search" name="q" value="{{ request('q') }}" maxlength="150" placeholder="Title, source, cheque no, bank, reference or note" class="field">
            </div>
            <div>
                <label for="filter-destination" class="label">Account</label>
                <select id="filter-destination" name="destination_account" class="field">
                    <option value="">All accounts</option>
                    @foreach($destinations as $value => $label)
                        <option value="{{ $value }}" @selected(request('destination_account') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="filter-status" class="label">Status</label>
                <select id="filter-status" name="status" class="field">
                    @foreach(['active' => 'Active entries', 'deleted' => 'Deleted entries', 'all' => 'All entries'] as $value => $label)
                        <option value="{{ $value }}" @selected(request('status', 'active') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-end gap-2">
                <button type="submit" class="btn-secondary">Apply filters</button>
                <a href="{{ route('extra-money.index') }}" class="btn-secondary">Reset</a>
            </div>
        </form>
    </search>

    <section class="panel overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-4">Date / Number</th>
                        <th class="p-4">Description / Source</th>
                        <th class="p-4">Account</th>
                        <th class="p-4">Method / Details</th>
                        <th class="p-4 text-right">Income Amount</th>
                        <th class="p-4">Status</th>
                        <th class="p-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($records as $record)
                        @php
                            $canManageChequeEdit = ! $record->cheque_id || ($record->cheque && ! $record->cheque->trashed() && in_array($record->cheque->status, ['Pending', 'Deposited'], true));
                        @endphp
                        <tr class="border-t border-slate-100">
                            <td class="p-4 whitespace-nowrap">
                                {{ $record->income_date->format('d M Y') }}
                                <a href="{{ route('extra-money.show', $record) }}" class="block font-bold text-blue-700">{{ $record->number }}</a>
                            </td>
                            <td class="p-4">
                                <span class="font-semibold text-slate-900">{{ $record->title }}</span>
                                @if($record->source_name)
                                    <span class="block text-xs text-slate-500">From: {{ $record->source_name }}</span>
                                @endif
                            </td>
                            <td class="p-4">
                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">{{ $record->destination_label }}</span>
                            </td>
                            <td class="p-4">
                                {{ $record->payment_method }}
                                @if($record->cheque)
                                    <x-cheque-reference :cheque="$record->cheque" />
                                    <small class="block text-slate-500">{{ $record->cheque->bank_name }}{{ $record->cheque->branch ? ' · '.$record->cheque->branch : '' }}</small>
                                @elseif($record->reference_number)
                                    <span class="block text-xs text-slate-500">{{ $record->reference_number }}</span>
                                @endif
                            </td>
                            <td class="p-4 text-right font-bold whitespace-nowrap {{ $record->status === 'Hold' ? 'text-amber-700' : ($record->status === 'Completed' && !$record->trashed() ? 'text-emerald-700' : 'text-slate-400 line-through') }}">+ Rs. {{ number_format($record->amount, 2) }}</td>
                            <td class="p-4">
                                @if($record->trashed())
                                    <span class="font-semibold text-red-700">Deleted</span>
                                @elseif($record->status === 'Hold')
                                    <span class="inline-block rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-bold text-amber-700">Cheque Hold</span>
                                @elseif(in_array($record->status, ['Returned', 'Cancelled'], true))
                                    <span class="inline-block rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-bold text-red-700">Cheque {{ $record->status }}</span>
                                @else
                                    <span class="inline-block rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-bold text-emerald-700">Income · Active</span>
                                @endif
                            </td>
                            <td class="p-4">
                                <div class="flex flex-wrap gap-3">
                                    <a class="font-bold text-blue-700" href="{{ route('extra-money.show', $record) }}">View</a>
                                    @if(!$record->trashed() && $canManageChequeEdit && auth()->user()->canAccess('extra_income.edit'))
                                        <a class="font-bold text-blue-700" href="{{ route('extra-money.edit', $record) }}">Edit</a>
                                    @endif
                                    @if($record->cheque && auth()->user()->canAccess('account.cheques'))
                                        <a class="font-bold text-amber-700" href="{{ route('accounts.cheques.show', $record->cheque) }}">Cheque</a>
                                    @endif
                                    @if(!$record->trashed() && auth()->user()->canAccess('extra_income.delete'))
                                        <a class="font-bold text-red-700" href="{{ route('extra-money.show', $record) }}#delete-extra-money">Delete</a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="p-10 text-center text-slate-500">No extra money entries match these filters.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $records->links() }}</div>
    </section>
</div>
</x-layouts::app>
