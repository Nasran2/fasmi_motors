<x-layouts::app title="{{ $job->number }}">
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
  $isLocked = in_array($job->status, ['Completed', 'Billed', 'Delivered']);
@endphp
<div class="space-y-5"><div class="flex flex-wrap items-end justify-between gap-3"><div><h1 class="page-title">{{ $job->number }} · {{ $job->intake->bike->number }}</h1><p class="page-subtitle">{{ $job->intake->bike->model }} · {{ $job->intake->bike->customer->name ?: 'Walk-in customer' }} · {{ $job->intake->bike->customer->phone }}</p></div><span class="rounded-full px-3 py-1 text-sm font-extrabold {{ $statusColors[$job->status] ?? 'bg-slate-100 text-slate-700' }}">{{ $job->status }}</span></div><x-flash/>
<div class="grid gap-5 xl:grid-cols-3"><div class="panel xl:col-span-2"><h2 class="mb-4 font-bold">Work details</h2><div class="rounded-xl p-4 border border-slate-200" style="background-color: #f8fafc !important; color: #1e293b !important;"><span class="block text-[11px] font-bold text-slate-400 uppercase tracking-wider mb-1">Customer Complaint</span><p class="text-sm font-semibold text-slate-800" style="color: #1e293b !important;">{{ $job->intake->complaint }}</p></div><form method="POST" action="{{ route('jobs.update',$job) }}" class="mt-4 grid gap-4 sm:grid-cols-2">@csrf<x-submission-token/> @method('PUT')<label><span class="label">Status</span><select name="status" class="field">
    @foreach($statuses as $s)
        <option @selected($job->status===$s)>{{ $s }}</option>
    @endforeach
</select></label><label><span class="label">Main technician</span><select name="main_technician_id" class="field" @disabled($isLocked)><option value="">Unassigned</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected($job->main_technician_id===$t->id)>{{ $t->name }}</option>@endforeach</select></label><label class="sm:col-span-2"><span class="label">Change reason / notes</span><input name="change_reason" class="field" placeholder="Enter reason for status change (optional)..."></label>

<div class="relative">
    <input type="hidden" name="service_name" id="hidden_service_name" value="{{ $job->service_name ?: 'Service' }}">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0 flex items-center gap-1"><span id="lbl_service_name">{{ $job->service_name ?: 'Service' }}</span> (LKR) <button type="button" onclick="editName('Service', 'hidden_service_name', 'lbl_service_name')" class="text-blue-500 hover:text-blue-700" @disabled($isLocked)>✏️</button></span></label>
    <input type="number" step=".01" name="service_total" value="{{ $job->service_total }}" class="field" @disabled($isLocked)>
</div>

<div class="relative">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0">Labor (LKR)</span><span class="flex items-center gap-1 text-[10px] text-slate-500 font-bold"><input type="checkbox" name="show_labor_on_bill" value="1" class="size-3 accent-red-600" @checked($job->show_labor_on_bill) @disabled($isLocked)> Show on bill</span></label>
    <input type="number" step=".01" name="labor_total" value="{{ $job->labor_total }}" class="field" @disabled($isLocked)>
</div>

<div class="relative">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0">Washing (LKR)</span><span class="flex items-center gap-1 text-[10px] text-slate-500 font-bold"><input type="checkbox" name="show_washing_on_bill" value="1" class="size-3 accent-red-600" @checked($job->show_washing_on_bill) @disabled($isLocked)> Show on bill</span></label>
    <input type="number" step=".01" name="washing_total" value="{{ $job->washing_total }}" class="field" @disabled($isLocked)>
</div>

<div class="relative">
    <input type="hidden" name="other_income_name" id="hidden_other_income_name" value="{{ $job->other_income_name ?: 'Other Income' }}">
    <label class="flex justify-between items-end mb-1.5"><span class="label !mb-0 flex items-center gap-1"><span id="lbl_other_income_name">{{ $job->other_income_name ?: 'Other Income' }}</span> (LKR) <button type="button" onclick="editName('Other Income', 'hidden_other_income_name', 'lbl_other_income_name')" class="text-blue-500 hover:text-blue-700" @disabled($isLocked)>✏️</button></span></label>
    <input type="number" step=".01" name="other_total" value="{{ $job->other_total }}" class="field" @disabled($isLocked)>
</div>

<label><span class="label">Discount (LKR)</span><input type="number" step=".01" name="discount" value="{{ $job->discount }}" class="field" @disabled($isLocked)></label>
<label><span class="label">Tax (LKR)</span><input type="number" step=".01" name="tax" value="{{ $job->tax }}" class="field" @disabled($isLocked)></label>

<div class="sm:col-span-2 flex flex-col sm:flex-row justify-between items-center gap-3 mt-2">@if($isLocked)<button type="button" onclick="openUnlockModal()" class="w-full sm:w-auto btn-primary bg-amber-500 hover:bg-amber-600 text-white font-extrabold text-xs py-3 px-4 shadow-lg shadow-amber-100 transition-colors order-2 sm:order-1">🔓 Request Changes / Edit Job</button><button class="w-full sm:w-auto btn-primary bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold text-xs py-3 px-6 shadow-lg shadow-emerald-100 transition-colors order-1 sm:order-2">Update Status</button>@else<button class="btn-primary">Update job</button>@endif</div></form></div>
<div class="space-y-4">
    <div class="panel">
        <h2 class="mb-4 font-bold">Bill summary</h2>
        <dl class="space-y-2 text-sm">
            <div class="flex justify-between"><dt>Parts</dt><dd>Rs. {{ number_format($job->parts_total,2) }}</dd></div>
            <div class="flex justify-between"><dt>Service & labor</dt><dd>Rs. {{ number_format($job->service_total+$job->labor_total+$job->washing_total+$job->other_total,2) }}</dd></div>
            <div class="flex justify-between"><dt>Discount</dt><dd>- Rs. {{ number_format($job->discount,2) }}</dd></div>
            <div class="flex justify-between border-t pt-3 text-lg font-bold"><dt>Total</dt><dd>Rs. {{ number_format($job->total,2) }}</dd></div>
            @if($job->invoice)
            <div class="flex justify-between border-t border-dashed pt-2 text-xs font-semibold text-slate-500"><dt>Paid</dt><dd class="text-emerald-600 font-bold">Rs. {{ number_format($job->invoice->paid_amount,2) }}</dd></div>
            <div class="flex justify-between text-xs font-semibold text-slate-500"><dt>Due</dt><dd class="text-red-600 font-bold">Rs. {{ number_format($job->invoice->due_amount,2) }}</dd></div>
            @endif
        </dl>
        @if($job->invoice)
        <div class="flex flex-col gap-2 mt-5">
            <a target="_blank" href="{{ route('invoices.public',$job->invoice->public_token) }}" class="btn-primary w-full text-center">View {{ $job->invoice->number }}</a>
            @if($job->invoice->due_amount > 0)
            <button type="button" onclick="openDuePaymentModal({{ $job->invoice->id }}, {{ $job->invoice->due_amount }}, '{{ $job->invoice->number }}')" class="btn-primary w-full bg-emerald-600 hover:bg-emerald-700 text-white font-extrabold flex items-center justify-center gap-2 shadow-lg shadow-emerald-100 transition-colors">
                💰 Pay Bill
            </button>
            @endif
        </div>
        @else
        <form method="POST" action="{{ route('jobs.invoice',$job) }}" class="mt-5 space-y-3">
            @csrf<x-submission-token/>
            <input type="number" step=".01" min="0" max="{{ $job->total }}" name="paid_amount" value="0" class="field" placeholder="Paid amount">
            <select name="payment_method" class="field">
                @foreach(['Cash','Card','Bank Transfer','Cheque','QR','Credit/Due'] as $m)
                <option>{{ $m }}</option>
                @endforeach
            </select>
            <button class="btn-primary w-full">Generate invoice</button>
        </form>
        @endif
    </div>

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
            @if($job->intake->bike->chassis_no)
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Chassis Number</span>
                <span class="font-mono text-slate-700">{{ $job->intake->bike->chassis_no }}</span>
            </div>
            @endif
            @if($job->intake->bike->engine_no)
            <div>
                <span class="block text-[10px] text-slate-400 font-bold uppercase tracking-wider">Engine Number</span>
                <span class="font-mono text-slate-700">{{ $job->intake->bike->engine_no }}</span>
            </div>
            @endif
        </div>
    </div>
</div></div>
<div id="spare-parts" class="panel scroll-mt-24">
    <div class="mb-4 flex justify-between">
        <h2 class="font-extrabold text-slate-800">⚙️ Spare Parts</h2>
    </div>

    @if($isLocked)
    <div class="rounded-xl border border-dashed border-slate-200 bg-slate-50 p-6 text-center mb-5" style="background-color: #f8fafc !important; border: 1px dashed #cbd5e1 !important;">
        <p class="text-xs font-extrabold text-slate-500">⚙️ Spare parts list is locked because this Job Card is Billed or Completed. To edit spare parts, click **Request Changes / Edit Job** in the Work details panel.</p>
    </div>
    @else
    {{-- POS Aligned Form Layout --}}
    <div id="dynamic-parts-builder" class="mb-5 border border-slate-100 bg-slate-50/30 p-4 rounded-xl">
        <div class="grid gap-3 grid-cols-2 md:grid-cols-6 items-end">
            <div class="col-span-2">
                <x-searchable-product-field :products="$products" id="job-part-product"/>
                <div id="batch-msg" class="mt-1 text-xs font-bold text-indigo-600 empty:hidden"></div>
            </div>
            <div>
                <label class="label">Store *</label>
                <select id="part-store" class="w-full">
                    @foreach($stores as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="label">Quantity *</label>
                <input id="part-qty" type="number" step="any" class="w-full" placeholder="Qty" value="1">
            </div>
            <div>
                <label class="label">Unit Price *</label>
                <input id="part-price" type="number" step=".01" class="w-full" placeholder="Price">
            </div>
            <div>
                <label class="label">Discount</label>
                <input id="part-discount" type="number" step=".01" class="w-full" placeholder="Discount" value="0">
            </div>
            <input type="hidden" id="part-batch">
            
            <div class="col-span-2 md:col-span-6 flex flex-wrap items-center justify-between bg-white border border-slate-200 p-3 rounded-xl mt-2 gap-3 shadow-sm">
                <div class="text-xs font-bold text-slate-500">
                    Line Total: <span id="live-line-total" class="text-base font-black text-green-600">Rs. 0.00</span>
                </div>
                <button type="button" id="btn-add-pending" class="btn-secondary !min-h-9 !py-1 !px-4 text-blue-700 bg-blue-50 border-blue-200 hover:bg-blue-100">+ Add to list</button>
            </div>
        </div>

        {{-- Pending Parts Table --}}
        <form method="POST" action="{{ route('jobs.parts.store_multiple', $job) }}" id="multiple-parts-form" class="mt-6 hidden">
            @csrf<x-submission-token/>
            <h3 class="text-sm font-bold text-slate-700 mb-2">📋 Pending Parts to Save</h3>
            <div class="overflow-x-auto rounded-lg border border-slate-200 bg-white">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500">
                        <tr>
                            <th class="p-2">Product</th>
                            <th class="p-2">Store</th>
                            <th class="p-2 text-right">Qty</th>
                            <th class="p-2 text-right">Unit Price</th>
                            <th class="p-2 text-right">Discount</th>
                            <th class="p-2 text-right">Total</th>
                            <th class="p-2 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="pending-parts-list" class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
            <div class="mt-3 flex justify-between items-center">
                <div class="text-sm font-bold text-slate-600">Total Pending: <span id="pending-grand-total" class="text-green-600">Rs. 0.00</span></div>
                <button type="submit" class="btn-primary">Save All Parts</button>
            </div>
        </form>
    </div>
    @endif

    {{-- Issued Parts List --}}
    <div class="table-wrap">
        <table class="data-table text-xs">
            <thead>
                <tr>
                    <th class="font-bold">{{ $isLocked ? 'Product Name' : 'Product Name (Click to edit)' }}</th>
                    <th class="font-bold">Store</th>
                    <th class="font-bold text-center">Qty</th>
                    <th class="font-bold text-right">Price</th>
                    <th class="font-bold text-right">Total</th>
                    <th class="font-bold text-center">Status</th>
                    @if(!$isLocked)
                    <th class="font-bold text-center">Action</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @forelse($job->parts as $part)
                <tr>
                    <td>
                        @if($isLocked)
                        <span class="font-extrabold text-slate-755 text-slate-700">{{ $part->product->name }}</span>
                        @else
                        <button type="button" onclick="openPartModal('{{ $part->id }}', '{{ $part->product->name }}', '{{ $part->cost_price }}', '{{ $part->unit_price }}', '{{ $part->quantity }}')" class="font-extrabold text-blue-600 hover:underline text-left">
                            {{ $part->product->name }}
                        </button>
                        @endif
                    </td>
                    <td>{{ $stores->firstWhere('id',$part->store_id)?->name }}</td>
                    <td class="text-center font-mono">{{ $part->quantity }}</td>
                    <td class="text-right font-mono">Rs. {{ number_format($part->unit_price, 2) }}</td>
                    <td class="text-right font-bold text-slate-800">Rs. {{ number_format($part->total, 2) }}</td>
                    @php $currentStock = $part->product->stocks->where('store_id', $part->store_id)->first()?->quantity ?? 0; @endphp
                    <td class="text-center {{ $currentStock < 0 ? 'text-red-600 font-semibold' : 'text-slate-500' }}">
                        @if($currentStock < 0)
                            <span class="text-xs">Out of stock ({{ $currentStock }})</span>
                        @else
                            <span class="text-xs">Issued (Rem: {{ $currentStock }})</span>
                        @endif
                    </td>
                    @if(!$isLocked)
                    <td class="text-center">
                        <button type="button" onclick="confirmDeletePart('{{ $part->id }}')" class="text-red-500 hover:text-red-700 bg-red-50 hover:bg-red-100 p-1.5 rounded font-bold transition-colors" title="Delete part">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path><path d="M10 11v6"></path><path d="M14 11v6"></path></svg>
                            </button>
                    </td>
                    @endif
                </tr>
                @empty
                <tr>
                    <td colspan="{{ $isLocked ? 6 : 7 }}" class="text-center py-6 text-slate-400">No parts added yet.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="panel mt-4">
    <h2 class="mb-4 font-bold text-slate-800 flex items-center gap-2">
        <span>⏱️ Status History & Change Logs</span>
    </h2>
    <div class="flow-root">
        <ul role="list" class="-mb-8">
            @forelse($statusLogs as $index => $log)
            <li>
                <div class="relative pb-8">
                    @if($index !== count($statusLogs) - 1)
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
                                <p class="text-xs text-slate-600">
                                    Status changed from <span class="font-extrabold text-slate-500">{{ $log->old_status }}</span> to <span class="font-extrabold text-emerald-600">{{ $log->new_status }}</span> by <span class="font-bold text-slate-800">{{ $log->changer_name ?: 'System' }}</span>
                                </p>
                                @if($log->note)
                                <p class="mt-1 text-[11px] font-semibold text-slate-500 bg-slate-50 border border-slate-100 rounded p-1.5 inline-block">
                                    Note: {{ $log->note }}
                                </p>
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

{{-- Price Edit Modal --}}
<dialog id="part-edit-modal" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Edit Part Details</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('part-edit-modal').close()">✕</button>
        </div>
        <form id="part-edit-form" method="POST" action="">
            @csrf<x-submission-token/>
            <div class="space-y-4">
                <div>
                    <label class="label" style="color: #475569 !important;">Product Name</label>
                    <div id="pe-name" class="text-xs font-bold text-slate-800 border p-2.5 rounded-lg" style="background-color: #f8fafc !important; color: #1e293b !important; border-color: #cbd5e1 !important;"></div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="label" style="color: #475569 !important;">Cost Price</label>
                        <div id="pe-cost" class="text-xs font-bold text-slate-800 border p-2.5 rounded-lg font-mono" style="background-color: #f8fafc !important; color: #1e293b !important; border-color: #cbd5e1 !important;"></div>
                    </div>
                    <div>
                        <label class="label" style="color: #475569 !important;">Quantity *</label>
                        <input type="number" step="any" min="0.01" name="quantity" id="pe-qty" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                    <div>
                        <label class="label" style="color: #475569 !important;">Unit Price *</label>
                        <input type="number" step="0.01" min="0" name="unit_price" id="pe-price" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                </div>
                <div class="flex items-center justify-between pt-2">
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
    const stores = {{ Illuminate\Support\Js::from($stores) }};
    const storeSel = document.getElementById('part-store');
    const productIdInput = document.getElementById('job-part-product').querySelector('[data-product-id]');
    const searchInput = document.getElementById('job-part-product').querySelector('[data-product-search]');
    const priceInput = document.getElementById('part-price');
    const qtyInput = document.getElementById('part-qty');
    const discountInput = document.getElementById('part-discount');
    const liveTotalSpan = document.getElementById('live-line-total');
    const batchIdInput = document.getElementById('part-batch');
    const batchMsg = document.getElementById('batch-msg');
    const btnAddPending = document.getElementById('btn-add-pending');
    
    const multiplePartsForm = document.getElementById('multiple-parts-form');
    const pendingPartsList = document.getElementById('pending-parts-list');
    const pendingGrandTotal = document.getElementById('pending-grand-total');

    const modal = document.getElementById('batch-modal');
    const list = document.getElementById('batch-list');
    const tpl = document.getElementById('batch-item-tpl');
    const money = n => 'Rs. ' + Number(n || 0).toFixed(2);

    let pendingParts = [];
    let partIndex = 0;

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
        
        const pid = parseInt(productIdInput.value);
        if (!pid) return;
        
        const p = products.find(x => x.id === pid);
        if (!p) return;
        
        const storeId = +storeSel.value;
        const batches = p.batches.filter(b => b.store_id === storeId);
        
        priceInput.value = p.price;
        
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
            setBatchMsg(`Selected Batch: ${batches[0].batch_no}`);
        }
        calculateLiveLineTotal();
    };

    searchInput.addEventListener('input', () => setTimeout(checkBatch, 50));
    storeSel.addEventListener('change', checkBatch);

    function updatePendingTable() {
        pendingPartsList.innerHTML = '';
        let grandTotal = 0;
        
        if (pendingParts.length > 0) {
            multiplePartsForm.classList.remove('hidden');
        } else {
            multiplePartsForm.classList.add('hidden');
        }

        pendingParts.forEach((part, idx) => {
            grandTotal += part.total;
            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td class="p-2 font-bold text-slate-700">${part.productName}
                    <input type="hidden" name="parts[${idx}][product_id]" value="${part.productId}">
                </td>
                <td class="p-2 text-slate-500">${part.storeName}
                    <input type="hidden" name="parts[${idx}][store_id]" value="${part.storeId}">
                </td>
                <td class="p-2 text-right font-mono">${part.qty}
                    <input type="hidden" name="parts[${idx}][quantity]" value="${part.qty}">
                </td>
                <td class="p-2 text-right font-mono">${money(part.price)}
                    <input type="hidden" name="parts[${idx}][unit_price]" value="${part.price}">
                </td>
                <td class="p-2 text-right font-mono text-red-500">${part.discount > 0 ? money(part.discount) : '-'}
                    <input type="hidden" name="parts[${idx}][discount]" value="${part.discount}">
                </td>
                <td class="p-2 text-right font-bold text-slate-800">${money(part.total)}
                    <input type="hidden" name="parts[${idx}][stock_batch_id]" value="${part.batchId || ''}">
                </td>
                <td class="p-2 text-center">
                    <button type="button" class="text-red-500 hover:text-red-700 font-bold px-2 py-1 bg-red-50 rounded" onclick="removePendingPart(${part.id})">✕</button>
                </td>
            `;
            pendingPartsList.appendChild(tr);
        });
        
        pendingGrandTotal.textContent = money(grandTotal);
    }

    window.removePendingPart = (id) => {
        pendingParts = pendingParts.filter(p => p.id !== id);
        updatePendingTable();
    };

    if(btnAddPending) {
        btnAddPending.addEventListener('click', () => {
            const pid = parseInt(productIdInput.value);
            if (!pid) {
                workshopNotify('Please search and select a product first.');
                return searchInput.focus();
            }
            
            const p = products.find(x => x.id === pid);
            const storeId = +storeSel.value;
            const storeName = stores.find(s => s.id === storeId)?.name || 'Store';
            const qty = parseFloat(qtyInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const discount = parseFloat(discountInput.value) || 0;
            const batchId = batchIdInput.value;
            const total = calculateLiveLineTotal();

            if (qty <= 0) {
                workshopNotify('Quantity must be greater than 0.');
                return qtyInput.focus();
            }

            pendingParts.push({
                id: ++partIndex,
                productId: pid,
                productName: p.name,
                storeId: storeId,
                storeName: storeName,
                qty: qty,
                price: price,
                discount: discount,
                batchId: batchId,
                total: total
            });

            updatePendingTable();

            // Reset inputs
            searchInput.value = '';
            productIdInput.value = '';
            batchIdInput.value = '';
            batchMsg.textContent = '';
            qtyInput.value = '1';
            priceInput.value = '';
            discountInput.value = '0';
            calculateLiveLineTotal();
            searchInput.focus();
        });
    }

})();

function confirmDeletePart(partId) {
    const dialog = document.getElementById('delete-part-dialog');
    const form = document.getElementById('delete-part-form');
    const route = "{{ route('jobs.parts.destroy', ['job' => $job->id, 'part' => ':part']) }}";
    form.action = route.replace(':part', partId);
    dialog.showModal();
}

function openPartModal(partId, name, cost, price, qty) {
    const modal = document.getElementById('part-edit-modal');
    const form = document.getElementById('part-edit-form');
    const delForm = document.getElementById('part-delete-form');
    
    // Set form action route dynamically
    const updateRoute = "{{ route('jobs.parts.update', ['job' => $job->id, 'part' => ':part']) }}";
    const deleteRoute = "{{ route('jobs.parts.destroy', ['job' => $job->id, 'part' => ':part']) }}";
    
    form.action = updateRoute.replace(':part', partId);
    delForm.action = deleteRoute.replace(':part', partId);
    
    document.getElementById('pe-name').textContent = name;
    document.getElementById('pe-cost').textContent = 'Rs. ' + parseFloat(cost).toFixed(2);
    document.getElementById('pe-qty').value = parseFloat(qty);
    document.getElementById('pe-price').value = parseFloat(price);
    
    modal.showModal();
}

let currentEditingHiddenInputId = '';
let currentEditingLabelId = '';

function editName(defaultLabel, hiddenInputId, labelId) {
    currentEditingHiddenInputId = hiddenInputId;
    currentEditingLabelId = labelId;
    
    const hiddenInput = document.getElementById(hiddenInputId);
    const currentName = hiddenInput.value || defaultLabel;
    
    document.getElementById('edit-name-title').textContent = `Enter custom name for ${defaultLabel}`;
    document.getElementById('edit-name-input').value = currentName;
    document.getElementById('edit-name-dialog').showModal();
}

function saveEditedName() {
    const newName = document.getElementById('edit-name-input').value.trim();
    if (newName !== "") {
        document.getElementById(currentEditingHiddenInputId).value = newName;
        document.getElementById(currentEditingLabelId).textContent = newName;
    }
    document.getElementById('edit-name-dialog').close();
}
</script>

{{-- Due Payment Dialog --}}
<dialog id="due-payment-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <form method="POST" id="due-payment-form" class="p-6">
        @csrf<x-submission-token/>
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
        <p class="text-sm text-slate-500" style="color: #64748b !important;">Are you sure you want to remove this part from the job card? This action cannot be undone.</p>
        <form id="delete-part-form" method="POST" action="" class="mt-6 flex justify-center gap-3">
            @csrf<x-submission-token/>
            @method('DELETE')
            <button type="button" class="btn-secondary text-sm px-4" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('delete-part-dialog').close()">Cancel</button>
            <button type="submit" class="btn-primary bg-red-600 hover:bg-red-700 text-sm px-4 shadow-red-200" style="background-color: #dc2626 !important;">Yes, Remove it</button>
        </form>
    </div>
</dialog>
<dialog id="unlock-job-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
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
<dialog id="edit-name-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
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
                <input type="text" id="edit-name-input" class="w-full bg-white text-slate-900 p-2.5 rounded-lg border border-slate-200" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;" required>
            </label>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" onclick="document.getElementById('edit-name-dialog').close()" class="btn-secondary" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;">Cancel</button>
            <button type="button" onclick="saveEditedName()" class="btn-primary" style="background-color: #2563eb !important;">Save Name</button>
        </div>
    </div>
</dialog>
</div></x-layouts::app>
