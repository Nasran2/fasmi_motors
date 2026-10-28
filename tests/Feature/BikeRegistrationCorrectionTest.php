<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\ServiceReminder;
use App\Models\User;
use App\Services\SettlementService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->customer = Customer::create(['name' => 'Registration Customer', 'phone' => '0774455667']);
    $this->bike = $this->customer->bikes()->create(['number' => 'BAM 546', 'model' => 'Dio', 'chassis_number' => 'FRAME-REGISTRATION']);
    $this->intake = BikeIntake::create(['intake_number' => 'IN-REGISTRATION', 'bike_id' => $this->bike->id, 'complaint' => 'Service', 'created_by' => auth()->id()]);
    $this->job = JobCard::create(['number' => 'JOB-REGISTRATION', 'bike_intake_id' => $this->intake->id, 'status' => 'Billed', 'service_total' => 1000, 'created_by' => auth()->id()]);
    $this->invoice = Invoice::create(['number' => 'INV-REGISTRATION', 'invoice_type' => 'Job Card Bill', 'job_card_id' => $this->job->id, 'customer_id' => $this->customer->id, 'bike_id' => $this->bike->id, 'subtotal' => 1000, 'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid', 'public_token' => 'registration-correction-token', 'bill_date' => today()->subDays(5), 'created_by' => auth()->id()]);
    $this->input = ['bike_number' => ' bam-0546 ', 'bike_number_reason' => 'Correct the full registration number'];
});

test('top editor renames one shared bike across intake jobs bills reminders and reports without moving payments', function () {
    $otherIntake = $this->bike->intakes()->create(['intake_number' => 'IN-REGISTRATION-OLD', 'complaint' => 'Older repair', 'created_by' => auth()->id()]);
    $otherJob = JobCard::create(['number' => 'JOB-REGISTRATION-OLD', 'bike_intake_id' => $otherIntake->id, 'status' => 'Completed', 'created_by' => auth()->id()]);
    $quick = JobCard::create(['number' => 'JOB-REGISTRATION-QUICK', 'is_quick_job' => true, 'quick_bike_id' => $this->bike->id, 'quick_customer_id' => $this->customer->id, 'quick_bike_details' => ['number' => 'BAM 546', 'model' => 'Dio'], 'status' => 'Completed', 'created_by' => auth()->id(), 'updated_at' => today()->subDays(3)]);
    $quickUpdatedAt = $quick->updated_at->toDateTimeString();
    $reminder = ServiceReminder::create(['job_card_id' => $this->job->id, 'bike_id' => $this->bike->id, 'due_date' => today()->addMonth(), 'status' => 'Pending']);
    $receipt = app(SettlementService::class)->receive($this->invoice, ['method' => 'Cash', 'amount' => 300, 'account_id' => Account::systemAccount('main')->id], today()->subDays(2));
    $billDate = $this->invoice->bill_date->toDateTimeString();
    $count = Bike::count();
    $this->from(route('jobs.show', $this->job))->put(route('jobs.bike-number', $this->job), $this->input)->assertRedirect(route('jobs.show', $this->job))->assertSessionHasNoErrors();
    expect(Bike::count())->toBe($count)->and($this->bike->fresh()->number)->toBe('BAM-0546')
        ->and($this->bike->fresh()->chassis_number)->toBe('FRAME-REGISTRATION')
        ->and($this->intake->fresh()->bike_id)->toBe($this->bike->id)->and($otherIntake->fresh()->bike_id)->toBe($this->bike->id)
        ->and($otherJob->fresh()->bike->number)->toBe('BAM-0546')
        ->and($quick->fresh()->bike->number)->toBe('BAM-0546')->and($quick->fresh()->quick_bike_details['number'])->toBe('BAM-0546')
        ->and($quick->fresh()->updated_at->toDateTimeString())->toBe($quickUpdatedAt)
        ->and($this->invoice->fresh()->bike_id)->toBe($this->bike->id)->and($this->invoice->fresh()->bill_date->toDateTimeString())->toBe($billDate)
        ->and($receipt->fresh()->paid_at->toDateString())->toBe(today()->subDays(2)->toDateString())
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(700.0)->and($reminder->fresh()->bike->number)->toBe('BAM-0546');
    $audit = AuditLog::where('event', 'bike_number_corrected')->firstOrFail();
    expect($audit->auditable_id)->toBe($this->bike->id)->and($audit->old_values['number'])->toBe('BAM 546')->and($audit->new_values['number'])->toBe('BAM-0546')->and($audit->new_values['reason'])->toBe($this->input['bike_number_reason']);
    foreach ([route('intakes.show', $this->intake), route('jobs.show', $otherJob), route('jobs.show', $quick), route('sales.invoices.show', $this->invoice), route('customers.bikes.show', $this->bike)] as $url) {
        $this->get($url)->assertOk()->assertSeeText('BAM-0546');
    }
    foreach (['a4', 'a5', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $this->invoice->public_token, 'format' => $format]))->assertOk()->assertSeeText('BAM-0546')->assertDontSeeText('BAM 546');
    }
    $filters = ['date_range' => 'all_time', 'search' => 'BAM-0546'];
    $this->get(route('reports.index', $filters))->assertOk()->assertSeeText('INV-REGISTRATION');
    $this->get(route('reports.index', ['date_range' => 'all_time', 'search' => 'BAM 546']))->assertOk()->assertDontSeeText('INV-REGISTRATION');
    $page = $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Bike number corrected by')->assertSeeText('Edit bill date');
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="UTF-8">'.$page->getContent());
    $xpath = new DOMXPath($doc);
    expect($xpath->query('//*[@aria-label="Job card overview"]//input[@name="bike_number"]')->length)->toBe(1)
        ->and($xpath->query('//input[@name="bike_number" and @list]')->length)->toBe(0);
    if ($directory = getenv('BIKE_REGISTRATION_QA_DIR')) {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
        file_put_contents($directory.'/job.html', $page->getContent());
    }
});

test('registration corrections reject an existing active or archived bike even for the same customer', function ($archived) {
    $other = $this->customer->bikes()->create(['number' => 'TAKEN-1234', 'model' => 'Honda']);
    if ($archived) {
        $other->delete();
    }
    $this->put(route('jobs.bike-number', $this->job), array_replace($this->input, ['bike_number' => 'taken-1234']))->assertSessionHasErrorsIn('bikeNumber', 'bike_number');
    expect($this->bike->fresh()->number)->toBe('BAM 546')->and($this->job->fresh()->bike->id)->toBe($this->bike->id)
        ->and(AuditLog::where('event', 'bike_number_corrected')->count())->toBe(0);
})->with([false, true]);

test('registration correction requires a valid number and correction reason', function ($overrides, $field) {
    $this->put(route('jobs.bike-number', $this->job), array_replace($this->input, $overrides))->assertSessionHasErrorsIn('bikeNumber', $field);
    expect($this->bike->fresh()->number)->toBe('BAM 546');
})->with([
    [['bike_number' => ''], 'bike_number'],
    [['bike_number' => '<script>'], 'bike_number'],
    [['bike_number' => '---'], 'bike_number'],
    [['bike_number' => str_repeat('A', 31)], 'bike_number'],
    [['bike_number_reason' => ''], 'bike_number_reason'],
    [['bike_number_reason' => 'oops'], 'bike_number_reason'],
]);

test('view-only staff cannot correct a bike registration', function () {
    $role = Role::create(['name' => 'Registration Viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('jobs.show', $this->job))->assertOk()->assertDontSeeText('Save bike number');
    $this->put(route('jobs.bike-number', $this->job), $this->input)->assertForbidden();
    expect($this->bike->fresh()->number)->toBe('BAM 546');
});

test('quick-job header corrects the same shared bike and date editor cannot reassign it', function () {
    $quick = JobCard::create(['number' => 'QUICK-REGISTRATION', 'is_quick_job' => true, 'quick_bike_id' => $this->bike->id, 'quick_customer_id' => $this->customer->id, 'status' => 'Received', 'created_by' => auth()->id()]);
    $this->put(route('jobs.bike-number', $quick), $this->input)->assertSessionHasNoErrors();
    expect($this->job->fresh()->bike->number)->toBe('BAM-0546')->and($quick->fresh()->quick_bike_id)->toBe($this->bike->id);
    $this->put(route('jobs.bill-details', $this->job), ['bill_date' => today()->toDateString(), 'bike_number' => 'SWAP-1234', 'reason' => 'Attempt to change assigned bike'])->assertSessionHasErrors('bike_number');
    expect($this->bike->fresh()->number)->toBe('BAM-0546')->and($this->invoice->fresh()->bike_id)->toBe($this->bike->id);
});
