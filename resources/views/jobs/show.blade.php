<x-layouts::app title="{{ $job->number }}"><div class="space-y-5"><div class="flex flex-wrap items-end justify-between gap-3"><div><h1 class="page-title">{{ $job->number }} · {{ $job->intake->bike->number }}</h1><p class="page-subtitle">{{ $job->intake->bike->model }} · {{ $job->intake->bike->customer->name ?: 'Walk-in customer' }} · {{ $job->intake->bike->customer->phone }}</p></div><span class="rounded-full bg-amber-100 px-3 py-1 text-sm font-semibold text-amber-900">{{ $job->status }}</span></div><x-flash/>
<div class="grid gap-5 xl:grid-cols-3"><div class="panel xl:col-span-2"><h2 class="mb-4 font-bold">Work details</h2><p class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800"><span class="block text-xs text-zinc-500">Customer complaint</span>{{ $job->intake->complaint }}</p><form method="POST" action="{{ route('jobs.update',$job) }}" class="mt-4 grid gap-4 sm:grid-cols-2">@csrf @method('PUT')<label><span class="label">Status</span><select name="status" class="field">@foreach($statuses as $s)<option @selected($job->status===$s)>{{ $s }}</option>@endforeach</select></label><label><span class="label">Main technician</span><select name="main_technician_id" class="field"><option value="">Unassigned</option>@foreach($technicians as $t)<option value="{{ $t->id }}" @selected($job->main_technician_id===$t->id)>{{ $t->name }}</option>@endforeach</select></label><label class="sm:col-span-2"><span class="label">Change reason / notes</span><input name="change_reason" class="field"></label>@foreach(['service_total'=>'Service','labor_total'=>'Labor','washing_total'=>'Washing','other_total'=>'Other income','discount'=>'Discount','tax'=>'Tax'] as $name=>$label)<label><span class="label">{{ $label }} (LKR)</span><input type="number" step=".01" name="{{ $name }}" value="{{ $job->$name }}" class="field"></label>@endforeach<div class="sm:col-span-2 text-right"><button class="btn-primary">Update job</button></div></form></div>
<div class="panel"><h2 class="mb-4 font-bold">Bill summary</h2><dl class="space-y-2 text-sm"><div class="flex justify-between"><dt>Parts</dt><dd>Rs. {{ number_format($job->parts_total,2) }}</dd></div><div class="flex justify-between"><dt>Service & labor</dt><dd>Rs. {{ number_format($job->service_total+$job->labor_total+$job->washing_total+$job->other_total,2) }}</dd></div><div class="flex justify-between"><dt>Discount</dt><dd>- Rs. {{ number_format($job->discount,2) }}</dd></div><div class="flex justify-between border-t pt-3 text-lg font-bold"><dt>Total</dt><dd>Rs. {{ number_format($job->total,2) }}</dd></div></dl>@if($job->invoice)<a target="_blank" href="{{ route('invoices.public',$job->invoice->public_token) }}" class="btn-primary mt-5 w-full">View {{ $job->invoice->number }}</a>@else<form method="POST" action="{{ route('jobs.invoice',$job) }}" class="mt-5 space-y-3">@csrf<input type="number" step=".01" min="0" max="{{ $job->total }}" name="paid_amount" value="0" class="field" placeholder="Paid amount"><select name="payment_method" class="field">@foreach(['Cash','Card','Bank Transfer','Cheque','QR','Credit/Due'] as $m)<option>{{ $m }}</option>@endforeach</select><button class="btn-primary w-full">Generate invoice</button></form>@endif</div></div>
<div class="panel"><div class="mb-4 flex justify-between"><h2 class="font-bold">Spare parts</h2></div><form method="POST" action="{{ route('jobs.parts.store',$job) }}" id="job-part-form" class="mb-5 grid gap-3 md:grid-cols-5">@csrf<div class="md:col-span-2"><x-searchable-product-field :products="$products" id="job-part-product"/><div id="batch-msg" class="mt-1 text-xs font-bold text-indigo-600 empty:hidden"></div></div><select name="store_id" class="field" required>@foreach($stores as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select><input name="quantity" type="number" step=".01" class="field" placeholder="Qty" required><input name="unit_price" type="number" step=".01" class="field" placeholder="Unit price" required><input name="discount" type="number" step=".01" class="field" placeholder="Discount"><input type="hidden" name="stock_batch_id"><button class="btn-primary md:col-span-2">Add / issue part</button></form><div class="table-wrap"><table class="data-table"><thead><tr><th>Product</th><th>Store</th><th>Qty</th><th>Price</th><th>Total</th><th>Stock</th></tr></thead><tbody>@forelse($job->parts as $part)<tr><td>{{ $part->product->name }}</td><td>{{ $stores->firstWhere('id',$part->store_id)?->name }}</td><td>{{ $part->quantity }}</td><td>{{ number_format($part->unit_price,2) }}</td><td>{{ number_format($part->total,2) }}</td><td class="{{ $part->out_of_stock?'text-red-600 font-semibold':'' }}">{{ $part->out_of_stock?'Out of stock':'Issued' }}</td></tr>@empty<tr><td colspan="6" class="text-center">No parts added.</td></tr>@endforelse</tbody></table></div></div></div>
<dialog id="batch-modal" class="m-auto rounded-2xl p-0 shadow-2xl backdrop:bg-slate-900/50 open:animate-in open:fade-in open:zoom-in-95">
    <div class="w-full max-w-lg p-6">
        <h2 class="text-xl font-black text-slate-900">Select Stock Batch</h2>
        <p class="mb-4 text-sm text-slate-500">This product has stock across multiple price batches. Choose which batch to issue from.</p>
        <div id="batch-list" class="max-h-[60vh] space-y-3 overflow-y-auto"></div>
        <div class="mt-6 flex justify-end gap-3">
            <button type="button" class="btn-secondary" onclick="document.getElementById('batch-modal').close()">Cancel</button>
        </div>
    </div>
</dialog>
<template id="batch-item-tpl">
    <label class="flex cursor-pointer items-center justify-between rounded-xl border-2 border-slate-100 p-4 transition hover:border-blue-600 hover:bg-blue-50 [&:has(:checked)]:border-blue-600 [&:has(:checked)]:bg-blue-50">
        <div>
            <div class="flex items-center gap-2">
                <input type="radio" name="selected_batch" class="text-blue-600 focus:ring-blue-600">
                <span class="font-bold text-slate-900" data-batch-no></span>
            </div>
            <p class="mt-1 text-xs text-slate-500">Cost: <span data-cost></span> | Selling: <span data-selling></span></p>
        </div>
        <div class="text-right">
            <span class="block text-xl font-black text-emerald-600" data-qty></span>
            <span class="text-[10px] uppercase tracking-wider text-slate-400">Available</span>
        </div>
    </label>
</template>
<script>
(() => {
    const products = {{ Illuminate\Support\Js::from($productBatchOptions) }};
    const form = document.getElementById('job-part-form');
    const storeSel = form.querySelector('[name="store_id"]');
    const productIdInput = document.getElementById('job-part-product').querySelector('[data-product-id]');
    const searchInput = document.getElementById('job-part-product').querySelector('[data-product-search]');
    const priceInput = form.querySelector('[name="unit_price"]');
    const batchIdInput = form.querySelector('[name="stock_batch_id"]');
    const batchMsg = document.getElementById('batch-msg');
    
    const modal = document.getElementById('batch-modal');
    const list = document.getElementById('batch-list');
    const tpl = document.getElementById('batch-item-tpl');
    const money = n => 'Rs. ' + Number(n || 0).toFixed(2);

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
                    batchMsg.textContent = `Batch: ${b.batch_no}`;
                    modal.close();
                });
                list.appendChild(el);
            });
            modal.showModal();
        } else if (batches.length === 1) {
            batchIdInput.value = batches[0].id;
            priceInput.value = batches[0].selling;
            batchMsg.textContent = `Batch: ${batches[0].batch_no}`;
        }
    };

    // Need a tiny timeout to let the searchable field set the hidden input
    searchInput.addEventListener('input', () => setTimeout(checkBatch, 50));
    storeSel.addEventListener('change', checkBatch);
})();
</script>
</x-layouts::app>
