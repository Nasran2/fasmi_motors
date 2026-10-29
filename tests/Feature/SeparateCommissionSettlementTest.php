<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Bike;
use App\Models\CommissionDeduction;
use App\Models\CommissionPayment;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\CommissionSettlementService;
use App\Services\JobCommissionService;
use Carbon\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-10-29 12:00:00');
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->staff = Technician::create(['name' => 'Commission Person', 'phone' => '0700666000', 'active' => true,
        'salary_type' => 'Monthly', 'basic_salary' => 30000, 'salary_period_mode' => 'normal_calendar', 'commission_type' => 'Manual only', 'epf_eligible' => false, 'etf_eligible' => false]);
    $this->other = Technician::create(['name' => 'Other Person', 'phone' => '0700666001', 'active' => true]);
    $customer = Customer::create(['name' => 'Commission Customer', 'phone' => '0700666002']);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'BIJ-5246', 'brand' => 'Test', 'model' => 'Bike']);
    $invoice = Invoice::create(['number' => 'INV-COM-1', 'customer_id' => $customer->id, 'bike_id' => $bike->id, 'invoice_type' => 'Parts Sale', 'bill_date' => '2026-10-05',
        'subtotal' => 1000, 'total' => 1000, 'paid_amount' => 1000, 'due_amount' => 0, 'status' => 'Paid', 'public_token' => 'commission-test-token', 'created_by' => auth()->id()]);
    $this->row = TechnicianCommission::create(['technician_id' => $this->staff->id, 'invoice_id' => $invoice->id, 'commission_type' => 'Manual',
        'amount' => 300, 'earned_date' => '2026-10-05', 'created_by' => auth()->id()]);
    $this->second = TechnicianCommission::create(['technician_id' => $this->staff->id, 'commission_type' => 'Manual', 'amount' => 200, 'earned_date' => '2026-10-06', 'created_by' => auth()->id()]);
    $this->period = ['from' => '2026-10-01', 'to' => '2026-10-31', 'label' => 'October 2026'];
    $this->input = function ($extra = [], $staff = null, $period = null) {
        $staff ??= $this->staff;
        $period ??= $this->period;
        $service = app(CommissionSettlementService::class);

        return array_replace(['period' => 'month', 'month' => '2026-10', 'amount' => 100, 'description' => 'Agreed recovery',
            'account_id' => Account::systemAccount('main')->id, 'payment_method' => 'Cash', 'payment_date' => '2026-10-29',
            'balance_version' => $service->version($service->rows($staff->id, $period), $period)], $extra);
    };
});

afterEach(fn () => Carbon::setTestNow());

it('shows per-person totals and a popup with bill bike and separate daily monthly slips', function () {
    $this->get(route('technicians.commissions', ['month' => '2026-10']))->assertOk()->assertSeeText('Commission Person')->assertSeeText('500.00')->assertSeeText('View / Deduct / Pay');
    $this->get(route('technicians.commissions.show', $this->staff), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertSeeText('BIJ-5246')->assertSeeText('INV-COM-1')->assertSeeText('Pay commission');
    $this->get(route('technicians.commissions.slip', ['technician' => $this->staff->id, 'period' => 'day', 'date' => '2026-10-05']))->assertOk()->assertViewHas('totals', fn ($t) => $t['earned'] === 300.0)->assertSeeText('BIJ-5246');
    $this->get(route('technicians.commissions.slip', ['technician' => $this->staff->id, 'period' => 'month', 'month' => '2026-10']))->assertOk()->assertViewHas('totals', fn ($t) => $t['earned'] === 500.0);
});

it('never adds commission to salary or consumes it at finalization and reopen', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    expect((float) $sheet->commission)->toBe(0.0)->and((float) $sheet->gross_salary)->toBe(30000.0);
    $this->row->update(['amount' => 400]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    expect($this->row->fresh()->status)->toBe('Pending')->and($this->row->fresh()->salary_sheet_id)->toBeNull();
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertDontSee('>Commission</dt>', false);
    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Review salary only'])->assertSessionHasNoErrors();
    expect($this->row->fresh()->salary_sheet_id)->toBeNull();
});

it('requires legacy drafts to refresh before finalizing commission in salary', function () {
    $this->post(route('technicians.salaries.store'), ['technician_id' => $this->staff->id, 'month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $sheet->update(['commission' => 500, 'gross_salary' => 30500, 'net_payable' => 30500, 'balance_amount' => 30500]);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    $this->post(route('technicians.salaries.refresh-attendance', $sheet), ['reason' => 'Separate commission from salary'])->assertSessionHasNoErrors();
    expect((float) $sheet->fresh()->commission)->toBe(0.0)->and((float) $sheet->fresh()->net_payable)->toBe(30000.0);
});

it('deducts with a description then pays partially and finally without changing salary or double charging cash', function () {
    $this->post(route('technicians.commissions.deduct', $this->staff), ($this->input)(['amount' => 100]))->assertSessionHasNoErrors();
    expect((float) $this->row->fresh()->deducted_amount)->toBe(100.0)->and(AccountTransaction::count())->toBe(0);
    $this->post(route('technicians.commissions.pay', $this->staff), ($this->input)(['amount' => 250, 'note' => 'First commission payment']))->assertSessionHasNoErrors();
    expect((float) $this->row->fresh()->paid_amount)->toBe(200.0)->and($this->row->fresh()->status)->toBe('Paid')
        ->and((float) $this->second->fresh()->paid_amount)->toBe(50.0)->and($this->second->fresh()->status)->toBe('Partially Paid');
    $this->post(route('technicians.commissions.pay', $this->staff), ($this->input)(['amount' => 150]))->assertSessionHasNoErrors();
    expect(CommissionPayment::sum('amount'))->toBe('400.00')->and((float) AccountTransaction::where('category', 'commission')->sum('debit'))->toBe(400.0)
        ->and(TechnicianSalarySheet::count())->toBe(0)->and(CommissionDeduction::first()->description)->toBe('Agreed recovery');
    $this->get(route('technicians.commissions.slip', $this->staff))->assertOk()->assertSeeText('Agreed recovery')->assertSeeText('First commission payment')->assertSeeText('Cash');
});

it('rejects stale repeated payments overdrawing deductions and missing descriptions', function () {
    $input = ($this->input)(['amount' => 100]);
    $this->post(route('technicians.commissions.pay', $this->staff), $input)->assertSessionHasNoErrors();
    $this->postJson(route('technicians.commissions.pay', $this->staff), $input)->assertUnprocessable()->assertJsonValidationErrors('balance_version');
    $this->postJson(route('technicians.commissions.deduct', $this->staff), ($this->input)(['amount' => 401]))->assertUnprocessable()->assertJsonValidationErrors('amount');
    $this->postJson(route('technicians.commissions.deduct', $this->staff), ($this->input)(['description' => '']))->assertUnprocessable()->assertJsonValidationErrors('description');
    expect(CommissionPayment::count())->toBe(1)->and(CommissionDeduction::count())->toBe(0);
});

it('excludes earlier salary-linked and previously paid commissions from another payment', function () {
    $sheet = TechnicianSalarySheet::create(['number' => 'LEGACY-PAY', 'technician_id' => $this->staff->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        'basic_salary' => 30000, 'commission' => 300, 'net_payable' => 30300, 'gross_salary' => 30300, 'status' => 'Paid', 'created_by' => auth()->id()]);
    $this->row->update(['status' => 'Paid', 'salary_sheet_id' => $sheet->id]);
    $this->second->update(['status' => 'Paid']);
    $this->get(route('technicians.commissions.show', $this->staff))->assertOk()->assertSeeText('In earlier salary')->assertDontSeeText('Record Commission Payment');
    $this->postJson(route('technicians.commissions.pay', $this->staff), ($this->input)())->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect((float) $sheet->fresh()->gross_salary)->toBe(30300.0)->and(CommissionPayment::count())->toBe(0);
});

it('posts bank transfer once to Bank and once to the selected logical account without creating a cash movement', function () {
    $this->post(route('technicians.commissions.pay', $this->staff), ($this->input)(['payment_method' => 'Bank Transfer']))->assertSessionHasNoErrors();
    expect((float) AccountTransaction::ledger('main')->sum('debit'))->toBe(100.0)->and((float) AccountTransaction::ledger('bank')->sum('debit'))->toBe(100.0)
        ->and((float) AccountTransaction::ledger('cash')->sum('debit'))->toBe(0.0);
});

it('does not pay commissions belonging to another person or outside the selected day', function () {
    $input = ($this->input)([], $this->other);
    $this->postJson(route('technicians.commissions.pay', $this->other), $input)->assertUnprocessable()->assertJsonValidationErrors('amount');
    $period = ['from' => '2026-10-05', 'to' => '2026-10-05', 'label' => '05 Oct 2026'];
    $this->postJson(route('technicians.commissions.pay', $this->staff), ($this->input)(['period' => 'day', 'date' => '2026-10-05', 'amount' => 301], null, $period))->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect(CommissionPayment::count())->toBe(0);
});

it('requires commission permission to view deduct and pay', function () {
    $role = Role::create(['name' => 'Salary only', 'permissions' => ['technician.salaries']]);
    $input = ($this->input)();
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('technicians.commissions.show', $this->staff))->assertForbidden();
    $this->post(route('technicians.commissions.pay', $this->staff), $input)->assertForbidden();
    $this->post(route('technicians.commissions.deduct', $this->staff), $input)->assertForbidden();
});

it('supports full deduction settlement and prints the reason without recording cash', function () {
    $this->post(route('technicians.commissions.deduct', $this->staff), ($this->input)(['amount' => 500, 'description' => 'Approved commission recovery']))->assertSessionHasNoErrors();
    expect($this->row->fresh()->status)->toBe('Settled')->and($this->second->fresh()->status)->toBe('Settled')
        ->and(AccountTransaction::count())->toBe(0)->and((float) CommissionDeduction::sum('amount'))->toBe(500.0);
    $this->get(route('technicians.commissions.slip', $this->staff))->assertOk()->assertSeeText('Approved commission recovery')->assertViewHas('totals', fn ($t) => $t['balance'] === 0.0 && $t['deducted'] === 500.0);
});

it('attributes a monthly payment correctly to each daily commission slip', function () {
    $this->post(route('technicians.commissions.pay', $this->staff), ($this->input)(['amount' => 400]))->assertSessionHasNoErrors();
    $this->get(route('technicians.commissions.slip', ['technician' => $this->staff->id, 'period' => 'day', 'date' => '2026-10-05']))->assertOk()
        ->assertViewHas('payments', fn ($payments) => $payments->first()['amount'] === 300.0)->assertViewHas('totals', fn ($t) => $t['paid'] === 300.0 && $t['balance'] === 0.0);
    $this->get(route('technicians.commissions.slip', ['technician' => $this->staff->id, 'period' => 'day', 'date' => '2026-10-06']))->assertOk()
        ->assertViewHas('payments', fn ($payments) => $payments->first()['amount'] === 100.0)->assertViewHas('totals', fn ($t) => $t['paid'] === 100.0 && $t['balance'] === 100.0);
});

it('rejects invalid payment amounts and dates without a partial settlement', function ($changes, $field) {
    $this->postJson(route('technicians.commissions.pay', $this->staff), ($this->input)($changes))->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(CommissionPayment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0)->and((float) $this->row->fresh()->paid_amount)->toBe(0.0);
})->with([
    [['amount' => 0], 'amount'], [['amount' => -1], 'amount'], [['amount' => 1.123], 'amount'], [['amount' => 501], 'amount'],
    [['payment_date' => '2026-10-30'], 'payment_date'], [['payment_date' => '2026-10-01'], 'payment_date'], [['payment_method' => 'Unknown'], 'payment_method'],
]);

it('pays directly from Bank without duplicate bank entries and rejects cash marked against Bank', function () {
    $bank = Account::systemAccount('bank');
    $this->postJson(route('technicians.commissions.pay', $this->staff), ($this->input)(['account_id' => $bank->id]))->assertUnprocessable()->assertJsonValidationErrors('payment_method');
    $this->post(route('technicians.commissions.pay', $this->staff), ($this->input)(['account_id' => $bank->id, 'payment_method' => 'Bank Transfer']))->assertSessionHasNoErrors();
    expect(AccountTransaction::count())->toBe(1)->and((float) AccountTransaction::ledger('bank')->sum('debit'))->toBe(100.0);
});

it('protects deducted job commission from later pending-basis synchronization', function () {
    $job = JobCard::create(['number' => 'COMMISSION-LOCK', 'is_quick_job' => true, 'quick_complaint' => 'Work', 'status' => 'Assigned', 'service_total' => 6000, 'created_by' => auth()->id()]);
    $this->row->update(['job_card_id' => $job->id, 'commission_type' => JobCommissionService::TYPE, 'base_amount' => 3000, 'percentage' => 10]);
    $this->post(route('technicians.commissions.deduct', $this->staff), ($this->input)(['amount' => 10]))->assertSessionHasNoErrors();
    app(JobCommissionService::class)->syncPending($job);
    expect((float) $this->row->fresh()->amount)->toBe(300.0)->and((float) $this->row->fresh()->deducted_amount)->toBe(10.0);
});

it('keeps manual commission and payout accounts inside the selected shop', function () {
    session(['shop_mode' => 'helmet']);
    Account::ensureSystemAccounts();
    $this->get(route('technicians.commissions'))->assertOk()->assertViewHas('groups', fn ($groups) => $groups->isEmpty());
    $data = ($this->input)(['account_id' => Account::systemAccount('main')->id]);
    $this->postJson(route('technicians.commissions.pay', $this->staff), $data)->assertUnprocessable()->assertJsonValidationErrors('amount');
    expect(CommissionPayment::count())->toBe(0);
});
