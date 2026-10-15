<x-layouts::app title="Advance Payments">
<div class="space-y-5">
    <div class="flex flex-wrap justify-between gap-3">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Staff / Payroll</p>
            <h1 class="page-title">Advance Payments</h1>
            <p class="page-subtitle">Record daily, weekly, or monthly advances given to staff</p>
        </div>
        <a href="#advance-form" class="btn-primary">＋ Add Advance</a>
    </div>
    <x-flash/>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Today Advances','Rs. '.number_format($stats['today'],2)],['This Month','Rs. '.number_format($stats['month'],2)],['Pending Deductions','Rs. '.number_format($stats['pending'],2)],['Advance Balance','Rs. '.number_format($stats['balance'],2)]] as [$label,$value])
            <article class="panel !p-4"><p class="text-xs font-bold text-slate-500">{{ $label }}</p><p class="mt-2 text-xl font-black">{{ $value }}</p></article>
        @endforeach
    </div>
    <form id="advance-form" method="POST" action="{{ route('technicians.advances.store') }}" class="panel">
        @csrf
        <x-submission-token/>
        <h2 class="text-lg font-extrabold">Add Advance Payment</h2>
        @include('technicians._advance-fields', ['formAdvance' => null, 'retainInput' => !old('_editing_advance_id'), 'gridColumns' => 'xl:grid-cols-4'])
        <div class="mt-4 flex justify-end"><button class="btn-primary">Record Advance</button></div>
    </form>
    <div class="table-wrap">
        <table class="data-table min-w-[1100px]">
            <thead><tr><th>Advance No</th><th>Staff</th><th>Date</th><th>Amount</th><th>Account</th><th>Method</th><th>Deduct Month</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                @forelse($advances as $advance)
                    <tr>
                        <td class="font-black text-red-600">{{ $advance->number }}</td>
                        <td>{{ $advance->technician?->name ?? 'Former staff' }}</td>
                        <td>{{ $advance->payment_date->format('d M Y') }}</td>
                        <td>Rs. {{ number_format($advance->amount,2) }}</td>
                        <td>{{ $advance->account?->name ?? 'Unavailable account' }}</td>
                        <td>{{ $advance->payment_method }}</td>
                        <td>{{ $advance->deduct_month->format('M Y') }}</td>
                        <td>{{ $advance->status }}</td>
                        <td>
                            <div class="flex flex-wrap items-center gap-2">
                                <button type="button" onclick="window.print()" class="btn-secondary !px-3 !py-2">Receipt</button>
                                @if(auth()->user()->canAccess('technician.advances'))
                                    @if($advance->status === 'Pending Deduction' && $advance->salary_sheet_id === null)
                                        @php
                                            $editDetails = [
                                                'id' => $advance->id, 'number' => $advance->number,
                                                'update_url' => route('technicians.advances.update', $advance),
                                                'technician_id' => $advance->technician_id, 'staff_name' => $advance->technician?->name ?? 'Former staff',
                                                'account_id' => $advance->account_id, 'account_name' => $advance->account?->name ?? 'Unavailable account',
                                                'payment_date' => $advance->payment_date->toDateString(),
                                                'amount' => $advance->amount, 'payment_method' => $advance->payment_method,
                                                'deduct_month' => $advance->deduct_month->format('Y-m'), 'note' => $advance->note,
                                            ];
                                        @endphp
                                        <button type="button" data-edit-advance="{{ json_encode($editDetails) }}" class="btn-secondary !px-3 !py-2" aria-label="Edit advance {{ $advance->number }}" aria-haspopup="dialog" aria-controls="edit-advance-dialog">Edit</button>
                                        <form method="POST" action="{{ route('technicians.advances.destroy', $advance) }}" onsubmit="return confirm('Delete this advance payment? Its account entries will be reversed and the salary deduction removed.');">
                                            @csrf
                                            @method('DELETE')
                                            <x-submission-token/>
                                            <button type="submit" class="btn-secondary !px-3 !py-2 !text-red-600" aria-label="Delete advance {{ $advance->number }}">Delete</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-500">Reopen salary sheet to edit or delete</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="py-12 text-center text-slate-400">No advances have been recorded. New advances automatically update the selected account ledger.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="border-t p-4">{{ $advances->links() }}</div>
    </div>
</div>
@if(auth()->user()->canAccess('technician.advances'))
    <dialog id="edit-advance-dialog" aria-labelledby="edit-advance-title" class="m-auto w-[calc(100%-2rem)] max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl backdrop:bg-slate-900/60" style="max-height:90vh;overflow-y:auto" @if($editingAdvance) data-open-on-load @endif>
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 id="edit-advance-title" class="text-lg font-extrabold">Edit Advance Payment</h2>
                <p id="edit-advance-number" class="mt-1 text-sm font-bold text-slate-500">{{ $editingAdvance?->number }}</p>
            </div>
            <button type="button" data-close-advance class="btn-secondary !px-3" aria-label="Close edit advance">✕</button>
        </div>
        @if($editingAdvance && $errors->any())
            <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                <strong>Please correct the following:</strong>
                <ul class="mt-1 list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif
        <form id="edit-advance-form" method="POST" action="{{ $editingAdvance ? route('technicians.advances.update', $editingAdvance) : '' }}">
            @csrf
            @method('PUT')
            <x-submission-token/>
            <input type="hidden" name="_editing_advance_id" value="{{ $editingAdvance?->id }}">
            @include('technicians._advance-fields', ['formAdvance' => $editingAdvance, 'retainInput' => $editingAdvance && old('_editing_advance_id') == $editingAdvance->id, 'gridColumns' => ''])
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" data-close-advance class="btn-secondary">Cancel</button>
                <button type="submit" class="btn-primary">Save Changes</button>
            </div>
        </form>
    </dialog>
    <script>
    (() => {
        const dialog = document.getElementById('edit-advance-dialog');
        const form = document.getElementById('edit-advance-form');
        const ensureOption = (name, value, label) => {
            const select = form.elements.namedItem(name);
            if (!Array.from(select.options).some(option => option.value === String(value))) {
                select.add(new Option(label, value));
            }
        };
        document.querySelectorAll('[data-edit-advance]').forEach(button => {
            button.addEventListener('click', () => {
                const data = JSON.parse(button.dataset.editAdvance);
                form.reset();
                dialog.querySelector('[role="alert"]')?.remove();
                form.action = data.update_url;
                form.elements.namedItem('_editing_advance_id').value = data.id;
                document.getElementById('edit-advance-number').textContent = data.number;
                ensureOption('technician_id', data.technician_id, data.staff_name);
                ensureOption('account_id', data.account_id, data.account_name);
                ensureOption('payment_method', data.payment_method, data.payment_method);
                for (const name of ['technician_id', 'payment_date', 'amount', 'account_id', 'payment_method', 'deduct_month', 'note']) {
                    form.elements.namedItem(name).value = data[name] ?? '';
                }
                dialog.showModal();
                form.elements.namedItem('technician_id').focus();
            });
        });
        dialog.querySelectorAll('[data-close-advance]').forEach(button => button.addEventListener('click', () => dialog.close()));
        dialog.addEventListener('click', event => {
            if (event.target !== dialog) return;
            const bounds = dialog.getBoundingClientRect();
            if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
        });
        if (dialog.hasAttribute('data-open-on-load')) dialog.showModal();
    })();
    </script>
@endif
</x-layouts::app>
