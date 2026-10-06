<?php

use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;
use App\Services\WorkshopService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->store = Store::create(['name' => 'Managed Stock Store', 'active' => true]);
    $this->product = Product::create(['code' => 'MANAGED-ADJ', 'name' => 'Managed Adjustment Part', 'cost_price' => 100, 'selling_price' => 150, 'unit' => 'Piece']);
    $this->stock = StoreStock::create(['store_id' => $this->store->id, 'product_id' => $this->product->id, 'quantity' => 10]);
    $this->createAdjustment = function ($direction = 'increase', $quantity = 5) {
        $this->post(route('products.adjustments.store'), ['store_id' => $this->store->id, 'direction' => $direction,
            'products' => [['id' => $this->product->id, 'quantity' => $quantity, 'cost_price' => 110, 'selling_price' => 160]], 'note' => 'Original adjustment',
        ])->assertSessionHasNoErrors();

        return StockMovement::whereNull('voided_at')->latest('id')->firstOrFail();
    };
    $this->editData = ['direction' => 'increase', 'quantity' => 8, 'cost_price' => 125, 'selling_price' => 190, 'note' => 'Corrected adjustment', 'reason' => 'Incorrect quantity entered'];
});

test('recent adjustments have working edit and delete controls', function () {
    $movement = ($this->createAdjustment)();
    $this->get(route('products.adjustments'))->assertOk()->assertSee('Edit adjustment for Managed Adjustment Part')
        ->assertSee('Delete adjustment for Managed Adjustment Part')->assertSeeText('Edit Stock Adjustment')
        ->assertSeeText('Delete Stock Adjustment')->assertSee('data-edit-adjustment=', false)->assertSee('data-delete-adjustment=', false);
});

test('editing an adjustment preserves later stock movements and deletion reverses the full edited effect', function () {
    $movement = ($this->createAdjustment)();
    app(WorkshopService::class)->adjustStock($this->store->id, $this->product->id, -4, 'sale', null, 'Later sale');
    $this->put(route('products.adjustments.update', $movement), $this->editData)->assertRedirect(route('products.adjustments'))->assertSessionHasNoErrors();
    $replacement = StockMovement::whereNull('voided_at')->where('type', 'adjustment_in')->latest('id')->firstOrFail();
    expect((float) $this->stock->fresh()->quantity)->toBe(14.0)->and($movement->fresh()->voided_at)->not->toBeNull()
        ->and((float) $movement->fresh()->quantity)->toBe(5.0)->and($replacement->adjustmentEffect())->toBe(8.0)
        ->and((float) $replacement->quantity)->toBe(3.0)->and($replacement->reference_id)->toBe($movement->id)
        ->and((float) $this->product->fresh()->cost_price)->toBe(125.0)->and((float) $this->product->fresh()->selling_price)->toBe(190.0);
    $data = $this->editData;
    $data['quantity'] = 6;
    $this->put(route('products.adjustments.update', $replacement), $data)->assertSessionHasNoErrors();
    $current = StockMovement::whereNull('voided_at')->where('type', 'adjustment_in')->latest('id')->firstOrFail();
    expect((float) $this->stock->fresh()->quantity)->toBe(12.0)->and($current->adjustmentEffect())->toBe(6.0);
    $this->delete(route('products.adjustments.destroy', $current), ['reason' => 'Adjustment entered for wrong product'])->assertSessionHasNoErrors();
    expect((float) $this->stock->fresh()->quantity)->toBe(6.0)->and($current->fresh()->voided_at)->not->toBeNull()
        ->and((float) StockMovement::sum('quantity'))->toBe(-4.0)
        ->and((float) $this->product->fresh()->cost_price)->toBe(125.0);
    $this->get(route('products.adjustments'))->assertOk()->assertDontSee('data-edit-adjustment=', false);
    $this->deleteJson(route('products.adjustments.destroy', $current), ['reason' => 'Retry'])->assertUnprocessable()->assertJsonValidationErrors('adjustment');
    expect((float) $this->stock->fresh()->quantity)->toBe(6.0);
});

test('exact quantity revisions use the original baseline and keep subsequent sales', function () {
    $movement = ($this->createAdjustment)('override', 20);
    app(WorkshopService::class)->adjustStock($this->store->id, $this->product->id, -3, 'sale');
    $data = array_replace($this->editData, ['direction' => 'override', 'quantity' => 15]);
    $this->put(route('products.adjustments.update', $movement), $data)->assertSessionHasNoErrors();
    $replacement = StockMovement::whereNull('voided_at')->where('type', 'adjustment_in')->latest('id')->firstOrFail();
    expect((float) $this->stock->fresh()->quantity)->toBe(12.0)->and((float) $replacement->adjustment_base_quantity)->toBe(10.0)
        ->and($replacement->adjustmentEffect())->toBe(5.0);
    $data['quantity'] = 18;
    $this->put(route('products.adjustments.update', $replacement), $data)->assertSessionHasNoErrors();
    $current = StockMovement::whereNull('voided_at')->where('type', 'adjustment_in')->latest('id')->firstOrFail();
    expect((float) $this->stock->fresh()->quantity)->toBe(15.0)->and($current->adjustmentEffect())->toBe(8.0);
    $this->delete(route('products.adjustments.destroy', $current), ['reason' => 'Undo count'])->assertSessionHasNoErrors();
    expect((float) $this->stock->fresh()->quantity)->toBe(7.0);
});

test('editing and deleting legacy adjustments works without revision metadata', function () {
    $legacy = StockMovement::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'type' => 'adjustment_out', 'quantity' => -2,
        'balance_after' => 10, 'cost_price' => 100, 'selling_price' => 150, 'created_by' => auth()->id()]);
    $this->put(route('products.adjustments.update', $legacy), array_replace($this->editData, ['direction' => 'decrease', 'quantity' => 3]))->assertSessionHasNoErrors();
    $current = StockMovement::whereNull('voided_at')->where('type', 'adjustment_out')->latest('id')->firstOrFail();
    expect((float) $this->stock->fresh()->quantity)->toBe(9.0)->and($current->adjustmentEffect())->toBe(-3.0);
    $this->delete(route('products.adjustments.destroy', $current), ['reason' => 'Undo legacy adjustment'])->assertSessionHasNoErrors();
    expect((float) $this->stock->fresh()->quantity)->toBe(12.0);
});

test('insufficient stock blocks editing or deleting without changing prices or audit records', function () {
    $movement = ($this->createAdjustment)();
    app(WorkshopService::class)->adjustStock($this->store->id, $this->product->id, -13, 'sale');
    $this->deleteJson(route('products.adjustments.destroy', $movement), ['reason' => 'Undo'])->assertUnprocessable()->assertJsonValidationErrors('quantity');
    $this->putJson(route('products.adjustments.update', $movement), array_replace($this->editData, ['quantity' => 0]))->assertUnprocessable()->assertJsonValidationErrors('quantity');
    expect((float) $this->stock->fresh()->quantity)->toBe(2.0)->and($movement->fresh()->voided_at)->toBeNull()
        ->and((float) $this->product->fresh()->cost_price)->toBe(110.0)->and(StockMovement::count())->toBe(2);
});

test('no-op adjustments are skipped and price-only adjustments can be managed', function () {
    $this->post(route('products.adjustments.store'), ['store_id' => $this->store->id, 'direction' => 'increase',
        'products' => [['id' => $this->product->id, 'quantity' => 0, 'cost_price' => 100, 'selling_price' => 150]],
    ])->assertSessionHasNoErrors();
    expect(StockMovement::count())->toBe(0);
    $movement = ($this->createAdjustment)('increase', 0);
    expect($movement->type)->toBe('adjustment_price');
    $this->put(route('products.adjustments.update', $movement), array_replace($this->editData, ['quantity' => 0]))->assertSessionHasNoErrors();
    $current = StockMovement::whereNull('voided_at')->latest('id')->firstOrFail();
    $this->delete(route('products.adjustments.destroy', $current), ['reason' => 'Remove record'])->assertSessionHasNoErrors();
    expect((float) $this->stock->fresh()->quantity)->toBe(10.0)->and((float) StockMovement::sum('quantity'))->toBe(0.0)
        ->and((float) $this->product->fresh()->selling_price)->toBe(190.0);
});

test('invalid edits unrelated movements and other shops cannot be modified', function () {
    $movement = ($this->createAdjustment)();
    foreach (['quantity' => -1, 'cost_price' => -1, 'selling_price' => 'bad', 'direction' => 'invalid', 'reason' => ''] as $field => $value) {
        $this->putJson(route('products.adjustments.update', $movement), array_replace($this->editData, [$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->deleteJson(route('products.adjustments.destroy', $movement), [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $sale = StockMovement::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'type' => 'sale', 'quantity' => -1, 'balance_after' => 15, 'created_by' => auth()->id()]);
    $this->putJson(route('products.adjustments.update', $sale), $this->editData)->assertNotFound();
    $this->deleteJson(route('products.adjustments.destroy', $sale), ['reason' => 'Invalid'])->assertNotFound();
    $helmet = Product::create(['code' => 'MANAGED-HELMET', 'name' => 'Helmet Item', 'shop_type' => 'helmet']);
    $helmetStore = Store::create(['name' => 'Helmet Store', 'active' => true, 'shop_type' => 'helmet']);
    $other = StockMovement::create(['product_id' => $helmet->id, 'store_id' => $helmetStore->id, 'type' => 'adjustment_in', 'quantity' => 5, 'balance_after' => 5, 'created_by' => auth()->id()]);
    $this->putJson(route('products.adjustments.update', $other), $this->editData)->assertNotFound();
    $this->deleteJson(route('products.adjustments.destroy', $other), ['reason' => 'Invalid'])->assertNotFound();
    $this->get(route('products.adjustments'))->assertOk()->assertDontSeeText('Helmet Item');
    expect((float) $this->stock->fresh()->quantity)->toBe(15.0)->and($movement->fresh()->voided_at)->toBeNull();
});

test('stock adjustment revisions are logged and actions require adjustment permission', function () {
    $movement = ($this->createAdjustment)();
    $this->put(route('products.adjustments.update', $movement), $this->editData)->assertSessionHasNoErrors();
    $audit = AuditLog::where('auditable_type', StockMovement::class)->where('auditable_id', $movement->id)->where('event', 'updated')->latest('id')->firstOrFail();
    expect($audit->new_values['void_reason'])->toBe('Edited: Incorrect quantity entered');
    $current = StockMovement::whereNull('voided_at')->latest('id')->firstOrFail();
    $role = Role::create(['name' => 'Product Viewer', 'permissions' => ['product.view']]);
    $viewer = User::factory()->create(['role_id' => $role->id]);
    $this->actingAs($viewer)->putJson(route('products.adjustments.update', $current), $this->editData)->assertForbidden();
    $this->deleteJson(route('products.adjustments.destroy', $current), ['reason' => 'Not permitted'])->assertForbidden();
    $this->get(route('products.adjustments'))->assertOk()->assertDontSee('data-edit-adjustment=', false);
    expect((float) $this->stock->fresh()->quantity)->toBe(18.0)->and($current->fresh()->voided_at)->toBeNull();
});

test('negative stock setting is respected when undoing an adjustment', function () {
    $movement = ($this->createAdjustment)();
    app(WorkshopService::class)->adjustStock($this->store->id, $this->product->id, -13, 'sale');
    Setting::updateOrCreate(['key' => 'negative_stock'], ['value' => 'true', 'group' => 'stock']);
    $this->delete(route('products.adjustments.destroy', $movement), ['reason' => 'Verified correction'])->assertSessionHasNoErrors();
    expect((float) $this->stock->fresh()->quantity)->toBe(-3.0)->and($movement->fresh()->voided_at)->not->toBeNull();
});
