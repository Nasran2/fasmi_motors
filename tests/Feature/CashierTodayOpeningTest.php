<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->service = app(CashierService::class);
    $this->accounts = app(AccountService::class);
    $this->version = fn () => $this->service->fingerprint($this->service->summary(today()->toDateString()));
    $this->payload = fn (array $amounts) => ['opening_balance' => array_sum($amounts), 'opening_balances' => $amounts,
        'opening_reason' => 'Verified starting cash for today', 'recalculate_today' => 1, 'balance_version' => ($this->version)()];
    $this->closeDay = function () {
        $summary = $this->service->summary(today()->toDateString());
        $this->service->close(CashierSession::active(), ['closing_balance' => $summary['cash_in_hand'], 'owner_withdrawal' => 0,
            'balance_version' => $this->service->fingerprint($summary)]);
    };
});

test('today accepts a replacement main opening and counts actual cash receipts once excluding bank', function () {
    Account::systemAccount('main')->update(['opening_balance' => 120281.57]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 1266018.43]);
    Account::systemAccount('bank')->update(['opening_balance' => 300]);
    $session = CashierSession::create(['session_date' => today(), 'status' => 'open', 'opening_balance' => 4665,
        'cash_opening_balances' => ['main' => -1261353.43, 'spare_parts' => 1266018.43, 'washing' => 0], 'opened_at' => now(), 'opened_by' => auth()->id()]);
    $this->accounts->recordTransaction('main', 'adjustment_decrease', 1381635, 'Old opening error', $session, 'Cash', transactionDate: today()->startOfDay(), category: 'cashier_opening');
    $invoice = Invoice::create(['number' => 'TODAY-BASELINE', 'invoice_type' => 'Job Card Bill', 'total' => 1000, 'subtotal' => 1000,
        'service_amount' => 400, 'labor_amount' => 300, 'washing_amount' => 200, 'parts_amount' => 100, 'created_by' => auth()->id(), 'public_token' => 'today-baseline']);
    foreach (['Cash' => 600, 'Bank Transfer' => 400] as $method => $amount) {
        $payment = $invoice->payments()->create(['amount' => $amount, 'method' => $method, 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
        $this->accounts->receiveInvoicePayment($invoice, $payment);
    }
    $this->accounts->recordTransaction('main', 'expense', 100, 'Actual cash expense', paymentMethod: 'Cash');
    $paymentsBefore = $invoice->payments()->get()->toArray();
    $collectionsBefore = $this->service->summary(today()->toDateString())['collections']->toJson();
    $this->get(route('cashier.opening-popup', ['edit' => 1]))->assertOk()->assertViewHas('canRecalculateToday', true)
        ->assertDontSee('name="opening_balance" readonly', false)->assertSee('name="recalculate_today"', false)
        ->assertSeeText('Cash received on this date:')->assertSeeText('Recalculate today from these opening balances');
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)(['main' => 74838, 'spare_parts' => 0, 'washing' => 0]))->assertOk();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_opening'])->toBe(74838.0)->and($summary['cash_accounts']['main']['opening'])->toBe(74838.0)
        ->and($summary['cash_received'])->toBe(600.0)->and($summary['bank_received'])->toBe(400.0)
        ->and($summary['cash_in_hand'])->toBe(75338.0)->and($summary['cash_accounts']['main']['closing'])->toBe(75338.0)
        ->and($summary['cash_accounts']['spare_parts']['closing'])->toBe(0.0)->and($summary['cash_paid'])->toBe(100.0)
        ->and(Account::systemAccount('main')->balance)->toBe(75438.0)->and(Account::systemAccount('bank')->balance)->toBe(700.0)
        ->and($invoice->payments()->get()->toArray())->toBe($paymentsBefore)->and($summary['collections']->toJson())->toBe($collectionsBefore);
    $event = $session->events()->where('action', 'opening_corrected')->sole();
    expect($event->details['recalculate_today'])->toBeTrue()->and($event->details['checkpoint'])->toBeNull();
    ($this->closeDay)();
    $this->travel(1)->days();
    expect($this->service->suggestedOpening())->toBe(75338.0);
});

test('today can recalculate an already reopened till without rewriting earlier counted amounts', function () {
    Account::systemAccount('main')->update(['opening_balance' => 600]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 400]);
    $session = $this->service->open(1000, null);
    ($this->closeDay)();
    $count = $session->events()->where('action', 'closed')->sole()->toArray();
    $actual = $session->fresh()->cash_balances;
    $this->service->open(1000, null);
    $this->accounts->recordTransaction('main', 'income', 200, 'Received after reopening', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('main', 'expense', 30, 'Cash expense', paymentMethod: 'Cash');
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)(['main' => 1600, 'spare_parts' => 400, 'washing' => 0]))->assertOk();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(2170.0)
        ->and($this->service->summary(today()->toDateString())['cash_received'])->toBe(200.0)
        ->and($session->fresh()->status)->toBe('open')->and($session->fresh()->cash_balances)->toBe($actual)
        ->and($session->events()->where('action', 'closed')->sole()->toArray())->toBe($count)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(1000.0)
        ->and((float) DailyClosing::sole()->cash_closing_balance)->toBe(2000.0)
        ->and((float) DailyClosing::sole()->cash_difference)->toBe(-1000.0);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Today recalculated from corrected opening');
});

test('today can remove an earlier opening reconciliation even when the confirmed opening amounts are unchanged', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    $session = $this->service->open(1000, null);
    ($this->closeDay)();
    $this->service->open(1000, null);
    $this->accounts->recordTransaction('main', 'income', 200, 'Cash receipt', paymentMethod: 'Cash');
    $amounts = ['main' => 2000, 'spare_parts' => 0, 'washing' => 0];
    $preserved = ($this->payload)($amounts);
    $preserved['recalculate_today'] = 0;
    $this->postJson(route('cashier.opening-balances', $session), $preserved)->assertOk();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(1200.0);
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)($amounts))->assertOk();
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(2200.0)
        ->and($this->service->summary(today()->toDateString())['cash_received'])->toBe(200.0)
        ->and(AccountTransaction::where('category', 'cashier_opening_rebase')->count())->toBe(1)
        ->and($session->events()->where('action', 'opening_corrected')->count())->toBe(2);
    $count = AccountTransaction::count();
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)($amounts))->assertOk();
    expect(AccountTransaction::count())->toBe($count)->and($session->events()->where('action', 'opening_corrected')->count())->toBe(2);
});

test('today recalculation rejects closed previous day stale and unexplained corrections atomically', function (string $state) {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    $session = $this->service->open(1000, null);
    if ($state === 'closed') {
        ($this->closeDay)();
    } elseif ($state === 'previous day') {
        $this->travel(1)->days();
    }
    $payload = ($this->payload)(['main' => 2000, 'spare_parts' => 0, 'washing' => 0]);
    $payload['balance_version'] = $this->service->fingerprint($this->service->summary($session->session_date->toDateString()));
    $error = 'recalculate_today';
    if ($state === 'stale') {
        $this->accounts->recordTransaction('bank', 'income', 50, 'Bank changed', paymentMethod: 'Bank Transfer');
        $error = 'balance_version';
    } elseif ($state === 'no reason') {
        unset($payload['opening_reason']);
        $error = 'opening_reason';
    }
    $count = AccountTransaction::count();
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(AccountTransaction::count())->toBe($count)->and((float) $session->fresh()->opening_balance)->toBe(1000.0);
})->with(['closed', 'previous day', 'stale', 'no reason']);

test('today does not remove the same opening reconciliation twice after a manual reversal', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    $session = $this->service->open(1000, null);
    ($this->closeDay)();
    $this->service->open(1000, null);
    $amounts = ['main' => 2000, 'spare_parts' => 0, 'washing' => 0];
    $preserved = ($this->payload)($amounts);
    $preserved['recalculate_today'] = 0;
    $this->postJson(route('cashier.opening-balances', $session), $preserved)->assertOk();
    $entry = AccountTransaction::where('description', 'Opening correction reconciled to recorded cash count')->sole();
    $this->accounts->reverseTransaction($entry, 'Operator cancelled the opening offset');
    expect($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(2000.0);
    $count = AccountTransaction::count();
    $this->postJson(route('cashier.opening-balances', $session), ($this->payload)($amounts))->assertOk();
    expect(AccountTransaction::count())->toBe($count)->and($this->service->summary(today()->toDateString())['cash_in_hand'])->toBe(2000.0);
});
