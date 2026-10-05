<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockDamageReport;
use App\Models\StockMovement;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Supplier;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class ProductController extends Controller
{
    public function togglePosPin(Product $product)
    {
        DB::transaction(function () use ($product) {
            $product = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            if ($product->pos_pinned) {
                $product->update(['pos_pinned' => false, 'pos_pin_order' => null]);
            } else {
                $nextOrder = (int) Product::where('pos_pinned', true)->max('pos_pin_order') + 1;
                $product->update(['pos_pinned' => true, 'pos_pin_order' => $nextOrder]);
            }
        });

        return back()->with('success', 'POS pin updated.');
    }

    public function posPins()
    {
        return response()->json([
            'product_ids' => Product::where('active', true)->where('pos_pinned', true)
                ->orderBy('pos_pin_order')->orderBy('name')->pluck('id'),
        ]);
    }

    public function reorderPosPins(Request $request)
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array'],
            'product_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        DB::transaction(function () use ($data) {
            $pinnedIds = Product::where('active', true)->where('pos_pinned', true)
                ->lockForUpdate()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $submittedIds = array_map('intval', $data['product_ids']);
            if (count($submittedIds) !== count($pinnedIds) || array_diff($submittedIds, $pinnedIds)) {
                throw ValidationException::withMessages(['product_ids' => 'Pinned products changed. Reopen the pin manager and try again.']);
            }

            foreach ($submittedIds as $position => $id) {
                Product::whereKey($id)->update(['pos_pin_order' => $position + 1]);
            }
        });

        return response()->json(['success' => true]);
    }

    public function index(Request $request)
    {
        $products = Product::query()
            ->with(['category', 'supplier'])
            ->withSum('stocks', 'quantity')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($x) => $x
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('code', 'like', '%'.$request->q.'%')
                ->orWhere('barcode', 'like', '%'.$request->q.'%')
                ->orWhere('rack_number', 'like', '%'.$request->q.'%')))
            ->when($request->filled('rack_number'), fn ($q) => $q->where('rack_number', 'like', '%'.$request->rack_number.'%'))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->brand))
            ->when($request->status === 'low', fn ($q) => $q->whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock'));

        $sortBy = $request->input('sort_by', 'name');
        $sortDir = $request->input('sort_dir', 'asc');
        
        $validSortColumns = ['name', 'code', 'rack_number', 'brand', 'stocks_sum_quantity', 'cost_price', 'selling_price'];
        if (!in_array($sortBy, $validSortColumns)) {
            $sortBy = 'name';
        }
        if (!in_array($sortDir, ['asc', 'desc'])) {
            $sortDir = 'asc';
        }

        $products = $products->orderBy($sortBy, $sortDir)->paginate(10)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => ProductCategory::orderBy('name')->get(),
            'brands' => ProductBrand::orderBy('name')->get(),
            'selectedSupplier' => $request->filled('supplier') ? Supplier::find($request->supplier) : null,
            'stats' => $this->stockStats(),
            'lowStocks' => StoreStock::with(['product', 'store'])->whereHas('product', fn ($q) => $q->whereColumn('store_stocks.quantity', '<=', 'products.minimum_stock'))->orderBy('quantity')->limit(6)->get(),
            'stores' => Store::withCount('stocks')->withSum('stocks', 'quantity')->where('active', true)->get(),
        ]);
    }

    public function create()
    {
        return view('products.create', $this->formData());
    }

    public function store(Request $request, WorkshopService $service)
    {
        $data = $request->validate([
            'code' => ['required', 'max:50', 'unique:products'], 'barcode' => ['nullable', 'max:100', 'unique:products'],
            'name' => ['required', 'max:150'], 'category_id' => ['required', 'exists:product_categories,id'],
            'brand' => ['required', 'max:100'], 'unit' => ['required', 'max:30'], 'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'cost_price' => ['required', 'numeric', 'min:0'], 'selling_price' => ['required', 'numeric', 'min:0'],
            'opening_amount' => ['nullable', 'numeric', 'min:0'], 'rack_number' => ['nullable', 'max:100'],
            'minimum_stock' => ['required', 'numeric', 'min:0'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'opening_quantity' => ['required', 'numeric', 'min:0'],
            'model_compatibility' => ['nullable', 'max:255'], 'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $opening = (float) $data['opening_quantity'];
        $data['opening_amount'] = filled($data['opening_amount'] ?? null)
            ? (float) $data['opening_amount']
            : $opening * (float) $data['cost_price'];
        $mainStore = Store::where('is_default', true)->first() ?? Store::where('name', 'Main Store')->first();
        if (! $mainStore) {
            throw ValidationException::withMessages(['opening_quantity' => 'Main Store must be configured before adding products.']);
        }
        $storeId = $mainStore->id;
        unset($data['opening_quantity'], $data['image']);
        if ($request->hasFile('image')) {
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }
        $data['active'] = true;

        $product = DB::transaction(function () use ($data, $opening, $storeId, $service) {
            $product = Product::create($data);
            StoreStock::firstOrCreate(['store_id' => $storeId, 'product_id' => $product->id], ['quantity' => 0]);
            if ($opening > 0) {
                $service->adjustStock($storeId, $product->id, $opening, 'opening', $product, 'Opening stock');
            }

            return $product;
        });

        return $request->boolean('save_new')
            ? redirect()->route('products.create')->with('success', "{$product->name} saved. Add another product.")
            : redirect()->route('products.index')->with('success', "{$product->name} was added to inventory.");
    }
    public function edit(Product $product)
    {
        return view('products.edit', [
            'product' => $product,
            'categories' => ProductCategory::orderBy('name')->get(),
            'brands' => ProductBrand::orderBy('name')->get(),
            'units' => ProductUnit::orderBy('name')->get(),
            'suppliers' => Supplier::orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'code' => ['required', 'max:50', \Illuminate\Validation\Rule::unique('products')->ignore($product->id)],
            'barcode' => ['nullable', 'max:100', \Illuminate\Validation\Rule::unique('products')->ignore($product->id)],
            'name' => ['required', 'max:150'], 'category_id' => ['required', 'exists:product_categories,id'],
            'brand' => ['required', 'max:100'], 'unit' => ['required', 'max:30'], 'supplier_id' => ['nullable', 'exists:suppliers,id'],
            'cost_price' => ['required', 'numeric', 'min:0'], 'selling_price' => ['required', 'numeric', 'min:0'],
            'rack_number' => ['nullable', 'max:100'],
            'minimum_stock' => ['required', 'numeric', 'min:0'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'model_compatibility' => ['nullable', 'max:255'], 'image' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->boolean('remove_image') && $product->image_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image_path);
            $data['image_path'] = null;
        }

        unset($data['image']);
        if ($request->hasFile('image')) {
            if ($product->image_path) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image_path);
            }
            $data['image_path'] = $request->file('image')->store('products', 'public');
        }

        $product->update($data);

        return redirect()->route('products.index')->with('success', "{$product->name} was updated.");
    }

    public function destroy(Product $product)
    {
        if ($product->image_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($product->image_path);
        }
        $product->delete();

        return back()->with('success', "{$product->name} was deleted.");
    }
    public function importForm()
    {
        return view('products.import', [
            'mainStore' => Store::where('is_default', true)->first() ?? Store::where('name', 'Main Store')->first(),
        ]);
    }

    public function importStore(Request $request, WorkshopService $service)
    {
        if (function_exists('set_time_limit')) {
            set_time_limit(0);
        }

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        $mainStore = Store::where('is_default', true)->first() ?? Store::where('name', 'Main Store')->first();
        if (! $mainStore) {
            throw ValidationException::withMessages(['file' => 'Main Store must be configured before importing products.']);
        }

        try {
            $rows = IOFactory::load($request->file('file')->getRealPath())
                ->getActiveSheet()
                ->toArray(null, true, true, false);
        } catch (Throwable) {
            throw ValidationException::withMessages(['file' => 'The spreadsheet could not be read. Please upload a valid Excel or CSV file.']);
        }

        [$headerRow, $columns] = $this->locateImportHeader($rows);
        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $messages = [];
        $knownProducts = Product::withTrashed()->get(['id', 'name', 'barcode', 'code']);
        $existingNames = array_fill_keys($knownProducts->pluck('name')->map(fn ($name) => Str::lower($name))->all(), true);
        $existingBarcodes = array_fill_keys($knownProducts->pluck('barcode')->filter()->map(fn ($barcode) => Str::lower($barcode))->all(), true);
        $usedCodes = array_fill_keys($knownProducts->pluck('code')->map(fn ($code) => Str::lower($code))->all(), true);
        $productIdsByName = $knownProducts->mapWithKeys(fn (Product $product) => [Str::lower($product->name) => $product->id]);
        $productIdsByBarcode = $knownProducts->filter->barcode->mapWithKeys(fn (Product $product) => [Str::lower($product->barcode) => $product->id]);

        foreach (array_slice($rows, $headerRow + 1, null, true) as $index => $row) {
            $rowNumber = $index + 1;
            $name = Str::limit(trim((string) ($row[$columns['name']] ?? '')), 150, '');
            if ($name === '') {
                continue;
            }

            $barcode = $this->importText($this->importColumnValue($row, $columns, 'barcode'), 100);
            if (in_array(Str::lower($barcode ?? ''), ['', '0', '8', '-'], true)) {
                $barcode = null;
            }

            $groupName = $this->importText($this->importColumnValue($row, $columns, 'group_name'), 100);
            $supplierName = ($groupName && $groupName !== '-') ? $groupName : null;
            if (! $groupName || $groupName === '-') {
                $groupName = 'Uncategorized';
            }

            if (isset($existingNames[Str::lower($name)]) || ($barcode && isset($existingBarcodes[Str::lower($barcode)]))) {
                $existingProductId = $productIdsByName[Str::lower($name)]
                    ?? ($barcode ? $productIdsByBarcode[Str::lower($barcode)] ?? null : null);
                if ($existingProductId && $supplierName) {
                    $supplier = Supplier::firstOrCreate(
                        ['name' => $supplierName],
                        ['company_name' => $supplierName, 'supplier_type' => 'Spare Parts Supplier', 'active' => true]
                    );
                    Product::withTrashed()->whereKey($existingProductId)->whereNull('supplier_id')->update(['supplier_id' => $supplier->id]);
                }
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($row, $columns, $name, $barcode, $groupName, $supplierName, $rowNumber, $mainStore, $service, &$usedCodes) {
                    $costPrice = $this->importNumber($this->importColumnValue($row, $columns, 'cost_price'));
                    $sellingPrice = $this->importNumber($this->importColumnValue($row, $columns, 'selling_price'));
                    $openingQuantity = $this->importNumber($this->importColumnValue($row, $columns, 'opening_quantity'));
                    $providedOpeningAmount = $this->importNullableNumber($this->importColumnValue($row, $columns, 'opening_amount'));
                    $category = ProductCategory::firstOrCreate(['name' => $groupName]);
                    ProductBrand::firstOrCreate(['name' => $groupName]);
                    ProductUnit::firstOrCreate(['name' => 'Piece'], ['type' => 'Quantity']);
                    $supplier = $supplierName ? Supplier::firstOrCreate(
                        ['name' => $supplierName],
                        ['company_name' => $supplierName, 'supplier_type' => 'Spare Parts Supplier', 'active' => true]
                    ) : null;

                    $product = Product::create([
                        'code' => $this->uniqueImportCode($barcode ?: $name, $rowNumber, $usedCodes),
                        'barcode' => $barcode,
                        'name' => $name,
                        'category_id' => $category->id,
                        'supplier_id' => $supplier?->id,
                        'brand' => $groupName,
                        'unit' => 'Piece',
                        'cost_price' => $costPrice,
                        'selling_price' => $sellingPrice,
                        'opening_amount' => $providedOpeningAmount ?? ($openingQuantity * $costPrice),
                        'rack_number' => $this->importText($this->importColumnValue($row, $columns, 'rack_number'), 100),
                        'minimum_stock' => 0,
                        'active' => true,
                    ]);

                    StoreStock::firstOrCreate(
                        ['store_id' => $mainStore->id, 'product_id' => $product->id],
                        ['quantity' => 0]
                    );
                    if ($openingQuantity > 0) {
                        $service->adjustStock(
                            $mainStore->id,
                            $product->id,
                            $openingQuantity,
                            'opening',
                            $product,
                            'Opening stock imported from spreadsheet',
                            null,
                            $costPrice,
                            $sellingPrice,
                        );
                    }
                });
                $imported++;
                $existingNames[Str::lower($name)] = true;
                if ($barcode) {
                    $existingBarcodes[Str::lower($barcode)] = true;
                }
            } catch (Throwable $exception) {
                $failed++;
                if (count($messages) < 8) {
                    $messages[] = "Row {$rowNumber} ({$name}): {$exception->getMessage()}";
                }
            }
        }

        $summary = "{$imported} products imported to {$mainStore->name}. {$skipped} existing/duplicate rows skipped.";
        if ($failed > 0) {
            $summary .= " {$failed} rows could not be imported.";
        }

        return redirect()->route('products.import')
            ->with('success', $summary)
            ->with('import_errors', $messages);
    }

    private function locateImportHeader(array $rows): array
    {
        $aliases = [
            'name' => ['name', 'product name'],
            'barcode' => ['barcode'],
            'group_name' => ['group name', 'category'],
            'opening_amount' => ['op amount', 'opening amount'],
            'opening_quantity' => ['op stock main', 'opening stock main', 'opening qty', 'opening quantity'],
            'cost_price' => ['purc price', 'purchase price', 'cost price'],
            'selling_price' => ['sale price', 'selling price', 'sell price'],
            'rack_number' => ['rack no', 'rack number'],
        ];

        foreach (array_slice($rows, 0, 12, true) as $rowIndex => $row) {
            $normalized = array_map(fn ($value) => $this->normalizeImportHeader((string) $value), $row);
            $columns = [];
            foreach ($aliases as $field => $names) {
                foreach ($names as $alias) {
                    $column = array_search($alias, $normalized, true);
                    if ($column !== false) {
                        $columns[$field] = $column;
                        break;
                    }
                }
            }
            if (isset($columns['name'], $columns['cost_price'], $columns['selling_price'])) {
                return [(int) $rowIndex, $columns];
            }
        }

        throw ValidationException::withMessages([
            'file' => 'No supported product header row was found. Expected columns include Name, Purc. Price and Sale Price.',
        ]);
    }

    private function normalizeImportHeader(string $header): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower($header)));
    }

    private function importText(mixed $value, int $limit): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : Str::limit($text, $limit, '');
    }

    private function importColumnValue(array $row, array $columns, string $field): mixed
    {
        return isset($columns[$field]) ? ($row[$columns[$field]] ?? null) : null;
    }

    private function importNumber(mixed $value): float
    {
        return $this->importNullableNumber($value) ?? 0;
    }

    private function importNullableNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $number = preg_replace('/[^0-9.\-]/', '', (string) $value);

        return is_numeric($number) ? max(0, (float) $number) : 0;
    }

    private function uniqueImportCode(string $source, int $rowNumber, array &$usedCodes): string
    {
        $base = Str::upper(Str::slug($source));
        $base = Str::limit($base ?: 'IMPORTED-PRODUCT', 42, '');
        $candidate = $base;
        $suffix = 1;
        while (isset($usedCodes[Str::lower($candidate)])) {
            $candidate = Str::limit($base, 42, '').'-'.$rowNumber.'-'.$suffix++;
        }
        $usedCodes[Str::lower($candidate)] = true;

        return Str::limit($candidate, 50, '');
    }

    public function references()
    {
        return view('products.references', [
            'categories' => ProductCategory::orderBy('name')->get(),
            'brands' => ProductBrand::orderBy('name')->get(),
            'units' => ProductUnit::orderBy('name')->get(),
        ]);
    }

    public function referenceStore(Request $request, string $type)
    {
        $map = ['categories' => ProductCategory::class, 'brands' => ProductBrand::class, 'units' => ProductUnit::class];
        abort_unless(isset($map[$type]), 404);
        $data = $request->validate(['name' => ['required', 'max:100', Rule::unique((new $map[$type])->getTable())], 'description' => ['nullable', 'max:500'], 'type' => ['nullable', 'max:50']]);
        if ($type !== 'categories' && $type !== 'brands') {
            unset($data['description']);
        }
        if ($type !== 'units') {
            unset($data['type']);
        }
        $record = $map[$type]::create($data);

        if ($request->expectsJson()) {
            return response()->json(['id' => $record->id, 'name' => $record->name, 'type' => $type], 201);
        }

        return back()->with('success', Str::singular(ucfirst($type)).' saved.');
    }

    public function suppliers(Request $request)
    {
        $suppliers = Supplier::query()->when($request->q, fn ($q, $term) => $q->where(fn ($x) => $x->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))->latest()->paginate(15)->withQueryString();

        return view('products.suppliers', compact('suppliers'));
    }

    public function stores()
    {
        return view('products.stores', ['stores' => Store::withCount('stocks')->withSum('stocks', 'quantity')->orderByDesc('is_default')->orderBy('name')->get()]);
    }

    public function storeStore(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'max:120', 'unique:stores'], 'is_default' => ['nullable', 'boolean'], 'active' => ['nullable', 'boolean']]);
        $data['is_default'] = $request->boolean('is_default');
        $data['active'] = $request->boolean('active', true);
        DB::transaction(function () use ($data) {
            if ($data['is_default']) {
                Store::query()->update(['is_default' => false]);
            }
            Store::create($data);
        });

        return back()->with('success', 'Store added.');
    }

    public function supplierStore(Request $request)
    {
        $supplier = Supplier::create($request->validate(['name' => ['required', 'max:150'], 'phone' => ['nullable', 'max:30'], 'address' => ['nullable', 'max:500']]));

        if ($request->expectsJson()) {
            return response()->json(['id' => $supplier->id, 'name' => $supplier->name, 'type' => 'suppliers'], 201);
        }

        return back()->with('success', 'Supplier added.');
    }

    public function storeStock(Request $request)
    {
        $stores = Store::where('active', true)->get();
        $selected = $stores->firstWhere('id', (int) $request->store) ?? $stores->first();
        $stocks = StoreStock::with(['product.category', 'store'])->when($selected, fn ($q) => $q->where('store_id', $selected->id))
            ->when($request->q, fn ($q, $term) => $q->whereHas('product', fn ($x) => $x->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
            ->paginate(12)->withQueryString();

        return view('products.store-stock', compact('stores', 'selected', 'stocks'));
    }

    public function transfers()
    {
        return view('products.transfers', ['stores' => Store::where('active', true)->get(), 'products' => Product::orderBy('name')->get(), 'transfers' => StockTransfer::with(['fromStore', 'toStore', 'product'])->latest()->paginate(10)]);
    }

    public function transferStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate([
            'from_store_id' => ['required', 'exists:stores,id', 'different:to_store_id'],
            'to_store_id' => ['required', 'exists:stores,id'],
            'transfer_date' => ['required', 'date'], 'note' => ['nullable', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        DB::transaction(function () use ($data, $service) {
            $batchNumber = 'TRF-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            foreach ($data['items'] as $index => $item) {
                $transfer = StockTransfer::create([
                    'number' => $batchNumber.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'from_store_id' => $data['from_store_id'], 'to_store_id' => $data['to_store_id'],
                    'product_id' => $item['product_id'], 'quantity' => $item['quantity'],
                    'transfer_date' => $data['transfer_date'], 'note' => $data['note'] ?? null,
                    'status' => 'Completed', 'created_by' => auth()->id(),
                ]);
                $service->adjustStock((int) $data['from_store_id'], (int) $item['product_id'], -(float) $item['quantity'], 'transfer_out', $transfer, $data['note'] ?? null);
                $service->adjustStock((int) $data['to_store_id'], (int) $item['product_id'], (float) $item['quantity'], 'transfer_in', $transfer, $data['note'] ?? null);
            }
        });

        return back()->with('success', count($data['items']).' product line(s) transferred successfully.');
    }

    public function adjustments()
    {
        return view('products.adjustments', ['stores' => Store::where('active', true)->get(), 'products' => Product::orderBy('name')->get(), 'movements' => StockMovement::with(['store', 'product'])->whereIn('type', ['adjustment_in', 'adjustment_out'])->latest()->paginate(10)]);
    }

    public function adjustmentStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate([
            'store_id' => ['required', 'exists:stores,id'],
            'direction' => ['required', \Illuminate\Validation\Rule::in(['increase', 'decrease', 'override'])],
            'note' => ['nullable', 'max:500'],
            'products' => ['required', 'array', 'min:1'],
            'products.*.id' => ['required', 'exists:products,id'],
            'products.*.quantity' => ['required', 'numeric', 'min:0'],
        ]);
        
        $direction = $data['direction'] ?? 'increase';
        $storeId = (int) $data['store_id'];

        \Illuminate\Support\Facades\DB::transaction(function () use ($data, $direction, $storeId, $service) {
            foreach ($data['products'] as $item) {
                $quantity = (float) $item['quantity'];
                $productId = (int) $item['id'];

                if ($direction === 'override') {
                    $currentStock = \App\Models\StoreStock::where('store_id', $storeId)->where('product_id', $productId)->value('quantity') ?? 0;
                    $diff = $quantity - $currentStock;
                    
                    if ($diff != 0) {
                        $increase = $diff > 0;
                        $service->adjustStock($storeId, $productId, abs($diff) * ($increase ? 1 : -1), $increase ? 'adjustment_in' : 'adjustment_out', null, $data['note']);
                    }
                } else {
                    if ($quantity <= 0) {
                        continue; // Skip zero adjustments
                    }
                    $increase = $direction === 'increase';
                    $service->adjustStock($storeId, $productId, $quantity * ($increase ? 1 : -1), $increase ? 'adjustment_in' : 'adjustment_out', null, $data['note']);
                }
            }
        });

        return back()->with('success', 'Stock adjustment saved.');
    }

    public function stock(Request $request, Product $product, WorkshopService $service)
    {
        $data = $request->validate(['store_id' => ['required', 'exists:stores,id'], 'quantity' => ['required', 'numeric', 'not_in:0'], 'note' => ['required', 'max:500']]);
        $service->adjustStock((int) $data['store_id'], $product->id, (float) $data['quantity'], 'adjustment', null, $data['note']);

        return back()->with('success', 'Stock adjustment saved.');
    }

    public function lowStock(Request $request)
    {
        $stocks = StoreStock::with(['product.category', 'product.supplier', 'store'])->whereHas('product', fn ($q) => $q->whereColumn('store_stocks.quantity', '<=', 'products.minimum_stock'))
            ->when($request->q, fn ($q, $term) => $q->whereHas('product', fn ($x) => $x->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
            ->when($request->supplier, fn ($q, $supplier_id) => $q->whereHas('product', fn ($x) => $x->where('supplier_id', $supplier_id)))
            ->orderBy('quantity')->paginate(15)->withQueryString();

        $suppliers = Supplier::orderBy('name')->get();

        return view('products.low-stock', compact('stocks', 'suppliers'));
    }

    public function damages()
    {
        return view('products.damages', ['stores' => Store::where('active', true)->get(), 'products' => Product::orderBy('name')->get(), 'reports' => StockDamageReport::with(['store', 'product'])->latest()->paginate(10)]);
    }

    public function damageStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate([
            'store_id' => ['required', 'exists:stores,id'], 'type' => ['required', Rule::in(['Damage', 'Write-off'])],
            'reported_at' => ['required', 'date'], 'reason' => ['required', 'max:1000'], 'photo' => ['nullable', 'image', 'max:5120'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);
        $photoPath = null;
        if ($request->hasFile('photo')) {
            $photoPath = $request->file('photo')->store('stock-damage', 'public');
        }
        DB::transaction(function () use ($data, $photoPath, $service) {
            $batchNumber = 'DMG-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->get()->keyBy('id');
            foreach ($data['items'] as $index => $item) {
                $product = $products->get($item['product_id']);
                $report = StockDamageReport::create([
                    'number' => $batchNumber.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT),
                    'store_id' => $data['store_id'], 'product_id' => $item['product_id'], 'type' => $data['type'],
                    'quantity' => $item['quantity'], 'loss_value' => (float) $item['quantity'] * (float) $product->cost_price,
                    'reported_at' => $data['reported_at'], 'reason' => $data['reason'], 'photo_path' => $photoPath,
                    'status' => 'Completed', 'created_by' => auth()->id(),
                ]);
                $service->adjustStock((int) $data['store_id'], (int) $item['product_id'], -(float) $item['quantity'], 'damage', $report, $data['reason']);
            }
        });

        return back()->with('success', count($data['items']).' damage/write-off line(s) recorded and stock updated.');
    }

    private function formData(): array
    {
        return [
            'categories' => ProductCategory::orderBy('name')->get(), 'brands' => ProductBrand::orderBy('name')->get(),
            'units' => ProductUnit::orderBy('name')->get(), 'suppliers' => Supplier::orderBy('name')->get(),
            'mainStore' => Store::where('is_default', true)->first() ?? Store::where('name', 'Main Store')->first(),
        ];
    }

    private function stockStats(): array
    {
        $stockValue = StoreStock::join('products', 'products.id', '=', 'store_stocks.product_id')->sum(DB::raw('store_stocks.quantity * products.cost_price'));

        return ['products' => Product::count(), 'in_stock' => Product::whereHas('stocks', fn ($q) => $q->where('quantity', '>', 0))->count(), 'low' => Product::whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock')->count(), 'value' => $stockValue];
    }

    public function show(Product $product)
    {
        $product->load(['category', 'supplier', 'stocks.store', 'stockBatches.store', 'stockBatches.supplier', 'stockBatches' => fn ($q) => $q->where('status', 'active')]);

        return view('products.show', compact('product'));
    }
    public function export()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ProductExport, 'products.xlsx');
    }

    public function exportPdf(\Illuminate\Http\Request $request)
    {
        ini_set('memory_limit', '512M');
        ini_set('max_execution_time', '300');

        $query = Product::query()
            ->with(['category', 'supplier'])
            ->withSum('stocks', 'quantity')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($x) => $x
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('code', 'like', '%'.$request->q.'%')
                ->orWhere('barcode', 'like', '%'.$request->q.'%')
                ->orWhere('rack_number', 'like', '%'.$request->q.'%')))
            ->when($request->filled('rack_number'), fn ($q) => $q->where('rack_number', 'like', '%'.$request->rack_number.'%'))
            ->when($request->filled('supplier'), fn ($q) => $q->where('supplier_id', $request->supplier))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->brand))
            ->when($request->status === 'low', fn ($q) => $q->whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock'));

        if ($request->sort === 'qty_asc') {
            $products = $query->get()->sortBy('stocks_sum_quantity');
        } elseif ($request->sort === 'qty_desc') {
            $products = $query->get()->sortByDesc('stocks_sum_quantity');
        } elseif ($request->sort === 'rack_number') {
            $products = $query->orderBy('rack_number', 'asc')->get();
        } else {
            $products = $query->orderBy('name', 'asc')->get();
        }

        $columns = $request->input('columns', []);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('products.pdf', compact('products', 'columns'))->setPaper('a4', 'landscape');
        return $pdf->stream('products-list.pdf');
    }

    public function import(\Illuminate\Http\Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,csv,xls',
        ]);

        \Maatwebsite\Excel\Facades\Excel::import(new \App\Imports\ProductImport, $request->file('file'));

        return back()->with('success', 'Products imported successfully!');
    }
}
