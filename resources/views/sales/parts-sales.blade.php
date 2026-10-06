<x-layouts::pos title="POS - Parts Sales">
    @section('pos-search')
    <div class="relative w-full max-w-2xl" x-data="{ query: '' }">
        <svg class="absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input id="pos-search-input" type="text" x-model="query" @input.debounce.100ms="$dispatch('search-products', query)" @keydown.enter.prevent="$dispatch('scan-product', query)" placeholder="Enter product name / SKU / Scan barcode (Alt+S)" class="h-12 w-full rounded-2xl border-2 border-slate-700 bg-[#0b2447] pl-12 pr-12 text-[15px] font-semibold text-white placeholder-slate-400 outline-none transition focus:border-red-500 focus:bg-[#06162f] focus:ring-4 focus:ring-red-500/20" autofocus>
        <button type="button" title="Focus barcode search (Alt+S)" aria-label="Focus barcode search (Alt+S)" onclick="document.getElementById('pos-search-input').focus()" class="absolute right-3 top-1/2 -translate-y-1/2 grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-700 hover:text-white transition">
            <kbd class="pos-header-key">Alt+S</kbd>
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
        </button>
    </div>
    @endsection

    @section('pos-actions')
    <div class="flex items-center gap-2 mr-4">
        @if(auth()->user()->canAccess('intake.add'))
            <a href="{{ route('intakes.create') }}" class="relative inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-red-600 px-4 text-sm font-bold text-white shadow-lg shadow-red-500/20 hover:bg-red-500 transition" title="New Bike Intake (Alt+I)">
                <kbd class="pos-header-key">Alt+I</kbd>New Bike Intake
            </a>
        @endif
        @if(auth()->user()->canAccess('job_card.create'))
            <a href="{{ route('jobs.create') }}" class="relative inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl border-2 border-slate-700 bg-transparent px-4 text-sm font-bold text-white hover:border-slate-500 hover:bg-slate-800 transition" title="Create Job Card (Alt+J)">
                <kbd class="pos-header-key">Alt+J</kbd>Create Job Card
            </a>
        @endif
        @if(auth()->user()->canAccess('sales.view_invoices'))
            <a href="{{ route('sales.parts.index') }}" class="relative inline-flex size-10 shrink-0 items-center justify-center rounded-xl border-2 border-slate-700 bg-transparent text-white hover:border-slate-500 hover:bg-slate-800 transition" title="Bills and quotations (Alt+L)" aria-label="Bills and quotations (Alt+L)">
                <kbd class="pos-header-key">Alt+L</kbd><svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M5 3h14v18l-3-2-4 2-4-2-3 2V3Z"/><path d="M8 8h8M8 12h8"/></svg>
            </a>
        @endif
    </div>
    @endsection

    <div class="flex w-full h-full overflow-hidden" x-data="posApp()" @keydown.window="handleShortcut($event)">
        <!-- Left Side: Products Grid -->
        <div class="pos-left flex flex-col relative w-full h-full">
            <!-- Store Selector & Categories -->
            <div class="flex flex-col gap-4 p-4 pb-0 bg-white border-b border-slate-200 shrink-0">
                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex items-center gap-2 bg-slate-100 rounded-xl px-3 py-2 border border-slate-200">
                        <svg class="size-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <select id="pos-store-select" class="bg-transparent border-none outline-none font-bold text-slate-700 text-sm cursor-pointer pr-4" x-model="storeId" @change="changeStore" title="Select store (Alt+T)">
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </label>

                    <button type="button" @click="openPinManager()" title="Arrange pinned products (Alt+P)" class="inline-flex h-10 items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 text-xs font-extrabold text-amber-800 transition hover:bg-amber-100 focus:outline-none focus:ring-2 focus:ring-amber-400">
                        <svg class="size-4 fill-amber-500" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="m12 2 3.1 6.3 7 .9-5 4.9 1.2 6.9L12 17.8 5.7 21l1.2-6.9-5-4.9 7-.9L12 2Z"/></svg>
                        <span>Pinned (<span x-text="pinnedCount"></span>)</span><span class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px]">Alt+P</span>
                    </button>

                    <button type="button" @click="openCarts()" title="View held bills (Alt+M)" class="inline-flex h-10 items-center gap-2 rounded-xl border border-violet-200 bg-violet-50 px-3 text-xs font-extrabold text-violet-800 transition hover:bg-violet-100 focus:outline-none focus:ring-2 focus:ring-violet-400">
                        <x-dashboard-icon name="square-3-stack-3d" class="size-4"/>
                        <span>Held bills (<span x-text="heldCarts.length"></span>)</span><span class="rounded bg-violet-100 px-1.5 py-0.5 text-[10px]">Alt+M</span>
                    </button>

                    <div class="text-slate-500 font-bold text-sm tracking-wider flex items-center gap-2 ml-auto">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span x-text="currentTime"></span>
                    </div>
                </div>

                <!-- Horizontal scrollable categories -->
                <div class="flex items-center gap-2 overflow-x-auto pb-4 hide-scrollbar snap-x" @keydown.arrow-right.prevent="cycleCategory(1)" @keydown.arrow-left.prevent="cycleCategory(-1)">
                    <button data-pos-category type="button" title="All categories (Alt+G, then arrow keys)" class="relative snap-start shrink-0 rounded-full px-5 py-2.5 text-sm font-bold transition-all border-2" style="padding-right: 3.5rem"
                        :class="activeCategory === null ? 'bg-red-600 border-red-600 text-white shadow-md shadow-red-500/20' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                        @click="activeCategory = null">
                        <kbd class="pos-key">Alt+G</kbd>All <span class="ml-1 opacity-70">({{ count($products) }})</span>
                    </button>
                    @foreach($categories as $cat)
                    <button data-pos-category type="button" title="Use arrow keys to change category" class="snap-start shrink-0 rounded-full px-5 py-2.5 text-sm font-bold transition-all border-2 flex items-center gap-2"
                        :class="activeCategory === {{ $cat->id }} ? 'bg-red-600 border-red-600 text-white shadow-md shadow-red-500/20' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                        @click="activeCategory = {{ $cat->id }}">
                        {{ $cat->name }}
                    </button>
                    @endforeach
                </div>
            </div>

            <!-- Product Grid -->
            <div id="pos-products-scroll" class="flex-1 overflow-y-auto p-4 bg-[#f7f9fc]" @scroll.passive="loadMoreOnScroll($event)">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5 gap-4 content-start">
                    <template x-for="(product, index) in visibleProducts" :key="product.id">
                        <button class="relative flex flex-col text-left rounded-2xl bg-white p-4 transition-all shadow-sm border-2 overflow-hidden group outline-none focus:ring-4 focus:ring-red-500/20"
                            :class="{
                                'border-transparent hover:border-red-300 hover:shadow-md': product.currentStock > 0 || allowNegativeStock,
                                'border-red-100 bg-red-50/50 hover:border-red-300': product.currentStock <= 0 && !allowNegativeStock,
                            }"
                            :title="product.currentStock <= 0 && !allowNegativeStock ? 'Can be quoted; sale requires stock' : 'Add to cart'"
                            @click="productClicked(product)">
                            <kbd x-show="index < 9" x-text="index + 1" class="absolute left-2 top-2 rounded bg-slate-100 px-1.5 py-0.5 text-[9px] font-black text-slate-500" :title="'Press ' + (index + 1) + ' to add'"></kbd>
                            
                            <!-- Badges -->
                            <div class="absolute top-3 right-3 flex flex-col gap-1 items-end">
                                <template x-if="product.currentStock <= 0">
                                    <span class="rounded-full bg-red-600 px-2 py-0.5 text-[10px] font-black uppercase text-white shadow-sm">Out of Stock</span>
                                </template>
                                <template x-if="product.currentStock > 0 && product.currentStock <= 8">
                                    <span class="rounded-full bg-orange-500 px-2 py-0.5 text-[10px] font-black uppercase text-white shadow-sm">Low Stock</span>
                                </template>
                                <template x-if="product.currentBatches.length > 1 && product.currentStock > 0">
                                    <span class="rounded-full bg-[#06162f] px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider text-white shadow-sm flex items-center gap-1">
                                        <svg class="size-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
                                        Multiple Prices
                                    </span>
                                </template>
                            </div>

                            <!-- Product Info -->
                            <div class="mt-2">
                                <h3 class="text-sm font-black text-slate-900 leading-tight line-clamp-2"><span x-show="product.posPinned" class="mr-1 text-amber-500" aria-label="Pinned">★</span><span x-text="product.name"></span></h3>
                                <p class="text-[11px] font-semibold tracking-wider text-slate-400 mt-1 uppercase" x-text="product.sku"></p>
                            </div>
                            
                            <div class="mt-auto pt-4 flex items-end justify-between w-full">
                                <div>
                                    <p class="text-lg font-black text-red-600 leading-none" x-text="'Rs. ' + formatNumber(product.price)"></p>
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mt-2">
                                        Stock: 
                                        <span class="text-xs ml-0.5" 
                                            :class="product.currentStock > 8 ? 'text-emerald-600' : (product.currentStock > 0 ? 'text-orange-500' : 'text-red-600')" 
                                            x-text="product.currentStock"></span>
                                    </p>
                                </div>
                                <div class="text-right">
                                    <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Unit</p>
                                    <p class="text-xs font-bold text-slate-700" x-text="product.unit"></p>
                                </div>
                            </div>
                        </button>
                    </template>
                </div>
                <div x-show="filteredProducts.length === 0" x-cloak class="py-12 text-center text-sm font-semibold text-slate-500">No products match this search.</div>
                <div x-show="visibleProductCount < filteredProducts.length" x-cloak class="mt-6 text-center">
                    <button type="button" @click="showMoreProducts()" class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-bold text-slate-700 shadow-sm transition hover:border-red-300 hover:text-red-600">Load more products</button>
                    <p class="mt-2 text-xs text-slate-500" x-text="`Showing ${Math.min(visibleProductCount, filteredProducts.length)} of ${filteredProducts.length}`"></p>
                </div>
                <!-- Notice -->
                <div class="mt-8 text-center border-t border-slate-200/60 pt-4 pb-8">
                    <p class="text-xs font-semibold text-slate-400 flex items-center justify-center gap-2">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                        Multiple price batches available for some items. Tap on product to view batch prices.
                    </p>
                </div>
            </div>
        </div>

        <!-- Right Side: Cart Panel -->
        <div class="pos-right shadow-2xl flex flex-col z-40 bg-white h-[calc(100vh-72px)]">
            <div x-show="cart.sourceQuotationId" class="border-b border-violet-200 bg-violet-50 px-4 py-2 text-xs font-bold text-violet-800" x-cloak>
                <span>Quotation <span x-text="cart.sourceQuotationNumber"></span> ready for checkout</span>
                <span x-show="cart.bikeNumber" class="block text-[11px] font-medium" x-text="'Bike: ' + cart.bikeNumber"></span>
            </div>
            <!-- Customer Section -->
            <div class="p-4 border-b border-slate-100 flex gap-2">
                <div class="relative flex-1">
                    <select id="pos-customer-select" class="w-full h-11 bg-slate-50 border border-slate-200 rounded-xl px-4 text-sm font-bold text-slate-800 outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50 transition appearance-none" x-model="cart.customerId" title="Select customer (Alt+Y)">
                        <option value="">Walk-in Customer</option>
                        <option value="new" x-show="cart.customerName" x-text="'New: ' + cart.customerName"></option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} — {{ $customer->phone }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>
                <button type="button" @click="openNewCustomer" title="Add customer (Alt+A)" aria-label="Add customer (Alt+A)" class="relative size-11 shrink-0 grid place-items-center bg-[#0b2447] text-white rounded-xl hover:bg-[#123665] transition shadow-md">
                    <kbd class="pos-key !text-white">Alt+A</kbd>
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </button>
            </div>

            <!-- Cart Items -->
            <div class="flex-1 overflow-y-auto p-4 bg-slate-50/50">
                <!-- Headers -->
                <div class="grid grid-cols-[1fr_auto_auto_auto] gap-2 mb-3 px-2 pr-10">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Product</span>
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 text-center w-[84px]">Qty</span>
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 text-right w-[80px]">Price</span>
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 text-right w-[95px]">Subtotal</span>
                </div>

                <div class="space-y-3">
                    <template x-if="!hasCartLines()">
                        <div class="flex flex-col items-center justify-center py-12 text-slate-400">
                            <svg class="size-12 mb-3 opacity-20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            <p class="text-sm font-bold">Cart is empty</p>
                            <p class="text-xs mt-1">Add items from the grid to begin</p>
                        </div>
                    </template>
                    <template x-for="(item, index) in cart.items" :key="index">
                        <div role="button" tabindex="0" @click="openItemEditor(index)" @keydown.enter.prevent="openItemEditor(index)" @keydown.space.prevent="openItemEditor(index)" :aria-label="'Edit ' + (item.display_name || item.product.name)" class="grid grid-cols-[1fr_auto_auto_auto] items-center gap-2 p-3 bg-white rounded-xl border border-slate-100 shadow-sm relative pr-10 min-h-[64px] cursor-pointer hover:border-red-200 hover:shadow-md focus:outline-none focus:ring-2 focus:ring-red-400 transition">
                            <!-- Product Detail -->
                            <div class="min-w-0">
                                <p class="text-[11px] font-black text-slate-900 leading-tight truncate" x-text="item.display_name || item.product.name" :title="item.display_name || item.product.name"></p>
                                <p class="text-[9px] font-semibold text-slate-400 mt-0.5 uppercase tracking-wider" x-text="item.product.sku"></p>
                                <template x-if="item.batch_no">
                                    <span class="inline-block mt-1 rounded bg-[#06162f] px-1 py-0.5 text-[8px] font-bold tracking-wider text-white" x-text="'B: ' + item.batch_no"></span>
                                </template>
                            </div>
                            
                            <!-- Qty -->
                            <div class="rounded-lg border border-slate-200 bg-slate-50 px-2 py-1 text-center text-xs font-black text-slate-900 w-[84px]" x-text="Number(item.qty).toLocaleString('en-US', { maximumFractionDigits: 2 })">
                            </div>
                            
                            <!-- Price -->
                            <div class="text-right w-[80px]">
                                <span class="text-xs font-black text-slate-700" x-text="formatNumber(item.unit_price)"></span>
                            </div>

                            <!-- Subtotal -->
                            <div class="text-right w-[95px]">
                                <p class="text-xs font-black text-slate-950" x-text="'Rs. ' + formatNumber(item.qty * item.unit_price)"></p>
                            </div>

                            <!-- Delete -->
                            <button type="button" @click.stop="removeItem(index)" @keydown.enter.stop @keydown.space.stop :aria-label="'Remove ' + (item.display_name || item.product.name)" class="absolute right-2 top-1/2 -translate-y-1/2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-md p-1 transition">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </template>
                    <template x-for="(charge, index) in cart.charges" :key="'charge-' + index">
                        <div class="flex items-center gap-2 rounded-xl border border-violet-100 bg-violet-50 px-3 py-2 text-xs">
                            <div class="min-w-0 flex-1"><p class="truncate font-bold text-slate-900" x-text="charge.description"></p><p class="capitalize text-[10px] text-violet-600" x-text="charge.type"></p></div>
                            <strong class="whitespace-nowrap text-slate-900" x-text="'Rs. ' + formatNumber(charge.amount)"></strong>
                            <button type="button" @click="cart.charges.splice(index, 1); recalculateTotals()" class="rounded p-1 font-bold text-red-600 hover:bg-red-50" :aria-label="'Remove ' + charge.description">×</button>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Cart Actions -->
            @if(session('shop_mode', 'workshop') !== 'helmet')
            <div class="grid grid-cols-3 gap-2 px-4 py-3 bg-white border-y border-slate-100">
                <button type="button" @click="openNote('internal')" title="Internal note (Alt+N)" class="relative flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition">
                    <kbd class="pos-key">Alt+N</kbd>
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Note</span>
                </button>
                <button type="button" @click="openNote('customer')" title="Customer note (Alt+U)" class="relative flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition">
                    <kbd class="pos-key">Alt+U</kbd>
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Cust Note</span>
                </button>
                <button type="button" @click="clearCart" title="Clear cart (Alt+X)" class="relative flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-700 transition">
                    <kbd class="pos-key">Alt+X</kbd>
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Clear</span>
                </button>
            </div>
            @endif

            <!-- Totals -->
            <div class="px-4 py-4 bg-white">
                <div class="space-y-2 mb-4">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500 font-bold">Subtotal (<span x-text="cart.items.length + cart.charges.length"></span> lines)</span>
                        <span class="font-bold text-slate-900" x-text="formatNumber(cart.subtotal)"></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-500 font-bold" title="Focus discount (Alt+D)">Discount <kbd class="text-[9px] text-slate-400">Alt+D</kbd></span>
                            <div class="flex bg-slate-100 rounded-lg p-0.5">
                                <button type="button" title="Fixed discount (Alt+F)" class="px-2 py-0.5 text-[10px] font-bold rounded-md" :class="cart.discountType === 'fixed' ? 'bg-white shadow-sm text-slate-800' : 'text-slate-400'" @click="cart.discountType = 'fixed'; recalculateTotals()"><span class="block text-[8px]">Alt+F</span>Fixed</button>
                                <button type="button" title="Percent discount (Alt+E)" class="px-2 py-0.5 text-[10px] font-bold rounded-md" :class="cart.discountType === 'percent' ? 'bg-white shadow-sm text-slate-800' : 'text-slate-400'" @click="cart.discountType = 'percent'; recalculateTotals()"><span class="block text-[8px]">Alt+E</span>%</button>
                            </div>
                        </div>
                        <input id="pos-discount-input" type="number" min="0" x-model.number="cart.discountValue" @input="recalculateTotals" class="w-24 text-right bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-sm font-bold text-slate-900 outline-none focus:border-red-400">
                    </div>
                    <div class="flex justify-between items-center text-sm font-bold text-emerald-600 bg-emerald-50/50 px-2 py-1 rounded-lg mt-2" x-show="cart.discount > 0">
                        <span>Discounted Amount</span>
                        <span x-text="'- Rs. ' + formatNumber(cart.discount)"></span>
                    </div>
                </div>
                
                <div class="flex justify-between items-end border-t border-slate-200 pt-4 mb-2">
                    <span class="text-xs font-black uppercase tracking-wider text-slate-400">Total</span>
                    <span class="text-xl font-black text-slate-900 line-through opacity-40" x-show="cart.discount > 0" x-text="formatNumber(cart.subtotal)"></span>
                </div>
                <div class="flex justify-between items-end">
                    <span class="text-lg font-black uppercase tracking-wider text-red-600">Payable</span>
                    <span class="text-3xl font-black text-red-600 tracking-tight" x-text="'Rs. ' + formatNumber(cart.total)"></span>
                </div>
            </div>

            <!-- Payment Buttons -->
            <div class="px-4 pb-4 pt-0 bg-white space-y-2 shrink-0">
                @if(session('shop_mode', 'workshop') === 'helmet')
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="holdCart" title="Hold current bill (Alt+H)" class="btn-pos bg-orange-100 text-orange-700 hover:bg-orange-200"><kbd class="pos-key">Alt+H</kbd><x-dashboard-icon name="pause" class="size-5"/><span class="pos-button-label">Hold bill</span></button>
                    <button type="button" @click="openCarts" title="View held bills (Alt+M)" class="btn-pos bg-purple-100 text-purple-700 hover:bg-purple-200"><kbd class="pos-key">Alt+M</kbd><x-dashboard-icon name="square-3-stack-3d" class="size-5"/><span class="pos-button-label" x-text="'View held (' + heldCarts.length + ')'"></span></button>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="openPayment('Cash')" title="Cash checkout (Alt+C)" class="btn-pos bg-emerald-600 text-white hover:bg-emerald-500 shadow-lg shadow-emerald-500/20"><kbd class="pos-key">Alt+C</kbd><x-dashboard-icon name="banknotes" class="size-5"/><span class="pos-button-label">Cash</span></button>
                    <button type="button" @click="clearCart" title="Cancel cart (Alt+X)" class="btn-pos bg-red-600 text-white hover:bg-red-500 shadow-lg shadow-red-500/20"><kbd class="pos-key">Alt+X</kbd><x-dashboard-icon name="x-mark" class="size-5"/><span class="pos-button-label">Cancel</span></button>
                </div>
                @else
                <div class="grid grid-cols-3 gap-2">
                    <button type="button" @click="submitSale('Quotation')" :disabled="isSubmitting || !hasCartLines() || cart.sourceQuotationId" title="Save quotation (Alt+Q)" class="btn-pos bg-slate-100 text-slate-700 hover:bg-slate-200 disabled:opacity-50"><kbd class="pos-key">Alt+Q</kbd><x-dashboard-icon name="document-text" class="size-5"/><span class="pos-button-label">Quotation</span></button>
                    <button type="button" @click="holdCart" title="Hold current bill (Alt+H)" class="btn-pos bg-orange-100 text-orange-700 hover:bg-orange-200"><kbd class="pos-key">Alt+H</kbd><x-dashboard-icon name="pause" class="size-5"/><span class="pos-button-label">Hold bill</span></button>
                    <button type="button" @click="openCarts" title="View held bills (Alt+M)" class="btn-pos bg-purple-100 text-purple-700 hover:bg-purple-200"><kbd class="pos-key">Alt+M</kbd><x-dashboard-icon name="square-3-stack-3d" class="size-5"/><span class="pos-button-label" x-text="'View held (' + heldCarts.length + ')'"></span></button>
                </div>
                <div class="grid grid-cols-4 gap-2">
                    <button type="button" @click="openPayment('Cash')" title="Cash checkout (Alt+C)" class="btn-pos bg-emerald-600 text-white hover:bg-emerald-500 shadow-lg shadow-emerald-500/20"><kbd class="pos-key">Alt+C</kbd><x-dashboard-icon name="banknotes" class="size-5"/><span class="pos-button-label">Cash</span></button>
                    <button type="button" @click="openPayment('Card')" title="Card checkout (Alt+K)" class="btn-pos bg-[#0b2447] text-white hover:bg-[#123665] shadow-lg shadow-[#0b2447]/20"><kbd class="pos-key">Alt+K</kbd><x-dashboard-icon name="credit-card" class="size-5"/><span class="pos-button-label">Card</span></button>
                    <button type="button" @click="openPayment('Bank Transfer')" title="Bank checkout (Alt+B)" class="btn-pos bg-blue-600 text-white hover:bg-blue-500 shadow-lg shadow-blue-500/20"><kbd class="pos-key">Alt+B</kbd><x-dashboard-icon name="building-library" class="size-5"/><span class="pos-button-label">Bank</span></button>
                    <button type="button" @click="clearCart" title="Cancel cart (Alt+X)" class="btn-pos bg-red-600 text-white hover:bg-red-500 shadow-lg shadow-red-500/20"><kbd class="pos-key">Alt+X</kbd><x-dashboard-icon name="x-mark" class="size-5"/><span class="pos-button-label">Cancel</span></button>
                </div>
                @endif
            </div>
        </div>

        <!-- Modals -->

        <dialog id="pos-item-modal" class="m-auto w-[calc(100%-2rem)] max-w-md rounded-2xl border-0 p-0 shadow-2xl backdrop:bg-slate-900/60" @close="editingItemIndex = null">
            <div class="space-y-5 bg-white p-5 sm:p-6">
                <div class="flex items-start justify-between gap-3">
                    <div><h2 class="text-lg font-black text-slate-900">Edit cart item</h2><p class="mt-1 text-xs text-slate-500" x-text="itemDraft.sku"></p></div>
                    <button type="button" @click="closeDialog('pos-item-modal')" aria-label="Close item editor" class="rounded-lg p-2 text-slate-400 hover:bg-slate-100 hover:text-slate-700"><x-dashboard-icon name="x-mark" class="size-5"/></button>
                </div>
                <label class="block text-sm font-bold text-slate-700">Name
                    <input id="pos-item-name" type="text" maxlength="255" x-model="itemDraft.name" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-semibold text-slate-900 focus:border-red-400 focus:ring-2 focus:ring-red-100" required>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block text-sm font-bold text-slate-700">Quantity
                        <input type="number" min="0.01" step="0.01" x-model="itemDraft.qty" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-semibold text-slate-900 focus:border-red-400 focus:ring-2 focus:ring-red-100" required>
                    </label>
                    <label class="block text-sm font-bold text-slate-700">Unit price (Rs.)
                        <input type="number" min="0" step="0.01" x-model="itemDraft.unit_price" class="mt-1.5 w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm font-semibold text-slate-900 focus:border-red-400 focus:ring-2 focus:ring-red-100" required>
                    </label>
                </div>
                <div class="flex items-center justify-between rounded-xl bg-slate-50 px-4 py-3 text-sm"><span class="font-bold text-slate-500">Line total</span><strong class="text-base text-slate-900" x-text="'Rs. ' + formatNumber(Number(itemDraft.qty) * Number(itemDraft.unit_price))"></strong></div>
                <p class="text-xs text-slate-500">The edited name will appear on this bill or quotation.</p>
                <div class="flex gap-3"><button type="button" @click="closeDialog('pos-item-modal')" class="flex-1 rounded-xl bg-slate-100 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-200">Cancel</button><button type="button" @click="saveItemEditor" title="Save item (Alt+Enter)" class="flex-1 rounded-xl bg-[#0b2447] px-4 py-2.5 text-sm font-bold text-white hover:bg-[#123665]"><span class="mr-1 text-[10px] opacity-70">Alt+Enter</span>Save item</button></div>
            </div>
        </dialog>

        <dialog id="pos-customer-modal" class="m-auto w-full max-w-sm rounded-2xl border-0 p-0 shadow-2xl backdrop:bg-slate-900/60">
            <div class="space-y-4 bg-white p-6">
                <h2 class="text-lg font-black">New POS Customer</h2>
                <label class="block text-sm font-bold">Name <input id="pos-new-customer-name" type="text" x-model.trim="newCustomer.name" maxlength="150" class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
                <label class="block text-sm font-bold">Phone <input type="tel" x-model.trim="newCustomer.phone" maxlength="30" required class="mt-1 w-full rounded-xl border border-slate-300 px-3 py-2"></label>
                <p class="text-xs text-slate-500">The customer is saved when the sale or quotation is confirmed.</p>
                <div class="flex gap-2"><button type="button" @click="closeDialog('pos-customer-modal')" class="flex-1 rounded-xl bg-slate-100 px-4 py-2 font-bold"><span class="block text-[9px]">Esc</span>Cancel</button><button type="button" @click="useNewCustomer" title="Use customer (Alt+Enter)" class="flex-1 rounded-xl bg-[#0b2447] px-4 py-2 font-bold text-white"><span class="block text-[9px]">Alt+Enter</span>Use Customer</button></div>
            </div>
        </dialog>

        <dialog id="pos-note-modal" class="m-auto w-full max-w-md rounded-2xl border-0 p-0 shadow-2xl backdrop:bg-slate-900/60">
            <div class="space-y-4 bg-white p-6">
                <h2 class="text-lg font-black" x-text="editingNote === 'customer' ? 'Customer note' : 'Internal note'"></h2>
                <p class="text-xs text-slate-500" x-text="editingNote === 'customer' ? 'This note appears on the printed bill.' : 'This note stays on the internal invoice.'"></p>
                <textarea id="pos-note-input" x-model="noteDraft" maxlength="2000" rows="4" class="w-full rounded-xl border border-slate-300 p-3" placeholder="Enter note..."></textarea>
                <div class="flex gap-2"><button type="button" @click="closeDialog('pos-note-modal')" class="flex-1 rounded-xl bg-slate-100 px-4 py-2 font-bold"><span class="block text-[9px]">Esc</span>Cancel</button><button type="button" @click="saveNote" title="Save note (Alt+Enter)" class="flex-1 rounded-xl bg-[#0b2447] px-4 py-2 font-bold text-white"><span class="block text-[9px]">Alt+Enter</span>Save Note</button></div>
            </div>
        </dialog>

        <dialog id="pos-carts-modal" class="m-auto w-[calc(100%-2rem)] max-w-2xl rounded-2xl border-0 p-0 shadow-2xl backdrop:bg-slate-900/60">
            <div class="space-y-4 bg-white p-5 sm:p-6">
                <div>
                    <h2 class="text-lg font-black">Held bills (<span x-text="heldCarts.length"></span>)</h2>
                    <p class="mt-1 text-xs text-slate-500">View the items and total, then resume a bill to continue checkout. Held bills stay on this device.</p>
                </div>
                <div class="max-h-[65vh] space-y-3 overflow-y-auto pr-1">
                    <template x-if="heldCarts.length === 0"><p class="rounded-xl bg-slate-50 p-5 text-center text-sm text-slate-500">No held bills yet. Add items and select Hold bill to save one here.</p></template>
                    <template x-for="held in heldCarts" :key="held.id">
                        <div class="rounded-xl border border-slate-200 bg-white p-3 sm:p-4">
                            <div class="flex flex-wrap items-center gap-3">
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-extrabold text-slate-900" x-text="held.name"></p>
                                    <p class="mt-0.5 text-xs text-slate-500"><span x-text="storeOptions[held.storeId] || 'Store'">Store</span><span x-show="held.heldAt" x-text="' · ' + formatHeldTime(held.heldAt)"></span></p>
                                    <p class="mt-1 text-sm font-bold text-slate-700" x-text="held.cart.items.length + (held.cart.charges?.length || 0) + ' lines · Rs. ' + formatNumber(held.cart.total)"></p>
                                </div>
                                <button type="button" @click="viewingHeldId = viewingHeldId === held.id ? null : held.id" :aria-expanded="viewingHeldId === held.id" class="rounded-lg border border-violet-200 bg-violet-50 px-3 py-2 text-xs font-bold text-violet-800 hover:bg-violet-100" x-text="viewingHeldId === held.id ? 'Hide details' : 'View bill'"></button>
                                <button type="button" @click="resumeCart(held.id)" class="rounded-lg bg-[#0b2447] px-3 py-2 text-xs font-bold text-white hover:bg-[#123665]">Resume</button>
                                <button type="button" @click="deleteHeldCart(held.id)" class="rounded-lg bg-red-50 px-3 py-2 text-xs font-bold text-red-600 hover:bg-red-100">Delete</button>
                            </div>
                            <div x-show="viewingHeldId === held.id" x-cloak class="mt-4 border-t border-slate-200 pt-3">
                                <div class="space-y-2">
                                    <template x-for="(item, index) in held.cart.items" :key="index">
                                        <div class="flex items-start justify-between gap-4 text-sm">
                                            <div class="min-w-0"><p class="font-semibold text-slate-800" x-text="item.display_name || item.product?.name || 'Product'"></p><p class="text-xs text-slate-500" x-text="item.qty + ' × Rs. ' + formatNumber(item.unit_price)"></p></div>
                                            <span class="shrink-0 font-bold text-slate-800" x-text="'Rs. ' + formatNumber(item.qty * item.unit_price - (item.discount || 0))"></span>
                                        </div>
                                    </template>
                                </div>
                                <div class="mt-3 space-y-1 border-t border-slate-100 pt-3 text-xs">
                                    <div class="flex justify-between text-slate-600"><span>Subtotal</span><span x-text="'Rs. ' + formatNumber(held.cart.subtotal)"></span></div>
                                    <div x-show="Number(held.cart.discount) > 0" class="flex justify-between text-slate-600"><span>Discount</span><span x-text="'- Rs. ' + formatNumber(held.cart.discount)"></span></div>
                                    <div x-show="Number(held.cart.tax) > 0" class="flex justify-between text-slate-600"><span>Tax</span><span x-text="'Rs. ' + formatNumber(held.cart.tax)"></span></div>
                                    <div class="flex justify-between pt-1 text-sm font-black text-slate-900"><span>Payable</span><span x-text="'Rs. ' + formatNumber(held.cart.total)"></span></div>
                                </div>
                                <p x-show="held.cart.customerNote" class="mt-3 rounded-lg bg-slate-50 p-2 text-xs text-slate-600"><strong>Customer note: </strong><span x-text="held.cart.customerNote"></span></p>
                            </div>
                        </div>
                    </template>
                </div>
                <div class="flex gap-2 border-t border-slate-100 pt-4"><button type="button" @click="closeDialog('pos-carts-modal')" class="flex-1 rounded-xl bg-slate-100 px-4 py-2 font-bold"><span class="block text-[9px]">Esc</span>Close</button><button type="button" @click="newCart" title="Start another cart (Alt+Enter)" class="flex-1 rounded-xl bg-purple-600 px-4 py-2 font-bold text-white"><span class="block text-[9px]">Alt+Enter</span>New Cart</button></div>
            </div>
        </dialog>
        
        <!-- Print Bill Modal -->
        <dialog id="print-bill-modal" @close="window.location.reload()" class="backdrop:bg-slate-900/60 backdrop:backdrop-blur-sm bg-transparent w-full max-w-sm mx-auto p-0 rounded-3xl shadow-2xl overflow-hidden transition-all">
            <div class="bg-white">
                <div class="p-6 text-center">
                    <div class="w-16 h-16 bg-emerald-100 text-emerald-600 rounded-full flex items-center justify-center mx-auto mb-4">
                        <svg class="size-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"></path></svg>
                    </div>
                    <h3 class="text-xl font-black text-slate-800 mb-2" x-text="lastDocumentType === 'Quotation' ? 'Quotation Saved!' : 'Sale Completed!'"></h3>
                    <p class="text-sm font-bold text-slate-500 mb-6" x-text="lastDocumentType === 'Quotation' ? 'Print or view the quotation now?' : 'Print or view the bill now?'"></p>
                    <div class="grid grid-cols-2 gap-3 mb-3">
                        <button @click="openDocument('80mm')" type="button" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition text-sm">Print 80mm</button>
                        <a :href="lastInvoiceId ? @js(route('sales.invoices.pdf', ['invoice' => '__ID__', 'format' => '80mm'])).replace('__ID__', lastInvoiceId) : '#'" class="flex-1 py-3 text-center bg-slate-100 text-slate-700 font-bold rounded-xl text-sm">80mm PDF</a>
                        <button @click="openDocument('a5')" type="button" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition text-sm">Print A5</button>
                    </div>
                    <div class="flex gap-3">
                        <button @click="closeDocument" type="button" class="flex-1 py-3 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-xl transition text-sm"><span class="block text-[9px]">Esc</span>Close</button>
                        <button @click="openDocument('a4')" type="button" class="flex-1 py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-xl shadow-lg shadow-indigo-200 transition text-sm"><span class="block text-[9px]">Alt+P</span>Print A4</button>
                        <button @click="downloadDocument" type="button" class="flex-1 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-xl transition text-sm"><span class="block text-[9px]">Alt+D</span>PDF</button>
                    </div>
                </div>
            </div>
        </dialog>

        <!-- Batch Selection Modal -->
        <dialog id="batch-selection-modal" class="backdrop:bg-slate-900/50 backdrop:backdrop-blur-sm bg-transparent w-full max-w-lg mx-auto p-4 rounded-3xl shadow-2xl overflow-visible transition-all">
            <div class="w-full max-w-2xl bg-white flex flex-col max-h-[85vh]">
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h2 class="text-xl font-black text-slate-900">Select Stock Batch</h2>
                        <p class="text-sm font-semibold text-slate-500 mt-1">Multiple price batches exist for <span class="text-red-600 font-bold" x-text="activeProductForBatch?.name"></span></p>
                    </div>
                    <button @click="document.getElementById('batch-selection-modal').close()" class="size-10 grid place-items-center rounded-full hover:bg-slate-100 text-slate-400 hover:text-slate-700 transition">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                <div class="p-6 overflow-y-auto bg-slate-50/50 flex-1">
                    <div class="space-y-3">
                        <template x-for="batch in activeProductForBatch?.currentBatches" :key="batch.id">
                            <label class="flex cursor-pointer items-center justify-between rounded-2xl border-2 border-slate-200 bg-white p-5 transition hover:border-red-400 hover:shadow-md [&:has(:checked)]:border-red-600 [&:has(:checked)]:bg-red-50/50 [&:has(:checked)]:shadow-lg shadow-red-500/10">
                                <div>
                                    <div class="flex items-center gap-3">
                                        <input type="radio" name="selected_batch" :value="batch.id" x-model="selectedBatchId" class="size-5 text-red-600 focus:ring-red-600 border-slate-300">
                                        <span class="font-black text-slate-900 text-lg" x-text="batch.batch_no"></span>
                                        <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[10px] font-bold tracking-widest text-slate-500 uppercase" x-text="batch.date"></span>
                                    </div>
                                    <div class="mt-3 flex items-center gap-6">
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Cost Price</p>
                                            <p class="text-sm font-bold text-slate-600" x-text="'Rs. ' + formatNumber(batch.cost)"></p>
                                        </div>
                                        <div>
                                            <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Selling Price</p>
                                            <p class="text-sm font-black text-red-600" x-text="'Rs. ' + formatNumber(batch.selling)"></p>
                                        </div>
                                    </div>
                                </div>
                                <div class="text-right border-l border-slate-100 pl-6 py-2">
                                    <span class="block text-3xl font-black text-emerald-600 leading-none" x-text="batch.qty"></span>
                                    <span class="text-[10px] font-black uppercase tracking-widest text-slate-400 mt-2 block">Available Qty</span>
                                </div>
                            </label>
                        </template>
                    </div>
                </div>
                <div class="p-6 border-t border-slate-100 flex justify-end gap-3 bg-white rounded-b-3xl">
                    <button type="button" class="btn-secondary px-8 py-3 rounded-xl font-bold" @click="document.getElementById('batch-selection-modal').close()"><span class="block text-[9px]">Esc</span>Cancel</button>
                    <button type="button" class="btn-primary px-8 py-3 rounded-xl font-bold bg-red-600 hover:bg-red-700" :disabled="!selectedBatchId" @click="confirmBatchSelection" title="Add batch (Alt+Enter)"><span class="block text-[9px]">Alt+Enter</span>Add Selected Batch</button>
                </div>
            </div>
        </dialog>

        <!-- Payment Modal -->
        @php
            $cashAccountId = $accounts->firstWhere('code', App\Models\Account::systemCode('main'))?->id ?? $accounts->firstWhere('type', 'main')?->id ?? $accounts->first()?->id;
            $bankAccountId = $accounts->firstWhere('code', App\Models\Account::systemCode('bank'))?->id ?? $accounts->firstWhere('type', 'bank')?->id ?? $cashAccountId;
        @endphp
        <dialog id="payment-modal" aria-labelledby="checkout-title" class="pos-payment-modal m-auto w-[calc(100%-2rem)] max-w-3xl max-h-[90dvh] rounded-2xl border-0 p-0 shadow-2xl backdrop:bg-slate-900/60 overflow-hidden">
            <div class="flex max-h-[90dvh] flex-col bg-white text-slate-800">
                <div class="shrink-0 border-t-4 border-indigo-500 border-b border-slate-100 px-5 py-4">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="checkout-title" class="text-lg font-black text-slate-900">Checkout POS Sale</h2>
                        <button type="button" aria-label="Close checkout" @click="document.getElementById('payment-modal').close()" class="size-11 grid place-items-center rounded-full bg-slate-100 text-slate-500 hover:bg-slate-200">
                            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <div class="min-h-0 overflow-y-auto">
                    <div class="grid gap-0 sm:grid-cols-2">
                        <div class="space-y-4 p-5 sm:p-6">
                            <div class="flex items-center justify-between gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/60 px-4 py-4">
                                <span class="text-xs font-black uppercase text-slate-500">Total Payable</span>
                                <span class="text-2xl font-black text-indigo-600" x-text="'Rs. ' + formatNumber(cart.total)"></span>
                            </div>
                            <label class="block"><span class="label uppercase">Payment Method</span>
                                <select x-model="paymentMethod" @change="accountId=defaultPaymentAccount(paymentMethod); activePaymentIndex=null" class="field">
                                    @if(session('shop_mode', 'workshop') === 'helmet')
                                        <option>Cash</option><option>Cheque</option><option>Credit/Due</option>
                                    @else
                                        <option>Cash</option><option>Card</option><option>Bank Transfer</option><option>Cheque</option><option>Credit/Due</option>
                                    @endif
                                </select>
                            </label>
                            <label class="block" x-show="paymentMethod !== 'Credit/Due'"><span class="label uppercase">Account <kbd class="text-[9px] normal-case">Alt+O</kbd></span>
                                <select id="pos-account-select" x-model="accountId" class="field" title="Select account (Alt+O)">
                                    <option value="">Select account</option>
                                    @foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach
                                </select>
                            </label>
                            <label class="block" x-show="paymentMethod !== 'Credit/Due'"><span class="label uppercase">Payment Amount <kbd class="text-[9px] normal-case">Alt+P</kbd></span>
                                <div class="relative"><span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-sm font-bold text-slate-400">Rs.</span>
                                    <input id="checkout-paid-amount" type="number" min="0" step=".01" inputmode="decimal" x-model.number="paidAmount" @focus="activePaymentIndex=null" class="field !pl-14 text-xl font-black" title="Paid amount (Alt+P)">
                                </div>
                            </label>
                            <div x-show="paymentMethod==='Cash'" class="flex items-center justify-between rounded-xl border border-emerald-100 bg-emerald-50/60 p-4">
                                <span class="text-xs font-black uppercase text-slate-500">Change Due</span>
                                <span class="text-xl font-black text-emerald-600" x-text="'Rs. ' + formatNumber(Math.max(0, Number(paidAmount) + extraPayments.reduce((n,r)=>n+Number(r.amount||0),0) - cart.total))"></span>
                            </div>
                            <div x-show="cart.discount > 0" class="flex justify-between text-xs font-bold text-amber-700"><span>Discount Applied</span><span x-text="'- Rs. ' + formatNumber(cart.discount)"></span></div>
                        </div>
                        <div x-show="paymentMethod !== 'Credit/Due' || extraPayments.length" class="border-t border-slate-100 bg-slate-50/40 p-5 sm:border-t-0 sm:border-l sm:p-6">
                            <div class="mb-3 flex items-center justify-between gap-2"><span class="text-xs font-black uppercase text-slate-500">Quick Cash / Numpad</span><span class="text-[10px] font-bold text-indigo-600" x-text="'Payment ' + (activePaymentIndex===null ? 1 : activePaymentIndex+2)"></span></div>
                            <div class="mb-3 grid grid-cols-4 gap-2">
                                @foreach([500, 1000, 2000, 5000] as $shortcutIndex => $shortcutVal)
                                    <button @click="setPaymentAmount({{ $shortcutVal }})" type="button" title="Set cash amount (Alt+{{ $shortcutIndex + 1 }})" class="min-h-14 rounded-xl border border-slate-200 bg-white px-1 py-2 text-[11px] font-black text-slate-700 hover:border-indigo-300 active:bg-indigo-100"><kbd class="block text-[9px] text-slate-400">Alt+{{ $shortcutIndex + 1 }}</kbd>Rs. {{ $shortcutVal }}</button>
                                @endforeach
                            </div>
                            <div class="grid grid-cols-3 gap-2">
                                @foreach([1,2,3,4,5,6,7,8,9] as $numKey)<button @click="pressKey('{{ $numKey }}')" type="button" class="min-h-14 rounded-xl border border-slate-200 bg-white text-xl font-black text-slate-800 shadow-sm hover:bg-indigo-50 active:bg-indigo-100">{{ $numKey }}</button>@endforeach
                                <button @click="pressKey('C')" type="button" aria-label="Clear payment amount" class="min-h-14 rounded-xl bg-red-50 font-black text-red-600 active:bg-red-100">C</button>
                                <button @click="pressKey('0')" type="button" class="min-h-14 rounded-xl border border-slate-200 bg-white text-xl font-black shadow-sm active:bg-indigo-100">0</button>
                                <button @click="pressKey('B')" type="button" aria-label="Backspace" class="min-h-14 rounded-xl bg-slate-100 text-xl font-black text-slate-600 active:bg-slate-200">⌫</button>
                            </div>
                        </div>
                    </div>
                    <div x-cloak x-show="paymentMethod==='Cheque'" class="payment-entry mx-5 mb-4 rounded-xl border border-amber-100 bg-amber-50/70 p-3 space-y-2 sm:mx-6">
                        <p class="text-xs font-bold text-amber-800">Cheque amount stays on hold until passed.</p>
                        <div class="payment-fields">
                            <label><span class="label">Cheque Date *</span><input type="date" x-model="chequeDate" class="field"></label>
                            <label><span class="label">Cheque Number *</span><input x-model="chequeNumber" maxlength="80" class="field"></label>
                            <label><span class="label">Bank *</span><input x-model="chequeBank" maxlength="120" class="field"></label>
                        </div>
                    </div>
                    <div class="border-t border-slate-100 px-5 py-4 space-y-3 sm:px-6">
                        <div class="flex items-center justify-between gap-3"><h3 class="text-sm font-bold">Split Payments</h3><button type="button" class="min-h-12 rounded-xl border border-slate-200 bg-white px-4 text-xs font-bold text-slate-700 shadow-sm active:bg-indigo-50" @click="extraPayments.push({method:'Cheque',amount:0,account_id:defaultPaymentAccount('Cheque'),cheque_date:'',cheque_number:'',bank_name:''})">＋ Add Payment</button></div>
                        <template x-for="(row,index) in extraPayments" :key="index">
                            <div class="payment-entry rounded-xl border border-slate-200 bg-slate-50/70 p-3 space-y-3">
                                <div class="flex items-center justify-between text-xs"><span class="font-bold text-slate-500" x-text="'Payment ' + (index + 2)"></span><button type="button" class="min-h-11 px-2 font-bold text-red-600" @click="extraPayments.splice(index,1); activePaymentIndex=null">Remove</button></div>
                                <div class="payment-fields">
                                    <label><span class="label">Method</span><select class="field" x-model="row.method" @change="row.account_id=defaultPaymentAccount(row.method)">@foreach(['Cash','Card','Bank Transfer','Cheque','QR'] as $method)<option>{{ $method }}</option>@endforeach</select></label>
                                    <label><span class="label">Amount</span><input type="number" min="0" step=".01" class="field" inputmode="decimal" x-model.number="row.amount" @focus="activePaymentIndex=index"></label>
                                    <label><span class="label">Account</span><select class="field" x-model="row.account_id"><option value="">Select account</option>@foreach($accounts as $account)<option value="{{ $account->id }}">{{ $account->name }}</option>@endforeach</select></label>
                                </div>
                                <div x-show="row.method==='Cheque'" class="border-t border-slate-200 pt-3 space-y-2">
                                    <div class="payment-fields">
                                        <label><span class="label">Cheque Date *</span><input class="field" type="date" x-model="row.cheque_date"></label>
                                        <label><span class="label">Cheque Number *</span><input class="field" x-model="row.cheque_number" maxlength="80"></label>
                                        <label><span class="label">Bank *</span><input class="field" x-model="row.bank_name" maxlength="120"></label>
                                    </div>
                                    <p class="text-xs text-amber-700">On hold until the cheque is marked passed.</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="shrink-0 border-t border-slate-200 bg-white px-5 py-4 space-y-3">
                    <div class="grid grid-cols-3 gap-2 text-xs font-bold">
                        <div class="rounded-lg bg-emerald-50 px-3 py-2 text-emerald-700"><span class="block mb-1">Paid</span><span x-text="'Rs. ' + formatNumber(checkoutPayments().filter(r=>r.method!=='Cheque').reduce((n,r)=>n+Number(r.amount),0))"></span></div>
                        <div class="rounded-lg bg-amber-50 px-3 py-2 text-amber-700"><span class="block mb-1">Cheque Hold</span><span x-text="'Rs. ' + formatNumber(checkoutPayments().filter(r=>r.method==='Cheque').reduce((n,r)=>n+Number(r.amount),0))"></span></div>
                        <div class="rounded-lg bg-red-50 px-3 py-2 text-red-600"><span class="block mb-1">Due</span><span x-text="'Rs. ' + formatNumber(Math.max(0,cart.total-checkoutPayments().reduce((n,r)=>n+Number(r.amount),0)))"></span></div>
                    </div>
                    <button type="button" class="min-h-14 w-full rounded-xl bg-red-600 py-3 font-bold text-white shadow-lg shadow-red-500/15 hover:bg-red-700 disabled:opacity-50" @click="submitSale('Parts Sale')" :disabled="isSubmitting" title="Confirm sale (Alt+Enter)">
                        <span x-show="!isSubmitting">Confirm Sale</span><span x-show="isSubmitting">Processing...</span><kbd class="ml-2 text-[10px] font-normal opacity-75">Alt+Enter</kbd>
                    </button>
                </div>
            </div>
        </dialog>

        <dialog id="pos-pin-modal" class="m-auto w-[min(94vw,30rem)] max-h-[85vh] rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/50" @close="pinOrderDraft = []">
            <div class="border-b border-slate-100 px-5 py-4"><h2 class="text-lg font-black">Arrange pinned products</h2><p class="mt-1 text-sm text-slate-500">Pinned products appear first in POS. Use the arrows to set their order.</p></div>
            <div class="max-h-[55vh] space-y-2 overflow-y-auto p-5">
                <p x-show="pinOrderDraft.length === 0" class="rounded-xl bg-slate-50 p-6 text-center text-sm text-slate-500">Star products in Products &amp; Stock to add them here.</p>
                <template x-for="(product, index) in pinOrderDraft" :key="product.id">
                    <div class="flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3">
                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-amber-100 text-sm font-black text-amber-700" x-text="index + 1"></span>
                        <div class="min-w-0 flex-1"><p class="truncate text-sm font-bold" x-text="product.name"></p><p class="truncate text-xs text-slate-500" x-text="product.sku"></p></div>
                        <button type="button" @click="movePin(index, -1)" :disabled="index === 0" :aria-label="'Move ' + product.name + ' up'" title="Move up" class="grid size-8 place-items-center rounded-lg border border-slate-200 bg-white font-bold hover:bg-amber-50 disabled:opacity-30">↑</button>
                        <button type="button" @click="movePin(index, 1)" :disabled="index === pinOrderDraft.length - 1" :aria-label="'Move ' + product.name + ' down'" title="Move down" class="grid size-8 place-items-center rounded-lg border border-slate-200 bg-white font-bold hover:bg-amber-50 disabled:opacity-30">↓</button>
                    </div>
                </template>
            </div>
            <div class="flex gap-3 border-t border-slate-100 p-5"><button type="button" @click="closeDialog('pos-pin-modal')" class="flex-1 rounded-xl bg-slate-100 px-4 py-3 text-sm font-bold hover:bg-slate-200">Cancel</button><button type="button" @click="savePinOrder()" :disabled="isSavingPins || !pinOrderDraft.length" title="Save pinned order (Alt+Enter)" class="flex-1 rounded-xl bg-amber-500 px-4 py-3 text-sm font-bold text-white hover:bg-amber-600 disabled:opacity-50"><span x-text="isSavingPins ? 'Saving...' : 'Save order'"></span></button></div>
        </dialog>

    </div>

    @push('scripts')
    <style>
        [x-cloak] { display: none !important; }
        .btn-pos {
            position: relative;
            display: flex;
            min-height: 60px;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 3px;
            border-radius: .75rem;
            padding: 13px 4px 8px;
            font-size: 11px;
            font-weight: 700;
            line-height: 1.1;
            letter-spacing: .02em;
            text-transform: uppercase;
            transition: background-color .2s, box-shadow .2s;
        }
        .btn-pos svg { flex: none; }
        .pos-button-label { display: block; white-space: nowrap; }
        .pos-key {
            position: absolute;
            right: .35rem;
            top: .2rem;
            border-radius: 4px;
            background: rgba(148,163,184,.12);
            padding: 2px 3px;
            font-size: 8px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: 0;
            text-transform: none;
            opacity: .75;
        }
        .btn-pos .pos-key {
            right: 5px;
            top: 4px;
            border-radius: 4px;
            background: rgba(255,255,255,.22);
            padding: 2px 3px;
        }
        .pos-header-key {
            position: absolute;
            right: .3rem;
            top: -.45rem;
            border-radius: .25rem;
            border: 1px solid rgba(255,255,255,.35);
            background: #0b2447;
            color: #fff;
            padding: 2px 4px;
            font-size: 8px;
            font-weight: 800;
            line-height: 1;
            box-shadow: 0 1px 3px rgba(0,0,0,.18);
        }
        /* Custom scrollbar for pos */
        .hide-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .hide-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('posApp', () => ({
                productsData: @json($productOptions),
                importQuotationData: {{ Illuminate\Support\Js::from($importQuotation) }},
                storeOptions: @json($stores->pluck('name', 'id')),
                categoryIds: [null, ...@json($categories->pluck('id')->values())],
                isHelmetShop: {{ session('shop_mode', 'workshop') === 'helmet' ? 'true' : 'false' }},
                storeId: {{ $stores->first()->id ?? 1 }},
                allowNegativeStock: {{ filter_var(\App\Models\Setting::valueFor('negative_stock', false), FILTER_VALIDATE_BOOL) ? 'true' : 'false' }},
                activeCategory: null,
                query: '',
                visibleProductCount: 60,
                
                cart: {
                    customerId: '',
                    customerName: '',
                    customerPhone: '',
                    internalNote: '',
                    customerNote: '',
                    items: [],
                    charges: [],
                    sourceQuotationId: null,
                    sourceQuotationNumber: '',
                    bikeNumber: '',
                    subtotal: 0,
                    discountType: 'fixed',
                    discountValue: 0,
                    discount: 0,
                    tax: 0,
                    total: 0
                },

                activeProductForBatch: null,
                selectedBatchId: null,
                lastInvoiceToken: null,
                lastInvoiceId: null,
                lastDocumentType: 'Parts Sale',
                heldCarts: [],
                viewingHeldId: null,
                newCustomer: { name: '', phone: '' },
                editingNote: 'internal',
                noteDraft: '',
                editingItemIndex: null,
                itemDraft: { name: '', sku: '', qty: 1, unit_price: 0 },
                pinOrderDraft: [],
                isSavingPins: false,

                paymentMethod: 'Cash',
                accountId: '',
                paidAmount: 0,
                activePaymentIndex: null,
                chequeDate: '', chequeNumber: '', chequeBank: '', extraPayments: [],
                isSubmitting: false,
                submissionToken: crypto.randomUUID(),

                currentTime: '',
                selectedStoreId: null,
                cartStorageWarningShown: false,

                init() {
                    try {
                        const saved = JSON.parse(localStorage.getItem('pos_cart') || 'null');
                        if (saved && typeof saved === 'object' && Array.isArray(saved.items)) {
                            this.cart = { ...this.cart, ...saved };
                        }
                    } catch (e) {}
                    try {
                        this.heldCarts = JSON.parse(localStorage.getItem('pos_held_carts') || '[]');
                        if (!Array.isArray(this.heldCarts)) this.heldCarts = [];
                    } catch (e) { this.heldCarts = []; }

                    this.storeId = localStorage.getItem('pos_store_id') || this.storeId;
                    this.selectedStoreId = this.storeId;

                    this.recalculateStocks();
                    this.cart.items = this.restoreCartItems(this.cart.items);
                    if (!Array.isArray(this.cart.charges)) this.cart.charges = [];
                    if (this.importQuotationData) {
                        this.loadQuotation(this.importQuotationData);
                        const url = new URL(window.location.href);
                        url.searchParams.delete('quotation');
                        window.history.replaceState({}, '', url.toString());
                    }
                    this.recalculateTotals();
                    this.currentTime = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second:'2-digit' });
                    setInterval(() => {
                        this.currentTime = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second:'2-digit' });
                    }, 1000);

                    window.addEventListener('search-products', (e) => {
                        this.query = e.detail;
                    });
                    window.addEventListener('scan-product', (e) => this.scanProduct(e.detail));
                    window.addEventListener('focus', () => this.refreshPins());
                    this.$watch('query', () => this.resetProductWindow());
                    this.$watch('activeCategory', () => this.resetProductWindow());

                    this.$watch('cart', () => this.persistCart());
                    this.$watch('heldCarts', () => this.persistHeldCarts());
                    window.addEventListener('pagehide', () => {
                        this.persistCart();
                        this.persistHeldCarts();
                    });
                    this.persistCart();
                },

                restoreCartItems(items) {
                    if (!Array.isArray(items)) return [];
                    return items.filter(item => item?.product?.id).map(item => ({
                        ...item,
                        product: this.productsData.find(product => product.id == item.product.id) || item.product,
                    }));
                },

                hasCartLines() {
                    return this.cart.items.length > 0 || this.cart.charges.length > 0;
                },

                loadQuotation(quote) {
                    if (this.cart.sourceQuotationId == quote.id) return;
                    if (this.hasCartLines()) this.holdCart();
                    this.storeId = String(quote.store_id);
                    this.selectedStoreId = this.storeId;
                    this.recalculateStocks();
                    this.cart.customerId = quote.customer_id ? String(quote.customer_id) : '';
                    this.cart.internalNote = quote.internal_note || '';
                    this.cart.customerNote = quote.customer_note || '';
                    this.cart.discountType = 'fixed';
                    this.cart.discountValue = Number(quote.discount) || 0;
                    this.cart.tax = Number(quote.tax) || 0;
                    this.cart.sourceQuotationId = quote.id;
                    this.cart.sourceQuotationNumber = quote.number;
                    this.cart.bikeNumber = quote.bike || '';
                    this.cart.items = quote.items.map(line => {
                        const product = this.productsData.find(p => p.id == line.product_id);
                        const batch = product?.currentBatches?.find(b => b.id == line.stock_batch_id);
                        return {
                            product,
                            stock_batch_id: batch?.id || null,
                            batch_no: batch?.batch_no || null,
                            cost_price: batch?.cost ?? product?.cost_price ?? 0,
                            unit_price: Number(line.unit_price),
                            qty: Number(line.quantity),
                            max_qty: batch?.qty ?? product?.currentStock ?? 0,
                            display_name: line.description || null,
                            discount: Number(line.discount) || 0,
                        };
                    }).filter(line => line.product);
                    this.cart.charges = quote.charges.map(charge => ({ ...charge, amount: Number(charge.amount) }));
                    this.recalculateTotals();
                    this.persistCart();
                    workshopNotify('Quotation loaded into POS. Review the cart before checkout.');
                },

                cartSnapshot() {
                    return {
                        customerId: this.cart.customerId,
                        customerName: this.cart.customerName,
                        customerPhone: this.cart.customerPhone,
                        internalNote: this.cart.internalNote,
                        customerNote: this.cart.customerNote,
                        sourceQuotationId: this.cart.sourceQuotationId,
                        sourceQuotationNumber: this.cart.sourceQuotationNumber,
                        bikeNumber: this.cart.bikeNumber,
                        items: this.cart.items.map(item => ({
                            product: { id: item.product.id, name: item.product.name, sku: item.product.sku },
                            stock_batch_id: item.stock_batch_id,
                            batch_no: item.batch_no,
                            cost_price: item.cost_price,
                            unit_price: item.unit_price,
                            qty: item.qty,
                            display_name: item.display_name || null,
                            max_qty: item.max_qty,
                            discount: item.discount,
                        })),
                        charges: this.cart.charges.map(charge => ({ type: charge.type, description: charge.description, amount: charge.amount })),
                        subtotal: this.cart.subtotal,
                        discountType: this.cart.discountType,
                        discountValue: this.cart.discountValue,
                        discount: this.cart.discount,
                        tax: this.cart.tax,
                        total: this.cart.total,
                    };
                },

                persistCart() {
                    try {
                        localStorage.setItem('pos_cart', JSON.stringify(this.cartSnapshot()));
                        localStorage.setItem('pos_store_id', String(this.storeId));
                    } catch (error) {
                        if (!this.cartStorageWarningShown) {
                            this.cartStorageWarningShown = true;
                            workshopNotify('Cart could not be saved in this browser. Check available storage.');
                        }
                    }
                },

                persistHeldCarts() {
                    try {
                        localStorage.setItem('pos_held_carts', JSON.stringify(this.heldCarts));
                    } catch (error) {
                        if (!this.cartStorageWarningShown) {
                            this.cartStorageWarningShown = true;
                            workshopNotify('Held bills could not be saved in this browser. Check available storage.');
                        }
                    }
                },

                handleShortcut(event) {
                    const modal = document.querySelector('dialog[open]');
                    if (!event.altKey && !event.ctrlKey && !event.metaKey && !modal && /^[1-9]$/.test(event.key) && !(event.target instanceof Element && event.target.closest('input, textarea, select, [contenteditable]'))) {
                        const product = this.filteredProducts[Number(event.key) - 1];
                        if (product) { event.preventDefault(); this.productClicked(product); }
                        return;
                    }
                    if (!event.altKey || event.ctrlKey || event.metaKey) return;
                    const key = event.key.toLowerCase();
                    if (modal) {
                        if (modal.id === 'payment-modal' && /^Digit[1-4]$/.test(event.code)) {
                            event.preventDefault();
                            this.setPaymentAmount([500, 1000, 2000, 5000][Number(event.code.slice(-1)) - 1]);
                        } else if (key === 'enter' && modal.id === 'payment-modal') {
                            event.preventDefault();
                            this.submitSale();
                        } else if (key === 'p' && modal.id === 'payment-modal') {
                            event.preventDefault();
                            document.getElementById('checkout-paid-amount')?.focus();
                        } else if (key === 'o' && modal.id === 'payment-modal') {
                            event.preventDefault();
                            document.getElementById('pos-account-select')?.focus();
                        } else if (key === 'enter' && modal.id === 'batch-selection-modal') {
                            event.preventDefault();
                            this.confirmBatchSelection();
                        } else if (key === 'enter' && modal.id === 'pos-customer-modal') {
                            event.preventDefault();
                            this.useNewCustomer();
                        } else if (key === 'enter' && modal.id === 'pos-note-modal') {
                            event.preventDefault();
                            this.saveNote();
                        } else if (key === 'enter' && modal.id === 'pos-item-modal') {
                            event.preventDefault();
                            this.saveItemEditor();
                        } else if (key === 'enter' && modal.id === 'pos-carts-modal') {
                            event.preventDefault();
                            this.newCart();
                        } else if (key === 'p' && modal.id === 'print-bill-modal') {
                            event.preventDefault();
                            this.openDocument();
                        } else if (key === 'd' && modal.id === 'print-bill-modal') {
                            event.preventDefault();
                            this.downloadDocument();
                        } else if (key === 'enter' && modal.id === 'pos-pin-modal') {
                            event.preventDefault();
                            this.savePinOrder();
                        }
                        return;
                    }
                    const actions = {
                        s: () => this.focusSearch(),
                        g: () => { this.activeCategory = null; document.querySelector('[data-pos-category]')?.focus(); },
                        d: () => document.getElementById('pos-discount-input')?.focus(),
                        f: () => { this.cart.discountType = 'fixed'; this.recalculateTotals(); },
                        e: () => { this.cart.discountType = 'percent'; this.recalculateTotals(); },
                        t: () => document.getElementById('pos-store-select')?.focus(),
                        y: () => document.getElementById('pos-customer-select')?.focus(),
                        a: () => this.openNewCustomer(),
                        n: () => { if (!this.isHelmetShop) this.openNote('internal'); },
                        u: () => { if (!this.isHelmetShop) this.openNote('customer'); },
                        q: () => { if (!this.isHelmetShop) this.submitSale('Quotation'); },
                        h: () => this.holdCart(),
                        m: () => this.openCarts(),
                        p: () => this.openPinManager(),
                        c: () => this.openPayment('Cash'),
                        k: () => { if (!this.isHelmetShop) this.openPayment('Card'); },
                        b: () => { if (!this.isHelmetShop) this.openPayment('Bank Transfer'); },
                        x: () => this.clearCart(),
                        i: () => { @if(auth()->user()->canAccess('intake.add')) window.location.href = @js(route('intakes.create')); @endif },
                        j: () => { @if(auth()->user()->canAccess('job_card.create')) window.location.href = @js(route('jobs.create')); @endif },
                        l: () => { @if(auth()->user()->canAccess('sales.view_invoices')) window.location.href = @js(route('sales.invoices')); @endif },
                    };
                    if (actions[key]) {
                        event.preventDefault();
                        actions[key]();
                    }
                },

                focusSearch() {
                    const input = document.getElementById('pos-search-input');
                    input?.focus();
                    input?.select();
                },

                get pinnedCount() {
                    return this.productsData.filter(product => product.posPinned).length;
                },

                sortProductsByPins() {
                    this.productsData.sort((a, b) => Number(Boolean(b.posPinned)) - Number(Boolean(a.posPinned))
                        || (a.posPinned ? (Number(a.posPinOrder) || 0) - (Number(b.posPinOrder) || 0) : 0)
                        || a.name.localeCompare(b.name));
                },

                async refreshPins() {
                    try {
                        const response = await fetch(@js(route('products.pos-pins.index')), { headers: { Accept: 'application/json' } });
                        if (!response.ok) return false;
                        const { product_ids: ids } = await response.json();
                        const positions = new Map(ids.map((id, index) => [Number(id), index + 1]));
                        this.productsData.forEach(product => {
                            product.posPinned = positions.has(Number(product.id));
                            product.posPinOrder = positions.get(Number(product.id)) ?? null;
                        });
                        this.sortProductsByPins();
                        return true;
                    } catch (error) {
                        return false;
                    }
                },

                async openPinManager() {
                    const modal = document.getElementById('pos-pin-modal');
                    if (!modal || document.querySelector('dialog[open]')) return;
                    if (!await this.refreshPins()) {
                        workshopNotify('Could not load pinned products. Please try again.');
                        return;
                    }
                    this.pinOrderDraft = this.productsData.filter(product => product.posPinned).slice();
                    modal.showModal();
                },

                movePin(index, direction) {
                    const next = index + direction;
                    if (next < 0 || next >= this.pinOrderDraft.length) return;
                    const draft = [...this.pinOrderDraft];
                    [draft[index], draft[next]] = [draft[next], draft[index]];
                    this.pinOrderDraft = draft;
                },

                async savePinOrder() {
                    if (this.isSavingPins || !this.pinOrderDraft.length) return;
                    this.isSavingPins = true;
                    try {
                        const response = await fetch(@js(route('products.pos-pins.order')), {
                            method: 'PUT',
                            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': @js(csrf_token()) },
                            body: JSON.stringify({ product_ids: this.pinOrderDraft.map(product => product.id) }),
                        });
                        if (!response.ok) throw new Error('Could not save pinned order. Reopen the list and try again.');
                        this.pinOrderDraft.forEach((product, index) => { product.posPinOrder = index + 1; });
                        this.sortProductsByPins();
                        this.closeDialog('pos-pin-modal');
                        workshopNotify('Pinned product order saved.');
                    } catch (error) {
                        workshopNotify(error.message);
                    } finally {
                        this.isSavingPins = false;
                    }
                },

                cycleCategory(direction) {
                    const current = this.categoryIds.findIndex(id => id === this.activeCategory);
                    const next = (current + direction + this.categoryIds.length) % this.categoryIds.length;
                    this.activeCategory = this.categoryIds[next];
                    document.querySelectorAll('[data-pos-category]')[next]?.focus();
                },

                scanProduct(value) {
                    const code = String(value || '').trim().toLowerCase();
                    if (!code) return;
                    const product = this.productsData.find(item => item.barcode?.toLowerCase() === code || item.sku?.toLowerCase() === code);
                    if (!product) {
                        workshopNotify('No product matches this barcode or SKU.');
                        return;
                    }
                    this.productClicked(product);
                    const input = document.getElementById('pos-search-input');
                    if (input) { input.value = ''; input.dispatchEvent(new Event('input', { bubbles: true })); }
                    this.query = '';
                },

                changeStore() {
                    if (this.hasCartLines()) {
                        this.storeId = this.selectedStoreId;
                        workshopNotify('Hold or clear this cart before changing stores.');
                        return;
                    }
                    this.selectedStoreId = this.storeId;
                    localStorage.setItem('pos_store_id', this.storeId);
                    this.recalculateStocks();
                },

                openNewCustomer() {
                    this.newCustomer = { name: this.cart.customerName || '', phone: this.cart.customerPhone || '' };
                    document.getElementById('pos-customer-modal').showModal();
                    this.$nextTick(() => document.getElementById('pos-new-customer-name')?.focus());
                },

                useNewCustomer() {
                    if (!this.newCustomer.name.trim() || !this.newCustomer.phone.trim()) {
                        workshopNotify('Enter the customer name and phone number.');
                        return;
                    }
                    this.cart.customerName = this.newCustomer.name.trim();
                    this.cart.customerPhone = this.newCustomer.phone.trim();
                    this.cart.customerId = 'new';
                    this.closeDialog('pos-customer-modal');
                },

                openNote(type) {
                    this.editingNote = type;
                    this.noteDraft = type === 'customer' ? this.cart.customerNote : this.cart.internalNote;
                    document.getElementById('pos-note-modal').showModal();
                    this.$nextTick(() => document.getElementById('pos-note-input')?.focus());
                },

                saveNote() {
                    if (this.editingNote === 'customer') this.cart.customerNote = this.noteDraft.trim();
                    else this.cart.internalNote = this.noteDraft.trim();
                    this.closeDialog('pos-note-modal');
                },

                openItemEditor(index) {
                    const item = this.cart.items[index];
                    if (!item || this.isSubmitting) return;
                    this.editingItemIndex = index;
                    this.itemDraft = {
                        name: item.display_name || item.product.name,
                        sku: item.product.sku || '',
                        qty: item.qty,
                        unit_price: item.unit_price,
                    };
                    document.getElementById('pos-item-modal').showModal();
                    this.$nextTick(() => document.getElementById('pos-item-name')?.focus());
                },

                saveItemEditor() {
                    const item = this.cart.items[this.editingItemIndex];
                    if (!item) return;
                    const name = String(this.itemDraft.name ?? '').trim();
                    const qtyInput = String(this.itemDraft.qty ?? '').trim();
                    const priceInput = String(this.itemDraft.unit_price ?? '').trim();
                    const qty = Number(this.itemDraft.qty);
                    const price = Number(this.itemDraft.unit_price);
                    const hasTwoDecimals = value => Math.abs(value * 100 - Math.round(value * 100)) < 0.000001;
                    if (!name || name.length > 255) { workshopNotify('Enter an item name of up to 255 characters.'); return; }
                    if (!qtyInput || !Number.isFinite(qty) || qty < 0.01 || !hasTwoDecimals(qty)) { workshopNotify('Enter a quantity greater than zero, with up to two decimals.'); return; }
                    if (!priceInput || !Number.isFinite(price) || price < 0 || !hasTwoDecimals(price)) { workshopNotify('Enter a valid unit price with up to two decimals.'); return; }
                    item.display_name = name === item.product.name ? null : name;
                    item.qty = qty;
                    item.unit_price = price;
                    this.recalculateTotals();
                    this.persistCart();
                    this.closeDialog('pos-item-modal');
                },

                closeDialog(id) { document.getElementById(id)?.close(); },

                resetCart() {
                    this.cart = { customerId: '', customerName: '', customerPhone: '', internalNote: '', customerNote: '', items: [], charges: [], sourceQuotationId: null, sourceQuotationNumber: '', bikeNumber: '', subtotal: 0, discountType: 'fixed', discountValue: 0, discount: 0, tax: 0, total: 0 };
                    this.submissionToken = crypto.randomUUID();
                    this.persistCart();
                },

                holdCart() {
                    if (this.isSubmitting) return;
                    if (!this.hasCartLines()) { workshopNotify('Cart is empty.'); return; }
                    const customer = this.cart.customerId === 'new' ? this.cart.customerName : document.querySelector('#pos-customer-select option:checked')?.textContent;
                    this.heldCarts = [...this.heldCarts, { id: crypto.randomUUID(), name: customer || 'Walk-in Customer', heldAt: new Date().toISOString(), storeId: this.storeId, cart: this.cartSnapshot() }];
                    this.persistHeldCarts();
                    this.resetCart();
                    workshopNotify('Bill held. Select View held to see or resume it.');
                },

                openCarts() {
                    this.viewingHeldId = this.heldCarts.length === 1 ? this.heldCarts[0].id : null;
                    document.getElementById('pos-carts-modal').showModal();
                },

                formatHeldTime(value) {
                    const date = new Date(value);
                    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString();
                },

                newCart() {
                    if (this.hasCartLines()) this.holdCart();
                    else this.resetCart();
                    this.closeDialog('pos-carts-modal');
                    this.focusSearch();
                },

                resumeCart(id) {
                    if (this.isSubmitting) return;
                    const held = this.heldCarts.find(cart => cart.id === id);
                    if (!held) return;
                    if (this.hasCartLines()) this.holdCart();
                    this.heldCarts = this.heldCarts.filter(cart => cart.id !== id);
                    this.persistHeldCarts();
                    this.viewingHeldId = null;
                    this.storeId = held.storeId;
                    this.selectedStoreId = held.storeId;
                    localStorage.setItem('pos_store_id', this.storeId);
                    this.recalculateStocks();
                    this.cart = { ...this.cart, ...held.cart };
                    this.cart.items = this.restoreCartItems(this.cart.items);
                    if (!Array.isArray(this.cart.charges)) this.cart.charges = [];
                    this.recalculateTotals();
                    this.persistCart();
                    this.closeDialog('pos-carts-modal');
                },

                async deleteHeldCart(id) {
                    if (await workshopConfirm('Delete this held bill?')) {
                        this.heldCarts = this.heldCarts.filter(cart => cart.id !== id);
                        this.persistHeldCarts();
                        if (this.viewingHeldId === id) this.viewingHeldId = null;
                    }
                },

                recalculateStocks() {
                    // Update currentStock and currentBatches for all products based on selected store
                    this.productsData.forEach(p => {
                        const s = p.stocks.find(st => st.store_id == this.storeId);
                        p.currentStock = s ? s.quantity : 0;
                        p.currentBatches = p.batches.filter(b => b.store_id == this.storeId);
                    });
                },

                resetProductWindow() {
                    this.visibleProductCount = 60;
                    const scroll = document.getElementById('pos-products-scroll');
                    if (scroll) scroll.scrollTop = 0;
                },

                showMoreProducts() {
                    this.visibleProductCount = Math.min(this.visibleProductCount + 60, this.filteredProducts.length);
                },

                loadMoreOnScroll(event) {
                    const scroll = event.target;
                    if (scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 600 && this.visibleProductCount < this.filteredProducts.length) {
                        this.showMoreProducts();
                    }
                },

                get filteredProducts() {
                    let p = this.productsData;
                    if (this.activeCategory) {
                        p = p.filter(x => x.category_id == this.activeCategory);
                    }
                    if (this.query.trim()) {
                        const q = this.query.toLowerCase();
                        p = p.filter(x => x.name.toLowerCase().includes(q) || (x.sku && x.sku.toLowerCase().includes(q)) || (x.barcode && x.barcode.toLowerCase().includes(q)));
                    }
                    return p;
                },

                get visibleProducts() {
                    return this.filteredProducts.slice(0, this.visibleProductCount);
                },

                productClicked(product) {
                    if (product.currentBatches.length > 1) {
                        // Show batch modal
                        this.activeProductForBatch = product;
                        this.selectedBatchId = product.currentBatches[0].id;
                        document.getElementById('batch-selection-modal').showModal();
                    } else if (product.currentBatches.length === 1) {
                        // Only one batch, add directly
                        this.addToCart(product, product.currentBatches[0]);
                    } else {
                        // No specific batch details (legacy stock), add generic
                        this.addToCart(product, null);
                    }
                },

                confirmBatchSelection() {
                    if (!this.selectedBatchId || !this.activeProductForBatch) return;
                    const batch = this.activeProductForBatch.currentBatches.find(b => b.id == this.selectedBatchId);
                    if (batch) {
                        this.addToCart(this.activeProductForBatch, batch);
                        document.getElementById('batch-selection-modal').close();
                        this.activeProductForBatch = null;
                        this.selectedBatchId = null;
                    }
                },

                addToCart(product, batch = null) {
                    const maxStock = batch ? parseFloat(batch.qty) : parseFloat(product.currentStock);
                    
                    // Check if already in cart with same batch
                    const existingIndex = this.cart.items.findIndex(i => i.product.id === product.id && i.stock_batch_id === (batch ? batch.id : null));
                    
                    if (existingIndex > -1) {
                        const item = this.cart.items[existingIndex];
                        item.qty += 1;
                    } else {
                        this.cart.items.unshift({
                            product: product,
                            stock_batch_id: batch ? batch.id : null,
                            batch_no: batch ? batch.batch_no : null,
                            cost_price: batch ? batch.cost : product.cost_price || 0,
                            unit_price: batch ? batch.selling : product.price,
                            qty: 1,
                            max_qty: maxStock,
                            discount: 0
                        });
                    }
                    if (maxStock < 1 && !this.allowNegativeStock) workshopNotify('This product can be quoted, but needs stock before sale.');
                    this.recalculateTotals();
                },

                updateQty(index, change) {
                    const item = this.cart.items[index];
                    item.qty = Number(item.qty) + change;
                    if (item.qty <= 0) item.qty = 1;
                    this.recalculateTotals();
                },

                validateItemQty(index) {
                    const item = this.cart.items[index];
                    let qty = parseFloat(item.qty);
                    if (isNaN(qty) || qty <= 0) {
                        qty = 1;
                    }
                    item.qty = qty;
                    this.recalculateTotals();
                },

                removeItem(index) {
                    this.cart.items.splice(index, 1);
                    this.recalculateTotals();
                },

                async clearCart() {
                    if (this.isSubmitting || (!this.hasCartLines() && !this.cart.customerId && !this.cart.internalNote && !this.cart.customerNote && !Number(this.cart.discountValue))) return;
                    if (await workshopConfirm('Are you sure you want to clear the cart?')) {
                        this.resetCart();
                    }
                },

                checkSaleStock() {
                    if (this.allowNegativeStock) return true;
                    for (const item of this.cart.items) {
                        const available = item.stock_batch_id
                            ? item.product.currentBatches?.find(batch => batch.id === item.stock_batch_id)?.qty ?? 0
                            : item.product.currentStock ?? 0;
                        if (Number(item.qty) > Number(available)) {
                            workshopNotify(`${item.product.name}: only ${available} in stock. Save a quotation or adjust the quantity.`);
                            return false;
                        }
                    }
                    return true;
                },

                defaultPaymentAccount(method) {
                    return ['Cheque', 'Card', 'Bank Transfer', 'QR'].includes(method)
                        ? {{ Illuminate\Support\Js::from($bankAccountId) }}
                        : {{ Illuminate\Support\Js::from($cashAccountId) }};
                },

                openPayment(method = 'Cash') {
                    if (this.isSubmitting) return;
                    if (!this.hasCartLines()) {
                        workshopNotify('Cart is empty.');
                        return;
                    }
                    if (!this.checkSaleStock()) return;
                    this.extraPayments = [];
                    this.activePaymentIndex = null;
                    this.chequeDate = ''; this.chequeNumber = ''; this.chequeBank = '';
                    this.paymentMethod = method;
                    this.paidAmount = this.cart.total;
                    
                    this.accountId = this.defaultPaymentAccount(method);

                    document.getElementById('payment-modal').showModal();

                    // Autofocus and highlight the Paid Amount input
                    setTimeout(() => {
                        const inputEl = document.getElementById('checkout-paid-amount');
                        if (inputEl) {
                            inputEl.focus();
                            inputEl.select();
                        }
                    }, 50);
                },

                setPaymentAmount(amount) {
                    const row = this.activePaymentIndex === null ? null : this.extraPayments[this.activePaymentIndex];
                    if (row) row.amount = amount;
                    else this.paidAmount = amount;
                },

                pressKey(val) {
                    const row = this.activePaymentIndex === null ? null : this.extraPayments[this.activePaymentIndex];
                    const amount = Number(row ? row.amount : this.paidAmount) || 0;
                    const str = amount.toString();
                    this.setPaymentAmount(val === 'C' ? 0 : val === 'B'
                        ? parseFloat(str.slice(0, -1)) || 0
                        : parseFloat((amount === 0 ? '' : str) + val) || 0);
                },

                checkoutPayments() {
                    const rows = this.paymentMethod === 'Credit/Due' ? [] : [{method:this.paymentMethod,amount:Number(this.paidAmount),account_id:this.accountId,cheque_date:this.chequeDate,cheque_number:this.chequeNumber,bank_name:this.chequeBank}];
                    rows.push(...this.extraPayments.map(r=>({...r,amount:Number(r.amount)})));
                    const excess = rows.reduce((n,r)=>n+r.amount,0)-this.cart.total;
                    if (excess>0 && rows[0]?.method==='Cash') rows[0].amount=Math.max(0,rows[0].amount-excess);
                    return rows.filter(r=>r.amount!==0);
                },

                async submitSale(type = 'Parts Sale') {
                    if (this.isSubmitting || !this.hasCartLines()) return;
                    if (type === 'Quotation' && this.cart.sourceQuotationId) {
                        workshopNotify('This quotation is already loaded. Complete it as a sale.');
                        return;
                    }
                    this.recalculateTotals();
                    if (this.cart.items.some(item => !Number.isFinite(Number(item.qty)) || Number(item.qty) <= 0 || !Number.isFinite(Number(item.unit_price)) || Number(item.unit_price) < 0)) {
                        workshopNotify('Check the quantity and price of each item.');
                        return;
                    }
                    if (type !== 'Quotation' && !this.checkSaleStock()) return;
                    const payments = type === 'Quotation' ? [] : this.checkoutPayments();
                    if (payments.some(r=>!Number.isFinite(r.amount)||r.amount<=0||!r.account_id)) {
                        workshopNotify('Enter valid amounts and choose an account for each payment.'); return;
                    }
                    if (payments.some(r=>r.method==='Cheque'&&(!r.cheque_date||!r.cheque_number.trim()||!r.bank_name.trim()))) {
                        workshopNotify('Enter the date, number and bank for every cheque.'); return;
                    }
                    const covered=payments.reduce((n,r)=>n+r.amount,0);
                    if (covered>this.cart.total+0.001) { workshopNotify('Payment amounts exceed the total.'); return; }
                    if (type !== 'Quotation' && (covered<this.cart.total || payments.some(r=>r.method==='Cheque')) && !this.cart.customerId) {
                        workshopNotify('Select a customer for cheque or due payments.'); return;
                    }

                    this.isSubmitting = true;
                    
                    const payload = {
                        _submission_token: this.submissionToken,
                        customer_mode: this.cart.customerId === 'new' ? 'new' : (this.cart.customerId ? 'existing' : 'walkin'),
                        customer_id: this.cart.customerId && this.cart.customerId !== 'new' ? this.cart.customerId : null,
                        customer_name: this.cart.customerId === 'new' ? this.cart.customerName : null,
                        customer_phone: this.cart.customerId === 'new' ? this.cart.customerPhone : null,
                        internal_note: this.cart.internalNote,
                        customer_note: this.cart.customerNote,
                        store_id: this.storeId,
                        discount: this.cart.discount,
                        tax: this.cart.tax,
                        payments: payments,
                        paid_amount: type === 'Quotation' || this.paymentMethod === 'Credit/Due' ? 0 : Math.min(Number(this.paidAmount), this.cart.total),
                        payment_method: type === 'Quotation' ? null : this.paymentMethod,
                        account_id: type === 'Quotation' || this.paymentMethod === 'Credit/Due' ? null : this.accountId,
                        invoice_type: type,
                        source_quotation_id: type === 'Parts Sale' ? this.cart.sourceQuotationId : null,
                        charges: this.cart.charges.map(charge => ({ type: charge.type, description: charge.description, amount: Number(charge.amount) })),
                        items: this.cart.items.map(i => ({
                            product_id: i.product.id,
                            stock_batch_id: i.stock_batch_id,
                            description: i.display_name || null,
                            quantity: i.qty,
                            unit_price: i.unit_price,
                            discount: i.discount
                        }))
                    };

                    try {
                        const res = await fetch('{{ route("sales.parts.store") }}', {
                            method: 'POST',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify(payload)
                        });

                        const data = await res.json();
                        if (res.ok && data.success) {
                            localStorage.removeItem('pos_cart');
                            this.resetCart();
                            this.lastInvoiceToken = data.public_token;
                            this.lastInvoiceId = data.invoice_id;
                            this.lastDocumentType = type;
                            this.closeDialog('payment-modal');
                            document.getElementById('print-bill-modal').showModal();
                        } else {
                            workshopNotify(Object.values(data.errors || {}).flat()[0] || data.message || 'Validation failed. Check the inputs.');
                        }
                    } catch (e) {
                        workshopNotify('An error occurred during submission.');
                        console.error(e);
                    } finally {
                        this.isSubmitting = false;
                    }
                },

                closeDocument() {
                    this.closeDialog('print-bill-modal');
                },

                openDocument(format = 'a4') {
                    if (!this.lastInvoiceToken) return;
                    const url = @js(route('invoices.public', ['token' => '__TOKEN__'])).replace('__TOKEN__', encodeURIComponent(this.lastInvoiceToken)) + '?print=1&format=' + format;
                    const opened = window.open(url, '_blank');
                    if (!opened) window.location.href = url;
                    else { opened.opener = null; this.closeDocument(); }
                },

                downloadDocument() {
                    if (!this.lastInvoiceId) return;
                    const url = @js(route('sales.invoices.pdf', ['invoice' => '__INVOICE__'])).replace('__INVOICE__', encodeURIComponent(this.lastInvoiceId));
                    const opened = window.open(url, '_blank');
                    if (!opened) window.location.href = url;
                    else { opened.opener = null; this.closeDocument(); }
                },

                recalculateTotals() {
                    let sub = 0;
                    this.cart.items.forEach(i => {
                        sub += (i.qty * i.unit_price) - (i.discount || 0);
                    });
                    this.cart.charges.forEach(charge => { sub += Number(charge.amount) || 0; });
                    this.cart.subtotal = sub;

                    if (this.cart.discountType === 'fixed') {
                        this.cart.discount = Math.min(Math.max(0, parseFloat(this.cart.discountValue) || 0), sub);
                    } else {
                        const pct = Math.min(Math.max(0, parseFloat(this.cart.discountValue) || 0), 100);
                        this.cart.discount = sub * (pct / 100);
                    }

                    const t = parseFloat(this.cart.tax) || 0;
                    
                    this.cart.total = Math.max(0, this.cart.subtotal - this.cart.discount + t);
                },

                formatNumber(num) {
                    return Number(num || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                }
            }));
        });
    </script>
    @endpush
</x-layouts::pos>
