<?php

use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->actingAs(User::factory()->create())->withOpenCashier();
    $this->intakeData = ['bike_number' => 'INTAKE-DATE-100', 'bike_model' => 'Honda Dio', 'complaint' => 'Service'];
});

test('new intake defaults to today in the workshop timezone and supports existing callers', function () {
    $this->travelTo(Carbon::parse('2026-10-19 00:15:00', 'Asia/Colombo'));
    $this->get(route('intakes.create'))->assertOk()->assertSee('name="intake_date" value="2026-10-19"', false);
    $this->post(route('intakes.store'), $this->intakeData)->assertSessionHasNoErrors();
    expect(BikeIntake::sole()->intake_date->toDateString())->toBe('2026-10-19');
});

test('chosen intake date is saved and shown in intake and customer history without changing creation time', function () {
    $date = today()->subDays(5);
    $this->post(route('intakes.store'), $this->intakeData + ['intake_date' => $date->toDateString()])->assertSessionHasNoErrors();
    $intake = BikeIntake::sole();
    expect($intake->intake_date->toDateString())->toBe($date->toDateString())->and($intake->created_at->isToday())->toBeTrue();
    foreach ([route('intakes.index'), route('intakes.show', $intake), route('customers.show', $intake->bike->customer), route('customers.bikes.show', $intake->bike)] as $url) {
        $this->get($url)->assertOk()->assertSeeText($date->format('d M Y'));
    }
    $this->get(route('intakes.edit', $intake))->assertOk()->assertSee('name="intake_date" value="'.$date->toDateString().'"', false);
    $originalCreatedAt = $intake->created_at;
    $this->put(route('intakes.update', $intake), $this->intakeData + ['intake_date' => $date->copy()->subDay()->toDateString()])->assertSessionHasNoErrors();
    expect($intake->fresh()->intake_date->toDateString())->toBe($date->copy()->subDay()->toDateString())
        ->and($intake->fresh()->created_at->equalTo($originalCreatedAt))->toBeTrue();
    $this->put(route('intakes.update', $intake), $this->intakeData)->assertSessionHasNoErrors();
    expect($intake->fresh()->intake_date->toDateString())->toBe($date->copy()->subDay()->toDateString());
});

test('invalid intake dates are rejected before creating or editing records', function ($date) {
    $this->post(route('intakes.store'), $this->intakeData + ['intake_date' => $date])->assertSessionHasErrors('intake_date');
    expect(BikeIntake::count())->toBe(0)->and(Customer::count())->toBe(0);
    $this->post(route('intakes.store'), $this->intakeData)->assertSessionHasNoErrors();
    $intake = BikeIntake::sole();
    $this->put(route('intakes.update', $intake), $this->intakeData + ['intake_date' => $date])->assertSessionHasErrors('intake_date');
    expect($intake->fresh()->intake_date->isToday())->toBeTrue();
})->with(['', 'not-a-date', '2026-02-30', '19/10/2026']);

test('backdated intake is counted on the selected date and sorted by intake date', function () {
    $yesterday = today()->subDay();
    $this->post(route('intakes.store'), $this->intakeData + ['intake_date' => $yesterday->toDateString()])->assertSessionHasNoErrors();
    $older = BikeIntake::sole();
    $this->post(route('intakes.store'), array_replace($this->intakeData, ['bike_number' => 'INTAKE-DATE-200']))->assertSessionHasNoErrors();
    $todayIntake = BikeIntake::latest('id')->firstOrFail();
    $list = $this->get(route('intakes.index'))->assertOk()->viewData('intakes');
    expect($list->first()->id)->toBe($todayIntake->id);
    $todayPage = $this->get(route('workspace.page', ['section' => 'dashboard', 'page' => 'today-summary']))->assertOk();
    expect($todayPage->viewData('cards')[0]['value'])->toBe(1)
        ->and($todayPage->viewData('recentIntakes')->pluck('id')->all())->toBe([$todayIntake->id]);
    $dashboard = $this->get(route('dashboard', ['filter' => 'yesterday']))->assertOk();
    expect(collect($dashboard->viewData('cards'))->firstWhere('label', 'Bike Intake')['value'])->toBe(1);
    expect($older->created_at->isToday())->toBeTrue();
});

test('job card creation date defaults to today and dates the job without changing the intake', function () {
    $date = today()->subDays(3)->toDateString();
    $this->post(route('intakes.store'), $this->intakeData + ['intake_date' => $date])->assertSessionHasNoErrors();
    $intake = BikeIntake::sole();
    $this->get(route('jobs.create'))->assertOk()->assertSee('name="job_date" type="date" value="'.today()->toDateString().'"', false);
    $this->get(route('jobs.create', ['intake' => $intake->id]))->assertOk()
        ->assertSeeText('Job card creation date')->assertSee('name="job_date" type="date" value="'.today()->toDateString().'"', false)
        ->assertSeeText('Intake date: '.$intake->intake_date->format('d M Y'));
    $correctedDate = today()->subDays(4)->toDateString();
    $this->post(route('jobs.store'), ['bike_intake_id' => $intake->id, 'job_date' => $correctedDate, 'priority' => 'Normal'])
        ->assertSessionHasNoErrors();
    expect(JobCard::sole()->bike_intake_id)->toBe($intake->id)
        ->and(JobCard::sole()->created_at->toDateString())->toBe($correctedDate)
        ->and(JobCard::sole()->number)->toStartWith('JOB-'.Carbon::parse($correctedDate)->format('ymd').'-')
        ->and($intake->fresh()->intake_date->toDateString())->toBe($date)
        ->and($intake->fresh()->created_at->isToday())->toBeTrue();
});

test('job card creation uses today when omitted and rejects invalid job dates', function () {
    $date = today()->subDays(3)->toDateString();
    $this->post(route('intakes.store'), $this->intakeData + ['intake_date' => $date])->assertSessionHasNoErrors();
    $intake = BikeIntake::sole();
    foreach (['', '2026-02-30'] as $invalidDate) {
        $this->post(route('jobs.store'), ['bike_intake_id' => $intake->id, 'job_date' => $invalidDate, 'priority' => 'Normal'])
            ->assertSessionHasErrors('job_date');
        expect(JobCard::count())->toBe(0)->and($intake->fresh()->intake_date->toDateString())->toBe($date);
    }
    $this->post(route('jobs.store'), ['bike_intake_id' => $intake->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    expect(JobCard::sole()->created_at->isToday())->toBeTrue()
        ->and($intake->fresh()->intake_date->toDateString())->toBe($date);
});

test('intake numbers use sequential format IN-Date-001 and intake list displays columns in requested order with job card link', function () {
    $date = Carbon::parse('2026-11-16');
    $this->travelTo($date);

    $this->post(route('intakes.store'), [
        'bike_number' => 'BFR-2000',
        'bike_model' => 'FZ V2',
        'customer_name' => 'Walk-in',
        'phone' => '0719203014',
        'complaint' => 'Full service, fork oil',
        'is_service' => 1,
        'intake_date' => '2026-11-16',
    ])->assertSessionHasNoErrors();

    $this->post(route('intakes.store'), [
        'bike_number' => 'BIF-6687',
        'bike_model' => 'Platina 100',
        'customer_name' => 'John',
        'phone' => '0777303685',
        'complaint' => 'Rear shocket',
        'is_service' => 0,
        'intake_date' => '2026-11-16',
    ])->assertSessionHasNoErrors();

    $intakes = BikeIntake::orderBy('id')->get();
    expect($intakes[0]->intake_number)->toBe('IN-2026_11_16-001')
        ->and($intakes[1]->intake_number)->toBe('IN-2026_11_16-002');

    // Create job card for the first intake
    $this->post(route('jobs.store'), [
        'bike_intake_id' => $intakes[0]->id,
        'priority' => 'Normal',
    ])->assertSessionHasNoErrors();
    $job = JobCard::sole();

    $response = $this->get(route('intakes.index'))->assertOk();

    // Verify column headers exist in correct order
    $html = $response->getContent();
    expect($html)->toContain('<th>Intake</th>')
        ->and($html)->toContain('<th>Bike number</th>')
        ->and($html)->toContain('<th>Service or not</th>')
        ->and($html)->toContain('<th>Complaint</th>')
        ->and($html)->toContain('<th>Job card</th>')
        ->and($html)->toContain('<th>Date</th>')
        ->and($html)->toContain('<th>Customer & Phone</th>');

    // Verify job card link is present for first intake
    expect($html)->toContain(route('jobs.show', $job))
        ->and($html)->toContain($job->number);

    // Verify "Not created" for second intake
    expect($html)->toContain('Not created');

    // Verify service badges
    expect($html)->toContain('Service')
        ->and($html)->toContain('Repair');

    // Verify search works by intake number
    $searchResponse = $this->get(route('intakes.index', ['q' => 'IN-2026_11_16-001']))->assertOk();
    expect($searchResponse->viewData('intakes')->pluck('id')->all())->toBe([$intakes[0]->id]);
});

test('intake search suggestions match bike numbers across formatting and link to the intake', function () {
    $customer = Customer::create(['name' => 'Search Customer', 'phone' => '0771234567']);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'BFR-2000', 'model' => 'FZ V2']);
    $intake = BikeIntake::create(['bike_id' => $bike->id, 'complaint' => 'Full service', 'created_by' => auth()->id()]);

    $this->getJson(route('intakes.index', ['q' => 'bfr2000', 'suggestions' => 1]))
        ->assertOk()
        ->assertExactJson([[
            'title' => $intake->intake_number,
            'detail' => 'BFR-2000 · FZ V2 · Search Customer',
            'url' => route('intakes.show', $intake),
        ]]);
});
