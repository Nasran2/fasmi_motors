<x-layouts::app title="Import Products">
<div class="mx-auto max-w-5xl space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div><p class="text-xs font-extrabold uppercase tracking-[.18em] text-red-600">Products / Stock</p><h1 class="page-title">Import Products</h1><p class="page-subtitle">Upload the product list spreadsheet and create products with their Main Store opening stock.</p></div>
        <a href="{{ route('products.index') }}" class="btn-secondary">← Back to Products</a>
    </div>
    <x-flash/>
    @if(session('import_errors'))
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900"><strong>Rows needing attention:</strong><ul class="mt-2 list-disc space-y-1 pl-5">@foreach(session('import_errors') as $message)<li>{{ $message }}</li>@endforeach</ul></div>
    @endif
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_330px]">
        <form method="POST" action="{{ route('products.import.store') }}" enctype="multipart/form-data" class="panel space-y-5">@csrf<x-submission-token/>
            <div><h2 class="text-lg font-extrabold">Choose Product Spreadsheet</h2><p class="mt-1 text-sm text-slate-500">Excel (.xlsx, .xls) or CSV, up to 20 MB.</p></div>
            <label class="grid min-h-60 cursor-pointer place-items-center rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50 p-6 text-center transition hover:border-red-300 hover:bg-red-50/30">
                <div><span class="mx-auto grid size-14 place-items-center rounded-2xl bg-white text-2xl shadow-sm">⇧</span><p class="mt-3 font-bold">Select product file</p><p id="import-file-name" class="mt-1 text-xs text-slate-500">No file selected</p></div>
                <input id="import-file" type="file" name="file" accept=".xlsx,.xls,.csv" class="sr-only" required>
            </label>
            <div class="rounded-xl bg-slate-50 p-4 text-sm"><span class="font-bold">Opening store:</span> {{ $mainStore?->name ?? 'Main Store not configured' }}</div>
            <button class="btn-primary w-full" @disabled(! $mainStore)>Import Products</button>
        </form>
        <aside class="panel h-fit">
            <h2 class="text-lg font-extrabold">Column Mapping</h2>
            <p class="mt-1 text-sm text-slate-500">The importer automatically finds the header row and maps:</p>
            <dl class="mt-4 space-y-3 text-sm">
                @foreach(['Name' => 'Product name', 'Barcode' => 'Barcode / generated SKU', 'Group Name' => 'Category, brand and supplier', 'Op. Amount' => 'Opening amount', 'Op. Stock(Main)' => 'Main Store quantity', 'Purc. Price' => 'Cost price', 'Sale Price' => 'Selling price', 'Rack No' => 'Rack location'] as $source => $target)
                    <div class="flex items-center justify-between gap-3 border-b border-slate-100 pb-2"><dt class="font-bold">{{ $source }}</dt><dd class="text-right text-slate-500">{{ $target }}</dd></div>
                @endforeach
            </dl>
            <p class="mt-4 rounded-xl bg-blue-50 p-3 text-xs leading-5 text-blue-800">Existing product names/barcodes are skipped. Placeholder barcodes such as “8” are left empty and a unique SKU is generated.</p>
        </aside>
    </div>
</div>
<script>
document.getElementById('import-file')?.addEventListener('change', event => {
    document.getElementById('import-file-name').textContent = event.target.files[0]?.name || 'No file selected';
});
</script>
</x-layouts::app>
