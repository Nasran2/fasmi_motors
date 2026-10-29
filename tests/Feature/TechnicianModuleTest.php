<?php

use App\Models\Account;
use App\Models\AttendanceImportBatch;
use App\Models\SalaryCycleSetting;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'Cash', 'opening_balance' => 100000, 'active' => true]);
    Account::create(['code' => 'SPARE', 'name' => 'Spare Parts Account', 'type' => 'Cash', 'opening_balance' => 100000, 'active' => true]);
    Account::create(['code' => 'BANK', 'name' => 'Bank Account', 'type' => 'Bank', 'opening_balance' => 100000, 'active' => true]);
});

it('renders every technician module page', function () {
    $technician = Technician::create(['name' => 'Workshop Technician', 'phone' => '0700000000', 'basic_salary' => 50000, 'salary_type' => 'Monthly', 'commission_percentage' => 5, 'commission_type' => 'Service / labor', 'active' => true]);

    foreach (['technicians.index', 'technicians.create', 'technicians.attendance.index', 'technicians.salaries', 'technicians.advances', 'technicians.commissions', 'technicians.bike-deductions', 'technicians.contributions'] as $route) {
        $this->get(route($route))->assertOk();
    }
    $this->get(route('technicians.history', ['technician' => $technician->id]))->assertOk()->assertSee('Workshop Technician');
});

it('recalculates attendance and uses it in a salary sheet', function () {
    $technician = Technician::create(['name' => 'Payroll Technician', 'phone' => '0710000000', 'basic_salary' => 50000, 'salary_type' => 'Monthly', 'ot_rate' => 500, 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'epf_eligible' => true, 'etf_eligible' => true, 'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3, 'active' => true]);

    $this->post(route('technicians.attendance.store'), ['technician_id' => $technician->id, 'date' => now()->startOfMonth()->toDateString(), 'status' => 'Present', 'in_time' => '07:59', 'out_time' => '18:00', 'late_minutes' => 0])->assertRedirect();
    expect((float) TechnicianAttendance::first()->working_hours)->toBe(10.02);

    $this->post(route('technicians.salaries.store'), ['technician_id' => $technician->id, 'month' => now()->format('Y-m'), 'paid_amount' => 0, 'other_deductions' => 0])->assertRedirect(route('technicians.salaries'));
    $sheet = TechnicianSalarySheet::first();
    expect((float) $sheet->ot_amount)->toBe(500.0)->and((float) $sheet->epf_employee)->toBe(4000.0)->and((float) $sheet->net_payable)->toBe(46500.0);
});

it('records an advance and posts an outgoing account ledger entry', function () {
    $technician = Technician::create(['name' => 'Advance Technician', 'phone' => '0720000000', 'basic_salary' => 45000, 'salary_type' => 'Monthly', 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'active' => true]);
    $account = Account::where('code', 'MAIN')->firstOrFail();

    $this->post(route('technicians.advances.store'), ['technician_id' => $technician->id, 'payment_date' => today()->toDateString(), 'amount' => 5000, 'account_id' => $account->id, 'payment_method' => 'Cash', 'deduct_month' => now()->format('Y-m')])->assertRedirect();

    expect(TechnicianAdvance::count())->toBe(1);
    $this->assertDatabaseHas('account_transactions', ['account_id' => $account->id, 'category' => 'salary_advance', 'debit' => 5000]);
});

it('previews and imports biometric attendance while ignoring detail rows', function () {
    $csv = implode("\n", [
        'Person ID,Name,Department,Date,Shift,Timetable,Attendance Status,Check-In,Check-out,Late,Early Leave,Attended,Absent,Worked,Break,Leave Type,Leave,OT1',
        "'1003,Chamara,Fasmir Motors,2026-06-16,Default Shift,Default Timetable(08:00:00-17:00:00),Normal,7:56:10,17:22:59,0 min,0 min,567 min,0 min,540 min,0 min,-,0 min,23 min",
        'Check-In Time: 2026-06-16 07:56:10,Check-Out Time: 2026-06-16 17:22:59,Attended Duration:567 min',
    ]);

    $this->post(route('technicians.attendance.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent('attendance.csv', $csv),
    ])->assertRedirect()->assertSessionHasNoErrors();

    $batch = AttendanceImportBatch::firstOrFail();
    expect($batch->total_rows)->toBe(1)->and($batch->status)->toBe('Preview');

    $this->post(route('technicians.attendance.import.confirm', $batch))->assertRedirect();
    $attendance = TechnicianAttendance::firstOrFail();
    expect($attendance->technician->attendance_person_id)->toBe('1003')
        ->and($attendance->is_ot_eligible)->toBeTrue()
        ->and($attendance->import_batch_id)->toBe($batch->id);
});

it('allows overtime when check in is at or before opening time', function () {
    SalarySetting::firstOrCreate([])->update(['opening_time' => '08:00 AM', 'closing_time' => '05:00 PM', 'ot_grace_minutes_after_closing' => 15, 'enable_ot' => true]);
    $technician = Technician::create(['name' => 'OT Rule Technician', 'phone' => '0730000000', 'basic_salary' => 50000, 'salary_type' => 'Monthly', 'ot_rate' => 600, 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'active' => true]);

    foreach ([['07:59', true], ['08:00', true], ['08:01', false]] as $index => [$checkIn, $eligible]) {
        $this->post(route('technicians.attendance.store'), [
            'technician_id' => $technician->id,
            'date' => now()->startOfMonth()->addDays($index)->toDateString(),
            'status' => 'Present',
            'in_time' => $checkIn,
            'out_time' => '18:00',
        ])->assertSessionHasNoErrors();
        $record = TechnicianAttendance::latest('id')->firstOrFail();
        expect($record->is_ot_eligible)->toBe($eligible);
        expect((float) $record->ot_amount)->toBe($eligible ? 600.0 : 0.0);
    }
});

it('supports unrestricted daily weekly and monthly range attendance entry', function () {
    $technician = Technician::create(['name' => 'Range Technician', 'phone' => '0750000000', 'basic_salary' => 40000, 'salary_type' => 'Monthly', 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'active' => true]);
    $from = now()->startOfMonth();
    $to = $from->copy()->addDays(6);
    $this->post(route('technicians.attendance.range.store'), ['technician_ids' => [$technician->id], 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString(), 'status' => 'Present', 'in_time' => '08:00', 'out_time' => '17:00'])->assertSessionHasNoErrors();
    expect(TechnicianAttendance::where('technician_id', $technician->id)->count())->toBe(7);
});

it('stores salary snapshots and supports finalize reopen and payment workflow', function () {
    $technician = Technician::create(['name' => 'Snapshot Technician', 'phone' => '0740000000', 'basic_salary' => 52000, 'salary_type' => 'Monthly', 'ot_rate' => 500, 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'epf_eligible' => true, 'etf_eligible' => true, 'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3, 'active' => true]);
    $date = now()->startOfMonth()->toDateString();
    $this->post(route('technicians.attendance.store'), ['technician_id' => $technician->id, 'date' => $date, 'status' => 'Present', 'in_time' => '07:55', 'out_time' => '17:30'])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.store'), ['technician_id' => $technician->id, 'month' => now()->format('Y-m'), 'action' => 'save', 'manual_additions' => 1000, 'addition_name' => 'Performance bonus', 'manual_deductions' => 250, 'deduction_name' => 'Tool replacement'])->assertSessionHasNoErrors();

    $sheet = TechnicianSalarySheet::firstOrFail();
    expect($sheet->status)->toBe('Draft')->and($sheet->calculation_snapshot)->toBeArray()->and($sheet->additions()->count())->toBe(1)->and($sheet->paymentDeductions()->count())->toBe(1);

    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Finalized');
    $this->post(route('technicians.attendance.store'), ['technician_id' => $technician->id, 'date' => $date, 'status' => 'Present', 'in_time' => '07:50', 'out_time' => '17:30', 'edit_reason' => 'Correction'])->assertSessionHasErrors('date');

    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Correct attendance record'])->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Draft');
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $account = Account::where('code', 'MAIN')->firstOrFail();
    $this->post(route('technicians.salaries.pay', $sheet), ['amount' => $sheet->fresh()->balance_amount, 'account_id' => $account->id, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $paidSheet = $sheet->fresh();
    expect($paidSheet->status)->toBe('Paid')->and($paidSheet->paid_at)->not->toBeNull();
});

it('calculates an exact live salary preview and tracks finalized sources and overpayment', function () {
    $user = auth()->user();
    $technician = Technician::create([
        'name' => 'Exact Payroll Technician', 'phone' => '0760000000', 'basic_salary' => 30000,
        'salary_type' => 'Monthly', 'daily_salary' => 1200, 'no_pay_deduction_rate' => 1200,
        'commission_percentage' => 0, 'commission_type' => 'Manual only', 'epf_eligible' => true,
        'etf_eligible' => true, 'epf_employee_percentage' => 8, 'epf_company_percentage' => 12,
        'etf_company_percentage' => 3, 'active' => true,
    ]);
    foreach ([
        ['2026-06-02', 'Present', 480, 60, 300, 100, 50, 0],
        ['2026-06-03', 'Absent', 0, 0, 0, 0, 0, 0],
        ['2026-06-04', 'Half Day', 240, 0, 0, 0, 0, 600],
    ] as [$date, $status, $worked, $ot, $otAmount, $late, $early, $half]) {
        TechnicianAttendance::create([
            'technician_id' => $technician->id, 'date' => $date, 'status' => $status,
            'working_minutes' => $worked, 'working_hours' => $worked / 60, 'ot_minutes' => $ot,
            'ot_hours' => $ot / 60, 'ot_amount' => $otAmount, 'late_deduction_amount' => $late,
            'early_leave_deduction_amount' => $early, 'half_day_deduction_amount' => $half,
            'created_by' => $user->id,
        ]);
    }
    $account = Account::where('code', 'MAIN')->firstOrFail();
    $advance = TechnicianAdvance::create([
        'number' => 'ADV-EXACT', 'technician_id' => $technician->id, 'account_id' => $account->id,
        'payment_date' => '2026-06-01', 'amount' => 1000, 'payment_method' => 'Cash',
        'deduct_month' => '2026-06-01', 'status' => 'Pending Deduction', 'created_by' => $user->id,
    ]);
    $deduction = TechnicianDeduction::create([
        'technician_id' => $technician->id, 'source_type' => 'damage', 'reference_no' => 'DMG-1',
        'deduction_name' => 'Tool damage', 'amount' => 500, 'reason' => 'Broken tool',
        'deduction_date' => '2026-06-05', 'status' => 'pending', 'created_by' => $user->id,
    ]);

    $this->getJson(route('technicians.salaries.preview', [
        'technician_id' => $technician->id, 'month' => '2026-06', 'other_allowances' => 1000,
        'manual_addition' => 500, 'other_deductions' => 200, 'manual_deduction' => 100,
        'paid_amount' => 27650,
    ]))->assertOk()->assertJsonPath('gross_salary', 31800)
        ->assertJsonPath('total_deductions', 6150)
        ->assertJsonPath('net_payable', 25650)
        ->assertJsonPath('balance_amount', -2000)
        ->assertJsonPath('overpaid_amount', 2000)
        ->assertJsonPath('attendance.absent_days', 1)
        ->assertJsonPath('advance_summary.count', 1)
        ->assertJsonPath('pending_deductions.count', 1);

    $this->post(route('technicians.salaries.store'), [
        'technician_id' => $technician->id, 'month' => '2026-06', 'allowances' => 1000,
        'manual_additions' => 500, 'other_deductions' => 200, 'manual_deductions' => 100,
    ])->assertRedirect(route('technicians.salaries'))->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    expect((float) $sheet->net_payable)->toBe(25650.0)->and((float) $sheet->paid_amount)->toBe(0.0);

    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect($advance->fresh()->status)->toBe('Deducted')->and($advance->fresh()->salary_sheet_id)->toBe($sheet->id)
        ->and($deduction->fresh()->status)->toBe('deducted')->and($deduction->fresh()->salary_payment_id)->toBe($sheet->id);

    $this->post(route('technicians.salaries.pay', $sheet), [
        'amount' => 27650, 'account_id' => $account->id, 'payment_method' => 'Online',
        'payment_date' => '2026-07-07', 'payment_note' => 'Includes Rs. 2,000 adjustment',
    ])->assertSessionHasNoErrors();
    $sheet->refresh();
    expect((float) $sheet->balance_amount)->toBe(-2000.0)->and((float) $sheet->overpaid_amount)->toBe(2000.0)
        ->and($sheet->status)->toBe('Paid')->and($sheet->payment_note)->toBe('Includes Rs. 2,000 adjustment');
    $this->assertDatabaseHas('technician_deductions', [
        'technician_id' => $technician->id, 'source_type' => 'salary_overpayment',
        'source_id' => $sheet->id, 'amount' => 2000, 'status' => 'pending',
    ]);
});

it('uses custom salary cycle periods instead of calendar months when enabled', function () {
    SalaryCycleSetting::current()->update([
        'salary_cycle_mode' => 'custom_cutoff',
        'custom_cutoff_enabled' => true,
        'cycle_start_rule' => 'previous_month_balance_day',
        'cycle_end_day' => 30,
        'carry_missing_days' => true,
    ]);

    $technician = Technician::create([
        'name' => 'Cutoff Technician', 'phone' => '0770000000', 'basic_salary' => 30000,
        'salary_type' => 'Daily', 'daily_salary' => 1000, 'commission_percentage' => 0,
        'commission_type' => 'Manual only', 'epf_eligible' => false, 'etf_eligible' => false,
        'active' => true,
    ]);

    foreach ([
        '2026-06-30',
        '2026-07-01',
        '2026-07-30',
        '2026-07-31',
        '2026-08-29',
    ] as $date) {
        TechnicianAttendance::create([
            'technician_id' => $technician->id,
            'date' => $date,
            'status' => 'Present',
            'working_minutes' => 480,
            'working_hours' => 8,
            'created_by' => auth()->id(),
        ]);
    }

    $this->getJson(route('technicians.salaries.preview', [
        'technician_id' => $technician->id,
        'month' => '2026-07',
    ]))->assertOk()
        ->assertJsonPath('salary_cycle_mode', 'custom_cutoff')
        ->assertJsonPath('salary_period_start', '2026-06-30')
        ->assertJsonPath('salary_period_end', '2026-07-30')
        ->assertJsonPath('salary_period_days', 31)
        ->assertJsonPath('carried_from_previous_month_days', 1)
        ->assertJsonPath('carried_to_next_month_days', 1)
        ->assertJsonPath('excluded_dates.0', '2026-07-31')
        ->assertJsonPath('attendance.present_days', 3)
        ->assertJsonPath('attendance_salary_amount', 3000);

    $this->getJson(route('technicians.salaries.preview', [
        'technician_id' => $technician->id,
        'month' => '2026-08',
    ]))->assertOk()
        ->assertJsonPath('salary_period_start', '2026-07-31')
        ->assertJsonPath('salary_period_end', '2026-08-29')
        ->assertJsonPath('attendance.present_days', 2)
        ->assertJsonPath('attendance_salary_amount', 2000);
});

it('keeps normal salary cycle as a calendar month by default', function () {
    $technician = Technician::create([
        'name' => 'Calendar Technician', 'phone' => '0780000000', 'basic_salary' => 30000,
        'salary_type' => 'Daily', 'daily_salary' => 1000, 'commission_percentage' => 0,
        'commission_type' => 'Manual only', 'epf_eligible' => false, 'etf_eligible' => false,
        'active' => true,
    ]);

    TechnicianAttendance::create([
        'technician_id' => $technician->id,
        'date' => '2026-07-31',
        'status' => 'Present',
        'working_minutes' => 480,
        'working_hours' => 8,
        'created_by' => auth()->id(),
    ]);

    $this->getJson(route('technicians.salaries.preview', [
        'technician_id' => $technician->id,
        'month' => '2026-07',
    ]))->assertOk()
        ->assertJsonPath('salary_cycle_mode', 'normal_calendar')
        ->assertJsonPath('salary_period_start', '2026-07-01')
        ->assertJsonPath('salary_period_end', '2026-07-31')
        ->assertJsonPath('attendance.present_days', 1)
        ->assertJsonPath('attendance_salary_amount', 1000);
});

it('shows technician history using the custom salary cycle period', function () {
    SalaryCycleSetting::current()->update([
        'salary_cycle_mode' => 'custom_cutoff',
        'custom_cutoff_enabled' => true,
        'cycle_start_rule' => 'previous_month_balance_day',
        'cycle_end_day' => 30,
        'carry_missing_days' => true,
    ]);

    $technician = Technician::create([
        'name' => 'History Cutoff Technician',
        'phone' => '0790000000',
        'basic_salary' => 30000,
        'salary_type' => 'Monthly',
        'commission_percentage' => 0,
        'commission_type' => 'Manual only',
        'active' => true,
    ]);

    foreach (['2026-05-31', '2026-06-01'] as $date) {
        TechnicianAttendance::create([
            'technician_id' => $technician->id,
            'date' => $date,
            'status' => 'Present',
            'working_minutes' => 480,
            'working_hours' => 8,
            'created_by' => auth()->id(),
        ]);
    }

    $this->get(route('technicians.history', ['technician' => $technician->id, 'month' => '2026-06']))
        ->assertOk()
        ->assertSee('31 May 2026')
        ->assertSee('29 Jun 2026')
        ->assertSee('31 May')
        ->assertSee('01 Jun');
});

it('gracefully handles salary preview when deductions exceed gross without 302 html redirect', function () {
    $technician = Technician::create([
        'name' => 'High Deduction Staff',
        'phone' => '0779998888',
        'basic_salary' => 10000,
        'salary_type' => 'Daily',
        'daily_salary' => 1000,
        'active' => true,
    ]);
    $account = Account::where('code', 'MAIN')->firstOrFail();
    TechnicianAdvance::create([
        'number' => 'ADV-HIGH-DED',
        'technician_id' => $technician->id,
        'account_id' => $account->id,
        'payment_date' => '2026-10-01',
        'amount' => 25000,
        'payment_method' => 'Cash',
        'deduct_month' => '2026-10-01',
        'status' => 'Pending Deduction',
        'created_by' => auth()->id(),
    ]);

    // Regular GET (no Json header) should still return a JSON response, not a 302 redirect to HTML
    $response = $this->get(route('technicians.salaries.preview', [
        'technician_id' => $technician->id,
        'month' => '2026-10',
    ]));

    $response->assertOk()
        ->assertHeader('content-type', 'application/json')
        ->assertJsonPath('net_payable', 0)
        ->assertJsonPath('total_advance_deduction', 25000);
});

it('returns structured json errors when salary preview validation fails without json headers', function () {
    $response = $this->get(route('technicians.salaries.preview', [
        'technician_id' => 999999,
        'month' => 'invalid-month',
    ]));

    $response->assertStatus(422)
        ->assertHeader('content-type', 'application/json')
        ->assertJsonStructure(['message', 'errors']);
});
