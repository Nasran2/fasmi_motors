<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->service = app(CashierService::class);
    $this->accounts = app(AccountService::class);
    Account::systemAccount('main')->update(['opening_balance' => 120281.57]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 1266018.43]);
    Account::systemAccount('bank')->update(['opening_balance' => 10000]);
    $this->allocation = ['main' => 3000, 'spare_parts' => 1665, 'washing' => 0]; // Synthetic confirmed split, never a live repair.
    $this->version = fn () => $this->service->fingerprint($this->service->summary(today()->toDateString()));
    $this->payload = fn () => ['opening_balance' => 4665, 'opening_balances' => $this->allocation,
        'opening_reason' => 'Verified physical cash allocation', 'balance_version' => ($this->version)()];
    $this->legacyOpening = function () {
        $session = CashierSession::create(['session_date' => today(), 'status' => 'open', 'opening_balance' => 4665,
            'cash_opening_balances' => ['main' => -1261353.43, 'spare_parts' => 1266018.43, 'washing' => 0],
            'opened_at' => now(), 'opened_by' => auth()->id()]);
        $this->accounts->recordTransaction('main', 'adjustment_decrease', 1381635, 'Legacy opening correction', $session, 'Cash', transactionDate: today()->startOfDay(), category: 'cashier_opening');

        return $session;
    };
});

test('custom opening posts confirmed changes per account without a negative main allocation', function () {
    $this->get(route('cashier.opening-popup'))->assertOk()->assertSee('name="opening_balances[main]"', false)
        ->assertSee('name="opening_balances[spare_parts]"', false)->assertSee('data-opening-total', false);
    $this->postJson(route('cashier.open'), ($this->payload)())->assertOk()->assertJsonPath('success', true);
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(4665.0)->and($summary['cash_accounts']['main']['opening'])->toBe(3000.0)
        ->and($summary['cash_accounts']['spare_parts']['opening'])->toBe(1665.0)->and($summary['cash_received'])->toBe(0.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(10000.0)
        ->and(AccountTransaction::where('category', 'cashier_opening')->count())->toBe(2);
    expect(CashierSession::active()->events()->sole()->details['cash_balances'])->toEqual($this->allocation);
});

test('a changed total without an explicit split never posts the whole difference into main', function () {
    $this->postJson(route('cashier.open'), ['opening_balance' => 4665, 'opening_reason' => 'Counted drawer'])
        ->assertUnprocessable()->assertJsonValidationErrors('opening_balances');
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(0)
        ->and(Account::systemAccount('main')->balance)->toBe(120281.57);
});

test('opening rejects mismatched negative or incomplete allocations before posting', function (array $allocation, string $error) {
    $payload = ($this->payload)();
    $payload['opening_balances'] = $allocation;
    $this->postJson(route('cashier.open'), $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
})->with([
    'mismatch' => [['main' => 3000, 'spare_parts' => 1660, 'washing' => 0], 'opening_balances'],
    'negative despite matching total' => [['main' => -1261353.43, 'spare_parts' => 1266018.43, 'washing' => 0], 'opening_balances.main'],
    'missing spare parts' => [['main' => 4665], 'opening_balances.spare_parts'],
    'bank is not physical cash' => [['main' => 3000, 'spare_parts' => 1665, 'bank' => 500], 'opening_balances'],
]);

test('redistributing opening cash with an unchanged total requires a reason', function () {
    Account::systemAccount('main')->update(['opening_balance' => 3000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 1665]);
    $payload = ($this->payload)();
    $payload['opening_balances'] = ['main' => 3100, 'spare_parts' => 1565, 'washing' => 0];
    unset($payload['opening_reason']);
    $this->postJson(route('cashier.open'), $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_reason');
    expect(AccountTransaction::count())->toBe(0);
});

test('opening rejects stale snapshots without posting or starting the cashier', function () {
    $payload = ($this->payload)();
    $this->accounts->recordTransaction('main', 'income', 10, 'New cash receipt', paymentMethod: 'Cash');
    $this->postJson(route('cashier.open'), $payload)->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(1);
});

test('negative automatic allocations require confirmation even when the total matches', function () {
    Account::systemAccount('main')->update(['opening_balance' => -1261353.43]);
    $this->postJson(route('cashier.open'), ['opening_balance' => 4665])->assertUnprocessable()->assertJsonValidationErrors('opening_balances.main');
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});

test('correcting a legacy opening preserves received cash bank and the original audit trail', function () {
    $session = ($this->legacyOpening)();
    $original = AccountTransaction::sole();
    foreach (['main' => 3500, 'spare_parts' => 10900, 'washing' => 500] as $type => $amount) {
        $this->accounts->recordTransaction($type, 'income', $amount, 'Today actual cash receipt', paymentMethod: 'Cash');
    }
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Edit Opening Balances');
    $this->get(route('cashier.opening-popup', ['edit' => 1]))->assertOk()->assertSeeText('Edit Opening Balances')
        ->assertViewHas('suggestedOpening', 4665.0)->assertViewHas('openingBalances', ['main' => -1261353.43, 'spare_parts' => 1266018.43, 'washing' => 0.0, 'bank' => 10000.0]);
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)())->assertOk();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(4665.0)->and($summary['cash_received'])->toBe(14900.0)
        ->and($summary['cash_in_hand'])->toBe(19565.0)->and($summary['cash_paid'])->toBe(0.0)->and($summary['cash_other_in'])->toBe(0.0)
        ->and($summary['cash_accounts']['main']['closing'])->toBe(6500.0)->and($summary['cash_accounts']['spare_parts']['closing'])->toBe(12565.0)
        ->and(Account::systemAccount('main')->balance)->toBe(6500.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(12565.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(10000.0)->and($original->fresh()->status)->toBe($original->status)
        ->and((float) $original->fresh()->debit)->toBe(1381635.0)->and(AccountTransaction::count())->toBe(6);
    $event = $session->events()->sole();
    expect($event->action)->toBe('opening_corrected')->and($event->details['previous_opening']['main'])->toBe(-1261353.43)
        ->and($event->details['cash_balances'])->toEqual($this->allocation);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Opening corrected')->assertSeeText('Verified physical cash allocation');
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)())->assertOk();
    expect(AccountTransaction::count())->toBe(6)->and($session->events()->count())->toBe(1);
});

test('opening correction rejects stale mismatched and unexplained submissions atomically', function () {
    $session = ($this->legacyOpening)();
    $payload = ($this->payload)();
    $payload['opening_balances']['main'] = 3001;
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_balances');
    $payload = ($this->payload)();
    unset($payload['opening_reason']);
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_reason');
    $payload = ($this->payload)();
    $this->accounts->recordTransaction('bank', 'income', 50, 'Bank changed', paymentMethod: 'Bank Transfer');
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    expect(AccountTransaction::count())->toBe(2)->and($session->fresh()->cash_opening_balances['main'])->toBe(-1261353.43)
        ->and($session->events()->count())->toBe(0);
});

test('reopening corrections preserve start of day cash and do not repeat receipts', function () {
    Account::systemAccount('main')->update(['opening_balance' => 3000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 1665]);
    $session = $this->service->open(4665, null);
    $this->accounts->recordTransaction('main', 'income', 200, 'Cash receipt', paymentMethod: 'Cash');
    $this->post(route('cashier.close', $session), ['closing_balance' => 4865, 'owner_withdrawal' => 0, 'balance_version' => ($this->version)()])->assertSessionHasNoErrors();
    $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('openingBalances', ['main' => 3200.0, 'spare_parts' => 1665.0, 'washing' => 0.0, 'bank' => 10000.0]);
    $this->travel(1)->hours();
    $this->postJson(route('cashier.open'), ['opening_balance' => 4855, 'opening_balances' => ['main' => 3190, 'spare_parts' => 1665, 'washing' => 0],
        'opening_reason' => 'Counted short ten', 'balance_version' => ($this->version)()])->assertOk();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(4665.0)->and($summary['cash_in_hand'])->toBe(4855.0)->and($summary['cash_received'])->toBe(200.0)
        ->and($summary['cash_paid'])->toBe(10.0)->and((float) $session->fresh()->opening_balance)->toBe(4665.0);
    $adjustment = AccountTransaction::where('category', 'cashier_cash_reconciliation')->sole();
    expect($adjustment->transaction_date->format('Y-m-d H:i:s'))->toBe(now()->format('Y-m-d H:i:s'));
});
