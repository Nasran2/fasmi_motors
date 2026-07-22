<?php

use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('weekly dashboard series contains seven consecutive calendar days', function () {
    $user = User::factory()->create();
    $weekStart = now()->startOfWeek();

    $response = $this->actingAs($user)->get(route('dashboard'))->assertOk();

    foreach (range(0, 6) as $offset) {
        $response->assertSee('title="'.$weekStart->copy()->addDays($offset)->format('d M').'"', false);
    }
});

test('dashboard totals and recent intake use persisted workshop data', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Nimal Perera', 'phone' => '0771234567']);
    $bike = $customer->bikes()->create(['number' => 'WP TEST 1001', 'model' => 'Honda Dio']);
    $intake = BikeIntake::create([
        'intake_number' => 'IN-TEST-0001', 'bike_id' => $bike->id, 'complaint' => 'Engine service',
        'estimated_amount' => 5000, 'advance_payment' => 0, 'is_service' => true, 'created_by' => $user->id,
    ]);
    $job = JobCard::create([
        'number' => 'JOB-TEST-0001', 'bike_intake_id' => $intake->id, 'status' => 'In Progress',
        'priority' => 'Normal', 'service_total' => 5000, 'created_by' => $user->id,
    ]);
    Invoice::create([
        'number' => 'INV-TEST-0001', 'job_card_id' => $job->id, 'subtotal' => 5000, 'total' => 5000,
        'paid_amount' => 5000, 'due_amount' => 0, 'status' => 'Paid', 'public_token' => str_repeat('a', 48), 'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSeeText('WP TEST 1001')
        ->assertSeeText('Rs. 5,000.00');
});

test('job card intake picker is searchable across bike and customer details', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Searchable Customer', 'phone' => '0779876543']);
    $bike = $customer->bikes()->create(['number' => 'WP SEARCH 2026', 'model' => 'Yamaha FZ']);
    BikeIntake::create([
        'intake_number' => 'IN-SEARCH-0001', 'bike_id' => $bike->id, 'complaint' => 'Brake inspection',
        'estimated_amount' => 0, 'advance_payment' => 0, 'is_service' => false, 'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('jobs.create'))
        ->assertOk()
        ->assertSee('id="intake-search"', false)
        ->assertSee('data-search="in-search-0001 wp search 2026 yamaha fz searchable customer 0779876543"', false)
        ->assertSeeText('Searchable Customer');
});
