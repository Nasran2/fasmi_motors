<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\CollectionReportService;
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

it('converts bank transfer main share to cashier cash without duplicating collections or bank transfers', function () {
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
    $parts = $summary['allocation_accounts']['spare_parts'];

    expect($main['opening'])->toBe(68578.0)
        ->and($main['expenses'])->toBe(4620.0)
        ->and($main['suggested_addition'])->toBe(39750.0)
        ->and($main['bank_converted_cash'])->toBe(9000.0)
        ->and($main['non_cash_income'])->toBe(0.0)
        ->and($main['other_movements'])->toBe(0.0)
        ->and(round($main['base'] + $main['suggested_addition'], 2))->toBe(103708.0)
        ->and($parts['bank_conversion_deduction'])->toBe(9000.0)
        ->and($parts['base'])->toBe(91000.0)
        ->and($summary['cash_received'])->toBe(30750.0)
        ->and($summary['bank_received'])->toBe(9000.0)
        ->and($summary['cash_accounts']['main']['other_in'])->toBe(9000.0)
        ->and($summary['cash_in_hand'])->toBe(44750.0)
        ->and(Account::systemAccount('main')->balance)->toBe(103708.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(91000.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(59000.0);

    $collections = app(CollectionReportService::class)->rows(today()->toDateString(), today()->toDateString());
    expect($collections)->toHaveCount(2)
        ->and(round((float) $collections->sum('amount'), 2))->toBe(39750.0);

    $this->get(route('cashier.index'))->assertOk()
        ->assertSeeText('Opening balance: Rs. 68,578.00')
        ->assertSeeText('Rs. 4,620.00')
        ->assertSeeText('+ Added today: Rs. 39,750.00')
        ->assertSeeText('Includes Rs. 9,000.00 converted from bank transfer')
        ->assertSeeText('Bank transfer cash to Main (opening):')
        ->assertSeeText('Rs. 9,000.00')
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

it('posts full mixed bank transfer to bank, converts main share to cash from spare parts opening balance, and keeps spare parts drawer cash untouched', function () {
    $this->service->open(5000, 'Start of day count', separateCash: true, allocations: ['main' => 68578, 'spare_parts' => 100000, 'bank' => 50000, 'washing' => 0]);
    $customer = Customer::create(['name' => 'Walk-in Customer', 'phone' => '0770001111']);
    $invoice = Invoice::create([
        'number' => 'INV-20261110-0003', 'invoice_type' => 'Job Card Bill', 'customer_id' => $customer->id,
        'parts_amount' => 7460, 'service_amount' => 5500, 'subtotal' => 12960, 'total' => 12960,
        'due_amount' => 0, 'paid_amount' => 12960, 'status' => 'Paid',
        'created_by' => auth()->id(), 'public_token' => 'inv-mixed-bank-conversion',
    ]);
    $payment = $invoice->payments()->create([
        'number' => 'RCPT-MIX-BANK', 'amount' => 12960, 'method' => 'Bank Transfer',
        'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id(),
    ]);
    $this->accounts->receiveInvoicePayment($invoice, $payment);

    $txs = AccountTransaction::where('reference_id', $payment->id)->orderBy('id')->get();
    expect($txs)->toHaveCount(3)
        ->and($txs->firstWhere('account_type', 'bank')->credit)->toBe('12960.00')
        ->and($txs->firstWhere('account_type', 'main')->payment_method)->toBe('Cash')
        ->and($txs->firstWhere('account_type', 'main')->converted_from_method)->toBe('Bank Transfer')
        ->and($txs->firstWhere('account_type', 'main')->credit)->toBe('5500.00')
        ->and($txs->firstWhere('account_type', 'spare_parts')->transaction_type)->toBe('transfer_out')
        ->and($txs->firstWhere('account_type', 'spare_parts')->category)->toBe('bank_to_main_cash')
        ->and($txs->firstWhere('account_type', 'spare_parts')->funding_source)->toBe('spare_parts')
        ->and($txs->firstWhere('account_type', 'spare_parts')->debit)->toBe('5500.00');

    expect(Account::systemAccount('bank')->balance)->toBe(62960.0)
        ->and(Account::systemAccount('main')->balance)->toBe(74078.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(94500.0);

    $summary = $this->service->summary(today()->toDateString());
    expect($summary['cash_received'])->toBe(0.0)
        ->and($summary['bank_received'])->toBe(12960.0)
        ->and($summary['cash_accounts']['main']['other_in'])->toBe(5500.0)
        ->and($summary['cash_accounts']['spare_parts']['closing'])->toBe(100000.0)
        ->and($summary['cash_in_hand'])->toBe(10500.0)
        ->and($summary['allocation_accounts']['main']['suggested_addition'])->toBe(5500.0)
        ->and($summary['allocation_accounts']['main']['bank_converted_cash'])->toBe(5500.0)
        ->and($summary['allocation_accounts']['spare_parts']['suggested_addition'])->toEqual(0.0)
        ->and($summary['allocation_accounts']['spare_parts']['bank_conversion_deduction'])->toBe(5500.0)
        ->and($summary['allocation_accounts']['spare_parts']['base'])->toBe(94500.0);

    $collections = app(CollectionReportService::class)->rows(today()->toDateString(), today()->toDateString());
    expect($collections)->toHaveCount(1)
        ->and($collections->first()['channel'])->toBe('bank')
        ->and($collections->first()['amount'])->toBe(12960.0);

    $this->get(route('cashier.index'))->assertOk()
        ->assertSeeText('Includes Rs. 5,500.00 converted from bank transfer')
        ->assertSeeText('Bank transfer cash to Main (opening):')
        ->assertSeeText('Rs. 5,500.00');
});

it('does not deduct spare parts for parts-only bank transfers and cleanly reverses mixed cash plus bank cancellations and close snapshots', function () {
    $session = $this->service->open(5000, 'Start of day count', separateCash: true, allocations: ['main' => 68578, 'spare_parts' => 100000, 'bank' => 50000, 'washing' => 0]);
    $customer = Customer::create(['name' => 'Split Customer', 'phone' => '0770002222']);

    $partsOnly = Invoice::create([
        'number' => 'INV-PARTS-BANK', 'invoice_type' => 'Parts Sale', 'customer_id' => $customer->id,
        'parts_amount' => 7460, 'service_amount' => 0, 'subtotal' => 7460, 'total' => 7460,
        'due_amount' => 0, 'paid_amount' => 7460, 'status' => 'Paid',
        'created_by' => auth()->id(), 'public_token' => 'inv-parts-only-bank',
    ]);
    $partsPayment = $partsOnly->payments()->create(['number' => 'RCPT-PARTS-BANK', 'amount' => 7460, 'method' => 'Bank Transfer', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $this->accounts->receiveInvoicePayment($partsOnly, $partsPayment);

    expect(Account::systemAccount('bank')->balance)->toBe(57460.0)
        ->and(Account::systemAccount('main')->balance)->toBe(68578.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(100000.0);

    $mixed = Invoice::create([
        'number' => 'INV-MIX-CANCEL', 'invoice_type' => 'Job Card Bill', 'customer_id' => $customer->id,
        'parts_amount' => 7460, 'service_amount' => 5500, 'subtotal' => 12960, 'total' => 12960,
        'due_amount' => 0, 'paid_amount' => 12960, 'status' => 'Paid',
        'created_by' => auth()->id(), 'public_token' => 'inv-mix-cancel',
    ]);
    $cash = $mixed->payments()->create(['number' => 'RCPT-MIX-CASH', 'amount' => 4000, 'method' => 'Cash', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $bank = $mixed->payments()->create(['number' => 'RCPT-MIX-BANK2', 'amount' => 8960, 'method' => 'Bank Transfer', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $this->accounts->receiveInvoicePayment($mixed, $cash);
    $this->accounts->receiveInvoicePayment($mixed, $bank);

    expect(Account::systemAccount('main')->balance)->toBe(74078.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(98500.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(66420.0);

    $this->delete(route('sales.payments.cancel', $bank), ['reason' => 'Wrong bank transfer posted'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(72578.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(100000.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(57460.0);

    $bank2 = $mixed->payments()->create(['number' => 'RCPT-MIX-BANK3', 'amount' => 8960, 'method' => 'Bank Transfer', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $this->accounts->receiveInvoicePayment($mixed->fresh(), $bank2);

    $summaryBeforeClose = $this->service->summary(today()->toDateString());
    expect($summaryBeforeClose['allocation_accounts']['main']['bank_converted_cash'])->toBe(1500.0)
        ->and($summaryBeforeClose['allocation_accounts']['main']['suggested_addition'])->toBe(5500.0)
        ->and($summaryBeforeClose['allocation_accounts']['spare_parts']['bank_conversion_deduction'])->toBe(1500.0);

    $this->service->close($session, [
        'owner_withdrawal' => 0,
        'closing_balance' => 5000,
        'separate_cash_balance' => 1,
        'account_additions' => ['main' => 5500, 'spare_parts' => 0],
        'balances' => ['bank' => 66420],
        'balance_version' => $this->service->fingerprint($summaryBeforeClose),
    ]);

    $this->service->open(5000, 'Reopen same day', separateCash: true, allocations: [
        'main' => 74078, 'spare_parts' => 98500, 'bank' => 66420, 'washing' => 0,
    ]);
    $cycle2 = $this->service->summary(today()->toDateString());
    expect($cycle2['allocation_accounts']['main']['opening'])->toBe(74078.0)
        ->and($cycle2['allocation_accounts']['main']['bank_converted_cash'])->toBe(0.0)
        ->and($cycle2['allocation_accounts']['spare_parts']['bank_conversion_deduction'])->toBe(0.0);
});
