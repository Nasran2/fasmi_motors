<x-layouts::app title="Accident Bike Quotation">
    <div class="space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Sales / Billing</p>
                <h1 class="page-title">Accident Bike Quotation</h1>
                <p class="page-subtitle">Prepare an estimate for parts, repair work and other charges. No stock or payment is recorded.</p>
            </div>
            @if(auth()->user()->canAccess('sales.view_invoices'))<a href="{{ route('sales.quotations') }}" class="btn-secondary">View quotations</a>@endif
        </div>

        <x-flash />
        @if($errors->any())
            <div role="alert" class="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                <p class="font-extrabold">Please check these details:</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('sales.accident-quotations.store') }}" x-data="accidentQuotationForm()" class="space-y-5" @submit="submitForm($event)">
            @csrf
            <x-submission-token />

            <div class="grid gap-5 xl:grid-cols-2">
                <section class="panel space-y-4">
                    <div><h2 class="text-lg font-extrabold text-slate-900">Customer details</h2><p class="text-sm text-slate-500">Select a customer or create a record for this quotation.</p></div>
                    <input type="hidden" name="customer_mode" :value="customerMode">
                    <div class="flex gap-2 rounded-xl bg-slate-100 p-1">
                        <button type="button" @click="customerMode = 'existing'" :class="customerMode === 'existing' ? 'bg-white text-red-600 shadow-sm' : 'text-slate-600'" class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">Existing customer</button>
                        <button type="button" @click="customerMode = 'new'" :class="customerMode === 'new' ? 'bg-white text-red-600 shadow-sm' : 'text-slate-600'" class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">New customer</button>
                    </div>
                    <div x-show="customerMode === 'existing'" x-cloak>
                        <label><span class="label">Customer *</span><select name="customer_id" x-model="customerId" @change="bikeId = ''" :disabled="customerMode !== 'existing'" class="field" required><option value="">Select customer</option>@foreach($customers as $customer)<option value="{{ $customer->id }}">{{ $customer->name }} — {{ $customer->phone }}</option>@endforeach</select></label>
                    </div>
                    <div x-show="customerMode === 'new'" x-cloak class="grid gap-3 sm:grid-cols-2">
                        <label><span class="label">Customer name *</span><input name="customer_name" value="{{ old('customer_name') }}" :disabled="customerMode !== 'new'" class="field" required></label>
                        <label><span class="label">Phone number *</span><input name="customer_phone" value="{{ old('customer_phone') }}" :disabled="customerMode !== 'new'" class="field" required></label>
                        <label class="sm:col-span-2"><span class="label">Address</span><input name="customer_address" value="{{ old('customer_address') }}" :disabled="customerMode !== 'new'" class="field"></label>
                    </div>
                </section>

                <section class="panel space-y-4">
                    <div><h2 class="text-lg font-extrabold text-slate-900">Bike details</h2><p class="text-sm text-slate-500">Connect an existing bike or register the accident bike.</p></div>
                    <input type="hidden" name="bike_mode" :value="bikeMode">
                    <div class="flex gap-2 rounded-xl bg-slate-100 p-1">
                        <button type="button" @click="bikeMode = 'existing'" :disabled="customerMode === 'new'" :class="bikeMode === 'existing' ? 'bg-white text-red-600 shadow-sm' : 'text-slate-600'" class="flex-1 rounded-lg px-3 py-2 text-sm font-bold disabled:opacity-40">Existing bike</button>
                        <button type="button" @click="bikeMode = 'new'" :class="bikeMode === 'new' ? 'bg-white text-red-600 shadow-sm' : 'text-slate-600'" class="flex-1 rounded-lg px-3 py-2 text-sm font-bold">New bike</button>
                    </div>
                    <div x-show="bikeMode === 'existing'" x-cloak>
                        <label><span class="label">Customer's bike *</span><select name="bike_id" x-model="bikeId" :disabled="bikeMode !== 'existing' || customerMode !== 'existing'" class="field" required><option value="">Select bike</option><template x-for="bike in availableBikes" :key="bike.id"><option :value="bike.id" x-text="`${bike.number} — ${bike.model}`"></option></template></select></label>
                        <p class="mt-2 text-xs text-slate-500" x-show="customerId && availableBikes.length === 0">No bike is registered to this customer. Choose New bike.</p>
                    </div>
                    <div x-show="bikeMode === 'new'" x-cloak class="grid gap-3 sm:grid-cols-2">
                        <label><span class="label">Registration number *</span><input name="bike_number" value="{{ old('bike_number') }}" :disabled="bikeMode !== 'new'" class="field uppercase" required></label>
                        <label><span class="label">Model *</span><input name="bike_model" value="{{ old('bike_model') }}" :disabled="bikeMode !== 'new'" class="field" required></label>
                        <label><span class="label">Brand</span><input name="bike_brand" value="{{ old('bike_brand') }}" :disabled="bikeMode !== 'new'" class="field"></label>
                        <label><span class="label">Colour</span><input name="bike_color" value="{{ old('bike_color') }}" :disabled="bikeMode !== 'new'" class="field"></label>
                    </div>
                </section>
            </div>

            <section class="panel space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-extrabold text-slate-900">Products and parts</h2><p class="text-sm text-slate-500">Search the catalog, then set the required quantity and quoted price.</p></div><button type="button" @click="addProduct()" class="btn-secondary">＋ Add product</button></div>
                <p x-show="productError" x-cloak role="alert" class="rounded-xl bg-red-50 px-4 py-3 text-sm font-bold text-red-700" x-text="productError"></p>
                <p x-show="items.length === 0" x-cloak class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">No products added. You can create a quotation using charges only.</p>
                <template x-for="(item, index) in items" :key="item.key">
                    <div class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,1.5fr)_100px_140px_100px_auto] lg:items-end">
                        <div class="relative" @click.outside="item.productOpen = false" @focusout="if (!$el.contains($event.relatedTarget)) item.productOpen = false">
                            <span class="label">Product *</span>
                            <div class="relative">
                                <input type="search" aria-label="Search or select product" role="combobox" aria-autocomplete="list" :aria-expanded="item.productOpen ? 'true' : 'false'" :aria-controls="`product-options-${item.key}`" :aria-activedescendant="item.productOpen && item.activeProductIndex >= 0 ? `product-option-${item.key}-${item.activeProductIndex}` : null" x-model="item.search" @focus="openProductPicker(item)" @click="openProductPicker(item)" @input="updateProductSearch(item)" @keydown.arrow-down.prevent="moveProductSelection(item, 1)" @keydown.arrow-up.prevent="moveProductSelection(item, -1)" @keydown.enter.prevent="selectActiveProduct(item)" @keydown.escape="item.productOpen = false" :name="`items[${index}][search]`" class="field !pr-12" placeholder="Search name or SKU" autocomplete="off" required>
                                <button type="button" @click="toggleProductPicker(item, $event)" :aria-label="item.productOpen ? 'Close product list' : 'Browse products'" class="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-500 hover:text-red-600"><svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 7 5 5 5-5"/></svg></button>
                            </div>
                            <input type="hidden" :name="`items[${index}][product_id]`" :value="item.product_id">
                            <div x-show="item.productOpen" x-cloak :id="`product-options-${item.key}`" role="listbox" aria-label="Products" class="absolute z-50 mt-1 max-h-64 w-full min-w-[280px] overflow-y-auto rounded-xl border border-slate-200 bg-white py-1 shadow-xl">
                                <p x-show="!item.productMatches.length" class="px-3 py-4 text-sm text-slate-500">No matching products. Try a different name or SKU.</p>
                                <template x-for="(product, productIndex) in item.productMatches" :key="product.id"><button type="button" role="option" :id="`product-option-${item.key}-${productIndex}`" :aria-selected="productIndex === item.activeProductIndex" @mousedown.prevent @click="chooseProduct(item, product)" :class="productIndex === item.activeProductIndex ? 'bg-red-50 text-red-700' : 'text-slate-800 hover:bg-slate-50'" class="flex w-full items-center justify-between gap-3 px-3 py-2.5 text-left text-sm"><span class="min-w-0 truncate font-semibold" x-text="product.name"></span><span class="shrink-0 text-xs text-slate-500" x-text="product.code || 'No SKU'"></span></button></template>
                                <p x-show="!item.search.trim() && item.productMatches.length" class="border-t border-slate-100 px-3 py-2 text-xs text-slate-500">Type to search the full catalog</p>
                            </div>
                        </div>
                        <label><span class="label">Line description</span><input :name="`items[${index}][description]`" x-model="item.description" maxlength="255" class="field" placeholder="Optional custom wording"></label>
                        <label><span class="label">Qty *</span><input type="number" min="0.01" max="1000" step="0.01" :name="`items[${index}][quantity]`" x-model="item.quantity" class="field" required></label>
                        <label><span class="label">Unit price (Rs.) *</span><input type="number" min="0" max="99999999.99" step="0.01" :name="`items[${index}][unit_price]`" x-model="item.unit_price" class="field" required></label>
                        <div class="pb-2 text-right text-sm font-extrabold text-slate-900" x-text="formatMoney(amount(item.quantity) * amount(item.unit_price))"></div>
                        <button type="button" @click="items.splice(index, 1)" :aria-label="`Remove product ${index + 1}`" class="rounded-lg px-3 py-2 text-sm font-bold text-red-600 hover:bg-red-50">Remove</button>
                    </div>
                </template>
            </section>

            <section class="panel space-y-4">
                <div><h2 class="text-lg font-extrabold text-slate-900">Repair and other charges</h2><p class="text-sm text-slate-500">Add as many service, labour, washing or custom charges as the estimate needs.</p></div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" @click="addCharge('service', 'Service charge')" class="btn-secondary">＋ Service</button>
                    <button type="button" @click="addCharge('labor', 'Labour charge')" class="btn-secondary">＋ Labour</button>
                    <button type="button" @click="addCharge('washing', 'Washing charge')" class="btn-secondary">＋ Washing</button>
                    <button type="button" @click="addCharge('other', '')" class="btn-secondary">＋ Custom charge</button>
                </div>
                <p x-show="charges.length === 0" x-cloak class="rounded-xl bg-slate-50 p-5 text-sm text-slate-500">No charges added.</p>
                <template x-for="(charge, index) in charges" :key="charge.key">
                    <div class="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-[160px_minmax(0,1fr)_170px_auto] md:items-end">
                        <label><span class="label">Type</span><select :name="`charges[${index}][type]`" x-model="charge.type" class="field"><option value="service">Service</option><option value="labor">Labour</option><option value="washing">Washing</option><option value="other">Other</option></select></label>
                        <label><span class="label">Description *</span><input :name="`charges[${index}][description]`" x-model="charge.description" class="field" placeholder="Describe the work or charge" required></label>
                        <label><span class="label">Amount (Rs.) *</span><input type="number" min="0" max="99999999.99" step="0.01" :name="`charges[${index}][amount]`" x-model="charge.amount" class="field" required></label>
                        <button type="button" @click="charges.splice(index, 1)" :aria-label="`Remove charge ${index + 1}`" class="rounded-lg px-3 py-2 text-sm font-bold text-red-600 hover:bg-red-50">Remove</button>
                    </div>
                </template>
            </section>

            <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_340px]">
                <section class="panel space-y-4">
                    <h2 class="text-lg font-extrabold text-slate-900">Quotation details</h2>
                    <label><span class="label">Store *</span><select name="store_id" class="field" required>@foreach($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id', $stores->first()?->id) === (string) $store->id)>{{ $store->name }}</option>@endforeach</select></label>
                    <label><span class="label">Accident details / work required</span><textarea name="customer_note" rows="4" class="field" placeholder="This note appears on the quotation">{{ old('customer_note') }}</textarea></label>
                    <label><span class="label">Private staff note</span><textarea name="internal_note" rows="3" class="field" placeholder="This note is not printed">{{ old('internal_note') }}</textarea></label>
                </section>
                <aside class="panel space-y-4 xl:self-start">
                    <h2 class="text-lg font-extrabold text-slate-900">Estimate summary</h2>
                    <div class="flex justify-between text-sm"><span class="text-slate-600">Products</span><strong x-text="formatMoney(productsTotal)"></strong></div>
                    <div class="flex justify-between text-sm"><span class="text-slate-600">Charges</span><strong x-text="formatMoney(chargesTotal)"></strong></div>
                    <label><span class="label">Discount (Rs.)</span><input type="number" name="discount" x-model="discount" min="0" max="99999999.99" step="0.01" class="field"></label>
                    <div class="flex justify-between border-t border-slate-200 pt-4 text-lg font-black"><span>Quoted total</span><span class="text-red-600" x-text="formatMoney(total)"></span></div>
                    <p class="text-xs text-slate-500">Saving this quotation does not deduct stock or create a payment.</p>
                    <button type="submit" :disabled="submitting || (!items.length && !charges.length)" class="btn-primary w-full disabled:opacity-50" x-text="submitting ? 'Saving quotation...' : 'Save quotation'"></button>
                </aside>
            </div>
        </form>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('accidentQuotationForm', () => ({
                products: @js($products->values()),
                bikes: @js($bikes->map(fn ($bike) => ['id' => $bike->id, 'customer_id' => $bike->customer_id, 'number' => $bike->number, 'model' => $bike->model])->values()),
                customerMode: @js(old('customer_mode', 'existing')),
                customerId: @js((string) old('customer_id', '')),
                bikeMode: @js(old('bike_mode', 'existing')),
                bikeId: @js((string) old('bike_id', '')),
                items: @js(array_values(old('items', []))),
                charges: @js(array_values(old('charges', []))),
                discount: @js(old('discount', 0)),
                submitting: false,
                productError: '',

                init() {
                    this.items = this.items.map(item => ({
                        key: crypto.randomUUID(),
                        product_id: String(item.product_id || ''),
                        search: item.search || this.productLabel(item.product_id),
                        description: item.description || '',
                        quantity: item.quantity ?? 1,
                        unit_price: item.unit_price ?? '',
                        productOpen: false,
                        productMatches: [],
                        activeProductIndex: -1,
                    }));
                    this.charges = this.charges.map(charge => ({
                        key: crypto.randomUUID(), type: charge.type || 'other',
                        description: charge.description || '', amount: charge.amount ?? '',
                    }));
                    this.$watch('customerMode', mode => {
                        if (mode === 'new') { this.bikeMode = 'new'; this.bikeId = ''; }
                    });
                },

                get availableBikes() {
                    return this.bikes.filter(bike => String(bike.customer_id) === String(this.customerId));
                },

                productLabel(id) {
                    const product = this.products.find(product => String(product.id) === String(id));
                    return product ? `${product.name} — ${product.code}` : '';
                },

                matchingProducts(item) {
                    const query = String(item.search || '').trim().toLowerCase();
                    if (!query) return this.products.slice(0, 20);
                    return this.products.filter(product => product.name.toLowerCase().includes(query) || String(product.code || '').toLowerCase().includes(query)).slice(0, 20);
                },

                openProductPicker(item) {
                    if (item.product_id) return;
                    item.productMatches = this.matchingProducts(item);
                    item.activeProductIndex = -1;
                    item.productOpen = true;
                },

                toggleProductPicker(item, event) {
                    if (item.productOpen) {
                        item.productOpen = false;
                    } else {
                        if (item.product_id) {
                            item.search = '';
                            item.product_id = '';
                            item.unit_price = '';
                        }
                        this.openProductPicker(item);
                        event.currentTarget.previousElementSibling.focus();
                    }
                },

                updateProductSearch(item) {
                    item.product_id = '';
                    item.unit_price = '';
                    item.productMatches = this.matchingProducts(item);
                    item.activeProductIndex = -1;
                    item.productOpen = true;
                    this.productError = '';
                },

                moveProductSelection(item, direction) {
                    if (!item.productOpen) this.openProductPicker(item);
                    if (!item.productMatches.length) return;
                    item.activeProductIndex = Math.max(0, Math.min(item.productMatches.length - 1, item.activeProductIndex + direction));
                    this.$nextTick(() => document.getElementById(`product-option-${item.key}-${item.activeProductIndex}`)?.scrollIntoView({ block: 'nearest' }));
                },

                selectActiveProduct(item) {
                    if (!item.productOpen || !item.productMatches.length) return;
                    this.chooseProduct(item, item.productMatches[Math.max(0, item.activeProductIndex)]);
                },

                chooseProduct(item, product) {
                    item.product_id = String(product.id);
                    item.search = `${product.name} — ${product.code}`;
                    item.description = '';
                    item.unit_price = product.price;
                    item.productOpen = false;
                    item.productMatches = [];
                    item.activeProductIndex = -1;
                },

                addProduct() {
                    this.items.push({ key: crypto.randomUUID(), product_id: '', search: '', description: '', quantity: 1, unit_price: '', productOpen: false, productMatches: [], activeProductIndex: -1 });
                },

                addCharge(type, description) {
                    this.charges.push({ key: crypto.randomUUID(), type, description, amount: '' });
                },

                submitForm(event) {
                    if (this.items.some(item => !item.product_id)) {
                        event.preventDefault();
                        this.productError = 'Choose each product from the search suggestions.';
                        return;
                    }
                    this.submitting = true;
                },

                amount(value) {
                    const number = Number(value);
                    return Number.isFinite(number) && number >= 0 ? number : 0;
                },

                get productsTotal() {
                    return this.items.reduce((sum, item) => sum + this.amount(item.quantity) * this.amount(item.unit_price), 0);
                },

                get chargesTotal() {
                    return this.charges.reduce((sum, charge) => sum + this.amount(charge.amount), 0);
                },

                get total() {
                    return Math.max(0, this.productsTotal + this.chargesTotal - this.amount(this.discount));
                },

                formatMoney(value) {
                    return 'Rs. ' + this.amount(value).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                },
            }));
        });
    </script>
</x-layouts::app>
