<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Services\SettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Advance Balance Customer', 'phone' => '0771234567']);
    $bike = $customer->bikes()->create(['number' => 'ADV-BALANCE', 'model' => 'Yamaha']);
    $intake = BikeIntake::withoutEvents(fn () => BikeIntake::create(['intake_number' => 'IN-BALANCE', 'bike_id' => $bike->id, 'complaint' => 'Repair', 'created_by' => auth()->id()]));
    $this->job = JobCard::withoutEvents(fn () => JobCard::create(['number' => 'JOB-ADV-BALANCE', 'bike_intake_id' => $intake->id, 'status' => 'Received', 'priority' => 'Normal', 'parts_total' => 3400, 'created_by' => auth()->id()]));
    $this->opening = app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 15750, 'opening_balance_date' => today()->subDay()->toDateString()], $bike);
    $this->data = ['advance_amount' => 9000, 'advance_method' => 'Cash', 'advance_account_id' => Account::systemAccount('main')->id, 'advance_paid_at' => today()->toDateString(), 'send_advance_sms' => 0];
});

test('advance surplus reduces combined balance and new charges get first claim before old dues', function () {
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Advance towards old dues')->assertSeeText('Rs. 5,600.00')->assertSeeText('Rs. 10,150.00')->assertSeeText('Edit advance')->assertSeeText('Delete advance');
    // Old bills stay unchanged until billing so added work can take priority.
    expect((float) $this->opening->fresh()->due_amount)->toBe(15750.0);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'service_total' => 2000])->assertSessionHasNoErrors();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Rs. 3,600.00')->assertSeeText('Rs. 12,150.00');
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    expect((float) $invoice->paid_amount)->toBe(5400.0)->and((float) $invoice->due_amount)->toBe(0.0)
        ->and((float) $this->opening->fresh()->paid_amount)->toBe(3600.0)->and((float) $this->opening->fresh()->due_amount)->toBe(12150.0)
        ->and(Payment::pluck('receipt_group_id')->unique())->toHaveCount(1)
        ->and(Account::systemAccount('main')->balance)->toBe(5600.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3400.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Rs. 12,150.00')->assertSeeText('Rs. 9,000.00');
});

test('cash advance above combined dues is returned as change at billing', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_amount' => 20000]))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->due_amount)->toBe(0.0)
        ->and((float) Payment::sum('received_amount'))->toBe(20000.0)->and((float) Payment::sum('change_amount'))->toBe(850.0)
        ->and(Account::systemAccount('main')->balance)->toBe(15750.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3400.0);
});

test('noncash advance allocation preserves the original bank receipt across both bills', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_method' => 'Bank Transfer', 'advance_account_id' => Account::systemAccount('bank')->id]))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->paid_amount)->toBe(5600.0)->and(Account::systemAccount('bank')->balance)->toBe(9000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(5600.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3400.0);
});

test('surplus advance cheque holds both bills and clearing or returning changes both once', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_method' => 'Cheque', 'advance_cheque_date' => today()->toDateString(), 'advance_cheque_number' => 'SHARED-ADV', 'advance_bank_name' => 'Bank']))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    expect((float) $invoice->hold_amount)->toBe(3400.0)->and((float) $this->opening->fresh()->hold_amount)->toBe(5600.0)->and(Account::systemAccount('bank')->balance)->toBe(0.0);
    app(SettlementService::class)->resolve(Cheque::sole(), 'Cleared', today()->toDateString());
    expect((float) $this->opening->fresh()->paid_amount)->toBe(5600.0)->and(Account::systemAccount('bank')->balance)->toBe(9000.0);
    app(SettlementService::class)->resolve(Cheque::sole(), 'Returned', today()->toDateString(), 'Returned advance cheque');
    expect((float) $invoice->fresh()->due_amount)->toBe(3400.0)->and((float) $this->opening->fresh()->due_amount)->toBe(15750.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0)->and(Account::systemAccount('bank')->balance)->toBe(0.0);
});

test('correcting cash advance replaces it reverses old money and preserves reason and values', function () {
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $original = JobAdvance::sole();
    $replacement = array_replace($this->data, ['advance_amount' => 8000, 'advance_method' => 'Bank Transfer', 'advance_account_id' => Account::systemAccount('bank')->id]);
    $this->put(route('jobs.advances.update', [$this->job, $original]), $replacement)->assertSessionHasErrors('advance_reason');
    expect(Account::systemAccount('main')->balance)->toBe(9000.0);
    $this->put(route('jobs.advances.update', [$this->job, $original]), $replacement + ['advance_reason' => 'Correct the amount and method'])->assertSessionHasNoErrors();
    expect($original->fresh()->status)->toBe('Cancelled')->and(JobAdvance::count())->toBe(2)
        ->and(Account::systemAccount('main')->balance)->toBe(8000.0)->and(Account::systemAccount('bank')->balance)->toBe(8000.0);
    $audit = AuditLog::where('event', 'Advance corrected')->sole();
    expect((float) $audit->old_values['amount'])->toBe(9000.0)->and((float) $audit->new_values['amount'])->toBe(8000.0)->and($audit->new_values['reason'])->toBe('Correct the amount and method');
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Correct the amount and method')->assertSeeText('Rs. 11,150.00');
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) Payment::sum('received_amount'))->toBe(8000.0)->and((float) $this->opening->fresh()->due_amount)->toBe(11150.0);
    Http::assertNothingSent();
});

test('deleting advance requires reason reverses funds and keeps cancelled receipt in history', function () {
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $advance = JobAdvance::sole();
    $this->delete(route('jobs.advances.destroy', [$this->job, $advance]))->assertSessionHasErrors('advance_reason');
    $this->delete(route('jobs.advances.destroy', [$this->job, $advance]), ['advance_reason' => 'Advance entered twice'])->assertSessionHasNoErrors();
    expect($advance->fresh()->status)->toBe('Cancelled')->and(Account::systemAccount('main')->balance)->toBe(0.0)
        ->and(DB::table('job_card_status_logs')->where('note', 'like', '%Advance entered twice%')->exists())->toBeTrue();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->invoice->paid_amount)->toBe(0.0)->and((float) $this->opening->fresh()->due_amount)->toBe(15750.0)->and(Payment::count())->toBe(0);
});

test('pending advance cheque can be corrected but a cleared one cannot be deleted here', function () {
    $data = array_replace($this->data, ['advance_method' => 'Cheque', 'advance_cheque_date' => today()->toDateString(), 'advance_cheque_number' => 'ORIGINAL', 'advance_bank_name' => 'Bank']);
    $this->post(route('jobs.advances.store', $this->job), $data)->assertSessionHasNoErrors();
    $old = JobAdvance::sole();
    $this->put(route('jobs.advances.update', [$this->job, $old]), array_replace($data, ['advance_amount' => 8000, 'advance_cheque_number' => 'REPLACEMENT', 'advance_reason' => 'Correct cheque and amount']))->assertSessionHasNoErrors();
    expect($old->fresh()->status)->toBe('Cancelled')->and($old->cheque->fresh()->status)->toBe('Cancelled');
    $new = JobAdvance::where('status', 'Hold')->sole();
    app(SettlementService::class)->resolve($new->cheque, 'Cleared', today()->toDateString());
    $this->delete(route('jobs.advances.destroy', [$this->job, $new]), ['advance_reason' => 'Cannot delete cleared cheque'])->assertSessionHasErrors('advance_reason');
    expect(Account::systemAccount('bank')->balance)->toBe(8000.0);
});

test('advance correction cannot cross jobs or alter an already billed receipt', function () {
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $advance = JobAdvance::sole();
    $another = JobCard::withoutEvents(fn () => JobCard::create(['number' => 'JOB-OTHER-ADVANCE', 'is_quick_job' => true, 'status' => 'Received', 'priority' => 'Normal', 'created_by' => auth()->id()]));
    $payload = $this->data + ['advance_reason' => 'Attempt another job change'];
    $this->put(route('jobs.advances.update', [$another, $advance]), $payload)->assertNotFound();
    $this->delete(route('jobs.advances.destroy', [$another, $advance]), $payload)->assertNotFound();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $this->put(route('jobs.advances.update', [$this->job, $advance]), $payload)->assertSessionHasErrors('advance_reason');
    $this->delete(route('jobs.advances.destroy', [$this->job, $advance]), $payload)->assertSessionHasErrors('advance_reason');
    expect((float) Payment::sum('amount'))->toBe(9000.0);
});

test('disabling old balances at billing leaves them untouched and returns excess cash', function () {
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['include_previous_balance' => 0, 'payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->due_amount)->toBe(15750.0)->and($this->job->fresh()->invoice->change_amount)->toBe(5600.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3400.0);
});

test('billed split advance is corrected as one receipt without double bank posting', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_method' => 'Bank Transfer']))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $payment = Payment::findOrFail(JobAdvance::sole()->payment_id);
    $this->put(route('jobs.payments.update', [$this->job, $payment]), ['reason' => 'Correct previously received advance', 'include_previous_balance' => 1, 'amount' => 8000, 'method' => 'Bank Transfer', 'account_id' => Account::systemAccount('bank')->id, 'paid_at' => today()->toDateString()])->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->paid_amount)->toBe(4600.0)->and(Account::systemAccount('bank')->balance)->toBe(8000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(4600.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3400.0);
});

test('old advance allocations cover oldest bills before newer ones', function () {
    $newer = app(OpeningBalanceService::class)->record($this->job->customer, ['opening_balance' => 3000, 'opening_balance_date' => today()->toDateString()], $this->job->bike);
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_amount' => 20000]))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->opening->fresh()->due_amount)->toBe(0.0)->and((float) $newer->fresh()->paid_amount)->toBe(850.0)->and((float) $newer->fresh()->due_amount)->toBe(2150.0);
});
