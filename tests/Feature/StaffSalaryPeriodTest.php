<?php

use App\Models\SalaryCycleSetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianCommission;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\SalaryCalculationService;
use App\Services\SalaryCycleService;
use Carbon\Carbon;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    SalaryCycleSetting::current()->update([
        'salary_cycle_mode' => 'custom_cutoff', 'custom_cutoff_enabled' => true,
        'cycle_start_rule' => 'previous_month_balance_day', 'cycle_end_day' => 30, 'carry_missing_days' => true,
    ]);
    $this->staff = Technician::create([
        'name' => 'Period Staff', 'phone' => '0701000001', 'active' => true,
        'basic_salary' => 30000, 'daily_salary' => 1000, 'salary_type' => 'Daily',
        'commission_percentage' => 0, 'parts_commission_percentage' => 0, 'commission_type' => 'Manual only',
        'epf_eligible' => false, 'etf_eligible' => false,
    ]);
    $this->cycles = app(SalaryCycleService::class);
    $this->profileData = [
        'name' => $this->staff->name, 'phone' => $this->staff->phone, 'active' => 1,
        'basic_salary' => 30000, 'daily_salary' => 1000, 'salary_type' => 'Daily',
        'commission_percentage' => 0, 'parts_commission_percentage' => 0, 'commission_type' => 'Manual only',
        'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3,
    ];
});

it('preserves the configured system default for staff without overrides', function ($month, $start, $end) {
    $period = $this->cycles->getSalaryPeriod($month, $this->staff);
    expect($this->staff->fresh()->salary_period_mode)->toBe('system_default')
        ->and($period['salary_period_start'])->toBe($start)->and($period['salary_period_end'])->toBe($end)
        ->and($period['salary_period_source'])->toBe('system_default');
    expect(SalaryCycleSetting::current()->cycle_end_day)->toBe(30);
})->with([
    ['2026-06', '2026-05-31', '2026-06-29'],
    ['2026-07', '2026-06-30', '2026-07-30'],
    ['2026-08', '2026-07-31', '2026-08-29'],
    ['2026-09', '2026-08-30', '2026-09-29'],
    ['2026-10', '2026-09-30', '2026-10-30'],
    ['2026-11', '2026-10-31', '2026-11-29'],
]);

it('uses exact calendar month dates and inclusive day counts for individual staff', function ($month, $last, $days) {
    $this->staff->update(['salary_period_mode' => 'normal_calendar']);
    $period = $this->cycles->getSalaryPeriod($month, $this->staff);
    expect($period['salary_period_start'])->toBe($month.'-01')->and($period['salary_period_end'])->toBe($month.'-'.$last)
        ->and($period['salary_period_days'])->toBe($days)->and($period['carried_from_previous_month_days'])->toBe(0)
        ->and($period['excluded_dates'])->toBe([])->and($period['salary_cycle_mode'])->toBe('normal_calendar');
})->with([
    ['2026-01', '31', 31], ['2026-04', '30', 30], ['2026-02', '28', 28], ['2024-02', '29', 29], ['2026-12', '31', 31],
]);

it('calculates custom start days with safe short month boundaries', function ($month, $day, $start, $end) {
    $this->staff->update(['salary_period_mode' => 'custom_start', 'salary_start_day' => $day]);
    $period = $this->cycles->getSalaryPeriod($month, $this->staff);
    expect($period['salary_period_start'])->toBe($start)->and($period['salary_period_end'])->toBe($end);
})->with([
    ['2026-10', 1, '2026-10-01', '2026-10-31'],
    ['2026-10', 15, '2026-09-15', '2026-10-14'],
    ['2026-10', 31, '2026-09-30', '2026-10-30'],
    ['2026-02', 31, '2026-01-31', '2026-02-27'],
    ['2026-03', 31, '2026-02-28', '2026-03-30'],
    ['2024-02', 31, '2024-01-31', '2024-02-28'],
    ['2024-03', 31, '2024-02-29', '2024-03-30'],
    ['2027-01', 25, '2026-12-25', '2027-01-24'],
]);

it('never omits or counts a date twice across consecutive salary periods', function ($mode) {
    $this->staff->update(['salary_period_mode' => $mode]);
    foreach ($mode === 'custom_start' ? range(1, 31) : [null] as $day) {
        $this->staff->salary_start_day = $day;
        $previous = null;
        foreach (range(0, 25) as $offset) {
            $month = Carbon::parse('2023-12-01')->addMonthsNoOverflow($offset);
            $period = $this->cycles->getSalaryPeriod($month, $this->staff);
            if ($previous) {
                expect(Carbon::parse($previous['salary_period_end'])->addDay()->toDateString())->toBe($period['salary_period_start']);
            }
            expect($period['salary_period_days'])->toBe((int) Carbon::parse($period['salary_period_start'])->diffInDays(Carbon::parse($period['salary_period_end'])) + 1);
            $previous = $period;
        }
    }
})->with(['system_default', 'normal_calendar', 'custom_start']);

it('saves per staff rules and clears unused start days while retaining other staff defaults', function () {
    $other = Technician::create(['name' => 'Default Staff', 'phone' => '0701000002']);
    $this->put(route('technicians.update', $this->staff), $this->profileData + ['salary_period_mode' => 'custom_start', 'salary_start_day' => 25])->assertSessionHasNoErrors();
    expect($this->staff->fresh()->salary_start_day)->toBe(25)->and($this->staff->fresh()->salary_period_mode)->toBe('custom_start');
    $this->get(route('technicians.edit', $this->staff))->assertOk()->assertSee('value="custom_start" selected', false)->assertSee('value="25"', false)->assertSeeText('Salary Period Rule');
    $this->put(route('technicians.update', $this->staff), $this->profileData + ['salary_period_mode' => 'normal_calendar'])->assertSessionHasNoErrors();
    expect($this->staff->fresh()->salary_start_day)->toBeNull()->and($other->fresh()->salary_period_mode)->toBe('system_default')
        ->and(SalaryCycleSetting::current()->isCustomCutoffActive())->toBeTrue();
    $this->get(route('technicians.create'))->assertOk()->assertSeeText('Use system default');
});

it('rejects invalid employee cycle settings', function ($changes, $field) {
    $this->putJson(route('technicians.update', $this->staff), array_replace($this->profileData, $changes))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect($this->staff->fresh()->salary_period_mode)->toBe('system_default');
})->with([
    [['salary_period_mode' => 'unknown'], 'salary_period_mode'],
    [['salary_period_mode' => 'custom_start'], 'salary_start_day'],
    [['salary_period_mode' => 'custom_start', 'salary_start_day' => 0], 'salary_start_day'],
    [['salary_period_mode' => 'custom_start', 'salary_start_day' => 32], 'salary_start_day'],
    [['salary_period_mode' => 'custom_start', 'salary_start_day' => 15.5], 'salary_start_day'],
]);

it('uses the selected staff period in attendance salary history and salary slips', function () {
    $this->staff->update(['salary_period_mode' => 'normal_calendar']);
    foreach (['2026-09-30', '2026-10-01', '2026-10-30', '2026-10-31', '2026-11-01'] as $date) {
        TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => $date, 'status' => 'Present', 'working_minutes' => 480, 'created_by' => auth()->id()]);
    }
    $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->staff->id, 'month' => '2026-10']))->assertOk()
        ->assertJsonPath('salary_period_start', '2026-10-01')->assertJsonPath('salary_period_end', '2026-10-31')
        ->assertJsonPath('attendance.present_days', 3)->assertJsonPath('attendance_salary_amount', 3000);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    expect($sheet->period_start->toDateString())->toBe('2026-10-01')->and($sheet->period_end->toDateString())->toBe('2026-10-31')
        ->and((float) $sheet->net_payable)->toBe(3000.0)->and($sheet->salary_period_days)->toBe(31);
    $this->get(route('technicians.history', ['technician' => $this->staff->id, 'month' => '2026-10']))->assertOk()
        ->assertViewHas('historyPeriod', fn ($p) => $p['salary_period_start'] === '2026-10-01' && $p['salary_period_end'] === '2026-10-31')
        ->assertViewHas('technician', fn ($staff) => $staff->attendance->count() === 3);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('Calendar Month Salary');
});

it('replaces a draft for the same salary month when the employee cycle changes', function () {
    $payload = ['technician_id' => $this->staff->id, 'month' => '2026-10'];
    $this->post(route('technicians.salaries.store'), $payload)->assertSessionHasNoErrors();
    $this->staff->update(['salary_period_mode' => 'normal_calendar']);
    $this->post(route('technicians.salaries.store'), $payload)->assertSessionHasNoErrors();
    expect(TechnicianSalarySheet::count())->toBe(1)->and(TechnicianContribution::count())->toBe(1)
        ->and(TechnicianSalarySheet::first()->period_start->toDateString())->toBe('2026-10-01');
});

it('requires a new draft when employee or default cycle dates change before finalization', function ($change) {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    if ($change === 'staff') {
        $this->staff->update(['salary_period_mode' => 'normal_calendar']);
    } else {
        SalaryCycleSetting::current()->update(['cycle_end_day' => 25]);
    }
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft');
})->with([
    'staff', 'system',
]);

it('blocks another salary for a finalized month after the employee changes cycle', function () {
    $payload = ['technician_id' => $this->staff->id, 'month' => '2026-10'];
    $this->post(route('technicians.salaries.store'), $payload)->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $snapshot = $sheet->fresh()->calculation_snapshot;
    $this->staff->update(['salary_period_mode' => 'normal_calendar']);
    $this->post(route('technicians.salaries.store'), $payload)->assertSessionHasErrors('month');
    expect(TechnicianSalarySheet::count())->toBe(1)->and($sheet->fresh()->calculation_snapshot)->toBe($snapshot);
});

it('blocks overlapping dates from an adjacent salary month', function () {
    $this->staff->update(['salary_period_mode' => 'normal_calendar']);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-09'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $this->staff->update(['salary_period_mode' => 'system_default']);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasErrors('month');
    expect(TechnicianSalarySheet::count())->toBe(1);
});

it('creates new staff with an individual calendar month without changing the default', function () {
    $data = array_replace($this->profileData, ['name' => 'Calendar Staff', 'phone' => '0701000002', 'salary_period_mode' => 'normal_calendar']);
    $this->post(route('technicians.store'), $data)->assertRedirect()->assertSessionHasNoErrors();
    $created = Technician::where('phone', '0701000002')->firstOrFail();
    expect($created->salary_period_mode)->toBe('normal_calendar')->and($created->salary_start_day)->toBeNull()
        ->and($this->staff->fresh()->salary_period_mode)->toBe('system_default');
});

it('keeps attendance and earned commissions in each staff member own payroll period', function () {
    $calendarStaff = Technician::create(['name' => 'Calendar Colleague', 'phone' => '0701000002', 'active' => true, 'salary_period_mode' => 'normal_calendar', 'salary_type' => 'Daily', 'basic_salary' => 30000, 'daily_salary' => 1000, 'commission_type' => 'Manual only']);
    foreach ([$this->staff, $calendarStaff] as $staff) {
        foreach (['2026-09-30' => 37, '2026-10-31' => 83] as $date => $amount) {
            TechnicianAttendance::create(['technician_id' => $staff->id, 'date' => $date, 'status' => 'Present', 'created_by' => auth()->id()]);
            TechnicianCommission::create(['technician_id' => $staff->id, 'commission_type' => 'Manual', 'amount' => $amount, 'earned_date' => $date, 'status' => 'Pending', 'created_by' => auth()->id()]);
        }
    }
    $calculator = app(SalaryCalculationService::class);
    $default = $calculator->generateSalaryPreview($this->staff->id, '2026-10');
    $calendar = $calculator->generateSalaryPreview($calendarStaff->id, '2026-10');
    expect($default['salary_period_start'])->toBe('2026-09-30')->and($default['commission'])->toBe(37.0)
        ->and($calendar['salary_period_start'])->toBe('2026-10-01')->and($calendar['commission'])->toBe(83.0)
        ->and($default['attendance']['present_days'])->toBe(1)->and($calendar['attendance']['present_days'])->toBe(1);
});
