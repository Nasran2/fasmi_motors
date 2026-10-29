<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 2000]);
    $this->service = app(CashierService::class);
    $this->closeData = function (float $withdrawal = 0, array $overrides = []) {
        $summary = $this->service->summary(today()->toDateString());

        return array_replace(['owner_withdrawal' => $withdrawal, 'owner_name' => 'Owner', 'closing_balance' => $summary['cash_in_hand'] - $withdrawal, 'balance_version' => $this->service->fingerprint($summary)], $overrides);
    };
});

test('closed cashier blocks creation pages and submissions but allows intake and history', function () {
    foreach (['jobs.create', 'jobs.quick.create', 'sales.parts'] as $route) {
        $this->get(route($route))->assertRedirect(route('cashier.index'))->assertSessionHas('error');
    }
    foreach (['jobs.store', 'jobs.quick.store', 'sales.parts.store'] as $route) {
        $this->post(route($route), [])->assertRedirect(route('cashier.index'));
        $this->postJson(route($route), [])->assertStatus(423)->assertJsonPath('cashier_url', route('cashier.index'));
    }
    $this->get(route('intakes.create'))->assertOk();
    $this->get(route('jobs.index'))->assertOk();
    expect(JobCard::count())->toBe(0)->and(Invoice::count())->toBe(0);
    $this->get(route('dashboard'))->assertOk()->assertSeeText('Open Cashier');
});

test('opening uses previous cash balances and rejects duplicate openings', function () {
    app(AccountService::class)->recordTransaction('main', 'income', 150, 'Previous day receipt', paymentMethod: 'Cash', transactionDate: today()->subDay());
    $this->get(route('cashier.index'))->assertOk()->assertViewHas('suggestedOpening', 3150.0);
    $this->post(route('cashier.open'), ['opening_balance' => 3150])->assertSessionHasNoErrors();
    expect(CashierSession::isOpenToday())->toBeTrue()->and(Account::systemAccount('main')->balance)->toBe(1150.0);
    foreach (['jobs.create', 'jobs.quick.create', 'sales.parts'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->post(route('cashier.open'), ['opening_balance' => 3150])->assertSessionHasErrors('cashier');
    expect(CashierSession::count())->toBe(1);
});

test('opening correction needs a reason and is recorded once in opening rather than income', function () {
    $this->post(route('cashier.open'), ['opening_balance' => 3200])->assertSessionHasErrors('opening_reason');
    $this->post(route('cashier.open'), ['opening_balance' => 3200, 'opening_reason' => 'Verified carried cash', 'opening_balances' => ['main' => 1200, 'spare_parts' => 2000, 'washing' => 0]])->assertSessionHasNoErrors();
    $summary = $this->service->summary(today()->toDateString());
    expect($summary['accounts']['main']['opening'])->toBe(1200.0)
        ->and($summary['accounts']['main']['income'])->toBe(0.0)
        ->and(Account::systemAccount('main')->balance)->toBe(1200.0);
});

test('closing records owner drawing only in parts and carries verified balances to next opening', function () {
    $session = $this->service->open(3000, null);
    $this->post(route('cashier.close', $session), ($this->closeData)(300, ['closing_balance' => 2650, 'difference_reason' => 'Verified physical cash count']))->assertSessionHasNoErrors();
    expect($session->fresh()->status)->toBe('closed')
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(1700.0)
        ->and(Account::systemAccount('main')->balance)->toBe(950.0)
        ->and(Drawing::firstOrFail()->account_id)->toBe(Account::systemAccount('spare_parts')->id)
        ->and(DailyClosing::firstOrFail()->spare_parts_actual_balance)->toBe('1700.00');
    $this->post(route('cashier.close', $session), ($this->closeData)())->assertSessionHasErrors('cashier');
    expect(Drawing::count())->toBe(1);
    $this->get(route('jobs.create'))->assertRedirect(route('cashier.index'));
    $this->travel(1)->days();
    $this->get(route('cashier.index'))->assertOk()->assertViewHas('suggestedOpening', 2650.0);
});

test('changed balances require an explanation and excessive withdrawal cannot post', function () {
    $session = $this->service->open(3000, null);
    $this->post(route('cashier.close', $session), ($this->closeData)(100, ['closing_balance' => 2400]))->assertSessionHasErrors('difference_reason');
    $this->post(route('cashier.close', $session), ($this->closeData)(2001))->assertSessionHasErrors('owner_withdrawal');
    expect($session->fresh()->status)->toBe('open')->and(Drawing::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});

test('a stale closing form cannot overwrite newer receipts', function () {
    $session = $this->service->open(3000, null);
    $data = ($this->closeData)();
    app(AccountService::class)->recordTransaction('main', 'income', 50, 'New receipt', paymentMethod: 'Cash');
    $this->post(route('cashier.close', $session), $data + ['difference_reason' => 'Should not overwrite'])->assertSessionHasErrors('balance_version');
    expect($session->fresh()->status)->toBe('open')->and(Account::systemAccount('main')->balance)->toBe(1050.0);
});

test('cash and bank filters preserve all account summary balances and count bank receipts once', function () {
    $accounts = app(AccountService::class);
    $accounts->recordTransaction('main', 'income', 100, 'Cash receipt marker', paymentMethod: 'Cash');
    $accounts->recordTransaction('main', 'income', 200, 'Logical bank receipt marker', paymentMethod: 'Card');
    $accounts->recordTransaction('bank', 'income', 200, 'Physical bank receipt marker', paymentMethod: 'Card');
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Cash receipt marker')->assertDontSeeText('Physical bank receipt marker')->assertViewHas('summary', fn ($summary) => $summary['total_received'] === 300.0 && $summary['cash_accounts']['main']['closing'] === 1100.0);
    $this->get(route('cashier.collections', ['method' => 'bank']))->assertOk()->assertSeeText('Physical bank receipt marker')->assertDontSeeText('Cash receipt marker')->assertDontSeeText('Logical bank receipt marker');
});

test('washing receipts use priority allocation and corrections and reversals preserve totals', function () {
    $invoice = Invoice::create(['number' => 'WASH-TEST', 'invoice_type' => 'Job Card Bill', 'subtotal' => 1000, 'total' => 1000, 'parts_amount' => 400, 'washing_amount' => 200, 'service_amount' => 400, 'created_by' => auth()->id(), 'public_token' => 'wash-test-token']);
    $service = app(AccountService::class);
    foreach ([333.33, 666.67] as $index => $amount) {
        $payment = Payment::create(['invoice_id' => $invoice->id, 'amount' => $amount, 'method' => 'Cash', 'paid_at' => now(), 'account_id' => Account::systemAccount('main')->id, 'created_by' => auth()->id(), 'status' => 'Completed']);
        $service->receiveInvoicePayment($invoice, $payment);
    }
    $service->reallocateInvoiceReceipts($invoice);
    expect(Account::systemAccount('washing')->balance)->toBe(200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(1400.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(2400.0)
        ->and($service->ledgerOpeningBalance('cash', today()->addDay()))->toBe(1000.0);
    AccountTransaction::where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('status', 'active')->get()->each(fn ($entry) => $service->reverseTransaction($entry, 'Cancel test receipt'));
    expect(round(Account::systemAccount('washing')->balance, 2))->toBe(0.0);
});

test('expenses reduce the selected operational account and bank expenses are not duplicated', function () {
    Account::systemAccount('washing')->update(['opening_balance' => 500]);
    Account::systemAccount('bank')->update(['opening_balance' => 500]);
    foreach (['washing', 'bank'] as $type) {
        $this->post(route('expenses.store'), ['expense_date' => today()->toDateString(), 'category' => 'Supplies', 'description' => 'Selected '.$type.' expense', 'account_id' => Account::systemAccount($type)->id, 'amount' => 100, 'payment_method' => $type === 'bank' ? 'Bank Transfer' : 'Cash'])->assertSessionHasNoErrors();
        expect(Account::systemAccount($type)->balance)->toBe(400.0);
    }
    expect(Account::systemAccount('main')->balance)->toBe(1000.0)->and(AccountTransaction::where('account_type', 'bank')->count())->toBe(1);
});

test('cashiers can open and close but reopening requires the existing reopen permission', function () {
    $role = Role::create(['name' => 'Cashier Test', 'permissions' => ['sales.parts_sales']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->post(route('cashier.open'), ['opening_balance' => 3000])->assertSessionHasNoErrors();
    $session = CashierSession::active();
    $this->post(route('cashier.close', $session), ($this->closeData)())->assertSessionHasNoErrors();
    $this->post(route('cashier.reopen', $session), ['reason' => 'Review cashier again'])->assertForbidden();
});

test('reopening and closing again never repeats a previous owner withdrawal', function () {
    $session = $this->service->open(3000, null);
    $this->post(route('cashier.close', $session), ($this->closeData)(100))->assertSessionHasNoErrors();
    $this->post(route('cashier.reopen', $session), ['reason' => 'Continue business today'])->assertSessionHasNoErrors();
    $this->post(route('cashier.close', $session), ($this->closeData)())->assertSessionHasNoErrors();
    expect(Account::systemAccount('spare_parts')->balance)->toBe(1900.0)->and(Drawing::count())->toBe(1)->and((float) $session->fresh()->owner_withdrawal)->toBe(100.0);
});

test('workshop and helmet cashier sessions and daily closings are isolated', function () {
    $workshop = $this->service->open(3000, null);
    $this->withSession(['shop_mode' => 'helmet'])->get(route('jobs.create'))->assertRedirect(route('cashier.index'));
    $this->post(route('cashier.open'), ['opening_balance' => 0])->assertSessionHasNoErrors();
    $helmet = CashierSession::active();
    expect($helmet->shop_type)->toBe('helmet');
    $this->post(route('cashier.close', $workshop), ($this->closeData)())->assertNotFound();
    $this->post(route('cashier.close', $helmet), ($this->closeData)())->assertSessionHasNoErrors();
    $this->withSession(['shop_mode' => 'workshop']);
    $this->post(route('cashier.close', $workshop), ($this->closeData)())->assertSessionHasNoErrors();
    expect(DailyClosing::withoutGlobalScopes()->count())->toBe(2);
});

test('previous open day must be closed first and reports export the four accounts', function () {
    $this->service->open(3000, null);
    $this->travel(1)->days();
    $this->get(route('jobs.create'))->assertRedirect(route('cashier.index'));
    $this->post(route('cashier.open'), ['opening_balance' => 3000])->assertSessionHasErrors('cashier');
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Close that day before opening today.');
    $this->get(route('accounts.daily-closing.export'))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('accounts.daily-closing.export', ['format' => 'excel']))->assertOk()->assertDownload();
});

test('cashier screen renders opening and live closing controls', function () {
    $closed = $this->get(route('cashier.index'))->assertOk()->assertSeeText('Open Cashier & Start Day');
    $this->service->open(3000, null);
    app(AccountService::class)->recordTransaction('washing', 'income', 500, 'Bike washing payment', paymentMethod: 'Cash');
    $open = $this->get(route('cashier.index'))->assertOk()->assertSeeText('Confirm & Close Cashier')->assertSeeText('Washing Account');
    if ($directory = getenv('CASHIER_PREVIEW_DIR')) {
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/closed.html', $closed->getContent());
        file_put_contents($directory.'/open.html', $open->getContent());
    }
});

test('opening separates washing on todays legacy receipts while preserving previous days', function () {
    $service = app(AccountService::class);
    foreach ([today()->subDay(), today()] as $index => $date) {
        $invoice = Invoice::create(['number' => 'LEGACY-WASH-'.$index, 'invoice_type' => 'Job Card Bill', 'subtotal' => 1000, 'total' => 1000, 'parts_amount' => 400, 'service_amount' => 400, 'washing_amount' => 200, 'created_by' => auth()->id(), 'public_token' => 'legacy-wash-'.$index]);
        $payment = $invoice->payments()->create(['amount' => 1000, 'method' => 'Cash', 'paid_at' => $date, 'status' => 'Completed', 'created_by' => auth()->id()]);
        $service->recordTransaction('main', 'income', 600, 'Legacy service and washing', $payment, 'Cash', transactionDate: $date);
        $service->recordTransaction('spare_parts', 'income', 400, 'Legacy parts', $payment, 'Cash', transactionDate: $date);
    }
    $this->service->open(4000, null);
    expect(Account::systemAccount('washing')->balance)->toBe(200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(2000.0)
        ->and($service->ledgerOpeningBalance('main', today()->startOfDay()))->toBe(1600.0);
    $count = AccountTransaction::count();
    $service->reallocateInvoiceReceipts($invoice, today()->startOfDay());
    expect(AccountTransaction::count())->toBe($count);
});

test('closing a forgotten previous day carries its verified balance into today', function () {
    $session = $this->service->open(3000, null);
    $this->travel(1)->days();
    $summary = $this->service->summary($session->session_date->toDateString());
    $this->post(route('cashier.close', $session), ['owner_withdrawal' => 100, 'closing_balance' => 2800, 'difference_reason' => 'Yesterday verified balance', 'balance_version' => $this->service->fingerprint($summary)])->assertSessionHasNoErrors();
    $this->get(route('cashier.index'))->assertOk()->assertViewHas('suggestedOpening', 2800.0);
    $this->post(route('cashier.open'), ['opening_balance' => 2800])->assertSessionHasNoErrors();
    expect(CashierSession::isOpenToday())->toBeTrue();
});
