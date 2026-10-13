<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Store;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Str;

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

test('intakes save chassis numbers and carry them through bike lookup and job cards', function () {
    $data = [
        'bike_number' => 'WP CH 1000', 'bike_model' => 'Honda Dio', 'phone' => '0771234569',
        'customer_name' => 'Chassis Customer', 'complaint' => 'Engine noise', 'chassis_number' => 'FRAME-123456789',
    ];
    $this->get(route('intakes.create'))->assertOk()->assertSee('name="chassis_number"', false);
    $this->post(route('intakes.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
    $intake = BikeIntake::with('bike')->firstOrFail();
    expect($intake->bike->chassis_number)->toBe('FRAME-123456789');
    $this->getJson(route('intakes.lookup', ['query' => 'WP CH 1000']))
        ->assertOk()->assertJsonPath('bikes.0.chassis_number', 'FRAME-123456789');
    $this->get(route('intakes.show', $intake))->assertOk()->assertSeeText('FRAME-123456789');
    $this->get(route('intakes.edit', $intake))->assertOk()->assertSee('value="FRAME-123456789"', false);

    $technician = Technician::create(['name' => 'Chamara', 'phone' => '0700000001', 'active' => true]);
    $this->post(route('jobs.store'), [
        'bike_intake_id' => $intake->id, 'main_technician_id' => $technician->id, 'priority' => 'Normal',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $job = JobCard::firstOrFail();
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('FRAME-123456789')
        ->assertSeeInOrder(['Job card overview', 'WP CH 1000', 'Main Technician', 'Chamara', 'Work details']);

    $this->put(route('intakes.update', $intake), array_merge($data, ['chassis_number' => 'FRAME-CORRECTED']))
        ->assertSessionHasNoErrors();
    expect($intake->bike->fresh()->chassis_number)->toBe('FRAME-CORRECTED');
    $this->put(route('intakes.update', $intake), array_merge($data, ['chassis_number' => '']))
        ->assertSessionHasNoErrors();
    expect($intake->bike->fresh()->chassis_number)->toBeNull();
});

test('a repeat intake without a chassis number preserves the recorded number', function () {
    $data = ['bike_number' => 'WP CH 2000', 'bike_model' => 'Yamaha FZ', 'phone' => '0770000002', 'complaint' => 'Full service'];
    $this->post(route('intakes.store'), $data + ['chassis_number' => 'FRAME-EXISTING'])->assertSessionHasNoErrors();
    $intake = BikeIntake::firstOrFail();
    $this->post(route('intakes.store'), $data + ['chassis_number' => ''])->assertSessionHasNoErrors();
    expect($intake->bike->fresh()->chassis_number)->toBe('FRAME-EXISTING');
    $this->put(route('intakes.update', $intake), $data)->assertSessionHasNoErrors();
    expect($intake->bike->fresh()->chassis_number)->toBe('FRAME-EXISTING');
});

test('intakes reject invalid chassis numbers', function ($chassis) {
    $this->post(route('intakes.store'), [
        'bike_number' => 'WP CH 3000', 'bike_model' => 'Honda Dio', 'phone' => '0770000003',
        'complaint' => 'Engine noise', 'chassis_number' => $chassis,
    ])->assertSessionHasErrors('chassis_number');
    expect(BikeIntake::count())->toBe(0);
})->with([str_repeat('X', 101), [['invalid']]]);

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

test('job bill summary shows previous customer due after total', function () {
    $this->post(route('intakes.store'), [
        'bike_number' => 'WP DUE 1000', 'bike_model' => 'Yamaha FZ', 'phone' => '0771234568',
        'customer_name' => 'Due Customer', 'complaint' => 'Due display check',
    ]);
    $intake = BikeIntake::with('bike.customer')->firstOrFail();
    $this->post(route('jobs.store'), ['bike_intake_id' => $intake->id, 'priority' => 'Normal']);
    $job = JobCard::firstOrFail();

    Invoice::create([
        'number' => 'INV-OLD-DUE',
        'invoice_type' => 'Opening Due',
        'customer_id' => $intake->bike->customer_id,
        'subtotal' => 1250,
        'total' => 1250,
        'paid_amount' => 0,
        'due_amount' => 1250,
        'status' => 'Unpaid',
        'public_token' => Str::random(48),
        'created_by' => $this->user->id,
    ]);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSeeText('Earlier unpaid balances')
        ->assertSeeText('Rs. 1,250.00');
});

test('same account transfers are rejected', function () {
    $account = Account::where('code', 'MAIN')->first();

    $this->from(route('finance.index'))->post(route('transfers.store'), [
        'from_account_id' => $account->id, 'to_account_id' => $account->id,
        'amount' => 100, 'transfer_date' => today()->toDateString(),
    ])->assertRedirect(route('finance.index'))->assertSessionHasErrors('from_account_id');
});
