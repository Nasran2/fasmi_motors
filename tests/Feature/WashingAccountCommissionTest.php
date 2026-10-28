<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use App\Models\User;
use App\Services\JobCommissionService;
use App\Services\WorkshopService;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->actingAs(User::factory()->create())->withOpenCashier();
    Account::ensureSystemAccounts();
    $this->technician = Technician::create([
        'name' => 'Service Technician', 'phone' => '0771111200', 'active' => true,
        'commission_enabled' => true, 'commission_type' => 'Service / labor',
        'commission_percentage' => 10, 'parts_commission_percentage' => 5,
    ]);
    $this->job = JobCard::create([
        'number' => 'WASHING-JOB', 'is_quick_job' => true, 'quick_complaint' => 'Service and washing',
        'main_technician_id' => $this->technician->id, 'status' => 'Assigned',
        'parts_total' => 2800, 'service_total' => 500, 'labor_total' => 500,
        'washing_total' => 200, 'created_by' => auth()->id(),
    ]);
});

test('unpaid washing charges appear separately from service and received money', function () {
    $invoice = app(WorkshopService::class)->invoice($this->job, 0, 'Cash');
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSee('id="job-summary-service-labor"', false)
        ->assertSee('id="job-summary-washing"', false)->assertSeeText('Rs. 1,000.00')->assertSeeText('Rs. 200.00');
    $this->get(route('reports.washing-account'))->assertOk()->assertSeeText($invoice->number)->assertSeeText('WASHING-JOB')->assertViewHas('stats', fn ($stats) => $stats['washing_charges_billed'] === 200.0 && $stats['washing_charges_unpaid'] === 200.0 && (float) $stats['closing_balance'] === 0.0);
    $csv = $this->get(route('reports.washing-account', ['export_csv' => 1]))->assertOk()->streamedContent();
    expect($csv)->toContain($invoice->number, '200.00', 'Unpaid');
    $this->get(route('accounts.washing-ledger.index'))->assertOk()->assertSeeText('Washing Account Ledger')
        ->assertViewHas('cards', fn ($cards) => $cards['Washing Charges Billed'] === 200.0 && $cards['Closing Balance'] === 0.0);
    $this->get(route('cashier.index'))->assertOk()->assertDontSeeText('Washing charges billed:')->assertViewHas('summary', fn ($summary) => $summary['cash_received'] === 0.0);
});

test('partial payment completes service and labor before collecting washing', function () {
    app(WorkshopService::class)->invoice($this->job, 1000, 'Cash');
    expect(Account::systemAccount('washing')->balance)->toBe(0.0);
    $this->get(route('reports.washing-account'))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['washing_charges_billed'] === 200.0 && $stats['washing_charges_unpaid'] === 200.0 && (float) $stats['closing_balance'] === 0.0);
});

test('service and labor commission excludes washing parts other charges and tax', function (string $type) {
    $this->technician->update(['commission_type' => $type]);
    $this->job->update(['other_total' => 300, 'tax' => 450]);
    $invoice = app(WorkshopService::class)->invoice($this->job, 0, 'Cash');
    $commission = TechnicianCommission::where('commission_type', JobCommissionService::TYPE)->sole();
    expect((float) $commission->base_amount)->toBe(1000.0)->and((float) $commission->amount)->toBe(100.0);
    $parts = TechnicianCommission::where('commission_type', 'Job Card - Parts')->sole();
    expect((float) $parts->base_amount)->toBe(2800.0)->and((float) $parts->amount)->toBe(140.0);
})->with(['Service / labor', 'Labor only', 'Total bill', 'Service only', 'Labour']);

test('a washing only job earns no service labor commission', function () {
    $this->job->update(['parts_total' => 0, 'service_total' => 0, 'labor_total' => 0]);
    app(WorkshopService::class)->invoice($this->job, 200, 'Cash');
    expect(TechnicianCommission::count())->toBe(0)->and(Account::systemAccount('washing')->balance)->toBe(200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0);
});

test('paid card washing reaches washing and bank while service and labor stay in main', function () {
    app(WorkshopService::class)->invoice($this->job, 4000, 'Card');
    expect(Account::systemAccount('washing')->balance)->toBe(200.0)
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(2800.0)
        ->and(Account::systemAccount('bank')->balance)->toBe(4000.0);
});

test('job edits refresh pending service labor commission while paid commission remains protected', function () {
    $invoice = app(WorkshopService::class)->invoice($this->job, 0, 'Cash');
    $pending = TechnicianCommission::where('commission_type', JobCommissionService::TYPE)->sole();
    $paid = TechnicianCommission::create($pending->replicate()->getAttributes() + ['status' => 'Paid']);
    $paid->update(['status' => 'Paid']);
    $this->post(route('jobs.unlock', $this->job), ['status' => 'In Progress', 'reason' => 'Correct service and labor charges'])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $this->job), ['status' => 'In Progress', 'service_total' => 750, 'labor_total' => 750, 'washing_total' => 1000])->assertSessionHasNoErrors();
    expect((float) $pending->fresh()->base_amount)->toBe(1500.0)->and((float) $pending->fresh()->amount)->toBe(150.0)
        ->and((float) $paid->fresh()->amount)->toBe(100.0)
        ->and(TechnicianCommission::where('commission_type', 'Job Card - Parts')->sole()->amount)->toEqual(140);
});

test('existing pending legacy commission is corrected once and audit retains previous amount', function () {
    $record = TechnicianCommission::create([
        'technician_id' => $this->technician->id, 'job_card_id' => $this->job->id,
        'commission_type' => 'Job Card - Total bill', 'base_amount' => 4000, 'percentage' => 10,
        'amount' => 400, 'earned_date' => today(), 'status' => 'Pending', 'created_by' => auth()->id(),
    ]);
    DB::transaction(fn () => app(JobCommissionService::class)->syncPending($this->job));
    DB::transaction(fn () => app(JobCommissionService::class)->syncPending($this->job));
    expect((float) $record->fresh()->amount)->toBe(100.0)
        ->and(AuditLog::where('event', 'commission_basis_corrected')->count())->toBe(1)
        ->and(AuditLog::where('event', 'commission_basis_corrected')->first()->old_values['amount'])->toEqual(400);
});

test('linked service labor commission ignores submitted washing inclusive base and manual amount', function () {
    $this->post(route('technicians.commissions.store'), ['technician_id' => $this->technician->id, 'job_card_id' => $this->job->id,
        'commission_type' => 'Service / labor', 'base_amount' => 4000, 'amount' => 400, 'percentage' => 10, 'earned_date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect((float) TechnicianCommission::sole()->base_amount)->toBe(1000.0)->and((float) TechnicianCommission::sole()->amount)->toBe(100.0);
});

test('washing report excludes quotations cancelled invoices and other shops and respects dates', function () {
    foreach ([['Quotation', 'Draft', 'workshop', today(), 100], ['Job Card Bill', 'Cancelled', 'workshop', today(), 200], ['Job Card Bill', 'Unpaid', 'helmet', today(), 300], ['Job Card Bill', 'Unpaid', 'workshop', today()->subDay(), 400]] as $index => [$type, $status, $shop, $date, $amount]) {
        Invoice::create(['number' => 'FILTER-WASH-'.$index, 'shop_type' => $shop, 'invoice_type' => $type, 'status' => $status, 'bill_date' => $date, 'total' => $amount, 'subtotal' => $amount, 'washing_amount' => $amount, 'due_amount' => $amount, 'public_token' => 'filter-wash-'.$index, 'created_by' => auth()->id()]);
    }
    $this->get(route('reports.washing-account'))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['washing_charges_billed'] === 0.0);
    $this->get(route('reports.washing-account', ['date_range' => 'yesterday']))->assertOk()->assertViewHas('stats', fn ($stats) => $stats['washing_charges_billed'] === 400.0);
});

test('washing ledger supports exports opening balances transfers and existing account permissions', function () {
    $this->post(route('accounts.opening-balances.store'), ['account_type' => 'washing', 'opening_date' => today()->toDateString(), 'amount' => 500, 'balance_type' => 'credit'])->assertSessionHasNoErrors();
    $this->post(route('accounts.transfers.store'), ['from_account' => 'washing', 'to_account' => 'main', 'amount' => 100, 'transfer_date' => now()->format('Y-m-d\TH:i'), 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('washing')->balance)->toBe(400.0)->and(Account::systemAccount('main')->balance)->toBe(100.0);
    $this->get(route('accounts.export', ['type' => 'washing', 'format' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    $this->get(route('accounts.export', ['type' => 'washing', 'format' => 'excel']))->assertOk()->assertDownload();
    $this->get(route('reports.washing-account', ['print' => 1, 'preview' => 1]))->assertOk()->assertSeeText('Washing Account Report');
    $role = Role::create(['name' => 'Washing Ledger Viewer', 'permissions' => ['account.view_main']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('accounts.washing-ledger.index'))->assertOk();
    $entry = AccountTransaction::where('account_type', 'washing')->first();
    $this->post(route('accounts.washing-ledger.reverse', $entry), ['reason' => 'Not permitted'])->assertForbidden();
});
