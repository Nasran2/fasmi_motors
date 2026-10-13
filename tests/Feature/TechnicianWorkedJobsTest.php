<?php

use App\Models\Customer;
use App\Models\JobCard;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianCommission;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-13 12:00:00'));
    $this->actingAs(User::factory()->create());
    $this->staff = Technician::create(['name' => 'Worked Jobs Staff', 'phone' => '0779000100', 'active' => true]);
    $customer = Customer::create(['name' => 'Jobs Customer', 'phone' => '0779000200']);
    $bike = $customer->bikes()->create(['number' => 'DAILY-JOBS', 'model' => 'Yamaha']);
    $intake = $bike->intakes()->create(['intake_number' => 'IN-DAILY-JOBS', 'complaint' => 'Repair', 'created_by' => auth()->id()]);
    $this->jobs = collect();
    foreach (['2026-09-10 12:00:00', '2026-10-12 12:00:00', '2026-10-13 00:00:00', '2026-10-13 23:59:59', '2026-10-14 00:00:00'] as $i => $date) {
        $this->jobs->push(JobCard::create([
            'number' => 'JOB-DAILY-'.$i, 'bike_intake_id' => $intake->id, 'main_technician_id' => $this->staff->id,
            'status' => 'Completed', 'priority' => 'Normal', 'created_by' => auth()->id(), 'created_at' => $date,
        ]));
    }
    foreach (['2026-09-10', '2026-10-12', '2026-10-13'] as $date) {
        TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => $date, 'status' => 'Present', 'created_by' => auth()->id()]);
    }
});

afterEach(function () {
    $this->travelBack();
});

test('worked jobs default to the salary period and show zero when no commission was recorded', function () {
    $response = $this->get(route('technicians.history', ['technician' => $this->staff->id, 'month' => '2026-10']))
        ->assertOk()->assertSeeText('Today')->assertSeeText('Filter Jobs')->assertSeeText('Commission: Rs. 0.00');
    expect($response->viewData('workedJobs')->pluck('id')->all())->toEqualCanonicalizing($this->jobs->slice(1)->pluck('id')->all());
    $response->assertDontSeeText('JOB-DAILY-0');
});

test('today worked jobs override the salary period and sum only this staff members recorded job commissions', function () {
    $other = Technician::create(['name' => 'Another Staff', 'phone' => '0779000101', 'active' => true]);
    foreach ([[$this->staff->id, $this->jobs[2]->id, 120, 'Pending'], [$this->staff->id, $this->jobs[2]->id, 30, 'Paid'], [$this->staff->id, $this->jobs[3]->id, 20, 'Pending'], [$other->id, $this->jobs[2]->id, 999, 'Pending'], [$this->staff->id, null, 777, 'Pending']] as [$staffId, $jobId, $amount, $status]) {
        TechnicianCommission::create([
            'technician_id' => $staffId, 'job_card_id' => $jobId, 'commission_type' => 'Manual',
            'base_amount' => 1000, 'percentage' => 10, 'amount' => $amount, 'earned_date' => '2026-09-10',
            'status' => $status, 'created_by' => auth()->id(),
        ]);
    }
    $response = $this->get(route('technicians.history', [
        'technician' => $this->staff->id, 'month' => '2026-09', 'jobs_from' => today()->toDateString(), 'jobs_to' => today()->toDateString(),
    ]))->assertOk()->assertSeeText('Commission: Rs. 170.00')->assertSeeText('Rs. 150.00')->assertSeeText('Rs. 20.00')
        ->assertSeeInOrder(['<th>Job</th>', '<th>Commission</th>', '<th>Bike</th>'], false);
    expect($response->viewData('workedJobs')->pluck('id')->all())->toEqualCanonicalizing([$this->jobs[2]->id, $this->jobs[3]->id]);
    expect($response->viewData('technician')->jobs->pluck('id')->all())->toBe([$this->jobs[0]->id])
        ->and($response->viewData('technician')->attendance->pluck('date')->map->toDateString()->all())->toBe(['2026-09-10'])
        ->and((float) $response->viewData('technician')->commissions->sum('amount'))->toBe(947.0);
    $response->assertDontSeeText('JOB-DAILY-0')->assertDontSeeText('JOB-DAILY-1')->assertDontSeeText('JOB-DAILY-4');
});

test('worked jobs allow a range with only an end date', function () {
    $response = $this->get(route('technicians.history', ['technician' => $this->staff->id, 'jobs_to' => '2026-10-12']))->assertOk();
    expect($response->viewData('workedJobs')->pluck('id')->all())->toEqualCanonicalizing([$this->jobs[0]->id, $this->jobs[1]->id]);
});

test('worked jobs reject invalid and reversed date filters', function (array $filters, string $field) {
    $this->getJson(route('technicians.history', ['technician' => $this->staff->id] + $filters))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    [['jobs_from' => 'not-a-date'], 'jobs_from'],
    [['jobs_to' => '2026-02-30'], 'jobs_to'],
    [['jobs_from' => '2026-10-13', 'jobs_to' => '2026-10-12'], 'jobs_to'],
]);
