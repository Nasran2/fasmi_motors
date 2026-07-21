<x-layouts::pos title="POS - Parts Sales">
    @section('pos-search')
    <div class="relative w-full max-w-2xl" x-data="{ query: '' }">
        <svg class="absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        <input type="text" x-model="query" @input.debounce.100ms="$dispatch('search-products', query)" placeholder="Enter product name / SKU / Scan barcode" class="h-12 w-full rounded-2xl border-2 border-slate-700 bg-[#0b2447] pl-12 pr-12 text-[15px] font-semibold text-white placeholder-slate-400 outline-none transition focus:border-red-500 focus:bg-[#06162f] focus:ring-4 focus:ring-red-500/20" autofocus>
        <button class="absolute right-3 top-1/2 -translate-y-1/2 grid size-8 place-items-center rounded-lg text-slate-400 hover:bg-slate-700 hover:text-white transition">
            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><path d="M8 12h8"/><path d="M12 8v8"/></svg>
        </button>
    </div>
    @endsection

    @section('pos-actions')
    <div class="flex items-center gap-2 mr-4">
        <a href="{{ route('intakes.create') }}" class="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl bg-red-600 px-4 text-sm font-bold text-white shadow-lg shadow-red-500/20 hover:bg-red-500 transition">
            New Bike Intake
        </a>
        <a href="{{ route('jobs.create') }}" class="inline-flex h-10 items-center gap-2 whitespace-nowrap rounded-xl border-2 border-slate-700 bg-transparent px-4 text-sm font-bold text-white hover:border-slate-500 hover:bg-slate-800 transition">
            Create Job Card
        </a>
    </div>
    @endsection

    <div class="flex w-full h-full" x-data="posApp()">
        <!-- Left Side: Products Grid -->
        <div class="pos-left flex flex-col relative">
            <!-- Store Selector & Categories -->
            <div class="flex flex-col gap-4 p-4 pb-0 bg-white border-b border-slate-200 shrink-0">
                <div class="flex items-center gap-4">
                    <label class="flex items-center gap-2 bg-slate-100 rounded-xl px-3 py-2 border border-slate-200">
                        <svg class="size-5 text-slate-500" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                        <select class="bg-transparent border-none outline-none font-bold text-slate-700 text-sm cursor-pointer pr-4" x-model="storeId" @change="recalculateStocks">
                            @foreach($stores as $store)
                                <option value="{{ $store->id }}">{{ $store->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    
                    <div class="text-slate-500 font-bold text-sm tracking-wider flex items-center gap-2 ml-auto">
                        <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span x-text="currentTime"></span>
                    </div>
                </div>

                <!-- Horizontal scrollable categories -->
                <div class="flex items-center gap-2 overflow-x-auto pb-4 hide-scrollbar snap-x">
                    <button class="snap-start shrink-0 rounded-full px-5 py-2.5 text-sm font-bold transition-all border-2"
                        :class="activeCategory === null ? 'bg-red-600 border-red-600 text-white shadow-md shadow-red-500/20' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                        @click="activeCategory = null">
                        All <span class="ml-1 opacity-70">({{ count($products) }})</span>
                    </button>
                    @foreach($categories as $cat)
                    <button class="snap-start shrink-0 rounded-full px-5 py-2.5 text-sm font-bold transition-all border-2 flex items-center gap-2"
                        :class="activeCategory === {{ $cat->id }} ? 'bg-red-600 border-red-600 text-white shadow-md shadow-red-500/20' : 'bg-white border-slate-200 text-slate-600 hover:border-slate-300 hover:bg-slate-50'"
                        @click="activeCategory = {{ $cat->id }}">
                        {{ $cat->name }}
                    </button>
                    @endforeach
                </div>
            </div>

            <!-- Product Grid -->
            <div class="flex-1 overflow-y-auto p-4 bg-[#f7f9fc]">
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5 gap-4 content-start">
                    <template x-for="product in filteredProducts" :key="product.id">
                        <button class="relative flex flex-col text-left rounded-2xl bg-white p-4 transition-all shadow-sm border-2 overflow-hidden group outline-none focus:ring-4 focus:ring-red-500/20"
                            :class="{
                                'border-transparent hover:border-red-300 hover:shadow-md': product.currentStock > 0,
                                'border-red-100 bg-red-50/50 opacity-70 cursor-not-allowed': product.currentStock <= 0,
                            }"
                            @click="productClicked(product)">
                            
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
                                <h3 class="text-sm font-black text-slate-900 leading-tight line-clamp-2" x-text="product.name"></h3>
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
            <!-- Customer Section -->
            <div class="p-4 border-b border-slate-100 flex gap-2">
                <div class="relative flex-1">
                    <select class="w-full h-11 bg-slate-50 border border-slate-200 rounded-xl px-4 text-sm font-bold text-slate-800 outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50 transition appearance-none" x-model="cart.customerId">
                        <option value="">Walk-in Customer</option>
                        @foreach($customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->name }} — {{ $customer->phone }}</option>
                        @endforeach
                    </select>
                    <div class="pointer-events-none absolute right-4 top-1/2 -translate-y-1/2 text-slate-400">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
                    </div>
                </div>
                <button class="size-11 shrink-0 grid place-items-center bg-[#0b2447] text-white rounded-xl hover:bg-[#123665] transition shadow-md">
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </button>
            </div>

            <!-- Cart Items -->
            <div class="flex-1 overflow-y-auto p-4 bg-slate-50/50">
                <!-- Headers -->
                <div class="grid grid-cols-[1fr_auto_auto_auto] gap-2 mb-3 px-2 pr-10">
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400">Product</span>
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 text-center w-[72px]">Qty</span>
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 text-right w-[80px]">Price</span>
                    <span class="text-[10px] font-black uppercase tracking-wider text-slate-400 text-right w-[95px]">Subtotal</span>
                </div>

                <div class="space-y-3">
                    <template x-if="cart.items.length === 0">
                        <div class="flex flex-col items-center justify-center py-12 text-slate-400">
                            <svg class="size-12 mb-3 opacity-20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                            <p class="text-sm font-bold">Cart is empty</p>
                            <p class="text-xs mt-1">Add items from the grid to begin</p>
                        </div>
                    </template>
                    <template x-for="(item, index) in cart.items" :key="index">
                        <div class="grid grid-cols-[1fr_auto_auto_auto] items-center gap-2 p-3 bg-white rounded-xl border border-slate-100 shadow-sm relative pr-10 min-h-[64px]">
                            <!-- Product Detail -->
                            <div class="min-w-0">
                                <p class="text-[11px] font-black text-slate-900 leading-tight truncate" x-text="item.product.name" :title="item.product.name"></p>
                                <p class="text-[9px] font-semibold text-slate-400 mt-0.5 uppercase tracking-wider" x-text="item.product.sku"></p>
                                <template x-if="item.batch_no">
                                    <span class="inline-block mt-1 rounded bg-[#06162f] px-1 py-0.5 text-[8px] font-bold tracking-wider text-white" x-text="'B: ' + item.batch_no"></span>
                                </template>
                            </div>
                            
                            <!-- Qty Control -->
                            <div class="flex items-center gap-0 bg-slate-100 rounded-lg p-0.5 border border-slate-200 w-[72px]">
                                <button @click="updateQty(index, -1)" class="size-6 grid place-items-center rounded hover:bg-white hover:shadow-sm text-slate-600 transition"><svg class="size-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"/></svg></button>
                                <input type="number" x-model.number="item.qty" class="w-6 bg-transparent text-center text-xs font-black text-slate-900 outline-none appearance-none" @change="recalculateTotals">
                                <button @click="updateQty(index, 1)" class="size-6 grid place-items-center rounded hover:bg-white hover:shadow-sm text-slate-600 transition"><svg class="size-2.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M12 5v14M5 12h14"/></svg></button>
                            </div>
                            
                            <!-- Price -->
                            <div class="text-right w-[80px]">
                                <input type="number" x-model.number="item.unit_price" class="w-full text-right text-xs font-black text-slate-700 focus:text-indigo-600" @change="recalculateTotals" style="min-height:30px; padding: 4px 6px;">
                            </div>

                            <!-- Subtotal -->
                            <div class="text-right w-[95px]">
                                <p class="text-xs font-black text-slate-950" x-text="'Rs. ' + formatNumber(item.qty * item.unit_price)"></p>
                            </div>

                            <!-- Delete -->
                            <button @click="removeItem(index)" class="absolute right-2 top-1/2 -translate-y-1/2 text-red-400 hover:text-red-600 hover:bg-red-50 rounded-md p-1 transition">
                                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                            </button>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Cart Actions -->
            <div class="grid grid-cols-4 gap-2 px-4 py-3 bg-white border-y border-slate-100">
                <button class="flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Note</span>
                </button>
                <button class="flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Cust Note</span>
                </button>
                <button class="flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-slate-500 hover:bg-slate-50 hover:text-slate-800 transition">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Coupon</span>
                </button>
                <button @click="clearCart" class="flex flex-col items-center justify-center gap-1.5 py-2 rounded-xl text-red-500 hover:bg-red-50 hover:text-red-700 transition">
                    <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    <span class="text-[9px] font-bold uppercase tracking-wider">Clear</span>
                </button>
            </div>

            <!-- Totals -->
            <div class="px-4 py-4 bg-white">
                <div class="space-y-2 mb-4">
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500 font-bold">Subtotal (<span x-text="cart.items.length"></span> items)</span>
                        <span class="font-bold text-slate-900" x-text="formatNumber(cart.subtotal)"></span>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <div class="flex items-center gap-2">
                            <span class="text-slate-500 font-bold">Discount</span>
                            <div class="flex bg-slate-100 rounded-lg p-0.5">
                                <button class="px-2 py-0.5 text-[10px] font-bold rounded-md" :class="cart.discountType === 'fixed' ? 'bg-white shadow-sm text-slate-800' : 'text-slate-400'" @click="cart.discountType = 'fixed'; recalculateTotals()">Fixed</button>
                                <button class="px-2 py-0.5 text-[10px] font-bold rounded-md" :class="cart.discountType === 'percent' ? 'bg-white shadow-sm text-slate-800' : 'text-slate-400'" @click="cart.discountType = 'percent'; recalculateTotals()">%</button>
                            </div>
                        </div>
                        <input type="number" x-model.number="cart.discountValue" @input="recalculateTotals" class="w-24 text-right bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-sm font-bold text-slate-900 outline-none focus:border-red-400">
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-slate-500 font-bold">Tax</span>
                        <input type="number" x-model.number="cart.tax" @input="recalculateTotals" class="w-24 text-right bg-slate-50 border border-slate-200 rounded-lg px-2 py-1 text-sm font-bold text-slate-900 outline-none focus:border-red-400">
                    </div>
                </div>
                
                <div class="flex justify-between items-end border-t border-slate-200 pt-4 mb-2">
                    <span class="text-xs font-black uppercase tracking-wider text-slate-400">Total</span>
                    <span class="text-xl font-black text-slate-900 line-through opacity-40" x-show="cart.discount > 0" x-text="formatNumber(cart.subtotal + cart.tax)"></span>
                </div>
                <div class="flex justify-between items-end">
                    <span class="text-lg font-black uppercase tracking-wider text-red-600">Payable</span>
                    <span class="text-3xl font-black text-red-600 tracking-tight" x-text="'Rs. ' + formatNumber(cart.total)"></span>
                </div>
            </div>

            <!-- Payment Buttons -->
            <div class="px-4 pb-4 pt-0 bg-white space-y-2 shrink-0">
                <div class="grid grid-cols-4 gap-2">
                    <button @click="submitSale('Quotation')" class="btn-pos bg-slate-100 text-slate-700 hover:bg-slate-200"><x-dashboard-icon name="document-text" class="size-4 mb-1"/> Quotation</button>
                    <button class="btn-pos bg-orange-100 text-orange-700 hover:bg-orange-200"><x-dashboard-icon name="pause" class="size-4 mb-1"/> Hold (0)</button>
                    <button class="btn-pos bg-red-50 text-red-600 hover:bg-red-100"><x-dashboard-icon name="arrow-uturn-left" class="size-4 mb-1"/> Return</button>
                    <button class="btn-pos bg-purple-100 text-purple-700 hover:bg-purple-200"><x-dashboard-icon name="square-3-stack-3d" class="size-4 mb-1"/> Multi</button>
                </div>
                <div class="grid grid-cols-4 gap-2">
                    <button @click="paymentMethod = 'Cash'; openPayment();" class="btn-pos bg-emerald-600 text-white hover:bg-emerald-500 shadow-lg shadow-emerald-500/20"><x-dashboard-icon name="banknotes" class="size-5 mb-1"/> Cash</button>
                    <button @click="paymentMethod = 'Card'; openPayment();" class="btn-pos bg-[#0b2447] text-white hover:bg-[#123665] shadow-lg shadow-[#0b2447]/20"><x-dashboard-icon name="credit-card" class="size-5 mb-1"/> Card</button>
                    <button @click="paymentMethod = 'Bank Transfer'; openPayment();" class="btn-pos bg-blue-600 text-white hover:bg-blue-500 shadow-lg shadow-blue-500/20"><x-dashboard-icon name="building-library" class="size-5 mb-1"/> Bank</button>
                    <button @click="clearCart" class="btn-pos bg-red-600 text-white hover:bg-red-500 shadow-lg shadow-red-500/20"><x-dashboard-icon name="x-mark" class="size-5 mb-1"/> Cancel</button>
                </div>
            </div>
        </div>

        <!-- Modals -->
        
        <!-- Batch Selection Modal -->
        <dialog id="batch-selection-modal" class="m-auto rounded-3xl p-0 shadow-2xl backdrop:bg-slate-900/60 open:animate-in open:fade-in open:zoom-in-95 border-0">
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
                    <button type="button" class="btn-secondary px-8 py-3 rounded-xl font-bold" @click="document.getElementById('batch-selection-modal').close()">Cancel</button>
                    <button type="button" class="btn-primary px-8 py-3 rounded-xl font-bold bg-red-600 hover:bg-red-700" :disabled="!selectedBatchId" @click="confirmBatchSelection">Add Selected Batch</button>
                </div>
            </div>
        </dialog>

        <!-- Payment Modal -->
        <dialog id="payment-modal" class="m-auto rounded-3xl p-0 shadow-2xl backdrop:bg-slate-900/60 open:animate-in open:fade-in border-0 w-full max-w-md">
            <div class="bg-white flex flex-col relative overflow-hidden">
                <div class="absolute inset-x-0 top-0 h-2 bg-gradient-to-r from-red-600 to-red-400"></div>
                <div class="p-6 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-xl font-black text-slate-900 flex items-center gap-2">
                        <svg class="size-5 text-red-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        Checkout
                    </h2>
                    <button @click="document.getElementById('payment-modal').close()" class="size-8 grid place-items-center rounded-full bg-slate-100 text-slate-400 hover:text-slate-700 hover:bg-slate-200 transition">
                        <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                
                <div class="p-6 bg-slate-50/50 space-y-4">
                    <div class="flex justify-between items-end mb-2">
                        <span class="text-sm font-bold uppercase tracking-wider text-slate-400">Total Payable</span>
                        <span class="text-4xl font-black text-red-600 tracking-tight" x-text="'Rs. ' + formatNumber(cart.total)"></span>
                    </div>
                    
                    <div>
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1 block">Payment Method</label>
                        <select x-model="paymentMethod" class="w-full h-12 bg-white border border-slate-200 rounded-xl px-4 text-sm font-bold text-slate-800 outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50 transition">
                            <option>Cash</option>
                            <option>Card</option>
                            <option>Bank Transfer</option>
                            <option>Cheque</option>
                            <option>Credit/Due</option>
                        </select>
                    </div>

                    <div x-show="paymentMethod !== 'Credit/Due'">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1 block">Account</label>
                        <select x-model="accountId" class="w-full h-12 bg-white border border-slate-200 rounded-xl px-4 text-sm font-bold text-slate-800 outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50 transition">
                            <option value="">Select Account...</option>
                            @foreach($accounts as $account)
                                <option value="{{ $account->id }}" @selected($account->code === 'MAIN' || $account->type === 'main')>{{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    
                    <div x-show="paymentMethod !== 'Credit/Due'">
                        <label class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-1 block">Paid Amount</label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 font-bold">Rs.</span>
                            <input type="number" x-model.number="paidAmount" class="w-full h-14 bg-white border-2 border-slate-200 rounded-xl !pl-14 pr-4 text-xl font-black text-slate-800 outline-none focus:border-red-400 focus:ring-4 focus:ring-red-50 transition">
                        </div>
                    </div>

                    <div class="flex justify-between items-center pt-3 border-t border-slate-200" x-show="paymentMethod !== 'Credit/Due'">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Change Due</span>
                        <span class="text-lg font-black text-emerald-600" x-text="'Rs. ' + formatNumber(Math.max(0, paidAmount - cart.total))"></span>
                    </div>
                </div>

                <div class="p-6 bg-white border-t border-slate-100 flex gap-3">
                    <button class="flex-1 bg-red-600 hover:bg-red-700 text-white font-bold py-4 rounded-xl shadow-lg shadow-red-500/20 transition disabled:opacity-50" @click="submitSale('Parts Sale')" :disabled="isSubmitting">
                        <span x-show="!isSubmitting">Confirm Sale</span>
                        <span x-show="isSubmitting">Processing...</span>
                    </button>
                </div>
            </div>
        </dialog>

    </div>

    @push('scripts')
    <style>
        .btn-pos {
            @apply flex flex-col items-center justify-center rounded-xl py-3 text-[11px] font-bold uppercase tracking-wider transition-all duration-200 outline-none;
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
                storeId: {{ $stores->first()->id ?? 1 }},
                activeCategory: null,
                query: '',
                
                cart: {
                    customerId: '',
                    items: [],
                    subtotal: 0,
                    discountType: 'fixed',
                    discountValue: 0,
                    discount: 0,
                    tax: 0,
                    total: 0
                },

                activeProductForBatch: null,
                selectedBatchId: null,

                paymentMethod: 'Cash',
                accountId: '',
                paidAmount: 0,
                isSubmitting: false,

                currentTime: '',

                init() {
                    const saved = localStorage.getItem('pos_cart');
                    if (saved) {
                        try {
                            const parsed = JSON.parse(saved);
                            this.cart = { ...this.cart, ...parsed };
                        } catch (e) {}
                    }

                    this.recalculateStocks();
                    setInterval(() => {
                        this.currentTime = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', second:'2-digit' });
                    }, 1000);
                    
                    window.addEventListener('search-products', (e) => {
                        this.query = e.detail;
                    });

                    this.$watch('cart', (val) => {
                        localStorage.setItem('pos_cart', JSON.stringify(val));
                    }, { deep: true });
                },

                recalculateStocks() {
                    // Update currentStock and currentBatches for all products based on selected store
                    this.productsData.forEach(p => {
                        const s = p.stocks.find(st => st.store_id == this.storeId);
                        p.currentStock = s ? s.quantity : 0;
                        p.currentBatches = p.batches.filter(b => b.store_id == this.storeId);
                    });
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

                productClicked(product) {
                    if (product.currentStock <= 0) {
                        // Cannot add if out of stock
                        return;
                    }

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
                    // Check if already in cart with same batch
                    const existingIndex = this.cart.items.findIndex(i => i.product.id === product.id && i.stock_batch_id === (batch ? batch.id : null));
                    
                    if (existingIndex > -1) {
                        this.cart.items[existingIndex].qty += 1;
                    } else {
                        this.cart.items.unshift({
                            product: product,
                            stock_batch_id: batch ? batch.id : null,
                            batch_no: batch ? batch.batch_no : null,
                            cost_price: batch ? batch.cost : product.cost_price || 0,
                            unit_price: batch ? batch.selling : product.price,
                            qty: 1,
                            discount: 0
                        });
                    }
                    this.recalculateTotals();
                },

                updateQty(index, change) {
                    const item = this.cart.items[index];
                    item.qty += change;
                    if (item.qty <= 0) item.qty = 1;
                    this.recalculateTotals();
                },

                removeItem(index) {
                    this.cart.items.splice(index, 1);
                    this.recalculateTotals();
                },

                clearCart() {
                    if (confirm('Are you sure you want to clear the cart?')) {
                        this.cart.items = [];
                        this.cart.discountValue = 0;
                        this.cart.tax = 0;
                        this.recalculateTotals();
                    }
                },

                openPayment() {
                    if (this.cart.items.length === 0) {
                        alert('Cart is empty.');
                        return;
                    }
                    this.paidAmount = this.cart.total;
                    document.getElementById('payment-modal').showModal();
                },

                async submitSale(type = 'Parts Sale') {
                    if (this.cart.items.length === 0) return;
                    
                    this.isSubmitting = true;
                    
                    const payload = {
                        customer_mode: this.cart.customerId ? 'existing' : 'walkin',
                        customer_id: this.cart.customerId || null,
                        store_id: this.storeId,
                        discount: this.cart.discount,
                        tax: this.cart.tax,
                        paid_amount: type === 'Quotation' ? 0 : (this.paymentMethod === 'Credit/Due' ? 0 : this.paidAmount),
                        payment_method: this.paymentMethod,
                        account_id: this.accountId,
                        invoice_type: type,
                        items: this.cart.items.map(i => ({
                            product_id: i.product.id,
                            stock_batch_id: i.stock_batch_id,
                            quantity: i.qty,
                            unit_price: i.unit_price,
                            discount: i.discount
                        }))
                    };

                    try {
                        const res = await fetch('{{ route("sales.parts.store") }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': '{{ csrf_token() }}'
                            },
                            body: JSON.stringify(payload)
                        });

                        const data = await res.json();
                        if (data.success) {
                            localStorage.removeItem('pos_cart');
                            window.location.href = `/sales/invoices/${data.invoice_id}`;
                        } else {
                            alert(data.message || 'Validation failed. Check the inputs.');
                        }
                    } catch (e) {
                        alert('An error occurred during submission.');
                        console.error(e);
                    } finally {
                        this.isSubmitting = false;
                    }
                },

                recalculateTotals() {
                    let sub = 0;
                    this.cart.items.forEach(i => {
                        sub += (i.qty * i.unit_price) - (i.discount || 0);
                    });
                    this.cart.subtotal = sub;

                    if (this.cart.discountType === 'fixed') {
                        this.cart.discount = parseFloat(this.cart.discountValue) || 0;
                    } else {
                        const pct = parseFloat(this.cart.discountValue) || 0;
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
