<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Account::ensureSystemAccounts('workshop');
    $this->store = Store::create(['name' => 'Return Store', 'active' => true]);
    $this->product = Product::create(['code' => 'RETURN-PART', 'name' => 'Brake Pad', 'cost_price' => 40, 'selling_price' => 100, 'unit' => 'pcs', 'active' => true]);
    StoreStock::create(['store_id' => $this->store->id, 'product_id' => $this->product->id, 'quantity' => 10]);
    $this->makeSale = function (array $extra = []) {
        $id = $this->postJson(route('sales.parts.store'), array_replace([
            'customer_mode' => 'walkin', 'store_id' => $this->store->id, 'paid_amount' => 0, 'account_id' => Account::where('code', 'BANK')->value('id'),
            'items' => [['product_id' => $this->product->id, 'quantity' => 2, 'unit_price' => 100, 'discount' => 0]],
        ], $extra))->assertOk()->json('invoice_id');

        return Invoice::findOrFail($id);
    };
    $this->stockQuantity = fn () => (float) StoreStock::where('store_id', $this->store->id)->where('product_id', $this->product->id)->value('quantity');
    $this->ledgerBalance = fn ($code) => (float) Account::where('code', $code)->firstOrFail()->transactions()->posted()->sum('credit') - (float) Account::where('code', $code)->firstOrFail()->transactions()->posted()->sum('debit');
});

test('parts management pages and receipt PDF render and list excludes other invoice types', function () {
    $sale = ($this->makeSale)();
    Invoice::create(['number' => 'OTHER-JOB', 'invoice_type' => 'Job Card Bill', 'status' => 'Unpaid', 'total' => 0, 'subtotal' => 0, 'due_amount' => 0, 'public_token' => 'other-job', 'created_by' => $this->user->id]);
    $this->get(route('sales.parts.index'))->assertOk()->assertSeeText($sale->number)->assertDontSeeText('OTHER-JOB')->assertSeeText('View')->assertSeeText('Edit')->assertSeeText('Return')->assertSeeText('Delete');
    $this->get(route('sales.parts.edit', $sale))->assertOk();
    $this->get(route('sales.parts.return', $sale))->assertOk();
    $this->get(route('sales.invoices.show', $sale))->assertOk()->assertSeeText('80 mm PDF');
    $this->get(route('invoices.public', ['token' => $sale->public_token, 'format' => '80mm']))->assertOk()->assertSeeText('Brake Pad')->assertSeeText('SALES RECEIPT')->assertSee('size: 80mm');
    $pdf = $this->get(route('sales.invoices.pdf', ['invoice' => $sale, 'format' => '80mm']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($pdf->getContent())->toStartWith('%PDF-')->toMatch('/MediaBox\s*\[0\.000 0\.000 226\.772/');
});

test('edit adjusts stock totals and due atomically while retaining payments', function () {
    $sale = ($this->makeSale)(['paid_amount' => 100, 'payment_method' => 'Cash']);
    $paymentId = $sale->payments()->firstOrFail()->id;
    $this->put(route('sales.parts.update', $sale), ['items' => [['id' => $sale->items()->first()->id, 'product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 100, 'discount' => 0]], 'discount' => 10, 'tax' => 0, 'reason' => 'Correct quantity'])->assertSessionHasNoErrors()->assertRedirect(route('sales.invoices.show', $sale));
    expect(($this->stockQuantity)())->toBe(7.0)->and((float) $sale->fresh()->total)->toBe(290.0)->and((float) $sale->fresh()->due_amount)->toBe(190.0)->and($sale->payments()->first()->id)->toBe($paymentId)->and(($this->ledgerBalance)('SPARE'))->toBe(100.0);
    $this->putJson(route('sales.parts.update', $sale), ['items' => [['id' => $sale->items()->first()->id, 'product_id' => $this->product->id, 'quantity' => 50, 'unit_price' => 100, 'discount' => 0]], 'discount' => 0, 'tax' => 0, 'reason' => 'Too many parts'])->assertUnprocessable();
    expect(($this->stockQuantity)())->toBe(7.0)->and((float) $sale->fresh()->total)->toBe(290.0);
});

test('return credits due first then refunds cash and deleting restores only remaining stock', function () {
    $sale = ($this->makeSale)(['paid_amount' => 150, 'payment_method' => 'Cash']);
    $item = $sale->items()->first();
    $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$item->id => 1], 'reason' => 'Wrong part returned'])->assertSessionHasNoErrors();
    $sale->refresh();
    expect(($this->stockQuantity)())->toBe(9.0)->and((float) $sale->total)->toBe(100.0)->and((float) $sale->due_amount)->toBe(0.0)->and((float) $sale->paid_amount)->toBe(100.0)->and((float) $sale->return_amount)->toBe(100.0)->and((float) SaleReturn::first()->refund_amount)->toBe(50.0)->and((float) $sale->payments()->first()->refunded_amount)->toBe(50.0)->and(($this->ledgerBalance)('SPARE'))->toBe(100.0);
    $this->get(route('sales.invoices.show', $sale))->assertOk()->assertSeeText('Returns & Refunds');
    $this->postJson(route('sales.parts.return.store', $sale), ['quantities' => [$item->id => 2], 'reason' => 'Duplicate return'])->assertUnprocessable();
    expect(($this->stockQuantity)())->toBe(9.0)->and(SaleReturn::count())->toBe(1);
    $this->delete(route('sales.parts.destroy', $sale), ['reason' => 'Customer cancelled sale'])->assertSessionHasNoErrors()->assertRedirect(route('sales.parts.index'));
    expect(($this->stockQuantity)())->toBe(10.0)->and(($this->ledgerBalance)('SPARE'))->toBe(0.0)->and($sale->fresh()->status)->toBe('Cancelled')->and($sale->fresh()->parts_deleted_at)->not->toBeNull();
    $this->get(route('sales.parts.index'))->assertDontSeeText($sale->number);
    $this->get(route('sales.parts.index', ['status' => 'Deleted']))->assertSeeText($sale->number);
    $this->get(route('invoices.public', $sale->public_token))->assertGone();
    $this->delete(route('sales.parts.destroy', $sale), ['reason' => 'Repeated deletion'])->assertSessionHasNoErrors();
    expect(($this->stockQuantity)())->toBe(10.0)->and(($this->ledgerBalance)('SPARE'))->toBe(0.0);
});

test('bank refund reduces bank and parts ledgers and full return leaves no balances', function () {
    $sale = ($this->makeSale)(['paid_amount' => 200, 'payment_method' => 'Card']);
    $item = $sale->items()->first();
    foreach ([1, 1] as $index => $quantity) {
        $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$item->id => $quantity], 'reason' => 'Returned unused part'])->assertSessionHasNoErrors();
        expect(($this->ledgerBalance)('BANK'))->toBe($index === 0 ? 100.0 : 0.0);
    }
    expect(($this->stockQuantity)())->toBe(10.0)->and(($this->ledgerBalance)('BANK'))->toBe(0.0)->and(($this->ledgerBalance)('SPARE'))->toBe(0.0)->and((float) $sale->fresh()->total)->toBe(0.0)->and((float) $sale->fresh()->paid_amount)->toBe(0.0)->and(SaleReturn::count())->toBe(2);
});

test('held cheque prevents refunding the cheque face value and rolls back stock', function () {
    $customer = Customer::create(['name' => 'Cheque Customer', 'phone' => '0778009001', 'active' => true]);
    $sale = ($this->makeSale)(['customer_mode' => 'existing', 'customer_id' => $customer->id, 'payments' => [['method' => 'Cheque', 'amount' => 200, 'account_id' => Account::where('code', 'BANK')->value('id'), 'cheque_date' => today()->addWeek()->toDateString(), 'cheque_number' => 'RETURN-CHQ', 'bank_name' => 'Test Bank']]]);
    $this->postJson(route('sales.parts.return.store', $sale), ['quantities' => [$sale->items()->first()->id => 1], 'reason' => 'Held cheque return'])->assertUnprocessable();
    expect(($this->stockQuantity)())->toBe(8.0)->and((float) $sale->fresh()->hold_amount)->toBe(200.0)->and(SaleReturn::count())->toBe(0);
});

test('edit cannot reduce total below receipts and unauthorized users cannot return or delete', function () {
    $sale = ($this->makeSale)(['paid_amount' => 200, 'payment_method' => 'Cash']);
    $this->putJson(route('sales.parts.update', $sale), ['items' => [['id' => $sale->items()->first()->id, 'product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]], 'discount' => 0, 'tax' => 0, 'reason' => 'Reduce covered total'])->assertUnprocessable();
    expect(($this->stockQuantity)())->toBe(8.0)->and((float) $sale->fresh()->total)->toBe(200.0);
    $role = Role::create(['name' => 'Read Only Sales', 'permissions' => ['sales.view_invoices']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('sales.parts.index'))->assertOk();
    $this->delete(route('sales.parts.destroy', $sale), ['reason' => 'Unauthorized delete'])->assertForbidden();
    $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$sale->items()->first()->id => 1], 'reason' => 'Unauthorized return'])->assertForbidden();
    $this->get(route('sales.parts.edit', $sale))->assertForbidden();
});

test('discounted taxed returns credit unpaid balance proportionately without a refund', function () {
    $sale = ($this->makeSale)(['discount' => 20, 'tax' => 10]);
    $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$sale->items()->first()->id => 1], 'reason' => 'Proportional credit'])->assertSessionHasNoErrors();
    expect((float) $sale->fresh()->total)->toBe(95.0)->and((float) $sale->fresh()->due_amount)->toBe(95.0)->and((float) $sale->fresh()->discount)->toBe(10.0)->and((float) $sale->fresh()->tax)->toBe(5.0)->and((float) SaleReturn::first()->amount)->toBe(95.0)->and((float) SaleReturn::first()->refund_amount)->toBe(0.0);
});

test('mixed payment refund updates both ledgers without double refund on later deletion', function () {
    $bank = Account::where('code', 'BANK')->value('id');
    $sale = ($this->makeSale)(['payments' => [['method' => 'Cash', 'amount' => 120, 'account_id' => $bank], ['method' => 'Card', 'amount' => 80, 'account_id' => $bank]]]);
    $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$sale->items()->first()->id => 1], 'reason' => 'Mixed refund test'])->assertSessionHasNoErrors();
    expect(($this->ledgerBalance)('BANK'))->toBe(0.0)->and(($this->ledgerBalance)('SPARE'))->toBe(100.0)->and((float) $sale->fresh()->paid_amount)->toBe(100.0)->and(count(SaleReturn::first()->refunds))->toBe(2);
    $this->delete(route('sales.parts.destroy', $sale), ['reason' => 'Delete mixed sale'])->assertSessionHasNoErrors();
    expect(($this->ledgerBalance)('BANK'))->toBe(0.0)->and(($this->ledgerBalance)('SPARE'))->toBe(0.0)->and(($this->stockQuantity)())->toBe(10.0);
});

test('returns restore batch quantity and prevent cross shop actions', function () {
    $batch = StockBatch::create(['product_id' => $this->product->id, 'store_id' => $this->store->id, 'batch_no' => 'SALE-RETURN-BATCH', 'available_qty' => 10, 'purchased_qty' => 10, 'cost_price' => 40, 'selling_price' => 100, 'purchase_date' => today(), 'status' => 'active']);
    $sale = ($this->makeSale)(['items' => [['product_id' => $this->product->id, 'stock_batch_id' => $batch->id, 'quantity' => 2, 'unit_price' => 100, 'discount' => 0]]]);
    $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$sale->items()->first()->id => 1], 'reason' => 'Batch return test'])->assertSessionHasNoErrors();
    expect((float) $batch->fresh()->available_qty)->toBe(9.0);
    $this->withSession(['shop_mode' => 'helmet'])->get(route('sales.parts.edit', $sale))->assertNotFound();
    $this->withSession(['shop_mode' => 'helmet'])->post(route('sales.parts.return.store', $sale), ['quantities' => [$sale->items()->first()->id => 1], 'reason' => 'Cross shop return'])->assertNotFound();
});

test('repeated fractional returns refund exact original cents and keep service income', function () {
    $sale = ($this->makeSale)(['items' => [['product_id' => $this->product->id, 'quantity' => 3, 'unit_price' => 0.33, 'discount' => 0]], 'discount' => 0.10, 'tax' => 0.05, 'paid_amount' => 0.94, 'payment_method' => 'Cash']);
    $id = $sale->items()->first()->id;
    for ($i = 0; $i < 3; $i++) {
        $this->post(route('sales.parts.return.store', $sale), ['quantities' => [$id => 1], 'reason' => 'Rounding return test'])->assertSessionHasNoErrors();
    }
    expect((float) $sale->fresh()->return_amount)->toBe(0.94)->and((float) $sale->fresh()->total)->toBe(0.0)->and(($this->ledgerBalance)('SPARE'))->toBe(0.0);
    $mixed = ($this->makeSale)(['charges' => [['type' => 'service', 'description' => 'Installation', 'amount' => 100]], 'paid_amount' => 300, 'payment_method' => 'Card']);
    $this->post(route('sales.parts.return.store', $mixed), ['quantities' => [$mixed->items()->first()->id => 1], 'reason' => 'Keep service charges'])->assertSessionHasNoErrors();
    expect((float) $mixed->fresh()->total)->toBe(200.0)->and(($this->ledgerBalance)('MAIN'))->toBe(100.0)->and(($this->ledgerBalance)('SPARE'))->toBe(100.0)->and(($this->ledgerBalance)('BANK'))->toBe(200.0);
});
