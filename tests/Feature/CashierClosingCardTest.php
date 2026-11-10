<?php

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\CashierSession;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\OutgoingFundingService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    Account::systemAccount('main')->update(['opening_balance' => 68578]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 100000]);
    Account::systemAccount('bank')->update(['opening_balance' => 50000]);
    $this->service = app(CashierService::class);
    $this->accounts = app(AccountService::class);
});

it('adds bank transfer once alongside cash receipts on the closing card without duplicating it', function () {
    $this->service->open(5000, 'Start of day count', separateCash: true, allocations: ['main' => 68578, 'spare_parts' => 100000, 'bank' => 50000, 'washing' => 0]);

    $invoice = Invoice::create([
        'number' => 'INV-BANK-ONCE', 'invoice_type' => 'Job Card Bill',
        'subtotal' => 39750, 'service_amount' => 39750, 'total' => 39750,
        'due_amount' => 0, 'paid_amount' => 39750, 'status' => 'Paid',
        'created_by' => auth()->id(), 'public_token' => 'inv-bank-once',
    ]);
    $cashPayment = $invoice->payments()->create(['number' => 'RCPT-CASH', 'amount' => 30750, 'method' => 'Cash', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $bankPayment = $invoice->payments()->create(['number' => 'RCPT-BANK', 'amount' => 9000, 'method' => 'Bank Transfer', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $this->accounts->receiveInvoicePayment($invoice, $cashPayment);
    $this->accounts->receiveInvoicePayment($invoice, $bankPayment);

    $expense = Expense::create([
        'number' => 'EXP-MAIN-1', 'expense_date' => today(), 'category' => 'Workshop',
        'description' => 'Workshop expense', 'account_id' => Account::systemAccount('main')->id,
        'amount' => 4620, 'payment_method' => 'Cash', 'created_by' => auth()->id(),
    ]);
    app(OutgoingFundingService::class)->post([['source' => 'main', 'amount' => 4620]], 4620, 'Workshop expense', $expense, 'Cash', today()->toDateString(), 'Workshop');

    $summary = $this->service->summary(today()->toDateString());
    $main = $summary['allocation_accounts']['main'];

    expect($main['opening'])->toBe(68578.0)
        ->and($main['expenses'])->toBe(4620.0)
        ->and($main['suggested_addition'])->toBe(30750.0)
        ->and($main['non_cash_income'])->toBe(9000.0)
        ->and($main['other_movements'])->toBe(0.0)
        ->and(round($main['base'] + $main['suggested_addition'], 2))->toBe(103708.0);

    $this->get(route('cashier.index'))->assertOk()
        ->assertSeeText('Opening balance: Rs. 68,578.00')
        ->assertSeeText('Rs. 4,620.00')
        ->assertSeeText('+ Added today: Rs. 39,750.00')
        ->assertSeeText('(Rs. 30,750.00 cash + Rs. 9,000.00 bank transfer)')
        ->assertSeeText('Account balance after addition: Rs. 103,708.00')
        ->assertDontSeeText('Transfers / adjustments');
});

it('does not count prior-cycle cash or reversed entries or transfers as bank transfers', function () {
    $session = $this->service->open(5000, 'Morning open', separateCash: true, allocations: ['main' => 68578, 'spare_parts' => 100000, 'bank' => 50000, 'washing' => 0]);

    // Cycle 1: 9,000 cash receipt + 500 bank receipt + 200 expense, then close and reopen.
    $this->accounts->recordTransaction('main', 'income', 9000, 'Cycle 1 cash', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('main', 'income', 500, 'Cycle 1 bank', paymentMethod: 'Bank Transfer');
    $this->accounts->recordTransaction('bank', 'income', 500, 'Cycle 1 bank', paymentMethod: 'Bank Transfer');
    $this->accounts->recordTransaction('main', 'expense', 200, 'Cycle 1 expense', paymentMethod: 'Cash', fundingSource: 'main');

    $summaryBeforeClose = $this->service->summary(today()->toDateString());
    $this->service->close($session, [
        'owner_withdrawal' => 0,
        'closing_balance' => 5000,
        'separate_cash_balance' => 1,
        'account_additions' => ['main' => 9000, 'spare_parts' => 0],
        'balances' => ['bank' => 50500],
        'balance_version' => $this->service->fingerprint($summaryBeforeClose),
    ]);

    // Reopen same day.
    $this->service->open(5000, 'Afternoon reopen', separateCash: true, allocations: ['main' => 77878, 'spare_parts' => 100000, 'bank' => 50500, 'washing' => 0]);

    // Cycle 2: 3,000 cash + reversed 1,000 cash + reversed 400 expense + 1,500 transfer from Spare Parts.
    $this->accounts->recordTransaction('main', 'income', 3000, 'Cycle 2 cash', paymentMethod: 'Cash');
    $revCash = $this->accounts->recordTransaction('main', 'income', 1000, 'Mistaken cash receipt', paymentMethod: 'Cash');
    $this->accounts->reverseTransaction($revCash, 'Duplicate receipt');
    $revExp = $this->accounts->recordTransaction('main', 'expense', 400, 'Mistaken expense', paymentMethod: 'Cash', fundingSource: 'main');
    $this->accounts->reverseTransaction($revExp, 'Wrong amount');

    $transfer = AccountTransfer::create([
        'transfer_no' => 'AT-TEST-1',
        'from_account_id' => Account::systemAccount('spare_parts')->id,
        'to_account_id' => Account::systemAccount('main')->id,
        'amount' => 1500, 'transfer_date' => now(), 'payment_method' => 'Cash', 'status' => 'Completed', 'created_by' => auth()->id(),
    ]);
    $this->accounts->recordTransaction('spare_parts', 'transfer_out', 1500, 'Transfer to Main', $transfer, 'Cash', category: 'transfer', fundingSource: 'spare_parts');
    $this->accounts->recordTransaction('main', 'transfer_in', 1500, 'Transfer from Spare Parts', $transfer, 'Cash', category: 'transfer');

    $summary = $this->service->summary(today()->toDateString());
    $main = $summary['allocation_accounts']['main'];

    expect($main['opening'])->toBe(77878.0)
        ->and($main['expenses'])->toBe(0.0)
        ->and($main['suggested_addition'])->toBe(3000.0)
        ->and($main['non_cash_income'])->toBe(0.0)
        ->and($main['other_movements'])->toBe(1500.0)
        ->and(round($main['base'] + $main['suggested_addition'], 2))->toBe(82378.0);

    $this->get(route('cashier.index'))->assertOk()
        ->assertSeeText('Opening balance: Rs. 77,878.00')
        ->assertSeeText('+ Transfers / adjustments: Rs. 1,500.00')
        ->assertSeeText('+ Added today: Rs. 3,000.00')
        ->assertSeeText('Account balance after addition: Rs. 82,378.00')
        ->assertDontSeeText('bank transfer');
});

it('does not double count cashier-funded spare parts payouts in card expenses', function () {
    $this->service->open(5000, 'Start of day count', separateCash: true, allocations: ['main' => 68578, 'spare_parts' => 100000, 'bank' => 50000, 'washing' => 0]);

    $this->accounts->recordTransaction('spare_parts', 'income', 20000, 'Parts cash sale', paymentMethod: 'Cash');
    $this->accounts->recordTransaction('spare_parts', 'expense', 2000, 'Cashier-funded expense', paymentMethod: 'Cash', fundingSource: 'spare_parts_cashier');
    $this->accounts->recordTransaction('spare_parts', 'expense', 5000, 'Opening-funded expense', paymentMethod: 'Cash', fundingSource: 'spare_parts');

    $summary = $this->service->summary(today()->toDateString());
    $parts = $summary['allocation_accounts']['spare_parts'];

    expect($parts['opening'])->toBe(100000.0)
        ->and($parts['expenses'])->toBe(5000.0)
        ->and($parts['suggested_addition'])->toBe(18000.0)
        ->and($parts['non_cash_income'])->toBe(0.0)
        ->and($parts['other_movements'])->toBe(0.0)
        ->and(round($parts['base'] + $parts['suggested_addition'], 2))->toBe(113000.0);
});
