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
<div class="space-y-5"><div class="flex justify-between gap-3"><div><h1 class="page-title">Job Cards</h1><p class="page-subtitle">Technician assignments and workshop progress.</p></div><a href="{{ route('jobs.create') }}" class="btn-primary">+ New job card</a></div><x-flash/><form class="panel flex flex-wrap gap-2"><select class="field max-w-xs" name="status"><option value="">All statuses</option>@foreach($statuses as $s)<option @selected(request('status')===$s)>{{ $s }}</option>@endforeach</select><button class="btn-secondary">Filter</button></form><div class="table-wrap"><table class="data-table"><thead><tr><th>Job</th><th>Bike / customer</th><th>Complaint</th><th>Technician</th><th>Status</th><th>Total</th></tr></thead><tbody>@forelse($jobs as $j)<tr><td><a href="{{ route('jobs.show',$j) }}" class="font-semibold text-emerald-600">{{ $j->number }}</a><br><span class="text-xs">{{ $j->created_at->format('d M Y') }}</span></td><td class="font-semibold">{{ $j->intake->bike->number }}<br><span class="text-xs font-normal">{{ $j->intake->bike->customer->name ?: 'Walk-in' }}</span></td><td class="max-w-xs truncate">{{ $j->intake->complaint }}</td><td>{{ $j->technician?->name ?: 'Unassigned' }}</td><td><span class="rounded-full px-2.5 py-0.5 text-xs font-bold {{ $statusColors[$j->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $j->status }}</span></td><td>Rs. {{ number_format($j->total,2) }}@if($j->invoice)<br><div class="flex items-center gap-1.5 mt-0.5"><span class="text-xs text-slate-500 font-semibold">{{ $j->invoice->status }}</span>@if($j->invoice->due_amount > 0)<button type="button" onclick="openDuePaymentModal({{ $j->invoice->id }}, {{ $j->invoice->due_amount }}, '{{ $j->invoice->number }}')" class="rounded bg-emerald-600 hover:bg-emerald-700 text-white text-[10px] font-extrabold px-1.5 py-0.5 leading-none transition-colors">Pay</button>@endif</div>@endif</td></tr>@empty<tr><td colspan="6" class="text-center">No jobs found.</td></tr>@endforelse</tbody></table></div>{{ $jobs->links() }}</div>

{{-- Due Payment Dialog --}}
<dialog id="due-payment-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <form method="POST" id="due-payment-form" class="p-6">
        @csrf
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-red-600">Payment Collection</p>
                <h2 class="mt-1 text-xl font-black text-slate-900" id="due-payment-title" style="color: #0f172a !important;">Receive Payment</h2>
            </div>
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl text-slate-500 hover:bg-slate-200">×</button>
        </div>
        <div class="mt-4 rounded-2xl bg-red-50 p-4" style="background-color: #fef2f2 !important; border: 1px solid #fee2e2 !important;">
            <p class="text-xs font-bold text-red-500">Outstanding Due</p>
            <p class="mt-1 text-2xl font-black text-red-700" id="due-payment-outstanding-label">Rs. 0.00</p>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <label class="block">
                <span class="label" style="color: #475569 !important;">Payment Amount *</span>
                <input type="number" step="0.01" min="0.01" name="amount" id="due-payment-amount" class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
            </label>
            <label class="block">
                <span class="label" style="color: #475569 !important;">Payment Method *</span>
                <select name="method" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    <option>Cash</option>
                    <option>Card</option>
                    <option>Bank Transfer</option>
                    <option>Cheque</option>
                    <option>QR</option>
                </select>
            </label>
            <label class="sm:col-span-2 block">
                <span class="label" style="color: #475569 !important;">Deposit Account *</span>
                <select name="account_id" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
                    @foreach($accounts as $account)
                    <option value="{{ $account->id }}" @selected($account->code === 'MAIN' || $account->type === 'main')>{{ $account->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="sm:col-span-2 block">
                <span class="label" style="color: #475569 !important;">Note</span>
                <textarea name="note" rows="2" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;"></textarea>
            </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('due-payment-dialog').close()" class="btn-secondary" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;">Cancel</button>
            <button class="btn-primary">Save Payment</button>
        </div>
    </form>
</dialog>

<script>
function openDuePaymentModal(invoiceId, dueAmount, invoiceNumber) {
    const dialog = document.getElementById('due-payment-dialog');
    const form = document.getElementById('due-payment-form');
    
    form.action = `{{ url('customer-dues') }}/${invoiceId}/payment`;
    document.getElementById('due-payment-title').textContent = `Pay Bill ${invoiceNumber}`;
    document.getElementById('due-payment-outstanding-label').textContent = 'Rs. ' + parseFloat(dueAmount).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    const amountInput = document.getElementById('due-payment-amount');
    amountInput.value = parseFloat(dueAmount).toFixed(2);
    amountInput.max = parseFloat(dueAmount);
    
    dialog.showModal();
}
</script>
</x-layouts::app>
