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
    Account::systemAccount('main')->update(['opening_balance' => 74838]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 12000]);
    Account::systemAccount('bank')->update(['opening_balance' => 300]);
    $this->service = app(CashierService::class);
    $this->accounts = app(AccountService::class);
    $this->balances = ['main' => 74838, 'spare_parts' => 12000, 'washing' => 0, 'bank' => 300];
    $this->version = fn ($date = null) => $this->service->fingerprint($this->service->summary($date ?? today()->toDateString()));
    $this->openBank = function (float $bank = 300) {
        $balances = array_replace($this->balances, ['bank' => $bank]);
        $this->postJson(route('cashier.open'), ['opening_balance' => 5000, 'opening_balances' => $balances,
            'opening_reason' => 'Confirmed separate bank statement balance', 'separate_cash_balance' => 1])->assertOk();

        return CashierSession::active();
    };
    $this->closeDay = function () {
        $summary = $this->service->summary(today()->toDateString());
        $this->service->close(CashierSession::active(), ['closing_balance' => $summary['cash_in_hand'], 'owner_withdrawal' => 0,
            'balance_version' => $this->service->fingerprint($summary)]);
    };
});

test('opening and editing popups show an editable automatically filled bank opening balance', function () {
    $this->accounts->recordTransaction('bank', 'income', 500, 'Previous bank receipt', paymentMethod: 'Bank Transfer', transactionDate: today()->subDay());
    $this->get(route('cashier.opening-popup'))->assertOk()->assertSeeText('Bank Account opening balance')
        ->assertSee('name="opening_balances[bank]"', false)->assertViewHas('openingBalances', fn ($balances) => $balances['bank'] === 800.0);
    $session = ($this->openBank)(800);
    $this->get(route('cashier.opening-popup', ['edit' => 1]))->assertOk()->assertViewHas('openingBalances', fn ($balances) => $balances['bank'] === 800.0);
    expect((float) $session->account_opening_balances['bank'])->toBe(800.0)->and((float) $session->drawer_opening_balance)->toBe(5000.0);
});

test('a changed bank opening requires a reason and adjusts only the bank ledger', function () {
    $balances = array_replace($this->balances, ['bank' => 1200]);
    $payload = ['opening_balance' => 5000, 'opening_balances' => $balances, 'separate_cash_balance' => 1];
    $this->postJson(route('cashier.open'), $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_reason');
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
    $this->postJson(route('cashier.open'), $payload + ['opening_reason' => 'Confirmed bank statement'])->assertOk();
    $entry = AccountTransaction::sole();
    expect($entry->account_id)->toBe(Account::systemAccount('bank')->id)->and((float) $entry->credit)->toBe(900.0)
        ->and($entry->category)->toBe('cashier_account_opening')->and($entry->payment_method)->toBe('Adjustment')
        ->and(Account::systemAccount('main')->balance)->toBe(74838.0)->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5000.0)
        ->and($this->service->summary(today()->toDateString())['bank_received'])->toBe(0.0);
    expect(CashierSession::active()->events()->sole()->details['bank_balance'])->toEqual(1200);
});

test('bank-only opening edits preserve cashier cash cash receipts and bank receipt totals', function (float $bank) {
    $session = ($this->openBank)();
    $this->accounts->recordTransaction('main', 'income', 100, 'Cash receipt', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('bank', 'income', 200, 'Bank receipt', paymentMethod: 'Bank Transfer');
    $collections = $this->service->summary(today()->toDateString())['collections']->toJson();
    $payload = ['opening_balance' => 5000, 'opening_balances' => array_replace($this->balances, ['bank' => $bank]),
        'opening_reason' => 'Verified opening bank amount', 'recalculate_today' => 1, 'balance_version' => ($this->version)()];
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertOk();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['accounts']['bank']['opening'])->toBe($bank)->and($summary['accounts']['bank']['closing'])->toBe($bank + 200)
        ->and($summary['cash_opening'])->toBe(5000.0)->and($summary['cash_in_hand'])->toBe(5100.0)
        ->and($summary['cash_received'])->toBe(100.0)->and($summary['bank_received'])->toBe(200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(74938.0)->and($summary['collections']->toJson())->toBe($collections);
    $event = $session->events()->where('action', 'opening_corrected')->sole();
    expect($event->details['previous_opening']['bank'])->toEqual(300)->and((float) $event->details['bank_balance'])->toBe($bank);
    $count = AccountTransaction::count();
    $payload['balance_version'] = ($this->version)();
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertOk();
    expect(AccountTransaction::count())->toBe($count);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Bank: Rs.');
})->with([800.0, -50.0]);

test('the confirmed bank balance carries forward separately from physical cash', function () {
    ($this->openBank)(4000);
    ($this->closeDay)();
    $this->travel(1)->days();
    $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('suggestedOpening', 5000.0)
        ->assertViewHas('openingBalances', fn ($balances) => $balances['bank'] === 4000.0);
    expect($this->service->suggestedOpening())->toBe(5000.0)->and(Account::systemAccount('bank')->balance)->toBe(4000.0);
});

test('same-day reopening accepts current bank figures without changing the original bank opening', function () {
    $session = ($this->openBank)();
    $this->accounts->recordTransaction('bank', 'income', 200, 'Bank receipt', paymentMethod: 'Bank Transfer');
    ($this->closeDay)();
    $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('openingBalances', fn ($balances) => $balances['bank'] === 500.0);
    $this->postJson(route('cashier.open'), ['opening_balance' => 5000, 'opening_balances' => array_replace($this->balances, ['bank' => 600]),
        'opening_reason' => 'Verified current bank statement'])->assertOk();
    expect((float) $session->fresh()->account_opening_balances['bank'])->toBe(300.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(600.0)->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5000.0)
        ->and($this->service->summary(today()->toDateString())['bank_received'])->toBe(200.0);
});

test('historical bank corrections retain actual closing figures and later cash counts', function () {
    $old = ($this->openBank)();
    ($this->closeDay)();
    $this->travel(1)->days();
    $later = ($this->openBank)();
    $laterBefore = $later->fresh()->toArray();
    $this->postJson(route('cashier.opening-balances', $old), ['opening_balance' => 5000,
        'opening_balances' => array_replace($this->balances, ['bank' => 800]), 'opening_reason' => 'Corrected old bank opening',
        'balance_version' => ($this->version)($old->session_date->toDateString())])->assertOk();
    $closing = DailyClosing::whereDate('closing_date', $old->session_date)->sole();
    expect((float) $closing->bank_opening_balance)->toBe(800.0)->and((float) $closing->bank_closing_balance)->toBe(800.0)
        ->and((float) $closing->bank_actual_balance)->toBe(300.0)->and((float) $closing->bank_difference)->toBe(-500.0)
        ->and((float) $closing->cash_actual_balance)->toBe(5000.0)->and(Account::systemAccount('bank')->balance)->toBe(300.0)
        ->and($later->fresh()->toArray())->toBe($laterBefore);
});

test('invalid bank values are rejected without posting balances', function ($bank) {
    $this->postJson(route('cashier.open'), ['opening_balance' => 5000, 'opening_balances' => array_replace($this->balances, ['bank' => $bank]),
        'opening_reason' => 'Reviewing bank amount', 'separate_cash_balance' => 1])->assertUnprocessable()->assertJsonValidationErrors('opening_balances.bank');
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
})->with(['bad', '1.234', null, '1000000000000']);

test('bank changes invalidate stale opening edit forms without changing cashier cash', function () {
    $session = ($this->openBank)();
    $version = ($this->version)();
    $this->accounts->recordTransaction('bank', 'income', 50, 'Bank changed while form open', paymentMethod: 'Bank Transfer');
    $this->postJson(route('cashier.opening-balances', $session), ['opening_balance' => 5000,
        'opening_balances' => array_replace($this->balances, ['bank' => 800]), 'opening_reason' => 'Old bank form', 'balance_version' => $version])
        ->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    expect(AccountTransaction::count())->toBe(1)->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5000.0);
});

test('bank-only edits retain a counted drawer variance even with today recalculation selected', function () {
    $session = ($this->openBank)();
    $this->service->close($session, ['closing_balance' => 4900, 'owner_withdrawal' => 0,
        'difference_reason' => 'Counted cashier short by one hundred', 'balance_version' => ($this->version)()]);
    $this->postJson(route('cashier.open'), ['opening_balance' => 4900])->assertOk();
    $this->postJson(route('cashier.opening-balances', $session), ['opening_balance' => 5000,
        'opening_balances' => array_replace($this->balances, ['bank' => 800]), 'recalculate_today' => 1,
        'opening_reason' => 'Corrected only the bank statement balance', 'balance_version' => ($this->version)()])->assertOk();
    expect(Account::systemAccount('bank')->balance)->toBe(800.0)
        ->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(4900.0)
        ->and((float) $session->fresh()->drawer_adjustment)->toBe(-100.0)
        ->and((float) $session->fresh()->drawer_closing_balance)->toBe(4900.0)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(4900.0);
});
