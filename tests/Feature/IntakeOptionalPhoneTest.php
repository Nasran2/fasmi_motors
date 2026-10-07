<?php

use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
});

test('intakes without phone numbers save separate customers and can become job cards', function () {
    foreach ([[], ['phone' => ''], ['phone' => null]] as $index => $phone) {
        $this->post(route('intakes.store'), $phone + [
            'bike_number' => 'WP NO PHONE '.$index,
            'bike_model' => 'Honda Dio',
            'customer_name' => 'Customer '.$index,
            'complaint' => 'Full service',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    $intakes = BikeIntake::with('bike.customer')->orderBy('id')->get();
    expect($intakes)->toHaveCount(3)
        ->and(Customer::count())->toBe(3)
        ->and($intakes->map(fn ($intake) => $intake->bike->customer->name)->all())->toBe(['Customer 0', 'Customer 1', 'Customer 2'])
        ->and($intakes->map(fn ($intake) => $intake->bike->customer->phone)->all())->toBe(['', '', '']);

    $this->post(route('jobs.store'), ['bike_intake_id' => $intakes->first()->id, 'priority' => 'Normal'])
        ->assertRedirect()->assertSessionHasNoErrors();
    expect(JobCard::firstOrFail()->bike_intake_id)->toBe($intakes->first()->id);

    foreach ([route('intakes.create'), route('intakes.edit', $intakes->first())] as $url) {
        $response = $this->get($url)->assertOk()->assertSee('(optional)');
        preg_match('/<input\b[^>]*\bid="phone"[^>]*>/', $response->getContent(), $matches);
        expect($matches)->toHaveCount(1)
            ->and($matches[0])->not->toContain('required');
    }
});

test('repeat intakes with no phone preserve the existing customer number', function () {
    $data = ['bike_number' => 'WP PHONE 1001', 'bike_model' => 'Honda Dio', 'customer_name' => 'Existing Customer', 'complaint' => 'Full service'];
    $this->post(route('intakes.store'), $data + ['phone' => '0771234567'])->assertSessionHasNoErrors();
    $this->post(route('intakes.store'), $data)->assertSessionHasNoErrors();
    $this->post(route('intakes.store'), $data + ['phone' => ''])->assertSessionHasNoErrors();

    expect(BikeIntake::count())->toBe(3)
        ->and(Customer::count())->toBe(1)
        ->and(Bike::firstOrFail()->customer->phone)->toBe('0771234567');
});

test('intakes can be edited without a phone or have their phone cleared and added later', function () {
    $data = ['bike_number' => 'WP PHONE 1002', 'bike_model' => 'Honda Dio', 'complaint' => 'Full service'];
    $this->post(route('intakes.store'), $data + ['phone' => '0771234567'])->assertSessionHasNoErrors();
    $intake = BikeIntake::with('bike.customer')->firstOrFail();

    $this->put(route('intakes.update', $intake), $data)->assertSessionHasNoErrors();
    expect($intake->bike->customer->fresh()->phone)->toBe('0771234567');

    $this->put(route('intakes.update', $intake), $data + ['phone' => ''])->assertSessionHasNoErrors();
    expect($intake->bike->customer->fresh()->phone)->toBe('');

    $this->put(route('intakes.update', $intake), $data + ['phone' => '0712345678'])->assertSessionHasNoErrors();
    expect($intake->bike->customer->fresh()->phone)->toBe('0712345678');

    $this->put(route('intakes.update', $intake), array_replace($data, ['bike_number' => 'WP PHONE 1003']))->assertSessionHasNoErrors();
    expect($intake->fresh()->bike->customer_id)->not->toBe($intake->bike->customer_id)
        ->and($intake->fresh()->bike->customer->phone)->toBe('');
});
