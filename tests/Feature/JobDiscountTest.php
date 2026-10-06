<?php

use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->post(route('intakes.store'), ['bike_number' => 'DISC-100', 'bike_model' => 'Honda Dio', 'phone' => '0771112211', 'complaint' => 'Discount test'])->assertSessionHasNoErrors();
    $this->post(route('jobs.store'), ['bike_intake_id' => BikeIntake::first()->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    $this->job = JobCard::first();
    $this->job->update(['parts_total' => 1000, 'service_total' => 200, 'labor_total' => 100, 'washing_total' => 50, 'other_total' => 150, 'tax' => 75]);
});

test('job discounts save as fixed amounts or percentages and survive reload', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'fixed', 'discount_value' => 75])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->discount)->toBe(75.0)->and($this->job->fresh()->total)->toBe(1500.0);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'percentage', 'discount_value' => 10, 'service_total' => 400])->assertSessionHasNoErrors();
    $job = $this->job->fresh();
    expect($job->discount_type)->toBe('percentage')->and((float) $job->discount_value)->toBe(10.0)->and((float) $job->discount)->toBe(170.0)->and($job->total)->toBe(1605.0);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Fixed')->assertSee('aria-label="Percentage"', false)->assertSee('name="discount_value"', false)->assertSeeText('Rs. 170.00');
});

test('percentage job discounts recalculate after parts change and reach the invoice', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'percentage', 'discount_value' => 12.5])->assertSessionHasNoErrors();
    $job = $this->job->fresh();
    expect((float) $job->discount)->toBe(187.5);
    $job->update(['parts_total' => 2000.08]);
    expect((float) $job->discount)->toBe(312.51)->and($job->total)->toBe(2262.57);
    $this->post(route('jobs.invoice', $job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertSessionHasNoErrors();
    $invoice = Invoice::first();
    expect((float) $invoice->discount)->toBe(312.51)->and((float) $invoice->total)->toBe(2262.57)->and((float) $invoice->due_amount)->toBe(2262.57);
});

test('legacy fixed discount requests still work and switching back to fixed uses the entered value', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'percentage', 'discount_value' => 10])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'fixed', 'discount_value' => 10])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->discount)->toBe(10.0);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount' => 200])->assertSessionHasNoErrors();
    $job = $this->job->fresh();
    expect($job->discount_type)->toBe('fixed')->and((float) $job->discount_value)->toBe(200.0)->and((float) $job->discount)->toBe(200.0);
    $job->update(['parts_total' => 3000]);
    expect((float) $job->discount)->toBe(200.0);
});

test('job percentage discount rejects invalid values without changing the job', function ($value) {
    $this->put(route('jobs.update', $this->job), ['status' => 'In Progress', 'discount_type' => 'percentage', 'discount_value' => $value, 'service_total' => 999])->assertSessionHasErrors('discount_value');
    expect((float) $this->job->fresh()->service_total)->toBe(200.0)->and($this->job->fresh()->status)->toBe('Received');
})->with([101, -1, 'invalid', '']);

test('job percentage discount accepts zero and one hundred percent', function ($value) {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'percentage', 'discount_value' => $value])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->discount)->toBe(1500.0 * $value / 100);
})->with([0, 100]);

test('percentage discount changes cannot reduce an existing bill below its payments', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'percentage', 'discount_value' => 10])->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 1000, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $this->post(route('jobs.unlock', $this->job), ['status' => 'Diagnosis', 'reason' => 'Review bill charges'])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $this->job), ['status' => 'Diagnosis', 'discount_type' => 'percentage', 'discount_value' => 90])->assertSessionHasErrors('total');
    expect((float) $this->job->fresh()->discount_value)->toBe(10.0)->and((float) $this->job->fresh()->discount)->toBe(150.0)->and((float) Invoice::first()->total)->toBe(1425.0)->and((float) Invoice::first()->paid_amount)->toBe(1000.0);
});
