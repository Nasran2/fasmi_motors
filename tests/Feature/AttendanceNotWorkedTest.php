<?php

use App\Models\AuditLog;
use App\Models\HolidayDate;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceRecalculationService;
use App\Services\SalaryCalculationService;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    SalarySetting::firstOrCreate([])->update(['work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday', 'Sunday'],
        'opening_time' => '08:00', 'closing_time' => '17:00', 'enable_half_day' => false, 'enable_ot' => true]);
    $this->staff = Technician::create(['name' => 'Not Worked Staff', 'phone' => '0774321999', 'salary_type' => 'Daily', 'daily_salary' => 1500,
        'salary_period_mode' => 'normal_calendar', 'basic_salary' => 39000, 'ot_rate' => 150, 'epf_eligible' => false, 'etf_eligible' => false]);
    HolidayDate::create(['holiday_date' => '2026-10-14', 'holiday_name' => 'Maintenance closure', 'holiday_type' => 'Company',
        'is_active' => true, 'is_leave_enabled' => true, 'created_by' => auth()->id()]);
    $this->record = function ($date = '2026-10-02') {
        return TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => $date, 'status' => 'Worked on Leave Day',
            'in_time' => '08:00:00', 'out_time' => '18:00:00', 'working_minutes' => 600, 'working_hours' => 10,
            'ot_minutes' => 60, 'actual_ot_minutes' => 60, 'ot_amount' => 150, 'worked_on_leave_day' => true,
            'is_leave_day' => true, 'leave_type' => 'Weekly Leave', 'is_auto_checkout' => true, 'deduction_amount' => 50,
            'is_manual_edit' => true, 'note' => "Imported worker's accidental punches", 'created_by' => auth()->id()]);
    };
    $this->correction = fn ($date = '2026-10-02') => ['technician_id' => $this->staff->id, 'date' => $date,
        'status' => 'Not Worked', 'edit_reason' => 'Accidental imported punches; shop was closed',
        'in_time' => '08:00', 'out_time' => '18:00', 'is_manual_edit' => 1, 'ot_amount' => 900, 'deduction_amount' => 500,
        'note' => "Imported worker's accidental punches"];
});

it('clears accidental work on weekly and special closure dates including old manual overrides', function ($date, $leaveType) {
    $row = ($this->record)($date);
    $before = app(SalaryCalculationService::class)->getAttendanceSummary($this->staff->id, '2026-10-01', '2026-10-31');
    expect($before['worked_on_leave_days'])->toBe(1);
    $this->post(route('technicians.attendance.store'), ($this->correction)($date))->assertSessionHasNoErrors();
    $row->refresh();
    expect($row->status)->toBe('Not Worked')->and($row->in_time)->toBeNull()->and($row->out_time)->toBeNull()
        ->and((float) $row->working_hours)->toBe(0.0)->and((int) $row->working_minutes)->toBe(0)
        ->and((int) $row->ot_minutes)->toBe(0)->and((int) $row->actual_ot_minutes)->toBe(0)
        ->and((float) $row->ot_amount)->toBe(0.0)->and((float) $row->deduction_amount)->toBe(0.0)
        ->and($row->is_manual_edit)->toBeFalse()->and($row->is_auto_checkout)->toBeFalse()
        ->and($row->worked_on_leave_day)->toBeFalse()->and($row->is_leave_day)->toBeTrue()->and($row->leave_type)->toBe($leaveType);
    $summary = app(SalaryCalculationService::class)->getAttendanceSummary($this->staff->id, '2026-10-01', '2026-10-31');
    expect($summary['worked_on_leave_days'])->toBe(0)->and($summary['worked_days'])->toBe(0.0)
        ->and($summary['absent_days'])->toBe(0)->and($summary['approved_leave_days'])->toBe(0)
        ->and($summary['total_worked_hours'])->toBe(0.0)->and($summary['total_ot_amount'])->toBe(0.0);
    $audit = AuditLog::where('event', 'Attendance Edited')->latest('id')->firstOrFail();
    expect($audit->old_values['status'])->toBe('Worked on Leave Day')->and($audit->new_values['status'])->toBe('Not Worked')
        ->and($audit->new_values['in_time'])->toBeNull()->and($audit->new_values['edit_reason'])->toContain('Accidental');
})->with([['2026-10-02', 'Weekly Leave'], ['2026-10-14', 'Company Holiday']]);

it('previews no work and ignores submitted accidental punches and money overrides', function () {
    $this->getJson(route('technicians.attendance.preview', ($this->correction)()))->assertOk()
        ->assertJsonPath('status', 'Not Worked')->assertJsonPath('working_minutes', 0)->assertJsonPath('ot_amount', 0)
        ->assertJsonPath('worked_on_leave_day', false)->assertJsonPath('in_time', null);
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-02', 'Not Worked', '18:00', '08:00',
        ['ot_amount' => 500, 'deduction_amount' => 1500, 'auto_filled_checkout' => true]);
    expect($metrics['status'])->toBe('Not Worked')->and($metrics['ot_amount'])->toBe(0)->and($metrics['deduction_amount'])->toBe(0);
});

it('retains not-worked corrections through single bulk and draft salary recalculation', function () {
    $row = ($this->record)();
    $this->post(route('technicians.attendance.store'), ($this->correction)())->assertSessionHasNoErrors();
    $this->post(route('technicians.attendance.recalculate', $row))->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('Not Worked')->and($row->fresh()->worked_on_leave_day)->toBeFalse();
    app(AttendanceRecalculationService::class)->recalculate($row->id, 'Recheck imported month');
    expect($row->fresh()->status)->toBe('Not Worked')->and((int) $row->fresh()->working_minutes)->toBe(0);
    // A second real workday keeps this daily paysheet positive and makes the pay change visible.
    TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => '2026-10-03', 'status' => 'Present',
        'in_time' => '08:00:00', 'out_time' => '17:00:00', 'working_minutes' => 540, 'working_hours' => 9, 'created_by' => auth()->id()]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::sole();
    expect((float) $sheet->attendance_salary_amount)->toBe(1500.0)->and((int) $sheet->worked_on_leave_days)->toBe(0);
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Check no accidental closure work'])->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('Not Worked')->and((float) $sheet->fresh()->attendance_salary_amount)->toBe(1500.0)
        ->and((int) $sheet->fresh()->worked_on_leave_days)->toBe(0);
});

it('keeps genuine holiday work and restores work when actual punches are supplied later', function () {
    $row = ($this->record)();
    $this->post(route('technicians.attendance.recalculate', $row))->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('Worked on Leave Day')->and($row->fresh()->worked_on_leave_day)->toBeTrue();
    $this->post(route('technicians.attendance.store'), ($this->correction)())->assertSessionHasNoErrors();
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-02', 'status' => 'Present',
        'in_time' => '08:00', 'out_time' => '18:00', 'edit_reason' => 'Confirmed staff really worked this holiday'])->assertSessionHasNoErrors();
    expect($row->fresh()->status)->toBe('Worked on Leave Day')->and($row->fresh()->worked_on_leave_day)->toBeTrue()
        ->and((int) $row->fresh()->working_minutes)->toBe(600)->and((float) $row->fresh()->ot_amount)->toBe(150.0);
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-02', 'status' => 'Worked on Leave Day',
        'in_time' => '08:00', 'out_time' => '17:00', 'edit_reason' => 'Keep actual holiday attendance'])->assertSessionHasNoErrors();
    expect($row->fresh()->worked_on_leave_day)->toBeTrue();
});

it('requires a reason for existing corrections including bulk requests', function () {
    $row = ($this->record)();
    $payload = ($this->correction)();
    unset($payload['edit_reason']);
    $this->post(route('technicians.attendance.store'), $payload)->assertSessionHasErrors('edit_reason');
    $this->post(route('technicians.attendance.store'), $payload + ['technician_ids' => [$this->staff->id]])->assertSessionHasErrors('edit_reason');
    expect($row->fresh()->status)->toBe('Worked on Leave Day');
});

it('does not allow the closed-day correction to waive absence on a normal working date', function () {
    $this->post(route('technicians.attendance.store'), ($this->correction)('2026-10-03'))->assertSessionHasErrors('status');
    expect(TechnicianAttendance::count())->toBe(0);
});

it('keeps finalized and paid attendance locked', function ($status) {
    $row = ($this->record)();
    TechnicianSalarySheet::create(['number' => 'NOT-WORKED-LOCKED', 'technician_id' => $this->staff->id,
        'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'basic_salary' => 39000, 'net_payable' => 39000, 'status' => $status, 'created_by' => auth()->id()]);
    $before = $row->fresh()->toArray();
    $this->post(route('technicians.attendance.store'), ($this->correction)())->assertSessionHasErrors('date');
    expect($row->fresh()->toArray())->toBe($before);
})->with(['Finalized', 'Partially Paid', 'Paid']);

it('shows the correction action and preserves quoted notes safely in the edit controls', function () {
    ($this->record)();
    $this->get(route('technicians.attendance.index', ['month' => '2026-10']))->assertOk()->assertSeeText('Mark not worked')
        ->assertSee('value="Not Worked"', false)->assertSee('value="Worked on Leave Day"', false)->assertSee('data-attendance-edit=', false);
});
