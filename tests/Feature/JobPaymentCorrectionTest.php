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
use App\Services\SettlementService;
use App\Support\PreviousCustomerBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user)->withOpenCashier();
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Correction Customer', 'phone' => '0771234555']);
    $bike = $customer->bikes()->create(['number' => 'VO-1783', 'model' => 'Honda']);
    $intake = BikeIntake::create(['intake_number' => 'IN-CORRECTION', 'bike_id' => $bike->id, 'complaint' => 'Repair', 'created_by' => $this->user->id]);
    $this->job = JobCard::create(['number' => 'JOB-CORRECTION', 'bike_intake_id' => $intake->id, 'status' => 'Completed', 'priority' => 'Normal', 'service_total' => 600, 'parts_total' => 400, 'created_by' => $this->user->id]);
    $this->post(route('jobs.invoice', $this->job), ['payments' => [['method' => 'Cash', 'amount' => 1000, 'account_id' => Account::systemAccount('main')->id, 'note' => 'Original note']]])->assertSessionHasNoErrors();
    $this->invoice = $this->job->fresh()->invoice;
    $this->receipt = $this->invoice->payments()->firstOrFail();
    $this->correction = ['reason' => 'Customer paid a different amount', 'method' => 'Cash', 'amount' => 500, 'account_id' => Account::systemAccount('main')->id, 'paid_at' => today()->toDateString()];
});

test('payment correction reverses and replaces the receipt with mandatory history', function () {
    $this->correction['method'] = 'Bank Transfer';
    $this->correction['account_id'] = Account::systemAccount('bank')->id;
    $this->correction['paid_at'] = today()->subDay()->toDateString();
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertRedirect(route('jobs.show', $this->job))->assertSessionHasNoErrors();
    $invoice = $this->invoice->fresh();
    $log = PaymentCorrection::firstOrFail();
    $replacement = Payment::findOrFail($log->replacement_payment_id);
    expect($this->receipt->fresh()->status)->toBe('Cancelled')->and((float) $this->receipt->fresh()->amount)->toBe(1000.0)
        ->and($this->receipt->fresh()->cancel_reason)->toBe($this->correction['reason'])
        ->and((float) $invoice->paid_amount)->toBe(500.0)->and((float) $invoice->due_amount)->toBe(500.0)
        ->and($invoice->status)->toBe('Partially Paid')->and($invoice->payment_method)->toBe('Bank Transfer')
        ->and($replacement->method)->toBe('Bank Transfer')->and($replacement->paid_at->toDateString())->toBe($this->correction['paid_at'])
        ->and($replacement->note)->toBe('Original note')->and($log->before['method'])->toBe('Cash')
        ->and((float) $log->before['received_amount'])->toBe(1000.0)->and((float) $log->after['received_amount'])->toBe(500.0)
        ->and($log->changed_by)->toBe($this->user->id)->and($this->job->fresh()->status)->toBe('Billed')
        ->and(Account::systemAccount('main')->balance)->toBe(500.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(-500.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(500.0);
    expect(AccountTransaction::where('reference_id', $this->receipt->id)->where('status', 'reversed')->count())->toBe(2);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Status History & Change Logs')
        ->assertSeeText('Payment corrected by '.$this->user->name)->assertSeeText('Before:')->assertSeeText('After:')->assertSeeText($this->correction['reason'])->assertSeeText('Edit payment');
});

test('blank and short reasons cannot change financial records', function ($reason) {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, ['reason' => $reason]))->assertSessionHasErrors('reason');
    expect($this->receipt->fresh()->status)->toBe('Completed')->and(Payment::count())->toBe(1)->and(PaymentCorrection::count())->toBe(0)
        ->and(Account::systemAccount('main')->balance)->toBe(600.0);
})->with(['', 'oops', '     ']);

test('a stale second correction cannot replace the original receipt twice', function () {
    $url = route('jobs.payments.update', [$this->job, $this->receipt]);
    $this->put($url, $this->correction)->assertSessionHasNoErrors();
    $this->put($url, $this->correction)->assertSessionHasErrors('payment');
    expect(PaymentCorrection::count())->toBe(1)->and(Payment::count())->toBe(2)->and((float) $this->invoice->fresh()->due_amount)->toBe(500.0);
});

test('invalid replacement payment rolls back reversal and history', function ($overrides, $error) {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, $overrides))->assertSessionHasErrors($error);
    expect($this->receipt->fresh()->status)->toBe('Completed')->and(Payment::count())->toBe(1)->and(PaymentCorrection::count())->toBe(0)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(0.0)->and(AccountTransaction::where('status', 'reversed')->count())->toBe(0);
})->with([
    [['method' => 'Card', 'amount' => 1001], 'payments'],
    [['method' => 'Cheque'], 'cheque_date'],
    [['paid_at' => '2099-12-31'], 'paid_at'],
    [['amount' => 0], 'amount'],
    [['account_id' => 999999], 'payments.0.account_id'],
]);

test('correction preserves cash received and change without increasing applied payments', function () {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, ['amount' => 1500]))->assertSessionHasNoErrors();
    $replacement = $this->invoice->payments()->where('status', 'Completed')->firstOrFail();
    expect((float) $replacement->amount)->toBe(1000.0)->and((float) $replacement->received_amount)->toBe(1500.0)
        ->and((float) $replacement->change_amount)->toBe(500.0)->and($this->invoice->fresh()->received_amount)->toBe(1500.0)
        ->and(Account::systemAccount('main')->balance)->toBe(600.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(400.0);
});

test('replacing cash with a future dated cheque keeps money on hold until the cheque passes', function () {
    $date = today()->addDays(5)->toDateString();
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, ['method' => 'Cheque', 'amount' => 1000, 'cheque_date' => $date, 'cheque_number' => 'CORRECT-CHEQUE', 'bank_name' => 'Test Bank', 'account_id' => Account::systemAccount('bank')->id]))->assertSessionHasNoErrors();
    $cheque = Cheque::firstOrFail();
    expect($cheque->status)->toBe('Pending')->and($cheque->cheque_date->toDateString())->toBe($date)
        ->and($this->invoice->fresh()->status)->toBe('Hold')->and((float) $this->invoice->fresh()->hold_amount)->toBe(1000.0)
        ->and((float) $this->invoice->fresh()->paid_amount)->toBe(0.0)->and(Account::systemAccount('bank')->balance)->toBe(0.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0);
    expect(fn () => app(SettlementService::class)->resolve($cheque, 'Cleared', today()->toDateString()))->toThrow(ValidationException::class);
});

test('a pending single cheque is cancelled before its replacement is received', function () {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, ['method' => 'Cheque', 'amount' => 1000, 'cheque_date' => today()->toDateString(), 'cheque_number' => 'ORIGINAL-CHEQUE', 'bank_name' => 'Test Bank']))->assertSessionHasNoErrors();
    $held = $this->invoice->payments()->where('status', 'Hold')->firstOrFail();
    $this->put(route('jobs.payments.update', [$this->job, $held]), $this->correction)->assertSessionHasNoErrors();
    expect($held->cheque->fresh()->status)->toBe('Cancelled')->and($held->fresh()->status)->toBe('Cancelled')
        ->and((float) $this->invoice->fresh()->hold_amount)->toBe(0.0)->and((float) $this->invoice->fresh()->paid_amount)->toBe(500.0)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(500.0)->and(PaymentCorrection::count())->toBe(2);
});

test('cleared and forwarded cheques cannot be changed in the payment editor', function ($state) {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, ['method' => 'Cheque', 'amount' => 1000, 'cheque_date' => today()->toDateString(), 'cheque_number' => 'LOCKED-CHEQUE', 'bank_name' => 'Test Bank']))->assertSessionHasNoErrors();
    $held = $this->invoice->payments()->where('status', 'Hold')->firstOrFail();
    if ($state === 'Cleared') {
        app(SettlementService::class)->resolve($held->cheque, 'Cleared', today()->toDateString());
    } else {
        $supplier = Supplier::create(['name' => 'Forwarded Supplier']);
        $held->cheque->update(['forwarded_supplier_id' => $supplier->id]);
    }
    $this->put(route('jobs.payments.update', [$this->job, $held]), $this->correction)->assertSessionHasErrors('payment');
    expect(PaymentCorrection::count())->toBe(1)->and($held->cheque->fresh()->status)->toBe($state === 'Cleared' ? 'Cleared' : 'Pending');
    $this->get(route('jobs.show', $this->job))->assertOk()->assertDontSee('data-edit-payment="'.$held->id.'"', false)->assertSeeText('cheque register');
})->with(['Cleared', 'Forwarded']);

test('paid current job still shows and collects the dated previous due from its list row', function () {
    $old = Invoice::create(['number' => 'OP-VO-1783', 'invoice_type' => 'Opening Due', 'customer_id' => $this->invoice->customer_id, 'bike_id' => $this->invoice->bike_id, 'total' => 10000, 'subtotal' => 10000, 'due_amount' => 10000, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_at' => today()->subDays(5), 'created_by' => $this->user->id]);
    $response = $this->get(route('jobs.index', ['bike_id' => $this->job->intake->bike_id]))->assertOk()->assertSeeText('Current bill: Completed')->assertSeeText('Rs. 10,000.00')
        ->assertDontSeeText('OP-VO-1783')->assertSee('data-job-id="'.$this->job->id.'"', false);
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $button = (new DOMXPath($document))->query('//button[@data-job-id="'.$this->job->id.'"]')->item(0);
    $bills = json_decode($button->getAttribute('data-bills'), true);
    expect(array_column($bills, 'invoice_id'))->toBe([$old->id])
        ->and($bills[0]['date'])->toBe(today()->subDays(5)->format('d M Y'));
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => [$old->id], 'payments' => [['method' => 'Cash', 'amount' => 10000, 'account_id' => Account::systemAccount('main')->id]]])->assertSessionHasNoErrors();
    $oldReceipt = $old->payments()->firstOrFail();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSee('data-edit-payment="'.$oldReceipt->id.'"', false);
    $this->put(route('jobs.payments.update', [$this->job, $oldReceipt]), $this->correction)->assertSessionHasNoErrors();
    expect((float) $old->fresh()->due_amount)->toBe(9500.0)->and((float) $this->invoice->fresh()->due_amount)->toBe(0.0);
});

test('payment corrections do not change the recorded job completion date', function () {
    DB::table('job_card_status_logs')->insert(['job_card_id' => $this->job->id, 'new_status' => 'Completed', 'changed_by' => $this->user->id, 'created_at' => today()->subDays(4), 'updated_at' => today()->subDays(4)]);
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertSessionHasNoErrors();
    $intake = $this->job->intake->bike->intakes()->create(['intake_number' => 'IN-LATER', 'complaint' => 'Later repair', 'created_by' => $this->user->id]);
    $later = JobCard::create(['number' => 'JOB-LATER', 'bike_intake_id' => $intake->id, 'status' => 'Received', 'priority' => 'Normal', 'created_by' => $this->user->id]);
    expect(PreviousCustomerBalance::forJob($later)->first()['date'])->toBe(today()->subDays(4)->format('d M Y'));
});

test('view-only users and unrelated or other-shop receipts cannot be corrected', function () {
    $role = Role::create(['name' => 'Correction Viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]))->withOpenCashier();
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertForbidden();
    $this->actingAs($this->user)->withOpenCashier();
    $other = Customer::create(['name' => 'Unrelated', 'phone' => '0771212121']);
    $this->invoice->update(['job_card_id' => null, 'customer_id' => $other->id]);
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertNotFound();
    $this->invoice->update(['job_card_id' => $this->job->id, 'shop_type' => 'helmet']);
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertNotFound();
    expect(PaymentCorrection::count())->toBe(0)->and($this->receipt->fresh()->status)->toBe('Completed');
});

test('mixed receipts keep all other payments and allocation totals when one amount changes', function () {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertSessionHasNoErrors();
    $cash = $this->invoice->payments()->where('status', 'Completed')->firstOrFail();
    $this->post(route('customers.dues.payment', $this->invoice), ['payments' => [['method' => 'Card', 'amount' => 300, 'account_id' => Account::systemAccount('bank')->id]]])->assertSessionHasNoErrors();
    $card = $this->invoice->payments()->where('method', 'Card')->firstOrFail();
    $this->put(route('jobs.payments.update', [$this->job, $cash]), array_replace($this->correction, ['amount' => 200]))->assertSessionHasNoErrors();
    expect((float) $this->invoice->fresh()->paid_amount)->toBe(500.0)->and((float) $this->invoice->fresh()->due_amount)->toBe(500.0)
        ->and($this->invoice->fresh()->payment_method)->toBe('Mixed')->and($card->fresh()->status)->toBe('Completed')->and((float) $card->fresh()->amount)->toBe(300.0)
        ->and(Account::systemAccount('main')->balance)->toBe(500.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(-300.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(300.0);
});

test('shared cheques are protected even when still pending', function () {
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), array_replace($this->correction, ['method' => 'Cheque', 'amount' => 500, 'cheque_date' => today()->toDateString(), 'cheque_number' => 'SHARED-CHEQUE', 'bank_name' => 'Test Bank']))->assertSessionHasNoErrors();
    $held = $this->invoice->payments()->where('status', 'Hold')->firstOrFail();
    $held->cheque->update(['amount' => 1000]);
    $otherInvoice = Invoice::create(['number' => 'INV-SHARED', 'invoice_type' => 'Parts Sale', 'customer_id' => $this->invoice->customer_id, 'subtotal' => 500, 'total' => 500, 'due_amount' => 500, 'status' => 'Unpaid', 'created_by' => $this->user->id, 'public_token' => Str::random(48)]);
    app(SettlementService::class)->receive($otherInvoice, ['method' => 'Cheque', 'amount' => 500, 'account_id' => Account::systemAccount('bank')->id], cheque: $held->cheque);
    $this->put(route('jobs.payments.update', [$this->job, $held]), $this->correction)->assertSessionHasErrors('payment');
    expect($held->fresh()->status)->toBe('Hold')->and($held->cheque->fresh()->status)->toBe('Pending')
        ->and((float) $otherInvoice->fresh()->hold_amount)->toBe(500.0)->and(PaymentCorrection::count())->toBe(1);
});

test('refunded and salary recovery receipts retain their protected workflows', function ($values) {
    $this->receipt->update($values);
    $this->put(route('jobs.payments.update', [$this->job, $this->receipt]), $this->correction)->assertSessionHasErrors('payment');
    expect(PaymentCorrection::count())->toBe(0)->and($this->receipt->fresh()->status)->toBe('Completed')->and((float) $this->invoice->fresh()->paid_amount)->toBe(1000.0);
})->with([[['refunded_amount' => 50]], [['method' => 'Salary Deduction']], [['method' => 'Manual Clearance']]]);
