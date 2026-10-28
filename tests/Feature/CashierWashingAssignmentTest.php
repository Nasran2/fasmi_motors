<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\CashierSession;
use App\Models\Drawing;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\JobCommissionService;
use App\Services\SalaryCalculationService;
use App\Services\WashingCommissionService;
use App\Services\WorkshopService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->washer = Technician::create(['name' => 'Today Washer', 'phone' => '0772222000', 'active' => true]);
    $this->otherWasher = Technician::create(['name' => 'Other Washer', 'phone' => '0772222001', 'active' => true]);
    $this->open = function ($rate = 10, $save = false) {
        $this->post(route('cashier.open'), ['opening_balance' => app(CashierService::class)->suggestedOpening(),
            'washing_person_id' => $this->washer->id, 'washing_commission_percentage' => $rate, 'save_washing_default' => $save])->assertRedirect(route('cashier.index'))->assertSessionHasNoErrors();
    };
    $this->close = function ($withdrawal = 0) {
        $service = app(CashierService::class);
        $summary = $service->summary(today()->toDateString());
        $balances = collect($summary['cash_accounts'])->map(fn ($account) => $account['closing'])->all();
        $balances['spare_parts'] -= $withdrawal;
        $this->post(route('cashier.close', CashierSession::active()), ['owner_withdrawal' => $withdrawal,
            'balances' => $balances, 'balance_version' => $service->fingerprint($summary)])->assertSessionHasNoErrors();
    };
});

test('opening popup starts at ten percent and a saved default is independent of daily overrides', function () {
    $this->get(route('dashboard'))->assertOk()->assertSee('data-cashier-open=', false);
    $this->get(route('cashier.opening-popup'))->assertOk()->assertSeeText('Today’s washing person')->assertViewHas('defaultPercentage', 10.0);
    ($this->open)(15, true);
    expect((float) CashierSession::active()->washing_commission_percentage)->toBe(15.0);
    ($this->close)();
    $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('defaultPercentage', 15.0)->assertViewHas('washingPerson', $this->washer->id);
    ($this->open)(20, false);
    expect(app(WashingCommissionService::class)->defaultPercentage())->toBe(15.0);
    $this->withSession(['shop_mode' => 'helmet'])->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('defaultPercentage', 10.0);
});

test('same day opening and closing can repeat without duplicating money or owner drawings', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 500]);
    ($this->open)();
    app(AccountService::class)->recordTransaction('main', 'income', 200, 'Cash received once', paymentMethod: 'Cash');
    ($this->close)(100);
    for ($i = 0; $i < 4; $i++) {
        $this->get(route('cashier.opening-popup'))->assertOk()->assertViewHas('suggestedOpening', 1600.0);
        ($this->open)();
        $summary = app(CashierService::class)->summary(today()->toDateString());
        expect($summary['cash_in_hand'])->toBe(1600.0)->and($summary['cash_received'])->toBe(200.0);
        ($this->close)();
    }
    expect(CashierSession::count())->toBe(1)->and(CashierSession::sole()->events()->count())->toBe(10)
        ->and(Drawing::count())->toBe(1)->and(AccountTransaction::where('category', 'cashier_opening')->count())->toBe(0);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Opening & Closing History')->assertSeeText('Today Washer');
});

test('reopening a changed cash count posts only its difference and preserves cumulative collections', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    ($this->open)();
    app(AccountService::class)->recordTransaction('main', 'income', 200, 'Received once', paymentMethod: 'Cash');
    ($this->close)();
    $this->post(route('cashier.open'), ['opening_balance' => 1190, 'washing_person_id' => $this->washer->id, 'washing_commission_percentage' => 10])->assertSessionHasErrors('opening_reason');
    $this->post(route('cashier.open'), ['opening_balance' => 1190, 'opening_reason' => 'Counted cash difference', 'washing_person_id' => $this->washer->id, 'washing_commission_percentage' => 10])->assertSessionHasNoErrors();
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_in_hand'])->toBe(1190.0)->and($summary['cash_received'])->toBe(200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(1190.0);
});

test('normal jobs and quick jobs inherit the cashier washing person and rate', function () {
    ($this->open)(12.5);
    $this->post(route('intakes.store'), ['bike_number' => 'WASH-333', 'bike_model' => 'Honda', 'phone' => '0778888333', 'customer_name' => 'Washing Customer', 'complaint' => 'Service'])->assertSessionHasNoErrors();
    $this->get(route('jobs.create'))->assertOk()->assertSeeText('Washing person');
    $this->post(route('jobs.store'), ['bike_intake_id' => BikeIntake::sole()->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    $normal = JobCard::sole();
    expect($normal->washing_person_id)->toBe($this->washer->id)->and((float) $normal->washing_commission_percentage)->toBe(12.5);
    $this->get(route('jobs.quick.create'))->assertOk()->assertSeeText('Washing person');
    $this->post(route('jobs.quick.store'), ['washing_total' => 500])->assertSessionHasNoErrors();
    $quick = JobCard::where('is_quick_job', true)->sole();
    expect($quick->washing_person_id)->toBe($this->washer->id)->and((float) $quick->washing_commission_percentage)->toBe(12.5);
});

test('job overrides earn washing commission separately from service and labor at billing', function () {
    ($this->open)();
    $mechanic = Technician::create(['name' => 'Mechanic', 'phone' => '0772222333', 'active' => true, 'commission_enabled' => true, 'commission_type' => 'Service / labor', 'commission_percentage' => 10]);
    $this->post(route('jobs.quick.store'), ['main_technician_id' => $mechanic->id, 'washing_person_id' => $this->otherWasher->id, 'washing_commission_percentage' => 20,
        'service_total' => 500, 'labor_total' => 500, 'washing_total' => 200])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    app(WorkshopService::class)->invoice($job, 0, 'Cash');
    $washing = TechnicianCommission::where('commission_type', WashingCommissionService::TYPE)->sole();
    expect($washing->technician_id)->toBe($this->otherWasher->id)->and((float) $washing->base_amount)->toBe(200.0)->and((float) $washing->amount)->toBe(40.0);
    expect((float) TechnicianCommission::where('commission_type', JobCommissionService::TYPE)->sole()->amount)->toBe(100.0);
    app(JobCommissionService::class)->syncPending($job->fresh());
    app(WashingCommissionService::class)->sync($job->fresh());
    expect((float) $washing->fresh()->amount)->toBe(40.0)->and(TechnicianCommission::where('commission_type', WashingCommissionService::TYPE)->count())->toBe(1);
    $this->get(route('technicians.commissions'))->assertOk()->assertSeeText('Other Washer')->assertSeeText(WashingCommissionService::TYPE);
    $this->otherWasher->update(['salary_period_mode' => 'normal_calendar']);
    $salary = app(SalaryCalculationService::class)->generateMonthlySalary($this->otherWasher, today());
    expect($salary['commission'])->toBe(40.0);
});

test('billed job washing assignment updates only its pending washing commission and paid payroll is protected', function () {
    ($this->open)();
    $this->post(route('jobs.quick.store'), ['washing_total' => 500])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    app(WorkshopService::class)->invoice($job, 0, 'Cash');
    $commission = TechnicianCommission::where('commission_type', WashingCommissionService::TYPE)->sole();
    $this->put(route('jobs.washing-assignment', $job), ['washing_person_id' => $this->otherWasher->id, 'washing_commission_percentage' => 12])->assertSessionHasNoErrors();
    expect($commission->fresh()->technician_id)->toBe($this->otherWasher->id)->and((float) $commission->fresh()->amount)->toBe(60.0);
    $commission->update(['status' => 'Paid']);
    $this->put(route('jobs.washing-assignment', $job), ['washing_person_id' => $this->washer->id, 'washing_commission_percentage' => 10])->assertSessionHasErrors('washing_person_id');
    expect($job->fresh()->washing_person_id)->toBe($this->otherWasher->id)->and((float) $commission->fresh()->amount)->toBe(60.0);
});

test('washing charge changes update the washing commission without including service or labor', function () {
    ($this->open)();
    $this->post(route('jobs.quick.store'), ['washing_total' => 200, 'service_total' => 500])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    app(WorkshopService::class)->invoice($job, 0, 'Cash');
    $this->post(route('jobs.unlock', $job), ['status' => 'In Progress', 'reason' => 'Correct washing charge'])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $job), ['status' => 'In Progress', 'washing_total' => 500, 'service_total' => 900, 'labor_total' => 1000])->assertSessionHasNoErrors();
    $commission = TechnicianCommission::where('commission_type', WashingCommissionService::TYPE)->sole();
    expect((float) $commission->base_amount)->toBe(500.0)->and((float) $commission->amount)->toBe(50.0);
});

test('unassigned and zero percent washing do not create commission and older jobs keep their rates', function () {
    ($this->open)();
    $this->post(route('jobs.quick.store'), ['washing_person_id' => null, 'washing_total' => 500])->assertSessionHasNoErrors();
    $unassigned = JobCard::sole();
    app(WorkshopService::class)->invoice($unassigned, 0, 'Cash');
    expect(TechnicianCommission::count())->toBe(0);
    $this->post(route('jobs.quick.store'), ['washing_commission_percentage' => 0, 'washing_total' => 500])->assertSessionHasNoErrors();
    $zero = JobCard::latest('id')->first();
    app(WorkshopService::class)->invoice($zero, 0, 'Cash');
    expect(TechnicianCommission::count())->toBe(0);
    ($this->close)();
    ($this->open)(25, true);
    expect((float) $zero->fresh()->washing_commission_percentage)->toBe(0.0)->and($unassigned->fresh()->washing_person_id)->toBeNull();
});

test('invalid percentages inactive washing staff and unauthorized job changes are rejected', function () {
    $inactive = Technician::create(['name' => 'Inactive Washer', 'phone' => '0776666333', 'active' => false]);
    $this->post(route('cashier.open'), ['opening_balance' => 0, 'washing_person_id' => $inactive->id, 'washing_commission_percentage' => 101])->assertSessionHasErrors(['washing_person_id', 'washing_commission_percentage']);
    expect(CashierSession::count())->toBe(0);
    ($this->open)();
    $this->post(route('jobs.quick.store'), [])->assertSessionHasNoErrors();
    $job = JobCard::sole();
    $role = Role::create(['name' => 'view-only-washer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->put(route('jobs.washing-assignment', $job), ['washing_person_id' => $this->otherWasher->id, 'washing_commission_percentage' => 50])->assertForbidden();
});

test('opening popup submits with JSON and returns validation errors without leaving the form', function () {
    $this->postJson(route('cashier.open'), ['opening_balance' => 0, 'washing_person_id' => $this->washer->id, 'washing_commission_percentage' => 101])
        ->assertUnprocessable()->assertJsonValidationErrors('washing_commission_percentage');
    $this->postJson(route('cashier.open'), ['opening_balance' => 0, 'washing_person_id' => $this->washer->id, 'washing_commission_percentage' => 10])
        ->assertOk()->assertJsonPath('success', true)->assertJsonPath('redirect', route('cashier.index'));
    expect(CashierSession::isOpenToday())->toBeTrue();
});
