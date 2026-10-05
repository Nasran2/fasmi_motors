<x-layouts::app title="Product Details">
<div class="space-y-6">
    <!-- Header Section with Gradient -->
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 p-8 text-white shadow-2xl">
        <!-- Abstract background elements -->
        <div class="absolute -right-20 -top-20 size-64 rounded-full bg-red-500/20 blur-3xl"></div>
        <div class="absolute -bottom-20 -left-20 size-64 rounded-full bg-blue-500/20 blur-3xl"></div>

        <div class="relative z-10 flex flex-col items-start gap-6 md:flex-row md:items-center">
            @if($product->image_path)
                <img src="{{ asset('storage/'.$product->image_path) }}" class="size-32 rounded-2xl object-cover ring-4 ring-white/10 shadow-2xl transition-transform hover:scale-105 duration-300">
            @else
                <div class="grid size-32 shrink-0 place-items-center rounded-2xl bg-white/5 ring-1 ring-white/20 shadow-2xl backdrop-blur-sm">
                    <svg class="size-12 text-white/30" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                </div>
            @endif
            
            <div class="flex-1">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <span class="rounded-full bg-white/10 px-4 py-1.5 text-xs font-bold tracking-wider text-white ring-1 ring-white/20 backdrop-blur-md">
                        {{ $product->category?->name ?? 'Uncategorized' }}
                    </span>
                    <span class="rounded-full bg-red-500/20 px-4 py-1.5 text-xs font-bold tracking-wider text-red-200 ring-1 ring-red-500/30 backdrop-blur-md">
                        {{ $product->brand ?: 'No Brand' }}
                    </span>
                </div>
                <h1 class="text-3xl font-black tracking-tight lg:text-4xl">{{ $product->name }}</h1>
                <div class="mt-4 flex flex-wrap items-center gap-6 text-sm font-medium text-slate-300">
                    <div class="flex items-center gap-2">
                        <div class="grid size-7 place-items-center rounded-full bg-white/5 ring-1 ring-white/10">
                            <svg class="size-3.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/></svg>
                        </div>
                        <span>SKU: <span class="font-bold text-white">{{ $product->code }}</span></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="grid size-7 place-items-center rounded-full bg-white/5 ring-1 ring-white/10">
                            <svg class="size-3.5 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v1m6 11h2m-6 0h-2v4m0-11v3m0 0h.01M12 12h4.01M16 20h4M4 12h4m12 0h.01M5 8h2a1 1 0 001-1V5a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1zm12 0h2a1 1 0 001-1V5a1 1 0 00-1-1h-2a1 1 0 00-1 1v2a1 1 0 001 1zM5 20h2a1 1 0 001-1v-2a1 1 0 00-1-1H5a1 1 0 00-1 1v2a1 1 0 001 1z"/></svg>
                        </div>
                        <span>Barcode: <span class="font-bold text-white">{{ $product->barcode ?: 'N/A' }}</span></span>
                    </div>
                </div>
            </div>
            
            <div class="flex w-full gap-4 md:w-auto md:flex-col lg:flex-row mt-4 md:mt-0">
                <div class="flex-1 rounded-2xl bg-white/10 p-5 ring-1 ring-white/20 backdrop-blur-md md:flex-none">
                    <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Default Cost</p>
                    <p class="mt-1 text-2xl font-black text-white">Rs. {{ number_format($product->cost_price, 2) }}</p>
                </div>
                <div class="flex-1 rounded-2xl bg-gradient-to-br from-emerald-500 to-emerald-700 p-5 ring-1 ring-emerald-400/50 shadow-lg shadow-emerald-900/50 md:flex-none">
                    <p class="text-xs font-bold uppercase tracking-wider text-emerald-100">Default Selling</p>
                    <p class="mt-1 text-2xl font-black text-white">Rs. {{ number_format($product->selling_price, 2) }}</p>
                </div>
            </div>
        </div>
    </div>

    <x-flash/>

    <div class="grid gap-6 xl:grid-cols-3">
        <!-- Stock By Store Panel -->
        <div class="group relative overflow-hidden rounded-3xl border-0 ring-1 ring-slate-200/60 shadow-sm transition-all hover:shadow-md bg-white">
            <div class="absolute inset-0 bg-gradient-to-b from-slate-50/50 to-transparent pointer-events-none"></div>
            <div class="relative p-6">
                <div class="flex items-center gap-4 border-b border-slate-100 pb-5">
                    <div class="grid size-12 place-items-center rounded-2xl bg-blue-50 text-blue-600 ring-1 ring-blue-100/50 shadow-sm">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
                    </div>
                    <div>
                        <h2 class="text-xl font-black text-slate-900">Stock by Store</h2>
                        <p class="text-sm font-medium text-slate-500">Current availability across locations</p>
                    </div>
                </div>
                
                <div class="mt-6 space-y-4">
                    @forelse($product->stocks as $stock)
                        <div class="group/item flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 shadow-sm transition-all hover:border-blue-200 hover:shadow-md hover:ring-1 hover:ring-blue-100">
                            <div class="flex items-center gap-4">
                                <div class="grid size-10 place-items-center rounded-full bg-slate-50 text-slate-500 ring-1 ring-slate-200/50 transition-colors group-hover/item:bg-blue-50 group-hover/item:text-blue-600 group-hover/item:ring-blue-200">
                                    <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </div>
                                <p class="text-base font-bold text-slate-700">{{ $stock->store->name }}</p>
                            </div>
                            <div class="text-right">
                                @if($stock->quantity > 0)
                                    <div class="flex items-center gap-2 rounded-full bg-emerald-50 px-4 py-1.5 ring-1 ring-emerald-200">
                                        <div class="size-2 rounded-full bg-emerald-500 animate-pulse shadow-[0_0_8px_rgba(16,185,129,0.8)]"></div>
                                        <b class="text-sm font-black text-emerald-700">{{ $stock->quantity }} <span class="text-xs font-bold uppercase text-emerald-600/80">{{ $product->unit }}</span></b>
                                    </div>
                                @else
                                    <div class="flex items-center gap-2 rounded-full bg-red-50 px-4 py-1.5 ring-1 ring-red-200">
                                        <div class="size-2 rounded-full bg-red-500"></div>
                                        <b class="text-sm font-black text-red-700">Out of Stock</b>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-10 text-center">
                            <p class="text-sm font-semibold text-slate-500">No stock available in any store.</p>
                        </div>
                    @endforelse
                </div>
            </div>
        </div>
        
        <!-- Stock Batches Table Panel -->
        <div class="xl:col-span-2 relative overflow-hidden rounded-3xl border-0 ring-1 ring-slate-200/60 shadow-sm transition-all bg-white">
            <div class="absolute inset-0 bg-gradient-to-b from-slate-50/50 to-transparent pointer-events-none"></div>
            <div class="relative p-6">
                <div class="mb-6 flex flex-col justify-between gap-4 border-b border-slate-100 pb-5 sm:flex-row sm:items-center">
                    <div class="flex items-center gap-4">
                        <div class="grid size-12 place-items-center rounded-2xl bg-indigo-50 text-indigo-600 ring-1 ring-indigo-100/50 shadow-sm">
                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <h2 class="text-xl font-black text-slate-900">Stock Batches</h2>
                            <p class="text-sm font-medium text-slate-500">Breakdown of available stock by purchase batches</p>
                        </div>
                    </div>
                </div>
                
                <div class="overflow-x-auto rounded-2xl border border-slate-100 shadow-sm ring-1 ring-black/5">
                    <table class="w-full text-left text-sm text-slate-600">
                        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th class="px-6 py-5 font-extrabold text-slate-700">Batch No</th>
                                <th class="px-6 py-5 font-extrabold text-slate-700">Store</th>
                                <th class="px-6 py-5 font-extrabold text-slate-700">Cost Price</th>
                                <th class="px-6 py-5 font-extrabold text-slate-700">Selling Price</th>
                                <th class="px-6 py-5 font-extrabold text-slate-700 text-right">Available Qty</th>
                                <th class="px-6 py-5 font-extrabold text-slate-700">Supplier</th>
                                <th class="px-6 py-5 font-extrabold text-slate-700">Purchase Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 bg-white">
                            @forelse($product->stockBatches as $batch)
                                @if($batch->available_qty > 0)
                                    <tr class="group transition-colors hover:bg-indigo-50/40">
                                        <td class="whitespace-nowrap px-6 py-4 font-black text-slate-900">
                                            <div class="flex items-center gap-3">
                                                <span class="size-2 rounded-full bg-indigo-500 ring-2 ring-indigo-200 group-hover:animate-pulse"></span>
                                                {{ $batch->batch_no }}
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 font-bold text-slate-700">{{ $batch->store->name }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 font-bold text-slate-700">Rs. {{ number_format($batch->cost_price, 2) }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 font-black text-emerald-600">Rs. {{ number_format($batch->selling_price, 2) }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-right">
                                            <span class="inline-flex items-center justify-center rounded-xl bg-emerald-100/80 px-4 py-1.5 font-black text-emerald-700 ring-1 ring-emerald-200/50 shadow-sm">
                                                {{ $batch->available_qty }}
                                            </span>
                                        </td>
                                        <td class="whitespace-nowrap px-6 py-4 text-xs font-bold text-slate-600">{{ $batch->supplier?->name ?? '—' }}</td>
                                        <td class="whitespace-nowrap px-6 py-4 text-xs font-bold text-slate-500">
                                            <div class="flex items-center gap-2">
                                                <div class="grid size-6 place-items-center rounded bg-slate-100 text-slate-400">
                                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                </div>
                                                {{ $batch->purchase_date->format('M d, Y') }}
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @empty
                                <tr>
                                    <td colspan="7" class="px-6 py-20 text-center">
                                        <div class="flex flex-col items-center justify-center space-y-4">
                                            <div class="grid size-16 place-items-center rounded-3xl bg-slate-50 text-slate-300 ring-1 ring-slate-200/50 shadow-inner">
                                                <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                                            </div>
                                            <div>
                                                <p class="text-base font-bold text-slate-600">No active stock batches found</p>
                                                <p class="mt-1 text-sm font-medium text-slate-400">There is currently no inventory batch available for this product.</p>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</x-layouts::app>
