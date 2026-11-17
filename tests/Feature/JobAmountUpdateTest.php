<?php

use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\JobCard;
use App\Models\User;
use App\Support\InvoiceDocument;

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

test('main account charges keep their full amount on the bill and route receipts to Main', function () {
    $this->job->update(['parts_total' => 0, 'washing_total' => 0, 'tax' => 0]);
    $this->put(route('jobs.update', $this->job), [
        'status' => 'Received',
        'show_labor_on_bill' => 1,
        'main_account_charges' => [
            ['name' => 'Pickup service', 'amount' => '120.00'],
            ['name' => 'Internal workshop fee', 'amount' => '80.00', 'show_on_bill' => '0'],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    $job = $this->job->fresh();
    expect($job->main_account_charges[0]['show_on_bill'])->toBeTrue()
        ->and($job->main_account_charges[1]['show_on_bill'])->toBeFalse()
        ->and($job->total)->toBe(650.0)
        ->and($job->service_labor_commission_base)->toBe(300.0);

    $this->post(route('jobs.invoice', $job), ['paid_amount' => 650, 'payment_method' => 'Cash'])
        ->assertRedirect()->assertSessionHasNoErrors();

    $invoice = $job->invoice()->firstOrFail();
    $payment = $invoice->payments()->firstOrFail();
    $receipts = AccountTransaction::whereMorphedTo('reference', $payment)
        ->where('transaction_type', 'income')->pluck('credit', 'account_type');

    expect((float) $invoice->service_amount)->toBe(400.0)
        ->and((float) $receipts['main'])->toBe(500.0)
        ->and((float) $receipts['spare_parts'])->toBe(150.0);

    foreach ([false, true] as $pdfMode) {
        $lines = collect(InvoiceDocument::data($invoice, pdfMode: $pdfMode)['lines'])->pluck('total', 'name');
        expect($lines->get('Service'))->toBe(280.0)
            ->and($lines->get('Pickup service'))->toBe(120.0)
            ->and($lines->has('Internal workshop fee'))->toBeFalse()
            ->and($lines->sum())->toBe(650.0);
    }

    $this->put(route('jobs.update', $job), ['status' => 'Billed', 'main_account_charges_present' => 1])
        ->assertSessionHasNoErrors();
    expect((float) $job->fresh()->main_account_charge_total)->toBe(200.0);

    $this->post(route('jobs.unlock', $job), ['status' => 'Diagnosis', 'reason' => 'Update the printed charge name'])
        ->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $job), [
        'status' => 'Diagnosis',
        'main_account_charges' => [['name' => 'Updated pickup service', 'amount' => 200]],
    ])->assertSessionHasNoErrors();
    expect(collect(InvoiceDocument::data($invoice->fresh())['lines'])->pluck('name')->all())->toContain('Updated pickup service')
        ->and((float) $invoice->fresh()->total)->toBe(650.0)
        ->and(AccountTransaction::whereMorphedTo('reference', $payment)->count())->toBe(2);
});

test('main account charges remain optional, validate both fields and can be removed', function () {
    $this->put(route('jobs.update', $this->job), [
        'status' => 'Received',
        'main_account_charges' => [['name' => 'Pickup', 'amount' => 100]],
    ])->assertSessionHasNoErrors();

    $this->put(route('jobs.update', $this->job), [
        'status' => 'Received',
        'main_account_charges' => [['name' => '', 'amount' => '-1']],
    ])->assertSessionHasErrors(['main_account_charges.0.name', 'main_account_charges.0.amount']);

    $this->put(route('jobs.update', $this->job), ['status' => 'Diagnosis'])->assertSessionHasNoErrors();
    expect((float) $this->job->fresh()->main_account_charge_total)->toBe(100.0);

    $this->put(route('jobs.update', $this->job), ['status' => 'Diagnosis', 'main_account_charges_present' => 1])
        ->assertSessionHasNoErrors();
    expect($this->job->fresh()->main_account_charges)->toBe([])
        ->and((float) $this->job->fresh()->main_account_charge_total)->toBe(0.0);
});
