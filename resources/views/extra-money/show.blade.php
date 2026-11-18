<x-layouts::app :title="'Extra Money · '.$extraIncome->number">
@php
    $cheque = $extraIncome->cheque;
    $chequeSettled = $cheque && ($cheque->trashed() || ! in_array($cheque->status, ['Pending', 'Deposited'], true) || $cheque->forwarded_supplier_id);
    $isDeleted = $extraIncome->trashed();
    $isHold = ! $isDeleted && $extraIncome->status === 'Hold';
    $isRejected = ! $isDeleted && in_array($extraIncome->status, ['Returned', 'Cancelled'], true);
    $statusLabel = $isDeleted
        ? 'Deleted · Reversed'
        : ($isHold ? 'Cheque Hold' : ($isRejected ? 'Cheque '.$extraIncome->status : 'Income · Active'));
    $statusBadgeClass = $isDeleted || $isRejected
        ? 'bg-rose-500/15 text-rose-200 ring-1 ring-rose-400/30'
        : ($isHold ? 'bg-amber-400/20 text-amber-200 ring-1 ring-amber-300/40' : 'bg-emerald-400/20 text-emerald-200 ring-1 ring-emerald-300/40');
    $headerPillClass = $isDeleted || $isRejected
        ? 'bg-rose-50 text-rose-700 ring-1 ring-rose-200'
        : ($isHold ? 'bg-amber-50 text-amber-800 ring-1 ring-amber-200' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200');
@endphp
<div class="mx-auto max-w-6xl space-y-6">
    {{-- Page Header --}}
    <header class="flex flex-wrap items-center justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 text-xs font-bold uppercase tracking-wider text-red-600">
                <span>Expenses &amp; Drawings</span>
                <span class="text-slate-300" aria-hidden="true">/</span>
                <span>Extra Money (Income)</span>
            </div>
            <div class="mt-1.5 flex flex-wrap items-center gap-3">
                <h1 class="page-title tracking-tight">{{ $extraIncome->number }}</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold {{ $headerPillClass }}">
                    <span class="size-1.5 rounded-full {{ $isDeleted || $isRejected ? 'bg-rose-500' : ($isHold ? 'bg-amber-500' : 'bg-emerald-500') }}" aria-hidden="true"></span>
                    {{ $statusLabel }}
                </span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('extra-money.index') }}" class="btn-secondary">← All Extra Money</a>
            @if(auth()->user()->canAccess('extra_income.add'))
                <a href="{{ route('extra-money.create') }}" class="btn-secondary">+ Add New</a>
            @endif
            @if($cheque && auth()->user()->canAccess('account.cheques'))
                <a href="{{ route('accounts.cheques.show', $cheque) }}" class="btn-secondary">Cheque Management</a>
            @endif
            @if(!$isDeleted && !$chequeSettled && auth()->user()->canAccess('extra_income.edit'))
                <a href="{{ route('extra-money.edit', $extraIncome) }}" class="btn-primary">Edit Entry</a>
            @endif
        </div>
    </header>

    {{-- Alerts --}}
    @if(session('success'))
        <div class="flex items-center gap-3 rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3.5 text-sm font-semibold text-emerald-900 shadow-xs" role="status">
            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-emerald-600 text-xs font-black text-white" aria-hidden="true">✓</span>
            <span>{{ session('success') }}</span>
        </div>
    @endif
    @if($errors->any())
        <div class="flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3.5 text-sm font-semibold text-rose-800 shadow-xs" role="alert">
            <span class="grid size-6 shrink-0 place-items-center rounded-full bg-rose-600 text-xs font-black text-white" aria-hidden="true">!</span>
            <span>{{ $errors->first() }}</span>
        </div>
    @endif

    {{-- Hero Summary Banner --}}
    <section class="relative overflow-hidden rounded-2xl bg-[#06162f] p-6 text-white shadow-lg">
        <div class="pointer-events-none absolute -right-12 -top-16 size-56 rounded-full bg-emerald-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-16 left-1/3 size-48 rounded-full bg-blue-500/15 blur-3xl" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase tracking-wider {{ $statusBadgeClass }}">{{ $statusLabel }}</span>
                    <span class="text-xs font-medium text-slate-400">Saved as Income (Credit)</span>
                </div>
                <p class="text-3xl font-black tracking-tight sm:text-4xl {{ $isDeleted || $isRejected ? 'text-slate-400 line-through' : ($isHold ? 'text-amber-300' : 'text-emerald-400') }}">
                    + Rs. {{ number_format($extraIncome->amount, 2) }}
                </p>
                <p class="text-sm font-semibold text-slate-200">{{ $extraIncome->title }}@if($extraIncome->source_name) <span class="font-normal text-slate-400">· From {{ $extraIncome->source_name }}</span>@endif</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 lg:min-w-[440px]">
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-xs">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Account</p>
                    <p class="mt-1 text-sm font-bold text-white">{{ $extraIncome->destination_label }}</p>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-xs">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Payment Method</p>
                    <p class="mt-1 text-sm font-bold text-white">{{ $extraIncome->payment_method }}</p>
                </div>
                <div class="rounded-xl border border-white/10 bg-white/5 px-4 py-3 backdrop-blur-xs">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Income Date</p>
                    <p class="mt-1 text-sm font-bold text-white">{{ $extraIncome->income_date->format('d M Y') }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Main Two-Column Body --}}
    <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
        {{-- Left Column --}}
        <div class="space-y-6">
            {{-- Entry Details --}}
            <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xs">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-6 py-4">
                    <div>
                        <h2 class="text-base font-black text-slate-900">Entry Details</h2>
                        <p class="text-xs text-slate-500">Complete metadata recorded for this extra money income</p>
                    </div>
                    <span class="rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700 ring-1 ring-emerald-200/70">Income</span>
                </div>

                <dl class="grid divide-y divide-slate-100 sm:grid-cols-2 sm:divide-y-0">
                    @php
                        $details = array_filter([
                            'Entry Number' => $extraIncome->number,
                            'Income Date' => $extraIncome->income_date->format('d M Y'),
                            'Description' => $extraIncome->title,
                            'Received From' => $extraIncome->source_name,
                            'Destination Account' => $extraIncome->destination_label,
                            'Payment Method' => $extraIncome->payment_method,
                            'Reference Number' => $extraIncome->reference_number,
                            'Recorded By' => $extraIncome->creator?->name,
                            'Last Edited By' => $extraIncome->editor?->name,
                            'Latest Change Reason' => $extraIncome->change_reason,
                        ], fn ($v) => filled($v));
                    @endphp
                    @foreach($details as $label => $value)
                        <div class="border-b border-slate-100 px-6 py-4">
                            <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $label }}</dt>
                            <dd class="mt-1 text-sm font-semibold text-slate-900 whitespace-pre-wrap">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>

                @if(filled($extraIncome->note))
                    <div class="border-t border-slate-100 bg-slate-50/40 px-6 py-4">
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400">Notes</p>
                        <p class="mt-1.5 text-sm text-slate-700 whitespace-pre-wrap">{{ $extraIncome->note }}</p>
                    </div>
                @endif
            </section>

            {{-- Cheque Details (when linked to a Cheque) --}}
            @if($cheque)
                <section class="overflow-hidden rounded-2xl border border-amber-200 bg-white shadow-xs">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-100 bg-amber-50/60 px-6 py-4">
                        <div>
                            <h2 class="text-base font-black text-slate-900">Cheque Details</h2>
                            <p class="text-xs text-slate-600">Linked received cheque in Cheque Management</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <x-cheque-status :cheque="$cheque" />
                            @if(auth()->user()->canAccess('account.cheques'))
                                <a href="{{ route('accounts.cheques.show', $cheque) }}" class="rounded-lg bg-white px-3 py-1.5 text-xs font-bold text-blue-700 ring-1 ring-slate-200 hover:bg-blue-50">Manage Cheque →</a>
                            @endif
                        </div>
                    </div>

                    <dl class="grid sm:grid-cols-2 lg:grid-cols-3">
                        @foreach([
                            'Cheque Number' => $cheque->cheque_number,
                            'Cheque Date' => $cheque->cheque_date?->format('d M Y'),
                            'Bank Name' => $cheque->bank_name,
                            'Branch' => $cheque->branch ?: '—',
                            'Passed Date' => $cheque->realized_date?->format('d M Y') ?: '—',
                            'Party / Source' => $cheque->party_name,
                            'Status Reason' => $cheque->status_reason,
                        ] as $label => $value)
                            @if(filled($value))
                                <div class="border-b border-slate-100 px-6 py-3.5">
                                    <dt class="text-[11px] font-bold uppercase tracking-wider text-slate-400">{{ $label }}</dt>
                                    <dd class="mt-1 text-sm font-semibold text-slate-900">{{ $value }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>

                    <div class="p-4">
                        @if($isHold)
                            <p class="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-xs leading-relaxed text-amber-900">
                                <strong>Condition:</strong> This cheque is currently <strong>{{ $cheque->status }}</strong> (on Hold). Bank Account balance and Profit &amp; Loss income will be credited automatically once the cheque is marked <strong>Passed</strong> (on or after {{ $cheque->cheque_date->format('d M Y') }}) in Cheque Management.
                            </p>
                        @elseif($cheque->status === 'Cleared')
                            <p class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-xs leading-relaxed text-emerald-900">
                                <strong>Condition:</strong> This cheque was marked <strong>Passed</strong>{{ $cheque->realized_date ? ' on '.$cheque->realized_date->format('d M Y') : '' }} and credited as <strong>Income</strong>. To return or cancel it, update its status in Cheque Management.
                            </p>
                        @elseif(in_array($cheque->status, ['Returned', 'Cancelled'], true))
                            <p class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-xs leading-relaxed text-rose-800">
                                <strong>Condition:</strong> This cheque was marked <strong>{{ $cheque->status }}</strong>. Any previously credited Bank Account income has been reversed and is excluded from Profit &amp; Loss.
                            </p>
                        @endif
                    </div>
                </section>
            @endif

            {{-- Account Postings --}}
            <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xs">
                <div class="flex items-center justify-between border-b border-slate-100 bg-slate-50/70 px-6 py-4">
                    <div>
                        <h2 class="text-base font-black text-slate-900">Account Postings</h2>
                        <p class="text-xs text-slate-500">Ledger credit and reversal trail</p>
                    </div>
                    <span class="rounded-full bg-slate-200/70 px-2.5 py-0.5 text-xs font-bold text-slate-700">{{ $transactions->count() }} {{ Str::plural('entry', $transactions->count()) }}</span>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-slate-50/80 text-[11px] font-bold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-6 py-3">Date / Ref</th>
                                <th class="px-4 py-3">Account</th>
                                <th class="px-4 py-3">Type</th>
                                <th class="px-4 py-3">Description</th>
                                <th class="px-4 py-3 text-right">Money In</th>
                                <th class="px-4 py-3 text-right">Money Out</th>
                                <th class="px-6 py-3 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @forelse($transactions as $entry)
                                <tr class="hover:bg-slate-50/70">
                                    <td class="px-6 py-3.5 whitespace-nowrap">
                                        <span class="font-semibold text-slate-900">{{ $entry->transaction_date->format('d M Y') }}</span>
                                        <span class="block text-xs text-slate-400">{{ $entry->transaction_no }}</span>
                                    </td>
                                    <td class="px-4 py-3.5 font-medium text-slate-800 whitespace-nowrap">{{ $entry->account?->name }}</td>
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-bold {{ $entry->credit > 0 ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200/70' : 'bg-rose-50 text-rose-700 ring-1 ring-rose-200/70' }}">
                                            {{ ucwords(str_replace('_', ' ', $entry->transaction_type)) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="font-medium text-slate-800">{{ $entry->description }}</span>
                                        @if($entry->note)
                                            <span class="block text-xs text-slate-500">{{ $entry->note }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3.5 text-right font-bold whitespace-nowrap {{ $entry->credit > 0 ? 'text-emerald-600' : 'text-slate-300' }}">
                                        {{ $entry->credit > 0 ? 'Rs. '.number_format($entry->credit, 2) : '—' }}
                                    </td>
                                    <td class="px-4 py-3.5 text-right font-bold whitespace-nowrap {{ $entry->debit > 0 ? 'text-rose-600' : 'text-slate-300' }}">
                                        {{ $entry->debit > 0 ? 'Rs. '.number_format($entry->debit, 2) : '—' }}
                                    </td>
                                    <td class="px-6 py-3.5 text-right whitespace-nowrap">
                                        <span class="text-xs font-semibold {{ $entry->status === 'active' ? 'text-slate-700' : 'text-slate-400' }}">{{ ucfirst($entry->status) }}</span>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-8 text-center text-sm text-slate-500">
                                        {{ $isHold ? 'No ledger posting yet — income will be posted to Bank Account when the cheque is marked Passed.' : 'No active account postings for this entry.' }}
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        {{-- Right Sidebar Column --}}
        <aside class="space-y-6">
            {{-- Change History Timeline --}}
            <section class="overflow-hidden rounded-2xl border border-slate-200/80 bg-white shadow-xs">
                <div class="border-b border-slate-100 bg-slate-50/70 px-5 py-4">
                    <h2 class="text-base font-black text-slate-900">Change History</h2>
                    <p class="text-xs text-slate-500">Audit trail of all actions</p>
                </div>

                <div class="p-5">
                    @if($history->isNotEmpty())
                        <ol class="relative space-y-5 border-l-2 border-slate-100 pl-5">
                            @foreach($history as $event)
                                @php
                                    $dotClass = str_contains($event->event, 'Deleted')
                                        ? 'bg-rose-500 ring-rose-100'
                                        : (str_contains($event->event, 'Updated') ? 'bg-blue-500 ring-blue-100' : 'bg-emerald-500 ring-emerald-100');
                                @endphp
                                <li class="relative">
                                    <span class="absolute -left-[25px] top-1 size-3 rounded-full ring-4 {{ $dotClass }}" aria-hidden="true"></span>
                                    <p class="text-sm font-bold text-slate-900">{{ $event->event }}</p>
                                    <time class="mt-0.5 block text-xs text-slate-400">{{ $event->created_at->format('d M Y · h:i A') }}</time>
                                    @if(data_get($event->new_values, 'change_reason'))
                                        <p class="mt-1.5 rounded-lg bg-slate-50 px-3 py-2 text-xs text-slate-600">{{ data_get($event->new_values, 'change_reason') }}</p>
                                    @endif
                                    @if($event->old_values)
                                        <p class="mt-1.5 text-xs text-slate-500">
                                            Rs. {{ number_format(data_get($event->old_values, 'amount', 0), 2) }} → <strong class="text-slate-800">Rs. {{ number_format(data_get($event->new_values, 'amount', 0), 2) }}</strong>
                                        </p>
                                    @endif
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="text-sm text-slate-500">No history events recorded.</p>
                    @endif
                </div>
            </section>

            {{-- Delete / Reversal Card --}}
            @if(!$isDeleted && auth()->user()->canAccess('extra_income.delete'))
                <section class="overflow-hidden rounded-2xl border border-rose-200/80 bg-white shadow-xs" id="delete-extra-money">
                    <div class="border-b border-rose-100 bg-rose-50/60 px-5 py-4">
                        <h2 class="text-base font-black text-rose-900">Delete Entry</h2>
                        <p class="text-xs text-rose-700/80">Safe soft-delete with ledger reversal</p>
                    </div>
                    <div class="p-5">
                        @if($cheque && !$cheque->trashed() && $cheque->status === 'Cleared')
                            <p class="text-sm leading-relaxed text-amber-900">This entry is linked to a <strong>Passed</strong> cheque. Return, cancel, or delete it in <a href="{{ route('accounts.cheques.show', $cheque) }}" class="font-bold text-blue-700 underline">Cheque Management</a> first to reverse its bank credit.</p>
                        @else
                            <p class="text-sm leading-relaxed text-slate-600">
                                {{ $isHold ? 'Deleting cancels the linked pending cheque and moves this entry to Deleted entries.' : 'Deleting reverses its income credit from '.$extraIncome->destination_label.'. The entry and its audit history remain preserved in Deleted entries.' }}
                            </p>
                            <button type="button" id="open-delete-extra-money" class="mt-4 w-full rounded-xl border border-rose-200 bg-rose-50 px-4 py-2.5 text-sm font-bold text-rose-700 transition hover:bg-rose-100">
                                Delete Extra Money
                            </button>

                            <style>
                                #delete-extra-money-dialog:modal {
                                    overscroll-behavior: contain;
                                }
                                #delete-extra-money-dialog::backdrop {
                                    overflow: hidden;
                                    overscroll-behavior: contain;
                                    background: rgba(6, 22, 47, 0.55);
                                }
                            </style>
                            <dialog id="delete-extra-money-dialog" aria-labelledby="delete-extra-money-title" class="m-auto w-[min(32rem,calc(100vw-2rem))] rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                                <form method="POST" action="{{ route('extra-money.destroy', $extraIncome) }}" class="space-y-4">
                                    @csrf
                                    @method('DELETE')
                                    <x-submission-token />
                                    <input type="hidden" name="record_version" value="{{ $extraIncome->version() }}">
                                    <h2 id="delete-extra-money-title" class="text-xl font-black text-slate-900">Delete Extra Money</h2>
                                    <p class="text-sm text-slate-600">
                                        {{ $isHold ? 'Cancel the pending cheque and delete Rs. '.number_format($extraIncome->amount, 2).' extra money entry.' : 'Reverse Rs. '.number_format($extraIncome->amount, 2).' income from '.$extraIncome->destination_label.'.' }}
                                    </p>
                                    <div>
                                        <label for="delete-reason" class="label">Reason for deletion <span aria-hidden="true">*</span></label>
                                        <textarea id="delete-reason" name="reason" required minlength="5" maxlength="500" rows="3" class="field" placeholder="Explain why this entry is being deleted">{{ old('reason') }}</textarea>
                                    </div>
                                    <div class="flex justify-end gap-2 pt-2">
                                        <button type="button" id="close-delete-extra-money" class="btn-secondary" autofocus>Cancel</button>
                                        <button type="submit" class="btn-primary">Confirm Delete</button>
                                    </div>
                                </form>
                            </dialog>

                            <script>
                            (() => {
                                const dialog = document.getElementById('delete-extra-money-dialog');
                                const openBtn = document.getElementById('open-delete-extra-money');
                                const closeBtn = document.getElementById('close-delete-extra-money');
                                if (!dialog || !openBtn || !closeBtn) return;
                                openBtn.addEventListener('click', () => dialog.showModal());
                                closeBtn.addEventListener('click', () => dialog.close());
                            })();
                            </script>
                        @endif
                    </div>
                </section>
            @endif
        </aside>
    </div>
</div>
</x-layouts::app>
