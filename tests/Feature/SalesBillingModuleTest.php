<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\ServiceReminder;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;

beforeEach(function () {
    Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'main', 'active' => true]);
    Account::create(['code' => 'SPARE', 'name' => 'Spare Parts Account', 'type' => 'spare', 'active' => true]);
    Account::create(['code' => 'BANK', 'name' => 'Bank Account', 'type' => 'bank', 'active' => true]);
});

test('all sales billing module pages render complete interfaces', function () {
    $user = User::factory()->create();

    foreach (['sales.job-billing', 'sales.parts', 'sales.invoices', 'sales.payments', 'sales.dues', 'sales.customer-payments', 'sales.public-links'] as $route) {
        $this->actingAs($user)->withOpenCashier()->get(route($route))->assertOk()->assertDontSeeText('Page available')->assertDontSeeText('No records to display yet');
    }
});

test('parts sale and customer payment update stock due spare ledger and public access', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Billing Customer', 'phone' => '0776000001', 'active' => true]);
    $store = Store::create(['name' => 'Billing Store', 'active' => true]);
    $product = Product::create(['code' => 'SALE-001', 'name' => 'Sale Test Part', 'cost_price' => 50, 'selling_price' => 100, 'minimum_stock' => 2, 'unit' => 'pcs', 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 10]);
    $spare = Account::where('code', 'SPARE')->firstOrFail();

    $this->actingAs($user)->withOpenCashier()->post(route('sales.parts.store'), [
        'customer_mode' => 'existing', 'customer_id' => $customer->id, 'store_id' => $store->id,
        'discount' => 0, 'tax' => 0, 'paid_amount' => 100, 'payment_method' => 'Cash', 'account_id' => $spare->id,
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100, 'discount' => 0]],
    ])->assertSessionHasNoErrors();

    $invoice = Invoice::firstOrFail();
    expect((float) StoreStock::where(['store_id' => $store->id, 'product_id' => $product->id])->value('quantity'))->toBe(8.0)
        ->and((float) $invoice->due_amount)->toBe(100.0)
        ->and((float) $spare->transactions()->sum('credit'))->toBe(100.0);

    $this->actingAs($user)->withOpenCashier()->post(route('sales.payments.store'), [
        'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'Cash',
        'account_id' => $spare->id, 'paid_at' => today()->toDateString(), 'note' => 'Balance settlement',
    ])->assertSessionHasNoErrors();

    expect((float) $invoice->fresh()->due_amount)->toBe(0.0)
        ->and((float) $spare->transactions()->sum('credit'))->toBe(200.0);

    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSeeText('Sale Test Part');
    expect($invoice->fresh()->open_count)->toBe(1);
});

test('an overpaid parts sale is rejected without partial invoice stock or ledger changes', function () {
    $user = User::factory()->create();
    $store = Store::create(['name' => 'Rollback Store', 'active' => true]);
    $product = Product::create(['code' => 'ROLLBACK-001', 'name' => 'Rollback Part', 'cost_price' => 50, 'selling_price' => 100, 'unit' => 'pcs', 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $account = Account::where('code', 'SPARE')->firstOrFail();

    $this->actingAs($user)->withOpenCashier()->postJson(route('sales.parts.store'), [
        'customer_mode' => 'walkin',
        'store_id' => $store->id,
        'paid_amount' => 150,
        'payment_method' => 'Cash',
        'account_id' => $account->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]],
    ])->assertUnprocessable()->assertJsonValidationErrors('paid_amount');

    expect(Invoice::count())->toBe(0)
        ->and((float) StoreStock::where(['store_id' => $store->id, 'product_id' => $product->id])->value('quantity'))->toBe(5.0)
        ->and($account->transactions()->count())->toBe(0);
});

test('helmet shop initializes its own accounts without colliding with workshop codes', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->withSession(['shop_mode' => 'helmet'])->withOpenCashier()->get(route('sales.parts'))->assertOk();

    expect(Account::withoutGlobalScopes()->where('code', 'MAIN')->exists())->toBeTrue()
        ->and(Account::withoutGlobalScopes()->where('code', 'HELMET_MAIN')->where('shop_type', 'helmet')->exists())->toBeTrue()
        ->and(Account::withoutGlobalScopes()->where('code', 'HELMET_SPARE')->where('shop_type', 'helmet')->exists())->toBeTrue()
        ->and(Account::withoutGlobalScopes()->where('code', 'HELMET_BANK')->where('shop_type', 'helmet')->exists())->toBeTrue();
});

test('card-paid job bill splits service income and bank receipt correctly', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Job Bill Customer', 'phone' => '0776000002', 'active' => true]);
    $bike = $customer->bikes()->create(['number' => 'WP BILL 100', 'model' => 'Yamaha FZ']);
    $intake = BikeIntake::create(['intake_number' => 'IN-BILL-001', 'bike_id' => $bike->id, 'complaint' => 'Service billing test', 'created_by' => $user->id]);
    $job = JobCard::create(['number' => 'JOB-BILL-001', 'bike_intake_id' => $intake->id, 'status' => 'Completed', 'priority' => 'Normal', 'created_by' => $user->id]);
    $bank = Account::where('code', 'BANK')->firstOrFail();

    $this->actingAs($user)->withOpenCashier()->post(route('sales.job-billing.store', $job), [
        'service_total' => 700, 'labor_total' => 200, 'washing_total' => 100, 'other_total' => 0,
        'discount' => 0, 'tax' => 0, 'paid_amount' => 1000, 'payment_method' => 'Card', 'account_id' => $bank->id,
    ])->assertSessionHasNoErrors();

    expect((float) Account::where('code', 'MAIN')->firstOrFail()->transactions()->sum('credit'))->toBe(900.0)
        ->and((float) Account::systemAccount('washing')->transactions()->sum('credit'))->toBe(100.0)
        ->and((float) $bank->transactions()->sum('credit'))->toBe(1000.0)
        ->and($job->fresh()->status)->toBe('Billed')
        ->and($job->invoice?->invoice_type)->toBe('Job Card Bill')
        ->and($job->invoice?->number)->toMatch('/^INV-\d{8}-0001$/')
        ->and(ServiceReminder::where('job_card_id', $job->id)->where('status', 'Pending')->exists())->toBeTrue();
});
