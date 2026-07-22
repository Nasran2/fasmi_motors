@props(['products', 'name' => 'product_id', 'id', 'label' => 'Product *', 'placeholder' => 'Type product name, SKU or barcode…'])
@php
    $selectedId = old($name);
    $selected = $products->firstWhere('id', (int) $selectedId);
    $labelFor = fn ($product) => $product->name.' — '.$product->code.($product->barcode ? ' — '.$product->barcode : '');
@endphp
<div {{ $attributes->class('searchable-product-field') }} id="{{ $id }}">
    <span class="label">{{ $label }}</span>
    <div class="relative">
        <svg class="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input data-product-search list="{{ $id }}-options" value="{{ $selected ? $labelFor($selected) : '' }}" class="field !pl-10" placeholder="{{ $placeholder }}" aria-label="{{ $label }}" autocomplete="off" required>
        <input data-product-id type="hidden" name="{{ $name }}" value="{{ $selectedId }}">
        <datalist id="{{ $id }}-options">@foreach($products as $product)<option value="{{ $labelFor($product) }}"></option>@endforeach</datalist>
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
        search.setCustomValidity(match ? '' : 'Select a product from the suggestions.');
        error.classList.toggle('hidden', !search.value || Boolean(match));
    };
    search.addEventListener('input', validate);
    root.closest('form')?.addEventListener('submit', validate);
})();
</script>
