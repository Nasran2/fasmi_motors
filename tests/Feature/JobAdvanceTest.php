<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\User;
use App\Services\OpeningBalanceService;
use App\Services\SettlementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('OK:ADVANCE', 200)]);
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    SmsSetting::instance()->update(['enabled' => true, 'auto_sms_enabled' => false, 'textit_id' => 'test', 'textit_password' => 'test']);
    $customer = Customer::create(['name' => 'Advance Customer', 'phone' => '0771234567']);
    $bike = $customer->bikes()->create(['number' => 'ADV-100', 'model' => 'Yamaha']);
    $intake = BikeIntake::withoutEvents(fn () => BikeIntake::create(['intake_number' => 'IN-ADV', 'bike_id' => $bike->id, 'complaint' => 'Repair', 'created_by' => auth()->id()]));
    $this->job = JobCard::withoutEvents(fn () => JobCard::create(['number' => 'JOB-ADVANCE', 'bike_intake_id' => $intake->id, 'status' => 'Assigned', 'priority' => 'Normal', 'parts_total' => 3000, 'labor_total' => 2000, 'created_by' => auth()->id()]));
    $this->data = ['advance_amount' => 1000, 'advance_method' => 'Cash', 'advance_account_id' => Account::systemAccount('main')->id, 'advance_paid_at' => today()->toDateString(), 'send_advance_sms' => 0];
});

test('advance popup defaults SMS on and saving without SMS posts a receipt without billing', function () {
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('+ Add advance')
        ->assertSee('name="send_advance_sms" value="1" checked', false);
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $advance = JobAdvance::sole();
    expect((float) $advance->amount)->toBe(1000.0)->and($advance->status)->toBe('Completed')
        ->and($advance->send_sms)->toBeFalse()->and($advance->payment_id)->toBeNull()
        ->and(Invoice::count())->toBe(0)->and(Payment::count())->toBe(0)
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0);
    Http::assertNothingSent();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Advance paid')->assertSeeText('Rs. 1,000.00')->assertSeeText('Rs. 4,000.00')->assertSeeText($advance->number);
    expect(DB::table('job_card_status_logs')->where('job_card_id', $this->job->id)->value('note'))->toContain($advance->number, '1,000.00', 'not requested');
});

test('checked SMS sends exactly one message with customer job and advance details', function () {
    $token = (string) Str::uuid();
    $data = array_replace($this->data, ['send_advance_sms' => 1, '_submission_token' => $token]);
    $this->post(route('jobs.advances.store', $this->job), $data)->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'Customer SMS sent.'));
    $this->post(route('jobs.advances.store', $this->job), $data)->assertRedirect();
    Http::assertSentCount(1);
    expect(JobAdvance::count())->toBe(1)->and(SmsLog::sole()->message)->toContain('Advance Customer', 'JOB-ADVANCE', 'ADV-100', '1,000.00');
});

test('invoice deducts advances and posts only the new cash with no duplicate advance SMS', function () {
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => [['amount' => 4500, 'method' => 'Cash', 'account_id' => Account::systemAccount('main')->id]]])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    expect((float) $invoice->paid_amount)->toBe(5000.0)->and((float) $invoice->due_amount)->toBe(0.0)
        ->and($invoice->received_amount)->toBe(5500.0)->and($invoice->change_amount)->toBe(500.0)
        ->and($invoice->payments)->toHaveCount(2)->and(JobAdvance::sole()->payment_id)->not->toBeNull()
        ->and(Account::systemAccount('main')->balance)->toBe(2000.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3000.0);
    Http::assertNothingSent();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertDontSeeText('+ Add advance')->assertSeeText(JobAdvance::sole()->number);
});

test('bank advances are deducted once and bank ledger is not posted twice', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_method' => 'Bank Transfer', 'advance_account_id' => Account::systemAccount('bank')->id]))->assertSessionHasNoErrors();
    expect(Account::systemAccount('bank')->balance)->toBe(1000.0);
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect(Account::systemAccount('bank')->balance)->toBe(1000.0)
        ->and((float) $this->job->fresh()->invoice->due_amount)->toBe(4000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(400.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(600.0);
});

test('cheque advance remains held on invoice and clears or returns using normal cheque rules', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, [
        'advance_method' => 'Cheque', 'advance_cheque_date' => today()->toDateString(), 'advance_cheque_number' => 'ADV-CHQ', 'advance_bank_name' => 'Test Bank',
    ]))->assertSessionHasNoErrors();
    expect(JobAdvance::sole()->status)->toBe('Hold')->and(AccountTransaction::count())->toBe(0);
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    expect((float) $invoice->hold_amount)->toBe(1000.0)->and((float) $invoice->paid_amount)->toBe(0.0)->and((float) $invoice->due_amount)->toBe(4000.0);
    app(SettlementService::class)->resolve(Cheque::sole(), 'Cleared', today()->toDateString());
    expect((float) $invoice->fresh()->hold_amount)->toBe(0.0)->and((float) $invoice->fresh()->paid_amount)->toBe(1000.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(1000.0);
    app(SettlementService::class)->resolve(Cheque::sole(), 'Returned', today()->toDateString(), 'Returned by bank');
    expect((float) $invoice->fresh()->paid_amount)->toBe(0.0)->and((float) $invoice->fresh()->due_amount)->toBe(5000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0)->and(Account::systemAccount('bank')->balance)->toBe(0.0);
});

test('cheque cleared before billing transfers its existing receipt without double income', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, [
        'advance_method' => 'Cheque', 'advance_cheque_date' => today()->toDateString(), 'advance_cheque_number' => 'EARLY', 'advance_bank_name' => 'Test Bank',
    ]))->assertSessionHasNoErrors();
    app(SettlementService::class)->resolve(Cheque::sole(), 'Cleared', today()->toDateString());
    expect(JobAdvance::sole()->status)->toBe('Completed')->and(Account::systemAccount('main')->balance)->toBe(1000.0);
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->invoice->paid_amount)->toBe(1000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(400.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(600.0)->and(Account::systemAccount('bank')->balance)->toBe(1000.0);
});

test('future cheques cannot clear early and returned unbilled advances are not deducted', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, [
        'advance_method' => 'Cheque', 'advance_cheque_date' => today()->addDays(2)->toDateString(), 'advance_cheque_number' => 'FUTURE', 'advance_bank_name' => 'Test Bank',
    ]))->assertSessionHasNoErrors();
    expect(fn () => app(SettlementService::class)->resolve(Cheque::sole(), 'Cleared', today()->toDateString()))->toThrow(ValidationException::class);
    app(SettlementService::class)->resolve(Cheque::sole(), 'Returned', today()->toDateString());
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->invoice->due_amount)->toBe(5000.0)->and(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});

test('invalid advances and bills below advances do not write partial financial data', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_amount' => 0]))->assertSessionHasErrors('advance_amount');
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_method' => 'Cheque']))->assertSessionHasErrors('advance_cheque_number');
    expect(JobAdvance::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_amount' => 6000, 'advance_method' => 'Bank Transfer']))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasErrors('advance_amount');
    expect(Invoice::count())->toBe(0)->and(Payment::count())->toBe(0)->and(JobAdvance::sole()->payment_id)->toBeNull()->and(Account::systemAccount('main')->balance)->toBe(6000.0);
});

test('billed and cancelled jobs reject new advances', function () {
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasErrors('advance_amount');
    $this->job->updateQuietly(['status' => 'Cancelled']);
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertSessionHasErrors('advance_amount');
    expect(JobAdvance::count())->toBe(0);
});

test('SMS failure leaves the advance saved and reports the delivery problem', function () {
    SmsSetting::instance()->update(['enabled' => false]);
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['send_advance_sms' => 1]))
        ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'SMS was not sent'));
    expect(JobAdvance::count())->toBe(1)->and(Account::systemAccount('main')->balance)->toBe(1000.0)->and(SmsLog::sole()->status)->toBe('failed');
});

test('advance receipt collection requires payment permission', function () {
    $role = Role::create(['name' => 'Advance Viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('jobs.show', $this->job))->assertOk()->assertDontSeeText('+ Add advance');
    $this->post(route('jobs.advances.store', $this->job), $this->data)->assertForbidden();
    expect(JobAdvance::count())->toBe(0);
    Http::assertNothingSent();
});

test('unbilled advance cheques lock their amount and remain visible in the cheque register', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, [
        'advance_method' => 'Cheque', 'advance_cheque_date' => today()->toDateString(), 'advance_cheque_number' => 'LOCKED', 'advance_bank_name' => 'Test Bank',
    ]))->assertSessionHasNoErrors();
    $cheque = Cheque::sole();
    $details = ['cheque_type' => 'Received', 'party_name' => $this->job->customer->name, 'related_reference' => $this->job->number,
        'cheque_number' => 'LOCKED', 'bank_name' => 'Test Bank', 'cheque_date' => today()->toDateString(), 'amount' => 1500];
    $this->put(route('accounts.cheques.update', $cheque), $details)->assertSessionHasErrors('amount');
    expect((float) $cheque->fresh()->amount)->toBe(1000.0);
    $this->get(route('accounts.cheques.show', $cheque))->assertOk()->assertSeeText('JOB-ADVANCE · Job advance');
    $this->put(route('accounts.cheques.update', $cheque), array_replace($details, ['amount' => 1000, 'bank_account_id' => Account::systemAccount('bank')->id]))->assertSessionHasNoErrors();
    expect(JobAdvance::sole()->account_id)->toBe(Account::systemAccount('bank')->id);
});

test('cleared unbilled cheque returning reverses the advance ledgers', function () {
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, [
        'advance_method' => 'Cheque', 'advance_cheque_date' => today()->toDateString(), 'advance_cheque_number' => 'RETURN', 'advance_bank_name' => 'Test Bank',
    ]))->assertSessionHasNoErrors();
    app(SettlementService::class)->resolve(Cheque::sole(), 'Cleared', today()->toDateString());
    app(SettlementService::class)->resolve(Cheque::sole(), 'Returned', today()->toDateString(), 'Cheque returned');
    expect(JobAdvance::sole()->status)->toBe('Returned')->and(Account::systemAccount('main')->balance)->toBe(0.0)->and(Account::systemAccount('bank')->balance)->toBe(0.0);
});

test('multiple advances reduce the final bill before new receipts and earlier dues', function () {
    $customer = $this->job->customer;
    $opening = app(OpeningBalanceService::class)->record($customer, ['opening_balance' => 500, 'opening_balance_date' => today()->subDay()->toDateString()], $this->job->bike);
    foreach ([1000, 500] as $amount) {
        $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['advance_amount' => $amount]))->assertSessionHasNoErrors();
    }
    $this->post(route('jobs.invoice', $this->job), ['payments' => [['method' => 'Cash', 'amount' => 3700, 'account_id' => Account::systemAccount('main')->id]]])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->invoice->paid_amount)->toBe(5000.0)->and((float) $opening->fresh()->due_amount)->toBe(300.0)
        ->and(Account::systemAccount('main')->balance)->toBe(2200.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(3000.0);
});

test('SMS settings can stop delivery while retaining the requested advance', function () {
    SmsSetting::instance()->update(['manual_sms_enabled' => false]);
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['send_advance_sms' => 1]))
        ->assertSessionHasNoErrors()->assertSessionHas('success', fn ($message) => str_contains($message, 'SMS was not sent'));
    expect(JobAdvance::count())->toBe(1);
    Http::assertNothingSent();
});

test('billing an advance does not trigger a second payment SMS even when automation is enabled', function () {
    SmsSetting::instance()->update(['auto_sms_enabled' => true]);
    Setting::updateOrCreate(['key' => 'sms_event_payment'], ['group' => 'sms', 'value' => '1']);
    Setting::updateOrCreate(['key' => 'sms_event_billed'], ['group' => 'sms', 'value' => '0']);
    $this->post(route('jobs.advances.store', $this->job), array_replace($this->data, ['send_advance_sms' => 1]))->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['payments' => []])->assertSessionHasNoErrors();
    Http::assertSentCount(1);
    expect(SmsLog::count())->toBe(1)->and(SmsLog::sole()->message_type)->toBe('Job advance');
});
