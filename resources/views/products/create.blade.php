<x-layouts::app title="Add Product"><div class="space-y-5"><div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Products / Stock</p><h1 class="page-title">Add Product</h1><p class="page-subtitle">Add a spare part and its real opening stock.</p></div><x-flash/>
<form method="POST" action="{{ route('products.store') }}" enctype="multipart/form-data" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_320px]">@csrf<x-submission-token/>
<div class="space-y-4"><section class="panel"><h2 class="mb-4 text-lg font-extrabold">Basic Information</h2><div class="grid gap-4 md:grid-cols-3"><label><span class="label">Product Name *</span><input name="name" value="{{ old('name') }}" class="field" required></label><label><span class="label">SKU *</span><input name="code" value="{{ old('code') }}" class="field" required></label><label><span class="label">Barcode</span><input name="barcode" value="{{ old('barcode') }}" class="field"></label><div><span class="label">Category *</span><select id="product-category" name="category_id" class="field" required><option value="">Select category</option>@foreach($categories as $item)<option value="{{ $item->id }}" @selected(old('category_id')==$item->id)>{{ $item->name }}</option>@endforeach</select><button type="button" data-quick-add="categories" data-target="product-category" class="mt-2 text-xs font-extrabold text-red-600 hover:text-red-700">＋ Add new category</button></div><div><span class="label">Brand *</span><select id="product-brand" name="brand" class="field" required><option value="">Select brand</option>@foreach($brands as $item)<option @selected(old('brand')===$item->name)>{{ $item->name }}</option>@endforeach</select><button type="button" data-quick-add="brands" data-target="product-brand" class="mt-2 text-xs font-extrabold text-red-600 hover:text-red-700">＋ Add new brand</button></div><div><span class="label">Unit *</span><select id="product-unit" name="unit" class="field" required><option value="">Select unit</option>@foreach($units as $item)<option @selected(old('unit')===$item->name)>{{ $item->name }}</option>@endforeach</select><button type="button" data-quick-add="units" data-target="product-unit" class="mt-2 text-xs font-extrabold text-red-600 hover:text-red-700">＋ Add new unit</button></div><label class="md:col-span-3"><span class="label">Bike / Model Compatibility</span><input name="model_compatibility" value="{{ old('model_compatibility') }}" class="field" placeholder="e.g. Yamaha FZ-S V3"></label></div></section>
<section class="panel"><h2 class="mb-4 text-lg font-extrabold">Pricing & Supplier</h2><div class="grid gap-4 md:grid-cols-3"><label><span class="label">Cost Price (Rs.) *</span><input type="number" step=".01" min="0" name="cost_price" value="{{ old('cost_price') }}" class="field" required></label><label><span class="label">Sell Price (Rs.) *</span><input type="number" step=".01" min="0" name="selling_price" value="{{ old('selling_price') }}" class="field" required></label><div><span class="label">Supplier</span><select id="product-supplier" name="supplier_id" class="field"><option value="">Select supplier</option>@foreach($suppliers as $item)<option value="{{ $item->id }}" @selected(old('supplier_id')==$item->id)>{{ $item->name }}</option>@endforeach</select><button type="button" data-quick-add="suppliers" data-target="product-supplier" class="mt-2 text-xs font-extrabold text-red-600 hover:text-red-700">＋ Add new supplier</button></div></div></section>
<section class="panel"><h2 class="mb-4 text-lg font-extrabold">Inventory & Store</h2><div class="grid gap-4 md:grid-cols-4"><label><span class="label">Minimum Stock *</span><input type="number" step=".01" min="0" name="minimum_stock" value="{{ old('minimum_stock',0) }}" class="field" required></label><label><span class="label">Reorder Level</span><input type="number" step=".01" min="0" name="reorder_level" value="{{ old('reorder_level') }}" class="field"></label><label><span class="label">Opening Qty *</span><input type="number" step=".01" min="0" name="opening_quantity" value="{{ old('opening_quantity',0) }}" class="field" required></label><div><span class="label">Opening Store</span><div class="field flex items-center gap-2 bg-slate-100 font-bold text-slate-700"><span class="grid size-7 place-items-center rounded-lg bg-white text-red-600">⌂</span>{{ $mainStore?->name ?? 'Main Store not configured' }}</div><p class="mt-1.5 text-[11px] font-medium text-slate-500">Opening stock is always assigned to Main Store.</p></div></div></section>
<div class="flex flex-wrap justify-end gap-2"><a href="{{ route('products.index') }}" class="btn-secondary">Cancel</a><button name="save_new" value="1" class="btn-secondary">Save & New</button><button class="btn-primary">Save Product</button></div></div>
<aside class="panel h-fit"><h2 class="text-lg font-extrabold">Product Image</h2><p class="mt-1 text-sm text-slate-500">JPG, PNG or WEBP, maximum 2MB.</p><label class="mt-5 grid min-h-56 cursor-pointer place-items-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-5 text-center transition hover:border-red-300 hover:bg-red-50/30"><div><span class="mx-auto grid size-14 place-items-center rounded-2xl bg-white text-2xl shadow-sm">⇧</span><p class="mt-3 text-sm font-bold">Choose product image</p></div><input type="file" name="image" accept="image/*" class="sr-only"></label></aside></form>

<dialog id="quick-add-dialog" class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-3xl border-0 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/55 backdrop:backdrop-blur-sm">
    <form id="quick-add-form" class="p-6"><div class="flex items-start justify-between gap-4"><div><p class="text-xs font-extrabold uppercase tracking-[.16em] text-red-600">Quick Setup</p><h2 id="quick-add-title" class="mt-1 text-2xl font-black">Add New</h2><p class="mt-1 text-sm text-slate-500">It will be selected in this product immediately.</p></div><button type="button" data-dialog-close class="grid size-9 place-items-center rounded-xl bg-slate-100 text-xl text-slate-500 hover:bg-slate-200">×</button></div><div id="quick-add-error" class="mt-4 hidden rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700"></div><div class="mt-5 space-y-4"><label><span class="label">Name *</span><input name="name" class="field" required autocomplete="off"></label><label data-description-field><span class="label">Description</span><textarea name="description" class="field" rows="3"></textarea></label><label data-unit-field class="hidden"><span class="label">Unit Type *</span><select name="type" class="field"><option>Quantity</option><option>Volume</option><option>Weight</option><option>Length</option></select></label><div data-supplier-fields class="hidden grid gap-4 sm:grid-cols-2"><label><span class="label">Phone</span><input name="phone" class="field"></label><label><span class="label">Address</span><input name="address" class="field"></label></div></div><div class="mt-6 flex justify-end gap-2"><button type="button" data-dialog-close class="btn-secondary">Cancel</button><button id="quick-add-submit" class="btn-primary">Save</button></div></form>
</dialog>

<script>
(() => {
    const dialog = document.getElementById('quick-add-dialog');
    if (!dialog || dialog.dataset.ready) return;
    dialog.dataset.ready = 'true';
    const form = document.getElementById('quick-add-form');
    const title = document.getElementById('quick-add-title');
    const error = document.getElementById('quick-add-error');
    const submit = document.getElementById('quick-add-submit');
    const endpoints = {{ Js::from([
        'categories' => route('products.references.store', 'categories'),
        'brands' => route('products.references.store', 'brands'),
        'units' => route('products.references.store', 'units'),
        'suppliers' => route('products.suppliers.store'),
    ]) }};
    const labels = {categories: 'Category', brands: 'Brand', units: 'Unit', suppliers: 'Supplier'};
    let currentType = null;
    let targetId = null;

    document.querySelectorAll('[data-quick-add]').forEach(button => button.addEventListener('click', () => {
        currentType = button.dataset.quickAdd;
        targetId = button.dataset.target;
        form.reset();
        error.classList.add('hidden');
        title.textContent = `Add New ${labels[currentType]}`;
        form.querySelector('[data-description-field]').classList.toggle('hidden', currentType === 'units' || currentType === 'suppliers');
        form.querySelector('[data-unit-field]').classList.toggle('hidden', currentType !== 'units');
        form.querySelector('[data-supplier-fields]').classList.toggle('hidden', currentType !== 'suppliers');
        dialog.showModal();
        requestAnimationFrame(() => form.elements.name.focus());
    }));
    dialog.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        submit.disabled = true;
        submit.textContent = 'Saving…';
        error.classList.add('hidden');
        try {
            const response = await fetch(endpoints[currentType], {method: 'POST', headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}'}, body: new FormData(form)});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || Object.values(result.errors || {}).flat().join(' '));
            const select = document.getElementById(targetId);
            const value = currentType === 'categories' || currentType === 'suppliers' ? result.id : result.name;
            select.add(new Option(result.name, value, true, true));
            select.dispatchEvent(new Event('change', {bubbles: true}));
            dialog.close();
        } catch (exception) {
            error.textContent = exception.message || 'Unable to save. Please check the details.';
            error.classList.remove('hidden');
        } finally {
            submit.disabled = false;
            submit.textContent = 'Save';
        }
    });
})();
</script>
</div></x-layouts::app>
