<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;

function posWorkflowQuotation(User $user, Store $store, Customer $customer, ?Product $product = null, int $sequence = 1): Invoice
{
    $quotation = Invoice::create([
        'number' => sprintf('QTN-202609-%04d', $sequence),
        'invoice_type' => 'Quotation',
        'status' => 'Draft',
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'subtotal' => $product ? 150 : 50,
        'discount' => 0,
        'tax' => 0,
        'total' => $product ? 150 : 50,
        'paid_amount' => 0,
        'due_amount' => 0,
        'public_token' => str_repeat((string) $sequence, 48),
        'created_by' => $user->id,
    ]);
    if ($product) {
        $quotation->items()->create([
            'product_id' => $product->id,
            'store_id' => $store->id,
            'quantity' => 1,
            'unit_price' => 100,
            'discount' => 0,
            'total' => 100,
        ]);
    }
    $quotation->charges()->create(['type' => 'labor', 'description' => 'Repair labour', 'amount' => 50, 'sort_order' => 1]);

    return $quotation;
}

test('draft quotation opens in POS and checks out with products and charges only once', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Quote Store', 'active' => true]);
    $customer = Customer::create(['name' => 'Quote Customer', 'phone' => '0776000100', 'active' => true]);
    $product = Product::create(['code' => 'Q-PART', 'name' => 'Quoted Part', 'cost_price' => 20, 'selling_price' => 100, 'unit' => 'pcs', 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $account = Account::create(['code' => 'QUOTE-ACC', 'name' => 'Quote Account', 'type' => 'main', 'active' => true]);
    $quotation = posWorkflowQuotation($user, $store, $customer, $product);

    $this->actingAs($user)->get(route('sales.quotations'))->assertOk()
        ->assertSee(route('sales.parts', ['quotation' => $quotation->id]))
        ->assertSee('Cancel quotation');
    $this->get(route('sales.parts', ['quotation' => $quotation->id]))->assertOk()
        ->assertSee('QTN-202609-0001')->assertSee('Repair labour');

    $payload = [
        'source_quotation_id' => $quotation->id,
        'customer_mode' => 'existing', 'customer_id' => $customer->id, 'store_id' => $store->id,
        'invoice_type' => 'Parts Sale', 'paid_amount' => 150, 'payment_method' => 'Cash', 'account_id' => $account->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]],
        'charges' => [['type' => 'labor', 'description' => 'Repair labour', 'amount' => 50]],
    ];
    $this->postJson(route('sales.parts.store'), $payload)->assertOk()->assertJsonPath('success', true);
    $sale = Invoice::where('source_quotation_id', $quotation->id)->firstOrFail();
    expect($quotation->fresh()->status)->toBe('Converted')
        ->and((float) $sale->total)->toBe(150.0)
        ->and($sale->charges()->count())->toBe(1)
        ->and((float) StoreStock::where(['store_id' => $store->id, 'product_id' => $product->id])->value('quantity'))->toBe(4.0);
    $this->get(route('sales.parts', ['quotation' => $quotation->id]))->assertRedirect(route('sales.quotations'));
    $this->postJson(route('sales.parts.store'), $payload)->assertUnprocessable()->assertJsonValidationErrors('source_quotation_id');
    $this->delete(route('sales.invoices.cancel', $quotation), ['reason' => 'Changed my mind'])
        ->assertSessionHas('error');
    expect($quotation->fresh()->status)->toBe('Converted');
    $this->get(route('invoices.public', $sale->public_token))->assertOk()->assertSeeText('Repair labour');
});

test('charge-only quotation can be sold and a cancelled quotation cannot be opened in POS', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Charge Store', 'active' => true]);
    $customer = Customer::create(['name' => 'Charge Customer', 'phone' => '0776000101', 'active' => true]);
    $account = Account::create(['code' => 'CHARGE-ACC', 'name' => 'Charge Account', 'type' => 'main', 'active' => true]);
    $quotation = posWorkflowQuotation($user, $store, $customer);

    $this->actingAs($user)->postJson(route('sales.parts.store'), [
        'source_quotation_id' => $quotation->id, 'customer_mode' => 'existing', 'customer_id' => $customer->id,
        'store_id' => $store->id, 'invoice_type' => 'Parts Sale', 'paid_amount' => 50,
        'payment_method' => 'Cash', 'account_id' => $account->id, 'items' => [],
        'charges' => [['type' => 'labor', 'description' => 'Repair labour', 'amount' => 50]],
    ])->assertOk();
    expect(Invoice::where('source_quotation_id', $quotation->id)->firstOrFail()->total)->toEqual(50);

    $draft = posWorkflowQuotation($user, $store, $customer, sequence: 2);
    $this->delete(route('sales.invoices.cancel', $draft), ['reason' => 'Customer declined'])->assertSessionHasNoErrors();
    expect($draft->fresh()->status)->toBe('Cancelled');
    $this->get(route('sales.parts', ['quotation' => $draft->id]))->assertRedirect(route('sales.quotations'));
});

test('accident bike quotation has no POS action and cannot be converted through the POS endpoints', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Accident Store', 'active' => true]);
    $customer = Customer::create(['name' => 'Accident Customer', 'phone' => '0776000102', 'active' => true]);
    $quotation = posWorkflowQuotation($user, $store, $customer);
    $quotation->update(['is_accident_quotation' => true]);

    $this->actingAs($user)->get(route('sales.quotations', ['type' => 'accident']))
        ->assertOk()
        ->assertSee($quotation->number)
        ->assertDontSee(route('sales.parts', ['quotation' => $quotation->id]));
    $this->get(route('sales.parts', ['quotation' => $quotation->id]))
        ->assertRedirect(route('sales.quotations'));
    $this->postJson(route('sales.parts.store'), [
        'source_quotation_id' => $quotation->id,
        'customer_mode' => 'existing', 'customer_id' => $customer->id,
        'store_id' => $store->id, 'invoice_type' => 'Parts Sale',
        'paid_amount' => 0, 'payment_method' => 'Cash',
        'items' => [],
        'charges' => [['type' => 'labor', 'description' => 'Repair labour', 'amount' => 50]],
    ])->assertUnprocessable()->assertJsonValidationErrors('source_quotation_id');
    expect($quotation->fresh()->status)->toBe('Draft');
});
