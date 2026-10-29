<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\SalaryBalanceRecovery;
use App\Models\Technician;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use App\Models\User;

beforeEach(function () {
    $this->travelTo('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->staff = Technician::create(['name' => 'Old Balance Staff', 'phone' => '0774012345', 'salary_type' => 'Monthly',
        'salary_period_mode' => 'normal_calendar', 'basic_salary' => 63075, 'daily_salary' => 3000, 'epf_eligible' => false, 'etf_eligible' => false]);
    $this->job = JobCard::create(['number' => 'OLD-REPAIR-JOB', 'is_quick_job' => true, 'quick_bike_details' => ['number' => 'BAA-9398'], 'status' => 'Billed', 'created_by' => auth()->id()]);
    $this->invoice = Invoice::create(['number' => 'OLD-REPAIR-INVOICE', 'invoice_type' => 'Job Card Bill', 'job_card_id' => $this->job->id,
        'subtotal' => 4000, 'total' => 4000, 'due_amount' => 4000, 'status' => 'Unpaid', 'public_token' => 'old-repair', 'created_by' => auth()->id()]);
    $this->plan = TechnicianBikeDeduction::create(['number' => 'OLD-REPAIR-PLAN', 'technician_id' => $this->staff->id,
        'invoice_id' => $this->invoice->id, 'job_card_id' => $this->job->id, 'total_amount' => 4000, 'deduction_type' => 'Monthly installment',
        'installment_amount' => 4000, 'start_month' => '2026-10-01', 'next_deduction_month' => '2026-10-01', 'created_by' => auth()->id()]);
    $this->input = fn ($month = '2026-10') => ['technician_id' => $this->staff->id, 'month' => $month,
        'old_balance_amount' => 48950, 'old_balance_deduction' => 48950, 'old_balance_description' => 'Opening loan balance',
        'old_balance_note' => 'Agreed balance before October', 'other_deductions' => 61500, 'bike_installments' => [$this->plan->id => 0]];
    $this->save = function ($data) {
        $this->post(route('technicians.salaries.store'), $data)->assertSessionHasNoErrors();

        return TechnicianSalarySheet::latest('id')->firstOrFail();
    };
});

it('caps requested old debt at available salary and highlights old and repair balances without duplicate debt', function () {
    $sheet = ($this->save)(($this->input)());
    $totals = $sheet->calculation_snapshot['debt_summary'];
    expect((float) $sheet->net_payable)->toBe(0.0)->and((float) $sheet->total_deductions)->toBe(63075.0)
        ->and($sheet->old_balance_deduction)->toBe(1575.0)->and($totals['requested_salary_balance'])->toEqual(-47375)
        ->and($totals['balance_carried_forward'])->toEqual(51375)->and($totals['remaining_old_balance'])->toEqual(47375)
        ->and($totals['remaining_repair_balance'])->toEqual(4000)->and(TechnicianDeduction::count())->toBe(0);
    $response = $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('Opening loan balance')
        ->assertSeeText('Agreed balance before October')->assertSeeText('OLD-REPAIR-JOB')->assertSeeText('51,375.00')
        ->assertSeeText('-47,375.00')->assertDontSeeText('previous month days included')->assertDontSeeText('period days')
        ->assertDontSeeText('Excluded / carried forward');
    if ($directory = getenv('SALARY_DEBT_QA_DIR')) {
        @mkdir($directory, 0755, true);
        file_put_contents($directory.'/salary-old-balance.html', $response->getContent());
    }
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $balance = TechnicianDeduction::where('source_type', 'salary_old_balance')->sole();
    expect((float) $balance->amount)->toBe(48950.0)->and((float) $balance->recovered_amount)->toBe(1575.0)
        ->and(SalaryBalanceRecovery::count())->toBe(1)->and((float) $this->invoice->fresh()->due_amount)->toBe(4000.0)
        ->and(AccountTransaction::count())->toBe(0);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertUnprocessable();
    expect(TechnicianDeduction::where('source_type', 'salary_old_balance')->count())->toBe(1)->and(SalaryBalanceRecovery::count())->toBe(1);
});

it('offers the old balance next month and recovers only the selected partial amount alongside a repair', function () {
    $first = ($this->save)(($this->input)());
    $this->post(route('technicians.salaries.finalize', $first))->assertSessionHasNoErrors();
    $debt = TechnicianDeduction::where('source_type', 'salary_old_balance')->sole();
    $preview = $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->staff->id, 'month' => '2026-11', 'bike_installments' => [$this->plan->id => 0]]))->assertOk();
    expect($preview->json('old_balances.0.remaining_balance'))->toEqual(47375)->and($preview->json('old_balances.0.amount'))->toEqual(0);
    $next = ($this->save)(['technician_id' => $this->staff->id, 'month' => '2026-11',
        'old_balance_installments' => [$debt->id => 1000], 'bike_installments' => [$this->plan->id => 500]]);
    expect($next->old_balance_deduction)->toBe(1000.0)->and((float) $next->net_payable)->toBe(61575.0);
    $this->post(route('technicians.salaries.finalize', $next))->assertSessionHasNoErrors();
    expect((float) $debt->fresh()->recovered_amount)->toBe(2575.0)->and((float) $this->plan->fresh()->deducted_amount)->toBe(500.0)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(3500.0)->and(AccountTransaction::count())->toBe(0);
    $december = $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->staff->id, 'month' => '2026-12', 'bike_installments' => [$this->plan->id => 0]]))->assertOk();
    expect($december->json('old_balances.0.remaining_balance'))->toEqual(46375)->and($december->json('debt_summary.balance_carried_forward'))->toEqual(49875);
});

it('caps repair recovery without settling invoice amounts which were not recovered', function () {
    $sheet = ($this->save)(['technician_id' => $this->staff->id, 'month' => '2026-10', 'other_deductions' => 61500, 'bike_installments' => [$this->plan->id => 4000]]);
    expect((float) $sheet->net_payable)->toBe(0.0)->and($sheet->repair_deduction_amount)->toBe(1575.0)
        ->and($sheet->calculation_snapshot['debt_summary']['balance_carried_forward'])->toEqual(2425);
    $this->post(route('technicians.salaries.finalize', $sheet), ['repair_selection_reviewed' => 1, 'bike_installments' => [$this->plan->id => 4000]])->assertSessionHasNoErrors();
    expect((float) $this->invoice->fresh()->due_amount)->toBe(2425.0)->and((float) $this->plan->fresh()->deducted_amount)->toBe(1575.0)
        ->and(TechnicianDeduction::where('source_type', 'salary_old_balance')->count())->toBe(0);
});

it('does not save or duplicate an opening debt while previewing replacing or refreshing drafts', function () {
    $data = ($this->input)();
    $this->getJson(route('technicians.salaries.preview', $data))->assertOk();
    ($this->save)($data);
    $sheet = ($this->save)($data);
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Review draft old balances'])->assertSessionHasNoErrors();
    expect(TechnicianSalarySheet::count())->toBe(1)->and(TechnicianDeduction::count())->toBe(0)->and(SalaryBalanceRecovery::count())->toBe(0)
        ->and($sheet->fresh()->old_balance_deduction)->toBe(1575.0);
});

it('restores partial recovery when reopened and prevents reopening its source after later finalization', function () {
    $first = ($this->save)(($this->input)());
    $this->post(route('technicians.salaries.finalize', $first))->assertSessionHasNoErrors();
    $debt = TechnicianDeduction::where('source_type', 'salary_old_balance')->sole();
    $next = ($this->save)(['technician_id' => $this->staff->id, 'month' => '2026-11', 'old_balance_installments' => [$debt->id => 1000], 'bike_installments' => [$this->plan->id => 0]]);
    $this->post(route('technicians.salaries.finalize', $next))->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.reopen', $first), ['reason' => 'Review old balance origin'])->assertSessionHasErrors('reason');
    $this->post(route('technicians.salaries.reopen', $next), ['reason' => 'Change monthly recovery amount'])->assertSessionHasNoErrors();
    expect((float) $debt->fresh()->recovered_amount)->toBe(1575.0)->and(SalaryBalanceRecovery::count())->toBe(1);
    $this->post(route('technicians.salaries.finalize', $next))->assertSessionHasNoErrors();
    expect((float) $debt->fresh()->recovered_amount)->toBe(2575.0)->and(SalaryBalanceRecovery::count())->toBe(2);
});

it('validates ownership outstanding amounts and stale drafts', function () {
    $first = ($this->save)(($this->input)());
    $this->post(route('technicians.salaries.finalize', $first))->assertSessionHasNoErrors();
    $debt = TechnicianDeduction::where('source_type', 'salary_old_balance')->sole();
    foreach ([[$debt->id => 50000], [999999 => 100], [$debt->id => -1]] as $choice) {
        $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-11', 'old_balance_installments' => $choice])->assertSessionHasErrors();
    }
    $next = ($this->save)(['technician_id' => $this->staff->id, 'month' => '2026-11', 'old_balance_installments' => [$debt->id => 1000]]);
    $debt->update(['recovered_amount' => 1600]);
    $this->post(route('technicians.salaries.finalize', $next))->assertSessionHasErrors('month');
    expect($next->fresh()->status)->toBe('Draft')->and(SalaryBalanceRecovery::count())->toBe(1);
});

it('still blocks excessive ordinary deductions and offers overpaid salary as an optional old balance', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10', 'other_deductions' => 70000])->assertSessionHasErrors();
    $carry = TechnicianDeduction::create(['technician_id' => $this->staff->id, 'deduction_name' => 'Earlier salary overpayment',
        'amount' => 5000, 'source_type' => 'salary_overpayment', 'source_id' => 123, 'deduction_date' => '2026-10-01', 'status' => 'pending', 'created_by' => auth()->id()]);
    $sheet = ($this->save)(['technician_id' => $this->staff->id, 'month' => '2026-10', 'old_balance_installments' => [$carry->id => 1500], 'bike_installments' => [$this->plan->id => 0]]);
    expect($sheet->old_balance_deduction)->toBe(1500.0)->and((float) $sheet->net_payable)->toBe(61575.0);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect((float) $carry->fresh()->recovered_amount)->toBe(1500.0)->and($carry->fresh()->status)->toBe('pending');
});
