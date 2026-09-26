<?php

use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
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

    $this->get(route('live-board.customer'))->assertOk()->assertSee('Object.values(data.groups || {})', false);
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
