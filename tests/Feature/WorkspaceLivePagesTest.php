<?php

use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Technician;
use App\Models\User;

test('today summary and performance pages render persisted workshop data', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Live Summary Customer', 'phone' => '0771112233']);
    $bike = $customer->bikes()->create(['number' => 'WP LIVE 1000', 'model' => 'Honda Dio']);
    $intake = BikeIntake::create([
        'intake_number' => 'IN-LIVE-1000', 'bike_id' => $bike->id, 'complaint' => 'Live dashboard service',
        'estimated_amount' => 6500, 'advance_payment' => 1000, 'is_service' => true, 'created_by' => $user->id,
    ]);
    $job = JobCard::create([
        'number' => 'JOB-LIVE-1000', 'bike_intake_id' => $intake->id, 'status' => 'Completed',
        'priority' => 'Normal', 'service_total' => 6500, 'created_by' => $user->id,
    ]);
    Invoice::create([
        'number' => 'INV-LIVE-1000', 'job_card_id' => $job->id, 'subtotal' => 6500, 'total' => 6500,
        'paid_amount' => 5000, 'due_amount' => 1500, 'status' => 'Partially Paid',
        'public_token' => str_repeat('l', 48), 'created_by' => $user->id,
    ]);

    $this->actingAs($user)->get(route('workspace.page', ['section' => 'dashboard', 'page' => 'today-summary']))
        ->assertOk()->assertSee('today-summary-live')->assertSeeText('WP LIVE 1000')->assertDontSeeText('Page available');
    $this->actingAs($user)->get(route('workspace.page', ['section' => 'dashboard', 'page' => 'workshop-performance']))
        ->assertOk()->assertSee('workshop-performance-live')->assertSeeText('JOB-LIVE-1000')->assertDontSeeText('Page available');
});

test('bike intake workspace filters use real intake, service, technician and due data', function () {
    $user = User::factory()->create();
    $technician = Technician::create(['name' => 'Live Technician', 'phone' => '0772223344']);
    $customer = Customer::create(['name' => 'Filtered Customer', 'phone' => '0773334455']);

    $pendingBike = $customer->bikes()->create(['number' => 'WP PENDING 1', 'model' => 'Bajaj CT100']);
    BikeIntake::create(['intake_number' => 'IN-PENDING-1', 'bike_id' => $pendingBike->id, 'complaint' => 'Pending inspection', 'created_by' => $user->id]);

    $serviceBike = $customer->bikes()->create(['number' => 'WP SERVICE 2', 'model' => 'Yamaha FZ', 'is_technician_bike' => true, 'technician_id' => $technician->id]);
    $serviceIntake = BikeIntake::create(['intake_number' => 'IN-SERVICE-2', 'bike_id' => $serviceBike->id, 'complaint' => 'Technician service job', 'is_service' => true, 'estimated_amount' => 8000, 'created_by' => $user->id]);
    $job = JobCard::create(['number' => 'JOB-SERVICE-2', 'bike_intake_id' => $serviceIntake->id, 'main_technician_id' => $technician->id, 'status' => 'In Progress', 'priority' => 'Normal', 'created_by' => $user->id]);
    Invoice::create(['number' => 'INV-SERVICE-2', 'job_card_id' => $job->id, 'subtotal' => 8000, 'total' => 8000, 'paid_amount' => 3000, 'due_amount' => 5000, 'status' => 'Partially Paid', 'public_token' => str_repeat('s', 48), 'created_by' => $user->id]);

    $routes = [
        'pending-intakes' => 'WP PENDING 1',
        'service-jobs' => 'WP SERVICE 2',
        'technician-bikes' => 'WP SERVICE 2',
        'due-bike-records' => 'WP SERVICE 2',
    ];
    foreach ($routes as $page => $expectedBike) {
        $this->actingAs($user)->get(route('workspace.page', ['section' => 'bike-intake', 'page' => $page]))
            ->assertOk()->assertSee('live-intake-records')->assertSeeText($expectedBike)->assertDontSeeText('Page available');
    }
});

test('job card status workspaces return only live jobs for their status', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Status Customer', 'phone' => '0774445566']);
    $statuses = [
        'assigned-jobs' => 'Assigned', 'in-progress-jobs' => 'In Progress', 'waiting-for-parts' => 'Waiting for Parts',
        'completed-jobs' => 'Completed', 'delivered-jobs' => 'Delivered', 'cancelled-jobs' => 'Cancelled',
    ];

    $position = 0;
    foreach ($statuses as $status) {
        $position++;
        $bike = $customer->bikes()->create(['number' => 'WP STATUS '.$position, 'model' => 'Test Model']);
        $intake = BikeIntake::create(['intake_number' => 'IN-STATUS-'.$position, 'bike_id' => $bike->id, 'complaint' => $status.' repair', 'created_by' => $user->id]);
        JobCard::create(['number' => 'JOB-STATUS-'.$position, 'bike_intake_id' => $intake->id, 'status' => $status, 'priority' => 'Normal', 'created_by' => $user->id]);
    }

    foreach ($statuses as $page => $status) {
        $this->actingAs($user)->get(route('workspace.page', ['section' => 'job-cards', 'page' => $page]))
            ->assertOk()->assertSee('live-job-records')->assertSeeText($status.' repair')->assertDontSeeText('Page available');
    }
});
