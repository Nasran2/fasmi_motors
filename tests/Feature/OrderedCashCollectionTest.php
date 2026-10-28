<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\CashPositionService;
use App\Services\CollectionReportService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->invoice = Invoice::create(['number' => 'ORDER-COLLECTION', 'invoice_type' => 'Job Card Bill', 'subtotal' => 4000,
        'total' => 4000, 'service_amount' => 500, 'labor_amount' => 500, 'washing_amount' => 200, 'other_amount' => 0,
        'parts_amount' => 2800, 'due_amount' => 4000, 'status' => 'Unpaid', 'public_token' => 'ordered-cash-token', 'created_by' => auth()->id()]);
    $this->receive = function ($amount, $method = 'Cash', $status = 'Completed') {
        $payment = $this->invoice->payments()->create(['number' => 'RCPT-'.uniqid(), 'amount' => $amount, 'method' => $method,
            'paid_at' => now(), 'status' => $status, 'created_by' => auth()->id()]);
        if ($status === 'Completed') {
            app(AccountService::class)->receiveInvoicePayment($this->invoice, $payment);
        }

        return $payment;
    };
});

test('a 200 partial receipt goes entirely to service without washing or spare parts', function () {
    $payment = ($this->receive)(200);
    expect(Account::systemAccount('main')->balance)->toBe(200.0)
        ->and(Account::systemAccount('washing')->balance)->toBe(0.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0);
    $parts = app(AccountService::class)->paymentComponentAllocation($this->invoice, $payment);
    expect($parts)->toBe(['service' => 20000, 'labor' => 0, 'washing' => 0, 'other' => 0, 'spare_parts' => 0]);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Cash collected')->assertDontSee('Washing charges billed:')
        ->assertDontSee('name="balances[bank]"', false)->assertViewHas('summary', fn ($s) => $s['cash_received'] === 200.0 && $s['cash_in_hand'] === 200.0);
});

test('sequential receipts exhaust service then labor then washing then other and spare parts', function () {
    $this->invoice->update(['other_amount' => 100, 'parts_amount' => 2700]);
    $expected = [[200, [200, 0, 0, 0, 0]], [500, [300, 200, 0, 0, 0]], [400, [0, 300, 100, 0, 0]], [300, [0, 0, 100, 100, 100]]];
    foreach ($expected as [$amount, $pieces]) {
        $payment = ($this->receive)($amount);
        expect(array_values(app(AccountService::class)->paymentComponentAllocation($this->invoice, $payment)))->toBe(array_map(fn ($v) => $v * 100, $pieces));
    }
    expect(Account::systemAccount('main')->balance)->toBe(1100.0)->and(Account::systemAccount('washing')->balance)->toBe(200.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(100.0);
    $count = AccountTransaction::count();
    app(AccountService::class)->reallocateInvoiceReceipts($this->invoice);
    expect(AccountTransaction::count())->toBe($count);
});

test('bank payments occupy their priority share but never enter cash in hand', function () {
    ($this->receive)(600, 'Bank Transfer');
    ($this->receive)(600, 'Cash');
    expect(Account::systemAccount('bank')->balance)->toBe(600.0);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_received'])->toBe(600.0)->and($summary['bank_received'])->toBe(600.0)->and($summary['cash_in_hand'])->toBe(600.0)
        ->and($summary['cash_accounts']['main']['closing'])->toBe(400.0)->and($summary['cash_accounts']['washing']['closing'])->toBe(200.0);
    $this->get(route('cashier.collections', ['method' => 'cash']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()['amount'] === 600.0);
    $this->get(route('cashier.collections', ['method' => 'bank_transfer']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1 && $rows->first()['channel'] === 'bank');
    $this->get(route('cashier.collections'))->assertOk()->assertViewHas('totals', fn ($s) => $s['all'] === 1200.0);
});

test('held and cancelled receipts cannot appear as collected money', function () {
    ($this->receive)(500, 'Cheque', 'Hold');
    $cash = ($this->receive)(200);
    AccountTransaction::where('reference_type', $cash->getMorphClass())->where('reference_id', $cash->id)->get()->each(fn ($t) => app(AccountService::class)->reverseTransaction($t, 'Cancel receipt'));
    $cash->update(['status' => 'Cancelled']);
    expect(app(CollectionReportService::class)->rows(today()->toDateString(), today()->toDateString())->count())->toBe(0)
        ->and(app(CashierService::class)->summary(today()->toDateString())['cash_in_hand'])->toBe(0.0);
});

test('cash closing accepts only cash balances and does not change bank funds', function () {
    app(CashierService::class)->open(0, null);
    ($this->receive)(600, 'Bank Transfer');
    ($this->receive)(600, 'Cash');
    $session = CashierSession::active();
    $service = app(CashierService::class);
    $summary = $service->summary(today()->toDateString());
    $payload = ['owner_withdrawal' => 0, 'balances' => ['main' => 390, 'washing' => 200, 'spare_parts' => 0], 'difference_reason' => 'Verified physical cash count', 'balance_version' => $service->fingerprint($summary)];
    $this->post(route('cashier.close', $session), $payload)->assertSessionHasNoErrors();
    expect(Account::systemAccount('bank')->balance)->toBe(600.0)->and(array_sum($session->fresh()->cash_balances))->toEqual(590);
    $this->travel(1)->days();
    expect(app(CashPositionService::class)->suggestedOpening(today()->toDateString()))->toBe(590.0);
});

test('owner cannot withdraw bank collections as spare parts cash', function () {
    app(CashierService::class)->open(0, null);
    ($this->receive)(4000, 'Bank Transfer');
    $session = CashierSession::active();
    $service = app(CashierService::class);
    $summary = $service->summary(today()->toDateString());
    $this->post(route('cashier.close', $session), ['owner_withdrawal' => 100, 'balances' => ['main' => 0, 'washing' => 0, 'spare_parts' => -100], 'balance_version' => $service->fingerprint($summary)])->assertSessionHasErrors('owner_withdrawal');
    expect($session->fresh()->status)->toBe('open');
});

test('legacy proportional receipt can be corrected without collecting or duplicating more money', function () {
    $payment = ($this->receive)(200, 'Cash', 'Hold');
    $payment->update(['status' => 'Completed']);
    $service = app(AccountService::class);
    foreach (['main' => 50, 'washing' => 10, 'spare_parts' => 140] as $type => $amount) {
        $service->recordTransaction($type, 'income', $amount, 'Legacy proportional', $payment, 'Cash');
    }
    $service->reallocateInvoiceReceipts($this->invoice);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_received'])->toBe(200.0)->and($summary['cash_in_hand'])->toBe(200.0)
        ->and($summary['cash_accounts']['main']['closing'])->toBe(200.0)->and($summary['cash_accounts']['washing']['closing'])->toBe(0.0)
        ->and($summary['cash_accounts']['spare_parts']['closing'])->toBe(0.0);
});

test('collection link opens a new window and dates and search filter settled receipts', function () {
    ($this->receive)(200);
    $this->get(route('cashier.index'))->assertOk()->assertSee('target="_blank"', false)->assertSeeText('All Collections');
    $this->get(route('cashier.collections', ['search' => 'ORDER-COLLECTION']))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
    $this->get(route('cashier.collections', ['from' => today()->subDay()->toDateString(), 'to' => today()->subDay()->toDateString()]))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
    $this->withSession(['shop_mode' => 'helmet'])->get(route('cashier.collections'))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->isEmpty());
});

test('one advance split across invoices remains one collection with the full amount', function (string $method) {
    $group = (string) Str::uuid();
    $first = $this->invoice->payments()->create(['number' => 'ADV-GROUP', 'receipt_group_id' => $group, 'amount' => 500, 'method' => $method, 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $other = Invoice::create(['number' => 'ADV-OTHER', 'invoice_type' => 'Job Card Bill', 'subtotal' => 500, 'total' => 500, 'service_amount' => 500, 'public_token' => 'advance-other', 'created_by' => auth()->id()]);
    $other->payments()->create(['number' => 'ADV-PIECE', 'receipt_group_id' => $group, 'amount' => 500, 'method' => $method, 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $service = app(AccountService::class);
    $service->recordTransaction('main', 'income', 1000, 'Original advance receipt', $first, $method);
    if ($method !== 'Cash') {
        $service->recordTransaction('bank', 'income', 1000, 'Original bank receipt', $first, $method);
    }
    $service->reallocateInvoiceReceipts($this->invoice);
    $service->reallocateInvoiceReceipts($other);
    $rows = app(CollectionReportService::class)->rows(today()->toDateString(), today()->toDateString());
    expect($rows->count())->toBe(1)->and($rows->first()['amount'])->toBe(1000.0)
        ->and(array_sum($rows->first()['components']))->toEqual(1000.0);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_received'])->toBe($method === 'Cash' ? 1000.0 : 0.0)
        ->and($summary['cash_in_hand'])->toBe($method === 'Cash' ? 1000.0 : 0.0);
})->with(['Cash', 'Bank Transfer']);

test('a held cheque only takes the next priority share when it actually clears', function () {
    ($this->receive)(500, 'Cash');
    $cheque = ($this->receive)(600, 'Cheque', 'Hold');
    $cheque->update(['paid_at' => today()->subDay(), 'status' => 'Completed']);
    app(AccountService::class)->receiveInvoicePayment($this->invoice, $cheque, settledAt: now()->addMinute());
    expect(app(AccountService::class)->paymentComponentAllocation($this->invoice, $cheque))->toBe(['service' => 0, 'labor' => 50000, 'washing' => 10000, 'other' => 0, 'spare_parts' => 0]);
    expect(app(CashierService::class)->summary(today()->toDateString())['cash_in_hand'])->toBe(500.0);
});

test('cent receipts follow priority and full settlement retains existing discount allocation', function () {
    $this->invoice->update(['subtotal' => 0.04, 'total' => 0.04, 'parts_amount' => 0.01, 'service_amount' => 0.01, 'labor_amount' => 0.01, 'washing_amount' => 0.01]);
    foreach (['service', 'labor', 'washing', 'spare_parts'] as $type) {
        $payment = ($this->receive)(0.01);
        $parts = app(AccountService::class)->paymentComponentAllocation($this->invoice, $payment);
        expect($parts[$type])->toBe(1)->and(array_sum($parts))->toBe(1);
    }
    $this->invoice->update(['subtotal' => 250, 'total' => 225, 'parts_amount' => 150, 'service_amount' => 100, 'labor_amount' => 0, 'washing_amount' => 0, 'discount' => 25]);
    expect(app(AccountService::class)->receiptAllocation($this->invoice, 22500))->toBe(['spare_parts' => 15000, 'washing' => 0, 'main' => 7500]);
});
