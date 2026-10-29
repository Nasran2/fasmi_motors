<?php

use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use App\Services\SalaryCalculationService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    SalarySetting::firstOrCreate([])->update(['enable_ot' => true, 'opening_time' => '08:00', 'closing_time' => '17:00',
        'ot_grace_minutes_after_closing' => 15, 'enable_half_day' => false, 'lunch_break_minutes' => 0]);
    $this->staff = Technician::create(['name' => 'Rounded OT Staff', 'phone' => '0700345000', 'salary_type' => 'Daily', 'daily_salary' => 3000,
        'basic_salary' => 75000, 'ot_rate' => 150, 'salary_period_mode' => 'normal_calendar', 'active' => true, 'commission_type' => 'Manual only', 'epf_eligible' => false, 'etf_eligible' => false]);
});

afterEach(fn () => Carbon::setTestNow());

it('rounds payable OT to the nearest half hour while preserving actual duration and checkout', function ($checkout, $actual, $payable, $amount) {
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-05', 'status' => 'Present', 'in_time' => '08:00', 'out_time' => $checkout])->assertSessionHasNoErrors();
    $row = TechnicianAttendance::firstOrFail();
    expect($row->actual_ot_minutes)->toBe($actual)->and($row->ot_minutes)->toBe($payable)->and((float) $row->ot_amount)->toBe($amount)
        ->and((float) $row->ot_hours)->toBe((float) ($payable / 60))->and(substr($row->out_time, 0, 5))->toBe($checkout);
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['total_ot_hours'])->toBe(round($payable / 60, 2))->and($preview['ot_amount'])->toBe($amount)->and($preview['net_payable'])->toBe(3000.0 + $amount);
})->with([
    ['17:16', 16, 30, 75.0], ['17:29', 29, 30, 75.0], ['17:30', 30, 30, 75.0], ['17:44', 44, 30, 75.0],
    ['17:45', 45, 60, 150.0], ['17:59', 59, 60, 150.0], ['18:00', 60, 60, 150.0], ['18:14', 74, 60, 150.0], ['18:15', 75, 90, 225.0],
]);

it('rounds half-hour ties up while keeping the original grace and eligibility conditions', function () {
    $calculator = app(AttendanceCalculationService::class);
    expect($calculator->payableOTMinutes(14))->toBe(0)->and($calculator->payableOTMinutes(15))->toBe(30)
        ->and($calculator->payableOTMinutes(44))->toBe(30)->and($calculator->payableOTMinutes(45))->toBe(60);
    foreach ([['08:00', '17:15'], ['08:01', '18:00'], ['08:00', null]] as [$in, $out]) {
        $result = $calculator->calculateDailyAttendance($this->staff, '2026-10-05', null, $in, $out);
        expect($result['ot_minutes'])->toBe(0)->and($result['ot_amount'])->toBe(0);
    }
});

it('does not remove rounded-up minutes from hourly regular pay or add rounded-down minutes twice', function ($out, $expectedOT, $net) {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100]);
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-05', 'status' => 'Present', 'in_time' => '08:00', 'out_time' => $out])->assertSessionHasNoErrors();
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['attendance_salary_amount'])->toBe(900.0)->and($preview['ot_amount'])->toBe($expectedOT)->and($preview['net_payable'])->toBe($net);
})->with([['17:16', 75.0, 975.0], ['17:44', 75.0, 975.0], ['17:45', 150.0, 1050.0]]);

it('updates legacy rows through bulk recalculation and keeps saved drafts until explicitly refreshed', function () {
    $row = TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => '2026-10-05', 'status' => 'Present', 'in_time' => '08:00:00', 'out_time' => '17:16:00',
        'ot_minutes' => 16, 'ot_hours' => 0.27, 'ot_amount' => 40, 'working_minutes' => 556, 'working_hours' => 9.27, 'created_by' => auth()->id()]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    expect((float) $sheet->ot_amount)->toBe(40.0)->and($row->actual_ot_minutes)->toBeNull();
    $this->post(route('technicians.attendance.recalculate-all'), ['scope' => 'all_dates', 'reason' => 'Round overtime to nearest half hour'])->assertSessionHasNoErrors();
    expect($row->fresh()->actual_ot_minutes)->toBe(16)->and($row->fresh()->ot_minutes)->toBe(30)->and((float) $row->fresh()->ot_amount)->toBe(75.0)
        ->and((float) $sheet->fresh()->ot_amount)->toBe(40.0);
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Use rounded overtime in salary'])->assertSessionHasNoErrors();
    expect((float) $sheet->fresh()->ot_amount)->toBe(75.0);
});
