<x-layouts::app title="Stock Transfer"><div class="space-y-5"><div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Products / Stock</p><h1 class="page-title">Stock Transfer</h1><p class="page-subtitle">Move inventory safely between stores with a complete audit trail.</p></div><x-flash/><div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_300px]"><div class="space-y-5"><form method="POST" action="{{ route('products.transfers.store') }}" class="panel" id="stock-transfer-form">@csrf<x-submission-token/>
<div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-extrabold">Create New Stock Transfer</h2><p class="mt-1 text-sm text-slate-500">Search and add up to 50 different products in one transfer.</p></div><span class="rounded-xl bg-blue-50 px-3 py-2 text-xs font-bold text-blue-700"><span data-line-count>1</span> product line</span></div>
<div class="mt-5 grid gap-4 md:grid-cols-2 xl:grid-cols-4"><label><span class="label">From Store *</span><select name="from_store_id" class="field" required><option value="">Select source</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(old('from_store_id')==$store->id)>{{ $store->name }}</option>@endforeach</select></label><label><span class="label">To Store *</span><select name="to_store_id" class="field" required><option value="">Select destination</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(old('to_store_id')==$store->id)>{{ $store->name }}</option>@endforeach</select></label><label><span class="label">Transfer Date *</span><input type="date" name="transfer_date" value="{{ old('transfer_date',now()->toDateString()) }}" class="field" required></label><label><span class="label">Note</span><input name="note" value="{{ old('note') }}" class="field" placeholder="Transfer reason"></label></div>
<div class="my-5 h-px bg-slate-100"></div>
<datalist id="transfer-products">@foreach($products as $product)<option value="{{ $product->name }} — {{ $product->code }}{{ ($product->barcode && $product->barcode !== $product->code) ? ' — '.$product->barcode : '' }}"></option>@endforeach</datalist>
<div data-transfer-lines class="space-y-3"></div>
<template id="transfer-line-template"><div data-transfer-line class="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50/70 p-3 md:grid-cols-[minmax(0,1fr)_180px_44px]"><div><span class="label">Search Product *</span><div class="relative"><svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input data-product-search list="transfer-products" class="field !pl-10" placeholder="Type product name, SKU or barcode…" autocomplete="off" required><input data-product-id type="hidden"></div><p data-product-error class="mt-1 hidden text-xs font-semibold text-red-600">Choose a product from the search suggestions.</p></div><label><span class="label">Quantity *</span><input data-quantity type="number" step="any" min=".01" class="field" placeholder="0" required></label><button data-remove-line type="button" class="mt-5 grid size-11 place-items-center rounded-xl border border-red-100 bg-white text-xl text-red-500 transition hover:bg-red-50" aria-label="Remove product">×</button></div></template>
<div class="mt-4 flex flex-wrap items-center justify-between gap-3"><button data-add-line type="button" class="btn-secondary">＋ Add Another Product</button><button class="btn-primary">⇄ Transfer All Products</button></div></form>
<script>
(() => {
    const form = document.getElementById('stock-transfer-form');
    if (!form || form.dataset.ready) return;
    form.dataset.ready = 'true';
    const container = form.querySelector('[data-transfer-lines]');
    const template = document.getElementById('transfer-line-template');
    const count = form.querySelector('[data-line-count]');
    const products = {{ Js::from($products->map(fn ($product) => ['id' => $product->id, 'label' => $product->name.' — '.$product->code.(($product->barcode && $product->barcode !== $product->code) ? ' — '.$product->barcode : '')])) }};
    const byLabel = new Map(products.map(product => [product.label, product]));
    let nextIndex = 0;

    const updateCount = () => {
        const total = container.querySelectorAll('[data-transfer-line]').length;
        count.textContent = total;
        form.querySelectorAll('[data-remove-line]').forEach(button => button.classList.toggle('invisible', total === 1));
    };
    const addLine = (values = {}) => {
        if (container.querySelectorAll('[data-transfer-line]').length >= 50) return;
        const line = template.content.firstElementChild.cloneNode(true);
        const index = nextIndex++;
        const search = line.querySelector('[data-product-search]');
        const productId = line.querySelector('[data-product-id]');
        const quantity = line.querySelector('[data-quantity]');
        const error = line.querySelector('[data-product-error]');
        search.name = `items[${index}][product_search]`;
        productId.name = `items[${index}][product_id]`;
        quantity.name = `items[${index}][quantity]`;
        if (values.product_id) {
            const product = products.find(item => String(item.id) === String(values.product_id));
            if (product) { search.value = product.label; productId.value = product.id; }
        }
        quantity.value = values.quantity || '';
        search.addEventListener('input', () => {
            const match = byLabel.get(search.value);
            productId.value = match ? match.id : '';
            search.setCustomValidity(match ? '' : 'Select a product from the suggestions.');
            error.classList.toggle('hidden', !search.value || Boolean(match));
        });
        line.querySelector('[data-remove-line]').addEventListener('click', () => { line.remove(); updateCount(); });
        container.appendChild(line);
        updateCount();
    };
    form.querySelector('[data-add-line]').addEventListener('click', () => addLine());
    form.addEventListener('submit', event => {
        let valid = true;
        container.querySelectorAll('[data-transfer-line]').forEach(line => {
            const search = line.querySelector('[data-product-search]');
            const selected = Boolean(line.querySelector('[data-product-id]').value);
            search.setCustomValidity(selected ? '' : 'Select a product from the suggestions.');
            line.querySelector('[data-product-error]').classList.toggle('hidden', selected);
            if (!selected) valid = false;
        });
        if (!valid) event.preventDefault();
    });
    const oldItems = {{ Js::from(old('items', [['product_id' => '', 'quantity' => '']])) }};
    oldItems.forEach(addLine);
})();
</script>
<div class="table-wrap"><div class="p-5"><h2 class="text-lg font-extrabold">Recent Stock Transfers</h2></div><table class="data-table"><thead><tr><th>Transfer ID</th><th>From</th><th>To</th><th>Product</th><th>Qty</th><th>Date</th><th>Status</th></tr></thead><tbody>@forelse($transfers as $transfer)<tr><td class="font-bold">{{ $transfer->number }}</td><td>{{ $transfer->fromStore->name }}</td><td>{{ $transfer->toStore->name }}</td><td><b>{{ $transfer->product->name }}</b><br><span class="text-xs text-slate-400">{{ $transfer->product->code }}</span></td><td class="font-black text-emerald-600">{{ $transfer->quantity }} {{ $transfer->product->unit }}</td><td>{{ $transfer->transfer_date->format('d M Y') }}</td><td><span class="rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700">{{ $transfer->status }}</span></td></tr>@empty<tr><td colspan="7" class="py-14 text-center text-slate-500">No stock transfers recorded yet.</td></tr>@endforelse</tbody></table><div class="border-t p-4">{{ $transfers->links() }}</div></div></div>
<aside class="space-y-4"><div class="panel"><h2 class="font-extrabold">Transfer Summary</h2><div class="mt-4 space-y-4"><div class="flex justify-between"><span class="text-sm text-slate-500">Total transfers</span><b>{{ $transfers->total() }}</b></div><div class="flex justify-between"><span class="text-sm text-slate-500">Quantity on this page</span><b>{{ number_format($transfers->sum('quantity'),2) }}</b></div></div></div><div class="panel bg-amber-50/50"><h2 class="font-extrabold">Quick Tips</h2><ul class="mt-3 space-y-3 text-sm text-slate-600"><li>✓ Source stock is checked before transfer.</li><li>✓ Both store quantities update atomically.</li><li>✓ Every movement remains in the audit trail.</li></ul></div></aside></div></div></x-layouts::app>
