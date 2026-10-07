<?php

use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('authenticated staff can load both live board views and live data', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('live-board.index'))
        ->assertOk()
        ->assertSee('Live Bike Status Board')
        ->assertSee('Quick Calculator')
        ->assertSee('Open Full Board');

    $this->actingAs($user)
        ->get(route('live-board.fullscreen'))
        ->assertOk()
        ->assertSee('Fasmir Motors Live Workshop Board');

    $this->actingAs($user)
        ->getJson(route('live-board.data'))
        ->assertOk()
        ->assertJsonStructure(['groups', 'counts', 'total', 'updated_at']);

    $this->get(route('live-board.customer'))
        ->assertOk()
        ->assertSee('INTAKES')
        ->assertSee('PROCESSING')
        ->assertSee('COMPLETED')
        ->assertSee('customer_display=1', false)
        ->assertDontSee('READY / WAITING');
});

test('customer display groups all workshop bikes into intake processing and completed stages', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $this->actingAs($user);
    $customer = Customer::create(['name' => 'Live Board Customer', 'phone' => '0771234567']);
    $technician = Technician::create(['name' => 'Workshop Technician', 'phone' => '0700000001', 'active' => true]);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP LIVE 2001', 'model' => 'Honda Dio']);
    BikeIntake::create(['intake_number' => 'IN-UNASSIGNED', 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => $user->id]);

    $statuses = ['Received', 'Assigned', 'Diagnosis', 'In Progress', 'Waiting for Parts', 'Washing / Final Check', 'On Hold', 'Repair Ready', 'Ready for Delivery', 'Pending Payment', 'Completed', 'Billed', 'Delivered', 'Cancelled'];
    foreach ($statuses as $index => $status) {
        $intake = BikeIntake::create(['intake_number' => 'IN-STAGE-'.$index, 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => $user->id]);
        $job = JobCard::create(['number' => 'JOB-STAGE-'.$index, 'bike_intake_id' => $intake->id, 'status' => $status, 'priority' => 'Normal', 'created_by' => $user->id]);
        if (in_array($status, ['Billed', 'Pending Payment'], true)) {
            $paid = $status === 'Billed';
            Invoice::create(['number' => 'INV-STAGE-'.$index, 'invoice_type' => 'Job Card', 'job_card_id' => $job->id, 'customer_id' => $customer->id, 'subtotal' => 1000, 'total' => 1000, 'paid_amount' => $paid ? 1000 : 0, 'due_amount' => $paid ? 0 : 1000, 'status' => $paid ? 'Paid' : 'Unpaid', 'public_token' => 'live-board-test-'.$index, 'created_by' => $user->id]);
        }
    }

    $assignedIntake = BikeIntake::create(['intake_number' => 'IN-ASSIGNED', 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => $user->id]);
    JobCard::create(['number' => 'JOB-ASSIGNED-RECEIVED', 'bike_intake_id' => $assignedIntake->id, 'main_technician_id' => $technician->id, 'status' => 'Received', 'priority' => 'Normal', 'created_by' => $user->id]);

    $response = $this->getJson(route('live-board.data', ['customer_display' => 1]))
        ->assertOk()
        ->assertJsonPath('total', 14)
        ->assertJsonPath('counts.intakes', 2)
        ->assertJsonPath('counts.processing', 7)
        ->assertJsonPath('counts.completed', 5)
        ->assertJsonMissing(['database_status' => 'Delivered'])
        ->assertJsonMissing(['database_status' => 'Cancelled']);

    expect(collect($response->json('groups.intakes'))->pluck('database_status')->all())->toBe(['Received', 'New Intake'])
        ->and(collect($response->json('groups.processing'))->pluck('database_status')->all())->toBe(['Assigned', 'Diagnosis', 'In Progress', 'Waiting for Parts', 'Washing / Final Check', 'On Hold', 'Received'])
        ->and(collect($response->json('groups.completed'))->pluck('database_status')->all())->toBe(['Repair Ready', 'Ready for Delivery', 'Pending Payment', 'Completed', 'Billed']);
});

test('live data includes unassigned intakes and excludes terminal jobs', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);
    $customer = Customer::create(['name' => 'Nimal Perera', 'phone' => '0771234567']);

    $activeBike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP LIVE 1001', 'model' => 'Yamaha FZ']);
    BikeIntake::create([
        'intake_number' => 'IN-LIVE-001',
        'bike_id' => $activeBike->id,
        'complaint' => 'Engine noise',
        'created_by' => $user->id,
    ]);

    $completedBike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP DONE 1002', 'model' => 'Honda Dio']);
    $completedIntake = BikeIntake::create([
        'intake_number' => 'IN-DONE-002',
        'bike_id' => $completedBike->id,
        'complaint' => 'Brake service',
        'created_by' => $user->id,
    ]);
    JobCard::create([
        'number' => 'JOB-DONE-002',
        'bike_intake_id' => $completedIntake->id,
        'status' => 'Completed',
        'priority' => 'Normal',
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->getJson(route('live-board.data'))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('groups.new_intake.0.bike_number', 'WP LIVE 1001')
        ->assertJsonMissing(['bike_number' => 'WP DONE 1002']);
});
