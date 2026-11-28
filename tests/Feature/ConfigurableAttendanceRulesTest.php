<?php

use App\Models\HolidayDate;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceImportService;
use App\Services\SalaryCalculationService;
use App\Support\StaffWorkSchedule;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->travelTo('2026-11-28 12:00:00');
    $this->actingAs(User::factory()->create());
    $this->settings = SalarySetting::firstOrCreate([])->refresh();
    $this->staff = Technician::create(['name' => 'Policy Staff', 'phone' => '0771009900', 'attendance_person_id' => 'POLICY-1',
        'salary_type' => 'Monthly', 'basic_salary' => 30000, 'daily_salary' => 1000, 'active' => true,
        'salary_period_mode' => 'normal_calendar', 'epf_eligible' => false, 'etf_eligible' => false]);
});

test('default thresholds follow individual shifts and their exact boundaries', function () {
    $calculator = app(AttendanceCalculationService::class);
    expect(StaffWorkSchedule::resolve($this->staff)['late_cutoff'])->toBe('08:15:00');
    foreach ([['08:14', '16:46', false, false], ['08:15', '16:46', true, false], ['08:14', '16:45', false, true]] as [$in, $out, $late, $early]) {
        $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, $in, $out);
        expect($result['late_minutes'] > 0)->toBe($late)->and($result['early_leave_minutes'] > 0)->toBe($early)
            ->and((float) $result['late_deduction_amount'])->toBe($late ? 50.0 : 0.0)->and((float) $result['early_leave_deduction_amount'])->toBe($early ? 50.0 : 0.0);
    }
    $this->staff->update(['work_schedule_mode' => 'custom', 'work_start_time' => '09:00', 'work_end_time' => '18:00']);
    $schedule = StaffWorkSchedule::resolve($this->staff);
    expect($schedule['late_cutoff'])->toBe('09:15:00')->and($schedule['early_cutoff'])->toBe('17:45:00');
    $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '09:14', '17:46');
    expect($result['late_minutes'])->toBe(0)->and($result['early_leave_minutes'])->toBe(0);
    $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '09:15', '17:45');
    expect($result['late_minutes'])->toBeGreaterThan(0)->and($result['early_leave_minutes'])->toBeGreaterThan(0);
});

test('each started quarter hour pays fifty rupees only for on-time staff', function ($out, $minutes, $amount) {
    $calculator = app(AttendanceCalculationService::class);
    $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '08:00', $out);
    expect($result['ot_minutes'])->toBe($minutes)->and($result['ot_amount'])->toBe($amount);
    $late = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '08:01', $out);
    expect($late['is_ot_eligible'])->toBeFalse()->and((float) $late['ot_amount'])->toBe(0.0);
})->with([['17:00', 15, 50.0], ['17:14', 15, 50.0], ['17:15', 30, 100.0], ['17:29', 30, 100.0], ['17:30', 45, 150.0], ['18:00', 75, 250.0]]);

test('OT respects custom staff shifts missing checkouts and configurable intervals', function () {
    $this->staff->update(['work_schedule_mode' => 'custom', 'work_start_time' => '09:00', 'work_end_time' => '18:00', 'ot_rate' => 999]);
    $calculator = app(AttendanceCalculationService::class);
    $this->settings->update(['ot_slab_minutes' => 10, 'ot_amount_per_slab' => 75]);
    $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '09:00', '18:10');
    expect($result['ot_amount'])->toBe(150.0)->and($result['ot_minutes'])->toBe(20);
    $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '09:00', null);
    expect($result['is_auto_checkout'])->toBeTrue()->and((float) $result['ot_amount'])->toBe(0.0);
    $this->settings->update(['ot_round_method' => 'Floor slab']);
    expect($calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, '09:00', '18:09')['ot_amount'])->toBe(0.0);
});

test('imported late arrivals and early departures replace completed groups of occurrence fees with separate half-day entries', function () {
    $csv = "Person ID,Name,Date,Check-In,Check-out\n";
    foreach (['05', '06', '07', '08', '10', '11'] as $day) {
        $csv .= "POLICY-1,Policy Staff,2026-10-{$day},09:01,17:00\n";
    }
    foreach (['12', '13', '15'] as $day) {
        $csv .= "POLICY-1,Policy Staff,2026-10-{$day},08:00,15:59\n";
    }
    $batch = app(AttendanceImportService::class)->preview(UploadedFile::fake()->createWithContent('policy.csv', $csv), auth()->id());
    $this->post(route('technicians.attendance.import.confirm', $batch))->assertSessionHasNoErrors();
    expect(TechnicianAttendance::count())->toBe(9)->and((float) TechnicianAttendance::sum('deduction_amount'))->toBe(450.0);
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['late_deduction'])->toBe(0.0)->and($preview['early_leave_deduction'])->toBe(0.0)
        ->and($preview['half_day_deduction'])->toBe(1500.0)->and($preview['half_days'])->toBe(3)
        ->and($preview['attendance']['timing_half_day_penalties'])->toHaveCount(3)
        ->and(array_column($preview['attendance']['timing_half_day_penalties'], 'days'))->toBe([0.5, 0.5, 0.5])
        ->and($preview['net_payable'])->toBe(28500.0);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    foreach (['technicians.salaries.show', 'technicians.salaries.slip'] as $route) {
        $this->get(route($route, $sheet))->assertOk()->assertSeeText('Late arrival #3')->assertSeeText('Late arrival #6')->assertSeeText('Early departure #3');
    }
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
});

test('counters reset by salary period and ignore holidays and existing half days', function () {
    $calculator = app(AttendanceCalculationService::class);
    foreach (['2026-09-30', '2026-10-01', '2026-10-05', '2026-10-06', '2026-10-09'] as $date) {
        $metrics = $calculator->calculateDailyAttendance($this->staff, $date, null, '09:01', '17:00');
        TechnicianAttendance::create($metrics + ['technician_id' => $this->staff->id, 'date' => $date, 'created_by' => auth()->id()]);
    }
    TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => '2026-10-07', 'status' => 'Half Day', 'in_time' => '13:00', 'out_time' => '17:00', 'created_by' => auth()->id()]);
    $summary = app(SalaryCalculationService::class)->getAttendanceSummary($this->staff->id, '2026-10-01', '2026-10-31');
    expect($summary['late_days'])->toBe(3)->and($summary['timing_half_day_penalties'])->toHaveCount(1)
        ->and($summary['half_day_deduction'])->toBe(1000.0);
    $this->settings->update(['late_occurrences_per_half_day' => 2, 'enable_early_leave_deduction' => false]);
    $summary = app(SalaryCalculationService::class)->getAttendanceSummary($this->staff->id, '2026-10-01', '2026-10-31');
    expect($summary['timing_half_day_penalties'][0]['date'])->toBe('2026-10-05');
});

test('Friday and company holiday work each pay basic salary divided by twenty five', function () {
    HolidayDate::create(['holiday_date' => '2026-10-14', 'holiday_name' => 'Company holiday', 'holiday_type' => 'Company', 'is_active' => true, 'is_leave_enabled' => true]);
    foreach (['2026-10-09', '2026-10-14'] as $date) {
        $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, $date, null, '08:00', '16:00');
        TechnicianAttendance::create($metrics + ['technician_id' => $this->staff->id, 'date' => $date, 'created_by' => auth()->id()]);
    }
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['worked_on_leave_pay'])->toBe(2400.0)->and($preview['calculation_snapshot']['earnings']['worked_on_leave_rate'])->toBe(1200.0);
    $this->settings->update(['leave_work_salary_divisor' => 30]);
    expect(app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10')['worked_on_leave_pay'])->toBe(2000.0);
});

test('salary settings save customizable thresholds counts OT and holiday divisor and reject invalid values', function () {
    $data = $this->settings->toArray();
    unset($data['id'], $data['created_at'], $data['updated_at']);
    $data = array_replace($data, ['group' => 'salary', 'salary_cycle_mode' => 'normal_calendar', 'slip_paper_size' => 'A4',
        'work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday', 'Sunday'],
        'late_grace_minutes' => 45, 'early_leave_grace_minutes' => 30, 'late_occurrences_per_half_day' => 4,
        'early_occurrences_per_half_day' => 5, 'ot_slab_minutes' => 20, 'ot_amount_per_slab' => 80, 'leave_work_salary_divisor' => 28]);
    $this->post(route('system-settings.save'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($this->settings->fresh()->late_occurrences_per_half_day)->toBe(4)
        ->and(StaffWorkSchedule::resolve($this->staff)['late_cutoff'])->toBe('08:45:00');
    $this->get(route('system-settings.salary-settings'))->assertOk()->assertSeeText('Friday / Company Holiday Work')->assertSeeText('Late arrivals per half-day deduction');
    $this->post(route('system-settings.save'), array_replace($data, ['late_occurrences_per_half_day' => 0, 'ot_slab_minutes' => 0, 'leave_work_salary_divisor' => 0]))
        ->assertSessionHasErrors(['late_occurrences_per_half_day', 'ot_slab_minutes', 'leave_work_salary_divisor']);
    expect($this->settings->fresh()->late_occurrences_per_half_day)->toBe(4);
});

test('hourly regular pay uses actual OT minutes while interval OT is paid separately', function () {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100]);
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-05',
        'status' => 'Present', 'in_time' => '08:00', 'out_time' => '17:14'])->assertSessionHasNoErrors();
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['attendance_salary_amount'])->toBe(900.0)->and($preview['ot_amount'])->toBe(50.0)
        ->and($preview['net_payable'])->toBe(950.0)->and($preview['half_day_deduction'])->toBe(0);
});

test('rule changes require refreshing a draft and do not rewrite finalized snapshots', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $snapshot = $sheet->calculation_snapshot;
    $this->settings->update(['late_occurrences_per_half_day' => 4]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->calculation_snapshot)->toBe($snapshot)->and($sheet->fresh()->status)->toBe('Draft');
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Apply new late count'])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $snapshot = $sheet->fresh()->calculation_snapshot;
    $this->settings->update(['ot_amount_per_slab' => 75]);
    expect($sheet->fresh()->calculation_snapshot)->toBe($snapshot)->and($sheet->fresh()->status)->toBe('Finalized');
});

test('alternative hourly OT modes honor their selected pay basis', function (string $mode, float $expected) {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100, 'ot_rate' => 600]);
    $this->settings->update(['ot_calculation_type' => $mode, 'ot_round_method' => 'Floor slab']);
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-05',
        'status' => 'Present', 'in_time' => '08:00', 'out_time' => '17:30'])->assertSessionHasNoErrors();
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['ot_amount'])->toBe($expected)->and($preview['net_payable'])->toBe(900.0 + $expected);
    $this->get(route('technicians.create'))->assertOk()->assertDontSee('name="late_deduction_rate"', false);
    $this->get(route('technicians.edit', $this->staff))->assertOk()->assertDontSee('name="late_deduction_rate"', false);
})->with([['Fixed amount per hour', 300.0], ['Salary-based hourly rate', 50.0]]);

test('only incomplete groups retain fees and each completed group becomes a separate half day', function (string $kind, int $count) {
    $dates = ['01', '03', '04', '05', '06', '07', '08', '10'];
    foreach (array_slice($dates, 0, $count) as $day) {
        $date = '2026-10-'.$day;
        $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, $date, null,
            $kind === 'late' ? '08:15' : '08:00', $kind === 'early' ? '16:45' : '17:00');
        TechnicianAttendance::create($metrics + ['technician_id' => $this->staff->id, 'date' => $date, 'created_by' => auth()->id()]);
    }
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    $halfDays = intdiv($count, 3);
    $fee = ($count % 3) * 50.0;
    expect($preview[$kind === 'late' ? 'late_deduction' : 'early_leave_deduction'])->toBe($fee)
        ->and($preview['half_day_deduction'])->toBe($halfDays * 500.0)
        ->and($preview['half_days'])->toBe($halfDays)
        ->and($preview['attendance']['timing_fee_items'])->toHaveCount($count % 3);
    foreach ($preview['attendance']['timing_half_day_penalties'] as $penalty) {
        expect($penalty['days'])->toBe(0.5)->and($penalty['waived_fees'])->toBe(150.0)->and($penalty['dates'])->toHaveCount(3);
    }
})->with(['late', 'early'])->with([1, 2, 3, 4, 5, 6, 7, 8]);

test('staff overrides save independently from shifts and inherit company rules when cleared', function () {
    $data = ['name' => $this->staff->name, 'phone' => $this->staff->phone, 'active' => 1, 'basic_salary' => 30000,
        'salary_type' => 'Monthly', 'daily_salary' => 1000, 'commission_percentage' => 0, 'commission_type' => 'Manual only',
        'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3,
        'work_schedule_mode' => 'custom', 'work_start_time' => '09:00', 'work_end_time' => '18:00',
        'late_cutoff_time' => '09:30', 'early_leave_cutoff_time' => '17:30', 'late_fee' => 75, 'early_leave_fee' => 25];
    $this->put(route('technicians.update', $this->staff), $data)->assertSessionHasNoErrors();
    $this->staff->refresh();
    $schedule = StaffWorkSchedule::resolve($this->staff);
    expect($schedule['late_cutoff'])->toBe('09:30:00')->and($schedule['early_cutoff'])->toBe('17:30:00')
        ->and($schedule['late_fee'])->toBe(75.0)->and($schedule['early_fee'])->toBe(25.0);
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-05', null, '09:30', '17:30');
    expect($metrics['late_deduction_amount'])->toBe(75.0)->and($metrics['early_leave_deduction_amount'])->toBe(25.0);
    $this->get(route('technicians.edit', $this->staff))->assertOk()->assertSeeText('Individual Late / Early Rules');
    $this->put(route('technicians.update', $this->staff), array_replace($data, ['late_cutoff_time' => null, 'early_leave_cutoff_time' => null, 'late_fee' => 0, 'early_leave_fee' => null]))->assertSessionHasNoErrors();
    $schedule = StaffWorkSchedule::resolve($this->staff->fresh());
    expect($schedule['late_cutoff'])->toBe('09:15:00')->and($schedule['early_cutoff'])->toBe('17:45:00')
        ->and($schedule['late_fee'])->toBe(0.0)->and($schedule['early_fee'])->toBe(50.0);
    $this->putJson(route('technicians.update', $this->staff), array_replace($data, ['late_cutoff_time' => '08:30']))
        ->assertUnprocessable()->assertJsonValidationErrors('late_cutoff_time');
    $this->putJson(route('technicians.update', $this->staff), array_replace($data, ['late_fee' => -1]))
        ->assertUnprocessable()->assertJsonValidationErrors('late_fee');
});

test('late and early counters stay separate and use the staff fees for remainders', function () {
    $this->staff->update(['late_fee' => 75, 'early_leave_fee' => 25]);
    foreach (['05', '06'] as $day) {
        $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-'.$day, null, '08:15', '16:45');
        TechnicianAttendance::create($metrics + ['technician_id' => $this->staff->id, 'date' => '2026-10-'.$day, 'created_by' => auth()->id()]);
    }
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['late_deduction'])->toBe(150.0)->and($preview['early_leave_deduction'])->toBe(50.0)
        ->and($preview['half_day_deduction'])->toBe(0.0);
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-07', null, '08:15', '17:00');
    TechnicianAttendance::create($metrics + ['technician_id' => $this->staff->id, 'date' => '2026-10-07', 'created_by' => auth()->id()]);
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['late_deduction'])->toBe(0.0)->and($preview['early_leave_deduction'])->toBe(50.0)
        ->and($preview['half_day_deduction'])->toBe(500.0)
        ->and($preview['attendance']['timing_half_day_penalties'][0]['waived_fees'])->toBe(225.0);
});
