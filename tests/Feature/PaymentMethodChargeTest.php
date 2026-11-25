<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Setting;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\User;
use App\Services\CashierService;
use App\Support\PaymentMethodCharges;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user)->withOpenCashier();
    Account::ensureSystemAccounts();
});

test('payment method settings defaults card charge to 2.5 percent on bank account and persists updates', function () {
    expect(PaymentMethodCharges::rateFor('Card'))->toBe(2.5)
        ->and(PaymentMethodCharges::chargeAccountType('Card'))->toBe('bank')
        ->and(PaymentMethodCharges::rateFor('Cash'))->toBe(0.0);

    $this->get(route('system-settings.payment-method-settings'))
        ->assertOk()
        ->assertSee('name="pm_card_charge_percent"', false)
        ->assertSee('value="2.5"', false)
        ->assertSee('name="pm_card_charge_account"', false)
        ->assertSee('Main Account — opening balance')
        ->assertSee('Spare Parts Account — opening balance')
        ->assertSee('Spare Parts Account Cashier — today’s cash')
        ->assertSee('Bank Account');

    $this->post(route('system-settings.save'), [
        'group' => 'payment',
        'pm_card_charge_percent' => '3.00',
        'pm_card_charge_account' => 'main',
        'pm_cash_charge_percent' => '0',
        'pm_cash_charge_account' => 'bank',
    ])->assertSessionHasNoErrors();

    expect(PaymentMethodCharges::rateFor('Card'))->toBe(3.0)
        ->and(PaymentMethodCharges::chargeAccountType('Card'))->toBe('main')
        ->and(Setting::valueFor('pm_cash_charge_percent'))->toBe('0');
});

test('card payment keeps existing bank transfer pool concept and posts 2.5 percent fee as bank expense outside cashier', function () {
    $cashier = app(CashierService::class);
    $customer = Customer::create(['name' => 'Card Customer', 'phone' => '0775551122']);
    $bike = $customer->bikes()->create(['number' => 'XC-1010', 'model' => 'Yamaha FZ']);
    $intake = BikeIntake::create([
        'intake_number' => 'IN-CARD-FEE',
        'bike_id' => $bike->id,
        'complaint' => 'Full service',
        'created_by' => $this->user->id,
    ]);
    $job = JobCard::create([
        'number' => 'JOB-CARD-FEE',
        'bike_intake_id' => $intake->id,
        'status' => 'Completed',
        'priority' => 'Normal',
        'service_total' => 4000,
        'parts_total' => 6000,
        'created_by' => $this->user->id,
    ]);

    $bankId = Account::systemAccount('bank')->id;
    $this->post(route('jobs.invoice', $job), [
        'payments' => [['method' => 'Card', 'amount' => 10000, 'account_id' => $bankId]],
    ])->assertSessionHasNoErrors();

    $invoice = $job->fresh()->invoice;
    $payment = $invoice->payments()->firstOrFail();

    // 1. Existing bank-to-main transfer concept preserved intact:
    // Bank gets 10,000 receipt; Main gets 4,000 service; Spare Parts transfers out 4,000 from cashier.
    // Bank ALSO gets the 2.5% Card Charge Expense (-250.00), leaving net 9,750.00 in Bank.
    expect((float) $payment->charge_rate)->toBe(2.5)
        ->and((float) $payment->charge_amount)->toBe(250.0)
        ->and($payment->charge_expense_id)->not->toBeNull()
        ->and(Account::systemAccount('main')->balance)->toBe(4000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(-4000.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(9750.0);

    // 2. Fee is saved as a real Expense outside the cashier drawer
    $expense = Expense::findOrFail($payment->charge_expense_id);
    expect((float) $expense->amount)->toBe(250.0)
        ->and($expense->account_id)->toBe($bankId)
        ->and($expense->status)->toBe('Active')
        ->and($expense->payment_method)->toBe('Card')
        ->and($expense->funding_allocations)->toBe([['source' => 'bank', 'amount' => '250.00']]);

    $summary = $cashier->summary(today()->toDateString());
    expect($summary['cash_in_hand'])->toBe(0.0)
        ->and($summary['cash_paid'])->toBe(0.0)
        ->and($summary['display_accounts']['bank']['income'])->toBe(10000.0)
        ->and($summary['display_accounts']['bank']['outgoing'])->toBe(250.0)
        ->and($summary['display_accounts']['bank']['closing'])->toBe(9750.0);

    // 3. Expense appears in Expenses report & P&L report, and payment shows live charge info on job/POS views
    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertSeeText('250.00')
        ->assertSeeText(App\Services\PaymentChargeService::EXPENSE_CATEGORY);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSeeText('Card charge (2.5%): Rs. 250.00')
        ->assertSee('chargeConfig', false);

    $this->get(route('sales.parts'))
        ->assertOk()
        ->assertSee('calculateMethodCharge', false);
});

test('charge account can be switched in settings to spare parts or main without touching cashier cash drawer', function () {
    $this->post(route('system-settings.save'), [
        'group' => 'payment',
        'pm_card_charge_percent' => '2.5',
        'pm_card_charge_account' => 'main',
    ])->assertSessionHasNoErrors();

    $customer = Customer::create(['name' => 'Parts Sale Customer', 'phone' => '0775552233']);
    $store = Store::create(['name' => 'Card Fee Store', 'active' => true]);
    $product = Product::create(['code' => 'CF-001', 'name' => 'Brake Pad', 'cost_price' => 500, 'selling_price' => 1000, 'minimum_stock' => 1, 'unit' => 'pcs', 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $bank = Account::systemAccount('bank');

    $this->post(route('sales.parts.store'), [
        'customer_mode' => 'existing',
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'discount' => 0,
        'tax' => 0,
        'paid_amount' => 1000,
        'payment_method' => 'Card',
        'account_id' => $bank->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1000, 'discount' => 0]],
    ])->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    $expense = Expense::findOrFail($payment->charge_expense_id);

    expect((float) $expense->amount)->toBe(25.0)
        ->and($expense->account_id)->toBe(Account::systemAccount('main')->id)
        ->and(Account::systemAccount('main')->balance)->toBe(-25.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(1000.0);

    // Must NOT enter Cashier Cash-in-Hand!
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_in_hand'])->toBe(0.0)
        ->and($summary['cash_paid'])->toBe(0.0);
});

test('cancelling correcting or deleting a card payment reverses or purges its charge expense cleanly', function () {
    $customer = Customer::create(['name' => 'Lifecycle Customer', 'phone' => '0775553344']);
    $bike = $customer->bikes()->create(['number' => 'XC-2020', 'model' => 'R15']);
    $intake = BikeIntake::create(['intake_number' => 'IN-CARD-LIFE', 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => $this->user->id]);
    $job = JobCard::create([
        'number' => 'JOB-CARD-LIFE',
        'bike_intake_id' => $intake->id,
        'status' => 'Completed',
        'priority' => 'Normal',
        'parts_total' => 2000,
        'created_by' => $this->user->id,
    ]);
    $bank = Account::systemAccount('bank');
    $main = Account::systemAccount('main');

    $this->post(route('jobs.invoice', $job), [
        'payments' => [['method' => 'Card', 'amount' => 2000, 'account_id' => $bank->id]],
    ])->assertSessionHasNoErrors();

    $payment = $job->fresh()->invoice->payments()->firstOrFail();
    $expense = Expense::findOrFail($payment->charge_expense_id);
    expect((float) $expense->amount)->toBe(50.0)
        ->and($bank->fresh()->balance)->toBe(1950.0);

    // Manual cancellation of the auto-created expense is blocked
    $this->post(route('finance-module.cancel', ['expense', $expense->id]), ['reason' => 'Try direct cancel'])
        ->assertStatus(422);

    // Deleting the payment hard-purges the charge expense and restores Bank to 0.00
    $this->delete(route('jobs.payments.destroy', [$job, $payment]))
        ->assertSessionHasNoErrors();

    expect(Payment::count())->toBe(0)
        ->and(Expense::withTrashed()->count())->toBe(0)
        ->and(AccountTransaction::count())->toBe(0)
        ->and($bank->fresh()->balance)->toBe(0.0);

    // Pay by Card again, then edit payment to Cash -> old fee reversed, Cash has 0 fee
    $this->post(route('jobs.dues.payment', $job), [
        'paid_at' => today()->toDateString(),
        'invoice_ids' => [$job->fresh()->invoice->id],
        'payments' => [['method' => 'Card', 'amount' => 2000, 'account_id' => $bank->id]],
    ])->assertSessionHasNoErrors();

    $card2 = $job->fresh()->invoice->payments()->firstOrFail();
    $exp2Id = $card2->charge_expense_id;
    expect($bank->fresh()->balance)->toBe(1950.0);

    $this->put(route('jobs.payments.update', [$job, $card2]), [
        'reason' => 'Customer actually paid Cash',
        'method' => 'Cash',
        'amount' => 2000,
        'account_id' => $main->id,
        'paid_at' => today()->toDateString(),
    ])->assertSessionHasNoErrors();

    expect(Expense::findOrFail($exp2Id)->status)->toBe('Cancelled')
        ->and($bank->fresh()->balance)->toBe(0.0);
});

test('spare parts cashier charge account option deducts fee from today open cashier drawer', function () {
    $this->post(route('system-settings.save'), [
        'group' => 'payment',
        'pm_card_charge_percent' => '2.5',
        'pm_card_charge_account' => 'spare_parts_cashier',
    ])->assertSessionHasNoErrors();

    expect(PaymentMethodCharges::chargeAccountType('Card'))->toBe('spare_parts_cashier');

    $customer = Customer::create(['name' => 'Cashier Fee Customer', 'phone' => '0775554455']);
    $store = Store::create(['name' => 'Cashier Fee Store', 'active' => true]);
    $product = Product::create(['code' => 'CF-002', 'name' => 'Air Filter', 'cost_price' => 1000, 'selling_price' => 2000, 'minimum_stock' => 1, 'unit' => 'pcs', 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $bank = Account::systemAccount('bank');

    $this->post(route('sales.parts.store'), [
        'customer_mode' => 'existing',
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'discount' => 0,
        'tax' => 0,
        'paid_amount' => 2000,
        'payment_method' => 'Card',
        'account_id' => $bank->id,
        'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 2000, 'discount' => 0]],
    ])->assertSessionHasNoErrors();

    $payment = Payment::firstOrFail();
    $expense = Expense::findOrFail($payment->charge_expense_id);
    $tx = AccountTransaction::where('reference_type', Expense::class)
        ->where('reference_id', $expense->id)
        ->firstOrFail();

    expect((float) $expense->amount)->toBe(50.0)
        ->and($expense->account_id)->toBe(Account::systemAccount('spare_parts')->id)
        ->and($expense->payment_method)->toBe('Cash')
        ->and($expense->funding_allocations)->toBe([['source' => 'spare_parts_cashier', 'amount' => '50.00']])
        ->and($tx->funding_source)->toBe('spare_parts_cashier')
        ->and($tx->payment_method)->toBe('Cash')
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(-50.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(2000.0);

    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_paid'])->toBe(50.0)
        ->and($summary['cash_in_hand'])->toBe(-50.0);
});
