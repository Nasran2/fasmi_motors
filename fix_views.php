<?php
$file = 'resources/views/customers/index.blade.php';
$content = file_get_contents($file);

$search = '<a href="{{ route(\'customers.create\') }}" class="btn-primary">＋ Add Customer</a></div>';
$replace = <<<'HTML'
<div class="flex items-center gap-2">
    <a href="{{ route('customers.export') }}" class="btn-secondary">Export</a>
    <button onclick="document.getElementById('import-dialog').showModal()" class="btn-secondary">Import</button>
    <a href="{{ route('customers.create') }}" class="btn-primary">＋ Add Customer</a>
</div></div>
<dialog id="import-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Import Customers</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('import-dialog').close()">✕</button>
        </div>
        <form method="POST" action="{{ route('customers.import') }}" enctype="multipart/form-data">
            @csrf
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
</dialog>
HTML;

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);


$file = 'resources/views/products/index.blade.php';
$content = file_get_contents($file);

$search = '<a href="{{ route(\'products.create\') }}" class="btn-primary">＋ Add Product</a></div>';
$replace = <<<'HTML'
<div class="flex items-center gap-2">
    <a href="{{ route('products.export') }}" class="btn-secondary">Export</a>
    <button onclick="document.getElementById('import-dialog').showModal()" class="btn-secondary">Import</button>
    <a href="{{ route('products.create') }}" class="btn-primary">＋ Add Product</a>
</div></div>
<dialog id="import-dialog" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Import Products</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('import-dialog').close()">✕</button>
        </div>
        <form method="POST" action="{{ route('products.import') }}" enctype="multipart/form-data">
            @csrf
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
</dialog>
HTML;

$content = str_replace($search, $replace, $content);
file_put_contents($file, $content);

echo "Views updated!";
