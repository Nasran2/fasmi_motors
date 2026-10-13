<?php

use App\Models\Account;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'main', 'active' => true]);
});

test('quick jobs accept empty details and open the job directly without an intake or invented bike', function () {
    $this->get(route('jobs.quick.create'))->assertOk()->assertSeeText('All details are optional.')
        ->assertSeeText('NIC')->assertSeeText('Staff bike')->assertDontSeeText('Bike Condition & Intake Notes');
    $this->post(route('jobs.quick.store'), [])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    expect($job->is_quick_job)->toBeTrue()->and($job->bike_intake_id)->toBeNull()
        ->and($job->main_technician_id)->toBeNull()->and($job->status)->toBe('Received')
        ->and($job->customer)->toBeInstanceOf(Customer::class)->and($job->bike)->toBeNull()
        ->and(BikeIntake::count())->toBe(0)->and(Bike::count())->toBe(0);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Quick Job')->assertSeeText('Not recorded');
    $this->get(route('jobs.index'))->assertOk()->assertSeeText($job->number);
    $this->get(route('customers.index'))->assertOk();
    $this->get(route('customers.show', $job->customer))->assertOk();
    $this->get(route('customers.service-history'))->assertOk()->assertSeeText($job->number);
    $this->get(route('search', ['q' => $job->number]))->assertOk()->assertSeeText($job->number);
    $this->get(route('reports.job-cards'))->assertOk()->assertSeeText($job->number);
    $this->get(route('live-board.data'))->assertOk()->assertJsonFragment(['number' => $job->number, 'complaint' => 'Quick job']);
});

test('washing quick jobs can be billed and paid without a bike or technician', function () {
    $this->post(route('jobs.quick.store'), ['customer_name' => 'Quick Wash Customer', 'nic' => '123456789V', 'washing_total' => 500, 'complaint' => 'Washing only'])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    $this->post(route('jobs.invoice', $job), ['paid_amount' => 200, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $invoice = $job->fresh()->invoice;
    expect((float) $invoice->total)->toBe(500.0)->and((float) $invoice->due_amount)->toBe(300.0)
        ->and($invoice->bike_id)->toBeNull()->and($invoice->customer_id)->toBe($job->customer->id)
        ->and($job->customer->nic)->toBe('123456789V');
    foreach (['a4', 'a5', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertSeeText('Quick Wash Customer')->assertSeeText('Wash')->assertSeeText('500.00');
    }
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Pay Bill');
    $this->post(route('jobs.dues.payment', $job), ['invoice_ids' => [$invoice->id], 'paid_at' => today()->toDateString(), 'paid_amount' => 100, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id])->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->due_amount)->toBe(200.0);
    $this->get(route('customers.show', $job->customer))->assertOk()->assertSeeText($invoice->number);
});

test('quick jobs reuse existing bikes without clearing optional information and assign a technician', function () {
    $customer = Customer::create(['name' => 'Existing Owner', 'phone' => '0771234567', 'nic' => '987654321V']);
    $bike = $customer->bikes()->create(['number' => 'QUICK-123', 'model' => 'Yamaha', 'brand' => 'Yamaha', 'last_mileage' => 600]);
    $technician = Technician::create(['name' => 'Quick Technician', 'phone' => '0776543210', 'active' => true]);
    $this->post(route('jobs.quick.store'), ['bike_number' => 'quick-123', 'bike_model' => '', 'customer_name' => '', 'phone' => '', 'nic' => '', 'main_technician_id' => $technician->id, 'labor_total' => 100])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    expect($job->quick_bike_id)->toBe($bike->id)->and($job->quick_customer_id)->toBe($customer->id)
        ->and($job->status)->toBe('Assigned')->and($job->main_technician_id)->toBe($technician->id)
        ->and($bike->fresh()->model)->toBe('Yamaha')->and($customer->fresh()->nic)->toBe('987654321V')
        ->and(Bike::count())->toBe(1)->and(Customer::count())->toBe(1)->and(BikeIntake::count())->toBe(0);
    $this->get(route('jobs.index', ['bike_id' => $bike->id]))->assertOk()->assertSeeText($job->number);
    $this->get(route('customers.service-history', ['q' => $bike->number]))->assertOk()->assertSeeText($job->number);
    $this->get(route('technicians.history', ['technician' => $technician->id]))->assertOk()->assertSeeText($job->number);
    $this->get(route('customers.bikes.show', $bike))->assertOk()->assertSeeText($job->number)->assertSeeText('Quick Job');
    $response = $this->get(route('customers.index'))->assertOk();
    expect($response->viewData('customers')->first()->jobs_count)->toBe(1);
});

test('staff bike owner is separate from the optional working technician', function () {
    $owner = Technician::create(['name' => 'Staff Owner', 'phone' => '0776543211', 'active' => true]);
    $worker = Technician::create(['name' => 'Staff Worker', 'phone' => '0776543212', 'active' => true]);
    $this->post(route('jobs.quick.store'), ['bike_number' => 'STAFF-QUICK', 'is_technician_bike' => 1, 'technician_id' => $owner->id, 'main_technician_id' => $worker->id])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    expect($job->is_staff_bike)->toBeTrue()->and($job->staff_bike_owner_id)->toBe($owner->id)
        ->and($job->main_technician_id)->toBe($worker->id)->and($job->bike->is_technician_bike)->toBeTrue()
        ->and($job->bike->technician_id)->toBe($owner->id);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Staff Owner')->assertSeeText('Staff Worker');
    $this->post(route('jobs.invoice', $job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertSessionHasNoErrors();
    $this->get(route('technicians.bike-deductions'))->assertOk()->assertSeeText($job->number);
});

test('bike details without registration and staff owner without a bike remain optional', function () {
    $owner = Technician::create(['name' => 'No Registration Owner', 'phone' => '0776543213', 'active' => true]);
    $this->post(route('jobs.quick.store'), ['bike_model' => 'Scooter', 'color' => 'Blue', 'is_technician_bike' => 1, 'technician_id' => $owner->id])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    expect($job->quick_bike_id)->toBeNull()->and($job->bike->model)->toBe('Scooter')
        ->and($job->staff_bike_owner_id)->toBe($owner->id)->and($job->main_technician_id)->toBeNull()
        ->and(Bike::count())->toBe(0);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Scooter')->assertSeeText('No Registration Owner');
});

test('quick job input validation and permissions prevent invalid records', function () {
    $this->post(route('jobs.quick.store'), ['washing_total' => -1, 'main_technician_id' => 99999, 'is_technician_bike' => 1])->assertSessionHasErrors(['washing_total', 'main_technician_id', 'technician_id']);
    expect(JobCard::count())->toBe(0)->and(Customer::count())->toBe(0);
    $role = Role::create(['name' => 'Quick Jobs Viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('jobs.quick.create'))->assertForbidden();
    $this->post(route('jobs.quick.store'), [])->assertForbidden();
});

test('quick job submissions are idempotent and opening balances remain separate', function () {
    $payload = ['_submission_token' => (string) Str::uuid(), 'customer_name' => 'Opening Quick Customer', 'opening_balance' => 1000, 'opening_balance_date' => today()->toDateString(), 'labor_total' => 100];
    $this->post(route('jobs.quick.store'), $payload)->assertSessionHasNoErrors();
    $this->post(route('jobs.quick.store'), $payload)->assertSessionHasNoErrors();
    $job = JobCard::sole();
    expect($job->total)->toBe(100.0)->and($job->customer->invoices()->sole()->invoice_type)->toBe('Opening Due');
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Rs. 1,000.00');
});
