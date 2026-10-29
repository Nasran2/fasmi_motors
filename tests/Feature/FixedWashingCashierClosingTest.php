<?php

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\JobCard;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\WashingCommissionService;
use App\Services\WorkshopService;
use Maatwebsite\Excel\Facades\Excel;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->closeCash = function (float $counted, float $withdrawal = 0, ?string $reason = null) {
        $service = app(CashierService::class);

        return $this->post(route('cashier.close', CashierSession::active()), ['closing_balance' => $counted, 'owner_withdrawal' => $withdrawal,
            'difference_reason' => $reason, 'balance_version' => $service->fingerprint($service->summary(today()->toDateString()))]);
    };
});

test('single closing count preserves account allocations and sweeps washing cash into main', function () {
    Account::systemAccount('main')->update(['opening_balance' => -1257853.43]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 1276918.43]);
    Account::systemAccount('washing')->update(['opening_balance' => 500]);
    // Legacy snapshots may contain negative account allocations; new openings reject these.
    CashierSession::create(['session_date' => today(), 'status' => 'open', 'opening_balance' => 19565,
        'cash_opening_balances' => ['main' => -1257853.43, 'spare_parts' => 1276918.43, 'washing' => 500], 'opened_at' => now(), 'opened_by' => auth()->id()]);
    $this->get(route('cashier.index'))->assertOk()->assertSee('name="closing_balance"', false)->assertSee('name="balances[main]"', false)->assertSee('name="balances[spare_parts]"', false)->assertDontSee('name="balances[washing]"', false);
    ($this->closeCash)(19560, 0, 'Physical drawer short by five rupees')->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(-1257358.43)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(1276918.43)->and(Account::systemAccount('washing')->balance)->toBe(0.0)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(19560.0)
        ->and((float) DailyClosing::sole()->cash_difference)->toBe(-5.0);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_in_hand'])->toBe(19560.0)->and($summary['cash_paid'])->toBe(5.0)->and($summary['cash_other_in'])->toBe(0.0);
});

test('washing sweep excludes bank money and never repeats on a same day reopening', function () {
    app(CashierService::class)->open(0, null);
    app(AccountService::class)->recordTransaction('washing', 'income', 500, 'Cash washing receipt', paymentMethod: 'Cash');
    app(AccountService::class)->recordTransaction('washing', 'income', 300, 'Bank washing allocation', paymentMethod: 'Bank Transfer');
    app(AccountService::class)->recordTransaction('bank', 'income', 300, 'Bank washing receipt', paymentMethod: 'Bank Transfer');
    ($this->closeCash)(500)->assertSessionHasNoErrors();
    expect(Account::systemAccount('washing')->balance)->toBe(300.0)->and(Account::systemAccount('main')->balance)->toBe(500.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(300.0)->and(AccountTransfer::count())->toBe(1);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_received'])->toBe(500.0)->and($summary['cash_paid'])->toBe(0.0)->and($summary['cash_other_in'])->toBe(0.0)
        ->and($summary['cash_accounts']['washing']['closing'])->toBe(0.0)->and($summary['cash_in_hand'])->toBe(500.0);
    app(CashierService::class)->open(500, null);
    ($this->closeCash)(500)->assertSessionHasNoErrors();
    expect(AccountTransfer::count())->toBe(1)->and(Account::systemAccount('main')->balance)->toBe(500.0);
    $this->travel(1)->days();
    expect(app(CashierService::class)->suggestedOpening())->toBe(500.0);
});

test('cash count and washing settlement remain atomic on invalid reasons or stale forms', function () {
    Account::systemAccount('washing')->update(['opening_balance' => 500]);
    app(CashierService::class)->open(500, null);
    ($this->closeCash)(490)->assertSessionHasErrors('difference_reason');
    expect(AccountTransfer::count())->toBe(0)->and(Account::systemAccount('washing')->balance)->toBe(500.0);
    $service = app(CashierService::class);
    $version = $service->fingerprint($service->summary(today()->toDateString()));
    app(AccountService::class)->recordTransaction('main', 'income', 5, 'Receipt after form opened', paymentMethod: 'Cash');
    $this->post(route('cashier.close', CashierSession::active()), ['owner_withdrawal' => 0, 'closing_balance' => 500, 'balance_version' => $version])->assertSessionHasErrors('balance_version');
    expect(AccountTransfer::count())->toBe(0)->and(CashierSession::active())->not->toBeNull();
});

test('cashier close requires a nonnegative physical cash count and keeps expenses deducted', function () {
    Account::systemAccount('washing')->update(['opening_balance' => 500]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 300]);
    app(CashierService::class)->open(800, null);
    app(AccountService::class)->recordTransaction('washing', 'expense', 100, 'Washing supplies', paymentMethod: 'Cash');
    $this->post(route('cashier.close', CashierSession::active()), ['owner_withdrawal' => 0])->assertSessionHasErrors('closing_balance');
    ($this->closeCash)(-1)->assertSessionHasErrors('closing_balance');
    ($this->closeCash)(650, 50)->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(400.0)->and(Account::systemAccount('washing')->balance)->toBe(0.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(250.0)->and((float) DailyClosing::sole()->cash_expense)->toBe(150.0);
    $event = CashierSession::sole()->events()->where('action', 'closed')->sole();
    expect($event->details['washing_to_main'])->toEqual(400)->and($event->details['closing_balance'])->toEqual(650);
    Excel::fake();
    $this->get(route('accounts.daily-closing.export', ['format' => 'excel']))->assertOk();
    Excel::assertDownloaded('daily_closing_'.today()->toDateString().'.xlsx', function ($export) {
        $rows = $export->array();

        return (float) $rows[0][4] === 400.0 && (float) $rows[0][5] === 400.0 && (float) $rows[1][4] === 0.0 && (float) $rows[1][5] === 0.0;
    });
});

test('fixed washing commission is sixty per job regardless of washing charge and can exceed a hundred', function () {
    $washer = Technician::create(['name' => 'Fixed Washer', 'phone' => '0777897890', 'active' => true]);
    app(CashierService::class)->open(0, null, ['washing_person_id' => $washer->id, 'washing_commission_amount' => 60]);
    foreach ([200, 500] as $charge) {
        $this->post(route('jobs.quick.store'), ['washing_total' => $charge])->assertSessionHasNoErrors();
        $job = JobCard::latest('id')->first();
        app(WorkshopService::class)->invoice($job, 0, 'Cash');
        $commission = TechnicianCommission::where('job_card_id', $job->id)->where('commission_type', WashingCommissionService::TYPE)->sole();
        expect((float) $commission->amount)->toBe(60.0)->and((float) $commission->percentage)->toBe(0.0);
    }
    $this->post(route('jobs.quick.store'), ['washing_total' => 500, 'washing_commission_amount' => 120])->assertSessionHasNoErrors();
    $job = JobCard::latest('id')->first();
    app(WorkshopService::class)->invoice($job, 0, 'Cash');
    expect((float) TechnicianCommission::where('job_card_id', $job->id)->sole()->amount)->toBe(120.0);
});

test('fixed commission migration changes pending earnings and preserves paid commission history', function () {
    $washer = Technician::create(['name' => 'Legacy Washer', 'phone' => '0777897891', 'active' => true]);
    foreach (['Pending', 'Paid'] as $index => $status) {
        $job = JobCard::create(['number' => 'LEGACY-FIXED-'.$index, 'is_quick_job' => true, 'washing_total' => 200, 'washing_person_id' => $washer->id,
            'washing_commission_percentage' => 10, 'created_by' => auth()->id()]);
        $invoice = app(WorkshopService::class)->invoice($job, 0, 'Cash');
        TechnicianCommission::where('job_card_id', $job->id)->update(['amount' => 20, 'percentage' => 10, 'status' => $status]);
    }
    $migration = require database_path('migrations/2026_10_29_000005_use_fixed_washing_commission.php');
    $migration->up();
    $pending = TechnicianCommission::where('status', 'Pending')->sole();
    $paid = TechnicianCommission::where('status', 'Paid')->sole();
    expect((float) $pending->amount)->toBe(60.0)->and((float) $pending->percentage)->toBe(0.0)
        ->and((float) $paid->amount)->toBe(20.0)->and((float) $paid->percentage)->toBe(10.0)
        ->and((float) $paid->jobCard->washing_commission_amount)->toBe(20.0);
});

test('washing cash deficit is settled from main without counting the transfer as another expense', function () {
    Account::systemAccount('main')->update(['opening_balance' => 500]);
    app(CashierService::class)->open(500, null);
    app(AccountService::class)->recordTransaction('washing', 'expense', 100, 'Washing supplies paid from cash', paymentMethod: 'Cash');
    ($this->closeCash)(400)->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(400.0)->and(Account::systemAccount('washing')->balance)->toBe(0.0);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    expect($summary['cash_paid'])->toBe(100.0)->and($summary['cash_in_hand'])->toBe(400.0);
});

test('editable main and spare parts balances reconcile their own accounts after washing settlement', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('washing')->update(['opening_balance' => 200]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 500]);
    Account::systemAccount('bank')->update(['opening_balance' => 300]);
    $service = app(CashierService::class);
    $cashier = $service->open(1700, null);
    $payload = ['owner_withdrawal' => 100, 'closing_balance' => 1600, 'balances' => ['main' => 1250, 'spare_parts' => 350],
        'balance_version' => $service->fingerprint($service->summary(today()->toDateString()))];
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('difference_reason');
    expect(AccountTransfer::count())->toBe(0);
    $this->post(route('cashier.close', $cashier), $payload + ['difference_reason' => 'Verified cash allocation between main and spare parts'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(1250.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(350.0)
        ->and(Account::systemAccount('washing')->balance)->toBe(0.0)->and(Account::systemAccount('bank')->balance)->toBe(300.0)
        ->and((float) DailyClosing::sole()->main_difference)->toBe(50.0)->and((float) DailyClosing::sole()->spare_parts_difference)->toBe(-50.0)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(1600.0)->and((float) DailyClosing::sole()->cash_difference)->toBe(0.0);
    expect($cashier->fresh()->cash_balances['main'])->toEqual(1250)->and($cashier->fresh()->cash_balances['spare_parts'])->toEqual(350);
    $this->travel(1)->days();
    expect($service->suggestedOpening())->toBe(1600.0);
});

test('account closing amounts must match the drawer count before any money is posted', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('washing')->update(['opening_balance' => 200]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 500]);
    $service = app(CashierService::class);
    $cashier = $service->open(1700, null);
    $payload = ['owner_withdrawal' => 100, 'closing_balance' => 1600, 'balances' => ['main' => 1200, 'spare_parts' => 500],
        'difference_reason' => 'Does not match the physical cash', 'balance_version' => $service->fingerprint($service->summary(today()->toDateString()))];
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('closing_balance');
    expect(AccountTransfer::count())->toBe(0)->and($cashier->fresh()->status)->toBe('open')
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0)->and(Account::systemAccount('spare_parts')->balance)->toBe(500.0);
    $payload['balances'] = ['main' => 1200];
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('balances.spare_parts');
    $payload['balances'] = ['main' => 1200, 'spare_parts' => 400, 'washing' => 0];
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('balances');
});

test('editable bank closing balance is saved separately from counted cash with a reason', function (float $verifiedBank) {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('washing')->update(['opening_balance' => 200]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 500]);
    Account::systemAccount('bank')->update(['opening_balance' => 500]);
    $service = app(CashierService::class);
    $cashier = $service->open(1700, null);
    $this->get(route('cashier.index'))->assertOk()->assertSee('name="balances[bank]"', false);
    $payload = ['owner_withdrawal' => 0, 'closing_balance' => 1700, 'balances' => ['main' => 1200, 'spare_parts' => 500, 'bank' => $verifiedBank],
        'balance_version' => $service->fingerprint($service->summary(today()->toDateString()))];
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('difference_reason');
    expect($cashier->fresh()->status)->toBe('open')->and(Account::systemAccount('bank')->balance)->toBe(500.0)->and(AccountTransfer::count())->toBe(0);
    $this->post(route('cashier.close', $cashier), $payload + ['difference_reason' => 'Verified bank statement balance'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('bank')->balance)->toBe($verifiedBank)->and(Account::systemAccount('main')->balance)->toBe(1200.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(500.0)->and(Account::systemAccount('washing')->balance)->toBe(0.0)
        ->and((float) DailyClosing::sole()->bank_actual_balance)->toBe($verifiedBank)->and((float) DailyClosing::sole()->bank_difference)->toBe($verifiedBank - 500)
        ->and((float) DailyClosing::sole()->cash_actual_balance)->toBe(1700.0)->and((float) DailyClosing::sole()->cash_difference)->toBe(0.0);
    $summary = $service->summary(today()->toDateString());
    expect($summary['cash_in_hand'])->toBe(1700.0)->and($summary['cash_paid'])->toBe(0.0)->and($summary['total_received'])->toBe(0.0)
        ->and(array_sum($cashier->fresh()->cash_balances))->toEqual(1700)->and((float) $cashier->fresh()->actual_balances['bank'])->toBe($verifiedBank);
    $event = $cashier->events()->where('action', 'closed')->sole();
    expect((float) $event->cash_amount)->toBe(1700.0)->and((float) $event->details['bank_balance'])->toBe($verifiedBank);
    $this->get(route('cashier.index'))->assertOk()->assertSeeText('Verified Bank Account balance');
    Excel::fake();
    $this->get(route('accounts.daily-closing.export', ['format' => 'excel']))->assertOk();
    Excel::assertDownloaded('daily_closing_'.today()->toDateString().'.xlsx', function ($export) use ($verifiedBank) {
        $bank = collect($export->array())->first(fn ($row) => $row[0] === 'Bank Account (Non-cash)');

        return $bank && (float) $bank[4] === 500.0 && (float) $bank[5] === $verifiedBank && (float) $bank[6] === $verifiedBank - 500;
    });
    $this->travel(1)->days();
    expect($service->suggestedOpening())->toBe(1700.0);
})->with([450.0, 550.0]);

test('bank money cannot cover a mismatched cash count and bank changes make old closing forms stale', function () {
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('bank')->update(['opening_balance' => 500]);
    $service = app(CashierService::class);
    $cashier = $service->open(1000, null);
    $payload = ['owner_withdrawal' => 0, 'closing_balance' => 1500, 'balances' => ['main' => 1000, 'spare_parts' => 0, 'bank' => 500],
        'balance_version' => $service->fingerprint($service->summary(today()->toDateString()))];
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('closing_balance');
    app(AccountService::class)->recordTransaction('bank', 'expense', 10, 'New bank charge', paymentMethod: 'Bank Transfer');
    $payload['closing_balance'] = 1000;
    $this->post(route('cashier.close', $cashier), $payload)->assertSessionHasErrors('balance_version');
    expect($cashier->fresh()->status)->toBe('open')->and(Account::systemAccount('main')->balance)->toBe(1000.0);
});
