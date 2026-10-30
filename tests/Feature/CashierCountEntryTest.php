<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 500]);
    Account::systemAccount('bank')->update(['opening_balance' => 9000]);
    $this->cashier = app(CashierService::class);
    $this->version = fn () => $this->cashier->fingerprint($this->cashier->summary(today()->toDateString()));
    $this->openCount = function ($amount = 200) {
        $this->postJson(route('cashier.open'), ['opening_balance' => $amount, 'separate_cash_balance' => 1])->assertOk();

        return CashierSession::active();
    };
});

it('shows opening and closing physical cash fields separately from account balances', function () {
    $this->get(route('cashier.opening-popup'))->assertOk()->assertSeeText('Physical cashier cash')->assertSee('name="opening_balance"', false);
    ($this->openCount)();
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Closing balance in cashier')->assertSeeText('Filled once from Main Account + Spare Parts closing balances, excluding Bank.')
        ->assertSeeText('Later changes to account balances or withdrawals will not overwrite this amount.')->assertSee('name="separate_cash_balance" value="1"', false);
});

it('saves an independently counted cashier amount and carries it to the next opening', function () {
    $session = ($this->openCount)();
    $data = ['closing_balance' => 123.45, 'owner_withdrawal' => 0, 'separate_cash_balance' => 1,
        'balances' => ['main' => 1000, 'spare_parts' => 500, 'bank' => 9000], 'balance_version' => ($this->version)()];
    $this->post(route('cashier.close', $session), $data)->assertSessionHasErrors('difference_reason');
    expect($session->fresh()->status)->toBe('open')->and(AccountTransaction::count())->toBe(0);
    $this->post(route('cashier.close', $session), $data + ['difference_reason' => 'Verified physical cash after account review'])->assertSessionHasNoErrors();
    expect((float) $session->fresh()->drawer_opening_balance)->toBe(200.0)->and((float) $session->fresh()->drawer_closing_balance)->toBe(123.45)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(123.45)->and(AccountTransaction::count())->toBe(0)
        ->and((float) Account::systemAccount('main')->balance)->toBe(1000.0)->and((float) Account::systemAccount('spare_parts')->balance)->toBe(500.0);
    $this->travel(1)->days();
    $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('suggestedOpening', 123.45);
});

it('retains a manually entered count when another transaction makes the submitted version stale', function () {
    $session = ($this->openCount)();
    $version = ($this->version)();
    app(AccountService::class)->recordTransaction('main', 'income', 10, 'New cash receipt', paymentMethod: 'Cash');
    $this->post(route('cashier.close', $session), ['closing_balance' => 175.25, 'owner_withdrawal' => 0,
        'separate_cash_balance' => 1, 'balance_version' => $version])->assertSessionHasErrors('balance_version');
    $this->get(route('cashier.index'))->assertOk()->assertSee('175.25');
    expect($session->fresh()->status)->toBe('open')->and($session->fresh()->drawer_closing_balance)->toBeNull();
});

it('lets the current form close a legacy session using a separately counted cashier amount', function () {
    $session = $this->cashier->open(1500, null);
    expect($session->drawer_opening_balance)->toBeNull();
    $this->post(route('cashier.close', $session), ['closing_balance' => 200, 'owner_withdrawal' => 0, 'separate_cash_balance' => 1,
        'balances' => ['main' => 1000, 'spare_parts' => 500, 'bank' => 9000], 'difference_reason' => 'Separate physical drawer verified',
        'balance_version' => ($this->version)()])->assertSessionHasNoErrors();
    expect((float) $session->fresh()->drawer_opening_balance)->toBe(1500.0)->and((float) $session->fresh()->drawer_closing_balance)->toBe(200.0)
        ->and($this->cashier->summary(today()->toDateString())['cash_in_hand'])->toBe(200.0)
        ->and(AccountTransaction::count())->toBe(0)->and((float) Account::systemAccount('main')->balance)->toBe(1000.0);
});
