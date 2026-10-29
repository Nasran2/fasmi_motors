<?php

use App\Models\HolidayDate;
use App\Models\LeaveHolidaySetting;
use App\Models\Role;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceImportService;
use App\Services\HolidayService;
use App\Services\SalaryCalculationService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    SalarySetting::firstOrCreate([])->update(['work_days' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Saturday', 'Sunday'],
        'opening_time' => '08:00', 'closing_time' => '17:00', 'enable_half_day' => false]);
    LeaveHolidaySetting::create(['key' => 'weekly_leave_days', 'value' => json_encode(['Sunday'])]);
    $this->staff = Technician::create(['name' => 'Closure Staff', 'attendance_person_id' => 'CLOSE-1', 'phone' => '0779988771',
        'active' => true, 'salary_type' => 'Monthly', 'basic_salary' => 26000, 'daily_salary' => 1000,
        'salary_period_mode' => 'normal_calendar', 'epf_eligible' => false, 'etf_eligible' => false]);
    $this->special = HolidayDate::create(['holiday_date' => '2026-10-14', 'holiday_name' => 'Shop maintenance',
        'holiday_type' => 'Company', 'is_active' => true, 'is_leave_enabled' => true, 'created_by' => auth()->id()]);
    $this->preview = function (array $rows) {
        $csv = "Person ID,Name,Date,Check-In,Check-out\n";
        foreach ($rows as $row) {
            $csv .= 'CLOSE-1,Closure Staff,'.implode(',', $row)."\n";
        }

        return app(AttendanceImportService::class)->preview(UploadedFile::fake()->createWithContent('closed-days.csv', $csv), auth()->id());
    };
});

test('preview uses weekly work settings and colours special closures separately from personal paid leave', function () {
    $batch = ($this->preview)([['2026-10-13', '', ''], ['2026-10-14', '', ''], ['2026-10-16', '', ''], ['2026-10-17', '08:00', '17:00']]);
    $rows = $batch->preview_data;
    expect($rows[0]['mark_leave'])->toBeTrue()->and($rows[0]['shop_day']['kind'])->toBe('working')
        ->and($rows[1]['mark_leave'])->toBeFalse()->and($rows[1]['shop_day']['kind'])->toBe('special')
        ->and($rows[2]['mark_leave'])->toBeFalse()->and($rows[2]['shop_day']['kind'])->toBe('weekly')
        ->and($rows[3]['mark_leave'])->toBeFalse();
    $response = $this->get(route('technicians.attendance.index', ['batch' => $batch->id, 'month' => '2026-10']))
        ->assertOk()->assertSeeText('Shop maintenance')->assertSeeText('Paid leave')
        ->assertSee('bg-violet-50', false)->assertSee('bg-sky-50', false)->assertSee('bg-amber-50', false);
    $dom = new DOMDocument;
    @$dom->loadHTML($response->getContent());
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//form[@id="attendance-import-form"]//input[@type="checkbox" and @data-import-leave]')->length)->toBe(4)
        ->and($xpath->query('//form[@id="attendance-import-form"]//*[@aria-label="Attendance colour meanings"]')->length)->toBe(1);
    $response->assertSeeText('Not paid leave?')->assertSeeText('Absent / unpaid, normal deduction');
});

test('checked working-day absences import as paid leave and shop closures are not personal leave or deductions', function () {
    $batch = ($this->preview)([['2026-10-13', '', ''], ['2026-10-14', '', ''], ['2026-10-16', '', '']]);
    $this->post(route('technicians.attendance.import.confirm', $batch))->assertRedirect()->assertSessionHasNoErrors();
    $records = TechnicianAttendance::orderBy('date')->get();
    expect($records[0]->status)->toBe('Paid Leave')->and((float) $records[0]->deduction_amount)->toBe(0.0)
        ->and($records[0]->is_leave_day)->toBeFalse()->and($records[0]->leave_type)->toBeNull()
        ->and($records[1]->status)->toBe('Company Holiday')->and($records[1]->is_leave_day)->toBeTrue()
        ->and($records[2]->status)->toBe('Weekly Leave')->and((float) $records->sum('deduction_amount'))->toBe(0.0);
    $summary = app(SalaryCalculationService::class)->getAttendanceSummary($this->staff->id, '2026-10-12', '2026-10-17');
    expect($summary['approved_leave_days'])->toBe(1)->and($summary['absent_days'])->toBe(0)
        ->and($summary['weekly_leave_days'])->toBe(1)->and($summary['custom_holiday_days'])->toBe(1);
});

test('an unchecked working-day leave box preserves absence while closed days cannot be forced into personal leave', function () {
    $batch = ($this->preview)([['2026-10-13', '', ''], ['2026-10-14', '', ''], ['2026-10-16', '', '']]);
    $rows = collect($batch->preview_data)->map(fn ($row) => ['row' => $row['row'], 'date' => $row['date'], 'check_in' => null, 'check_out' => null,
        'technician_id' => $this->staff->id, 'mark_leave' => $row['date'] === '2026-10-13' ? 0 : 1])->all();
    $this->post(route('technicians.attendance.import.confirm', $batch), ['rows' => $rows])->assertRedirect()->assertSessionHasNoErrors();
    $records = TechnicianAttendance::orderBy('date')->get();
    expect($records[0]->status)->toBe('Absent')->and((float) $records[0]->deduction_amount)->toBe(1000.0)
        ->and($records[1]->status)->toBe('Company Holiday')->and($records[2]->status)->toBe('Weekly Leave');
});

test('actual punches on a closed day remain worked attendance even if a leave checkbox is submitted', function () {
    $batch = ($this->preview)([['2026-10-14', '08:00', '17:00']]);
    $row = $batch->preview_data[0];
    $this->post(route('technicians.attendance.import.confirm', $batch), ['rows' => [['row' => $row['row'], 'date' => $row['date'],
        'check_in' => '08:00', 'check_out' => '17:00', 'technician_id' => $this->staff->id, 'mark_leave' => 1]]])->assertRedirect()->assertSessionHasNoErrors();
    $record = TechnicianAttendance::sole();
    expect($record->status)->toBe('Worked on Leave Day')->and($record->worked_on_leave_day)->toBeTrue()
        ->and((int) $record->working_minutes)->toBe(540)->and((float) $record->deduction_amount)->toBe(0.0);
});

test('date edits are evaluated against the new date rather than the original closure', function () {
    $batch = ($this->preview)([['2026-10-13', '', '']]);
    $row = $batch->preview_data[0];
    $this->post(route('technicians.attendance.import.confirm', $batch), ['rows' => [['row' => $row['row'], 'date' => '2026-10-16', 'mark_leave' => 1]]])->assertRedirect()->assertSessionHasNoErrors();
    expect(TechnicianAttendance::sole()->status)->toBe('Weekly Leave')->and(TechnicianAttendance::sole()->date->toDateString())->toBe('2026-10-16');
});

test('monthly special closures preserve the import preview and refresh automatic checkbox choices', function () {
    $batch = ($this->preview)([['2026-10-13', '', '']]);
    $this->post(route('technicians.attendance.closed-days.store'), ['closure_date' => '2026-10-13', 'closure_name' => 'Staff event', 'month' => '2026-10', 'batch' => $batch->id])
        ->assertRedirect(route('technicians.attendance.index', ['month' => '2026-10', 'batch' => $batch->id]));
    expect(HolidayDate::whereDate('holiday_date', '2026-10-13')->sole()->holiday_type)->toBe('Company');
    $this->get(route('technicians.attendance.index', ['batch' => $batch->id, 'month' => '2026-10']))->assertOk()
        ->assertViewHas('previewRows', fn ($rows) => $rows[0]['mark_leave'] === false && $rows[0]['shop_day']['kind'] === 'special');
    $this->post(route('technicians.attendance.closed-days.store'), ['closure_date' => '2026-11-13', 'closure_name' => 'Wrong month', 'month' => '2026-10'])->assertSessionHasErrors('closure_date');
    $this->post(route('technicians.attendance.closed-days.store'), ['closure_date' => '2026-10-13', 'closure_name' => 'Duplicate', 'month' => '2026-10'])->assertSessionHasErrors('closure_date');
});

test('changed calendars reject stale preview confirmations before writing attendance', function () {
    $batch = ($this->preview)([['2026-10-13', '', '']]);
    $version = app(HolidayService::class)->calendarVersion();
    HolidayDate::create(['holiday_date' => '2026-10-13', 'holiday_name' => 'New closure', 'holiday_type' => 'Company', 'is_leave_enabled' => true]);
    $this->post(route('technicians.attendance.import.confirm', $batch), ['calendar_version' => $version])->assertSessionHasErrors('calendar_version');
    expect(TechnicianAttendance::count())->toBe(0)->and($batch->fresh()->status)->toBe('Preview');
});

test('both weekly settings screens control the same attendance calendar including an empty work week', function () {
    expect(app(HolidayService::class)->getWeeklyLeaveDays())->toBe(['Friday']);
    $this->post(route('system-settings.leave-holiday-settings.weekly'), ['days' => ['Sunday']])->assertRedirect()->assertSessionHasNoErrors();
    expect(SalarySetting::first()->work_days)->not->toContain('Sunday')->and(app(HolidayService::class)->getWeeklyLeaveDays())->toBe(['Sunday']);
    $settings = SalarySetting::first()->toArray();
    $payload = array_replace($settings, ['group' => 'salary', 'salary_cycle_mode' => 'normal_calendar', 'work_days' => ['Monday', 'Tuesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']]);
    $this->post(route('system-settings.save'), $payload)->assertRedirect()->assertSessionHasNoErrors();
    expect(app(HolidayService::class)->getWeeklyLeaveDays())->toBe(['Wednesday'])
        ->and(json_decode(LeaveHolidaySetting::where('key', 'weekly_leave_days')->value('value'), true))->toBe(['Wednesday']);
    SalarySetting::first()->update(['work_days' => []]);
    expect(app(HolidayService::class)->getWeeklyLeaveDays())->toHaveCount(7);
});

test('disabled and inactive holiday dates are working days unless the weekday is closed', function () {
    $this->special->update(['is_leave_enabled' => false]);
    $batch = ($this->preview)([['2026-10-14', '', '']]);
    expect($batch->preview_data[0]['mark_leave'])->toBeTrue()->and($batch->preview_data[0]['shop_day']['kind'])->toBe('working');
    $this->special->update(['is_leave_enabled' => true, 'is_active' => false]);
    expect(app(HolidayService::class)->dayInfo('2026-10-14')['closed'])->toBeFalse();
});

test('future empty working days are not automatically recorded as absence or paid leave', function () {
    $batch = ($this->preview)([['2026-10-31', '', '']]);
    expect($batch->preview_data[0]['mark_leave'])->toBeFalse();
    $this->post(route('technicians.attendance.import.confirm', $batch))->assertRedirect()->assertSessionHasNoErrors();
    expect(TechnicianAttendance::count())->toBe(0)->and($batch->fresh()->skipped_rows)->toBe(1);
});

test('explicit future paid leave is permitted while actual future attendance is never overwritten by the checkbox', function () {
    $batch = ($this->preview)([['2026-10-31', '', '']]);
    $row = $batch->preview_data[0];
    $this->post(route('technicians.attendance.import.confirm', $batch), ['rows' => [['row' => $row['row'], 'date' => $row['date'], 'mark_leave' => 1]]])->assertRedirect()->assertSessionHasNoErrors();
    expect(TechnicianAttendance::sole()->status)->toBe('Paid Leave')->and((float) TechnicianAttendance::sole()->deduction_amount)->toBe(0.0);
});

test('closed-day management requires salary-settings permission', function () {
    $role = Role::create(['name' => 'Attendance only', 'permissions' => ['technician.attendance']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('technicians.attendance.index'))->assertOk()->assertDontSeeText('Add special closed day');
    $this->post(route('technicians.attendance.closed-days.store'), ['closure_date' => '2026-10-13', 'closure_name' => 'Unauthorized', 'month' => '2026-10'])->assertForbidden();
    expect(HolidayDate::count())->toBe(1);
});

test('finalized payroll attendance cannot be changed by leave checkbox imports', function () {
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-13', 'Paid Leave', null, null);
    TechnicianAttendance::create($metrics + ['technician_id' => $this->staff->id, 'date' => '2026-10-13', 'created_by' => auth()->id()]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertRedirect()->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::where('technician_id', $this->staff->id)->sole();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertRedirect()->assertSessionHasNoErrors();
    $batch = ($this->preview)([['2026-10-13', '', '']]);
    $row = $batch->preview_data[0];
    $this->post(route('technicians.attendance.import.confirm', $batch), ['rows' => [['row' => $row['row'], 'date' => $row['date'], 'mark_leave' => 0]]])->assertRedirect()->assertSessionHasNoErrors();
    expect(TechnicianAttendance::sole()->status)->toBe('Paid Leave')->and($batch->fresh()->skipped_rows)->toBe(1);
});

test('changing a closed weekday back to a working day refreshes its automatic paid-leave selection', function () {
    $batch = ($this->preview)([['2026-10-16', '', '']]);
    expect($batch->preview_data[0]['mark_leave'])->toBeFalse();
    SalarySetting::first()->update(['work_days' => HolidayService::DAYS]);
    $this->get(route('technicians.attendance.index', ['batch' => $batch->id, 'month' => '2026-10']))->assertOk()
        ->assertViewHas('previewRows', fn ($rows) => $rows[0]['mark_leave'] === true && $rows[0]['shop_day']['kind'] === 'working');
    $this->post(route('technicians.attendance.import.confirm', $batch))->assertRedirect()->assertSessionHasNoErrors();
    expect(TechnicianAttendance::sole()->status)->toBe('Paid Leave')->and((float) TechnicianAttendance::sole()->deduction_amount)->toBe(0.0);
});

test('a preview opened on a previous day must be reviewed again before automatic leave is imported', function () {
    $batch = ($this->preview)([['2026-10-31', '', '']]);
    $version = app(HolidayService::class)->calendarVersion();
    $this->travel(1)->days();
    $this->post(route('technicians.attendance.import.confirm', $batch), ['calendar_version' => $version])->assertSessionHasErrors('calendar_version');
    expect(TechnicianAttendance::count())->toBe(0)->and($batch->fresh()->status)->toBe('Preview');
});
