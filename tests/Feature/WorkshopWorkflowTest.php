<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Store;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($this->user);
    Store::create(['name' => 'Main Store', 'is_default' => true, 'active' => true]);
    foreach ([['MAIN', 'Main Account', 'main'], ['SPARE', 'Spare Parts Account', 'spare_parts'], ['BANK', 'Bank Account', 'bank']] as [$code, $name, $type]) {
        Account::create(compact('code', 'name', 'type'));
    }
});

test('a bike can be received and converted to a job card', function () {
    $this->post(route('intakes.store'), [
        'bike_number' => 'BAM 5428', 'bike_model' => 'Honda Dio', 'phone' => '0771234567',
        'customer_name' => 'Kamal', 'complaint' => 'Engine noise', 'estimated_amount' => 5000,
    ])->assertRedirect();

    $intake = BikeIntake::with('bike.customer')->firstOrFail();
    expect($intake->bike->number)->toBe('BAM 5428');

    $this->post(route('jobs.store'), [
        'bike_intake_id' => $intake->id, 'priority' => 'Normal', 'estimated_amount' => 5000,
    ])->assertRedirect();

    expect(JobCard::firstOrFail()->status)->toBe('Received');
});

test('billing splits paid income between workshop and spare parts ledgers', function () {
    $this->post(route('intakes.store'), [
        'bike_number' => 'WP ABC 1234', 'bike_model' => 'Yamaha FZ', 'phone' => '0710000000', 'complaint' => 'Full service',
    ]);
    $this->post(route('jobs.store'), ['bike_intake_id' => BikeIntake::first()->id, 'priority' => 'High']);
    $job = JobCard::first();
    $job->update(['parts_total' => 10000, 'service_total' => 2000, 'status' => 'Completed']);

    $this->post(route('jobs.invoice', $job), ['paid_amount' => 12000, 'payment_method' => 'Cash'])->assertRedirect();

    expect(Invoice::first()->due_amount)->toEqual(0)
        ->and(Account::where('code', 'SPARE')->first()->transactions()->sum('credit'))->toEqual(10000)
        ->and(Account::where('code', 'MAIN')->first()->transactions()->sum('credit'))->toEqual(2000);
});

test('same account transfers are rejected', function () {
    $account = Account::where('code', 'MAIN')->first();

    $this->from(route('finance.index'))->post(route('transfers.store'), [
        'from_account_id' => $account->id, 'to_account_id' => $account->id,
        'amount' => 100, 'transfer_date' => today()->toDateString(),
    ])->assertRedirect(route('finance.index'))->assertSessionHasErrors('from_account_id');
});
