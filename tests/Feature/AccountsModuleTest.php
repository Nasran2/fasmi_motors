<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Models\Cheque;
use App\Models\User;
use App\Services\AccountService;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($this->user);
    foreach ([['MAIN', 'Main Account', 'main'], ['SPARE', 'Spare Parts Account', 'spare_parts'], ['BANK', 'Bank Account', 'bank']] as [$code, $name, $type]) {
        Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'active' => true]);
    }
});

test('all account workspaces render their complete live interfaces', function () {
    foreach (['accounts.main-ledger.index', 'accounts.spare-parts-ledger.index', 'accounts.bank-ledger.index', 'accounts.cash-book.index', 'accounts.transfers.index', 'accounts.daily-closing.index', 'accounts.cheques.index', 'accounts.card-payments.index', 'accounts.opening-balances.index'] as $route) {
        $this->get(route($route))->assertOk()->assertDontSeeText('Page available')->assertDontSeeText('No records to display yet');
    }
});

test('ledgers show today by default and allow historical filters', function () {
    $service = app(AccountService::class);
    $service->recordTransaction('main', 'income', 100, 'Today receipt');
    $service->recordTransaction('main', 'income', 80, 'Yesterday receipt', null, null, null, null, now()->subDay());

    $this->get(route('accounts.main-ledger.index'))->assertOk()->assertSeeText('Today receipt')->assertDontSeeText('Yesterday receipt');
    $this->get(route('accounts.main-ledger.index', ['date_range' => 'yesterday']))->assertOk()->assertSeeText('Yesterday receipt')->assertDontSeeText('Today receipt');
});

test('an account transfer creates balanced paired entries and can be reversed', function () {
    Account::where('code', 'MAIN')->update(['opening_balance' => 5000]);
    $response = $this->post(route('accounts.transfers.store'), ['from_account' => 'main', 'to_account' => 'spare_parts', 'amount' => 1200, 'transfer_date' => now()->format('Y-m-d\TH:i'), 'payment_method' => 'Bank Transfer']);
    $response->assertSessionHasNoErrors();
    $transfer = AccountTransfer::firstOrFail();
    expect(AccountTransaction::where('reference_type', $transfer->getMorphClass())->where('reference_id', $transfer->id)->count())->toBe(2);
    $this->post(route('accounts.transfers.reverse', $transfer), ['reason' => 'Entered against wrong destination'])->assertSessionHasNoErrors();
    expect($transfer->fresh()->status)->toBe('Cancelled');
});

test('pending cheque does not post until it clears', function () {
    $this->post(route('accounts.cheques.store'), ['cheque_type' => 'Received', 'party_name' => 'Test Customer', 'cheque_number' => 'CQ-100', 'bank_name' => 'Test Bank', 'cheque_date' => today()->toDateString(), 'amount' => 2500, 'status' => 'Pending'])->assertSessionHasNoErrors();
    $cheque = Cheque::firstOrFail();
    expect(AccountTransaction::where('reference_type', $cheque->getMorphClass())->where('reference_id', $cheque->id)->count())->toBe(0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect(AccountTransaction::where('reference_type', $cheque->getMorphClass())->where('reference_id', $cheque->id)->where('credit', 2500)->exists())->toBeTrue();
});

test('ledger PDF and Excel reports export the selected period', function () {
    app(AccountService::class)->recordTransaction('main', 'income', 750, 'Export test receipt');

    $this->get(route('accounts.export', ['type' => 'main', 'format' => 'pdf', 'from' => today()->toDateString(), 'to' => today()->toDateString()]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('accounts.export', ['type' => 'main', 'format' => 'excel', 'from' => today()->toDateString(), 'to' => today()->toDateString()]))
        ->assertOk()->assertDownload();
});

test('daily closing adjustments require a reason and appear in the ledger', function () {
    $this->post(route('accounts.daily-closing.adjustment'), ['account_type' => 'main', 'action' => 'increase', 'amount' => 350, 'date' => today()->toDateString()])
        ->assertSessionHasErrors('reason');

    $this->post(route('accounts.daily-closing.adjustment'), ['account_type' => 'main', 'action' => 'increase', 'amount' => 350, 'date' => today()->toDateString(), 'reason' => 'Correct verified cash count'])
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('account_transactions', ['account_type' => 'main', 'transaction_type' => 'adjustment_increase', 'category' => 'closing_adjustment', 'credit' => 350]);

    $this->get(route('accounts.daily-closing.export', ['date' => today()->toDateString()]))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('accounts.daily-closing.export', ['date' => today()->toDateString(), 'format' => 'excel']))->assertOk()->assertDownload();
});
