<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\OtSlabRule;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceImportService;
use App\Services\SalaryCalculationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->staff = Technician::create(['name' => 'Audit Staff', 'phone' => '0778899001', 'salary_type' => 'Monthly', 'basic_salary' => 26000, 'active' => true, 'epf_eligible' => false, 'etf_eligible' => false, 'salary_period_mode' => 'normal_calendar']);
    $this->attendanceInput = ['technician_id' => $this->staff->id, 'date' => '2026-10-06', 'status' => 'Present', 'in_time' => '08:00', 'out_time' => '17:00'];
    $this->makeSheet = function () {
        $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
        $sheet = TechnicianSalarySheet::where('technician_id', $this->staff->id)->firstOrFail();
        $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();

        return $sheet->fresh();
    };
});

it('rejects invalid bulk staff IDs before any attendance is saved', function () {
    $data = $this->attendanceInput;
    unset($data['technician_id']);
    $this->post(route('technicians.attendance.store'), $data + ['technician_ids' => [$this->staff->id, 999999999]])->assertSessionHasErrors('technician_ids.1');
    expect(TechnicianAttendance::count())->toBe(0);
    $this->post(route('technicians.attendance.store'), $data + ['technician_ids' => [$this->staff->id, $this->staff->id]])->assertSessionHasErrors('technician_ids.0');
    expect(TechnicianAttendance::count())->toBe(0);
});

it('recalculates saved attendance through its own action without invalid time or missing-reason errors', function () {
    $this->post(route('technicians.attendance.store'), $this->attendanceInput + ['is_manual_edit' => 1, 'deduction_amount' => 125])->assertSessionHasNoErrors();
    $record = TechnicianAttendance::firstOrFail();
    $this->post(route('technicians.attendance.recalculate', $record))->assertSessionHasNoErrors();
    expect((float) $record->fresh()->deduction_amount)->toBe(0.0)->and($record->fresh()->is_manual_edit)->toBeFalse();
    ($this->makeSheet)();
    $this->post(route('technicians.attendance.recalculate', $record))->assertSessionHasErrors('date');
});

it('the legacy staff-specific attendance route uses the shared calculator', function () {
    $data = $this->attendanceInput;
    unset($data['technician_id']);
    $this->post(route('technicians.attendance', $this->staff), $data)->assertSessionHasNoErrors();
    expect(TechnicianAttendance::where('technician_id', $this->staff->id)->count())->toBe(1);
});

it('does not match biometric rows to a different person ID just because the names are the same', function () {
    $this->staff->update(['attendance_person_id' => 'OLD-ID']);
    $csv = "Person ID,Name,Date,Check-In,Check-out\nNEW-ID,Audit Staff,2026-10-06,08:00,17:00";
    $importer = app(AttendanceImportService::class);
    $batch = $importer->preview(UploadedFile::fake()->createWithContent('ids.csv', $csv), auth()->id());
    expect($batch->preview_data[0]['technician_id'])->toBeNull();
    $importer->confirm($batch, app(AttendanceCalculationService::class), auth()->id());
    expect(TechnicianAttendance::where('technician_id', $this->staff->id)->count())->toBe(0)
        ->and(Technician::where('attendance_person_id', 'NEW-ID')->count())->toBe(1);
});

it('does not choose arbitrarily between staff sharing a name without a biometric ID', function () {
    Technician::create(['name' => $this->staff->name, 'phone' => '0778899002', 'basic_salary' => 26000, 'active' => true]);
    $csv = "Person ID,Name,Date,Check-In,Check-out\n,Audit Staff,2026-10-06,08:00,17:00";
    $batch = app(AttendanceImportService::class)->preview(UploadedFile::fake()->createWithContent('ambiguous.csv', $csv), auth()->id());
    expect($batch->preview_data[0]['technician_id'])->toBeNull();
    $result = app(AttendanceImportService::class)->confirm($batch, app(AttendanceCalculationService::class), auth()->id());
    expect(TechnicianAttendance::count())->toBe(0)->and($result->skipped_rows)->toBe(1);
});

it('pays salary from the selected account and uses the selected completion date', function () {
    $sheet = ($this->makeSheet)();
    $date = today()->subDays(3)->toDateString();
    $account = Account::systemAccount('spare_parts');
    $this->post(route('technicians.salaries.pay', $sheet), ['amount' => 26000, 'account_id' => $account->id, 'payment_method' => 'Cash', 'payment_date' => $date])->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Paid')->and($sheet->fresh()->paid_at->toDateString())->toBe($date);
    $entries = AccountTransaction::where('reference_type', $sheet->getMorphClass())->where('reference_id', $sheet->id)->where('category', 'salary')->get();
    expect($entries->count())->toBe(1)->and($entries->first()->account_id)->toBe($account->id)
        ->and($entries->first()->transaction_date->toDateString())->toBe($date)->and((float) $entries->first()->debit)->toBe(26000.0);
});

it('rejects sub-cent payments future dates and inactive accounts without finalizing or paying', function ($override, $field) {
    $sheet = ($this->makeSheet)();
    $payload = ['amount' => 100, 'account_id' => Account::systemAccount('main')->id, 'payment_method' => 'Cash', 'payment_date' => today()->toDateString()];
    if ($override === 'inactive') {
        Account::systemAccount('bank')->update(['active' => false]);
        $override = ['account_id' => Account::systemAccount('bank')->id];
    }
    $this->post(route('technicians.salaries.pay', $sheet), array_replace($payload, $override))->assertSessionHasErrors($field);
    expect((float) $sheet->fresh()->paid_amount)->toBe(0.0)->and(AccountTransaction::count())->toBe(0);
})->with([
    [['amount' => '0.001'], 'amount'],
    [['payment_date' => '2099-01-01'], 'payment_date'],
    ['inactive', 'account_id'],
]);

it('does not pay the last overtime slab for minutes below the minimum configured bracket', function () {
    SalarySetting::firstOrCreate([])->update(['enable_ot' => true, 'opening_time' => '08:00', 'closing_time' => '17:00', 'ot_grace_minutes_after_closing' => 0]);
    OtSlabRule::query()->delete();
    foreach ([[30, 59, 100], [60, 120, 300]] as [$from, $to, $amount]) {
        OtSlabRule::create(['from_minutes' => $from, 'to_minutes' => $to, 'amount' => $amount, 'status' => 'Active']);
    }
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-06', 'Present', '07:55', '17:10');
    expect((float) $metrics['ot_amount'])->toBe(0.0);
});

it('prorates a weekly rate by calendar days and uses a weekly daily deduction rate', function () {
    $this->staff->update(['salary_type' => 'Weekly', 'basic_salary' => 7000, 'daily_salary' => 999]);
    TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => '2026-10-06', 'status' => 'Absent', 'created_by' => auth()->id()]);
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['attendance_salary_amount'])->toBe(31000.0)
        ->and($preview['daily_salary'])->toBe(1000.0)
        ->and($preview['no_pay_deduction'])->toBe(1000.0)
        ->and($preview['net_payable'])->toBe(30000.0)
        ->and($preview['pay_basis'])->toContain('31 calendar days');
});

it('pays hourly work and overtime exactly once with an optional separate OT rate', function ($otRate, $expectedOT, $expectedNet) {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100, 'ot_rate' => $otRate]);
    SalarySetting::firstOrCreate([])->update(['opening_time' => '08:00', 'closing_time' => '15:00', 'lunch_break_minutes' => 0, 'enable_ot' => true, 'ot_grace_minutes_after_closing' => 0, 'enable_half_day' => false]);
    $this->post(route('technicians.attendance.store'), array_replace($this->attendanceInput, ['in_time' => '07:00', 'out_time' => '16:00']))->assertSessionHasNoErrors();
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['attendance_salary_amount'])->toBe(800.0)
        ->and($preview['ot_amount'])->toBe($expectedOT)
        ->and($preview['earnings']['ot'])->toBe($expectedOT)
        ->and($preview['net_payable'])->toBe($expectedNet)
        ->and(data_get($preview, 'calculation_snapshot.earnings.regular_hours'))->toBe(8.0);
})->with([[150, 150.0, 950.0], [0, 100.0, 900.0]]);

it('pays hourly recorded minutes without duplicating late early or absence deductions', function () {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100.25]);
    SalarySetting::firstOrCreate([])->update(['opening_time' => '08:00', 'closing_time' => '17:00', 'lunch_break_minutes' => 0, 'enable_half_day' => true, 'enable_late_deduction' => true, 'late_deduction_type' => 'Fixed amount', 'late_deduction_amount' => 100, 'enable_early_leave_deduction' => true, 'early_leave_deduction_amount' => 100]);
    $this->post(route('technicians.attendance.store'), array_replace($this->attendanceInput, ['in_time' => '09:00', 'out_time' => '10:31']))->assertSessionHasNoErrors();
    expect((float) TechnicianAttendance::firstOrFail()->deduction_amount)->toBe(0.0);
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-07', 'status' => 'Absent'])->assertSessionHasNoErrors();
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['attendance_salary_amount'])->toBe(152.05)->and($preview['net_payable'])->toBe(152.05)
        ->and($preview['total_deductions'])->toBe(0.0);
});

it('uses paid leave hours and explicit manual deductions for hourly staff', function () {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100]);
    SalarySetting::firstOrCreate([])->update(['working_hours_per_day' => 8]);
    $this->post(route('technicians.attendance.store'), ['technician_id' => $this->staff->id, 'date' => '2026-10-06', 'status' => 'Paid Leave', 'is_manual_edit' => 1, 'deduction_amount' => 50])->assertSessionHasNoErrors();
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-10');
    expect($preview['attendance_salary_amount'])->toBe(800.0)->and($preview['net_payable'])->toBe(750.0);
});

it('previews the same attendance metrics as the saved calculation without writing attendance', function () {
    $url = route('technicians.attendance.preview', $this->attendanceInput);
    $response = $this->getJson($url)->assertOk();
    expect(TechnicianAttendance::count())->toBe(0);
    $this->post(route('technicians.attendance.store'), $this->attendanceInput)->assertSessionHasNoErrors();
    $record = TechnicianAttendance::firstOrFail();
    foreach (['working_minutes', 'ot_minutes', 'ot_amount', 'deduction_amount', 'late_minutes'] as $key) {
        expect((float) $response->json($key))->toBe((float) $record->$key);
    }
    $this->getJson(route('technicians.attendance.preview', array_replace($this->attendanceInput, ['out_time' => '07:00'])))->assertUnprocessable()->assertJsonValidationErrors('out_time');
});

it('rejects auto checkout earlier than a late check in', function () {
    expect(fn () => app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-06', null, '23:00', null))
        ->toThrow(ValidationException::class);
});

it('keeps a generated hourly payslip rate stable after profile changes and shows base earnings only once', function () {
    $this->staff->update(['salary_type' => 'Hourly', 'hourly_salary' => 100]);
    TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => '2026-10-06', 'status' => 'Present', 'working_minutes' => 480, 'created_by' => auth()->id()]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10', 'allowances' => 75])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $this->staff->update(['salary_type' => 'Monthly', 'hourly_salary' => 250]);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSee('Hourly salary')->assertSee('Rs. 100.00/hour')->assertSee('Base earnings')->assertDontSee('Attendance salary')->assertDontSee('Basic salary');
    expect($sheet->base_earnings)->toBe(800.0)->and((float) $sheet->gross_salary)->toBe(875.0);
});

it('prevents reopening a salary that already has a disbursement', function () {
    $sheet = ($this->makeSheet)();
    $sheet->update(['paid_amount' => 100]);
    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Correct salary calculation'])->assertUnprocessable();
    expect($sheet->fresh()->status)->toBe('Finalized');
});

it('honours additive OT brackets instead of dropping the previous bracket amount', function () {
    SalarySetting::firstOrCreate([])->update(['enable_ot' => true, 'opening_time' => '08:00', 'closing_time' => '17:00', 'ot_grace_minutes_after_closing' => 0]);
    OtSlabRule::query()->delete();
    OtSlabRule::create(['from_minutes' => 30, 'to_minutes' => 59, 'amount' => 100, 'calculation_rule' => 'Fixed for this range', 'status' => 'Active']);
    OtSlabRule::create(['from_minutes' => 60, 'to_minutes' => 120, 'amount' => 200, 'calculation_rule' => 'Add previous slab amount', 'status' => 'Active']);
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-06', 'Present', '07:55', '18:10');
    expect($metrics['ot_amount'])->toBe(300.0);
});

it('reports impossible dates and invalid times as import row errors rather than moving the date', function () {
    $csv = "Person ID,Name,Date,Check-In,Check-out\nID-1,Example,2026-02-30,08:00,17:00\nID-1,Example,2026-10-06,invalid,17:00";
    $batch = app(AttendanceImportService::class)->preview(UploadedFile::fake()->createWithContent('invalid.csv', $csv), auth()->id());
    expect($batch->error_rows)->toBe(2)->and($batch->total_rows)->toBe(0)->and(TechnicianAttendance::count())->toBe(0);
});

it('allows hourly staff rates to be optional and non hourly profiles to keep a zero hourly rate and turn contributions off', function () {
    $payload = ['name' => $this->staff->name, 'phone' => $this->staff->phone, 'active' => 1, 'basic_salary' => 26000, 'salary_type' => 'Hourly', 'hourly_salary' => 0, 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3, 'epf_eligible' => 0, 'etf_eligible' => 0];
    $this->put(route('technicians.update', $this->staff), $payload)->assertSessionHasNoErrors();
    expect((float) $this->staff->fresh()->hourly_salary)->toBe(0.0)->and($this->staff->fresh()->salary_type)->toBe('Hourly');
    $this->put(route('technicians.update', $this->staff), array_replace($payload, ['hourly_salary' => 125.50]))->assertSessionHasNoErrors();
    expect((float) $this->staff->fresh()->hourly_salary)->toBe(125.5)->and($this->staff->fresh()->salary_type)->toBe('Hourly');
    $this->staff->update(['epf_eligible' => true, 'etf_eligible' => true]);
    $this->put(route('technicians.update', $this->staff), array_replace($payload, ['salary_type' => 'Monthly', 'hourly_salary' => null]))->assertSessionHasNoErrors();
    expect($this->staff->fresh()->epf_eligible)->toBeFalse()->and($this->staff->fresh()->etf_eligible)->toBeFalse()->and((float) $this->staff->fresh()->hourly_salary)->toBe(0.0);
});

it('does not delete a draft with an existing disbursement', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $sheet->update(['paid_amount' => 100]);
    $this->delete(route('technicians.salaries.destroy', $sheet))->assertUnprocessable();
    expect(TechnicianSalarySheet::whereKey($sheet->id)->exists())->toBeTrue();
});
