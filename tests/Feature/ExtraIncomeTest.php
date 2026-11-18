<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Models\Expense;
use App\Models\ExtraIncome;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountTransactionSourceService;
use App\Services\CashierService;
use App\Services\OutgoingFundingService;

test('sidebar navigation displays extra money links under expenses and drawings', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('Extra Money')
        ->assertSeeText('Add Extra Money')
        ->assertSee(route('extra-money.index'), false)
        ->assertSee(route('extra-money.create'), false);
});

test('extra money into main account saves as income not expense and updates profit and loss', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withOpenCashier();

    $beforeSummary = app(CashierService::class)->summary(today()->toDateString());
    $beforeBalances = app(OutgoingFundingService::class)->balances(today()->toDateString());

    $response = $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Scrap parts sale',
        'source_name' => 'Metal Buyer',
        'destination_account' => 'main',
        'amount' => '1500.00',
        'payment_method' => 'Cash',
        'reference_number' => 'SCRAP-01',
        'note' => 'Sold old scrap parts',
    ]);

    $record = ExtraIncome::firstOrFail();
    $response->assertRedirect(route('extra-money.show', $record));

    expect($record->destination_account)->toBe('main')
        ->and((float) $record->amount)->toBe(1500.0)
        ->and(Expense::count())->toBe(0);

    $tx = AccountTransaction::where('reference_type', $record->getMorphClass())
        ->where('reference_id', $record->id)
        ->firstOrFail();

    expect($tx->transaction_type)->toBe('income')
        ->and($tx->account_type)->toBe('main')
        ->and((float) $tx->credit)->toBe(1500.0)
        ->and((float) $tx->debit)->toBe(0.0)
        ->and($tx->funding_source)->toBe('main')
        ->and($tx->category)->toBe('extra_income');

    $afterSummary = app(CashierService::class)->summary(today()->toDateString());
    $afterBalances = app(OutgoingFundingService::class)->balances(today()->toDateString());

    expect($afterBalances['main'])->toBe(round($beforeBalances['main'] + 1500.0, 2))
        ->and($afterSummary['cash_in_hand'])->toBe($beforeSummary['cash_in_hand'])
        ->and($afterSummary['cashier_funds']['main'])->toBe($beforeSummary['cashier_funds']['main'])
        ->and($afterSummary['cashier_cycle']['income'])->toBe($beforeSummary['cashier_cycle']['income']);

    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertViewHas('otherIncome', 1500.0)
        ->assertViewHas('totalExpenses', 0)
        ->assertViewHas('netProfit', 1500.0);

    expect(app(AccountTransactionSourceService::class)->url($tx, $user))
        ->toBe(route('extra-money.show', $record));
});

test('extra money into spare parts account credits carried balance outside cashier', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withOpenCashier();

    $beforeSummary = app(CashierService::class)->summary(today()->toDateString());
    $beforeBalances = app(OutgoingFundingService::class)->balances(today()->toDateString());

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Supplier rebate',
        'destination_account' => 'spare_parts',
        'amount' => '2250.50',
        'payment_method' => 'Cash',
    ])->assertRedirect();

    $record = ExtraIncome::firstOrFail();
    $tx = AccountTransaction::where('reference_type', $record->getMorphClass())
        ->where('reference_id', $record->id)
        ->firstOrFail();

    expect($tx->transaction_type)->toBe('income')
        ->and($tx->account_type)->toBe('spare_parts')
        ->and($tx->funding_source)->toBe('spare_parts')
        ->and((float) $tx->credit)->toBe(2250.50)
        ->and((float) $tx->debit)->toBe(0.0);

    $afterSummary = app(CashierService::class)->summary(today()->toDateString());
    $afterBalances = app(OutgoingFundingService::class)->balances(today()->toDateString());

    expect($afterBalances['spare_parts'])->toBe(round($beforeBalances['spare_parts'] + 2250.50, 2))
        ->and($afterBalances['spare_parts_cashier'])->toBe($beforeBalances['spare_parts_cashier'])
        ->and($afterSummary['cash_in_hand'])->toBe($beforeSummary['cash_in_hand']);
});

test('spare parts cashier is blocked when cashier is closed and allowed when open', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $closedOptions = $this->getJson(route('extra-money.options', ['date' => today()->toDateString()]))
        ->assertOk()
        ->json();

    $closedCashierOption = collect($closedOptions['options'])->firstWhere('value', 'spare_parts_cashier');
    expect($closedOptions['cashier_open'])->toBeFalse()
        ->and($closedCashierOption['disabled'])->toBeTrue();

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Cashier extra cash',
        'destination_account' => 'spare_parts_cashier',
        'amount' => '800.00',
        'payment_method' => 'Cash',
    ])->assertSessionHasErrors('destination_account');

    expect(ExtraIncome::count())->toBe(0);

    // Now open the cashier for today
    $this->withOpenCashier();

    $openOptions = $this->getJson(route('extra-money.options', ['date' => today()->toDateString()]))
        ->assertOk()
        ->json();

    $openCashierOption = collect($openOptions['options'])->firstWhere('value', 'spare_parts_cashier');
    expect($openOptions['cashier_open'])->toBeTrue()
        ->and($openCashierOption['disabled'])->toBeFalse();

    $beforeSummary = app(CashierService::class)->summary(today()->toDateString());
    $beforeBalances = app(OutgoingFundingService::class)->balances(today()->toDateString());

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Cashier extra cash',
        'destination_account' => 'spare_parts_cashier',
        'amount' => '800.00',
        'payment_method' => 'Cash',
    ])->assertRedirect();

    $record = ExtraIncome::firstOrFail();
    $tx = AccountTransaction::where('reference_type', $record->getMorphClass())
        ->where('reference_id', $record->id)
        ->firstOrFail();

    expect($tx->transaction_type)->toBe('income')
        ->and($tx->account_type)->toBe('spare_parts')
        ->and($tx->funding_source)->toBe('spare_parts_cashier')
        ->and((float) $tx->credit)->toBe(800.0)
        ->and((float) $tx->debit)->toBe(0.0);

    $afterSummary = app(CashierService::class)->summary(today()->toDateString());
    $afterBalances = app(OutgoingFundingService::class)->balances(today()->toDateString());

    expect($afterBalances['spare_parts_cashier'])->toBe(round($beforeBalances['spare_parts_cashier'] + 800.0, 2))
        ->and($afterBalances['spare_parts'])->toBe($beforeBalances['spare_parts'])
        ->and($afterSummary['cashier_funds']['spare_parts'])->toBe(round($beforeSummary['cashier_funds']['spare_parts'] + 800.0, 2))
        ->and($afterSummary['cash_in_hand'])->toBe(round($beforeSummary['cash_in_hand'] + 800.0, 2));
});

test('extra money into bank account saves as bank income and validates cash-only accounts', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Invalid method on main',
        'destination_account' => 'main',
        'amount' => '500.00',
        'payment_method' => 'Bank Transfer',
    ])->assertSessionHasErrors('payment_method');

    $beforeBank = Account::systemAccount('bank')->balance;

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Bank interest bonus',
        'destination_account' => 'bank',
        'amount' => '3400.00',
        'payment_method' => 'Bank Transfer',
        'reference_number' => 'BNK-99',
    ])->assertRedirect();

    $record = ExtraIncome::firstOrFail();
    $tx = AccountTransaction::where('reference_type', $record->getMorphClass())
        ->where('reference_id', $record->id)
        ->firstOrFail();

    expect($tx->transaction_type)->toBe('income')
        ->and($tx->account_type)->toBe('bank')
        ->and((float) $tx->credit)->toBe(3400.0)
        ->and((float) $tx->debit)->toBe(0.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(round($beforeBank + 3400.0, 2));

    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertViewHas('otherIncome', 3400.0)
        ->assertViewHas('netProfit', 3400.0);
});

test('editing and deleting extra money reverses old income and updates balances accurately', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Workshop commission',
        'destination_account' => 'main',
        'amount' => '1000.00',
        'payment_method' => 'Cash',
    ])->assertRedirect();

    $record = ExtraIncome::firstOrFail();
    expect(Account::systemAccount('main')->balance)->toBe(1000.0);

    $this->put(route('extra-money.update', $record), [
        'income_date' => today()->toDateString(),
        'title' => 'Workshop commission corrected',
        'destination_account' => 'bank',
        'amount' => '1250.00',
        'payment_method' => 'Bank Transfer',
        'reason' => 'Received directly into bank account instead of cash',
        'record_version' => $record->version(),
    ])->assertRedirect(route('extra-money.show', $record));

    $record->refresh();
    expect(Account::systemAccount('main')->balance)->toBe(0.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(1250.0);

    $this->delete(route('extra-money.destroy', $record), [
        'reason' => 'Duplicate income entry removed',
        'record_version' => $record->version(),
    ])->assertRedirect(route('extra-money.index'));

    expect(ExtraIncome::count())->toBe(0)
        ->and(ExtraIncome::withTrashed()->count())->toBe(1)
        ->and(Account::systemAccount('bank')->balance)->toBe(0.0);

    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertViewHas('otherIncome', 0.0)
        ->assertViewHas('netProfit', 0.0);
});

test('users without extra income permissions cannot access extra money routes', function () {
    $role = Role::create(['name' => 'Limited Staff', 'permissions' => ['dashboard.view']]);
    $user = User::factory()->create(['role_id' => $role->id]);

    $this->actingAs($user)->get(route('extra-money.index'))->assertForbidden();
    $this->actingAs($user)->get(route('extra-money.create'))->assertForbidden();
    $this->actingAs($user)->post(route('extra-money.store'), [])->assertForbidden();
});

test('extra money form includes cheque details fields and conditions and validates required cheque info', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('extra-money.create'))
        ->assertOk()
        ->assertSeeText('Cheque Details & Conditions')
        ->assertSee('name="cheque_date"', false)
        ->assertSee('name="cheque_number"', false)
        ->assertSee('name="bank_name"', false)
        ->assertSee('name="branch"', false)
        ->assertSee('name="cheque_status"', false)
        ->assertSee('name="realized_date"', false)
        ->assertSeeText('Pending / Hold');

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Cheque without details',
        'destination_account' => 'bank',
        'amount' => '2500.00',
        'payment_method' => 'Cheque',
    ])->assertSessionHasErrors(['cheque_date', 'cheque_number', 'bank_name', 'cheque_status']);

    // Post-dated cheque cannot be marked Passed immediately
    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Post-dated cheque',
        'destination_account' => 'bank',
        'amount' => '2500.00',
        'payment_method' => 'Cheque',
        'cheque_date' => today()->addDays(5)->toDateString(),
        'cheque_number' => 'CHQ-FUTURE-1',
        'bank_name' => 'Commercial Bank',
        'cheque_status' => 'Cleared',
        'realized_date' => today()->toDateString(),
    ])->assertSessionHasErrors('realized_date');

    expect(ExtraIncome::count())->toBe(0)
        ->and(Cheque::count())->toBe(0);
});

test('cheque extra money stays on hold until passed in cheque management and reverses if returned', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Insurance rebate cheque',
        'source_name' => 'Ceylinco Insurance',
        'destination_account' => 'bank',
        'amount' => '4500.00',
        'payment_method' => 'Cheque',
        'cheque_date' => today()->toDateString(),
        'cheque_number' => 'CHQ-778899',
        'bank_name' => 'Sampath Bank',
        'branch' => 'Kandy',
        'cheque_status' => 'Pending',
        'note' => 'Annual rebate',
    ])->assertRedirect();

    $record = ExtraIncome::with('cheque')->firstOrFail();
    $cheque = $record->cheque;

    expect($record->status)->toBe('Hold')
        ->and($cheque)->not->toBeNull()
        ->and($cheque->cheque_type)->toBe('Received')
        ->and($cheque->status)->toBe('Pending')
        ->and($cheque->cheque_number)->toBe('CHQ-778899')
        ->and($cheque->bank_name)->toBe('Sampath Bank')
        ->and($cheque->branch)->toBe('Kandy')
        ->and($cheque->party_name)->toBe('Ceylinco Insurance')
        ->and(Account::systemAccount('bank')->balance)->toBe(0.0)
        ->and(AccountTransaction::count())->toBe(0);

    // Held cheque must not count in Profit & Loss yet
    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertViewHas('otherIncome', 0.0);

    // Both detail pages show the link and cheque details
    $this->get(route('extra-money.show', $record))
        ->assertOk()
        ->assertSeeText('Cheque Details')
        ->assertSeeText('CHQ-778899')
        ->assertSeeText('Sampath Bank')
        ->assertSeeText('Kandy');

    $this->get(route('accounts.cheques.show', $cheque))
        ->assertOk()
        ->assertSeeText($record->number)
        ->assertSeeText('Extra Money (Income)');

    // Mark cheque Passed in Cheque Management
    $this->patch(route('accounts.cheques.status', $cheque), [
        'status' => 'Cleared',
        'realized_date' => today()->toDateString(),
    ])->assertRedirect();

    $record->refresh();
    $cheque->refresh();

    expect($record->status)->toBe('Completed')
        ->and($cheque->status)->toBe('Cleared')
        ->and(Account::systemAccount('bank')->balance)->toBe(4500.0)
        ->and(AccountTransaction::where('status', 'active')->count())->toBe(1);

    $tx = AccountTransaction::where('status', 'active')->firstOrFail();
    expect($tx->transaction_type)->toBe('income')
        ->and($tx->category)->toBe('extra_income')
        ->and((float) $tx->credit)->toBe(4500.0)
        ->and((float) $tx->debit)->toBe(0.0);

    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertViewHas('otherIncome', 4500.0)
        ->assertViewHas('netProfit', 4500.0);

    // Passed cheque cannot be deleted directly from Extra Money until reversed in Cheque Management
    $this->delete(route('extra-money.destroy', $record), [
        'reason' => 'Attempting direct delete on passed cheque',
        'record_version' => $record->version(),
    ])->assertSessionHasErrors('reason');

    // Return the cheque in Cheque Management -> reverses bank income and updates status
    $this->patch(route('accounts.cheques.status', $cheque), [
        'status' => 'Returned',
        'reason' => 'Insufficient funds in drawer bank account',
    ])->assertRedirect();

    $record->refresh();
    expect($record->status)->toBe('Returned')
        ->and(Account::systemAccount('bank')->balance)->toBe(0.0);

    $this->get(route('reports.profit-loss'))
        ->assertOk()
        ->assertViewHas('otherIncome', 0.0)
        ->assertViewHas('netProfit', 0.0);
});

test('extra money cheque marked passed immediately credits bank income and deleting a pending cheque cancels it', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Cleared cheque bonus',
        'destination_account' => 'bank',
        'amount' => '1800.00',
        'payment_method' => 'Cheque',
        'cheque_date' => today()->toDateString(),
        'cheque_number' => 'CHQ-PASS-01',
        'bank_name' => 'Bank of Ceylon',
        'cheque_status' => 'Cleared',
        'realized_date' => today()->toDateString(),
    ])->assertRedirect();

    $clearedRecord = ExtraIncome::with('cheque')->firstOrFail();
    expect($clearedRecord->status)->toBe('Completed')
        ->and($clearedRecord->cheque->status)->toBe('Cleared')
        ->and(Account::systemAccount('bank')->balance)->toBe(1800.0);

    // Create a pending cheque and delete it from Extra Money -> cancels the linked cheque cleanly
    $this->post(route('extra-money.store'), [
        'income_date' => today()->toDateString(),
        'title' => 'Pending cheque to cancel',
        'destination_account' => 'bank',
        'amount' => '900.00',
        'payment_method' => 'Cheque',
        'cheque_date' => today()->addDay()->toDateString(),
        'cheque_number' => 'CHQ-PEND-02',
        'bank_name' => 'HNB',
        'cheque_status' => 'Pending',
    ])->assertRedirect();

    $pendingRecord = ExtraIncome::with('cheque')->latest('id')->firstOrFail();
    $pendingCheque = $pendingRecord->cheque;
    expect($pendingRecord->status)->toBe('Hold')
        ->and(Account::systemAccount('bank')->balance)->toBe(1800.0);

    $this->delete(route('extra-money.destroy', $pendingRecord), [
        'reason' => 'Cheque cancelled by payer before deposit',
        'record_version' => $pendingRecord->version(),
    ])->assertRedirect(route('extra-money.index'));

    expect($pendingCheque->fresh()->status)->toBe('Cancelled')
        ->and(Account::systemAccount('bank')->balance)->toBe(1800.0);
});
