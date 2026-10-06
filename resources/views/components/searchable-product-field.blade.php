@props(['products', 'name' => 'product_id', 'id', 'selectedId' => null, 'label' => 'Product *', 'placeholder' => 'Type product name, SKU, barcode or rack no…', 'required' => true])
@php
    $products = $products->sort(fn($a, $b) => strcmp(mb_strtolower(trim($a->name)), mb_strtolower(trim($b->name))) ?: strcmp($a->code, $b->code))->values();
    $selectedId = old($name, $selectedId);
    $selected = $products->firstWhere('id', (int) $selectedId);
    $labelFor = function($product) {
        $totalStock = $product->stocks ? $product->stocks->sum('quantity') : 0;
        $minStock = (float) ($product->minimum_stock ?? 0);
        $mark = $totalStock <= 0 ? '🔴 ' : ($totalStock <= $minStock ? '🟡 ' : '');
        $rack = $product->rack_number ? ' [Rack: '.$product->rack_number.']' : '';
        return $mark . $product->name.' — '.$product->code.(($product->barcode && $product->barcode !== $product->code) ? ' — '.$product->barcode : '').$rack;
    };
@endphp
<div {{ $attributes->class('searchable-product-field') }} id="{{ $id }}">
    <span class="label">{{ $label }}</span>
    <div class="relative">
        <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input data-product-search list="{{ $id }}-options" value="{{ $selected ? $labelFor($selected) : '' }}" class="field !pl-10" placeholder="{{ $placeholder }}" aria-label="{{ $label }}" autocomplete="off" @required($required)>
        <input data-product-id type="hidden" name="{{ $name }}" value="{{ $selectedId }}">
        <datalist id="{{ $id }}-options">@foreach($products as $product)<option value="{{ $labelFor($product) }}" data-sort-order="{{ $loop->index }}"></option>@endforeach</datalist>
    </div>
    <p data-product-error class="mt-1 hidden text-xs font-semibold text-red-600">Choose a product from the search suggestions.</p>
</div>
<script>
(() => {
    const root = document.getElementById(@js($id));
    if (!root || root.dataset.ready) return;
    root.dataset.ready = 'true';
    const products = {{ Js::from($products->map(fn ($product) => ['id' => $product->id, 'label' => $labelFor($product)])) }};
    const byLabel = new Map(products.map(product => [product.label, product]));
    const search = root.querySelector('[data-product-search]');
    const productId = root.querySelector('[data-product-id]');
    const error = root.querySelector('[data-product-error]');
    const validate = () => {
        const match = byLabel.get(search.value);
        productId.value = match ? match.id : '';
        search.setCustomValidity(match || (!@js($required) && !search.value) ? '' : 'Select a product from the suggestions.');
        error.classList.toggle('hidden', !search.value || Boolean(match));
    };
    search.addEventListener('input', validate);
    root.closest('form')?.addEventListener('submit', validate);
})();
</script>
