<?php

use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->store = Store::create(['name' => 'Adjustment Store', 'active' => true]);
    $this->product = Product::create(['code' => 'ADJ-PRICE', 'name' => 'Adjustment Part', 'cost_price' => 100, 'selling_price' => 150, 'unit' => 'Piece']);
    $this->stock = StoreStock::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'quantity' => 10]);
});

test('stock adjustment page provides prices and store-specific available quantities without lazy loading', function () {
    $other = Store::create(['name' => 'Second Adjustment Store', 'active' => true]);
    StoreStock::create(['product_id' => $this->product->id, 'store_id' => $other->id, 'quantity' => 3.5]);
    $this->get(route('products.adjustments'))->assertOk()->assertSeeText('Cost Price (Rs.)')->assertSeeText('Sale Price (Rs.)')
        ->assertSeeText('Product / Available Now')->assertViewHas('products', function ($products) use ($other) {
            $product = $products->firstWhere('id', $this->product->id);
            expect($product->relationLoaded('stocks'))->toBeTrue();
            expect((float) $product->stocks->firstWhere('store_id', $this->store->id)->quantity)->toBe(10.0);
            expect((float) $product->stocks->firstWhere('store_id', $other->id)->quantity)->toBe(3.5);

            return true;
        });
});

test('adjustment saves product prices and snapshots them on the stock movement', function ($direction, $quantity, $balance, $movementQuantity) {
    $this->post(route('products.adjustments.store'), [
        'store_id' => $this->store->id, 'direction' => $direction,
        'products' => [['id' => $this->product->id, 'quantity' => $quantity, 'cost_price' => 125.5, 'selling_price' => 210.75]],
    ])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $this->product->fresh()->cost_price)->toBe(125.5)
        ->and((float) $this->product->fresh()->selling_price)->toBe(210.75)
        ->and((float) $this->stock->fresh()->quantity)->toBe($balance);
    $movement = StockMovement::sole();
    expect((float) $movement->cost_price)->toBe(125.5)->and((float) $movement->selling_price)->toBe(210.75)
        ->and((float) $movement->quantity)->toBe($movementQuantity)->and((float) $movement->balance_after)->toBe($balance);
})->with([
    'increase' => ['increase', 2, 12.0, 2.0],
    'decrease' => ['decrease', 2, 8.0, -2.0],
    'exact increase' => ['override', 15, 15.0, 5.0],
    'exact decrease to zero' => ['override', 0, 0.0, -10.0],
]);

test('price-only changes are recorded without changing stock and zero is a valid price', function ($direction, $quantity) {
    $this->post(route('products.adjustments.store'), ['store_id' => $this->store->id, 'direction' => $direction,
        'products' => [['id' => $this->product->id, 'quantity' => $quantity, 'cost_price' => 0, 'selling_price' => 0]],
    ])->assertSessionHasNoErrors();
    expect((float) $this->product->fresh()->cost_price)->toBe(0.0)->and((float) $this->product->fresh()->selling_price)->toBe(0.0)
        ->and((float) $this->stock->fresh()->quantity)->toBe(10.0)->and(StockMovement::sole()->quantity)->toEqual(0)
        ->and(StockMovement::sole()->type)->toBe('adjustment_price');
})->with([['increase', 0], ['decrease', 0], ['override', 10]]);

test('omitting optional prices preserves existing product prices', function () {
    $this->post(route('products.adjustments.store'), ['store_id' => $this->store->id, 'direction' => 'increase',
        'products' => [['id' => $this->product->id, 'quantity' => 1]],
    ])->assertSessionHasNoErrors();
    expect((float) $this->product->fresh()->cost_price)->toBe(100.0)->and((float) $this->product->fresh()->selling_price)->toBe(150.0)
        ->and((float) $this->stock->fresh()->quantity)->toBe(11.0);
});

test('failed stock adjustment rolls back prices and earlier product changes', function () {
    $second = Product::create(['code' => 'ADJ-SECOND', 'name' => 'Second Part', 'cost_price' => 10, 'selling_price' => 20]);
    StoreStock::create(['product_id' => $second->id, 'store_id' => $this->store->id, 'quantity' => 0]);
    $this->postJson(route('products.adjustments.store'), ['store_id' => $this->store->id, 'direction' => 'decrease',
        'products' => [
            ['id' => $this->product->id, 'quantity' => 1, 'cost_price' => 125, 'selling_price' => 200],
            ['id' => $second->id, 'quantity' => 1, 'cost_price' => 15, 'selling_price' => 25],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    expect((float) $this->product->fresh()->cost_price)->toBe(100.0)->and((float) $this->product->fresh()->selling_price)->toBe(150.0)
        ->and((float) $this->stock->fresh()->quantity)->toBe(10.0)->and((float) $second->fresh()->cost_price)->toBe(10.0)
        ->and(StockMovement::count())->toBe(0);
});

test('invalid prices duplicate products and products or stores outside the current shop are rejected', function () {
    $helmet = Product::create(['code' => 'ADJ-HELMET', 'name' => 'Helmet Part', 'cost_price' => 100, 'selling_price' => 200, 'shop_type' => 'helmet']);
    $helmetStore = Store::create(['name' => 'Helmet Store', 'active' => true, 'shop_type' => 'helmet']);
    $inactive = Store::create(['name' => 'Inactive Store', 'active' => false]);
    $deleted = Product::create(['code' => 'ADJ-DELETED', 'name' => 'Deleted Part']);
    $deleted->delete();
    $base = ['store_id' => $this->store->id, 'direction' => 'increase', 'products' => [['id' => $this->product->id, 'quantity' => 1, 'cost_price' => 120, 'selling_price' => 180]]];
    foreach (['cost_price' => -1, 'selling_price' => 'invalid', 'quantity' => -1, 'id' => $helmet->id] as $field => $value) {
        $data = $base;
        $data['products'][0][$field] = $value;
        $this->postJson(route('products.adjustments.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('products.0.'.$field);
    }
    foreach ([$helmetStore->id, $inactive->id] as $id) {
        $this->postJson(route('products.adjustments.store'), array_replace($base, ['store_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('store_id');
    }
    $data = $base;
    $data['products'][0]['id'] = $deleted->id;
    $this->postJson(route('products.adjustments.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('products.0.id');
    $data = $base;
    $data['products'][] = $data['products'][0];
    $this->postJson(route('products.adjustments.store'), $data)->assertUnprocessable()->assertJsonValidationErrors('products.1.id');
    expect((float) $this->product->fresh()->cost_price)->toBe(100.0)->and((float) $this->stock->fresh()->quantity)->toBe(10.0)->and(StockMovement::count())->toBe(0);
});
