<?php

use App\Models\Account;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;
use App\Support\InvoiceNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

test('quotation numbers reset monthly and invoice numbers reset daily', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Numbering Store', 'active' => true]);
    $account = Account::create(['code' => 'NUMBER-BANK', 'name' => 'Number Bank', 'type' => 'bank', 'active' => true]);
    $product = Product::create(['code' => 'NUMBER-ITEM', 'name' => 'Numbered Part', 'cost_price' => 20, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 10]);

    $create = function (string $type) use ($user, $store, $account, $product): Invoice {
        $id = $this->actingAs($user)->withOpenCashier()->postJson(route('sales.parts.store'), [
            'customer_mode' => 'walkin', 'store_id' => $store->id, 'invoice_type' => $type,
            'paid_amount' => $type === 'Quotation' ? 0 : 100,
            'payment_method' => $type === 'Quotation' ? null : 'Cash',
            'account_id' => $type === 'Quotation' ? null : $account->id,
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertOk()->json('invoice_id');

        return Invoice::findOrFail($id);
    };

    $this->travelTo(Carbon::parse('2026-09-28 10:00:00'));
    expect($create('Quotation')->number)->toBe('QTN-202609-0001')
        ->and($create('Quotation')->number)->toBe('QTN-202609-0002')
        ->and($create('Parts Sale')->number)->toBe('INV-20260928-0001')
        ->and($create('Parts Sale')->number)->toBe('INV-20260928-0002');

    $this->travelTo(Carbon::parse('2026-09-29 10:00:00'));
    $quote = $create('Quotation');
    expect($quote->number)->toBe('QTN-202609-0003')
        ->and($create('Parts Sale')->number)->toBe('INV-20260929-0001');
    $this->get(route('invoices.public', $quote->public_token))->assertOk()->assertSeeText('QTN-202609-0003');

    $this->travelTo(Carbon::parse('2026-10-01 10:00:00'));
    expect($create('Quotation')->number)->toBe('QTN-202610-0001')
        ->and($create('Parts Sale')->number)->toBe('INV-20261001-0001');
});

test('sequence continues after an existing formatted number and is shared by job bills', function () {
    $this->travelTo(Carbon::parse('2026-09-28 12:00:00'));
    $user = User::factory()->create();
    Invoice::create(['number' => 'INV-20260928-0009', 'invoice_type' => 'Parts Sale', 'subtotal' => 0, 'total' => 0, 'status' => 'Paid', 'public_token' => Str::random(48), 'created_by' => $user->id]);
    Invoice::create(['number' => 'QTN-202609-0012', 'invoice_type' => 'Quotation', 'subtotal' => 0, 'total' => 0, 'status' => 'Draft', 'public_token' => Str::random(48), 'created_by' => $user->id]);

    expect(InvoiceNumber::next('Job Card Bill'))->toBe('INV-20260928-0010')
        ->and(InvoiceNumber::next('Parts Sale'))->toBe('INV-20260928-0011')
        ->and(InvoiceNumber::next('Quotation'))->toBe('QTN-202609-0013');
});
