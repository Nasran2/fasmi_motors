<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Supplier;
use App\Models\User;

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 6)->startOfDay());
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->account = Account::systemAccount('main');
    $this->customer = Customer::create(['name' => 'Cheque Customer', 'phone' => '0771234500', 'active' => true]);
    $this->supplier = Supplier::create(['name' => 'Cheque Supplier', 'active' => true]);
    $this->store = Store::create(['name' => 'Cheque Test Store', 'active' => true]);
    $this->product = Product::create(['code' => 'CHQ-PART', 'name' => 'Cheque Test Part', 'cost_price' => 50, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $this->store->id, 'product_id' => $this->product->id, 'quantity' => 100]);
    $this->sale = ['customer_id' => $this->customer->id, 'store_id' => $this->store->id, 'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'unit_price' => 100]]];
    $this->purchase = ['supplier_id' => $this->supplier->id, 'store_id' => $this->store->id, 'invoice_number' => 'SUP-CHQ-001', 'purchase_date' => '2026-10-06', 'items' => [['product_id' => $this->product->id, 'quantity' => 10, 'cost_price' => 100, 'selling_price' => 150]]];
    $this->cash = ['method' => 'Cash', 'amount' => 200, 'account_id' => $this->account->id];
    $this->cheque = ['method' => 'Cheque', 'amount' => 400, 'account_id' => $this->account->id, 'cheque_date' => '2026-10-08', 'cheque_number' => 'CHQ-001', 'bank_name' => 'Test Bank'];
});

test('checkout splits cash and multiple cheques into paid hold and due', function () {
    $rows = [$this->cash, $this->cheque, array_replace($this->cheque, ['amount' => 300, 'cheque_number' => 'CHQ-002', 'cheque_date' => '2026-10-12'])];
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => $rows])->assertOk();
    $invoice = Invoice::firstOrFail();
    expect((float) $invoice->paid_amount)->toBe(200.0)->and((float) $invoice->hold_amount)->toBe(700.0)->and((float) $invoice->due_amount)->toBe(100.0)->and($invoice->status)->toBe('Partially Paid / Hold')->and($invoice->payment_method)->toBe('Mixed');
    expect($invoice->payments()->where('status', 'Hold')->count())->toBe(2)->and((float) AccountTransaction::where('account_type', 'bank')->sum('credit'))->toBe(0.0)->and((float) AccountTransaction::where('account_type', 'spare_parts')->sum('credit'))->toBe(200.0);
    $this->get(route('sales.invoices.show', $invoice))->assertOk()->assertSeeText('Cheque Hold');
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSeeText('Cheque Hold');
    $this->get(route('dashboard'))->assertOk()->assertSeeText('CHQ-001')->assertDontSeeText('CHQ-002')->assertSeeText('Customer Cheques')->assertSeeText('Supplier Cheques');
});

test('a cheque passes only on its date and a returned cheque restores due', function () {
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [$this->cash, $this->cheque]])->assertOk();
    $cheque = Cheque::firstOrFail();
    $invoice = Invoice::firstOrFail();
    $this->patchJson(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => '2026-10-06'])->assertUnprocessable();
    $this->travelTo(now()->setDate(2026, 10, 8));
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => '2026-10-08'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->paid_amount)->toBe(600.0)->and((float) $invoice->fresh()->hold_amount)->toBe(0.0)->and((float) $invoice->fresh()->due_amount)->toBe(400.0)->and((float) AccountTransaction::where('account_type', 'bank')->sum('credit'))->toBe(400.0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Bank rejected cheque'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->paid_amount)->toBe(200.0)->and((float) $invoice->fresh()->due_amount)->toBe(800.0)->and($cheque->payments()->first()->status)->toBe('Returned')->and((float) Account::systemAccount('bank')->balance)->toBe(0.0)->and((float) Account::systemAccount('spare_parts')->balance)->toBe(200.0);
});

test('purchases split cash and multiple cheques and restore held due on return', function () {
    $this->post(route('purchases.store'), $this->purchase + ['payments' => [$this->cash, $this->cheque, array_replace($this->cheque, ['cheque_number' => 'CHQ-002'])]])->assertRedirect()->assertSessionHasNoErrors();
    $purchase = Purchase::firstOrFail();
    $cheques = Cheque::orderBy('id')->get();
    expect((float) $purchase->paid_amount)->toBe(200.0)->and((float) $purchase->hold_amount)->toBe(800.0)->and((float) $purchase->due_amount)->toBe(0.0)->and($purchase->payment_status)->toBe('Hold')->and((float) $this->supplier->fresh()->balance)->toBe(0.0);
    $this->patch(route('accounts.cheques.status', $cheques[0]), ['status' => 'Returned', 'reason' => 'Insufficient balance'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $purchase->fresh()->due_amount)->toBe(400.0)->and((float) $purchase->fresh()->hold_amount)->toBe(400.0)->and((float) $this->supplier->fresh()->balance)->toBe(400.0);
    $this->travelTo(now()->setDate(2026, 10, 8));
    $this->patch(route('accounts.cheques.status', $cheques[1]), ['status' => 'Cleared', 'realized_date' => '2026-10-08'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $purchase->fresh()->paid_amount)->toBe(600.0)->and((float) $purchase->fresh()->hold_amount)->toBe(0.0)->and((float) Account::systemAccount('bank')->balance)->toBe(-400.0);
    $this->get(route('purchases.show', $purchase))->assertOk()->assertSeeText('Cheque Hold');
});

test('forwarding a customer cheque settles both parties without posting to our bank', function () {
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [$this->cheque]])->assertOk();
    $cheque = Cheque::firstOrFail();
    $invoice = Invoice::firstOrFail();
    $party = ['method' => 'Party Cheque', 'amount' => 400, 'party_cheque_id' => $cheque->id];
    $this->post(route('purchases.store'), $this->purchase + ['payments' => [$this->cash, $party]])->assertRedirect()->assertSessionHasNoErrors();
    $purchase = Purchase::firstOrFail();
    expect((int) $cheque->fresh()->forwarded_supplier_id)->toBe($this->supplier->id)->and((float) $purchase->hold_amount)->toBe(400.0);
    $this->get(route('purchases.show', $purchase))->assertOk();
    $this->get(route('dashboard'))->assertOk()->assertSeeText('Customer → Supplier')->assertSeeText('Cheque Supplier');
    $this->post(route('purchases.store'), array_replace($this->purchase, ['invoice_number' => 'REUSE', 'payments' => [$party]]))->assertSessionHasErrors('payments');
    expect(Purchase::count())->toBe(1);
    $this->travelTo(now()->setDate(2026, 10, 8));
    foreach (range(1, 2) as $_) {
        $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => '2026-10-08'])->assertRedirect()->assertSessionHasNoErrors();
    }
    expect((float) $invoice->fresh()->paid_amount)->toBe(400.0)->and((float) $purchase->fresh()->paid_amount)->toBe(600.0)->and((float) Account::systemAccount('bank')->balance)->toBe(0.0)->and((float) Account::systemAccount('spare_parts')->balance)->toBe(-200.0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Cheque rejected by bank'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->due_amount)->toBe(1000.0)->and((float) $purchase->fresh()->due_amount)->toBe(800.0)->and((float) $purchase->fresh()->paid_amount)->toBe(200.0)->and((float) Account::systemAccount('bank')->balance)->toBe(0.0)->and((float) Account::systemAccount('spare_parts')->balance)->toBe(-200.0);
});

test('returning a forwarded pending cheque restores both customer and supplier dues', function () {
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [$this->cheque]])->assertOk();
    $cheque = Cheque::firstOrFail();
    $this->post(route('purchases.store'), $this->purchase + ['payments' => [['method' => 'Party Cheque', 'amount' => 400, 'party_cheque_id' => $cheque->id]]])->assertRedirect()->assertSessionHasNoErrors();
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Customer stopped payment'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) Invoice::first()->due_amount)->toBe(1000.0)->and((float) Purchase::first()->due_amount)->toBe(1000.0)->and((float) Invoice::first()->hold_amount)->toBe(0.0)->and((float) Purchase::first()->hold_amount)->toBe(0.0)->and(AccountTransaction::count())->toBe(0);
});

test('one customer cheque can cover multiple due invoices and remains one instrument', function () {
    foreach (range(1, 2) as $_) {
        $this->postJson(route('sales.parts.store'), $this->sale + ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertOk();
    }
    $this->post(route('sales.payments.store'), ['customer_id' => $this->customer->id, 'paid_at' => '2026-10-06', 'payments' => [array_replace($this->cheque, ['amount' => 1500])]])->assertRedirect()->assertSessionHasNoErrors();
    $cheque = Cheque::firstOrFail();
    expect(Cheque::count())->toBe(1)->and((float) $cheque->amount)->toBe(1500.0)->and((float) Invoice::sum('hold_amount'))->toBe(1500.0)->and((float) Invoice::sum('due_amount'))->toBe(500.0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Customer payment rejected'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) Invoice::sum('due_amount'))->toBe(2000.0);
});

test('missing cheque dates and overpayments are rejected atomically', function () {
    $rows = [array_replace($this->cheque, ['cheque_date' => null])];
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => $rows])->assertUnprocessable()->assertJsonValidationErrors('payments.0.cheque_date');
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [array_replace($this->cash, ['amount' => 1100])]])->assertUnprocessable();
    $this->post(route('purchases.store'), $this->purchase + ['payments' => $rows])->assertSessionHasErrors('payments.0.cheque_date');
    expect(Invoice::count())->toBe(0)->and(Purchase::count())->toBe(0)->and(Cheque::count())->toBe(0)->and(AccountTransaction::count())->toBe(0)->and((float) StoreStock::first()->quantity)->toBe(100.0);
});

test('held cheque cancellation restores due without posting funds', function () {
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [$this->cheque]])->assertOk();
    $invoice = Invoice::firstOrFail();
    $cheque = Cheque::firstOrFail();
    $this->delete(route('sales.invoices.cancel', $invoice), ['reason' => 'Sale entered incorrectly'])->assertRedirect()->assertSessionHasNoErrors();
    expect($invoice->fresh()->status)->toBe('Cancelled')->and((float) $invoice->fresh()->hold_amount)->toBe(0.0)->and($cheque->fresh()->status)->toBe('Cancelled')->and(AccountTransaction::count())->toBe(0);
});

test('supplier due payments allocate one cheque across purchases and restore each balance', function () {
    foreach (range(1, 2) as $number) {
        $this->post(route('purchases.store'), array_replace($this->purchase, ['invoice_number' => 'DUE-'.$number, 'paid_amount' => 0]))->assertRedirect()->assertSessionHasNoErrors();
    }
    $this->post(route('purchases.payments.store'), ['supplier_id' => $this->supplier->id, 'payment_date' => '2026-10-06', 'payments' => [array_replace($this->cheque, ['amount' => 1500])]])->assertRedirect()->assertSessionHasNoErrors();
    expect(Cheque::count())->toBe(1)->and((float) Purchase::sum('hold_amount'))->toBe(1500.0)->and((float) Purchase::sum('due_amount'))->toBe(500.0);
    $this->get(route('purchases.payments'))->assertOk()->assertSeeText('Hold');
    $this->get(route('purchases.index'))->assertOk()->assertSeeText('Cheque Hold');
    $this->patch(route('accounts.cheques.status', Cheque::first()), ['status' => 'Returned', 'reason' => 'Supplier returned cheque'])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) Purchase::sum('due_amount'))->toBe(2000.0)->and((float) $this->supplier->fresh()->balance)->toBe(2000.0);
});

test('customer due collections also put cheque payments on hold', function () {
    $this->postJson(route('sales.parts.store'), $this->sale + ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertOk();
    $invoice = Invoice::firstOrFail();
    $this->post(route('customers.dues.payment', $invoice), ['payments' => [$this->cash, $this->cheque]])->assertRedirect()->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->paid_amount)->toBe(200.0)->and((float) $invoice->fresh()->hold_amount)->toBe(400.0)->and((float) $invoice->fresh()->due_amount)->toBe(400.0);
    $this->get(route('customers.dues'))->assertOk()->assertSeeText('Cheque Hold');
});

test('empty split payments support credit sales and quotations', function () {
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [], 'payment_method' => 'Credit/Due'])->assertOk();
    $invoice = Invoice::firstOrFail();
    expect((float) $invoice->due_amount)->toBe(1000.0)->and($invoice->status)->toBe('Unpaid')->and($invoice->payments()->count())->toBe(0);
    $this->postJson(route('sales.parts.store'), $this->sale + ['payments' => [], 'invoice_type' => 'Quotation'])->assertOk();
    $quotation = Invoice::where('invoice_type', 'Quotation')->firstOrFail();
    expect($quotation->status)->toBe('Draft')->and((float) $quotation->due_amount)->toBe(0.0)->and($quotation->payments()->count())->toBe(0);
});
