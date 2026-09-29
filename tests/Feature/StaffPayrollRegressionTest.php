<?php

use App\Models\Account;
use App\Models\AttendanceImportBatch;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianCommission;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use App\Services\SalaryCalculationService;
use Illuminate\Http\UploadedFile;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    $this->staff = Technician::create([
        'name' => 'Payroll Audit Staff', 'phone' => '0701112223', 'active' => true,
        'salary_type' => 'Monthly', 'basic_salary' => 26000, 'daily_salary' => 1000,
        'commission_type' => 'Manual only', 'commission_percentage' => 0,
        'epf_eligible' => false, 'etf_eligible' => false,
    ]);
});

it('preserves explicit work and absence statuses', function ($status, $in, $out) {
    $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-06-02', $status, $in, $out);
    expect($metrics['status'])->toBe($status);
    if ($status === 'Early Leave') {
        expect($metrics['working_minutes'])->toBeGreaterThan(0);
    }
})->with([
    ['Early Leave', '08:00', '16:00'],
    ['Morning Half Day', '13:00', '17:00'],
    ['Evening Half Day', '08:00', '12:00'],
    ['Absent', '08:00', '17:00'],
]);

it('rejects checkout before check in', function () {
    $this->post(route('technicians.attendance.store'), [
        'technician_id' => $this->staff->id, 'date' => '2026-06-02',
        'status' => 'Present', 'in_time' => '17:00', 'out_time' => '08:00',
    ])->assertSessionHasErrors('out_time');
    expect(TechnicianAttendance::count())->toBe(0);
});

it('pays daily half days once without a second half day deduction', function () {
    $this->staff->update(['salary_type' => 'Daily']);
    TechnicianAttendance::create([
        'technician_id' => $this->staff->id, 'date' => '2026-06-02',
        'status' => 'Half Day', 'half_day_deduction_amount' => 500, 'created_by' => auth()->id(),
    ]);
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-06-01');
    expect($preview['attendance_salary_amount'])->toBe(500.0)
        ->and($preview['half_day_deduction'])->toBe(0)
        ->and($preview['net_payable'])->toBe(500.0);
});

it('calculates an empty commission amount and respects an explicit zero', function () {
    foreach (['' => 125.0, '0' => 0.0] as $amount => $expected) {
        $this->post(route('technicians.commissions.store'), [
            'technician_id' => $this->staff->id, 'commission_type' => 'Manual',
            'base_amount' => 2500, 'percentage' => 5, 'amount' => $amount,
            'earned_date' => '2026-06-02',
        ])->assertSessionHasNoErrors();
        expect((float) TechnicianCommission::latest('id')->first()->amount)->toBe($expected);
    }
});

it('rejects stale commission drafts and links only calculated commissions', function () {
    $commission = TechnicianCommission::create([
        'technician_id' => $this->staff->id, 'commission_type' => 'Manual',
        'base_amount' => 1000, 'percentage' => 10, 'amount' => 100,
        'earned_date' => '2026-06-02', 'status' => 'Pending', 'created_by' => auth()->id(),
    ]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $commission->update(['amount' => 200]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft')->and($commission->fresh()->status)->toBe('Pending');
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect($commission->fresh()->salary_sheet_id)->toBe($sheet->id);
    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Correct payroll audit'])->assertSessionHasNoErrors();
    expect($commission->fresh()->status)->toBe('Pending')->and($commission->fresh()->salary_sheet_id)->toBeNull();
});

it('normalizes the advance deduction month for payroll recovery', function () {
    $account = Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'Cash', 'active' => true]);
    $this->post(route('technicians.advances.store'), [
        'technician_id' => $this->staff->id, 'payment_date' => '2026-06-02',
        'amount' => 500, 'account_id' => $account->id, 'payment_method' => 'Cash', 'deduct_month' => '2026-06',
    ])->assertSessionHasNoErrors();
    expect(TechnicianAdvance::first()->deduct_month->toDateString())->toBe('2026-06-01');
    expect(app(SalaryCalculationService::class)->getAdvanceSummary($this->staff->id, '2026-06-01')['total_amount'])->toBe(500.0);
});

it('uses earned dates for technician commission reports', function () {
    TechnicianCommission::create([
        'technician_id' => $this->staff->id, 'commission_type' => 'Manual',
        'base_amount' => 1000, 'percentage' => 10, 'amount' => 100,
        'earned_date' => '2026-06-02', 'status' => 'Pending', 'created_by' => auth()->id(),
    ]);
    $this->get(route('reports.technicians', ['from' => '2026-06-01', 'to' => '2026-06-30']))
        ->assertOk()->assertViewHas('stats', fn ($stats) => (float) $stats['total_commission'] === 100.0);
});

it('keeps filtered attendance report totals consistent with the rows', function () {
    $other = Technician::create(['name' => 'Other Staff', 'phone' => '0701112224', 'basic_salary' => 26000, 'active' => true]);
    foreach ([[$this->staff, 'Auto Closed'], [$other, 'Absent']] as [$staff, $status]) {
        TechnicianAttendance::create(['technician_id' => $staff->id, 'date' => '2026-06-02', 'status' => $status, 'created_by' => auth()->id()]);
    }
    $this->get(route('reports.attendance', ['from' => '2026-06-02', 'to' => '2026-06-02', 'technician' => $this->staff->id]))
        ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('stats', fn ($stats) => $stats['present'] === 1 && $stats['absent'] === 0);
});

it('reports contributions by salary month excluding drafts and other staff', function () {
    $draftStaff = Technician::create(['name' => 'Draft Staff', 'phone' => '0701112226', 'basic_salary' => 26000]);
    foreach ([[$this->staff, 'Finalized'], [$draftStaff, 'Draft'], [Technician::create(['name' => 'Other', 'phone' => '0701112225', 'basic_salary' => 26000]), 'Finalized']] as $index => [$staff, $status]) {
        $sheet = TechnicianSalarySheet::create([
            'number' => 'PAY-AUDIT-'.$index, 'technician_id' => $staff->id,
            'period_start' => $index === 1 ? '2026-06-01' : '2026-05-31', 'period_end' => '2026-06-29',
            'basic_salary' => 26000, 'gross_salary' => 26000, 'net_payable' => 23920, 'status' => $status, 'created_by' => auth()->id(),
        ]);
        TechnicianContribution::create([
            'technician_id' => $staff->id, 'salary_sheet_id' => $sheet->id, 'month' => '2026-06-01',
            'basic_salary' => 26000, 'employee_epf_percentage' => 8, 'company_epf_percentage' => 12, 'company_etf_percentage' => 3,
            'employee_epf_amount' => 2080, 'company_epf_amount' => 3120, 'company_etf_amount' => 780,
        ]);
    }
    $filters = ['from' => '2026-06-01', 'to' => '2026-06-30', 'technician' => $this->staff->id];
    $this->get(route('reports.epf', $filters))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('stats', fn ($stats) => (float) $stats['total_epf'] === 5200.0);
    $this->get(route('reports.etf', $filters))->assertOk()->assertViewHas('stats', fn ($stats) => (float) $stats['total_etf'] === 780.0);
    $this->get(route('reports.salary', $filters))->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1)
        ->assertViewHas('stats', fn ($stats) => (float) $stats['gross'] === 26000.0);
    $this->get(route('reports.epf', ['from' => '2026-06-01', 'to' => '2026-06-30']))
        ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
});

it('requires recalculation when attendance changes after saving a draft', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    TechnicianAttendance::create([
        'technician_id' => $this->staff->id, 'date' => '2026-06-02', 'status' => 'Absent', 'created_by' => auth()->id(),
    ]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft');
});

it('does not mix previous years into current monthly staff totals', function () {
    $account = Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'Cash', 'active' => true]);
    foreach ([today()->startOfMonth(), today()->subYear()->startOfMonth()] as $index => $month) {
        TechnicianCommission::create([
            'technician_id' => $this->staff->id, 'commission_type' => 'Manual', 'base_amount' => 1000,
            'percentage' => 10, 'amount' => 100, 'earned_date' => $month, 'created_by' => auth()->id(),
        ]);
        TechnicianAdvance::create([
            'number' => 'ADV-YEAR-'.$index, 'technician_id' => $this->staff->id, 'account_id' => $account->id,
            'payment_date' => $month, 'deduct_month' => $month, 'amount' => 500, 'payment_method' => 'Cash', 'created_by' => auth()->id(),
        ]);
        TechnicianSalarySheet::create([
            'number' => 'PAY-YEAR-'.$index, 'technician_id' => $this->staff->id,
            'period_start' => $month->copy()->subDay(), 'period_end' => $month->copy()->addDays(28),
            'basic_salary' => 26000, 'gross_salary' => 26000, 'net_payable' => 26000, 'created_by' => auth()->id(),
        ]);
    }
    $this->get(route('technicians.commissions'))->assertOk()->assertViewHas('stats', fn ($stats) => (float) $stats['total'] === 100.0);
    $this->get(route('technicians.advances'))->assertOk()->assertViewHas('stats', fn ($stats) => (float) $stats['month'] === 500.0);
    $this->get(route('technicians.salaries'))->assertOk()->assertViewHas('stats', fn ($stats) => (float) $stats['payable'] === 26000.0);
});

it('places staff report selectors inside the filter form', function ($route) {
    $response = $this->get(route($route))->assertOk();
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//form[@method="GET"]//select[@name="technician"]')->length)->toBe(1);
})->with(['reports.attendance', 'reports.salary', 'reports.epf', 'reports.etf']);

it('requires recalculation when staff salary settings change', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $this->staff->update(['basic_salary' => 30000]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft');
});

it('preserves automatic checkout flags when biometric attendance is imported', function () {
    $this->staff->update(['attendance_person_id' => 'AUDIT-001']);
    $csv = "Person ID,Name,Date,Attendance Status,Check-In,Check-out\nAUDIT-001,Payroll Audit Staff,2026-06-02,Normal,07:55,";
    $this->post(route('technicians.attendance.import.preview'), [
        'file' => UploadedFile::fake()->createWithContent('auto-checkout.csv', $csv),
    ])->assertSessionHasNoErrors();
    $this->post(route('technicians.attendance.import.confirm', AttendanceImportBatch::firstOrFail()))->assertSessionHasNoErrors();
    $record = TechnicianAttendance::firstOrFail();
    expect($record->status)->toBe('Auto Closed')->and($record->is_auto_checkout)->toBeTrue()
        ->and($record->is_ot_eligible)->toBeFalse()->and((float) $record->ot_amount)->toBe(0.0);
});

it('includes manual attendance deduction overrides in salary totals and slips', function () {
    $this->post(route('technicians.attendance.store'), [
        'technician_id' => $this->staff->id, 'date' => '2026-06-02', 'status' => 'Present',
        'in_time' => '08:00', 'out_time' => '17:00', 'is_manual_edit' => 1, 'deduction_amount' => 200,
    ])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    expect((float) $sheet->total_deductions)->toBe(200.0)->and((float) $sheet->net_payable)->toBe(25800.0);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('Attendance override adjustment');
});

it('restores daily earnings when a half day deduction is manually reduced', function () {
    $this->staff->update(['salary_type' => 'Daily']);
    TechnicianAttendance::create([
        'technician_id' => $this->staff->id, 'date' => '2026-06-02', 'status' => 'Half Day',
        'half_day_deduction_amount' => 500, 'deduction_amount' => 400, 'is_manual_edit' => true, 'created_by' => auth()->id(),
    ]);
    $preview = app(SalaryCalculationService::class)->generateSalaryPreview($this->staff->id, '2026-06-01');
    expect($preview['attendance_salary_amount'])->toBe(600.0)->and($preview['net_payable'])->toBe(600.0);
});

it('requires recalculation when an advance is added after saving a draft', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $account = Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'Cash', 'active' => true]);
    TechnicianAdvance::create([
        'number' => 'ADV-LATE', 'technician_id' => $this->staff->id, 'account_id' => $account->id, 'payment_date' => '2026-06-02',
        'deduct_month' => '2026-06-01', 'amount' => 500, 'payment_method' => 'Cash', 'created_by' => auth()->id(),
    ]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft')->and(TechnicianAdvance::first()->status)->toBe('Pending Deduction');
});
