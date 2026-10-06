<x-layouts::app title="Stock Adjustment">
    @php
        $defaultStore = $stores->firstWhere('name', 'Main Store') ?? $stores->firstWhere('is_default', true) ?? $stores->first();
    @endphp
    <div class="space-y-5">
        <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Products / Stock</p><h1 class="page-title">Stock Adjustment</h1><p class="page-subtitle">Correct inventory counts and update product prices.</p></div>
        <x-flash/>
        <form method="POST" action="{{ route('products.adjustments.store') }}" class="panel" id="adjustment-form">
            @csrf<x-submission-token/>
            <h2 class="mb-4 text-lg font-extrabold">Create Stock Adjustment</h2>
            <div class="grid gap-4 md:grid-cols-3">
                <label><span class="label">Store *</span><select name="store_id" id="adjustment-store" class="field" required><option value="">Select store</option>@foreach($stores as $store)<option value="{{ $store->id }}" @selected(old('store_id', $defaultStore?->id) == $store->id)>{{ $store->name }}</option>@endforeach</select></label>
                <label><span class="label">Adjustment Type *</span><select name="direction" id="adjustment-direction" class="field" required><option value="increase" @selected(old('direction') === 'increase')>Increase</option><option value="decrease" @selected(old('direction') === 'decrease')>Decrease</option><option value="override" @selected(old('direction') === 'override')>Set Exact Quantity</option></select></label>
                <label><span class="label">Reason / Note</span><input name="note" value="{{ old('note') }}" maxlength="500" class="field" placeholder="Stock count correction (optional)"></label>
            </div>
            <div class="mt-6 border-t border-slate-100 pt-4">
                <h3 class="mb-4 text-sm font-extrabold text-slate-700">Add Products</h3>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
                    <div class="sm:col-span-2"><x-searchable-product-field :products="$products" name="dummy_product_id" id="adjustment-product" placeholder="Search product to add..." :required="false"/><p id="adjustment-available" role="status" aria-live="polite" class="mt-2 text-xs font-semibold text-slate-500">Select a product and store to see available quantity.</p></div>
                    <label><span class="label">Quantity *</span><input type="number" id="add-qty" step="any" min="0" class="field" placeholder="Qty"></label>
                    <label><span class="label">Cost Price (Rs.)</span><input type="number" id="add-cost" step="0.01" min="0" class="field" placeholder="Cost price"></label>
                    <label><span class="label">Sale Price (Rs.)</span><input type="number" id="add-sale" step="0.01" min="0" class="field" placeholder="Sale price"></label>
                    <button type="button" id="btn-add-item" class="btn-secondary self-start lg:mt-6">Add Item</button>
                </div>
                <p class="mt-3 mb-4 text-xs text-slate-500">Prices start with the current product prices and can be changed here or in the list. Save Adjustment saves both stock and prices. For Increase or Decrease, use quantity 0 to change only prices.</p>
                <div class="table-wrap mb-4"><table class="data-table min-w-[760px]"><thead><tr><th>Product / Available Now</th><th class="w-36">Quantity</th><th class="w-40">Cost Price (Rs.)</th><th class="w-40">Sale Price (Rs.)</th><th class="w-16"></th></tr></thead><tbody id="adjustment-items-body"><tr id="empty-row"><td colspan="5" class="py-6 text-center text-slate-500">No products added yet.</td></tr></tbody></table></div>
            </div>
            <div class="mt-4 flex justify-end"><button class="btn-primary">Save Adjustment</button></div>
        </form>
        <div class="table-wrap">
            <div class="p-5"><h2 class="text-lg font-extrabold">Recent Stock Adjustments</h2></div>
            <table class="data-table"><thead><tr><th>Date</th><th>Product</th><th>Store</th><th>Type</th><th>Qty</th><th>Balance After</th><th>Cost Price</th><th>Sale Price</th><th>Reason</th><th>Actions</th></tr></thead><tbody>
                @forelse($movements as $movement)
                    @php
                        $effect = $movement->adjustmentEffect();
                        $adjustmentDetails = [
                            'id' => $movement->id, 'name' => $movement->product?->name ?? 'Deleted product',
                            'store' => $movement->store?->name, 'unit' => $movement->product?->unit,
                            'direction' => $movement->adjustment_direction ?? ($effect < 0 ? 'decrease' : 'increase'),
                            'quantity' => (float)($movement->adjustment_quantity ?? abs($effect)),
                            'cost_price' => (float)($movement->product?->cost_price ?? $movement->cost_price),
                            'selling_price' => (float)($movement->product?->selling_price ?? $movement->selling_price),
                            'note' => $movement->note ?? '', 'effect' => $effect,
                            'available' => (float)($movement->product?->stocks->firstWhere('store_id',$movement->store_id)?->quantity ?? 0),
                            'update_url' => route('products.adjustments.update',$movement),
                            'delete_url' => route('products.adjustments.destroy',$movement),
                        ];
                    @endphp
                    <tr><td>{{ $movement->created_at->format('d M Y, h:i A') }}</td><td><b>{{ $movement->product?->name ?? 'Deleted product' }}</b><br><span class="text-xs text-slate-400">{{ $movement->product?->code }}</span></td><td>{{ $movement->store?->name }}</td><td><span class="rounded-lg px-2.5 py-1 text-xs font-bold {{ $effect>0?'bg-emerald-50 text-emerald-700':($effect<0?'bg-red-50 text-red-600':'bg-amber-50 text-amber-700') }}">{{ $effect>0?'Increase':($effect<0?'Decrease':'Prices Only') }}</span></td><td class="font-black {{ $effect>0?'text-emerald-600':($effect<0?'text-red-600':'text-slate-500') }}">{{ $effect>0?'+':'' }}{{ number_format($effect,2) }}</td><td>{{ $movement->balance_after }}</td><td>Rs. {{ number_format($movement->cost_price,2) }}</td><td>Rs. {{ number_format($movement->selling_price,2) }}</td><td>{{ $movement->note }}</td><td>@if(auth()->user()->canAccess('product.adjustment'))<div class="flex gap-2"><button type="button" data-edit-adjustment="{{ json_encode($adjustmentDetails) }}" class="btn-secondary !px-3 !text-blue-600" aria-label="Edit adjustment for {{ $adjustmentDetails['name'] }}">Edit</button><button type="button" data-delete-adjustment="{{ json_encode($adjustmentDetails) }}" class="btn-secondary !px-3 !text-red-600" aria-label="Delete adjustment for {{ $adjustmentDetails['name'] }}">Delete</button></div>@else — @endif</td></tr>
                @empty
                    <tr><td colspan="10" class="py-14 text-center text-slate-500">No manual stock adjustments recorded.</td></tr>
                @endforelse
            </tbody></table><div class="border-t p-4">{{ $movements->links() }}</div>
        </div>
    </div>
    <dialog id="edit-adjustment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl backdrop:bg-slate-900/60" style="max-height:90vh;overflow-y:auto">
        <div class="mb-4 flex items-center justify-between gap-3"><h2 class="text-lg font-extrabold">Edit Stock Adjustment</h2><button type="button" class="btn-secondary !px-3" aria-label="Close edit adjustment" onclick="this.closest('dialog').close()">✕</button></div>
        <p id="edit-adjustment-product" class="font-bold"></p><p id="edit-adjustment-stock" class="mt-1 mb-4 text-sm text-slate-500"></p>
        <p class="mb-4 rounded-xl bg-blue-50 p-3 text-xs text-blue-800">Change this adjustment while keeping later stock movements. Prices below are the current product prices.</p>
        <form id="edit-adjustment-form" method="POST" class="space-y-4">
            @csrf @method('PUT') <x-submission-token/>
            <input type="hidden" name="adjustment_action" value="edit"><input type="hidden" name="adjustment_id">
            <div class="grid grid-cols-2 gap-3"><label><span class="label">Adjustment Type *</span><select name="direction" class="field" required><option value="increase">Increase</option><option value="decrease">Decrease</option><option value="override">Set Exact Quantity</option></select></label><label><span class="label">Quantity *</span><input name="quantity" type="number" min="0" step="0.01" class="field" required></label><label><span class="label">Cost Price (Rs.) *</span><input name="cost_price" type="number" min="0" step="0.01" class="field" required></label><label><span class="label">Sale Price (Rs.) *</span><input name="selling_price" type="number" min="0" step="0.01" class="field" required></label></div>
            <label class="block"><span class="label">Adjustment Note</span><input name="note" maxlength="500" class="field"></label>
            <label class="block"><span class="label">Reason for Edit *</span><input name="reason" maxlength="500" class="field" required placeholder="Why are you changing this adjustment?"></label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="btn-primary">Save Changes</button></div>
        </form>
    </dialog>
    <dialog id="delete-adjustment-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl backdrop:bg-slate-900/60" style="max-height:90vh;overflow-y:auto">
        <div class="mb-4 flex items-center justify-between gap-3"><h2 class="text-lg font-extrabold">Delete Stock Adjustment</h2><button type="button" class="btn-secondary !px-3" aria-label="Close delete adjustment" onclick="this.closest('dialog').close()">✕</button></div>
        <p id="delete-adjustment-product" class="font-bold"></p><p id="delete-adjustment-effect" class="my-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800"></p>
        <p class="mb-4 text-sm text-slate-500">Current product prices are kept. The deleted adjustment and reversal remain in the audit history.</p>
        <form id="delete-adjustment-form" method="POST" class="space-y-4">
            @csrf @method('DELETE') <x-submission-token/>
            <input type="hidden" name="adjustment_action" value="delete"><input type="hidden" name="adjustment_id">
            <label class="block"><span class="label">Reason for Delete *</span><input name="reason" maxlength="500" class="field" required placeholder="Why are you deleting this adjustment?"></label>
            <div class="flex justify-end gap-2"><button type="button" class="btn-secondary" onclick="this.closest('dialog').close()">Cancel</button><button class="btn-primary">Delete Adjustment</button></div>
        </form>
    </dialog>
    @php
        $oldAdjustmentProducts = old('products', []);
        $oldAdjustmentProducts = collect(is_array($oldAdjustmentProducts) ? $oldAdjustmentProducts : [])->filter(fn($item) => is_array($item))->values();
        $adjustmentProducts = $products->mapWithKeys(fn($product) => [$product->id => [
            'id' => $product->id, 'name' => $product->name, 'code' => $product->code, 'unit' => $product->unit,
            'cost_price' => (float)$product->cost_price, 'selling_price' => (float)$product->selling_price,
            'stocks' => $product->stocks->mapWithKeys(fn($stock) => [$stock->store_id => (float)$stock->quantity]),
        ]]);
    @endphp
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const products = {{ Js::from($adjustmentProducts) }};
        const form = document.getElementById('adjustment-form');
        const store = document.getElementById('adjustment-store');
        const search = document.querySelector('#adjustment-product [data-product-search]');
        const productId = document.querySelector('#adjustment-product [data-product-id]');
        const qty = document.getElementById('add-qty');
        const cost = document.getElementById('add-cost');
        const sale = document.getElementById('add-sale');
        const available = document.getElementById('adjustment-available');
        const tbody = document.getElementById('adjustment-items-body');
        const empty = document.getElementById('empty-row');
        let itemIndex = 0;
        const stockLabel = product => {
            if (!store.value) return 'Select a store to see available quantity.';
            const quantity = Number(product.stocks[store.value] ?? 0).toLocaleString(undefined, {minimumFractionDigits:2, maximumFractionDigits:2});
            return 'Available now: ' + quantity + (product.unit ? ' ' + product.unit : '') + ' · ' + store.selectedOptions[0].textContent;
        };
        const refreshAvailable = () => {
            const selected = products[productId.value];
            available.textContent = selected ? stockLabel(selected) : 'Select a product and store to see available quantity.';
            tbody.querySelectorAll('.adj-row').forEach(row => {
                row.querySelector('[data-available]').textContent = stockLabel(products[row.dataset.productId]);
            });
        };
        search.addEventListener('input', () => {
            const selected = products[productId.value];
            cost.value = selected ? selected.cost_price.toFixed(2) : '';
            sale.value = selected ? selected.selling_price.toFixed(2) : '';
            refreshAvailable();
        });
        store.addEventListener('change', refreshAvailable);
        const addRow = item => {
            const product = products[item.id];
            if (!product) return;
            const row = document.createElement('tr');
            row.className = 'adj-row'; row.dataset.productId = product.id;
            row.innerHTML = `<td><input type="hidden" name="products[${itemIndex}][id]" class="prod-id"><b data-name></b><small data-available class="mt-1 block text-slate-500"></small></td><td><input type="number" step="any" min="0" name="products[${itemIndex}][quantity]" data-quantity class="field" required></td><td><input type="number" step="0.01" min="0" name="products[${itemIndex}][cost_price]" data-cost class="field" required></td><td><input type="number" step="0.01" min="0" name="products[${itemIndex}][selling_price]" data-sale class="field" required></td><td><button type="button" data-remove class="btn-secondary !px-3 !text-red-600">✕</button></td>`;
            row.querySelector('.prod-id').value = product.id;
            row.querySelector('[data-name]').textContent = product.name + ' — ' + product.code;
            for (const [selector, value, label] of [
                ['data-quantity',item.quantity,'Quantity'], ['data-cost',item.cost_price ?? product.cost_price,'Cost Price'], ['data-sale',item.selling_price ?? product.selling_price,'Sale Price'],
            ]) {
                const input = row.querySelector('['+selector+']'); input.value = value;
                input.setAttribute('aria-label',label+' for '+product.name);
            }
            const remove = row.querySelector('[data-remove]');
            remove.setAttribute('aria-label','Remove '+product.name);
            remove.addEventListener('click', () => { row.remove(); empty.hidden = Boolean(tbody.querySelector('.adj-row')); });
            tbody.appendChild(row); empty.hidden = true; itemIndex++; refreshAvailable();
        };
        document.getElementById('btn-add-item').addEventListener('click', () => {
            if (!products[productId.value]) { alert('Please select a product from the list.'); return; }
            if (tbody.querySelector(`.adj-row[data-product-id="${productId.value}"]`)) { alert('This product is already in the list.'); return; }
            for (const input of [qty,cost,sale]) {
                if (input.value === '' || !input.checkValidity()) { input.focus(); input.reportValidity(); if (input.value === '') alert('Enter a quantity, cost price and sale price.'); return; }
            }
            addRow({id:productId.value,quantity:qty.value,cost_price:cost.value,selling_price:sale.value});
            search.tomselect?.clear(true); search.value = ''; productId.value = ''; search.setCustomValidity('');
            document.querySelector('#adjustment-product [data-product-error]').classList.add('hidden');
            qty.value = ''; cost.value = ''; sale.value = ''; refreshAvailable();
        });
        for (const item of {{ Js::from($oldAdjustmentProducts) }}) addRow(item);
        form.addEventListener('submit', event => {
            if (!tbody.querySelector('.adj-row')) { event.preventDefault(); alert('Add at least one product.'); }
        });
        const editDialog = document.getElementById('edit-adjustment-dialog');
        const deleteDialog = document.getElementById('delete-adjustment-dialog');
        const editForm = document.getElementById('edit-adjustment-form');
        const deleteForm = document.getElementById('delete-adjustment-form');
        const formatQuantity = value => Number(value).toLocaleString(undefined,{minimumFractionDigits:2, maximumFractionDigits:2});
        const openEdit = (data, previous = {}) => {
            editForm.action = data.update_url;
            editForm.elements.namedItem('adjustment_id').value = data.id;
            document.getElementById('edit-adjustment-product').textContent = data.name + ' · ' + data.store;
            document.getElementById('edit-adjustment-stock').textContent = 'Available now: ' + formatQuantity(data.available) + ' ' + (data.unit || '');
            for (const name of ['direction','quantity','cost_price','selling_price','note','reason']) {
                editForm.elements.namedItem(name).value = previous[name] ?? data[name] ?? '';
            }
            editDialog.showModal();
        };
        const openDelete = (data, reason = '') => {
            deleteForm.action = data.delete_url;
            deleteForm.elements.namedItem('adjustment_id').value = data.id;
            deleteForm.elements.namedItem('reason').value = reason;
            document.getElementById('delete-adjustment-product').textContent = data.name + ' · ' + data.store;
            document.getElementById('delete-adjustment-effect').textContent = 'This reverses the adjustment by ' + (data.effect > 0 ? 'removing ' : 'adding ') + formatQuantity(Math.abs(data.effect)) + ' ' + (data.unit || '') + '. Available quantity becomes ' + formatQuantity(data.available - data.effect) + '.';
            deleteDialog.showModal();
        };
        const editButtons = Array.from(document.querySelectorAll('[data-edit-adjustment]'));
        editButtons.forEach(button => button.addEventListener('click', () => openEdit(JSON.parse(button.dataset.editAdjustment))));
        document.querySelectorAll('[data-delete-adjustment]').forEach(button => button.addEventListener('click', () => openDelete(JSON.parse(button.dataset.deleteAdjustment))));
        const previousAction = {{ Js::from(old('adjustment_action')) }};
        const previousId = {{ Js::from(old('adjustment_id')) }};
        const previousButton = editButtons.find(button => String(JSON.parse(button.dataset.editAdjustment).id) === String(previousId));
        if (previousButton && previousAction) {
            const data = JSON.parse(previousButton.dataset.editAdjustment);
            if (previousAction === 'edit') openEdit(data, {{ Js::from(old()) }});
            if (previousAction === 'delete') openDelete(data, {{ Js::from(old('reason','')) }});
        }

    });
    </script>
</x-layouts::app>
