<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Store;
use App\Services\WorkshopService;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $r)
    {
        $q = Product::with('stocks.store')->latest();
        if ($r->q) {
            $q->where(fn ($x) => $x->where('name', 'like', '%'.$r->q.'%')->orWhere('code', 'like', '%'.$r->q.'%'));
        }

return view('products.index', ['products' => $q->paginate(20), 'stores' => Store::where('active', 1)->get()]);
    }

    public function store(Request $r)
    {
        $v = $r->validate(['code' => 'required|max:50|unique:products', 'name' => 'required|max:150', 'cost_price' => 'required|numeric|min:0', 'selling_price' => 'required|numeric|min:0', 'minimum_stock' => 'nullable|numeric|min:0', 'unit' => 'required|max:20']);
        Product::create($v + ['brand' => $r->brand, 'model_compatibility' => $r->model_compatibility, 'active' => true]);

        return back()->with('success', 'Product created.');
    }

    public function stock(Request $r, Product $product, WorkshopService $service)
    {
        $v = $r->validate(['store_id' => 'required|exists:stores,id', 'quantity' => 'required|numeric|not_in:0', 'note' => 'required|max:500']);
        $service->adjustStock($v['store_id'], $product->id, (float) $v['quantity'], $v['quantity'] > 0 ? 'adjustment_in' : 'adjustment_out', $product, $v['note']);

        return back()->with('success','Stock adjusted.');
    }
}
