<?php
$file = 'resources/views/jobs/show.blade.php';
$content = file_get_contents($file);

// Replace header
$content = str_replace(
    "<th class=\"font-bold\">{{ \$isLocked ? 'Product Name' : 'Product Name (Click to edit price)' }}</th>",
    "<th class=\"font-bold\">{{ \$isLocked ? 'Product Name' : 'Product Name (Click to edit)' }}</th>",
    $content
);

// Replace openPriceModal call
$content = preg_replace(
    "/openPriceModal\('\{\{ \\\$part->id \}\}', '\{\{ \\\$part->product->name \}\}', '\{\{ \\\$part->cost_price \}\}', '\{\{ \\\$part->unit_price \}\}'\)/",
    "openPartModal('{{ \$part->id }}', '{{ \$part->product->name }}', '{{ \$part->cost_price }}', '{{ \$part->unit_price }}', '{{ \$part->quantity }}')",
    $content
);

// Replace modal
$oldModal = <<<'MODAL'
<dialog id="price-edit-modal" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Change Product Price</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('price-edit-modal').close()">✕</button>
        </div>
        <form id="price-edit-form" method="POST" action="">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="label" style="color: #475569 !important;">Product Name</label>
                    <div id="pe-name" class="text-xs font-bold text-slate-800 border p-2.5 rounded-lg" style="background-color: #f8fafc !important; color: #1e293b !important; border-color: #cbd5e1 !important;"></div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="label" style="color: #475569 !important;">Cost Price</label>
                        <div id="pe-cost" class="text-xs font-bold text-slate-800 border p-2.5 rounded-lg font-mono" style="background-color: #f8fafc !important; color: #1e293b !important; border-color: #cbd5e1 !important;"></div>
                    </div>
                    <div>
                        <label class="label" style="color: #475569 !important;">New Selling Price *</label>
                        <input type="number" step="0.01" min="0" name="unit_price" id="pe-price" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" class="btn-secondary text-xs" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('price-edit-modal').close()">Cancel</button>
                    <button type="submit" class="btn-primary text-xs">Save Price</button>
                </div>
            </div>
        </form>
    </div>
</dialog>
MODAL;

$newModal = <<<'MODAL'
<dialog id="part-edit-modal" class="m-auto rounded-2xl p-0 shadow-2xl open:animate-in open:fade-in open:zoom-in-95" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #e2e8f0 !important; width: calc(100% - 2rem) !important; max-width: 28rem !important;">
    <div class="p-6 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <h2 class="text-base font-black text-slate-900" style="color: #0f172a !important;">Edit Part Details</h2>
            <button type="button" class="text-slate-400 hover:text-slate-700" onclick="document.getElementById('part-edit-modal').close()">✕</button>
        </div>
        <form id="part-edit-form" method="POST" action="">
            @csrf
            <div class="space-y-4">
                <div>
                    <label class="label" style="color: #475569 !important;">Product Name</label>
                    <div id="pe-name" class="text-xs font-bold text-slate-800 border p-2.5 rounded-lg" style="background-color: #f8fafc !important; color: #1e293b !important; border-color: #cbd5e1 !important;"></div>
                </div>
                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="label" style="color: #475569 !important;">Cost Price</label>
                        <div id="pe-cost" class="text-xs font-bold text-slate-800 border p-2.5 rounded-lg font-mono" style="background-color: #f8fafc !important; color: #1e293b !important; border-color: #cbd5e1 !important;"></div>
                    </div>
                    <div>
                        <label class="label" style="color: #475569 !important;">Quantity *</label>
                        <input type="number" step="any" min="0.01" name="quantity" id="pe-qty" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                    <div>
                        <label class="label" style="color: #475569 !important;">Unit Price *</label>
                        <input type="number" step="0.01" min="0" name="unit_price" id="pe-price" required class="w-full font-mono bg-white text-slate-900 p-2.5 rounded-lg" style="background-color: #ffffff !important; color: #0f172a !important; border: 1px solid #cbd5e1 !important;">
                    </div>
                </div>
                <div class="flex items-center justify-between pt-2">
                    <button type="button" id="btn-delete-part" class="btn-secondary text-xs text-red-600 border-red-200 bg-red-50 hover:bg-red-100" onclick="if(confirm('Are you sure you want to remove this part?')) { document.getElementById('part-delete-form').submit(); }">🗑 Delete Part</button>
                    <div class="flex gap-2">
                        <button type="button" class="btn-secondary text-xs" style="background-color: #f1f5f9 !important; color: #334155 !important; border: 1px solid #cbd5e1 !important;" onclick="document.getElementById('part-edit-modal').close()">Cancel</button>
                        <button type="submit" class="btn-primary text-xs">Save Changes</button>
                    </div>
                </div>
            </div>
        </form>
        <form id="part-delete-form" method="POST" action="" class="hidden">
            @csrf
            @method('DELETE')
        </form>
    </div>
</dialog>
MODAL;

$content = str_replace($oldModal, $newModal, $content);

// Replace JS function
$oldJs = <<<'JS'
function openPriceModal(partId, name, cost, price) {
    const modal = document.getElementById('price-edit-modal');
    const form = document.getElementById('price-edit-form');
    
    // Set form action route dynamically
    const route = "{{ route('jobs.parts.update-price', ['job' => $job->id, 'part' => ':part']) }}";
    form.action = route.replace(':part', partId);
    
    document.getElementById('pe-name').textContent = name;
    document.getElementById('pe-cost').textContent = 'Rs. ' + parseFloat(cost).toFixed(2);
    document.getElementById('pe-price').value = parseFloat(price);
    
    modal.showModal();
}
JS;

$newJs = <<<'JS'
function openPartModal(partId, name, cost, price, qty) {
    const modal = document.getElementById('part-edit-modal');
    const form = document.getElementById('part-edit-form');
    const delForm = document.getElementById('part-delete-form');
    
    // Set form action route dynamically
    const updateRoute = "{{ route('jobs.parts.update', ['job' => $job->id, 'part' => ':part']) }}";
    const deleteRoute = "{{ route('jobs.parts.destroy', ['job' => $job->id, 'part' => ':part']) }}";
    
    form.action = updateRoute.replace(':part', partId);
    delForm.action = deleteRoute.replace(':part', partId);
    
    document.getElementById('pe-name').textContent = name;
    document.getElementById('pe-cost').textContent = 'Rs. ' + parseFloat(cost).toFixed(2);
    document.getElementById('pe-qty').value = parseFloat(qty);
    document.getElementById('pe-price').value = parseFloat(price);
    
    modal.showModal();
}
JS;

$content = str_replace($oldJs, $newJs, $content);

file_put_contents($file, $content);
echo "Blade fixed!";
