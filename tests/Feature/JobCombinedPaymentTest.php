<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\PaymentCorrection;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Services\SettlementService;
use App\Support\InvoiceDocument;
use App\Support\JobPaymentSummary;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Combined Customer', 'phone' => '0779990055']);
    $bike = $customer->bikes()->create(['number' => 'VO-1783', 'model' => 'Bajaj']);
    $intake = BikeIntake::create(['intake_number' => 'IN-COMBINED', 'bike_id' => $bike->id, 'complaint' => 'Repair', 'created_by' => auth()->id()]);
    $this->job = JobCard::create(['number' => 'JOB-COMBINED', 'bike_intake_id' => $intake->id, 'status' => 'Completed', 'priority' => 'Normal', 'parts_total' => 19510, 'labor_total' => 8000, 'fixed_discount' => 10, 'discount' => 10, 'created_by' => auth()->id()]);
    $this->opening = app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 10000, 'opening_balance_date' => today()->subDay()->toDateString()], $bike);
    $this->cash = ['method' => 'Cash', 'amount' => 35000, 'account_id' => Account::systemAccount('main')->id];
    $this->correction = $this->cash + ['reason' => 'Payment includes the opening balance', 'paid_at' => today()->toDateString()];
});

test('35000 received covers the current bill and opening balance leaving 2500 with no cash change', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$this->cash]])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $pieces = Payment::orderBy('id')->get();
    expect((float) $invoice->paid_amount)->toBe(27500.0)->and((float) $invoice->due_amount)->toBe(0.0)
        ->and((float) $this->opening->fresh()->paid_amount)->toBe(7500.0)->and((float) $this->opening->fresh()->due_amount)->toBe(2500.0)
        ->and($pieces->pluck('receipt_group_id')->unique())->toHaveCount(1)->and((float) $pieces->sum('received_amount'))->toBe(35000.0)
        ->and((float) $pieces->sum('change_amount'))->toBe(0.0)->and(Account::systemAccount('main')->balance)->toBe(15490.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(19510.0);
    $summary = JobPaymentSummary::data($this->job->fresh());
    expect($summary['paid'])->toBe(35000.0)->and($summary['due'])->toBe(2500.0)->and($summary['previousBalance'])->toBe(10000.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Rs. 37,500.00')->assertSeeText('Rs. 35,000.00')->assertSeeText('Rs. 2,500.00')
        ->assertSeeText('Include old balances in this payment');
    $document = InvoiceDocument::data($invoice);
    expect($document['grandTotal'])->toBe(37500.0)->and($document['summaryPaid'])->toBe(35000.0)->and($document['totalBalanceDue'])->toBe(2500.0);
    foreach (['a4', 'a5', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))->assertOk()->assertSeeText('35,000.00')->assertSeeText('2,500.00');
    }
});

test('editing a legacy current-only cash receipt reallocates excess and records all corrected allocations', function () {
    $this->post(route('jobs.invoice', $this->job), ['include_previous_balance' => 0, 'payments' => [$this->cash]])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $original = $invoice->payments()->sole();
    // Represents an existing receipt created before grouped allocations were supported.
    $original->update(['receipt_group_id' => null, 'source_job_card_id' => null]);
    expect((float) $original->change_amount)->toBe(7500.0)->and((float) $this->opening->fresh()->due_amount)->toBe(10000.0);
    $this->put(route('jobs.payments.update', [$this->job, $original]), $this->correction)->assertSessionHasNoErrors();
    $change = PaymentCorrection::sole();
    expect((float) $this->opening->fresh()->due_amount)->toBe(2500.0)->and($original->fresh()->status)->toBe('Cancelled')
        ->and($change->after['received_amount'])->toBe(35000)->and($change->after['change_amount'])->toBe(0)
        ->and($change->after['allocations'])->toHaveCount(2)->and($change->after['include_previous_balance'])->toBeTrue();
    $replacement = Payment::findOrFail($change->replacement_payment_id);
    $this->put(route('jobs.payments.update', [$this->job, $replacement]), array_replace($this->correction, ['amount' => 30000]))->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->due_amount)->toBe(7500.0)->and(PaymentCorrection::count())->toBe(2)
        ->and(JobPaymentSummary::data($this->job->fresh())['paid'])->toBe(30000.0);
    $latest = Payment::findOrFail(PaymentCorrection::latest('id')->first()->replacement_payment_id);
    $this->put(route('jobs.payments.update', [$this->job, $latest]), $this->correction + ['include_previous_balance' => 0])->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->due_amount)->toBe(10000.0)->and($invoice->fresh()->change_amount)->toBe(7500.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Include old balances: No')->assertSeeText('Payment includes the opening balance');
});

test('combined pending cheques can be corrected as one receipt and still obey clearance dates', function () {
    $row = array_replace($this->cash, ['method' => 'Cheque', 'account_id' => Account::systemAccount('bank')->id, 'cheque_date' => today()->addDays(3)->toDateString(), 'cheque_number' => 'COMBINED-HOLD', 'bank_name' => 'Test Bank']);
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$row]])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $held = $invoice->payments()->sole();
    $cheque = Cheque::sole();
    expect((float) $cheque->amount)->toBe(35000.0)->and($cheque->payments()->count())->toBe(2)
        ->and((float) $this->opening->fresh()->hold_amount)->toBe(7500.0)->and(AccountTransaction::count())->toBe(0);
    expect(fn () => app(SettlementService::class)->resolve($cheque, 'Cleared', today()->toDateString()))->toThrow(ValidationException::class);
    $this->put(route('jobs.payments.update', [$this->job, $held]), array_replace($this->correction, ['amount' => 30000]))->assertSessionHasNoErrors();
    expect($cheque->fresh()->status)->toBe('Cancelled')->and((float) $this->opening->fresh()->hold_amount)->toBe(0.0)
        ->and((float) $this->opening->fresh()->due_amount)->toBe(7500.0)->and(JobPaymentSummary::data($this->job->fresh())['paid'])->toBe(30000.0);
});

test('combined cheque clearing and return update both bills and protect settled cheques from editing', function () {
    $row = array_replace($this->cash, ['method' => 'Cheque', 'cheque_date' => today()->toDateString(), 'cheque_number' => 'COMBINED-PASS', 'bank_name' => 'Test Bank', 'account_id' => Account::systemAccount('bank')->id]);
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$row]])->assertSessionHasNoErrors();
    $cheque = Cheque::sole();
    $receipt = Payment::first();
    app(SettlementService::class)->resolve($cheque, 'Cleared', today()->toDateString());
    expect(JobPaymentSummary::data($this->job->fresh())['paid'])->toBe(35000.0)->and((float) $this->opening->fresh()->due_amount)->toBe(2500.0);
    $this->put(route('jobs.payments.update', [$this->job, $receipt]), $this->correction)->assertSessionHasErrors('payment');
    app(SettlementService::class)->resolve($cheque, 'Returned', reason: 'Bank rejected cheque');
    expect(JobPaymentSummary::data($this->job->fresh())['due'])->toBe(37500.0)->and(Account::systemAccount('main')->balance)->toBe(0.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0)->and(Account::systemAccount('bank')->balance)->toBe(0.0);
});

test('excess cash change is calculated once after all balances and mixed payment rows', function () {
    $card = array_replace($this->cash, ['method' => 'Card', 'amount' => 10000, 'account_id' => Account::systemAccount('bank')->id]);
    $this->post(route('jobs.invoice', $this->job), ['payments' => [array_replace($this->cash, ['amount' => 30000]), $card]])->assertSessionHasNoErrors();
    $summary = JobPaymentSummary::data($this->job->fresh());
    expect($summary['paid'])->toBe(37500.0)->and($summary['received'])->toBe(40000.0)->and($summary['change'])->toBe(2500.0)->and($summary['due'])->toBe(0.0);
});

test('an invalid combined correction rolls back every invoice allocation and ledger reversal', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$this->cash]])->assertSessionHasNoErrors();
    $receipt = Payment::first();
    $this->put(route('jobs.payments.update', [$this->job, $receipt]), array_replace($this->correction, ['method' => 'Card', 'amount' => 40000]))->assertSessionHasErrors('payments');
    expect($receipt->fresh()->status)->toBe('Completed')->and((float) $this->opening->fresh()->due_amount)->toBe(2500.0)
        ->and(PaymentCorrection::count())->toBe(0)->and(AccountTransaction::where('status', 'reversed')->count())->toBe(0);
});

test('combined payments stay with the billed customer after the bike changes ownership', function () {
    $this->post(route('jobs.invoice', $this->job), ['include_previous_balance' => 0, 'payments' => [$this->cash]])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $other = Customer::create(['name' => 'New Owner', 'phone' => '0779990066']);
    $otherOpening = app(OpeningBalanceService::class)->record($other, ['opening_balance' => 2000, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $this->job->intake->bike->update(['customer_id' => $other->id]);
    $this->put(route('jobs.payments.update', [$this->job, $invoice->payments()->sole()]), $this->correction)->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->due_amount)->toBe(2500.0)->and((float) $otherOpening->fresh()->due_amount)->toBe(2000.0)
        ->and($otherOpening->payments()->count())->toBe(0);
});

test('unchecked old balances and zero collections cannot create hidden allocations', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $this->post(route('jobs.dues.payment', $this->job), ['include_previous_balance' => 0, 'paid_at' => today()->toDateString(), 'invoice_ids' => [$invoice->id, $this->opening->id], 'payments' => [$this->cash]])->assertSessionHasErrors('invoice_ids');
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => [$invoice->id], 'payments' => []])->assertSessionHasErrors('amount');
    expect(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});
