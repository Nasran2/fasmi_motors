<?php

use App\Models\Account;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\SalaryCalculationService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->staff = Technician::create(['name' => 'Contribution Staff', 'phone' => '0700666000', 'active' => true,
        'salary_type' => 'Monthly', 'salary_period_mode' => 'normal_calendar', 'basic_salary' => 75000, 'daily_salary' => 3000,
        'hourly_salary' => 500, 'ot_rate' => 150, 'commission_type' => 'Manual only',
        'epf_eligible' => true, 'etf_eligible' => true, 'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3]);
    $this->profile = ['name' => $this->staff->name, 'phone' => $this->staff->phone, 'active' => 1, 'basic_salary' => 75000,
        'salary_type' => 'Monthly', 'commission_percentage' => 0, 'parts_commission_percentage' => 0, 'commission_type' => 'Manual only',
        'epf_eligible' => 1, 'etf_eligible' => 1, 'epf_employee_percentage' => 8, 'epf_company_percentage' => 12, 'etf_company_percentage' => 3];
    $this->calculator = app(SalaryCalculationService::class);
});

it('defaults staff contribution salary basic to 30000 and exposes an independent editable field', function () {
    expect((float) $this->staff->epf_etf_basic_salary)->toBe(30000.0)->and((float) $this->staff->fresh()->epf_etf_basic_salary)->toBe(30000.0);
    $this->get(route('technicians.create'))->assertOk()->assertSee('name="epf_etf_basic_salary" value="30000"', false)->assertSeeText('Only this amount is used for EPF and ETF.');
    $this->get(route('technicians.edit', $this->staff))->assertOk()->assertSee('name="epf_etf_basic_salary" value="30000.00"', false);
    $this->put(route('technicians.update', $this->staff), $this->profile + ['epf_etf_basic_salary' => 45000.25])->assertSessionHasNoErrors();
    expect((float) $this->staff->fresh()->epf_etf_basic_salary)->toBe(45000.25)->and((float) $this->staff->fresh()->basic_salary)->toBe(75000.0);
    $this->put(route('technicians.update', $this->staff), $this->profile)->assertSessionHasNoErrors();
    expect((float) $this->staff->fresh()->epf_etf_basic_salary)->toBe(45000.25);
});

it('saves new profiles with the default or an explicit contribution basic', function ($amount) {
    $data = array_replace($this->profile, ['name' => 'New Contribution Staff', 'phone' => '0700666001']);
    if ($amount !== null) {
        $data['epf_etf_basic_salary'] = $amount;
    }
    $this->post(route('technicians.store'), $data)->assertSessionHasNoErrors();
    $created = Technician::where('phone', '0700666001')->sole();
    expect((float) $created->epf_etf_basic_salary)->toBe((float) ($amount ?? 30000))->and((float) $created->basic_salary)->toBe(75000.0);
})->with([null, 42500.50, 0]);

it('rejects invalid contribution amounts without changing the profile', function ($amount) {
    $this->put(route('technicians.update', $this->staff), $this->profile + ['epf_etf_basic_salary' => $amount])->assertSessionHasErrors('epf_etf_basic_salary');
    expect((float) $this->staff->fresh()->epf_etf_basic_salary)->toBe(30000.0);
})->with([-1, 'invalid', '', '1.001', '1000000000000']);

it('uses only the separate contribution basic across salary types without changing earnings or deductions', function ($type) {
    $this->staff->update(['salary_type' => $type]);
    TechnicianAttendance::create(['technician_id' => $this->staff->id, 'date' => today()->startOfMonth(), 'status' => 'Present',
        'in_time' => '08:00:00', 'out_time' => '18:00:00', 'working_minutes' => 600, 'ot_minutes' => 60, 'actual_ot_minutes' => 60,
        'ot_amount' => 150, 'late_deduction_amount' => 50, 'created_by' => auth()->id()]);
    $custom = ['allowances' => 1000, 'manual_additions' => 500, 'manual_deductions' => 100, 'worked_on_leave_pay' => 200];
    $before = $this->calculator->generateMonthlySalary($this->staff->fresh(), today()->startOfMonth(), $custom);
    expect($before['epf_employee'])->toBe(2400.0)->and($before['epf_company'])->toBe(3600.0)->and($before['etf_company'])->toBe(900.0)
        ->and($before['calculation_snapshot']['contributions']['basic_salary'])->toBe(30000.0);
    $this->staff->update(['epf_etf_basic_salary' => 40000]);
    $after = $this->calculator->generateMonthlySalary($this->staff->fresh(), today()->startOfMonth(), $custom);
    foreach (['basic_salary', 'daily_salary', 'attendance_salary_amount', 'gross_salary', 'ot_amount', 'late_deduction', 'manual_additions', 'manual_deductions', 'commission'] as $key) {
        expect($after[$key])->toEqual($before[$key]);
    }
    expect($after['epf_employee'])->toBe(3200.0)->and($after['epf_company'])->toBe(4800.0)->and($after['etf_company'])->toBe(1200.0)
        ->and($after['net_payable'])->toBe(round($before['net_payable'] - 800, 2));
})->with(['Monthly', 'Daily', 'Weekly', 'Hourly']);

it('honours eligibility zero amounts and custom percentage rates', function () {
    $this->staff->update(['epf_etf_basic_salary' => 42500.50, 'epf_employee_percentage' => 7, 'epf_company_percentage' => 11, 'etf_company_percentage' => 2]);
    $values = $this->calculator->calculateEPFETF($this->staff->fresh());
    expect($values['employee_epf_amount'])->toBe(2975.04)->and($values['company_epf_amount'])->toBe(4675.06)->and($values['company_etf_amount'])->toBe(850.01);
    $this->staff->update(['epf_eligible' => false]);
    $values = $this->calculator->calculateEPFETF($this->staff->fresh());
    expect($values['employee_epf_amount'])->toBe(0.0)->and($values['company_epf_amount'])->toBe(0.0)->and($values['company_etf_amount'])->toBe(850.01);
    $this->staff->update(['epf_eligible' => true, 'etf_eligible' => false]);
    expect($this->calculator->calculateEPFETF($this->staff->fresh())['company_etf_amount'])->toBe(0.0);
    $this->staff->update(['epf_etf_basic_salary' => 0, 'etf_eligible' => true]);
    $values = $this->calculator->calculateEPFETF($this->staff->fresh());
    expect($values['employee_epf_amount'])->toBe(0.0)->and($values['company_epf_amount'])->toBe(0.0)->and($values['company_etf_amount'])->toBe(0.0);
});

it('snapshots the contribution base and keeps contribution reports aligned through draft refresh', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => today()->format('Y-m')])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::sole();
    $record = TechnicianContribution::sole();
    expect((float) $sheet->basic_salary)->toBe(75000.0)->and((float) $sheet->epf_employee)->toBe(2400.0)
        ->and((float) $record->basic_salary)->toBe(30000.0)->and((float) $record->employee_epf_amount)->toBe(2400.0);
    $this->staff->update(['epf_etf_basic_salary' => 40000, 'epf_employee_percentage' => 9]);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('EPF / ETF salary basic:')->assertSeeText('30,000.00');
    expect((float) $record->fresh()->basic_salary)->toBe(30000.0)->and((float) $sheet->fresh()->epf_employee)->toBe(2400.0);
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Apply new contribution salary basic'])->assertSessionHasNoErrors();
    expect((float) $sheet->fresh()->basic_salary)->toBe(75000.0)->and((float) $sheet->fresh()->epf_employee)->toBe(3600.0)
        ->and((float) $record->fresh()->basic_salary)->toBe(40000.0)->and((float) $record->fresh()->employee_epf_percentage)->toBe(9.0)
        ->and((float) $record->fresh()->company_epf_amount)->toBe(4800.0)->and((float) $record->fresh()->company_etf_amount)->toBe(1200.0);
    $this->get(route('technicians.salaries.show', $sheet))->assertOk()->assertSeeText('EPF / ETF salary basic:')->assertSeeText('40,000.00');
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $this->get(route('reports.epf'))->assertOk()->assertSeeText('EPF / ETF salary basic')->assertSeeText('40,000.00');
    $this->get(route('reports.etf'))->assertOk()->assertSeeText('40,000.00');
});

it('preserves finalized and paid contribution snapshots after a staff profile change', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => today()->format('Y-m')])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::sole();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $before = $sheet->fresh()->toArray();
    $contribution = TechnicianContribution::sole()->toArray();
    $this->put(route('technicians.update', $this->staff), $this->profile + ['epf_etf_basic_salary' => 50000])->assertSessionHasNoErrors();
    expect($sheet->fresh()->toArray())->toBe($before)->and(TechnicianContribution::sole()->toArray())->toBe($contribution);
    Account::ensureSystemAccounts();
    $this->post(route('technicians.salaries.pay', $sheet), ['amount' => $sheet->fresh()->balance_amount, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id])->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Paid')->and((float) $sheet->fresh()->epf_employee)->toBe(2400.0);
    $paid = $sheet->fresh()->toArray();
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Must not rewrite a paid sheet'])->assertUnprocessable();
    expect($sheet->fresh()->toArray())->toBe($paid)->and(TechnicianContribution::sole()->toArray())->toBe($contribution);
});

it('requires an old draft with contributions to refresh before finalization', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => today()->format('Y-m')])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::sole();
    $snapshot = $sheet->calculation_snapshot;
    unset($snapshot['technician']['epf_etf_basic_salary']);
    $sheet->update(['calculation_snapshot' => $snapshot]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft');
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Use separate EPF and ETF salary basic'])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Finalized')->and((float) $sheet->fresh()->epf_employee)->toBe(2400.0);
});
