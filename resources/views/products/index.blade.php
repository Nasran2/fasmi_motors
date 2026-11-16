<x-layouts::app title="Products & Stock">
<div class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3"><div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Inventory</p><h1 class="page-title">Spare Parts & Stock</h1><p class="page-subtitle">Manage real product quantities, pricing and store availability.</p></div><div class="flex flex-wrap gap-2"><a href="{{ route('products.transfers') }}" class="btn-secondary">⇄ Stock Transfer</a><div class="flex flex-wrap items-center gap-2">
    <a href="{{ route('products.excel.export') }}" class="btn-secondary">Export Excel</a>
    <button onclick="document.getElementById('export-pdf-dialog').showModal()" class="btn-secondary">Export PDF</button>
    <button onclick="document.getElementById('import-dialog').showModal()" class="btn-secondary">Import</button>
    <a href="{{ route('products.create') }}" class="btn-primary">＋ Add Product</a>
</div></div></div>
@include('products.partials.pdf-export')
<dialog id="import-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Import Products</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('import-dialog').close()">✕</button>
        </div>
        <form method="POST" action="{{ route('products.excel.import') }}" enctype="multipart/form-data">
            @csrf<x-submission-token/>
            <div class="space-y-4">
                <div>
                    <label class="label" style="color: #475569 !important;">Upload Excel/CSV File</label>
                    <input type="file" name="file" required accept=".csv,.xlsx,.xls" class="w-full text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn-secondary text-xs" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('import-dialog').close()">Cancel</button>
                    <button type="submit" class="btn-primary text-xs">Upload & Import</button>
                </div>
            </div>
        </form>
    </div>
</dialog></div>
    <x-flash/>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach([['Total Products',$stats['products'],'#2563eb','▣'],['Products In Stock',$stats['in_stock'],'#16a34a','✓'],['Low / Out of Stock',$stats['low'],'#f97316','!'],['Stock Value','Rs. '.number_format($stats['value'],2),'#7c3aed','Rs']] as $stat)
        <div class="panel flex items-center gap-4"><span class="grid size-12 shrink-0 place-items-center rounded-2xl text-sm font-black" style="color:{{ $stat[2] }};background:color-mix(in srgb, {{ $stat[2] }} 11%, white)">{{ $stat[3] }}</span><div><p class="text-xs font-bold text-slate-500">{{ $stat[0] }}</p><p class="mt-1 text-2xl font-black tracking-tight text-slate-950">{{ $stat[1] }}</p></div></div>
        @endforeach
    </div>
    <div class="grid gap-5 2xl:grid-cols-[minmax(0,1fr)_310px]">
        <div class="space-y-4">
            <form class="panel grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <label><span class="label">Rack No</span><input type="text" name="rack_number" value="{{ request('rack_number') }}" class="field" placeholder="E.g. A1"></label>
                <label><span class="label">Brand</span><select name="brand" class="field"><option value="">All brands</option>@foreach($brands as $brand)<option @selected(request('brand')===$brand->name)>{{ $brand->name }}</option>@endforeach</select></label>
                <label><span class="label">Status</span><select name="status" class="field"><option value="">All statuses</option><option value="low" @selected(request('status')==='low')>Low / out of stock</option></select></label>
                <label class="xl:col-span-2"><span class="label">Search products</span><div class="workshop-search-row"><input name="q" value="{{ request('q') }}" class="field" placeholder="Name, SKU or barcode"><button class="btn-secondary">Search</button></div></label>
            </form>
            @php
                $sortLink = function($column, $label) {
                    $currentSort = request('sort_by', 'name');
                    $currentDir = request('sort_dir', 'asc');
                    $isSorted = $currentSort === $column;
                    $newDir = $isSorted && $currentDir === 'asc' ? 'desc' : 'asc';
                    $url = request()->fullUrlWithQuery(['sort_by' => $column, 'sort_dir' => $newDir]);
                    $icon = $isSorted ? ($currentDir === 'asc' ? '↑' : '↓') : '';
                    return "<a href=\"{$url}\" class=\"flex items-center gap-1 hover:text-blue-600\">{$label} <span class=\"text-blue-500\">{$icon}</span></a>";
                };
            @endphp
            <div class="table-wrap"><table class="data-table"><thead><tr>
                <th>{!! $sortLink('name', 'Product') !!}</th>
                <th>{!! $sortLink('code', 'SKU / Barcode') !!}</th>
                <th>{!! $sortLink('rack_number', 'Rack No') !!}</th>
                <th>{!! $sortLink('brand', 'Brand') !!}</th>
                <th>{!! $sortLink('stocks_sum_quantity', 'Qty') !!}</th>
                <th>{!! $sortLink('cost_price', 'Cost') !!}</th>
                <th>{!! $sortLink('selling_price', 'Sell') !!}</th>
                <th>Status</th>
                <th class="w-20 text-right">Actions</th>
            </tr></thead><tbody>
                @forelse($products as $product) @php($qty=(float)($product->stocks_sum_quantity ?? 0))
                <tr><td><div class="flex items-center gap-3">@if($product->image_path)<img src="{{ asset('storage/'.$product->image_path) }}" class="size-10 rounded-xl object-cover">@else<span class="grid size-10 place-items-center rounded-xl bg-slate-100 text-slate-500">▣</span>@endif<div class="min-w-0"><a href="{{ route('products.show', $product) }}" class="font-bold text-slate-900 hover:text-blue-600 hover:underline">{{ $product->name }}</a><p class="text-xs text-slate-400">{{ $product->unit }}</p></div>
                    @if(auth()->user()->canAccess('product.edit'))
                        <form method="POST" action="{{ route('products.pos-pin.toggle', $product) }}" class="ml-auto shrink-0">@csrf<x-submission-token/><button type="submit" class="grid size-9 place-items-center rounded-lg transition focus:outline-none focus:ring-2 focus:ring-amber-400 {{ $product->pos_pinned ? 'bg-amber-50 text-amber-500 hover:bg-amber-100' : 'text-slate-300 hover:bg-amber-50 hover:text-amber-500' }}" title="{{ $product->pos_pinned ? 'Remove from POS pins' : 'Pin to front of POS' }}" aria-label="{{ $product->pos_pinned ? 'Unpin ' : 'Pin ' }}{{ $product->name }} in POS" aria-pressed="{{ $product->pos_pinned ? 'true' : 'false' }}"><svg class="size-5" viewBox="0 0 24 24" fill="{{ $product->pos_pinned ? 'currentColor' : 'none' }}" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="m12 2 3.1 6.3 7 .9-5 4.9 1.2 6.9L12 17.8 5.7 21l1.2-6.9-5-4.9 7-.9L12 2Z"/></svg></button></form>
                    @elseif($product->pos_pinned)<span class="ml-auto text-amber-500" title="Pinned in POS" aria-label="Pinned in POS">★</span>@endif
                </div></td><td><b>{{ $product->code }}</b><br><span class="text-xs text-slate-400">{{ $product->barcode ?: 'No barcode' }}</span></td><td>{{ $product->rack_number ?: '—' }}</td><td>{{ $product->brand ?: '—' }}</td><td class="font-black {{ $qty <= $product->minimum_stock ? 'text-red-600':'text-emerald-600' }}">{{ number_format($qty,2) }}</td><td>Rs. {{ number_format($product->cost_price,2) }}</td><td>Rs. {{ number_format($product->selling_price,2) }}</td><td><span class="rounded-lg px-2.5 py-1 text-xs font-bold {{ $qty<=0?'bg-red-50 text-red-600':($qty<=$product->minimum_stock?'bg-orange-50 text-orange-600':'bg-emerald-50 text-emerald-700') }}">{{ $qty<=0?'Out of stock':($qty<=$product->minimum_stock?'Low stock':'In stock') }}</span></td>
                <td>
                    <div class="flex justify-end gap-2">
                        @if(auth()->user()->canAccess('product.edit'))
                            <a href="{{ route('products.edit', $product) }}" class="text-slate-400 hover:text-blue-600" title="Edit">✎</a>
                        @endif
                        @if(auth()->user()->canAccess('product.delete'))
                            <form method="POST" action="{{ route('products.destroy', $product) }}" onsubmit="return confirm('Are you sure you want to delete this product?')">
                                @csrf @method('DELETE')
                                <button class="text-slate-400 hover:text-red-600" title="Delete">🗑</button>
                            </form>
                        @endif
                    </div>
                </td>
                </tr>
                @empty <tr><td colspan="8" class="py-14 text-center text-slate-500">No products match these filters. Add the first product to begin tracking stock.</td></tr>@endforelse
            </tbody></table><div class="border-t border-slate-100 p-4">{{ $products->links() }}</div></div>
        </div>
        <aside class="space-y-4"><div class="panel"><div class="flex justify-between"><h2 class="font-extrabold">Low Stock Alerts</h2><a href="{{ route('products.low-stock') }}" class="text-xs font-bold text-red-600">View all</a></div><div class="mt-4 divide-y divide-slate-100">@forelse($lowStocks as $stock)<div class="flex items-center justify-between py-3"><div><p class="text-sm font-bold">{{ $stock->product->name }}</p><p class="text-xs text-slate-400">{{ $stock->store->name }}</p></div><b class="text-red-600">{{ $stock->quantity }}</b></div>@empty<p class="py-8 text-center text-sm text-emerald-600">All tracked store stock is healthy.</p>@endforelse</div></div><div class="panel"><h2 class="font-extrabold">Store Overview</h2><div class="mt-3 space-y-3">@forelse($stores as $store)<a href="{{ route('products.store-stock',['store'=>$store->id]) }}" class="flex items-center justify-between rounded-xl bg-slate-50 p-3 transition hover:bg-slate-100"><div><p class="text-sm font-bold">{{ $store->name }}</p><p class="text-xs text-slate-400">{{ $store->stocks_count }} product lines</p></div><b class="text-sm text-blue-600">{{ number_format($store->stocks_sum_quantity ?? 0,2) }}</b></a>@empty<p class="text-sm text-slate-500">No stores configured.</p>@endforelse</div></div></aside>
    </div>
</div>
</x-layouts::app>
