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
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Fixed discount (LKR)')->assertSeeText('Percentage discount (%)')->assertSee('name="discount_percentage"', false)->assertSee('name="fixed_discount"', false)->assertSeeText('Rs. 170.00');
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

test('percentage and fixed discounts add together and print as one discount', function () {
    $this->job->update(['parts_total' => 1000, 'service_total' => 0, 'labor_total' => 0, 'washing_total' => 0, 'other_total' => 0, 'tax' => 0]);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_percentage' => 10, 'fixed_discount' => 100])
        ->assertSessionHasNoErrors();
    $job = $this->job->fresh();
    expect((float) $job->discount_percentage)->toBe(10.0)->and((float) $job->fixed_discount)->toBe(100.0)
        ->and($job->percentage_discount_amount)->toBe(100.0)->and((float) $job->discount)->toBe(200.0)->and($job->total)->toBe(800.0);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Rs. 100.00 added to discount')
        ->assertSeeText('Total discount: Rs. 200.00')->assertSeeText('- Rs. 200.00');
    $this->post(route('jobs.invoice', $job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])->assertSessionHasNoErrors();
    $invoice = $job->fresh()->invoice;
    expect((float) $invoice->discount)->toBe(200.0)->and((float) $invoice->total)->toBe(800.0)->and((float) $invoice->due_amount)->toBe(800.0);
    foreach (['a4', '80mm'] as $format) {
        $response = $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertSeeText('200.00')->assertDontSeeText('Percentage discount')->assertDontSeeText('Fixed discount');
        expect(substr_count($response->getContent(), '<td>Discount</td>'))->toBe(1);
    }
});

test('combined discounts round the percentage amount and recalculate when job charges change', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_percentage' => 12.5, 'fixed_discount' => 100.05])
        ->assertSessionHasNoErrors();
    $job = $this->job->fresh();
    expect($job->percentage_discount_amount)->toBe(187.5)->and((float) $job->discount)->toBe(287.55)->and($job->total)->toBe(1287.45);
    $job->update(['parts_total' => 2000.08]);
    expect($job->percentage_discount_amount)->toBe(312.51)->and((float) $job->discount)->toBe(412.56)->and($job->total)->toBe(2162.52)
        ->and((float) $job->fixed_discount)->toBe(100.05);
    $this->put(route('jobs.update', $job), ['status' => 'Diagnosis'])->assertSessionHasNoErrors();
    expect((float) $job->fresh()->discount)->toBe(412.56);
});

test('combined discounts can be edited independently or cleared and legacy requests still replace both components', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_percentage' => 10, 'fixed_discount' => 100])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'fixed_discount' => 50])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->discount)->toBe(200.0)->and((float) $this->job->fresh()->discount_percentage)->toBe(10.0);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_type' => 'percentage', 'discount_value' => 10])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->discount)->toBe(150.0)->and((float) $this->job->fresh()->fixed_discount)->toBe(0.0);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_percentage' => '', 'fixed_discount' => ''])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->discount)->toBe(0.0)->and((float) $this->job->fresh()->discount_percentage)->toBe(0.0);
});

test('combined discounts reject invalid inputs without changing charges', function ($field, $value) {
    $data = ['status' => 'Diagnosis', 'service_total' => 999, 'discount_percentage' => 10, 'fixed_discount' => 100];
    $data[$field] = $value;
    $this->put(route('jobs.update', $this->job), $data)->assertSessionHasErrors($field);
    expect((float) $this->job->fresh()->discount)->toBe(0.0)->and((float) $this->job->fresh()->service_total)->toBe(200.0);
})->with([
    ['discount_percentage', 101], ['discount_percentage', -1], ['discount_percentage', '10.123'],
    ['discount_percentage', 'invalid'], ['fixed_discount', -1], ['fixed_discount', '1.234'],
    ['fixed_discount', 'invalid'], ['fixed_discount', 1000000000000],
]);

test('combined discounts on revised bills cannot exceed unsettled charges', function () {
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'discount_percentage' => 10, 'fixed_discount' => 100])->assertSessionHasNoErrors();
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 1000, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $this->post(route('jobs.unlock', $this->job), ['status' => 'Diagnosis', 'reason' => 'Review combined discount'])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $this->job), ['status' => 'Diagnosis', 'discount_percentage' => 50, 'fixed_discount' => 500])->assertSessionHasErrors('total');
    expect((float) $this->job->fresh()->discount_percentage)->toBe(10.0)->and((float) $this->job->fresh()->fixed_discount)->toBe(100.0)
        ->and((float) $this->job->fresh()->discount)->toBe(250.0)->and((float) Invoice::first()->total)->toBe(1325.0);
});
