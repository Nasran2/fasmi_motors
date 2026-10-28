<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\SalaryCalculationService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    Account::ensureSystemAccounts();
    $this->owner = Technician::create(['name' => 'Repair Staff Owner', 'phone' => '0771234568', 'salary_type' => 'Monthly', 'basic_salary' => 50000, 'active' => true, 'epf_eligible' => false, 'etf_eligible' => false]);
    $this->worker = Technician::create(['name' => 'Working Technician', 'phone' => '0771234569', 'active' => true, 'commission_enabled' => false]);
});

function staffRepairTestJob(Technician $owner, float $amount = 3000, bool $quick = false): JobCard
{
    if ($quick) {
        test()->post(route('jobs.quick.store'), ['is_technician_bike' => 1, 'technician_id' => $owner->id, 'service_total' => $amount, 'complaint' => 'Staff quick repair'])->assertSessionHasNoErrors();

        return JobCard::latest('id')->firstOrFail();
    }
    $customer = Customer::create(['name' => $owner->name, 'phone' => Str::random(12)]);
    $bike = $customer->bikes()->create(['number' => 'STAFF-'.Str::upper(Str::random(5)), 'model' => 'Staff Scooter', 'is_technician_bike' => true, 'technician_id' => $owner->id]);
    $intake = BikeIntake::create(['intake_number' => 'IN-'.Str::random(8), 'bike_id' => $bike->id, 'complaint' => 'Staff engine repair', 'created_by' => auth()->id()]);
    test()->post(route('jobs.store'), ['bike_intake_id' => $intake->id, 'main_technician_id' => test()->worker->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    $job = JobCard::latest('id')->firstOrFail();
    $job->update(['service_total' => $amount]);

    return $job->fresh();
}

function staffRepairTestBill(JobCard $job, string $type = 'Monthly installment', float $installment = 500): TechnicianBikeDeduction
{
    test()->post(route('jobs.invoice', $job), ['staff_repair_payment' => 'salary', 'repair_deduction_type' => $type, 'repair_installment_amount' => $installment, 'repair_start_month' => '2026-10'])->assertSessionHasNoErrors();

    return TechnicianBikeDeduction::where('job_card_id', $job->id)->sole();
}

function staffRepairTestDraft(Technician $owner, string $month = '2026-10', array $amounts = []): TechnicianSalarySheet
{
    test()->post(route('technicians.salaries.store'), ['technician_id' => $owner->id, 'month' => $month] + ($amounts ? ['bike_installments' => $amounts] : []))->assertSessionHasNoErrors();

    return TechnicianSalarySheet::latest('id')->firstOrFail();
}

test('staff repairs are discoverable and normal payments remain separate from salary recovery', function () {
    $job = staffRepairTestJob($this->owner);
    expect($job->is_staff_bike)->toBeTrue()->and($job->staff_bike_owner_id)->toBe($this->owner->id)
        ->and($job->main_technician_id)->toBe($this->worker->id);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Staff bike payment')->assertSeeText($this->owner->name)->assertSeeText('Deduct from salary');
    $this->get(route('technicians.bike-repairs', ['staff' => $this->owner->id]))->assertOk()->assertSeeText($job->number)->assertSeeText('Not billed');
    $this->post(route('jobs.invoice', $job), ['staff_repair_payment' => 'normal', 'paid_amount' => 1000, 'payment_method' => 'Cash', 'include_previous_balance' => 0])->assertSessionHasNoErrors();
    $invoice = $job->fresh()->invoice;
    expect(TechnicianBikeDeduction::count())->toBe(0)->and((float) $invoice->due_amount)->toBe(2000.0);
    $this->get(route('technicians.bike-repairs.show', $job))->assertOk()->assertSeeText('Normal customer payments apply')->assertSeeText('Cash');
    expect(app(SalaryCalculationService::class)->generateSalaryPreview($this->owner->id, '2026-10-01')['own_bike_deduction'])->toBe(0.0);
});

test('full recovery can be changed in the salary payment popup and settles without a second cash receipt', function () {
    $job = staffRepairTestJob($this->owner);
    $plan = staffRepairTestBill($job, 'Full deduction');
    expect((float) $plan->installment_amount)->toBe(3000.0)->and($plan->installments)->toBe(1)
        ->and(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
    $sheet = staffRepairTestDraft($this->owner);
    $this->getJson(route('technicians.salaries.repair-deductions', $sheet))->assertOk()
        ->assertJsonPath('editable', true)->assertJsonPath('rows.0.job_number', $job->number)
        ->assertJsonPath('rows.0.remaining_balance', 3000)->assertJsonPath('rows.0.amount', 3000);
    $this->post(route('technicians.salaries.finalize', $sheet), [
        'repair_selection_reviewed' => 1, 'bike_installments' => [$plan->id => 1250],
        'amount' => 48750, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id,
    ])->assertSessionHasNoErrors();
    expect((float) $sheet->fresh()->net_payable)->toBe(48750.0)->and($sheet->fresh()->status)->toBe('Paid')
        ->and((float) $job->fresh()->invoice->due_amount)->toBe(1750.0)->and((float) $plan->fresh()->deducted_amount)->toBe(1250.0)
        ->and(Payment::where('method', 'Salary Deduction')->count())->toBe(1)
        ->and((float) AccountTransaction::sum('debit'))->toBe(48750.0)->and((float) AccountTransaction::sum('credit'))->toBe(0.0);
    $this->get(route('technicians.salaries.slip', $sheet))->assertOk()->assertSeeText('Staff bike repair deductions')->assertSeeText($job->number)->assertSeeText('1,750.00');
    $this->get(route('technicians.bike-repairs.show', $job))->assertOk()->assertSeeText('Salary Deduction')->assertSeeText('1,250.00');
});

test('quick staff repairs without a registered bike support upfront payment and monthly recovery', function () {
    $job = staffRepairTestJob($this->owner, quick: true);
    expect($job->bike?->id)->toBeNull()->and($job->repair_staff->id)->toBe($this->owner->id);
    $this->get(route('jobs.show', $job))->assertOk()->assertSeeText('Repair payment method');
    $this->post(route('jobs.invoice', $job), [
        'staff_repair_payment' => 'salary', 'repair_deduction_type' => 'Monthly installment',
        'repair_installment_amount' => 500, 'repair_start_month' => '2026-10',
        'paid_amount' => 300, 'payment_method' => 'Cash',
    ])->assertSessionHasNoErrors();
    $plan = TechnicianBikeDeduction::sole();
    expect($plan->bike_id)->toBeNull()->and((float) $plan->total_amount)->toBe(2700.0)->and($plan->installments)->toBe(6);
    $this->get(route('technicians.bike-repairs', ['staff' => $this->owner->id]))->assertOk()->assertSeeText($job->number)->assertSeeText('Monthly installment');
    $sheet = staffRepairTestDraft($this->owner);
    expect((float) $sheet->own_bike_deduction)->toBe(500.0);
});

test('each repair amount is selectable and finalized salary payments do not deduct the same repair twice', function () {
    $first = staffRepairTestBill(staffRepairTestJob($this->owner));
    $second = staffRepairTestBill(staffRepairTestJob($this->owner, quick: true));
    $sheet = staffRepairTestDraft($this->owner);
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$first->id => 0, $second->id => 900]])->assertSessionHasNoErrors();
    expect((float) $first->fresh()->deducted_amount)->toBe(0.0)->and((float) $second->fresh()->deducted_amount)->toBe(900.0)
        ->and((float) $sheet->fresh()->net_payable)->toBe(49100.0);
    $this->getJson(route('technicians.salaries.repair-deductions', $sheet))->assertOk()->assertJsonPath('editable', false);
    $this->get(route('technicians.salaries'))->assertOk()->assertSeeText('Reopen to change deductions');
    $payload = ['amount' => 1000, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id];
    $this->post(route('technicians.salaries.pay', $sheet), $payload + ['bike_installments' => [$second->id => 100]])->assertSessionHasErrors('bike_installments');
    $this->post(route('technicians.salaries.pay', $sheet), $payload)->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertUnprocessable();
    expect(Payment::where('method', 'Salary Deduction')->count())->toBe(1)->and((float) $second->fresh()->deducted_amount)->toBe(900.0);
});

test('custom monthly amounts carry forward skipped months and complete at the final remaining balance', function () {
    $job = staffRepairTestJob($this->owner);
    $plan = staffRepairTestBill($job);
    foreach (['2026-10' => 400, '2026-11' => 0, '2026-12' => 600, '2027-01' => 2000] as $month => $amount) {
        $sheet = staffRepairTestDraft($this->owner, $month, [$plan->id => $amount]);
        $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    }
    expect((float) $plan->fresh()->deducted_amount)->toBe(3000.0)->and($plan->fresh()->status)->toBe('Completed')
        ->and((float) $job->fresh()->invoice->due_amount)->toBe(0.0)->and(Payment::where('method', 'Salary Deduction')->count())->toBe(3)
        ->and(AccountTransaction::count())->toBe(0);
});

test('direct repair payments cap salary recovery to the actual outstanding bill', function () {
    $job = staffRepairTestJob($this->owner);
    $plan = staffRepairTestBill($job, 'Full deduction');
    $sheet = staffRepairTestDraft($this->owner);
    $invoice = $job->fresh()->invoice;
    $this->post(route('jobs.dues.payment', $job), ['invoice_ids' => [$invoice->id], 'paid_at' => today()->toDateString(), 'paid_amount' => 2800, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id])->assertSessionHasNoErrors();
    $this->getJson(route('technicians.salaries.repair-deductions', $sheet))->assertOk()->assertJsonPath('rows.0.remaining_balance', 200)->assertJsonPath('rows.0.amount', 200);
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$plan->id => 201]])->assertSessionHasErrors('bike_installments');
    expect($sheet->fresh()->status)->toBe('Draft');
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$plan->id => 200]])->assertSessionHasNoErrors();
    expect((float) $invoice->fresh()->due_amount)->toBe(0.0)->and((float) $invoice->fresh()->paid_amount)->toBe(3000.0);
    $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->owner->id, 'month' => '2026-11']))->assertOk()->assertJsonPath('own_bike_deduction', 0);
});

test('salary recovery validates staff ownership duplicates future plans and monthly limits', function () {
    $job = staffRepairTestJob($this->owner);
    $plan = staffRepairTestBill($job);
    $data = ['deduction_type' => 'Full deduction', 'start_month' => '2026-10'];
    $this->post(route('technicians.bike-repairs.store', $job), $data)->assertSessionHasErrors('job_card_id');
    $this->post(route('technicians.bike-deductions.store'), $data + ['technician_id' => $this->worker->id, 'job_card_id' => $job->id, 'total_amount' => 100])->assertSessionHasErrors('job_card_id');
    $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->worker->id, 'month' => '2026-10', 'bike_installments' => [$plan->id => 100]]))->assertUnprocessable();
    $plan->update(['next_deduction_month' => '2026-11-01']);
    $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->owner->id, 'month' => '2026-10', 'bike_installments' => [$plan->id => 100]]))->assertUnprocessable();
    $normal = staffRepairTestJob($this->owner);
    $normal->update(['is_staff_bike' => false, 'staff_bike_owner_id' => null]);
    $normal->bike->update(['is_technician_bike' => false, 'technician_id' => null]);
    $this->post(route('jobs.invoice', $normal), ['staff_repair_payment' => 'salary', 'repair_deduction_type' => 'Full deduction', 'repair_start_month' => '2026-10'])->assertSessionHasErrors('staff_repair_payment');
    expect($normal->fresh()->invoice)->toBeNull()->and(TechnicianBikeDeduction::count())->toBe(1);
});

test('repair balance limits block draft saves and finalization without changing financial records', function () {
    $job = staffRepairTestJob($this->owner, 4250);
    $plan = staffRepairTestBill($job);
    $invalid = ['technician_id' => $this->owner->id, 'month' => '2026-10', 'bike_installments' => [$plan->id => 5000]];
    $this->getJson(route('technicians.salaries.preview', $invalid))->assertUnprocessable()->assertJsonValidationErrors('bike_installments');
    $this->post(route('technicians.salaries.store'), $invalid)->assertSessionHasErrors('bike_installments');
    expect(TechnicianSalarySheet::count())->toBe(0);
    $sheet = staffRepairTestDraft($this->owner, amounts: [$plan->id => 2000]);
    $this->post(route('technicians.salaries.finalize', $sheet), [
        'bike_installments' => [$plan->id => 5000], 'amount' => 0,
    ])->assertSessionHasErrors('bike_installments');
    expect($sheet->fresh()->status)->toBe('Draft')
        ->and((float) $sheet->fresh()->own_bike_deduction)->toBe(2000.0)
        ->and((float) $plan->fresh()->deducted_amount)->toBe(0.0)
        ->and((float) $job->fresh()->invoice->due_amount)->toBe(4250.0)
        ->and(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});

test('large scheduled recovery still allows a salary preview and a smaller chosen deduction', function () {
    $job = staffRepairTestJob($this->owner, 60000);
    $plan = staffRepairTestBill($job, 'Full deduction');
    $this->getJson(route('technicians.salaries.preview', ['technician_id' => $this->owner->id, 'month' => '2026-10']))->assertOk()
        ->assertJsonPath('pending_deductions.items.0.amount', 0)->assertJsonStructure(['repair_deduction_warning']);
    $sheet = staffRepairTestDraft($this->owner, amounts: [$plan->id => 1000]);
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$plan->id => 51000]])->assertSessionHasErrors('bike_installments');
    expect($sheet->fresh()->status)->toBe('Draft')->and(Payment::count())->toBe(0);
});

test('reviewing repair amounts does not bypass stale advances and reopening restores unpaid recovery', function () {
    $job = staffRepairTestJob($this->owner);
    $plan = staffRepairTestBill($job);
    $sheet = staffRepairTestDraft($this->owner);
    $advance = TechnicianAdvance::create(['number' => 'ADV-STALE-REPAIR', 'technician_id' => $this->owner->id, 'payment_date' => '2026-10-01', 'deduct_month' => '2026-10-01', 'amount' => 100, 'status' => 'Pending Deduction', 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id, 'created_by' => auth()->id()]);
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$plan->id => 100]])->assertSessionHasErrors('month');
    expect((float) $plan->fresh()->deducted_amount)->toBe(0.0)->and(Payment::count())->toBe(0);
    $advance->delete();
    $this->post(route('technicians.salaries.finalize', $sheet), ['bike_installments' => [$plan->id => 100]])->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.reopen', $sheet), ['reason' => 'Correct staff repair amount'])->assertSessionHasNoErrors();
    expect((float) $job->fresh()->invoice->due_amount)->toBe(3000.0)->and((float) $plan->fresh()->deducted_amount)->toBe(0.0)
        ->and(Payment::where('method', 'Salary Deduction')->where('status', 'Completed')->count())->toBe(0);
});

test('staff repair and payroll routes respect their existing staff permissions', function () {
    $job = staffRepairTestJob($this->owner);
    staffRepairTestBill($job);
    $sheet = staffRepairTestDraft($this->owner);
    $role = Role::create(['name' => 'Repair Viewer Without Payroll', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]))->withOpenCashier();
    $this->get(route('technicians.bike-repairs'))->assertForbidden();
    $this->get(route('technicians.bike-repairs.show', $job))->assertForbidden();
    $this->getJson(route('technicians.salaries.repair-deductions', $sheet))->assertForbidden();
});

test('staff repair details can move a normally unpaid bill to salary recovery and reject a cancelled bill', function () {
    $job = staffRepairTestJob($this->owner);
    $this->post(route('jobs.invoice', $job), ['paid_amount' => 0])->assertSessionHasNoErrors();
    $this->post(route('technicians.bike-repairs.store', $job), ['deduction_type' => 'Monthly installment', 'installment_amount' => 4000, 'start_month' => '2026-10'])->assertSessionHasErrors('installment_amount');
    $this->post(route('technicians.bike-repairs.store', $job), ['deduction_type' => 'Full deduction', 'start_month' => '2026-10'])->assertSessionHasNoErrors();
    $sheet = staffRepairTestDraft($this->owner);
    $job->fresh()->invoice->update(['status' => 'Cancelled']);
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasErrors('month');
    expect($sheet->fresh()->status)->toBe('Draft')->and(Payment::count())->toBe(0);
});

test('repairs added after the payment review are skipped unless their amount was explicitly chosen', function () {
    $first = staffRepairTestBill(staffRepairTestJob($this->owner));
    $sheet = staffRepairTestDraft($this->owner);
    $second = staffRepairTestBill(staffRepairTestJob($this->owner, quick: true));
    $this->post(route('technicians.salaries.finalize', $sheet), ['repair_selection_reviewed' => 1, 'bike_installments' => [$first->id => 100]])->assertSessionHasNoErrors();
    expect((float) $first->fresh()->deducted_amount)->toBe(100.0)->and((float) $second->fresh()->deducted_amount)->toBe(0.0);
});

test('reopening a skipped month does not change later recovery installments', function () {
    $plan = staffRepairTestBill(staffRepairTestJob($this->owner));
    $first = staffRepairTestDraft($this->owner, '2026-10', [$plan->id => 0]);
    $this->post(route('technicians.salaries.finalize', $first))->assertSessionHasNoErrors();
    $second = staffRepairTestDraft($this->owner, '2026-11', [$plan->id => 500]);
    $this->post(route('technicians.salaries.finalize', $second))->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.reopen', $first), ['reason' => 'Review skipped month'])->assertSessionHasNoErrors();
    expect($plan->fresh()->next_deduction_month->format('Y-m'))->toBe('2026-12')
        ->and((float) $plan->fresh()->deducted_amount)->toBe(500.0);
});
