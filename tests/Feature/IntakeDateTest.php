<?php

use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake();
    $this->actingAs(User::factory()->create());
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
