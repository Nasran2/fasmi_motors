<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    Account::systemAccount('main')->update(['opening_balance' => 74838]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 12000]);
    Account::systemAccount('bank')->update(['opening_balance' => 300]);
    $this->service = app(CashierService::class);
    $this->accounts = app(AccountService::class);
    $this->balances = ['main' => 74838, 'spare_parts' => 12000, 'washing' => 0];
    $this->version = fn ($date = null) => $this->service->fingerprint($this->service->summary($date ?? today()->toDateString()));
    $this->openDrawer = function (float $cash = 5000) {
        $this->postJson(route('cashier.open'), ['opening_balance' => $cash, 'opening_balances' => $this->balances,
            'separate_cash_balance' => 1, 'balance_version' => ($this->version)()])->assertOk();

        return CashierSession::active();
    };
    $this->correctDrawer = function (CashierSession $session, float $cash, array $balances, bool $recalculate = true) {
        return $this->postJson(route('cashier.opening-balances', $session), ['opening_balance' => $cash, 'opening_balances' => $balances,
            'separate_cash_balance' => 1, 'recalculate_today' => $recalculate, 'opening_reason' => 'Verified physical drawer and separate accounts',
            'balance_version' => ($this->version)($session->session_date->toDateString())]);
    };
    $this->closeDrawer = function (float $cash, float $withdrawal = 0, ?string $reason = null, ?array $balances = null) {
        return $this->post(route('cashier.close', CashierSession::active()), array_filter(['closing_balance' => $cash, 'owner_withdrawal' => $withdrawal,
            'difference_reason' => $reason, 'balances' => $balances, 'balance_version' => ($this->version)()], fn ($value) => $value !== null));
    };
});

test('opening cashier cash is editable and independent of Main and Spare Parts balances', function () {
    $this->get(route('cashier.opening-popup'))->assertOk()->assertSeeText('Opening cash in cashier')
        ->assertDontSee('name="opening_balance" readonly', false)->assertSeeText('They do not need to match or add up.');
    $session = ($this->openDrawer)(5000);
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(5000.0)->and($summary['cash_in_hand'])->toBe(5000.0)
        ->and($summary['accounts']['main']['opening'])->toBe(74838.0)->and($summary['accounts']['spare_parts']['opening'])->toBe(12000.0)
        ->and((float) $session->drawer_opening_balance)->toBe(5000.0)->and(AccountTransaction::count())->toBe(0);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Account balances · separate from cashier cash')
        ->assertDontSeeText('Main and Spare Parts balances must add up to the counted closing balance.');
});

test('changing only physical opening cash does not change an account or post ledger money', function () {
    $session = ($this->openDrawer)();
    ($this->correctDrawer)($session, 2500, $this->balances)->assertOk();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(2500.0)
        ->and(Account::systemAccount('main')->balance)->toBe(74838.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(12000.0)
        ->and(AccountTransaction::count())->toBe(0)->and((float) $session->fresh()->opening_balance)->toBe(2500.0);
});

test('changing only Main opening does not overwrite physical cashier cash', function () {
    $session = ($this->openDrawer)();
    ($this->correctDrawer)($session, 5000, ['main' => 80000, 'spare_parts' => 12000, 'washing' => 0])->assertOk();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_in_hand'])->toBe(5000.0)->and($summary['cash_opening'])->toBe(5000.0)
        ->and($summary['accounts']['main']['opening'])->toBe(80000.0)->and($summary['cash_received'])->toBe(0.0)
        ->and($summary['cash_paid'])->toBe(0.0)->and(Account::systemAccount('main')->balance)->toBe(80000.0);
    expect(AccountTransaction::sole()->payment_method)->toBe('Adjustment');
});

test('cash receipts expenses and transfers move drawer cash while bank and account corrections do not', function () {
    ($this->openDrawer)();
    $invoice = Invoice::create(['number' => 'SEPARATE-DRAWER', 'invoice_type' => 'Job Card Bill', 'subtotal' => 1000, 'total' => 1000,
        'service_amount' => 400, 'labor_amount' => 300, 'washing_amount' => 200, 'parts_amount' => 100, 'created_by' => auth()->id(), 'public_token' => 'separate-drawer']);
    foreach (['Cash' => 600, 'Bank Transfer' => 400] as $method => $amount) {
        $payment = $invoice->payments()->create(['amount' => $amount, 'method' => $method, 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
        $this->accounts->receiveInvoicePayment($invoice, $payment);
    }
    $this->accounts->recordTransaction('main', 'expense', 50, 'Cash expense', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('bank', 'expense', 30, 'Bank expense', paymentMethod: 'Bank Transfer');
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(5000.0)->and($summary['cash_in_hand'])->toBe(5550.0)
        ->and($summary['cash_received'])->toBe(600.0)->and($summary['bank_received'])->toBe(400.0)->and($summary['cash_paid'])->toBe(50.0)
        ->and($summary['accounts']['main']['closing'])->toBe(75488.0)->and(Account::systemAccount('bank')->balance)->toBe(670.0);
    ($this->closeDrawer)(4540, 1000, 'Drawer counted short by ten')->assertSessionHasNoErrors();
    expect((float) CashierSession::sole()->drawer_closing_balance)->toBe(4540.0)
        ->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(4540.0)
        ->and(Account::systemAccount('main')->balance)->toBe(75488.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(11100.0)
        ->and((float) DailyClosing::sole()->cash_difference)->toBe(-10.0);
});

test('closing drawer count is separate from editable account balances and carries to the next opening', function () {
    ($this->openDrawer)();
    ($this->closeDrawer)(5000, balances: ['main' => 74838, 'spare_parts' => 12000, 'bank' => 300])->assertSessionHasNoErrors();
    expect(CashierSession::sole()->cash_balances)->toBeNull()->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(5000.0);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Verified cash in cashier');
    $this->travel(1)->days();
    expect($this->service->suggestedOpening())->toBe(5000.0);
    $session = ($this->openDrawer)(5000);
    expect((float) $session->drawer_opening_balance)->toBe(5000.0)->and(Account::systemAccount('main')->balance)->toBe(74838.0);
});

test('account closing adjustments do not move cash in the drawer', function () {
    ($this->openDrawer)();
    ($this->closeDrawer)(5000, reason: 'Confirmed account figures separately', balances: ['main' => 75000, 'spare_parts' => 13000, 'bank' => 350])->assertSessionHasNoErrors();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(75000.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(13000.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(350.0);
});

test('same-day reopening preserves the original drawer opening and counts only new cash movements', function () {
    ($this->openDrawer)();
    $this->accounts->recordTransaction('main', 'income', 1000, 'Cash receipt', paymentMethod: 'Cash');
    ($this->closeDrawer)(6000)->assertSessionHasNoErrors();
    $balances = $this->balances;
    $balances['main'] = 75838;
    $this->postJson(route('cashier.open'), ['opening_balance' => 5900, 'opening_balances' => $balances, 'opening_reason' => 'Counted current cashier cash', 'separate_cash_balance' => 1])->assertOk();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(5000.0)->and($summary['cash_in_hand'])->toBe(5900.0)->and($summary['cash_received'])->toBe(1000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(75838.0);
});

test('legacy opening can be separated without forcing Main to equal physical drawer cash', function () {
    $session = $this->service->open(86838, null);
    ($this->correctDrawer)($session, 4665, $this->balances)->assertOk();
    expect((float) $session->fresh()->drawer_opening_balance)->toBe(4665.0)
        ->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(4665.0)
        ->and(Account::systemAccount('main')->balance)->toBe(74838.0)->and(AccountTransaction::count())->toBe(0);
});

test('independent drawer edits reject stale counts invalid amounts and unexplained changes', function () {
    $session = ($this->openDrawer)();
    $payload = ['opening_balance' => 3000, 'opening_balances' => $this->balances, 'separate_cash_balance' => 1, 'recalculate_today' => 1, 'balance_version' => ($this->version)()];
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertUnprocessable()->assertJsonValidationErrors('opening_reason');
    $stale = $payload + ['opening_reason' => 'Changed physical cash'];
    ($this->correctDrawer)($session, 2500, $this->balances)->assertOk();
    $this->postJson(route('cashier.opening-balances', $session), $stale)->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    ($this->correctDrawer)($session, -1, $this->balances)->assertUnprocessable()->assertJsonValidationErrors('opening_balance');
    expect((float) $session->fresh()->drawer_opening_balance)->toBe(2500.0)->and(AccountTransaction::count())->toBe(0);
});

test('independent closing exports report physical cash separately from all account balances', function () {
    ($this->openDrawer)();
    ($this->closeDrawer)(5000)->assertSessionHasNoErrors();
    Excel::fake();
    $this->get(route('accounts.daily-closing.export', ['format' => 'excel']))->assertOk();
    Excel::assertDownloaded('daily_closing_'.today()->toDateString().'.xlsx', function ($export) {
        $rows = $export->array();

        return count($rows) === 5 && $rows[0][0] === 'Cash in cashier (separate)' && (float) $rows[0][1] === 5000.0
            && (float) $rows[0][5] === 5000.0 && (float) $rows[1][5] === 74838.0;
    });
    $this->get(route('accounts.daily-closing.export'))->assertOk()->assertHeader('content-type', 'application/pdf');
});

test('washing cash settles between accounts without changing the physical drawer total', function () {
    ($this->openDrawer)();
    $this->accounts->recordTransaction('washing', 'income', 200, 'Cash washing receipt', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('washing', 'income', 300, 'Bank washing allocation', paymentMethod: 'Bank Transfer');
    $this->accounts->recordTransaction('bank', 'income', 300, 'Bank washing receipt', paymentMethod: 'Bank Transfer');
    ($this->closeDrawer)(5200)->assertSessionHasNoErrors();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(75038.0)->and(Account::systemAccount('washing')->balance)->toBe(300.0)
        ->and($this->service->summary(today()->toDateString())['cash_paid'])->toBe(0.0);
    $this->postJson(route('cashier.open'), ['opening_balance' => 5200, 'separate_cash_balance' => 1])->assertOk();
    ($this->closeDrawer)(5200)->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(75038.0);
});

test('a historical drawer correction preserves later recorded physical cash and independent account counts', function () {
    $old = ($this->openDrawer)();
    ($this->closeDrawer)(5000)->assertSessionHasNoErrors();
    $this->travel(1)->days();
    $later = ($this->openDrawer)();
    $laterBefore = $later->fresh()->toArray();
    ($this->correctDrawer)($old, 4000, ['main' => 80000, 'spare_parts' => 12000, 'washing' => 0], false)->assertOk();
    expect((float) $old->fresh()->drawer_opening_balance)->toBe(4000.0)
        ->and((float) $old->fresh()->drawer_closing_balance)->toBe(5000.0)
        ->and($this->service->summary($old->session_date->toDateString())['cash_in_hand'])->toBe(5000.0)
        ->and($later->fresh()->toArray())->toBe($laterBefore)->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(74838.0);
    $closing = DailyClosing::whereDate('closing_date', $old->session_date)->sole();
    expect((float) $closing->cash_actual_balance)->toBe(5000.0)->and((float) $closing->cash_closing_balance)->toBe(4000.0)
        ->and((float) $closing->cash_difference)->toBe(1000.0);
});

test('drawer carry-forward includes cash movements on days without a session but excludes ledger-only balances', function () {
    ($this->openDrawer)();
    ($this->closeDrawer)(5000)->assertSessionHasNoErrors();
    $this->travel(1)->days();
    $this->accounts->recordTransaction('main', 'income', 300, 'Gap day cash', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('main', 'expense', 50, 'Gap day cash expense', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('main', 'adjustment_increase', 10000, 'Ledger only', paymentMethod: 'Adjustment', category: 'cashier_account_reconciliation');
    $this->travel(1)->days();
    expect($this->service->suggestedOpening())->toBe(5250.0);
    $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('suggestedOpening', 5250.0);
});

test('a negative Main ledger balance does not prevent entering positive physical cashier cash', function () {
    Account::systemAccount('main')->update(['opening_balance' => -100]);
    $this->postJson(route('cashier.open'), ['opening_balance' => 5000, 'opening_balances' => ['main' => -100, 'spare_parts' => 12000, 'washing' => 0],
        'separate_cash_balance' => 1])->assertOk();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(5000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(-100.0)->and(AccountTransaction::count())->toBe(0);
});

test('a later legacy counted day takes precedence over an older independent drawer baseline', function () {
    ($this->openDrawer)();
    ($this->closeDrawer)(5000)->assertSessionHasNoErrors();
    $this->travel(1)->days();
    CashierSession::create(['session_date' => today(), 'status' => 'closed', 'opening_balance' => 3000,
        'cash_opening_balances' => ['main' => 3000, 'spare_parts' => 0, 'washing' => 0],
        'cash_balances' => ['main' => 3000, 'spare_parts' => 0, 'washing' => 0], 'opened_by' => auth()->id(), 'opened_at' => now()]);
    $this->travel(1)->days();
    expect($this->service->suggestedOpening())->toBe(3000.0);
});

test('a historical independent opening can be recorded and viewed without inventing a closing cash count', function () {
    $date = today()->subDays(2)->toDateString();
    $this->postJson(route('cashier.historical-opening'), ['date' => $date, 'opening_balance' => 3000, 'opening_balances' => $this->balances,
        'opening_reason' => 'Verified starting cash only', 'separate_cash_balance' => 1, 'balance_version' => ($this->version)($date)])->assertOk();
    $this->get(route('cashier.index', ['date' => $date]))->assertOk()->assertSeeText('Account balances · separate from cashier cash');
    expect((float) CashierSession::sole()->drawer_opening_balance)->toBe(3000.0)->and(CashierSession::sole()->drawer_closing_balance)->toBeNull()
        ->and(Account::systemAccount('main')->balance)->toBe(74838.0)->and(AccountTransaction::count())->toBe(0);
});

test('repeated closing reports keep cashier cash math consistent after a drawer count adjustment', function () {
    ($this->openDrawer)();
    ($this->closeDrawer)(4900, reason: 'Counted short one hundred')->assertSessionHasNoErrors();
    $this->postJson(route('cashier.open'), ['opening_balance' => 4900, 'separate_cash_balance' => 1])->assertOk();
    ($this->closeDrawer)(4900)->assertSessionHasNoErrors();
    $closing = DailyClosing::sole();
    expect((float) $closing->cash_opening_balance)->toBe(5000.0)->and((float) $closing->cash_income)->toBe(0.0)
        ->and((float) $closing->cash_expense)->toBe(100.0)->and((float) $closing->cash_closing_balance)->toBe(4900.0)
        ->and((float) $closing->cash_actual_balance)->toBe(4900.0)->and(Account::systemAccount('main')->balance)->toBe(74838.0);
});
