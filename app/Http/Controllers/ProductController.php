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

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::query()
            ->with(['category', 'supplier'])
            ->withSum('stocks', 'quantity')
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($x) => $x
                ->where('name', 'like', '%'.$request->q.'%')
                ->orWhere('code', 'like', '%'.$request->q.'%')
                ->orWhere('barcode', 'like', '%'.$request->q.'%')))
            ->when($request->filled('category'), fn ($q) => $q->where('category_id', $request->category))
            ->when($request->filled('brand'), fn ($q) => $q->where('brand', $request->brand))
            ->when($request->status === 'low', fn ($q) => $q->whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id = products.id) <= products.minimum_stock'))
            ->latest()->paginate(10)->withQueryString();

        return view('products.index', [
            'products' => $products,
            'categories' => ProductCategory::orderBy('name')->get(),
            'brands' => ProductBrand::orderBy('name')->get(),
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
            'minimum_stock' => ['required', 'numeric', 'min:0'], 'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'opening_quantity' => ['required', 'numeric', 'min:0'],
            'model_compatibility' => ['nullable', 'max:255'], 'image' => ['nullable', 'image', 'max:2048'],
        ]);

        $opening = (float) $data['opening_quantity'];
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
        $data = $request->validate(['store_id' => ['required', 'exists:stores,id'], 'product_id' => ['required', 'exists:products,id'], 'quantity' => ['required', 'numeric', 'gt:0'], 'direction' => ['required', Rule::in(['increase', 'decrease'])], 'note' => ['required', 'max:500']]);
        $increase = ($data['direction'] ?? 'increase') === 'increase';
        $service->adjustStock((int) $data['store_id'], (int) $data['product_id'], (float) $data['quantity'] * ($increase ? 1 : -1), $increase ? 'adjustment_in' : 'adjustment_out', null, $data['note']);

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
        $stocks = StoreStock::with(['product.category', 'store'])->whereHas('product', fn ($q) => $q->whereColumn('store_stocks.quantity', '<=', 'products.minimum_stock'))
            ->when($request->q, fn ($q, $term) => $q->whereHas('product', fn ($x) => $x->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")))
            ->orderBy('quantity')->paginate(15)->withQueryString();

        return view('products.low-stock', compact('stocks'));
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
}
