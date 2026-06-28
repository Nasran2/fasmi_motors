<x-layouts::app title="Product Details">
<div class="space-y-5">
    <div>
        <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Products / Details</p>
        <h1 class="page-title">{{ $product->name }}</h1>
        <p class="page-subtitle">Product details and batch-wise stock levels.</p>
    </div>

    <x-flash/>

    <div class="grid gap-5 xl:grid-cols-3">
        <div class="space-y-5">
            <div class="panel">
                <h2 class="text-lg font-extrabold">Product Information</h2>
                <div class="mt-4 flex gap-4">
                    @if($product->image_path)
                        <img src="{{ asset('storage/'.$product->image_path) }}" class="size-20 rounded-xl object-cover">
                    @else
                        <span class="grid size-20 place-items-center rounded-xl bg-slate-100 text-2xl text-slate-500">▣</span>
                    @endif
                    <div>
                        <p class="text-sm font-bold text-slate-900">{{ $product->name }}</p>
                        <p class="text-xs text-slate-500">SKU: {{ $product->code }}</p>
                        <p class="text-xs text-slate-500">Barcode: {{ $product->barcode ?: 'N/A' }}</p>
                    </div>
                </div>
                <div class="mt-5 grid grid-cols-2 gap-4">
                    <div>
                        <small class="text-slate-400">Category</small>
                        <p class="font-bold">{{ $product->category?->name ?? '—' }}</p>
                    </div>
                    <div>
                        <small class="text-slate-400">Brand</small>
                        <p class="font-bold">{{ $product->brand ?: '—' }}</p>
                    </div>
                    <div>
                        <small class="text-slate-400">Default Cost</small>
                        <p class="font-bold text-slate-900">Rs. {{ number_format($product->cost_price, 2) }}</p>
                    </div>
                    <div>
                        <small class="text-slate-400">Default Selling</small>
                        <p class="font-bold text-slate-900">Rs. {{ number_format($product->selling_price, 2) }}</p>
                    </div>
                </div>
            </div>
            
            <div class="panel">
                <h2 class="text-lg font-extrabold">Stock by Store</h2>
                <div class="mt-4 space-y-3">
                    @forelse($product->stocks as $stock)
                        <div class="flex items-center justify-between rounded-xl bg-slate-50 p-3">
                            <p class="text-sm font-bold">{{ $stock->store->name }}</p>
                            <b class="text-sm {{ $stock->quantity <= 0 ? 'text-red-600' : 'text-emerald-600' }}">{{ $stock->quantity }} {{ $product->unit }}</b>
                        </div>
                    @empty
                        <p class="text-sm text-slate-500">No stock in any store.</p>
                    @endforelse
                </div>
            </div>
        </div>
        
        <div class="xl:col-span-2">
            <div class="panel">
                <h2 class="text-lg font-extrabold">Stock Batches</h2>
                <p class="text-sm text-slate-500">Breakdown of available stock by purchase price batches.</p>
                
                <div class="mt-4 overflow-x-auto">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Batch No</th>
                                <th>Store</th>
                                <th>Cost Price</th>
                                <th>Selling Price</th>
                                <th>Available Qty</th>
                                <th>Supplier</th>
                                <th>Purchase Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($product->stockBatches as $batch)
                                @if($batch->available_qty > 0)
                                    <tr>
                                        <td class="font-bold text-slate-900">{{ $batch->batch_no }}</td>
                                        <td>{{ $batch->store->name }}</td>
                                        <td>Rs. {{ number_format($batch->cost_price, 2) }}</td>
                                        <td>Rs. {{ number_format($batch->selling_price, 2) }}</td>
                                        <td class="font-black text-emerald-600">{{ $batch->available_qty }}</td>
                                        <td class="text-xs">{{ $batch->supplier?->name ?? '—' }}</td>
                                        <td class="text-xs text-slate-500">{{ $batch->purchase_date->format('M d, Y') }}</td>
                                    </tr>
                                @endif
                            @empty
                                <tr><td colspan="7" class="py-14 text-center text-slate-500">No active stock batches found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</x-layouts::app>
