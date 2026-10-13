<dialog id="pos-new-product-modal" aria-labelledby="pos-new-product-title" class="m-auto w-[min(94vw,42rem)] max-h-[90vh] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/50" @cancel="if (isSavingProduct) $event.preventDefault()">
    <form method="POST" action="{{ route('sales.parts.products.store') }}" @submit.prevent="saveNewProduct($event)" class="space-y-4 p-5">
        @csrf
        <input type="hidden" name="_submission_token" :value="newProductToken">
        <div class="flex items-start justify-between gap-3">
            <div><h2 id="pos-new-product-title" class="text-lg font-black">Add new product</h2><p class="mt-1 text-sm text-slate-500">Save the product and opening stock, then use it in this bill.</p></div>
            <button type="button" class="btn-secondary !px-3" @click="closeDialog('pos-new-product-modal')" :disabled="isSavingProduct" aria-label="Close new product">✕</button>
        </div>
        <div x-show="newProductErrors.length" x-cloak role="alert" class="rounded-xl bg-red-50 p-3 text-sm text-red-700">
            <template x-for="(error,index) in newProductErrors" :key="index"><p x-text="error"></p></template>
        </div>
        <fieldset :disabled="isSavingProduct" class="grid gap-3 sm:grid-cols-2">
            <label class="sm:col-span-2"><span class="label">Product name *</span><input id="pos-new-product-name" type="text" name="name" x-model="newProductName" required maxlength="150" class="field"></label>
            <label><span class="label">SKU *</span><input type="text" name="code" required maxlength="50" class="field"></label>
            <label><span class="label">Barcode (optional)</span><input type="text" name="barcode" maxlength="100" class="field"></label>
            <label><span class="label">Category *</span><select name="category_id" required class="field"><option value="">Select category</option>@foreach($categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></label>
            <label><span class="label">Brand *</span><input type="text" name="brand" value="Unbranded" required maxlength="100" class="field"></label>
            <label><span class="label">Unit *</span><input type="text" name="unit" value="Piece" required maxlength="30" class="field"></label>
            <label><span class="label">Cost price *</span><input type="number" name="cost_price" value="0" required min="0" step="0.01" class="field"></label>
            <label><span class="label">Selling price *</span><input type="number" name="selling_price" value="0" required min="0" step="0.01" class="field"></label>
            <label><span class="label">Opening quantity *</span><input type="number" name="opening_quantity" value="0" required min="0" step="0.01" class="field"></label>
            <label class="sm:col-span-2"><span class="label">Opening store *</span><select name="store_id" x-model="newProductStoreId" required class="field">@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select><span class="mt-1 block text-xs text-slate-500">Opening quantity is added to this store.</span></label>
            <input type="hidden" name="minimum_stock" value="0">
        </fieldset>
        <div class="flex justify-end gap-3">
            <button type="button" class="btn-secondary" @click="closeDialog('pos-new-product-modal')" :disabled="isSavingProduct">Cancel</button>
            <button type="submit" class="btn-primary" :disabled="isSavingProduct"><span x-text="isSavingProduct ? 'Saving…' : 'Save product'"></span></button>
        </div>
    </form>
</dialog>
