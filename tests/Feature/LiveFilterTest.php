<?php

use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    $this->customer1 = Customer::create([
        'name' => 'Mohamed Rifkhan',
        'phone' => '0773590412',
        'customer_type' => 'Normal Customer',
        'active' => true,
    ]);

    $this->bike1 = Bike::create([
        'customer_id' => $this->customer1->id,
        'number' => 'BBZ-3784',
        'model' => 'FZ V1',
        'brand' => 'Yamaha',
    ]);

    $this->customer2 = Customer::create([
        'name' => 'Ahamed Walk-in',
        'phone' => '0719203014',
        'customer_type' => 'Normal Customer',
        'active' => true,
    ]);

    $this->bike2 = Bike::create([
        'customer_id' => $this->customer2->id,
        'number' => 'BFR-2000',
        'model' => 'FZ V2',
        'brand' => 'Yamaha',
    ]);

    $this->intake1 = BikeIntake::create([
        'intake_number' => 'IN-261116-001',
        'bike_id' => $this->bike1->id,
        'complaint' => 'Brake sound check',
        'intake_date' => now(),
        'created_by' => auth()->id(),
    ]);
});

test('customer search filters by name, partial phone, and bike number with or without spaces/hyphens', function () {
    // Search by partial phone '07192'
    $resPhone = $this->get(route('customers.index', ['q' => '07192']));
    $resPhone->assertOk();
    $resPhone->assertSee('Ahamed Walk-in');
    $resPhone->assertDontSee('Mohamed Rifkhan');

    // Search by bike number with space 'BFR 2000'
    $resBikeSpace = $this->get(route('customers.index', ['q' => 'BFR 2000']));
    $resBikeSpace->assertOk();
    $resBikeSpace->assertSee('Ahamed Walk-in');
    $resBikeSpace->assertDontSee('Mohamed Rifkhan');

    // Search by bike number without hyphen 'bfr2000'
    $resClean = $this->get(route('customers.index', ['q' => 'bfr2000']));
    $resClean->assertOk();
    $resClean->assertSee('Ahamed Walk-in');
    $resClean->assertDontSee('Mohamed Rifkhan');
});

test('bikes list search filters by partial letters, bike number without hyphen, model, or customer phone', function () {
    // Search 'bbz'
    $res = $this->get(route('customers.bikes.index', ['q' => 'bbz']));
    $res->assertOk();
    $res->assertSee('BBZ-3784');
    $res->assertDontSee('BFR-2000');

    // Search 'bbz3784' (no hyphen)
    $resClean = $this->get(route('customers.bikes.index', ['q' => 'bbz3784']));
    $resClean->assertOk();
    $resClean->assertSee('BBZ-3784');
    $resClean->assertDontSee('BFR-2000');

    // Search '07192' (customer phone)
    $resPhone = $this->get(route('customers.bikes.index', ['q' => '07192']));
    $resPhone->assertOk();
    $resPhone->assertSee('BFR-2000');
    $resPhone->assertDontSee('BBZ-3784');
});

test('bike intake search filters by intake number, bike, or customer', function () {
    $res = $this->get(route('intakes.index', ['q' => 'IN-261116-001']));
    $res->assertOk();
    $res->assertSee('BBZ-3784');

    $resClean = $this->get(route('intakes.index', ['q' => 'bbz 3784']));
    $resClean->assertOk();
    $resClean->assertSee('IN-261116-001');
});
