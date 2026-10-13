<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\User;
use App\Observers\WorkshopSmsObserver;
use App\Services\OpeningBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->customerData = ['name' => 'Opening Customer', 'phone' => '0776000090', 'customer_type' => 'Normal Customer', 'active' => 1];
});

test('customer creation records dated opening balance with or without a bike', function (bool $withBike) {
    $payload = $this->customerData + ['opening_balance' => '1234.56', 'opening_balance_date' => today()->subDays(7)->toDateString()];
    if ($withBike) {
        $payload += ['bike_number' => 'OP-CUSTOMER-100', 'bike_model' => 'Honda Dio'];
    }
    $this->post(route('customers.store'), $payload)->assertSessionHasNoErrors();
    $customer = Customer::sole();
    $opening = Invoice::sole();
    expect($opening->invoice_type)->toBe('Opening Due')->and($opening->customer_id)->toBe($customer->id)
        ->and($opening->bike_id)->toBe($withBike ? $customer->bikes()->sole()->id : null)
        ->and($opening->created_at->toDateString())->toBe($payload['opening_balance_date'])
        ->and((float) $opening->due_amount)->toBe(1234.56)->and(AccountTransaction::count())->toBe(0);
    $this->get(route('customers.index'))->assertOk()->assertSee('data-customer-id="'.$customer->id.'"', false)->assertSeeText('1,234.56');
    $this->get(route('customers.show', $customer))->assertOk()->assertSeeText('1,234.56');
    if ($withBike) {
        $this->get(route('customers.bikes.index', ['filter' => 'due']))->assertOk()->assertSeeText('OP-CUSTOMER-100')->assertSeeText('1,234.56');
    }
})->with([false, true]);

test('zero opening balance creates no due or pay button', function () {
    $this->post(route('customers.store'), $this->customerData + ['opening_balance' => 0])->assertSessionHasNoErrors();
    $customer = Customer::sole();
    expect(Invoice::count())->toBe(0);
    $this->get(route('customers.index'))->assertOk()->assertDontSee('data-customer-id="'.$customer->id.'"', false);
});

test('invalid opening balance inputs do not create a customer', function ($amount, $date, $field) {
    $this->post(route('customers.store'), $this->customerData + ['opening_balance' => $amount, 'opening_balance_date' => $date])->assertSessionHasErrors($field);
    expect(Customer::count())->toBe(0)->and(Invoice::count())->toBe(0);
})->with([
    [-10, null, 'opening_balance'], ['1.234', null, 'opening_balance'],
    [1000000000000, null, 'opening_balance'], [100, '2099-01-01', 'opening_balance_date'],
    [100, 'not-a-date', 'opening_balance_date'],
]);

test('an intake saves the opening balance date and does not repeat a previously recorded balance', function () {
    $payload = ['bike_number' => 'OP-INTAKE-100', 'bike_model' => 'Yamaha', 'phone' => '0776000091', 'complaint' => 'Service'];
    $this->post(route('intakes.store'), $payload + ['opening_balance' => 1500, 'opening_balance_date' => today()->subDays(4)->toDateString()])->assertSessionHasNoErrors();
    $opening = Invoice::sole();
    expect($opening->invoice_type)->toBe('Opening Due')->and($opening->bike_id)->toBe(BikeIntake::sole()->bike_id)
        ->and($opening->created_at->toDateString())->toBe(today()->subDays(4)->toDateString());
    $this->post(route('intakes.store'), $payload)->assertSessionHasNoErrors();
    expect(Invoice::count())->toBe(1)->and(BikeIntake::count())->toBe(2);
    $this->get(route('intakes.create'))->assertOk()->assertSeeText('Add opening balance (previous / old balance)')->assertSee('name="opening_balance_date"', false);
});

test('customer opening balance defaults to today and duplicate submission cannot record twice', function () {
    $payload = $this->customerData + ['opening_balance' => 500, '_submission_token' => (string) Str::uuid()];
    $this->post(route('customers.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('customers.store'), $payload)->assertRedirect();
    expect(Customer::count())->toBe(1)->and(Invoice::count())->toBe(1)->and(Invoice::sole()->created_at->isToday())->toBeTrue();
});

test('customer list excludes cancelled quotation and other shop balances from payable dues', function () {
    $customer = Customer::create($this->customerData);
    $base = ['customer_id' => $customer->id, 'subtotal' => 100, 'total' => 100, 'due_amount' => 100, 'public_token' => Str::random(48), 'status' => 'Unpaid', 'created_by' => auth()->id()];
    foreach ([['Opening Due', 'Cancelled', 'workshop'], ['Quotation', 'Draft', 'workshop'], ['Opening Due', 'Unpaid', 'helmet']] as $i => [$type, $status, $shop]) {
        Invoice::create(array_replace($base, ['number' => 'INV-EXCLUDE-'.$i, 'public_token' => Str::random(48), 'invoice_type' => $type, 'status' => $status, 'shop_type' => $shop]));
    }
    $this->get(route('customers.index'))->assertOk()->assertDontSee('data-customer-id="'.$customer->id.'"', false);
    $this->get(route('customers.index', ['filter' => 'due']))->assertOk()->assertSeeText('Your customer register is ready');
});

test('customer collection is partial and allocates opening balance before later job bills', function () {
    $customer = Customer::create($this->customerData);
    $opening = app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 1000, 'opening_balance_date' => today()->subDays(5)->toDateString()]);
    $later = Invoice::create(['number' => 'INV-LATER', 'public_token' => Str::random(48), 'customer_id' => $customer->id, 'invoice_type' => 'Job Card Bill', 'subtotal' => 600, 'total' => 600, 'parts_amount' => 300, 'due_amount' => 600, 'status' => 'Unpaid', 'created_by' => auth()->id()]);
    $url = route('customers.payment', $customer);
    $row = ['method' => 'Cash', 'amount' => 700, 'account_id' => Account::systemAccount('main')->id];
    $date = today()->subDay()->toDateString();
    $this->post($url, ['paid_at' => $date, 'payments' => [$row]])->assertSessionHasNoErrors();
    expect((float) $opening->fresh()->due_amount)->toBe(300.0)->and($opening->fresh()->status)->toBe('Partially Paid')
        ->and((float) $later->fresh()->due_amount)->toBe(600.0)->and(Account::systemAccount('main')->balance)->toBe(700.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0)
        ->and($opening->payments()->sole()->paid_at->toDateString())->toBe($date);
    $this->post($url, ['paid_at' => $date, 'payments' => [array_replace($row, ['amount' => 900])]])->assertSessionHasNoErrors();
    expect($opening->fresh()->status)->toBe('Paid')->and($later->fresh()->status)->toBe('Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(1300.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(300.0);
    $this->get(route('customers.index'))->assertOk()->assertDontSee('data-customer-id="'.$customer->id.'"', false);
});

test('customer cheque spans opening balances and remains held until cleared or returned', function () {
    $customer = Customer::create($this->customerData);
    $opening = app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 1000]);
    $second = app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 500]);
    $this->post(route('customers.payment', $customer), ['paid_at' => today()->toDateString(), 'payments' => [[
        'method' => 'Cheque', 'amount' => 1500, 'account_id' => Account::systemAccount('bank')->id,
        'cheque_date' => today()->toDateString(), 'cheque_number' => 'OP-CUST-CHEQUE', 'bank_name' => 'Test Bank',
    ]]])->assertSessionHasNoErrors();
    $cheque = $opening->payments()->sole()->cheque;
    expect($second->payments()->sole()->cheque_id)->toBe($cheque->id)->and(AccountTransaction::count())->toBe(0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(1500.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Cheque rejected by bank'])->assertSessionHasNoErrors();
    expect((float) $opening->fresh()->due_amount)->toBe(1000.0)->and((float) $second->fresh()->due_amount)->toBe(500.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0);
});

test('overpayment and future collection dates leave all customer receipts unchanged', function () {
    $customer = Customer::create($this->customerData);
    app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 100]);
    $payload = ['paid_at' => today()->toDateString(), 'payments' => [['method' => 'Cash', 'amount' => 101, 'account_id' => Account::systemAccount('main')->id]]];
    $this->post(route('customers.payment', $customer), $payload)->assertSessionHasErrors('amount');
    $this->post(route('customers.payment', $customer), array_replace($payload, ['paid_at' => today()->addDay()->toDateString()]))->assertSessionHasErrors('paid_at');
    expect(Invoice::sole()->payments()->count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});

test('customer viewers cannot see pay buttons or record collections', function () {
    $customer = Customer::create($this->customerData);
    app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 100]);
    $role = Role::create(['name' => 'Customer Viewer', 'permissions' => ['customer.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('customers.index'))->assertOk()->assertDontSee('data-customer-id="'.$customer->id.'"', false);
    $this->post(route('customers.payment', $customer), ['paid_at' => today()->toDateString(), 'payments' => []])->assertForbidden();
});

test('legacy job bills linked only through a bike are included in customer cheque collections', function () {
    $customer = Customer::create($this->customerData);
    $bike = $customer->bikes()->create(['number' => 'LEGACY-OP-100', 'model' => 'Honda']);
    $intake = BikeIntake::create(['intake_number' => 'IN-LEGACY-OP', 'bike_id' => $bike->id, 'complaint' => 'Repair', 'created_by' => auth()->id()]);
    $job = JobCard::create(['number' => 'JOB-LEGACY-OP', 'bike_intake_id' => $intake->id, 'status' => 'Billed', 'priority' => 'Normal', 'created_by' => auth()->id()]);
    $invoice = Invoice::create(['number' => 'INV-LEGACY-OP', 'job_card_id' => $job->id, 'invoice_type' => 'Job Card Bill', 'subtotal' => 300, 'total' => 300, 'due_amount' => 300, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_by' => auth()->id()]);
    $this->get(route('customers.index'))->assertOk()->assertSee('data-customer-id="'.$customer->id.'"', false);
    $this->post(route('customers.payment', $customer), ['paid_at' => today()->toDateString(), 'payments' => [[
        'method' => 'Cheque', 'amount' => 100, 'account_id' => Account::systemAccount('bank')->id,
        'cheque_date' => today()->toDateString(), 'cheque_number' => 'LEGACY-CHQ', 'bank_name' => 'Test Bank',
    ]]])->assertSessionHasNoErrors();
    expect($invoice->fresh()->customer_id)->toBe($customer->id)->and((float) $invoice->fresh()->hold_amount)->toBe(100.0);
    $this->get(route('customers.index'))->assertOk()->assertSee('data-hold="100.00"', false);
});

test('opening balance creation does not send a new bill notification', function (string $type) {
    DB::partialMock()->shouldReceive('afterCommit')->never();
    app(WorkshopSmsObserver::class)->created(new Invoice(['invoice_type' => $type]));
})->with(['Opening Due', 'Opening Balance']);
