<?php

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Supplier;
use App\Models\User;

test('all purchase module pages render complete live interfaces', function () {
    $user = User::factory()->create();
    Account::create(['code' => 'PUR-TEST', 'name' => 'Purchase Test Account', 'type' => 'main', 'active' => true]);

    foreach (['purchases.index', 'purchases.create', 'purchases.returns', 'purchases.suppliers', 'purchases.suppliers.create', 'purchases.payments', 'purchases.dues'] as $route) {
        $this->actingAs($user)->get(route($route))->assertOk()->assertDontSeeText('Page available')->assertDontSeeText('No records to display yet');
    }
});

test('purchase payment and return workflows keep stock supplier due and ledger aligned', function () {
    $user = User::factory()->create();
    $supplier = Supplier::create(['name' => 'Workflow Parts Supplier', 'phone' => '0775550001', 'active' => true]);
    $store = Store::create(['name' => 'Purchase Workflow Store', 'active' => true]);
    $account = Account::create(['code' => 'PUR-CASH', 'name' => 'Purchase Cash', 'type' => 'main', 'opening_balance' => 5000, 'active' => true]);
    $product = Product::create(['code' => 'PUR-PART-001', 'name' => 'Purchase Workflow Part', 'cost_price' => 80, 'selling_price' => 150, 'minimum_stock' => 2, 'unit' => 'pcs', 'active' => true]);

    $this->actingAs($user)->post(route('purchases.store'), [
        'supplier_id' => $supplier->id, 'invoice_number' => 'SUP-INV-001', 'purchase_date' => today()->toDateString(),
        'due_date' => today()->addDays(30)->toDateString(), 'store_id' => $store->id, 'payment_method' => 'Cash',
        'account_id' => $account->id, 'paid_amount' => 200, 'discount' => 0, 'tax' => 0, 'other_cost' => 0,
        'items' => [['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 100, 'selling_price' => 180, 'discount' => 0]],
        'update_prices' => 1,
    ])->assertSessionHasNoErrors();

    $purchase = Purchase::firstOrFail();
    expect((float) StoreStock::where(['store_id' => $store->id, 'product_id' => $product->id])->value('quantity'))->toBe(10.0)
        ->and((float) $purchase->due_amount)->toBe(800.0)
        ->and((float) $account->transactions()->sum('debit'))->toBe(200.0)
        ->and((float) $product->fresh()->cost_price)->toBe(100.0);

    $this->actingAs($user)->post(route('purchases.payments.store'), [
        'supplier_id' => $supplier->id, 'purchase_id' => $purchase->id, 'account_id' => $account->id,
        'payment_date' => today()->toDateString(), 'amount' => 300, 'payment_method' => 'Cash', 'note' => 'Part payment',
    ])->assertSessionHasNoErrors();
    expect((float) $purchase->fresh()->due_amount)->toBe(500.0);

    $item = $purchase->items()->firstOrFail();
    $this->actingAs($user)->post(route('purchases.returns.store'), [
        'purchase_id' => $purchase->id, 'purchase_item_id' => $item->id, 'quantity' => 2,
        'refund_type' => 'Supplier credit', 'return_date' => today()->toDateString(), 'reason' => 'Incorrect items',
    ])->assertSessionHasNoErrors();
    $return = PurchaseReturn::firstOrFail();
    $this->actingAs($user)->post(route('purchases.returns.approve', $return))->assertSessionHasNoErrors();

    expect((float) StoreStock::where(['store_id' => $store->id, 'product_id' => $product->id])->value('quantity'))->toBe(8.0)
        ->and((float) $item->fresh()->returned_quantity)->toBe(2.0)
        ->and((float) $purchase->fresh()->due_amount)->toBe(300.0)
        ->and($return->fresh()->status)->toBe('Approved');
});
