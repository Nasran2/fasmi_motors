<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\PaymentCorrection;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashierService;
use App\Services\SettlementService;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user)->withOpenCashier();
    Account::ensureSystemAccounts();

    $customer = Customer::create(['name' => 'Delete Payment Customer', 'phone' => '0772223344']);
    $bike = $customer->bikes()->create(['number' => 'XC-8196', 'model' => 'Yamaha FZ']);
    $intake = BikeIntake::create([
        'intake_number' => 'IN-DEL-PAY',
        'bike_id' => $bike->id,
        'complaint' => 'Engine service',
        'created_by' => $this->user->id,
    ]);
    $this->job = JobCard::create([
        'number' => 'JOB-DEL-PAY',
        'bike_intake_id' => $intake->id,
        'status' => 'Completed',
        'priority' => 'Normal',
        'service_total' => 600,
        'parts_total' => 400,
        'created_by' => $this->user->id,
    ]);
});

test('deleting a job payment completely removes it from all accounts bills and reports and allows paying again cleanly', function () {
    $mainId = Account::systemAccount('main')->id;
    $this->post(route('jobs.invoice', $this->job), [
        'payments' => [['method' => 'Cash', 'amount' => 1000, 'account_id' => $mainId]],
    ])->assertSessionHasNoErrors();

    $invoice = $this->job->fresh()->invoice;
    $receipt = $invoice->payments()->firstOrFail();
    $receiptNo = $receipt->number;

    // Verify Delete payment button appears on job card page
    $this->get(route('jobs.show', $this->job))
        ->assertOk()
        ->assertSee('data-delete-payment="'.$receipt->id.'"', false)
        ->assertSeeText('Delete payment');

    // Delete the payment
    $this->delete(route('jobs.payments.destroy', [$this->job, $receipt]))
        ->assertRedirect(route('jobs.show', $this->job))
        ->assertSessionHasNoErrors();

    // 1. Database & balances completely clean (zero reversals, zero payment rows)
    $invoice->refresh();
    expect(Payment::count())->toBe(0)
        ->and(AccountTransaction::where('reference_type', $receipt->getMorphClass())->count())->toBe(0)
        ->and(AccountTransaction::where('transaction_type', 'reversal')->count())->toBe(0)
        ->and(AccountTransaction::where('status', 'reversed')->count())->toBe(0)
        ->and((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->due_amount)->toBe(1000.0)
        ->and($invoice->status)->toBe('Unpaid')
        ->and($invoice->payment_method)->toBe('Credit/Due')
        ->and($invoice->due_date)->not->toBeNull()
        ->and(Account::systemAccount('main')->balance)->toBe(0.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(0.0);

    // 2. Cashier summary shows zero credits, debits, adjustments, and collections
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['display_accounts']['main']['income'])->toBe(0.0)
        ->and($summary['display_accounts']['main']['outgoing'])->toBe(0.0)
        ->and($summary['display_accounts']['main']['adjustments'])->toBe(0.0)
        ->and($summary['display_accounts']['spare_parts']['income'])->toBe(0.0)
        ->and($summary['display_accounts']['spare_parts']['outgoing'])->toBe(0.0)
        ->and($summary['cash_received'])->toBe(0.0);

    // 3. Job page, public bill, sales pages, and all reports do NOT show the deleted receipt anywhere
    $this->get(route('jobs.show', $this->job))
        ->assertOk()
        ->assertDontSeeText($receiptNo)
        ->assertDontSeeText('Payment corrected')
        ->assertSee('data-job-id="'.$this->job->id.'"', false);

    $this->get(route('invoices.public', $invoice->public_token))
        ->assertOk()
        ->assertDontSeeText($receiptNo)
        ->assertSeeText('1,000.00');

    foreach ([
        route('sales.payments'),
        route('sales.customer-payments'),
        route('sales.invoices.show', $invoice),
        route('reports.collections'),
        route('reports.main-account'),
        route('reports.spare-parts-account'),
        route('reports.bank-account'),
        route('reports.cash-book'),
    ] as $url) {
        $this->get($url)->assertOk()->assertDontSeeText($receiptNo)->assertDontSeeText('Reversal of');
    }

    // Due bills accurately show the recovered Rs. 1,000.00 due
    $this->get(route('sales.dues'))->assertOk()->assertSeeText($invoice->number)->assertSeeText('1,000.00');
    $this->get(route('reports.due-bills'))->assertOk()->assertSeeText($invoice->number);

    // 4. Customer can now pay the recovered due cleanly without any issues
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(),
        'invoice_ids' => [$invoice->id],
        'payments' => [['method' => 'Cash', 'amount' => 1000, 'account_id' => $mainId]],
    ])->assertSessionHasNoErrors();

    $invoice->refresh();
    expect((float) $invoice->paid_amount)->toBe(1000.0)
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->status)->toBe('Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(600.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(400.0);
});

test('deleting a duplicate second receipt with cash change restores partial due while keeping the first receipt intact', function () {
    $this->job->update([
        'service_total' => 0,
        'labor_total' => 1000,
        'parts_total' => 6170,
        'fixed_discount' => 20,
        'discount' => 20,
    ]);
    $mainId = Account::systemAccount('main')->id;

    // First payment: Rs. 5,950
    $this->post(route('jobs.invoice', $this->job), [
        'payments' => [['method' => 'Cash', 'amount' => 5950, 'account_id' => $mainId]],
    ])->assertSessionHasNoErrors();

    $invoice = $this->job->fresh()->invoice;
    $firstReceipt = $invoice->payments()->firstOrFail();

    // Accidental duplicate Rs. 5,950 cash payment (Applied: 1,200, Change: 4,750)
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(),
        'invoice_ids' => [$invoice->id],
        'payments' => [['method' => 'Cash', 'amount' => 5950, 'account_id' => $mainId]],
    ])->assertSessionHasNoErrors();

    $secondReceipt = $invoice->payments()->where('id', '!=', $firstReceipt->id)->firstOrFail();
    expect((float) $secondReceipt->amount)->toBe(1200.0)
        ->and((float) $secondReceipt->change_amount)->toBe(4750.0);

    // Delete the second receipt
    $this->delete(route('jobs.payments.destroy', [$this->job, $secondReceipt]))
        ->assertRedirect(route('jobs.show', $this->job))
        ->assertSessionHasNoErrors();

    $invoice->refresh();
    expect(Payment::count())->toBe(1)
        ->and((float) $invoice->paid_amount)->toBe(5950.0)
        ->and((float) $invoice->due_amount)->toBe(1200.0)
        ->and((float) $invoice->received_amount)->toBe(5950.0)
        ->and((float) $invoice->change_amount)->toBe(0.0)
        ->and($invoice->status)->toBe('Partially Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(4950.0)
        ->and(AccountTransaction::where('transaction_type', 'reversal')->count())->toBe(0)
        ->and(AccountTransaction::balanceAdjustments()->count())->toBe(0);

    $this->get(route('jobs.show', $this->job))
        ->assertOk()
        ->assertSeeText($firstReceipt->number)
        ->assertDontSeeText($secondReceipt->number);
});

test('deleting the first receipt cleanly rebuilds the second receipt allocation without adjustment clutter', function () {
    $mainId = Account::systemAccount('main')->id;
    $bankId = Account::systemAccount('bank')->id;

    // First receipt: Rs. 600 Cash (covers Main 600)
    $this->post(route('jobs.invoice', $this->job), [
        'payments' => [['method' => 'Cash', 'amount' => 600, 'account_id' => $mainId]],
    ])->assertSessionHasNoErrors();

    $invoice = $this->job->fresh()->invoice;
    $firstReceipt = $invoice->payments()->firstOrFail();

    // Second receipt: Rs. 400 Card (covers Spare Parts 400 -> Bank)
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(),
        'invoice_ids' => [$invoice->id],
        'payments' => [['method' => 'Card', 'amount' => 400, 'account_id' => $bankId]],
    ])->assertSessionHasNoErrors();

    $secondReceipt = $invoice->payments()->where('id', '!=', $firstReceipt->id)->firstOrFail();

    // Delete first receipt: second receipt (Rs. 400 Card) is now the first receipt so it covers Main 400
    $this->delete(route('jobs.payments.destroy', [$this->job, $firstReceipt]))
        ->assertSessionHasNoErrors();

    $invoice->refresh();
    expect(Payment::count())->toBe(1)
        ->and((float) $invoice->paid_amount)->toBe(400.0)
        ->and((float) $invoice->due_amount)->toBe(600.0)
        ->and($invoice->status)->toBe('Partially Paid')
        ->and($invoice->payment_method)->toBe('Card')
        ->and((float) $secondReceipt->fresh()->balance_after)->toBe(600.0)
        ->and(Account::systemAccount('main')->balance)->toBe(400.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(-400.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(390.0)
        ->and(AccountTransaction::balanceAdjustments()->count())->toBe(0)
        ->and(AccountTransaction::where('transaction_type', 'reversal')->count())->toBe(0);
});

test('deleting an edited payment or pending cheque purges correction chains and unreferenced cheques completely', function () {
    $mainId = Account::systemAccount('main')->id;
    $bankId = Account::systemAccount('bank')->id;

    $this->post(route('jobs.invoice', $this->job), [
        'payments' => [['method' => 'Cash', 'amount' => 1000, 'account_id' => $mainId]],
    ])->assertSessionHasNoErrors();

    $invoice = $this->job->fresh()->invoice;
    $original = $invoice->payments()->firstOrFail();

    // Edit payment to a pending Cheque
    $this->put(route('jobs.payments.update', [$this->job, $original]), [
        'reason' => 'Changed to customer cheque',
        'method' => 'Cheque',
        'amount' => 1000,
        'account_id' => $bankId,
        'paid_at' => today()->toDateString(),
        'cheque_date' => today()->addDays(3)->toDateString(),
        'cheque_number' => 'CHQ-DEL-999',
        'bank_name' => 'Commercial Bank',
    ])->assertSessionHasNoErrors();

    $replacement = $invoice->payments()->where('status', 'Hold')->firstOrFail();
    expect(PaymentCorrection::count())->toBe(1)->and(Cheque::count())->toBe(1);

    // Now delete the replacement cheque payment
    $this->delete(route('jobs.payments.destroy', [$this->job, $replacement]))
        ->assertSessionHasNoErrors();

    $invoice->refresh();
    expect(Payment::count())->toBe(0)
        ->and(PaymentCorrection::count())->toBe(0)
        ->and(Cheque::withTrashed()->count())->toBe(0)
        ->and(AccountTransaction::whereIn('reference_id', [$original->id, $replacement->id])->where('reference_type', $original->getMorphClass())->count())->toBe(0)
        ->and((float) $invoice->hold_amount)->toBe(0.0)
        ->and((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->due_amount)->toBe(1000.0)
        ->and($invoice->status)->toBe('Unpaid');
});

test('protected payments and unauthorized users cannot delete job payments', function () {
    $bankId = Account::systemAccount('bank')->id;
    $this->post(route('jobs.invoice', $this->job), [
        'payments' => [[
            'method' => 'Cheque',
            'amount' => 1000,
            'account_id' => $bankId,
            'cheque_date' => today()->toDateString(),
            'cheque_number' => 'CHQ-LOCKED-1',
            'bank_name' => 'Sampath Bank',
        ]],
    ])->assertSessionHasNoErrors();

    $invoice = $this->job->fresh()->invoice;
    $held = $invoice->payments()->firstOrFail();

    // Viewer role cannot delete
    $viewerRole = Role::create(['name' => 'Payment Viewer Only', 'permissions' => ['job_card.view']]);
    $viewer = User::factory()->create(['role_id' => $viewerRole->id]);
    $this->actingAs($viewer)->delete(route('jobs.payments.destroy', [$this->job, $held]))->assertForbidden();

    // Forwarded cheque cannot be deleted here
    $this->actingAs($this->user)->withOpenCashier();
    $supplier = Supplier::create(['name' => 'Parts Supplier']);
    $held->cheque->update(['forwarded_supplier_id' => $supplier->id]);
    $this->delete(route('jobs.payments.destroy', [$this->job, $held]))->assertSessionHasErrors('payment');
    expect(Payment::count())->toBe(1);

    // Cleared cheque cannot be deleted here
    $held->cheque->update(['forwarded_supplier_id' => null]);
    app(SettlementService::class)->resolve($held->cheque, 'Cleared', today()->toDateString());
    $this->delete(route('jobs.payments.destroy', [$this->job, $held->fresh()]))->assertSessionHasErrors('payment');
    expect(Payment::count())->toBe(1);
});
