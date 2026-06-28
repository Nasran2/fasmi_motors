<?php

use App\Models\Account;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use App\Models\User;

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

    $this->post(route('technicians.attendance.store'), ['technician_id' => $technician->id, 'date' => now()->startOfMonth()->toDateString(), 'status' => 'Present', 'in_time' => '08:00', 'out_time' => '18:00', 'late_minutes' => 0])->assertRedirect();
    expect((float) TechnicianAttendance::first()->working_hours)->toBe(10.0);

    $this->post(route('technicians.salaries.store'), ['technician_id' => $technician->id, 'month' => now()->format('Y-m'), 'paid_amount' => 0, 'other_deductions' => 0])->assertRedirect(route('technicians.salaries'));
    $sheet = TechnicianSalarySheet::first();
    expect((float) $sheet->ot_amount)->toBe(1000.0)->and((float) $sheet->epf_employee)->toBe(4000.0)->and((float) $sheet->net_payable)->toBe(47000.0);
});

it('records an advance and posts an outgoing account ledger entry', function () {
    $technician = Technician::create(['name' => 'Advance Technician', 'phone' => '0720000000', 'basic_salary' => 45000, 'salary_type' => 'Monthly', 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'active' => true]);
    $account = Account::where('code', 'MAIN')->firstOrFail();

    $this->post(route('technicians.advances.store'), ['technician_id' => $technician->id, 'payment_date' => today()->toDateString(), 'amount' => 5000, 'account_id' => $account->id, 'payment_method' => 'Cash', 'deduct_month' => now()->format('Y-m')])->assertRedirect();

    expect(TechnicianAdvance::count())->toBe(1);
    $this->assertDatabaseHas('account_transactions', ['account_id' => $account->id, 'category' => 'salary_advance', 'debit' => 5000]);
});
