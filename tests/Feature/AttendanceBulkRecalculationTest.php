<?php

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    SalarySetting::firstOrCreate([])->update(['enable_ot' => true, 'opening_time' => '08:00 AM', 'closing_time' => '05:00 PM', 'ot_grace_minutes_after_closing' => 15, 'ot_calculation_type' => 'Fixed amount per hour', 'ot_round_method' => 'Nearest slab', 'ot_slab_minutes' => 30, 'enable_half_day' => false]);
    $this->staff = Technician::create(['name' => 'Bulk OT Staff', 'phone' => '0700999000', 'active' => true, 'salary_type' => 'Monthly', 'basic_salary' => 30000, 'ot_rate' => 150, 'salary_period_mode' => 'normal_calendar', 'commission_type' => 'Manual only']);
    $this->makeRow = fn ($date, $extra = []) => TechnicianAttendance::create(array_replace(['technician_id' => $this->staff->id, 'date' => $date, 'in_time' => '08:00:00', 'out_time' => '18:00:00', 'status' => 'Present', 'created_by' => auth()->id()], $extra));
    $this->payload = ['scope' => 'filtered', 'from' => '2026-10-01', 'to' => '2026-10-31', 'reason' => 'Apply inclusive 8 AM overtime'];
});

afterEach(fn () => Carbon::setTestNow());

it('recalculates all pages and chunks without skipping records whose status changes', function () {
    for ($day = 0; $day < 205; $day++) {
        ($this->makeRow)(Carbon::parse('2026-01-01')->addDays($day)->toDateString());
    }
    $this->get(route('technicians.attendance.index', ['month' => '2026-01']))->assertOk()->assertSeeText('Recalculate All Attendance')->assertSeeText('All recorded dates');
    $this->post(route('technicians.attendance.recalculate-all'), array_replace($this->payload, ['scope' => 'all_dates', 'status' => 'Present']))
        ->assertSessionHasNoErrors()->assertSessionHas('attendance_recalculation', ['recalculated' => 205, 'manual' => 0, 'locked' => 0, 'invalid' => 0]);
    expect(TechnicianAttendance::where('ot_amount', 150)->count())->toBe(205)->and(AuditLog::where('event', 'Attendance Bulk Recalculated')->count())->toBe(205)
        ->and(AccountTransaction::count())->toBe(0);
});

it('respects applied dates staff and status filters', function () {
    $selected = ($this->makeRow)('2026-10-05');
    $outside = ($this->makeRow)('2026-09-30');
    $absent = ($this->makeRow)('2026-10-06', ['status' => 'Absent', 'in_time' => null, 'out_time' => null]);
    $other = Technician::create(['name' => 'Other Staff', 'phone' => '0700999001', 'active' => true, 'salary_type' => 'Monthly', 'basic_salary' => 30000, 'ot_rate' => 150]);
    $colleague = ($this->makeRow)('2026-10-05', ['technician_id' => $other->id]);
    $this->post(route('technicians.attendance.recalculate-all'), $this->payload + ['technician' => $this->staff->id, 'status' => 'Present'])
        ->assertSessionHasNoErrors()->assertSessionHas('attendance_recalculation.recalculated', 1);
    expect((float) $selected->fresh()->ot_amount)->toBe(150.0)->and((float) $outside->fresh()->ot_amount)->toBe(0.0)
        ->and((float) $colleague->fresh()->ot_amount)->toBe(0.0)->and($absent->fresh()->status)->toBe('Absent');
    $audit = AuditLog::where('event', 'Attendance Bulk Recalculated')->firstOrFail();
    expect($audit->new_values['edit_reason'])->toBe($this->payload['reason']);
});

it('skips every finalized salary status while processing unlocked records', function () {
    foreach (['Finalized', 'Partially Paid', 'Paid'] as $index => $status) {
        $date = '2026-10-0'.($index + 1);
        ($this->makeRow)($date);
        TechnicianSalarySheet::create(['number' => 'PAY-LOCK-'.$index, 'technician_id' => $this->staff->id, 'period_start' => $date, 'period_end' => $date, 'status' => $status, 'basic_salary' => 30000, 'net_payable' => 30000, 'created_by' => auth()->id()]);
    }
    $open = ($this->makeRow)('2026-10-05');
    $this->post(route('technicians.attendance.recalculate-all'), $this->payload)->assertSessionHasNoErrors()
        ->assertSessionHas('attendance_recalculation.locked', 3)->assertSessionHas('attendance_recalculation.recalculated', 1);
    expect((float) $open->fresh()->ot_amount)->toBe(150.0)->and(TechnicianAttendance::where('ot_amount', 0)->count())->toBe(3);
});

it('retains manual amounts while refreshing automatic metrics', function () {
    $row = ($this->makeRow)('2026-10-05', ['is_manual_edit' => true, 'ot_amount' => 275, 'deduction_amount' => 25]);
    $this->post(route('technicians.attendance.recalculate-all'), $this->payload)->assertSessionHasNoErrors()->assertSessionHas('attendance_recalculation.manual', 1);
    expect((float) $row->fresh()->ot_amount)->toBe(275.0)->and((float) $row->fresh()->deduction_amount)->toBe(25.0)
        ->and($row->fresh()->ot_minutes)->toBe(60)->and($row->fresh()->is_manual_edit)->toBeTrue();
});

it('reports invalid times and continues to recalculate other records', function () {
    $bad = ($this->makeRow)('2026-10-05', ['in_time' => '18:00:00', 'out_time' => '08:00:00']);
    $good = ($this->makeRow)('2026-10-06');
    $this->post(route('technicians.attendance.recalculate-all'), $this->payload)->assertSessionHasNoErrors()
        ->assertSessionHas('attendance_recalculation.invalid', 1)->assertSessionHas('attendance_recalculation.recalculated', 1);
    expect((float) $good->fresh()->ot_amount)->toBe(150.0)->and((float) $bad->fresh()->ot_amount)->toBe(0.0);
});

it('requires a reason and valid scope or date range before making changes', function ($data, $error) {
    ($this->makeRow)('2026-10-05');
    $this->postJson(route('technicians.attendance.recalculate-all'), array_replace($this->payload, $data))->assertUnprocessable()->assertJsonValidationErrors($error);
    expect(TechnicianAttendance::where('ot_amount', 150)->count())->toBe(0);
})->with([
    [['reason' => ''], 'reason'], [['scope' => 'unknown'], 'scope'], [['from' => '2026-11-01'], 'to'], [['from' => null], 'from'],
]);

it('does not rewrite a saved draft and explains how to refresh salary', function () {
    ($this->makeRow)('2026-10-05');
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $before = $sheet->getAttributes();
    $this->post(route('technicians.attendance.recalculate-all'), $this->payload)->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'Refresh Attendance & OT'));
    expect($sheet->fresh()->getAttributes())->toBe($before);
});

it('requires attendance permission', function () {
    ($this->makeRow)('2026-10-05');
    $role = Role::create(['name' => 'No attendance access', 'permissions' => ['technician.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->post(route('technicians.attendance.recalculate-all'), $this->payload)->assertForbidden();
    expect(TechnicianAttendance::where('ot_amount', 150)->count())->toBe(0);
});
