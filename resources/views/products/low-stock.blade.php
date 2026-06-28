<x-layouts::app title="Low Stock">
    <div class="space-y-5">
        <div>
            <p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Products / Stock</p>
            <h1 class="page-title">Low Stock</h1>
            <p class="page-subtitle">Products at or below their configured minimum level.</p>
        </div>
        
        @php($out=$stocks->getCollection()->where('quantity','<=',0)->count())
        
        <div class="grid gap-3 sm:grid-cols-3">
            <div class="panel">
                <p class="text-xs font-bold text-orange-600">Affected Records</p>
                <p class="mt-2 text-3xl font-black">{{ $stocks->total() }}</p>
            </div>
            <div class="panel">
                <p class="text-xs font-bold text-red-600">Out of Stock on Page</p>
                <p class="mt-2 text-3xl font-black">{{ $out }}</p>
            </div>
            <div class="panel">
                <p class="text-xs font-bold text-blue-600">Suggested Purchase Value</p>
                <p class="mt-2 text-3xl font-black">Rs. {{ number_format($stocks->sum(fn($s)=>max(0,($s->product->reorder_level ?: $s->product->minimum_stock)-$s->quantity)*$s->product->cost_price),2) }}</p>
            </div>
        </div>
        
        <form class="panel flex gap-2">
            <select name="supplier" class="field !w-auto" onchange="this.form.submit()">
                <option value="">All Suppliers</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected(request('supplier') == $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
            <input name="q" value="{{ request('q') }}" class="field" placeholder="Search parts by name or SKU">
            <button class="btn-secondary">Search</button>
        </form>
        
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Part / SKU</th>
                        <th>Category</th>
                        <th>Supplier</th>
                        <th>Store</th>
                        <th>Current Qty</th>
                        <th>Minimum</th>
                        <th>Status</th>
                        <th>Suggested Order</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($stocks as $stock)
                        @php($suggest=max(0,($stock->product->reorder_level ?: $stock->product->minimum_stock)-$stock->quantity))
                        <tr>
                            <td>{{ $stocks->firstItem()+$loop->index }}</td>
                            <td>
                                <b>{{ $stock->product->name }}</b><br>
                                <span class="text-xs text-slate-400">{{ $stock->product->code }}</span>
                            </td>
                            <td>{{ $stock->product->category?->name ?? '—' }}</td>
                            <td>{{ $stock->product->supplier?->name ?? '—' }}</td>
                            <td>{{ $stock->store->name }}</td>
                            <td class="font-black text-red-600">{{ $stock->quantity }}</td>
                            <td>{{ $stock->product->minimum_stock }}</td>
                            <td>
                                <span class="rounded-lg px-2.5 py-1 text-xs font-bold {{ $stock->quantity<=0?'bg-red-50 text-red-600':'bg-orange-50 text-orange-600' }}">
                                    {{ $stock->quantity<=0?'Out of stock':'Low stock' }}
                                </span>
                            </td>
                            <td class="font-black text-emerald-600">{{ number_format($suggest,2) }} {{ $stock->product->unit }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-14 text-center text-emerald-600">No low-stock records. Inventory is healthy.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="border-t p-4">{{ $stocks->links() }}</div>
        </div>
    </div>
</x-layouts::app>
