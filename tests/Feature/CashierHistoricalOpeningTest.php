<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->travelTo('2026-10-06 08:00:00');
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 500]);
    Account::systemAccount('bank')->update(['opening_balance' => 300]);
    $this->service = app(CashierService::class);
    $this->accounts = app(AccountService::class);
    $this->closeDay = function (float $withdrawal = 0) {
        $summary = $this->service->summary(today()->toDateString());
        $this->service->close(CashierSession::active(), ['closing_balance' => $summary['cash_in_hand'] - $withdrawal,
            'owner_withdrawal' => $withdrawal, 'balance_version' => $this->service->fingerprint($summary)]);
    };
    $this->correctionPayload = function (CashierSession $session, array $balances) {
        return ['opening_balance' => array_sum($balances), 'opening_balances' => $balances,
            'opening_reason' => 'Verified opening from original cash record',
            'balance_version' => $this->service->fingerprint($this->service->summary($session->session_date->toDateString()))];
    };
});

test('closed and reopened cashiers expose editing without changing their status or counted cash', function (bool $reopen) {
    $session = $this->service->open(1500, null);
    ($this->closeDay)();
    if ($reopen) {
        $this->service->open(1500, null);
        $this->accounts->recordTransaction('main', 'income', 100, 'Cash after reopening', paymentMethod: 'Cash');
    }
    $originalStatus = $session->fresh()->status;
    $originalCash = $session->fresh()->cash_balances;
    $closeEvent = $session->events()->where('action', 'closed')->sole()->toArray();
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Edit Opening Balances')->assertSeeText('Edit opening');
    $this->get(route('cashier.opening-popup', ['edit' => 1, 'date' => '2026-10-06']))->assertOk()
        ->assertSeeText('06 Oct 2026')->assertSee('name="opening_reason" required', false);
    $payload = ($this->correctionPayload)($session, ['main' => 1200, 'spare_parts' => 500, 'washing' => 0]);
    $this->postJson(route('cashier.opening-balances', $session), $payload)->assertOk()
        ->assertJsonPath('redirect', route('cashier.index', ['date' => '2026-10-06']));
    expect($session->fresh()->status)->toBe($originalStatus)->and($session->fresh()->cash_balances)->toBe($originalCash)
        ->and($session->events()->where('action', 'closed')->sole()->toArray())->toBe($closeEvent)
        ->and($this->service->summary('2026-10-06')['cash_in_hand'])->toBe($reopen ? 1600.0 : 1500.0)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(1500.0)
        ->and((float) DailyClosing::sole()->cash_closing_balance)->toBe(1700.0)
        ->and((float) DailyClosing::sole()->cash_difference)->toBe(-200.0);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Closing reconciliation after opening correction')
        ->assertSeeText('Verified opening from original cash record');
})->with([false, true]);

test('historical corrections preserve later counts receipts bank withdrawals and the recorded original closing', function (array $newOpening) {
    $old = $this->service->open(1500, null);
    $this->accounts->recordTransaction('main', 'income', 200, 'Old cash receipt', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('main', 'expense', 50, 'Actual cash expense', paymentMethod: 'Cash');
    ($this->closeDay)(100);
    $originalSnapshot = $old->fresh()->closing_summary;
    $oldActual = $old->fresh()->actual_balances;
    $this->travelTo('2026-10-07 08:00:00');
    $later = $this->service->open(1550, null);
    $this->accounts->recordTransaction('spare_parts', 'income', 100, 'Later cash receipt', paymentMethod: 'Cash');
    ($this->closeDay)();
    $laterValues = $later->fresh()->toArray();
    $laterClosing = DailyClosing::whereDate('closing_date', '2026-10-07')->sole()->toArray();
    $this->travelTo('2026-10-29 08:00:00');
    $todaySession = $this->service->open(1650, null);
    $this->accounts->recordTransaction('main', 'income', 100, 'Today cash receipt', paymentMethod: 'Cash');
    $todayBefore = $this->service->summary('2026-10-29');
    $todayValues = $todaySession->fresh()->toArray();
    $this->get(route('cashier.index', ['date' => '2026-10-06']))->assertOk()->assertSeeText('Edit Opening Balances');
    $this->get(route('cashier.opening-popup', ['edit' => 1, 'date' => '2026-10-06']))->assertOk()
        ->assertViewHas('suggestedOpening', 1500.0)->assertSeeText('06 Oct 2026');
    $this->postJson(route('cashier.opening-balances', $old), ($this->correctionPayload)($old, $newOpening))->assertOk();
    $oldSummary = $this->service->summary('2026-10-06');
    $difference = array_sum($newOpening) - 1500;
    $closing = DailyClosing::whereDate('closing_date', '2026-10-06')->sole();
    expect($oldSummary['cash_opening'])->toBe((float) array_sum($newOpening))->and($oldSummary['cash_received'])->toBe(200.0)
        ->and($oldSummary['cash_in_hand'])->toBe(1550.0)->and((float) $closing->cash_actual_balance)->toBe(1550.0)
        ->and((float) $closing->cash_closing_balance)->toBe(1550.0 + $difference)->and((float) $closing->cash_difference)->toBe((float) -$difference)
        ->and($old->fresh()->actual_balances)->toBe($oldActual)->and((float) $old->fresh()->owner_withdrawal)->toBe(100.0)
        ->and($later->fresh()->toArray())->toBe($laterValues)->and($todaySession->fresh()->toArray())->toBe($todayValues)
        ->and(DailyClosing::whereDate('closing_date', '2026-10-07')->sole()->toArray())->toBe($laterClosing)
        ->and($this->service->summary('2026-10-29'))->toEqual($todayBefore)
        ->and(Account::systemAccount('main')->balance)->toBe(1250.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(500.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(300.0);
    $event = $old->events()->where('action', 'opening_corrected')->sole();
    expect($event->details['previous_opening'])->toEqual(['main' => 1000, 'spare_parts' => 500, 'washing' => 0])
        ->and($event->details['checkpoint']['kind'])->toBe('closing')
        ->and((float) $event->details['previous_closing']['cash_closing_balance'])->toBe(1550.0)
        ->and($event->created_by)->toBe(auth()->id())->and($event->created_at->toDateString())->toBe('2026-10-29')
        ->and($old->fresh()->closing_summary['cash_opening'])->toEqual(array_sum($newOpening))
        ->and($old->fresh()->closing_summary['closing_withdrawal'])->toEqual($originalSnapshot['closing_withdrawal']);
    $newEntries = AccountTransaction::where('reference_type', $old->getMorphClass())->where('reference_id', $old->id)->get();
    expect($newEntries->every(fn ($entry) => $entry->transaction_date->toDateString() === '2026-10-06'))->toBeTrue();
})->with([
    'same total different accounts' => [['main' => 900, 'spare_parts' => 600, 'washing' => 0]],
    'changed total' => [['main' => 1200, 'spare_parts' => 600, 'washing' => 0]],
]);

test('historical corrected exports show revised expected amounts and original actual counts', function () {
    $old = $this->service->open(1500, null);
    $this->accounts->recordTransaction('main', 'income', 200, 'Old cash receipt', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('main', 'expense', 50, 'Old expense', paymentMethod: 'Cash');
    ($this->closeDay)(100);
    $this->travelTo('2026-10-29 08:00:00');
    $this->postJson(route('cashier.opening-balances', $old), ($this->correctionPayload)($old, ['main' => 1200, 'spare_parts' => 600, 'washing' => 0]))->assertOk();
    Excel::fake();
    $this->get(route('accounts.daily-closing.export', ['date' => '2026-10-06', 'format' => 'excel']))->assertOk();
    Excel::assertDownloaded('daily_closing_2026-10-06.xlsx', function ($export) {
        $rows = $export->array();

        return (float) $rows[0][1] === 1200.0 && (float) $rows[0][4] === 1350.0 && (float) $rows[0][5] === 1150.0 && (float) $rows[0][6] === -200.0
            && (float) $rows[2][1] === 600.0 && (float) $rows[2][4] === 500.0 && (float) $rows[2][5] === 400.0 && (float) $rows[2][6] === -100.0
            && (float) $rows[3][4] === 300.0 && (float) $rows[3][5] === 300.0;
    });
});

test('repeated corrections update only the difference and reject stale or unexplained old forms', function () {
    $old = $this->service->open(1500, null);
    ($this->closeDay)();
    $this->travelTo('2026-10-29 08:00:00');
    $first = ($this->correctionPayload)($old, ['main' => 1200, 'spare_parts' => 500, 'washing' => 0]);
    $withoutReason = $first;
    unset($withoutReason['opening_reason']);
    $this->postJson(route('cashier.opening-balances', $old), $withoutReason)->assertUnprocessable()->assertJsonValidationErrors('opening_reason');
    $this->postJson(route('cashier.opening-balances', $old), $first)->assertOk();
    $count = AccountTransaction::count();
    $this->postJson(route('cashier.opening-balances', $old), $first)->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    $this->postJson(route('cashier.opening-balances', $old), ($this->correctionPayload)($old, ['main' => 1200, 'spare_parts' => 500, 'washing' => 0]))->assertOk();
    expect(AccountTransaction::count())->toBe($count)->and($old->events()->where('action', 'opening_corrected')->count())->toBe(1);
    $this->postJson(route('cashier.opening-balances', $old), ($this->correctionPayload)($old, ['main' => 1100, 'spare_parts' => 500, 'washing' => 0]))->assertOk();
    expect((float) DailyClosing::sole()->cash_closing_balance)->toBe(1600.0)->and((float) DailyClosing::sole()->cash_difference)->toBe(-100.0)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(1500.0)->and(Account::systemAccount('main')->balance)->toBe(1000.0);
});

test('the first count anchors corrections when a day was closed several times', function () {
    $old = $this->service->open(1500, null);
    $this->travelTo('2026-10-06 10:00:00');
    ($this->closeDay)();
    $this->service->open(1500, null);
    $this->accounts->recordTransaction('main', 'income', 200, 'Later same day cash', paymentMethod: 'Cash');
    $this->travelTo('2026-10-06 14:00:00');
    ($this->closeDay)();
    $originalEvents = $old->events()->where('action', 'closed')->get()->toArray();
    $this->travelTo('2026-10-29 08:00:00');
    $this->postJson(route('cashier.opening-balances', $old), ($this->correctionPayload)($old, ['main' => 1100, 'spare_parts' => 500, 'washing' => 0]))->assertOk();
    expect((float) DailyClosing::sole()->cash_actual_balance)->toBe(1700.0)->and((float) DailyClosing::sole()->cash_closing_balance)->toBe(1700.0)
        ->and((float) DailyClosing::sole()->cash_difference)->toBe(0.0)->and($old->fresh()->closing_summary['cash_in_hand'])->toEqual(1700)
        ->and($old->fresh()->closing_summary['cash_accounts']['main']['opening'])->toEqual(1100)
        ->and($old->fresh()->closing_summary['cash_accounts']['main']['outgoing'])->toEqual(100)
        ->and($old->events()->where('action', 'closed')->get()->toArray())->toBe($originalEvents);
    $this->get(route('cashier.index', ['date' => '2026-10-06']))->assertOk();
    Excel::fake();
    $this->get(route('accounts.daily-closing.export', ['date' => '2026-10-06', 'format' => 'excel']))->assertOk();
    Excel::assertDownloaded('daily_closing_2026-10-06.xlsx', function ($export) {
        $row = $export->array()[0];

        return (float) $row[1] === 1100.0 && (float) $row[2] === 200.0 && (float) $row[3] === 100.0 && (float) $row[4] === 1200.0 && (float) $row[5] === 1200.0;
    });
});

test('an old unclosed snapshot can be edited without changing a later saved opening', function () {
    $old = $this->service->open(1500, null);
    $old->update(['status' => 'closed']); // Imported record with no actual count or closing checkpoint.
    $this->travelTo('2026-10-07 08:00:00');
    $later = $this->service->open(1500, null);
    $laterBefore = $later->fresh()->toArray();
    $this->travelTo('2026-10-29 08:00:00');
    $this->postJson(route('cashier.opening-balances', $old), ($this->correctionPayload)($old, ['main' => 1100, 'spare_parts' => 500, 'washing' => 0]))->assertOk();
    expect($this->service->summary('2026-10-06')['cash_in_hand'])->toBe(1600.0)
        ->and($this->service->summary('2026-10-07')['cash_opening'])->toBe(1500.0)->and($later->fresh()->toArray())->toBe($laterBefore)
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0);
    $event = $old->events()->where('action', 'opening_corrected')->sole();
    expect($event->details['checkpoint']['kind'])->toBe('next_opening');
});

test('editing rejects future missing and other shop sessions without ledger changes', function () {
    $session = $this->service->open(1500, null);
    $session->update(['session_date' => today()->addDay()]);
    $this->getJson(route('cashier.opening-popup', ['edit' => 1, 'date' => '2026-10-07']))->assertUnprocessable();
    $this->postJson(route('cashier.opening-balances', $session), ($this->correctionPayload)($session, ['main' => 1100, 'spare_parts' => 500, 'washing' => 0]))->assertUnprocessable()->assertJsonValidationErrors('cashier');
    $this->get(route('cashier.opening-popup', ['edit' => 1, 'date' => '2026-10-05']))->assertOk()->assertSeeText('Record Opening Balances');
    $this->withSession(['shop_mode' => 'helmet'])->postJson(route('cashier.opening-balances', $session), [])->assertNotFound();
    expect(AccountTransaction::withoutGlobalScopes()->count())->toBe(0);
});

test('opening history editing uses the cashier permission checks', function () {
    $session = $this->service->open(1500, null);
    $role = Role::create(['name' => 'View only', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('cashier.opening-popup', ['edit' => 1]))->assertForbidden();
    $this->postJson(route('cashier.opening-balances', $session), [])->assertForbidden();
    expect(AccountTransaction::count())->toBe(0);
});

test('a missing historical opening can be recorded without opening a till or altering todays counted cash', function () {
    $this->travelTo('2026-10-29 08:00:00');
    $current = $this->service->open(1500, null);
    $currentBefore = $current->fresh()->toArray();
    $version = $this->service->fingerprint($this->service->summary('2026-10-06'));
    $this->get(route('cashier.index', ['date' => '2026-10-06']))->assertOk()->assertSeeText('Record Opening Balances');
    $this->get(route('cashier.opening-popup', ['date' => '2026-10-06', 'edit' => 1]))->assertOk()
        ->assertSee('name="date" value="2026-10-06"', false);
    $payload = ['date' => '2026-10-06', 'opening_balance' => 1700, 'opening_balances' => ['main' => 1200, 'spare_parts' => 500, 'washing' => 0],
        'opening_reason' => 'Recorded verified system starting cash', 'balance_version' => $version];
    $this->postJson(route('cashier.historical-opening'), $payload)->assertOk()->assertJsonPath('redirect', route('cashier.index', ['date' => '2026-10-06']));
    $historical = CashierSession::whereDate('session_date', '2026-10-06')->sole();
    expect($historical->status)->toBe('closed')->and($historical->cash_balances)->toBeNull()->and($historical->closed_at)->toBeNull()
        ->and(CashierSession::active()->id)->toBe($current->id)->and($current->fresh()->toArray())->toBe($currentBefore)
        ->and($this->service->summary('2026-10-06')['cash_opening'])->toBe(1700.0)
        ->and($this->service->summary('2026-10-29')['cash_opening'])->toBe(1500.0)->and(Account::systemAccount('main')->balance)->toBe(1000.0);
    $this->postJson(route('cashier.historical-opening'), $payload)->assertUnprocessable()->assertJsonValidationErrors('date');
    expect(CashierSession::count())->toBe(2)->and($historical->events()->where('action', 'opening_corrected')->count())->toBe(1);
});

test('recording a historical opening validates its reason split date and snapshot atomically', function () {
    $this->travelTo('2026-10-29 08:00:00');
    $payload = ['date' => '2026-10-06', 'opening_balance' => 1700, 'opening_balances' => ['main' => 1200, 'spare_parts' => 500, 'washing' => 0],
        'opening_reason' => 'Verified starting cash', 'balance_version' => $this->service->fingerprint($this->service->summary('2026-10-06'))];
    $this->postJson(route('cashier.historical-opening'), array_replace($payload, ['opening_reason' => '']))->assertUnprocessable()->assertJsonValidationErrors('opening_reason');
    $this->postJson(route('cashier.historical-opening'), array_replace($payload, ['opening_balance' => 1600]))->assertUnprocessable()->assertJsonValidationErrors('opening_balances');
    $this->postJson(route('cashier.historical-opening'), array_replace($payload, ['date' => '2026-10-29']))->assertUnprocessable()->assertJsonValidationErrors('date');
    $this->accounts->recordTransaction('main', 'income', 10, 'Changed old receipt', paymentMethod: 'Cash', transactionDate: '2026-10-06');
    $this->postJson(route('cashier.historical-opening'), $payload)->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    expect(CashierSession::count())->toBe(0)->and(AccountTransaction::count())->toBe(1);
});

test('an unchanged historical opening still records its author and reason without fabricated money', function () {
    $this->travelTo('2026-10-29 08:00:00');
    $this->postJson(route('cashier.historical-opening'), ['date' => '2026-10-06', 'opening_balance' => 1500,
        'opening_balances' => ['main' => 1000, 'spare_parts' => 500, 'washing' => 0], 'opening_reason' => 'Confirmed original starting cash',
        'balance_version' => $this->service->fingerprint($this->service->summary('2026-10-06'))])->assertOk();
    $session = CashierSession::sole();
    expect($session->events()->sole()->action)->toBe('opening_recorded')->and($session->events()->sole()->details['reason'])->toBe('Confirmed original starting cash')
        ->and(AccountTransaction::count())->toBe(0)->and(CashierSession::active())->toBeNull();
});

test('historical opening exports leave unknown actual cash and bank counts unrecorded', function () {
    $this->travelTo('2026-10-29 08:00:00');
    $this->postJson(route('cashier.historical-opening'), ['date' => '2026-10-06', 'opening_balance' => 1500,
        'opening_balances' => ['main' => 1000, 'spare_parts' => 500, 'washing' => 0], 'opening_reason' => 'Confirmed starting cash only',
        'balance_version' => $this->service->fingerprint($this->service->summary('2026-10-06'))])->assertOk();
    Excel::fake();
    $this->get(route('accounts.daily-closing.export', ['date' => '2026-10-06', 'format' => 'excel']))->assertOk();
    Excel::assertDownloaded('daily_closing_2026-10-06.xlsx', function ($export) {
        return count($export->array()) === 4 && collect($export->array())->every(fn ($row) => $row[5] === null && $row[6] === null);
    });
    $this->get(route('accounts.daily-closing.export', ['date' => '2026-10-06']))->assertOk()->assertHeader('content-type', 'application/pdf');
});
