<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AttendanceCalculationService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    SalarySetting::firstOrCreate([])->update(['opening_time' => '08:00 AM', 'closing_time' => '05:00 PM', 'ot_grace_minutes_after_closing' => 15, 'enable_ot' => true, 'enable_half_day' => false]);
    $this->staff = Technician::create(['name' => 'Salary Detail Staff', 'phone' => '0700555000', 'active' => true, 'salary_type' => 'Monthly',
        'salary_period_mode' => 'normal_calendar', 'basic_salary' => 30000, 'daily_salary' => 1000, 'ot_rate' => 600,
        'commission_type' => 'Manual only', 'epf_eligible' => false, 'etf_eligible' => false]);
    $this->record = TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => '2026-10-05', 'status' => 'Present',
        'in_time' => '08:00:00', 'out_time' => '18:00:00', 'working_minutes' => 600, 'working_hours' => 10, 'ot_minutes' => 0, 'ot_amount' => 0, 'created_by' => auth()->id()]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10', 'manual_additions' => 250,
        'paid_amount' => 8500, 'addition_name' => 'Initial bonus', 'addition_reason' => 'Excellent service', 'manual_deductions' => [['name' => 'Uniform', 'amount' => 50, 'reason' => 'October uniform']]])->assertSessionHasNoErrors();
    $this->sheet = TechnicianSalarySheet::firstOrFail();
});

afterEach(fn () => Carbon::setTestNow());

it('makes 8 AM inclusive without allowing late arrivals or automatic checkout overtime', function ($in, $out, $eligible, $amount) {
    $result = app(AttendanceCalculationService::class)->calculateDailyAttendance($this->staff, '2026-10-06', null, $in, $out);
    expect($result['is_ot_eligible'])->toBe($eligible)->and($result['ot_amount'])->toEqual($amount);
})->with([
    ['07:59', '18:00', true, 600], ['08:00', '18:00', true, 600], ['08:01', '18:00', false, 0],
    ['08:00', '17:15', false, 0], ['08:00', '17:16', true, 300], ['08:00', null, false, 0],
]);

it('shows attendance and descriptions and prints an unpaid draft with balance', function () {
    $this->get(route('technicians.salaries.show', $this->sheet))->assertOk()->assertSeeText('08:00 AM')->assertSeeText('06:00 PM')
        ->assertSeeText('Initial bonus')->assertSeeText('October uniform')->assertSeeText('Refresh Attendance & OT');
    $this->get(route('technicians.salaries.slip', $this->sheet))->assertOk()->assertSeeText('Excellent service')->assertSeeText('October uniform')
        ->assertSeeText('Draft payslip')->assertSeeText('Paid so far')->assertSeeText('30,200.00');
    $this->get(route('technicians.salaries'))->assertOk()->assertSee(route('technicians.salaries.show', $this->sheet), false);
});

it('adds multiple described earnings and deductions without posting cash and retains them through finalization', function () {
    $before = AccountTransaction::count();
    foreach ([['addition', 1000, 'Travel bonus'], ['addition', 400, 'Tools allowance'], ['deduction', 200, 'Loan recovery']] as [$type, $amount, $description]) {
        $this->post(route('technicians.salaries.adjustments', $this->sheet), compact('type', 'amount', 'description') + ['reason' => 'Agreed adjustment'])->assertSessionHasNoErrors();
    }
    $sheet = $this->sheet->fresh();
    expect((float) $sheet->gross_salary)->toBe(31650.0)->and((float) $sheet->total_deductions)->toBe(250.0)
        ->and((float) $sheet->balance_amount)->toBe(31400.0)->and((float) $sheet->paid_amount)->toBe(0.0)
        ->and($sheet->additions()->count())->toBe(3)->and(AccountTransaction::count())->toBe($before);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Finalized')->and(AuditLog::where('event', 'Salary Adjustment Added')->count())->toBe(3);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('Travel bonus')->assertSeeText('Loan recovery')->assertSeeText('Agreed adjustment');
});

it('refreshes old 8 AM attendance OT while preserving descriptions and validates the refreshed snapshot', function () {
    $this->post(route('technicians.salaries.refresh-attendance', $this->sheet), ['reason' => 'Apply corrected 8 AM rule'])->assertSessionHasNoErrors();
    expect((float) $this->record->fresh()->ot_amount)->toBe(600.0)->and($this->record->fresh()->ot_minutes)->toBe(60)
        ->and((float) $this->sheet->fresh()->ot_amount)->toBe(600.0)->and((float) $this->sheet->fresh()->net_payable)->toBe(30800.0)->and((float) $this->sheet->fresh()->paid_amount)->toBe(0.0)
        ->and($this->sheet->additions()->first()->addition_name)->toBe('Initial bonus');
    $this->get(route('technicians.salaries.show', $this->sheet))->assertOk()->assertSeeText('600.00');
    $this->post(route('technicians.salaries.finalize', $this->sheet))->assertSessionHasNoErrors();
});

it('retains attendance snapshots and rejects unnoticed time changes at finalization', function () {
    $this->record->update(['in_time' => '08:01:00']);
    $this->get(route('technicians.salaries.show', $this->sheet))->assertOk()->assertSeeText('08:00 AM')->assertDontSeeText('08:01 AM');
    $this->post(route('technicians.salaries.finalize', $this->sheet))->assertSessionHasErrors('month');
});

it('allows adjustments before full payment and prints partial payment without inventing transactions', function () {
    $account = Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'Cash', 'active' => true, 'opening_balance' => 100000]);
    $this->post(route('technicians.salaries.finalize', $this->sheet))->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.pay', $this->sheet), ['account_id' => $account->id, 'payment_method' => 'Cash', 'amount' => 10000])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.adjustments', $this->sheet), ['type' => 'addition', 'description' => 'Extra work', 'amount' => 1000])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.adjustments', $this->sheet), ['type' => 'deduction', 'description' => 'Loan', 'amount' => 200])->assertSessionHasNoErrors();
    expect($this->sheet->fresh()->status)->toBe('Partially Paid')->and((float) $this->sheet->fresh()->paid_amount)->toBe(10000.0)
        ->and((float) $this->sheet->fresh()->balance_amount)->toBe(21000.0)->and(AccountTransaction::where('category', 'salary')->count())->toBe(1);
    $this->get(route('technicians.salaries.slip', $this->sheet))->assertOk()->assertSeeText('Partially Paid')->assertSeeText('10,000.00')->assertSeeText('21,000.00')->assertSeeText('Extra work');
    $this->post(route('technicians.salaries.adjustments', $this->sheet), ['type' => 'deduction', 'description' => 'Too much', 'amount' => 21000.01])->assertSessionHasErrors('amount');
    expect((float) $this->sheet->fresh()->balance_amount)->toBe(21000.0);
});

it('rejects malformed adjustments without changing totals', function ($data, $key) {
    $this->postJson(route('technicians.salaries.adjustments', $this->sheet), $data)->assertUnprocessable()->assertJsonValidationErrors($key);
    expect((float) $this->sheet->fresh()->net_payable)->toBe(30200.0);
})->with([
    [['type' => 'addition', 'amount' => 10], 'description'],
    [['type' => 'other', 'amount' => 10, 'description' => 'Test'], 'type'],
    [['type' => 'addition', 'amount' => -1, 'description' => 'Test'], 'amount'],
    [['type' => 'addition', 'amount' => 1.123, 'description' => 'Test'], 'amount'],
    [['type' => 'deduction', 'amount' => 40000, 'description' => 'Test'], 'amount'],
]);

it('requires a reason to refresh and keeps finalized and paid sheets locked for attendance', function () {
    $this->post(route('technicians.salaries.refresh-attendance', $this->sheet))->assertSessionHasErrors('reason');
    $this->post(route('technicians.salaries.finalize', $this->sheet))->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.refresh-attendance', $this->sheet), ['reason' => 'Update old rule'])->assertStatus(422);
    $this->sheet->update(['status' => 'Paid', 'paid_amount' => 30200, 'balance_amount' => 0]);
    $this->post(route('technicians.salaries.adjustments', $this->sheet), ['type' => 'addition', 'amount' => 10, 'description' => 'Too late'])->assertStatus(422);
});

it('preserves deliberate manual amount overrides during draft attendance refresh', function () {
    $this->record->update(['is_manual_edit' => true, 'ot_amount' => 350, 'deduction_amount' => 25]);
    $this->post(route('technicians.salaries.refresh-attendance', $this->sheet), ['reason' => 'Apply corrected opening rule'])->assertSessionHasNoErrors();
    expect((float) $this->record->fresh()->ot_amount)->toBe(350.0)->and((float) $this->record->fresh()->deduction_amount)->toBe(25.0);
});
