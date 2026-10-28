<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]))->withOpenCashier();
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Cash Customer', 'phone' => '0771234567']);
    $bike = $customer->bikes()->create(['number' => 'CHANGE-100', 'model' => 'Honda Dio']);
    $intake = BikeIntake::create(['intake_number' => 'IN-CHANGE-100', 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => auth()->id()]);
    $this->job = JobCard::create(['number' => 'JOB-CHANGE-100', 'bike_intake_id' => $intake->id, 'status' => 'Completed', 'priority' => 'Normal', 'parts_total' => 150, 'service_total' => 100, 'discount' => 25, 'created_by' => auth()->id()]);
    $this->cash = ['method' => 'Cash', 'amount' => 500, 'account_id' => Account::systemAccount('main')->id];
});

test('cash above the job total records received money and change without inflating income', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$this->cash]])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    $payment = $invoice->payments()->firstOrFail();
    expect((float) $invoice->total)->toBe(225.0)
        ->and((float) $invoice->paid_amount)->toBe(225.0)
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->received_amount)->toBe(500.0)
        ->and($invoice->change_amount)->toBe(275.0)
        ->and($invoice->status)->toBe('Paid')
        ->and((float) $payment->amount)->toBe(225.0)
        ->and((float) $payment->received_amount)->toBe(500.0)
        ->and((float) $payment->change_amount)->toBe(275.0)
        ->and(Account::systemAccount('main')->balance)->toBe(75.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(150.0);

    foreach ([route('jobs.show', $this->job), route('sales.invoices.show', $invoice), route('invoices.public', $invoice->public_token), route('invoices.public', ['token' => $invoice->public_token, 'format' => '80mm'])] as $url) {
        $this->get($url)->assertOk()->assertSeeInOrder(['Amount received', '500.00', 'Change to return', '275.00']);
    }
    $this->get(route('invoices.public.pdf', $invoice->public_token))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('invoices.public.pdf', ['token' => $invoice->public_token, 'format' => '80mm']))->assertOk()->assertHeader('content-type', 'application/pdf');

    $this->delete(route('sales.payments.cancel', $payment), ['reason' => 'Correct receipt'])->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->due_amount)->toBe(225.0)
        ->and($invoice->fresh()->received_amount)->toBe(0.0)
        ->and($invoice->fresh()->change_amount)->toBe(0.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0);
});

test('mixed payment change comes from cash regardless of input order', function ($method, $reverse) {
    $other = ['method' => $method, 'amount' => 100, 'account_id' => Account::systemAccount('bank')->id];
    if ($method === 'Cheque') {
        $other += ['cheque_date' => today()->toDateString(), 'cheque_number' => 'CHANGE-CHEQUE', 'bank_name' => 'Test Bank'];
    }
    $rows = $reverse ? [$other, $this->cash] : [$this->cash, $other];
    $this->post(route('jobs.invoice', $this->job), ['payments' => $rows])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    expect($invoice->received_amount)->toBe(600.0)
        ->and($invoice->change_amount)->toBe(375.0)
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and((float) $invoice->payments()->where('method', 'Cash')->first()->amount)->toBe(125.0)
        ->and((float) $invoice->payments()->where('method', $method)->first()->amount)->toBe(100.0)
        ->and((float) $invoice->paid_amount)->toBe($method === 'Cheque' ? 125.0 : 225.0)
        ->and((float) $invoice->hold_amount)->toBe($method === 'Cheque' ? 100.0 : 0.0);
    if ($method === 'Cheque') {
        expect((float) Cheque::firstOrFail()->amount)->toBe(100.0);
    }
})->with([['Card', false], ['Card', true], ['Cheque', false], ['Cheque', true]]);

test('non-cash overpayments still roll back the entire bill', function ($method) {
    $row = ['method' => $method, 'amount' => 500, 'account_id' => Account::systemAccount('bank')->id];
    if ($method === 'Cheque') {
        $row += ['cheque_date' => today()->toDateString(), 'cheque_number' => 'EXCESS-CHEQUE', 'bank_name' => 'Test Bank'];
    }
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$this->cash, $row]])->assertSessionHasErrors('paid_amount');
    expect(Invoice::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(Cheque::count())->toBe(0)
        ->and(AccountTransaction::count())->toBe(0)
        ->and($this->job->fresh()->status)->toBe('Completed');
})->with(['Card', 'Bank Transfer', 'Cheque']);

test('partial exact and decimal cash amounts keep due and change correct', function ($received, $paid, $due, $change) {
    $this->job->update(['parts_total' => 0, 'service_total' => 225.75, 'discount' => 0]);
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => $received, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    expect((float) $invoice->paid_amount)->toBe($paid)
        ->and((float) $invoice->due_amount)->toBe($due)
        ->and($invoice->change_amount)->toBe($change)
        ->and($invoice->received_amount)->toBe($received)
        ->and(Account::systemAccount('main')->balance)->toBe($paid);
})->with([[100.0, 100.0, 125.75, 0.0], [225.75, 225.75, 0.0, 0.0], [500.0, 225.75, 0.0, 274.25]]);

test('cash is returned in full when a non-cash payment covers the whole bill', function () {
    $card = ['method' => 'Card', 'amount' => 225, 'account_id' => Account::systemAccount('bank')->id];
    $this->post(route('jobs.invoice', $this->job), ['payments' => [$this->cash, $card]])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    $cash = $invoice->payments()->where('method', 'Cash')->firstOrFail();
    expect((float) $invoice->paid_amount)->toBe(225.0)
        ->and($invoice->received_amount)->toBe(725.0)
        ->and($invoice->change_amount)->toBe(500.0)
        ->and((float) $cash->amount)->toBe(0.0)
        ->and(AccountTransaction::where('reference_type', $cash->getMorphClass())->where('reference_id', $cash->id)->count())->toBe(0);
});

test('separate cash rows retain their tendered amounts and combine change correctly', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => [array_replace($this->cash, ['amount' => 100]), $this->cash]])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    expect((float) $invoice->paid_amount)->toBe(225.0)
        ->and($invoice->received_amount)->toBe(600.0)
        ->and($invoice->change_amount)->toBe(375.0)
        ->and($invoice->payments()->count())->toBe(2);
});

test('the job billing page also previews and saves cash change', function () {
    $this->get(route('sales.job-billing', ['job' => $this->job->id]))->assertOk()->assertSee('Change to return')->assertSee('refreshTotal()', false);
    $this->post(route('sales.job-billing.store', $this->job), ['service_total' => 100, 'discount' => 25, 'payments' => [$this->cash]])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    expect((float) $invoice->paid_amount)->toBe(225.0)
        ->and($invoice->received_amount)->toBe(500.0)
        ->and($invoice->change_amount)->toBe(275.0);
});

test('pay bill accepts excess cash after the invoice has already been generated', function ($initial, $due, $change) {
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => $initial, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    expect((float) $invoice->due_amount)->toBe($due);
    foreach ([route('jobs.show', $this->job), route('customers.dues'), route('live-board.index')] as $url) {
        $this->get($url)->assertOk()->assertSee('payment-due-selected', false)->assertSee('Change to return');
    }

    $this->post(route('customers.dues.payment', $invoice), ['payments' => [$this->cash]])->assertRedirect()->assertSessionHasNoErrors();
    $invoice->refresh();
    $receipt = $invoice->payments()->latest('id')->firstOrFail();
    expect((float) $invoice->paid_amount)->toBe(225.0)
        ->and((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->status)->toBe('Paid')
        ->and((float) $receipt->amount)->toBe($due)
        ->and((float) $receipt->received_amount)->toBe(500.0)
        ->and((float) $receipt->change_amount)->toBe($change)
        ->and($invoice->received_amount)->toBe($initial + 500.0)
        ->and($invoice->change_amount)->toBe($change)
        ->and(Account::systemAccount('main')->balance)->toBe(75.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(150.0);
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSee('Change to return')->assertSee(number_format($change, 2));
    $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => '80mm']))->assertOk()->assertSee('Change to return')->assertSee(number_format($change, 2));
})->with([[0.0, 225.0, 275.0], [100.0, 125.0, 375.0]]);

test('pay bill applies non-cash first before calculating cash change', function ($method) {
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    $other = ['method' => $method, 'amount' => 100, 'account_id' => Account::systemAccount('bank')->id];
    if ($method === 'Cheque') {
        $other += ['cheque_date' => today()->toDateString(), 'cheque_number' => 'DUE-CHANGE-CHEQUE', 'bank_name' => 'Test Bank'];
    }
    $this->post(route('customers.dues.payment', $invoice), ['payments' => [$this->cash, $other]])->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->due_amount)->toBe(0.0)
        ->and($invoice->fresh()->change_amount)->toBe(375.0)
        ->and((float) $invoice->payments()->where('method', 'Cash')->first()->amount)->toBe(125.0)
        ->and((float) $invoice->payments()->where('method', $method)->first()->amount)->toBe(100.0);
})->with(['Card', 'Cheque']);

test('pay bill rejects excess non-cash payments without posting cash', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    $card = ['method' => 'Card', 'amount' => 300, 'account_id' => Account::systemAccount('bank')->id];
    $this->post(route('customers.dues.payment', $invoice), ['payments' => [$this->cash, $card]])->assertSessionHasErrors('amount');
    expect((float) $invoice->fresh()->due_amount)->toBe(225.0)
        ->and($invoice->payments()->count())->toBe(0)
        ->and(AccountTransaction::count())->toBe(0);
});
