<x-layouts::app title="{{ $job->intake->bike->number }} · {{ $job->technician?->name ?: 'Unassigned' }} · {{ $job->number }}">
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
  $isLocked = in_array($job->status, ['Completed', 'Billed', 'Delivered', 'Cancelled']);
@endphp
<div class="space-y-5">
<x-job-card-heading :job="$job" :status-class="$statusColors[$job->status] ?? 'bg-slate-100 text-slate-700'" />
<x-flash/>
@include('jobs.partials.spare-parts')
<div class="grid gap-5 xl:grid-cols-3" x-data="{
    discountPercentage: @js(old('discount_percentage', $job->discount_percentage ?? 0)),
    fixedDiscount: @js(old('fixed_discount', $job->fixed_discount ?? 0)),
    partsTotal: @js((float) $job->parts_total),
    serviceTotal: @js((float) $job->service_total),
    laborTotal: @js((float) $job->labor_total),
    washingTotal: @js((float) $job->washing_total),
    otherTotal: @js((float) $job->other_total),
    serviceName: @js(old('service_name', $job->service_name ?: 'Service')),
    otherIncomeName: @js(old('other_income_name', $job->other_income_name ?: 'Other Income')),
    taxTotal: @js((float) $job->tax),
    get subtotal() {
        return this.partsTotal + (Number(this.serviceTotal) || 0) + (Number(this.laborTotal) || 0) + (Number(this.washingTotal) || 0) + (Number(this.otherTotal) || 0);
    },
    get percentageDiscountAmount() {
        return Math.round(this.subtotal * (Number(this.discountPercentage) || 0)) / 100;
    },
    get discountAmount() {
        return Math.round((this.percentageDiscountAmount + (Number(this.fixedDiscount) || 0)) * 100) / 100;
    },
    get jobTotal() {
        return Math.max(0, Math.round((this.subtotal - this.discountAmount + (Number(this.taxTotal) || 0)) * 100) / 100);
    },
    get hasUnsavedBillChanges() {
        const saved = @js([(float) $job->service_total, (float) $job->labor_total, (float) $job->washing_total, (float) $job->other_total, (float) $job->discount_percentage, (float) $job->fixed_discount, (float) $job->tax]);
        return [this.serviceTotal, this.laborTotal, this.washingTotal, this.otherTotal, this.discountPercentage, this.fixedDiscount, this.taxTotal].some((value, index) => (Number(value) || 0) !== saved[index]);
    },
    money(amount) {
        return Number(amount).toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
}"><div class="panel xl:col-span-2"><h2 class="mb-4 font-bold">Work details</h2><div class="rounded-xl p-4 border border-slate-200" style="background-color: #f8fafc !important; color: #1e293b !important;"><span class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Customer Complaint</span><p class="text-sm font-semibold text-slate-800" style="color: #1e293b !important;">{{ $job->intake->complaint }}</p></div><form id="job-update-form" method="POST" action="{{ route('jobs.update',$job) }}" class="mt-4 grid gap-4 sm:grid-cols-2">@csrf<x-submission-token/> @method('PUT')<label><span class="label">Status</span><select name="status" class="field">
    @foreach($statuses as $s)
        <option @selected($job->status===$s)>{{ $s }}</option>
    @endforeach
</select></label><label><span class="label">Main technician</span><select name="main_technician_id" class="field" @disabled($isLocked)><option value="">Unassigned</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected($job->main_technician_id===$t->id)>{{ $t->name }}</option>@endforeach</select></label><label class="sm:col-span-2"><span class="label">Change reason / notes</span><input name="change_reason" class="field" placeholder="Enter reason for status change (optional)..."></label>

<div class="relative">
    <input type="hidden" name="service_name" id="hidden_service_name" x-model="serviceName" value="{{ old('service_name', $job->service_name ?: 'Service') }}">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0 flex items-center gap-1"><span id="lbl_service_name" x-text="serviceName">{{ $job->service_name ?: 'Service' }}</span> (LKR) <button type="button" onclick="editName('Service', 'hidden_service_name')" class="text-blue-500 hover:text-blue-700" @disabled($isLocked)>✏️</button></span></label>
    <input type="number" step=".01" name="service_total" x-model.number="serviceTotal" value="{{ $job->service_total }}" class="field" @disabled($isLocked)>
</div>

<div class="relative">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0">Labor (LKR)</span><span class="flex items-center gap-1 text-[10px] text-slate-500 font-bold"><input type="checkbox" name="show_labor_on_bill" value="1" class="size-3 accent-red-600" @checked($job->show_labor_on_bill) @disabled($isLocked)> Show on bill</span></label>
    <input type="number" step=".01" name="labor_total" x-model.number="laborTotal" value="{{ $job->labor_total }}" class="field" @disabled($isLocked)>
</div>

<div class="relative">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0">Washing (LKR)</span><span class="flex items-center gap-1 text-[10px] text-slate-500 font-bold"><input type="checkbox" name="show_washing_on_bill" value="1" class="size-3 accent-red-600" @checked($job->show_washing_on_bill) @disabled($isLocked)> Show on bill</span></label>
    <input type="number" step=".01" name="washing_total" x-model.number="washingTotal" value="{{ $job->washing_total }}" class="field" @disabled($isLocked)>
</div>

<div class="relative">
    <input type="hidden" name="other_income_name" id="hidden_other_income_name" x-model="otherIncomeName" value="{{ old('other_income_name', $job->other_income_name ?: 'Other Income') }}">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0 flex items-center gap-1"><span id="lbl_other_income_name" x-text="otherIncomeName">{{ $job->other_income_name ?: 'Other Income' }}</span> (LKR) <button type="button" onclick="editName('Other Income', 'hidden_other_income_name')" class="text-blue-500 hover:text-blue-700" @disabled($isLocked)>✏️</button></span></label>
    <input type="number" step=".01" name="other_total" x-model.number="otherTotal" value="{{ $job->other_total }}" class="field" @disabled($isLocked)>
</div>

<div class="sm:col-span-2 grid gap-4 sm:grid-cols-3">
    <div>
        <label for="job-discount-percentage" class="label">Percentage discount (%)</label>
        <input id="job-discount-percentage" type="number" step=".01" min="0" max="100" name="discount_percentage" x-model.number="discountPercentage" value="{{ old('discount_percentage', $job->discount_percentage ?? 0) }}" class="field text-right" aria-describedby="job-percentage-discount-amount" @disabled($isLocked)>
        <p id="job-percentage-discount-amount" class="mt-2 rounded-lg bg-emerald-50 px-2 py-1.5 text-xs font-semibold text-emerald-700" aria-live="polite">Rs. <span x-text="money(percentageDiscountAmount)">{{ number_format($job->percentage_discount_amount, 2) }}</span> added to discount</p>
    </div>
    <label><span class="label">Fixed discount (LKR)</span><input type="number" step=".01" min="0" max="999999999999.99" name="fixed_discount" x-model.number="fixedDiscount" value="{{ old('fixed_discount', $job->fixed_discount ?? 0) }}" class="field text-right" @disabled($isLocked)></label>
    <label><span class="label">Tax (LKR)</span><input type="number" step=".01" name="tax" x-model.number="taxTotal" value="{{ $job->tax }}" class="field" @disabled($isLocked)></label>
    <p class="sm:col-span-3 text-sm font-bold text-slate-700" aria-live="polite">Total discount: Rs. <span x-text="discountAmount.toLocaleString('en-LK', {minimumFractionDigits: 2, maximumFractionDigits: 2})">{{ number_format($job->discount, 2) }}</span></p>
</div>

<div class="sm:col-span-2 flex flex-col sm:flex-row justify-between items-center gap-3 mt-2">@if($isLocked)<button type="button" onclick="openUnlockModal()" class="w-full sm:w-auto btn-primary bg-amber-500 hover:bg-amber-600 text-white font-extrabold text-xs py-3 px-4 shadow-lg shadow-amber-100 transition-colors order-2 sm:order-1">🔓 Request Changes / Edit Job</button><button class="w-full sm:w-auto btn-primary bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs py-3 px-6 shadow-lg shadow-emerald-100 transition-colors order-1 sm:order-2">Update Status</button>@else<button class="btn-primary">Update job</button>@endif</div></form></div>
<div class="space-y-4">
    <div class="panel" x-data="{ includePreviousBalance: @js((bool) old('show_previous_balance_on_bill', $job->show_previous_balance_on_bill)) }">
        <h2 class="mb-4 font-bold">Bill summary</h2>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt>Parts</dt><dd>Rs. {{ number_format($job->parts_total,2) }}</dd></div>
            <div class="flex justify-between"><dt>Service & labor</dt><dd x-text="'Rs. ' + money(subtotal - partsTotal)">Rs. {{ number_format($job->service_total+$job->labor_total+$job->washing_total+$job->other_total,2) }}</dd></div>
            <div class="flex justify-between"><dt>Discount</dt><dd id="job-summary-discount" x-text="'- Rs. ' + money(discountAmount)">- Rs. {{ number_format($job->discount,2) }}</dd></div>
            <div class="flex justify-between border-t pt-3 text-lg font-bold"><dt>Total</dt><dd id="job-summary-total" x-text="'Rs. ' + money(jobTotal)">Rs. {{ number_format($job->total,2) }}</dd></div>
            <div class="flex justify-between text-sm font-semibold text-slate-500"><dt>Earlier unpaid balances</dt><dd class="font-bold text-red-600">Rs. {{ number_format($oldDue,2) }}</dd></div>
            <div class="flex justify-between border-t pt-3 text-lg font-bold" x-show="includePreviousBalance" x-cloak><dt>Grand Total</dt><dd id="job-summary-grand-total" x-text="'Rs. ' + money(jobTotal + {{ (float) $oldDue }})">Rs. {{ number_format($job->total + $oldDue,2) }}</dd></div>
            @if($job->invoice)
            <div class="flex justify-between border-t border-dashed pt-2 text-xs font-semibold text-slate-500"><dt>Paid</dt><dd class="text-emerald-600 font-bold">Rs. {{ number_format($job->invoice->paid_amount,2) }}</dd></div>
            @if($job->invoice->change_amount > 0)
            <div class="flex justify-between text-xs font-semibold text-slate-500"><dt>Amount received</dt><dd class="font-bold">Rs. {{ number_format($job->invoice->received_amount,2) }}</dd></div>
            <div class="flex justify-between text-xs font-semibold text-violet-700"><dt>Change to return</dt><dd class="font-bold">Rs. {{ number_format($job->invoice->change_amount,2) }}</dd></div>
            @endif
            <div class="flex justify-between text-xs font-semibold text-slate-500"><dt>Cheque Hold</dt><dd class="text-amber-600 font-bold">Rs. {{ number_format($job->invoice->hold_amount,2) }}</dd></div>
            <div class="flex justify-between text-xs font-semibold text-slate-500"><dt>Due</dt><dd class="text-red-600 font-bold" x-text="'Rs. ' + money(Math.max(0, jobTotal - {{ (float) $job->invoice->paid_amount }} - {{ (float) $job->invoice->hold_amount }}))">Rs. {{ number_format($job->invoice->due_amount,2) }}</dd></div>
            <div class="flex justify-between text-xs font-semibold text-slate-500"><dt>Payment status</dt><dd>{{ $job->invoice->status === 'Paid' ? 'Completed' : $job->invoice->status }}</dd></div>
            @endif
            @if($oldDue > 0)
            <div class="flex justify-between border-t pt-3 text-sm font-bold" x-show="includePreviousBalance" x-cloak><dt>Total Due Balance</dt><dd class="text-red-600" x-text="'Rs. ' + money(Math.max(0, jobTotal - {{ (float) ($job->invoice?->paid_amount ?? 0) }} - {{ (float) ($job->invoice?->hold_amount ?? 0) }}) + {{ (float) $oldDue }})">Rs. {{ number_format(($job->invoice?->due_amount ?? $job->total) + $oldDue,2) }}</dd></div>
            @endif
        </dl>
        <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-3">
            <input type="hidden" name="show_previous_balance_on_bill" form="job-update-form" :value="includePreviousBalance ? 1 : 0" value="{{ $job->show_previous_balance_on_bill ? 1 : 0 }}">
            <label class="flex items-start gap-2 text-sm font-semibold text-slate-700">
                <input type="checkbox" x-model="includePreviousBalance" class="mt-0.5 size-4 accent-red-600" @checked(old('show_previous_balance_on_bill', $job->show_previous_balance_on_bill))>
                <span>Include outstanding balances on bill</span>
            </label>
            <p class="mt-2 text-xs text-slate-500">Shows dated unpaid bills and Grand Total. Save with {{ $isLocked ? 'Update Status' : 'Update job' }}{{ $job->invoice ? '.' : ' or Generate invoice.' }}</p>
            <div x-show="includePreviousBalance" x-cloak class="mt-3 space-y-2 border-t border-slate-200 pt-3 text-xs">
                @forelse($previousBalances as $balance)
                    <div class="flex justify-between gap-3">
                        <span>{{ $balance['reference'] }}<span class="block text-slate-500">{{ $balance['date'] }}</span><span class="block text-slate-500">{{ $balance['status'] }}</span></span>
                        <div class="shrink-0 text-right"><span class="block font-bold text-red-600">Rs. {{ number_format($balance['amount'],2) }}</span><x-due-payment-button :invoice-id="$balance['invoice_id']" :due="$balance['amount']" :number="$balance['invoice_number']" :paid="$balance['paid']" :hold="$balance['hold']" class="mt-1"/></div>
                    </div>
                @empty
                    <p class="text-slate-500">No previous unpaid bills.</p>
                @endforelse
            </div>
        </div>
        @if(auth()->user()->canAccess('job_card.edit') && $job->status !== 'Cancelled' && $job->invoice?->status !== 'Cancelled')
        @php($previousBalanceLatestDate = min(today()->toDateString(), $job->invoice?->created_at->toDateString() ?? today()->toDateString()))
        <details class="mt-3 rounded-xl border border-slate-200 p-3" @if($errors->has('previous_balance_amount') || $errors->has('previous_balance_date')) open @endif>
            <summary class="cursor-pointer text-sm font-bold text-blue-600">+ Add opening balance</summary>
            <p class="mt-2 text-xs text-slate-500">Forgot to enter the opening balance when adding the customer or intake? Add only the missing amount here. Existing unpaid bills are included automatically.</p>
            <form method="POST" action="{{ route('jobs.previous-balance.store', $job) }}" class="mt-3 space-y-3">
                @csrf<x-submission-token/>
                <label class="block"><span class="label">Opening balance amount (LKR)</span><input type="number" name="previous_balance_amount" min="0.01" max="999999999999.99" step="0.01" value="{{ old('previous_balance_amount') }}" placeholder="0.00" class="field" required></label>
                <label class="block"><span class="label">Opening balance date</span><input type="date" name="previous_balance_date" max="{{ $previousBalanceLatestDate }}" value="{{ old('previous_balance_date', $previousBalanceLatestDate) }}" class="field" required></label>
                <button class="btn-secondary w-full">Add opening balance</button>
            </form>
        </details>
        @endif
        @if($job->invoice)
        <div class="flex flex-col gap-2 mt-5">
            <a target="_blank" href="{{ route('invoices.public',$job->invoice->public_token) }}" class="btn-primary w-full text-center">View {{ $job->invoice->number }}</a>
            @if($job->invoice->due_amount > 0 && $job->invoice->status !== 'Cancelled')
            <x-due-payment-button :invoice-id="$job->invoice->id" :due="$job->invoice->due_amount" :number="$job->invoice->number" :paid="$job->invoice->paid_amount" :hold="$job->invoice->hold_amount" label="Pay Bill" class="w-full"/>
            @endif
        </div>
        @else
        <form method="POST" action="{{ route('jobs.invoice',$job) }}" class="mt-5 space-y-3" x-effect="$dispatch('payment-due-selected', {due: jobTotal})">
            @csrf<x-submission-token/>
            <input type="hidden" name="service_name" :value="serviceName" value="{{ old('service_name', $job->service_name ?: 'Service') }}">
            <input type="hidden" name="other_income_name" :value="otherIncomeName" value="{{ old('other_income_name', $job->other_income_name ?: 'Other Income') }}">
            <input type="hidden" name="show_previous_balance_on_bill" :value="includePreviousBalance ? 1 : 0" value="{{ $job->show_previous_balance_on_bill ? 1 : 0 }}">
            <x-split-payments :accounts="$accounts" :change-total="$job->total" />
            <div class="mt-3">
                <label class="block text-xs font-semibold text-slate-700 mb-1">Assign Parts Commission (Optional)</label>
                <select name="parts_technician_id" class="field">
                    <option value="">-- Same as Main Technician --</option>
                    @foreach(\App\Models\Technician::where('active', true)->orderBy('name')->get() as $tech)
                        <option value="{{ $tech->id }}">{{ $tech->name }}</option>
                    @endforeach
                </select>
            </div>
            <p x-show="hasUnsavedBillChanges" x-cloak class="text-xs font-semibold text-amber-700">Save these changes with Update job before generating the invoice.</p>
            <button class="btn-primary w-full mt-3 disabled:opacity-50" :disabled="hasUnsavedBillChanges">Generate invoice</button>
        </form>
        @endif
    </div>

    @include('jobs.partials.payment-receipts')
    <div class="panel">
        <h2 class="mb-4 font-bold text-slate-800">👤 Customer & Vehicle</h2>
        <div class="space-y-3 text-xs leading-relaxed">
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Customer Name</span>
                <span class="font-extrabold text-slate-800">{{ $job->intake->bike->customer->name ?: 'Walk-in Customer' }}</span>
            </div>
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Phone Number</span>
                <span class="font-extrabold text-slate-800">{{ $job->intake->bike->customer->phone ?: 'N/A' }}</span>
            </div>
            @if($job->intake->bike->customer->address)
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Address</span>
                <span class="font-semibold text-slate-700">{{ $job->intake->bike->customer->address }}</span>
            </div>
            @endif
            <hr class="border-slate-100 my-2">
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Vehicle Details</span>
                <span class="font-extrabold text-red-600 text-sm">{{ $job->intake->bike->number }}</span>
                <span class="block text-slate-600 font-bold mt-0.5">{{ $job->intake->bike->brand }} {{ $job->intake->bike->model }} ({{ $job->intake->bike->year ?: 'N/A' }})</span>
            </div>
            @if($job->intake->bike->chassis_number)
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Chassis Number</span>
                <span class="font-mono text-slate-700">{{ $job->intake->bike->chassis_number }}</span>
            </div>
            @endif
            @if($job->intake->bike->engine_number)
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Engine Number</span>
                <span class="font-mono text-slate-700">{{ $job->intake->bike->engine_number }}</span>
            </div>
            @endif
        </div>
    </div>
</div></div>
<div class="panel mt-4">
    <h2 class="mb-4 font-bold text-slate-800 flex items-center gap-2">
        <span>⏱️ Status History & Change Logs</span>
    </h2>
    <div class="flow-root">
        <ul role="list" class="-mb-8">
            @forelse($history as $index => $entry)
            @php($log = $entry['record'])
            <li>
                <div class="relative pb-8">
                    @if($index !== count($history) - 1)
                    <span class="absolute left-4 top-4 -ml-px h-full w-0.5 bg-slate-200" aria-hidden="true"></span>
                    @endif
                    <div class="relative flex space-x-3">
                        <div>
                            <span class="flex size-8 items-center justify-center rounded-full bg-slate-100 ring-8 ring-white text-xs">
                                📌
                            </span>
                        </div>
                        <div class="flex min-w-0 flex-1 justify-between space-x-4 pt-1.5">
                            <div>
                                @if($entry['kind'] === 'payment')
                                <p class="text-xs font-bold text-slate-700">Payment corrected by {{ $log->changer?->name ?: 'System' }} · {{ $log->before['invoice_number'] }}</p>
                                <div class="mt-2 space-y-1 text-xs text-slate-600">
                                    @foreach(['before' => 'Before', 'after' => 'After'] as $key => $label)
                                    @php($details = $log->$key)
                                    <p><b>{{ $label }}:</b> {{ $details['number'] }} · {{ $details['method'] }} · Received Rs. {{ number_format($details['received_amount'], 2) }} · Applied Rs. {{ number_format($details['amount'], 2) }} · Change Rs. {{ number_format($details['change_amount'], 2) }} · {{ $details['paid_at'] }} · {{ $details['account_name'] }}</p>
                                    @if($details['cheque_id'])<p class="text-amber-700">Cheque {{ $details['cheque_number'] }} · {{ $details['bank_name'] }} · {{ $details['cheque_date'] }} · {{ $details['status'] }}</p>@endif
                                    @endforeach
                                    <p class="rounded border border-slate-200 bg-slate-50 p-2"><b>Reason:</b> {{ $log->reason }}</p>
                                </div>
                                @else
                                <p class="text-xs text-slate-600">
                                    Status changed from <span class="font-extrabold text-slate-500">{{ $log->old_status }}</span> to <span class="font-extrabold text-emerald-600">{{ $log->new_status }}</span> by <span class="font-bold text-slate-800">{{ $log->changer_name ?: 'System' }}</span>
                                </p>
                                @if($log->note)
                                <p class="mt-1 text-[11px] font-semibold text-slate-500 bg-slate-50 border border-slate-100 rounded p-1.5 inline-block">
                                    Note: {{ $log->note }}
                                </p>
                                @endif
                                @endif
                            </div>
                            <div class="whitespace-nowrap text-right text-[10px] font-bold text-slate-400">
                                <time datetime="{{ $log->created_at }}">{{ \Carbon\Carbon::parse($log->created_at)->format('d M Y, h:i A') }}</time>
                            </div>
                        </div>
                    </div>
                </div>
            </li>
            @empty
            <p class="text-xs font-semibold text-slate-400 text-center py-4">No status logs recorded for this Job Card.</p>
            @endforelse
        </ul>
    </div>
</div>
</div>

@include('jobs.partials.product-dialogs')

{{-- Price Edit Modal --}}
<dialog id="part-edit-modal" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important; max-height: 90vh; overflow-y: auto;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Edit Part Details</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" aria-label="Close part details" onclick="document.getElementById('part-edit-modal').close()">✕</button>
        </div>
        <form id="part-edit-form" method="POST" action="">
            @csrf<x-submission-token/>
            <div class="space-y-4">
                <div>
                    <label for="pe-name" class="label" style="color: #475569 !important;">Product Name</label>
                    <input id="pe-name" name="display_name" required maxlength="255" class="field">
                </div><label class="block"><span class="label">Part Note</span><input name="note" id="pe-note" maxlength="255" class="field" placeholder="Shown in brackets after the product name"></label>
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="pe-cost" class="label" style="color: #475569 !important;">Cost Price</label>
                        <input id="pe-cost" name="cost_price" type="number" step="0.01" min="0" required class="field">
                    </div>
                    <div>
                        <label for="pe-qty" class="label" style="color: #475569 !important;">Quantity *</label>
                        <input type="number" step="0.01" min="0.01" name="quantity" id="pe-qty" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                    <div>
                        <label for="pe-price" class="label" style="color: #475569 !important;">Unit Price *</label>
                        <input type="number" step="0.01" min="0" name="unit_price" id="pe-price" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                </div>
                <label id="pe-account-field" class="block" hidden><span class="label">Payment Account</span><select name="account_id" id="pe-account" class="field" disabled>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
                <div class="flex flex-wrap items-center justify-between gap-3 pt-2">
                    <button type="button" id="btn-delete-part" class="btn-secondary text-xs text-red-600 border-red-200 bg-red-50 hover:bg-red-100" onclick="workshopConfirm('Are you sure you want to remove this part?').then(ok => { if (ok) document.getElementById('part-delete-form').requestSubmit(); })">🗑 Delete Part</button>
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary text-xs" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('part-edit-modal').close()">Cancel</button>
                        <button type="submit" class="btn-primary text-xs">Save Changes</button>
                    </div>
                </div>
            </div>
        </form>
        <form id="part-delete-form" method="POST" action="" class="hidden">
            @csrf<x-submission-token/>
            @method('DELETE')
        </form>
    </div>
</dialog>

<dialog id="batch-modal" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 32rem !important;">
    <div class="p-6">
        <h2 class="text-xl font-black text-slate-900" style="color: #0f172a !important;">Select Stock Batch</h2>
        <p class="mb-4 text-sm text-slate-500" style="color: #64748b !important;">This product has stock across multiple price batches. Choose which batch to issue from.</p>
        <div id="batch-list" class="max-h-[60vh] space-y-3 overflow-y-auto"></div>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" class="btn-secondary" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('batch-modal').close()">Cancel</button>
        </div>
    </div>
</dialog>
<template id="batch-item-tpl">
    <label class="flex cursor-pointer items-center justify-between rounded-xl border-2 p-4 transition hover:border-blue-600 hover:bg-blue-50 [&:has(:checked)]:border-blue-600 [&:has(:checked)]:bg-blue-50" style="background-color: #ffffff !important; border-color: #e2e8f0 !important; color: #0f172a !important;">
        <div>
            <div class="flex items-center gap-2">
                <input type="radio" name="selected_batch" class="text-blue-600 focus:ring-blue-600">
                <span class="font-bold text-slate-900" data-batch-no style="color: #0f172a !important;"></span>
            </div>
            <p class="mt-1 text-xs text-slate-500" style="color: #64748b !important;">Cost: <span data-cost style="color: #0f172a !important; font-weight: bold;"></span> | Selling: <span data-selling style="color: #0f172a !important; font-weight: bold;"></span></p>
        </div>
        <div class="text-right">
            <span class="block text-xl font-black text-emerald-600" data-qty></span>
            <span class="text-[10px] uppercase tracking-wider text-slate-400" style="color: #94a3b8 !important;">Available</span>
        </div>
    </label>
</template>
<script>
(() => {
    const products = {{ Illuminate\Support\Js::from($productBatchOptions) }};
    const partsForm = document.getElementById('dynamic-parts-builder');
    if (!partsForm) return;
    const storeSel = document.getElementById('part-store');
    const productIdInput = document.getElementById('job-part-product').querySelector('[data-product-id]');
    const searchInput = document.getElementById('job-part-product').querySelector('[data-product-search]');
    const priceInput = document.getElementById('part-price');
    const qtyInput = document.getElementById('part-qty');
    const costLabel = document.getElementById('part-cost-label');
    const discountInput = document.getElementById('part-discount');
    const liveTotalSpan = document.getElementById('live-line-total');
    const batchIdInput = document.getElementById('part-batch');
    const batchMsg = document.getElementById('batch-msg');
    const btnAddPart = document.getElementById('btn-add-part');

    const modal = document.getElementById('batch-modal');
    const list = document.getElementById('batch-list');
    const tpl = document.getElementById('batch-item-tpl');
    const money = n => 'Rs. ' + Number(n || 0).toFixed(2);

    function calculateLiveLineTotal() {
        const qty = parseFloat(qtyInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        const discount = parseFloat(discountInput.value) || 0;
        const total = Math.max(0, (qty * price) - discount);
        liveTotalSpan.textContent = money(total);
        return total;
    }

    qtyInput.addEventListener('input', calculateLiveLineTotal);
    priceInput.addEventListener('input', calculateLiveLineTotal);
    discountInput.addEventListener('input', calculateLiveLineTotal);

    const checkBatch = () => {
        batchIdInput.value = '';
        batchMsg.textContent = '';
        costLabel.textContent = money(0);
        
        const pid = parseInt(productIdInput.value);
        if (!pid) return;
        
        const p = products.find(x => x.id === pid);
        if (!p) return;
        
        const storeId = +storeSel.value;
        const batches = p.batches.filter(b => b.store_id === storeId);
        
        priceInput.value = p.price;
        costLabel.textContent = money(p.cost);
        
        const currentStock = p.stocks ? (p.stocks[storeId] || 0) : 0;
        const minStock = p.minimum_stock || 0;
        let stockIndicator = '';
        if (currentStock <= 0) {
            stockIndicator = `<span class="text-red-600 block mt-1">🔴 Out of stock (Current stock: ${currentStock})</span>`;
        } else if (currentStock <= minStock) {
            stockIndicator = `<span class="text-amber-500 block mt-1">🟡 Low stock (Current stock: ${currentStock})</span>`;
        } else {
            stockIndicator = `<span class="text-emerald-600 block mt-1">🟢 In stock (Current stock: ${currentStock})</span>`;
        }
        
        const setBatchMsg = (msg) => {
            batchMsg.innerHTML = stockIndicator + (msg ? `<span class="text-indigo-600 block mt-1">${msg}</span>` : '');
        };
        setBatchMsg('');
        
        if (batches.length > 1) {
            list.innerHTML = '';
            batches.forEach(b => {
                const el = tpl.content.cloneNode(true);
                const radio = el.querySelector('input');
                radio.value = b.id;
                el.querySelector('[data-batch-no]').textContent = b.batch_no;
                el.querySelector('[data-cost]').textContent = money(b.cost);
                el.querySelector('[data-selling]').textContent = money(b.selling);
                el.querySelector('[data-qty]').textContent = b.qty;
                
                radio.addEventListener('change', () => {
                    batchIdInput.value = b.id;
                    priceInput.value = b.selling;
                    costLabel.textContent = money(b.cost);
                    setBatchMsg(`Selected Batch: ${b.batch_no}`);
                    modal.close();
                    calculateLiveLineTotal();
                });
                list.appendChild(el);
            });
            modal.showModal();
        } else if (batches.length === 1) {
            batchIdInput.value = batches[0].id;
            priceInput.value = batches[0].selling;
            costLabel.textContent = money(batches[0].cost);
            setBatchMsg(`Selected Batch: ${batches[0].batch_no}`);
        }
        calculateLiveLineTotal();
    };

    searchInput.addEventListener('input', () => setTimeout(checkBatch, 50));
    storeSel.addEventListener('change', checkBatch);

    partsForm.addEventListener('submit', event => {
        if (!productIdInput.value || !partsForm.checkValidity()) {
            event.preventDefault();
            partsForm.reportValidity();
            return;
        }

        btnAddPart.disabled = true;
        btnAddPart.textContent = 'Saving...';
    });

    if (productIdInput.value) checkBatch();
    calculateLiveLineTotal();

})();

function confirmDeletePart(partId) {
    const dialog = document.getElementById('delete-part-dialog');
    const form = document.getElementById('delete-part-form');
    const route = "{{ route('jobs.parts.destroy', ['job' => $job->id, 'part' => ':part']) }}";
    form.action = route.replace(':part', partId);
    dialog.showModal();
}

document.querySelectorAll('[data-edit-part]').forEach(button => button.addEventListener('click', () => openPartModal(JSON.parse(button.dataset.editPart))));

function openPartModal(details) {
    const {id: partId, name, cost, price, quantity: qty, note, external, account_id} = details;
    const modal = document.getElementById('part-edit-modal');
    const form = document.getElementById('part-edit-form');
    const delForm = document.getElementById('part-delete-form');
    
    // Set form action route dynamically
    const updateRoute = "{{ route('jobs.parts.update', ['job' => $job->id, 'part' => ':part']) }}";
    const deleteRoute = "{{ route('jobs.parts.destroy', ['job' => $job->id, 'part' => ':part']) }}";
    
    form.action = updateRoute.replace(':part', partId);
    delForm.action = deleteRoute.replace(':part', partId);
    
    document.getElementById('pe-name').value = name;
    document.getElementById('pe-note').value = note || '';
    document.getElementById('pe-account-field').hidden = !external;
    document.getElementById('pe-account').disabled = !external;
    if (external) document.getElementById('pe-account').value = account_id;
    document.getElementById('pe-cost').value = parseFloat(cost).toFixed(2);
    document.getElementById('pe-qty').value = parseFloat(qty);
    document.getElementById('pe-price').value = parseFloat(price);
    
    modal.showModal();
}

let currentEditingHiddenInputId = '';

function editName(defaultLabel, hiddenInputId) {
    currentEditingHiddenInputId = hiddenInputId;
    
    const hiddenInput = document.getElementById(hiddenInputId);
    const currentName = hiddenInput.value || defaultLabel;
    
    document.getElementById('edit-name-title').textContent = `Enter custom name for ${defaultLabel}`;
    document.getElementById('edit-name-input').value = currentName;
    document.getElementById('edit-name-dialog').showModal();
}

function saveEditedName() {
    const editInput = document.getElementById('edit-name-input');
    if (!editInput.reportValidity()) return;
    const newName = editInput.value.trim();
    if (newName !== "") {
        const input = document.getElementById(currentEditingHiddenInputId);
        input.value = newName;
        input.dispatchEvent(new Event('input', {bubbles: true}));
    }
    document.getElementById('edit-name-dialog').close();
}
</script>

<x-job-due-payment-dialog :accounts="$accounts"/>
@include('jobs.partials.payment-edit-dialog')
<script>
function openUnlockModal() {
    document.getElementById('unlock-job-dialog').showModal();
}
</script>

{{-- Unlock Job Dialog --}}
<dialog id="delete-part-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 24rem !important;">
    <div class="p-6 text-center space-y-4">
        <div class="mx-auto flex items-center justify-center size-12 rounded-full bg-red-100 mb-4">
            <svg class="size-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
        </div>
        <h3 class="text-lg font-bold text-slate-900" style="color: #0f172a !important;">Remove Part?</h3>
        <p class="text-sm text-slate-500" style="color: #64748b !important;">Remove this part from the job? Stock parts return to inventory; outer product expenses are reversed.</p>
        <form id="delete-part-form" method="POST" action="" class="mt-6 flex justify-center gap-3">
            @csrf<x-submission-token/>
            @method('DELETE')
            <button type="button" class="btn-secondary text-sm px-4" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('delete-part-dialog').close()">Cancel</button>
            <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700 text-sm px-4 shadow-red-200" style="background-color: #dc2626 !important;">Yes, Remove it</button>
        </form>
    </div>
</dialog>
<dialog id="unlock-job-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important; max-height: 90vh; overflow-y: auto;">
    <form method="POST" action="{{ route('jobs.unlock', $job) }}" class="p-6">
        @csrf<x-submission-token/>
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-amber-600">Reopen Job Card</p>
                <h2 class="mt-1 text-xl font-black text-slate-900" style="color: #0f172a !important;">Unlock Job Card</h2>
            </div>
            <button type="button" onclick="document.getElementById('unlock-job-dialog').close()" class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl text-slate-500 hover:bg-slate-200">×</button>
        </div>
        <div class="mt-4 grid gap-4">
            <label class="block">
                <span class="label" style="color: #475569 !important;">Change Status To *</span>
                <select name="status" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
                    <option value="In Progress">In Progress</option>
                    <option value="Diagnosis">Diagnosis</option>
                    <option value="Waiting for Parts">Waiting for Parts</option>
                    <option value="On Hold">On Hold</option>
                    <option value="Repair Ready">Repair Ready</option>
                    <option value="Ready for Delivery">Ready for Delivery</option>
                </select>
            </label>
            <label class="block">
                <span class="label" style="color: #475569 !important;">Reason for Changes *</span>
                <textarea name="reason" rows="3" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" placeholder="Enter the reason why you are modifying this billed/completed job card..." required></textarea>
            </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('unlock-job-dialog').close()" class="btn-secondary" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;">Cancel</button>
            <button class="btn-primary" style="background-color: #d97706 !important;">Unlock & Edit</button>
        </div>
    </form>
</dialog>
{{-- Edit Name Dialog --}}
<dialog id="edit-name-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important; max-height: 90vh; overflow-y: auto;">
    <div class="p-6">
        <div class="flex justify-between items-center border-b border-slate-100 pb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-blue-600">Edit Custom Name</p>
                <h2 class="mt-1 text-xl font-black text-slate-900" id="edit-name-title" style="color: #0f172a !important;">Enter custom name</h2>
            </div>
            <button type="button" onclick="document.getElementById('edit-name-dialog').close()" class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl text-slate-500 hover:bg-slate-200">×</button>
        </div>
        <div class="mt-4 grid gap-4">
            <label class="block">
                <span class="label" style="color: #475569 !important;">Custom Name *</span>
                <input type="text" id="edit-name-input" maxlength="50" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
            </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('edit-name-dialog').close()" class="btn-secondary" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;">Cancel</button>
            <button type="button" onclick="saveEditedName()" class="btn-primary" style="background-color: #2563eb !important;">Save Name</button>
        </div>
    </div>
</dialog>

@if(session('print_invoice'))
<dialog id="print-invoice-dialog" class="backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm bg-transparent w-full max-w-sm mx-auto p-0 rounded-3xl shadow-2xl overflow-hidden transition-all open:animate-in open:fade-in open:zoom-in-95">
    <div class="bg-white">
        <div class="p-6 text-center">
            <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                <svg class="size-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
            </div>
            <h3 class="text-xl font-black text-slate-800 mb-2">Invoice Generated!</h3>
            <p class="text-sm font-bold text-slate-500 mb-6">Print or view the bill now?</p>
            <div class="grid grid-cols-2 gap-3 mb-3">
                <button onclick="openPrint('80mm')" type="button" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition text-sm">Print 80mm</button>
                <button onclick="openPrint('a5')" type="button" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition text-sm">Print A5</button>
            </div>
            <div class="flex gap-3">
                <button onclick="document.getElementById('print-invoice-dialog').close()" type="button" class="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition text-sm"><span class="block text-[9px]">Esc</span>Close</button>
                <button onclick="openPrint('a4')" type="button" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition text-sm"><span class="block text-[9px]">Alt+P</span>Print A4</button>
                <a href="{{ route('invoices.public.pdf', ['token' => session('print_invoice'), 'hide_total_balance_due' => 1]) }}" target="_blank" onclick="document.getElementById('print-invoice-dialog').close()" class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl transition text-sm flex flex-col items-center justify-center"><span class="block text-[9px]">Alt+D</span>PDF</a>
            </div>
        </div>
    </div>
</dialog>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('print-invoice-dialog').showModal();
    });
    function openPrint(format) {
        const token = "{{ session('print_invoice') }}";
        const url = `{{ url('invoice') }}/${token}?print=1&format=${format}&hide_total_balance_due=1`;
        window.open(url, '_blank');
        document.getElementById('print-invoice-dialog').close();
    }
</script>
@endif
</div></x-layouts::app>
