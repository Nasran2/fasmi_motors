<?php

use App\Models\BikeIntake;
use App\Models\JobCard;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    $this->post(route('intakes.store'), ['bike_number' => 'AMOUNT-100', 'bike_model' => 'Honda Dio', 'phone' => '0771112211', 'complaint' => 'Engine repair'])->assertSessionHasNoErrors();
    $this->post(route('jobs.store'), ['bike_intake_id' => BikeIntake::sole()->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    $this->job = JobCard::sole();
    $this->amounts = ['parts_total' => 1000, 'service_total' => 200, 'labor_total' => 100, 'washing_total' => 50, 'other_total' => 150, 'tax' => 75];
    $this->job->update($this->amounts);
});

test('a cleared labor amount saves with the other entered charges', function () {
    $this->put(route('jobs.update', $this->job), [
        'status' => 'Received', 'service_total' => 4000, 'labor_total' => '',
        'washing_total' => 500, 'other_total' => 13000, 'tax' => 0,
        'other_income_name' => 'Engine Rep', 'show_labor_on_bill' => 1,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    expect((float) $job->labor_total)->toBe(0.0)
        ->and((float) $job->service_total)->toBe(4000.0)
        ->and((float) $job->washing_total)->toBe(500.0)
        ->and((float) $job->other_total)->toBe(13000.0)
        ->and((float) $job->parts_total)->toBe(1000.0)
        ->and($job->total)->toBe(18500.0);
});

test('cleared job charge and tax fields save as zero', function ($blank) {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received'] + array_fill_keys(array_keys($this->amounts), $blank))
        ->assertRedirect()->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    foreach (array_keys($this->amounts) as $field) {
        expect($job->$field)->not->toBeNull();
        expect((float) $job->$field)->toBe(0.0);
    }
    expect($job->total)->toBe(0.0);
})->with(['empty string' => [''], 'null' => [null], 'whitespace' => ['   ']]);

test('status only updates preserve existing job amounts', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Diagnosis'])->assertRedirect()->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    foreach ($this->amounts as $field => $amount) {
        expect((float) $job->$field)->toBe((float) $amount);
    }
});

test('invalid amounts are rejected before any charges change', function ($amount) {
    $this->put(route('jobs.update', $this->job), ['status' => 'Diagnosis', 'service_total' => '', 'labor_total' => $amount])
        ->assertSessionHasErrors('labor_total');

    $job = $this->job->fresh();
    expect($job->status)->toBe('Received')->and((float) $job->service_total)->toBe(200.0)
        ->and((float) $job->labor_total)->toBe(100.0);
})->with([-1, 'invalid']);

test('cleared amounts cannot change locked jobs', function () {
    $this->job->update(['status' => 'Completed']);
    $this->put(route('jobs.update', $this->job), ['status' => 'Delivered'] + array_fill_keys(array_keys($this->amounts), ''))
        ->assertRedirect()->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    foreach ($this->amounts as $field => $amount) {
        expect((float) $job->$field)->toBe((float) $amount);
    }
});
