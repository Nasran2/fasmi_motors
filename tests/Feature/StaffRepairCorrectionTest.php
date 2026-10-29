<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    Account::ensureSystemAccounts();
    $this->staff = Technician::create(['name' => 'Correction Staff', 'phone' => '0774321000', 'salary_type' => 'Monthly', 'basic_salary' => 50000, 'active' => true, 'epf_eligible' => false, 'etf_eligible' => false]);
    $this->post(route('jobs.quick.store'), ['is_technician_bike' => 1, 'technician_id' => $this->staff->id, 'bike_number' => 'STAFF-CORRECTION', 'service_total' => 4250])->assertSessionHasNoErrors();
    $this->job = JobCard::sole();
    $this->post(route('jobs.invoice', $this->job), ['staff_repair_payment' => 'salary', 'repair_deduction_type' => 'Monthly installment', 'repair_installment_amount' => 500, 'repair_start_month' => '2026-10'])->assertSessionHasNoErrors();
    $this->plan = TechnicianBikeDeduction::sole();
    $this->invoice = $this->job->fresh()->invoice;
});

function correctionTestSalary(string $month = '2026-10', float $paid = 0, array $oldBalances = []): TechnicianSalarySheet
{
    test()->post(route('technicians.salaries.store'), ['technician_id' => test()->staff->id, 'month' => $month, 'old_balance_installments' => $oldBalances])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::latest('id')->firstOrFail();
    test()->post(route('technicians.salaries.finalize', $sheet), ['amount' => $paid, 'account_id' => Account::systemAccount('main')->id, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();

    return $sheet->fresh();
}

function correctionTestUpdate(Payment $payment, float $amount, float $expected = 500, string $reason = 'Correct agreed staff repair recovery')
{
    return test()->put(route('technicians.salaries.repairs.update', $payment), ['amount' => $amount, 'expected_amount' => $expected, 'reason' => $reason]);
}

test('pending salary recovery is highlighted and its arrangement is editable before any deduction', function () {
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Salary deduction scheduled — not yet deducted')
        ->assertSeeText('Edit payment arrangement')->assertSeeText('Unpaid');
    $this->put(route('technicians.bike-repairs.update', $this->job), ['payment_mode' => 'normal'])->assertSessionHasNoErrors();
    expect($this->plan->fresh()->status)->toBe('Cancelled')->and((float) $this->invoice->fresh()->due_amount)->toBe(4250.0)->and(Payment::count())->toBe(0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Pay normally');
    $this->put(route('technicians.bike-repairs.update', $this->job), ['payment_mode' => 'salary', 'deduction_type' => 'Monthly installment', 'installment_amount' => 700, 'start_month' => '2026-11'])->assertSessionHasNoErrors();
    expect((float) TechnicianBikeDeduction::where('status', 'Active')->sole()->installment_amount)->toBe(700.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('700.00')->assertSeeText('Nov 2026');
});

test('after salary recovery arrangement changes require a reason and retain the linked past deduction', function () {
    $sheet = correctionTestSalary();
    $payment = Payment::where('method', 'Salary Deduction')->sole();
    expect($payment->salary_sheet_id)->toBe($sheet->id)->and($payment->technician_bike_deduction_id)->toBe($this->plan->id)
        ->and((float) $this->invoice->fresh()->received_amount)->toBe(0.0);
    $this->put(route('technicians.bike-repairs.update', $this->job), ['payment_mode' => 'normal'])->assertSessionHasErrors('reason');
    expect($this->plan->fresh()->status)->toBe('Active');
    $this->put(route('technicians.bike-repairs.update', $this->job), ['payment_mode' => 'normal', 'reason' => 'Owner will pay the remaining repair directly'])->assertSessionHasNoErrors();
    expect((float) $this->invoice->fresh()->due_amount)->toBe(3750.0)->and((float) $payment->fresh()->amount)->toBe(500.0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Rs. 500.00 deducted from salary')->assertSeeText($sheet->number)
        ->assertSee(route('technicians.salaries.slip', $sheet));
    $this->get(route('technicians.bike-repairs.show', $this->job))->assertOk()->assertSeeText('Owner will pay the remaining repair directly')->assertSeeText($sheet->number);
});

test('applied repair corrections require a reason and update the correct paysheet bill and plan', function () {
    $sheet = correctionTestSalary();
    $payment = $sheet->repairPayments()->sole();
    $this->get(route('technicians.salaries.repairs.edit', $payment))->assertOk()->assertSeeText('Correct Salary Deduction')->assertSeeText($sheet->number);
    $this->put(route('technicians.salaries.repairs.update', $payment), ['amount' => 200, 'expected_amount' => 500])->assertSessionHasErrors('reason');
    correctionTestUpdate($payment, 200)->assertSessionHasNoErrors();
    expect((float) $payment->fresh()->amount)->toBe(200.0)->and((float) $this->plan->fresh()->deducted_amount)->toBe(200.0)
        ->and((float) $this->invoice->fresh()->paid_amount)->toBe(200.0)->and((float) $this->invoice->fresh()->due_amount)->toBe(4050.0)
        ->and((float) $sheet->fresh()->net_payable)->toBe(49800.0)->and((float) $sheet->fresh()->total_job_deduction)->toBe(200.0)
        ->and(AccountTransaction::count())->toBe(0);
    $audit = AuditLog::where('event', 'Staff Repair Salary Deduction Corrected')->sole();
    expect($audit->new_values['old_amount'])->toEqual(500)->and($audit->new_values['amount'])->toEqual(200)->and($audit->new_values['salary_number'])->toBe($sheet->number);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('Correct agreed staff repair recovery')->assertSeeText('200.00');
});

test('reducing a deduction on a paid salary creates a payable balance without rewriting cash payments', function () {
    $sheet = correctionTestSalary(paid: 49500);
    $payment = $sheet->repairPayments()->sole();
    $ledgerIds = AccountTransaction::pluck('id')->all();
    correctionTestUpdate($payment, 200)->assertSessionHasNoErrors();
    expect((float) $sheet->fresh()->paid_amount)->toBe(49500.0)->and((float) $sheet->fresh()->balance_amount)->toBe(300.0)
        ->and($sheet->fresh()->status)->toBe('Partially Paid')->and(AccountTransaction::pluck('id')->all())->toBe($ledgerIds);
    $this->post(route('technicians.salaries.pay', $sheet), ['amount' => 300, 'account_id' => Account::systemAccount('main')->id, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    expect($sheet->fresh()->status)->toBe('Paid')->and((float) $sheet->fresh()->paid_amount)->toBe(49800.0)
        ->and((float) $payment->fresh()->amount)->toBe(200.0)->and(Payment::where('method', 'Salary Deduction')->count())->toBe(1)
        ->and((float) AccountTransaction::sum('debit'))->toBe(49800.0);
});

test('increasing an already paid salary deduction tracks and revises the overpayment carry forward', function () {
    $sheet = correctionTestSalary(paid: 49500);
    $payment = $sheet->repairPayments()->sole();
    correctionTestUpdate($payment, 800)->assertSessionHasNoErrors();
    expect((float) $sheet->fresh()->overpaid_amount)->toBe(300.0)->and((float) $sheet->fresh()->balance_amount)->toBe(-300.0);
    $carry = TechnicianDeduction::where('source_type', 'salary_overpayment')->sole();
    expect((float) $carry->amount)->toBe(300.0)->and($carry->deduction_date->format('Y-m'))->toBe('2026-11');
    correctionTestUpdate($payment->fresh(), 600, 800)->assertSessionHasNoErrors();
    expect((float) $carry->fresh()->amount)->toBe(100.0);
    correctionTestUpdate($payment->fresh(), 200, 600)->assertSessionHasNoErrors();
    expect($carry->fresh()->status)->toBe('cancelled')->and((float) $sheet->fresh()->balance_amount)->toBe(300.0)
        ->and((float) AccountTransaction::sum('debit'))->toBe(49500.0);
});

test('corrections enforce repair and salary limits reject stale forms and cannot edit normal receipts', function () {
    $sheet = correctionTestSalary();
    $payment = $sheet->repairPayments()->sole();
    correctionTestUpdate($payment, 4251)->assertSessionHasErrors('amount');
    correctionTestUpdate($payment, 800)->assertSessionHasNoErrors();
    correctionTestUpdate($payment->fresh(), 200, 500)->assertSessionHasErrors('amount');
    expect((float) $payment->fresh()->amount)->toBe(800.0)->and(AuditLog::where('event', 'Staff Repair Salary Deduction Corrected')->count())->toBe(1);
    $ordinary = $this->invoice->payments()->create(['number' => 'ORDINARY-RECEIPT', 'amount' => 100, 'method' => 'Cash', 'status' => 'Completed', 'paid_at' => now(), 'created_by' => auth()->id()]);
    $this->get(route('technicians.salaries.repairs.edit', $ordinary))->assertUnprocessable();
    correctionTestUpdate($ordinary, 50, 100)->assertUnprocessable();
});

test('correcting an earlier monthly recovery keeps later recoveries and their paysheets intact', function () {
    $october = correctionTestSalary();
    $november = correctionTestSalary('2026-11');
    $payment = $october->repairPayments()->sole();
    correctionTestUpdate($payment, 300)->assertSessionHasNoErrors();
    expect((float) $this->plan->fresh()->deducted_amount)->toBe(800.0)->and((float) $this->invoice->fresh()->due_amount)->toBe(3450.0)
        ->and((float) $november->fresh()->repair_deduction_amount)->toBe(500.0)->and((float) $november->fresh()->net_payable)->toBe(49500.0)
        ->and($this->plan->fresh()->next_deduction_month->format('Y-m'))->toBe('2026-12');
});

test('an overpayment already recovered by a later paysheet blocks inconsistent historical changes atomically', function () {
    $sheet = correctionTestSalary(paid: 49500);
    $payment = $sheet->repairPayments()->sole();
    correctionTestUpdate($payment, 800)->assertSessionHasNoErrors();
    $carry = TechnicianDeduction::where('source_type', 'salary_overpayment')->sole();
    correctionTestSalary('2026-11', oldBalances: [$carry->id => 300]);
    expect($carry->fresh()->status)->toBe('deducted');
    $due = (float) $this->invoice->fresh()->due_amount;
    correctionTestUpdate($payment->fresh(), 600, 800)->assertSessionHasErrors('amount');
    expect((float) $payment->fresh()->amount)->toBe(800.0)->and((float) $sheet->fresh()->net_payable)->toBe(49200.0)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe($due)->and((float) $carry->fresh()->amount)->toBe(300.0);
});

test('a zero correction can be reopened and finalized again without leaving duplicate active deductions', function () {
    $sheet = correctionTestSalary();
    $payment = $sheet->repairPayments()->sole();
    correctionTestUpdate($payment, 0)->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Choose a fresh repair deduction'])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$this->plan->id => 500]])->assertSessionHasNoErrors();
    expect(Payment::where('method', 'Salary Deduction')->where('status', 'Completed')->count())->toBe(1)
        ->and((float) $this->plan->fresh()->deducted_amount)->toBe(500.0);
    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Review the repair deduction again'])->assertSessionHasNoErrors();
    expect((float) $this->plan->fresh()->deducted_amount)->toBe(0.0)->and((float) $this->invoice->fresh()->due_amount)->toBe(4250.0);
});

test('payment arrangement permission cannot correct applied salary deductions without payroll access', function () {
    $sheet = correctionTestSalary();
    $payment = $sheet->repairPayments()->sole();
    $role = Role::create(['name' => 'Staff Repair Clerk', 'permissions' => ['technician.bike_deductions']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]))->withOpenCashier();
    $this->get(route('technicians.salaries.repairs.edit', $payment))->assertForbidden();
    correctionTestUpdate($payment, 200)->assertForbidden();
    $this->put(route('technicians.bike-repairs.update', $this->job), ['payment_mode' => 'normal', 'reason' => 'Staff will settle the remaining balance directly'])->assertSessionHasNoErrors();
    expect((float) $payment->fresh()->amount)->toBe(500.0);
});
