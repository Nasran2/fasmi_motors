<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;

test('POS renders working action controls with visible shortcut labels', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->withOpenCashier()->get(route('sales.parts'))->assertOk()
        ->assertSeeText('Alt+Q')->assertSeeText('Alt+H')->assertSeeText('Alt+M')
        ->assertSeeText('Alt+C')->assertSeeText('Alt+K')->assertSeeText('Alt+B')
        ->assertSee('handleShortcut($event)', false)
        ->assertSee('id="pos-product-search"', false);

    $posText = strip_tags(str($response->getContent())->after('<main class="pos-main">')->before('</main>')->toString());
    expect($posText)->not->toContain('Coupon', 'Return');
});

test('quotation saves a printable document and notes without moving stock or creating a due', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Quote Store', 'active' => true]);
    $product = Product::create(['code' => 'QUOTE-001', 'name' => 'Quoted Part', 'cost_price' => 40, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 0]);

    $response = $this->actingAs($user)->withOpenCashier()->postJson(route('sales.parts.store'), [
        'customer_mode' => 'new', 'customer_name' => 'POS Quote Customer', 'customer_phone' => '0771234567',
        'store_id' => $store->id, 'invoice_type' => 'Quotation', 'paid_amount' => 0,
        'internal_note' => 'Call before ordering', 'customer_note' => 'Price valid for seven days',
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
    ])->assertOk()->assertJsonPath('success', true);

    $invoice = Invoice::findOrFail($response->json('invoice_id'));
    expect($invoice->number)->toStartWith('QTN-')
        ->and($invoice->status)->toBe('Draft')
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->payments()->count())->toBe(0)
        ->and((float) StoreStock::firstOrFail()->quantity)->toBe(0.0)
        ->and(Customer::where('name', 'POS Quote Customer')->exists())->toBeTrue();

    $this->get(route('invoices.public', $invoice->public_token))
        ->assertOk()->assertSeeText('Quotation')->assertSeeText('Quoted Part')
        ->assertSeeText('Price valid for seven days')->assertDontSeeText('Call before ordering');
    $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSeeText('Call before ordering');
});

test('paid POS sale keeps its bill printable and records the selected payment', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'POS Store', 'active' => true]);
    $account = Account::create(['code' => 'POSBANK', 'name' => 'POS Bank', 'type' => 'bank', 'active' => true]);
    $product = Product::create(['code' => 'POS-001', 'name' => 'POS Sale Part', 'cost_price' => 40, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 3]);

    $response = $this->actingAs($user)->withOpenCashier()->postJson(route('sales.parts.store'), [
        'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => 'Parts Sale',
        'paid_amount' => 100, 'payment_method' => 'Card', 'account_id' => $account->id,
        'customer_note' => 'Keep this receipt',
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
    ])->assertOk()->assertJsonPath('success', true);

    $invoice = Invoice::findOrFail($response->json('invoice_id'));
    expect($invoice->status)->toBe('Paid')
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->payments()->firstOrFail()->method)->toBe('Card')
        ->and((float) StoreStock::firstOrFail()->quantity)->toBe(2.0);
    $this->get(route('invoices.public', $invoice->public_token))
        ->assertOk()->assertSeeText('POS Sale Part')->assertSeeText('Keep this receipt');
});

test('edited POS item name quantity and price appear on the saved quotation and bill', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Edited Item Store', 'active' => true]);
    $account = Account::create(['code' => 'EDIT-BANK', 'name' => 'Edited Item Bank', 'type' => 'bank', 'active' => true]);
    $product = Product::create(['code' => 'EDIT-001', 'name' => 'Catalog Part', 'cost_price' => 40, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 10]);

    foreach (['Quotation', 'Parts Sale'] as $type) {
        $isQuote = $type === 'Quotation';
        $response = $this->actingAs($user)->withOpenCashier()->postJson(route('sales.parts.store'), [
            'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => $type,
            'paid_amount' => $isQuote ? 0 : 250,
            'payment_method' => $isQuote ? null : 'Card',
            'account_id' => $isQuote ? null : $account->id,
            'items' => [[
                'product_id' => $product->id, 'description' => 'Custom brake fitting',
                'quantity' => 2, 'unit_price' => 125,
            ]],
        ])->assertOk();

        $invoice = Invoice::findOrFail($response->json('invoice_id'));
        $item = $invoice->items()->firstOrFail();
        expect($item->description)->toBe('Custom brake fitting')
            ->and((float) $item->quantity)->toBe(2.0)
            ->and((float) $item->unit_price)->toBe(125.0)
            ->and((float) $invoice->total)->toBe(250.0);

        $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSeeText('Custom brake fitting');
        $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSeeText('Custom brake fitting');
    }

    expect($product->fresh()->name)->toBe('Catalog Part')
        ->and((float) StoreStock::firstOrFail()->quantity)->toBe(8.0);
});
