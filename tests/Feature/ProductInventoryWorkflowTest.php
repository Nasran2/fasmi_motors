<?php

use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\ProductUnit;
use App\Models\StockDamageReport;
use App\Models\StockTransfer;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Supplier;
use App\Models\User;

test('product inventory screens render live product and supplier data', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Test Main Store', 'is_default' => true, 'active' => true]);
    $otherStore = Store::create(['name' => 'Wrong Opening Store', 'is_default' => false, 'active' => true]);
    $category = ProductCategory::create(['name' => 'Test Brakes']);
    ProductBrand::create(['name' => 'Test Brand']);
    ProductUnit::create(['name' => 'Piece', 'type' => 'Quantity']);
    $supplier = Supplier::create(['name' => 'Test Parts Supplier', 'phone' => '0771234567']);

    $this->actingAs($user)->post(route('products.store'), [
        'name' => 'Front Test Brake Pad', 'code' => 'TEST-BP-001', 'category_id' => $category->id,
        'brand' => 'Test Brand', 'unit' => 'Piece', 'supplier_id' => $supplier->id,
        'cost_price' => 120, 'selling_price' => 250, 'minimum_stock' => 5, 'reorder_level' => 10,
        'opening_quantity' => 20, 'store_id' => $otherStore->id,
    ])->assertRedirect(route('products.index'));

    expect(StoreStock::where('store_id', $store->id)->value('quantity'))->toEqual('20.00');
    expect(StoreStock::where('store_id', $otherStore->id)->exists())->toBeFalse();
    $this->actingAs($user)->get(route('products.index'))->assertOk()->assertSeeText('Front Test Brake Pad');
    $this->actingAs($user)->get(route('products.suppliers'))->assertOk()->assertSeeText('Test Parts Supplier');
    $this->actingAs($user)->get(route('products.store-stock', ['store' => $store->id]))->assertOk()->assertSeeText('Front Test Brake Pad');
});

test('product quick-add endpoints return new master records for modal selection', function () {
    $user = User::factory()->create();

    $category = $this->actingAs($user)->postJson(route('products.references.store', 'categories'), ['name' => 'Quick Category'])
        ->assertCreated()->assertJsonPath('name', 'Quick Category')->json();
    $brand = $this->actingAs($user)->postJson(route('products.references.store', 'brands'), ['name' => 'Quick Brand'])
        ->assertCreated()->assertJsonPath('name', 'Quick Brand')->json();
    $unit = $this->actingAs($user)->postJson(route('products.references.store', 'units'), ['name' => 'Quick Unit', 'type' => 'Quantity'])
        ->assertCreated()->assertJsonPath('name', 'Quick Unit')->json();
    $supplier = $this->actingAs($user)->postJson(route('products.suppliers.store'), ['name' => 'Quick Supplier', 'phone' => '0770000000'])
        ->assertCreated()->assertJsonPath('name', 'Quick Supplier')->json();

    expect($category['id'])->not->toBeNull()
        ->and($brand['id'])->not->toBeNull()
        ->and($unit['id'])->not->toBeNull()
        ->and($supplier['id'])->not->toBeNull();
});

test('stock transfer adjustment and damage workflows update real balances', function () {
    $user = User::factory()->create();
    $from = Store::create(['name' => 'Source Store', 'active' => true]);
    $to = Store::create(['name' => 'Destination Store', 'active' => true]);
    $product = Product::create(['code' => 'MOV-001', 'name' => 'Movement Product', 'cost_price' => 100, 'selling_price' => 150, 'minimum_stock' => 2, 'unit' => 'pcs']);
    $secondProduct = Product::create(['code' => 'MOV-002', 'name' => 'Second Movement Product', 'cost_price' => 80, 'selling_price' => 130, 'minimum_stock' => 2, 'unit' => 'pcs']);
    StoreStock::create(['store_id' => $from->id, 'product_id' => $product->id, 'quantity' => 20]);
    StoreStock::create(['store_id' => $from->id, 'product_id' => $secondProduct->id, 'quantity' => 12]);

    $this->actingAs($user)->post(route('products.transfers.store'), [
        'from_store_id' => $from->id, 'to_store_id' => $to->id, 'transfer_date' => now()->toDateString(),
        'items' => [
            ['product_id' => $product->id, 'quantity' => 5],
            ['product_id' => $secondProduct->id, 'quantity' => 4],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect(StockTransfer::count())->toBe(2)
        ->and((float) StoreStock::where(['store_id' => $from->id, 'product_id' => $product->id])->value('quantity'))->toBe(15.0)
        ->and((float) StoreStock::where(['store_id' => $to->id, 'product_id' => $product->id])->value('quantity'))->toBe(5.0)
        ->and((float) StoreStock::where(['store_id' => $from->id, 'product_id' => $secondProduct->id])->value('quantity'))->toBe(8.0)
        ->and((float) StoreStock::where(['store_id' => $to->id, 'product_id' => $secondProduct->id])->value('quantity'))->toBe(4.0);

    $this->actingAs($user)->post(route('products.adjustments.store'), ['store_id' => $from->id, 'product_id' => $product->id, 'direction' => 'increase', 'quantity' => 3, 'note' => 'Verified stock count'])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('products.damages.store'), [
        'store_id' => $from->id, 'type' => 'Damage', 'reported_at' => now()->toDateString(), 'reason' => 'Damaged during handling',
        'items' => [
            ['product_id' => $product->id, 'quantity' => 2],
            ['product_id' => $secondProduct->id, 'quantity' => 1],
        ],
    ])->assertSessionHasNoErrors();

    expect((float) StoreStock::where(['store_id' => $from->id, 'product_id' => $product->id])->value('quantity'))->toBe(16.0)
        ->and((float) StoreStock::where(['store_id' => $from->id, 'product_id' => $secondProduct->id])->value('quantity'))->toBe(7.0)
        ->and(StockDamageReport::count())->toBe(2)
        ->and((float) StockDamageReport::sum('loss_value'))->toBe(280.0);
});

test('job card spare part selector is searchable by product details', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Parts Customer', 'phone' => '0777777777']);
    $bike = $customer->bikes()->create(['number' => 'WP PARTS 1', 'model' => 'Parts Test Bike']);
    $intake = BikeIntake::create(['intake_number' => 'IN-PARTS-1', 'bike_id' => $bike->id, 'complaint' => 'Parts test', 'created_by' => $user->id]);
    $job = JobCard::create(['number' => 'JOB-PARTS-1', 'bike_intake_id' => $intake->id, 'status' => 'Received', 'priority' => 'Normal', 'created_by' => $user->id]);
    Store::create(['name' => 'Parts Store', 'active' => true]);
    Product::create(['code' => 'SEARCH-SKU-1', 'barcode' => '99887766', 'name' => 'Searchable Part', 'cost_price' => 10, 'selling_price' => 20, 'unit' => 'pcs', 'active' => true]);

    $this->actingAs($user)->get(route('jobs.show', $job))
        ->assertOk()->assertSee('Type product name, SKU or barcode…')->assertSee('Searchable Part — SEARCH-SKU-1 — 99887766');
});
