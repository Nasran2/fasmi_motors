<?php

use App\Models\Bike;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;

test('accident quotation combines products and flexible charges without moving stock or recording payment', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Amina Perera', 'phone' => '0771234567', 'active' => true]);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP-1234', 'model' => 'FZ-S', 'brand' => 'Yamaha', 'color' => 'Blue']);
    $store = Store::create(['name' => 'Quote Store', 'active' => true]);
    $product = Product::create(['code' => 'ACC-PART', 'name' => 'Headlamp', 'cost_price' => 80, 'selling_price' => 150, 'active' => true]);
    $stock = StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);

    $this->actingAs($user)->get(route('sales.accident-quotations.create'))->assertOk()->assertSeeText('Accident Bike Quotation');

    $this->post(route('sales.accident-quotations.store'), [
        'customer_mode' => 'existing', 'customer_id' => $customer->id,
        'bike_mode' => 'existing', 'bike_id' => $bike->id,
        'store_id' => $store->id,
        'items' => [['product_id' => $product->id, 'description' => 'Replacement headlamp', 'quantity' => 2, 'unit_price' => 150]],
        'charges' => [
            ['type' => 'service', 'description' => 'Accident inspection', 'amount' => 80],
            ['type' => 'labor', 'description' => 'Frame alignment labour', 'amount' => 120],
            ['type' => 'washing', 'description' => 'Final wash', 'amount' => 30],
            ['type' => 'other', 'description' => 'Paint materials', 'amount' => 45],
            ['type' => 'other', 'description' => 'Collection', 'amount' => 25],
        ],
        'discount' => 20,
        'customer_note' => 'Damage to front assembly.',
        'internal_note' => 'Check insurer approval.',
    ])->assertRedirect(route('sales.quotations'));

    $quote = Invoice::where('invoice_type', 'Quotation')->firstOrFail();
    expect($quote->number)->toMatch('/^QTN-\d{6}-\d{4}$/')
        ->and($quote->is_accident_quotation)->toBeTrue()
        ->and($quote->bike_id)->toBe($bike->id)
        ->and($quote->customer_id)->toBe($customer->id)
        ->and((float) $quote->parts_amount)->toBe(300.0)
        ->and((float) $quote->service_amount)->toBe(80.0)
        ->and((float) $quote->labor_amount)->toBe(120.0)
        ->and((float) $quote->washing_amount)->toBe(30.0)
        ->and((float) $quote->other_amount)->toBe(70.0)
        ->and((float) $quote->subtotal)->toBe(600.0)
        ->and((float) $quote->total)->toBe(580.0)
        ->and((float) $quote->paid_amount)->toBe(0.0)
        ->and((float) $quote->due_amount)->toBe(0.0)
        ->and($quote->payments()->count())->toBe(0)
        ->and($quote->charges()->count())->toBe(5)
        ->and((float) $stock->fresh()->quantity)->toBe(5.0);

    $this->get(route('sales.quotations', ['q' => 'WP-1234']))->assertOk()->assertSeeText($quote->number)->assertSeeText('Amina Perera');
    $posQuoteId = $this->postJson(route('sales.parts.store'), [
        'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => 'Quotation', 'paid_amount' => 0,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 150]],
    ])->assertOk()->json('invoice_id');
    $this->get(route('sales.quotations'))->assertOk()->assertSeeText($quote->number)
        ->assertSeeText(Invoice::findOrFail($posQuoteId)->number);
    $posQuote = Invoice::findOrFail($posQuoteId);
    expect($posQuote->is_accident_quotation)->toBeFalse();
    $this->get(route('sales.quotations', ['type' => 'accident']))->assertOk()
        ->assertSeeText('Accident bike')->assertSeeText($quote->number)->assertDontSeeText($posQuote->number);
    $this->get(route('sales.quotations', ['type' => 'normal']))->assertOk()
        ->assertSeeText('Normal')->assertSeeText($posQuote->number)->assertDontSeeText($quote->number);
    $this->get(route('invoices.public', $quote->public_token))->assertOk()
        ->assertSeeText('Replacement headlamp')->assertSeeText('Accident inspection')
        ->assertSeeText('Frame alignment labour')->assertSeeText('Paint materials')
        ->assertSeeText('WP-1234')->assertSeeText('Damage to front assembly.')
        ->assertDontSeeText('Check insurer approval.');
    $this->get(route('sales.invoices.show', $quote))->assertOk()->assertSeeText('Collection');
    $pdf = $this->get(route('sales.invoices.pdf', $quote))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr($pdf->getContent(), 0, 4))->toBe('%PDF');
});

test('accident quotation can register a customer and bike with charges only', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'New Quote Store', 'active' => true]);

    $this->actingAs($user)->post(route('sales.accident-quotations.store'), [
        'customer_mode' => 'new', 'customer_name' => 'New Rider', 'customer_phone' => '0712345678',
        'bike_mode' => 'new', 'bike_number' => 'cp-5678', 'bike_model' => 'Dio', 'bike_brand' => 'Honda',
        'store_id' => $store->id,
        'charges' => [['type' => 'service', 'description' => 'Accident assessment', 'amount' => 250]],
    ])->assertRedirect(route('sales.quotations'));

    $quote = Invoice::where('invoice_type', 'Quotation')->firstOrFail();
    expect($quote->customer->name)->toBe('New Rider')
        ->and($quote->bike->number)->toBe('CP-5678')
        ->and($quote->bike->customer_id)->toBe($quote->customer_id)
        ->and($quote->items()->count())->toBe(0)
        ->and((float) $quote->total)->toBe(250.0);
    $this->get(route('invoices.public', $quote->public_token))->assertOk()->assertSeeText('Accident assessment')->assertSeeText('CP-5678');
});

test('accident quotation rejects a bike belonging to another customer', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'First Rider', 'phone' => '0711111111', 'active' => true]);
    $other = Customer::create(['name' => 'Other Rider', 'phone' => '0722222222', 'active' => true]);
    $bike = Bike::create(['customer_id' => $other->id, 'number' => 'WP-9999', 'model' => 'X']);
    $store = Store::create(['name' => 'Secure Quote Store', 'active' => true]);

    $this->actingAs($user)->from(route('sales.accident-quotations.create'))->post(route('sales.accident-quotations.store'), [
        'customer_mode' => 'existing', 'customer_id' => $customer->id,
        'bike_mode' => 'existing', 'bike_id' => $bike->id,
        'store_id' => $store->id,
        'charges' => [['type' => 'labor', 'description' => 'Labour', 'amount' => 100]],
    ])->assertRedirect(route('sales.accident-quotations.create'))->assertSessionHasErrors('bike_id');
    expect(Invoice::count())->toBe(0);
});
