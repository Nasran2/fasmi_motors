<?php

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Role;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    $this->mainStore = Store::create(['name' => 'Main Store', 'is_default' => true, 'active' => true]);
    $this->posStore = Store::create(['name' => 'POS Counter', 'active' => true]);
    $this->category = ProductCategory::create(['name' => 'POS Parts']);
    $this->productData = [
        'name' => 'New counter part', 'code' => 'POS-NEW-001', 'barcode' => '900001',
        'category_id' => $this->category->id, 'brand' => 'Unbranded', 'unit' => 'Piece',
        'cost_price' => 100, 'selling_price' => 150, 'minimum_stock' => 0,
        'opening_quantity' => 4.5, 'store_id' => $this->posStore->id,
        '_submission_token' => (string) Str::uuid(),
    ];
});

test('POS product creation returns a usable product and records opening stock once in the chosen store', function () {
    $response = $this->postJson(route('sales.parts.products.store'), $this->productData)
        ->assertCreated()->assertJsonPath('success', true)
        ->assertJsonPath('product.name', 'New counter part')
        ->assertJsonPath('product.sku', 'POS-NEW-001')
        ->assertJsonPath('product.stocks.0.store_id', $this->posStore->id)
        ->assertJsonPath('product.stocks.0.quantity', 4.5)
        ->assertJsonPath('product.batches', []);
    $product = Product::findOrFail($response->json('product.id'));
    expect((float) $product->opening_amount)->toBe(450.0)
        ->and((float) StoreStock::where('product_id', $product->id)->sole()->quantity)->toBe(4.5)
        ->and(StoreStock::where('store_id', $this->mainStore->id)->exists())->toBeFalse()
        ->and(StockMovement::where('product_id', $product->id)->sole()->type)->toBe('opening');

    $this->postJson(route('sales.parts.products.store'), $this->productData)
        ->assertCreated()->assertJsonPath('product.id', $product->id);
    expect(Product::count())->toBe(1)->and(StockMovement::count())->toBe(1);
    $page = $this->get(route('sales.parts'))->assertOk();
    expect(collect($page->viewData('productOptions'))->firstWhere('id', $product->id)['stocks'][0]['quantity'])->toBe(4.5);
});

test('invalid POS products do not create inventory and zero opening stock is supported', function () {
    $this->postJson(route('sales.parts.products.store'), array_replace($this->productData, ['opening_quantity' => -1]))
        ->assertUnprocessable()->assertJsonValidationErrors('opening_quantity');
    $this->posStore->update(['active' => false]);
    $this->postJson(route('sales.parts.products.store'), $this->productData)
        ->assertUnprocessable()->assertJsonValidationErrors('store_id');
    expect(Product::count())->toBe(0)->and(StoreStock::count())->toBe(0)->and(StockMovement::count())->toBe(0);

    $this->posStore->update(['active' => true]);
    $this->postJson(route('sales.parts.products.store'), array_replace($this->productData, ['opening_quantity' => 0]))->assertCreated();
    expect((float) StoreStock::sole()->quantity)->toBe(0.0)->and(StockMovement::count())->toBe(0);
    $this->postJson(route('sales.parts.products.store'), array_replace($this->productData, ['_submission_token' => (string) Str::uuid()]))
        ->assertUnprocessable()->assertJsonValidationErrors(['code', 'barcode']);
    expect(Product::count())->toBe(1)->and(StockMovement::count())->toBe(0);
});

test('POS cashiers require product add permission to create products', function () {
    $role = Role::create(['name' => 'POS only', 'permissions' => ['sales.parts_sales']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]))->withOpenCashier();
    $this->get(route('sales.parts'))->assertOk()->assertDontSee('id="pos-new-product-modal"', false);
    $this->postJson(route('sales.parts.products.store'), $this->productData)->assertForbidden();
    expect(Product::count())->toBe(0);
});
