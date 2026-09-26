<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\ServiceReminder;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AccountService;
use App\Services\WorkshopService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    Account::query()->update(['opening_balance' => 100000]);
});

function financialAuditJob(float $parts, float $service): JobCard
{
    $customer = Customer::create(['name' => 'Audit Customer', 'phone' => Str::random(12)]);
    $bike = $customer->bikes()->create(['number' => 'AUDIT-'.Str::random(10), 'model' => 'Honda']);
    $intake = BikeIntake::create(['intake_number' => 'INT-'.Str::random(10), 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => auth()->id()]);

    return JobCard::create(['number' => 'JOB-'.Str::random(10), 'bike_intake_id' => $intake->id, 'status' => 'Completed', 'priority' => 'Normal', 'parts_total' => $parts, 'service_total' => $service, 'created_by' => auth()->id()]);
}

test('full repair receipts allocate each component and reverse exact postings', function ($parts, $service, $method) {
    $job = financialAuditJob($parts, $service);
    $invoice = app(WorkshopService::class)->invoice($job, $parts + $service, $method, Account::systemAccount('main')->id);
    expect(Account::systemAccount('main')->balance)->toBe(100000.0 + $service)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(100000.0 + $parts);
    $payment = $invoice->payments()->firstOrFail();
    $this->delete(route('sales.payments.cancel', $payment), ['reason' => 'Audit cancellation'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(100000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(100000.0)
        ->and($invoice->fresh()->due_amount)->toEqual($parts + $service);
    $count = AccountTransaction::count();
    $this->delete(route('sales.payments.cancel', $payment), ['reason' => 'Audit repeated cancellation']);
    expect(AccountTransaction::count())->toBe($count);
})->with([[8000.0, 3000.0, 'Cash'], [8000.0, 0.0, 'Cash'], [0.0, 3000.0, 'Cash'], [8000.0, 3000.0, 'Card']]);

test('partial repair and due settlement use the same allocation and reject duplicate form requests', function () {
    $invoice = app(WorkshopService::class)->invoice(financialAuditJob(10000, 5000), 6000, 'Cash');
    expect(Account::systemAccount('main')->balance)->toBe(102000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(104000.0);
    $data = ['customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'amount' => 3000, 'method' => 'Cash', 'account_id' => Account::systemAccount('main')->id, 'paid_at' => today()->toDateString(), '_submission_token' => (string) Str::uuid()];
    $this->post(route('sales.payments.store'), $data)->assertSessionHasNoErrors();
    $this->post(route('sales.payments.store'), $data)->assertSessionHasNoErrors();
    expect($invoice->fresh()->due_amount)->toEqual(6000)
        ->and($invoice->payments()->count())->toBe(2);
    $data['amount'] = 6000;
    $data['_submission_token'] = (string) Str::uuid();
    $this->post(route('sales.payments.store'), $data)->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(105000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(110000.0);
});

test('reversal retains history and backdated entries recalculate later running balances', function () {
    $service = app(AccountService::class);
    $first = $service->recordTransaction('main', 'expense', 5000, 'Expense', transactionDate: today());
    $service->recordTransaction('main', 'income', 1000, 'Backdated income', transactionDate: today()->subDay());
    expect((float) $first->fresh()->running_balance)->toBe(96000.0);
    expect($service->reverseTransaction($first, 'Audit reversal'))->toBeTrue()
        ->and($service->reverseTransaction($first, 'Duplicate reversal'))->toBeFalse()
        ->and(Account::systemAccount('main')->balance)->toBe(101000.0)
        ->and($service->getRunningBalance('main'))->toBe(101000.0);
});

test('edited paid invoice reallocates existing receipts without duplicate income', function () {
    $job = financialAuditJob(8000, 2000);
    $invoice = app(WorkshopService::class)->invoice($job, 5000, 'Cash');
    $this->post(route('jobs.unlock', $job), ['status' => 'In Progress', 'reason' => 'Correct service charge'])->assertSessionHasNoErrors();
    $this->put(route('jobs.update', $job), ['status' => 'In Progress', 'service_total' => 4000])->assertSessionHasNoErrors();
    expect(Account::systemAccount('spare_parts')->balance)->toBe(103333.33)
        ->and(Account::systemAccount('main')->balance)->toBe(101666.67)
        ->and($invoice->fresh()->due_amount)->toEqual(7000);
    $this->delete(route('sales.payments.cancel', $invoice->payments()->first()), ['reason' => 'Cancel corrected payment'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('spare_parts')->balance)->toBe(100000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(100000.0);
});

test('insufficient stock rolls back job part creation and totals', function () {
    $job = financialAuditJob(0, 0);
    $store = Store::create(['name' => 'Audit Store', 'active' => true]);
    $product = Product::create(['code' => 'AUDIT-PART', 'name' => 'Part', 'cost_price' => 50, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 1]);
    $this->post(route('jobs.parts.store', $job), ['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100])->assertSessionHasErrors('quantity');
    expect($job->parts()->count())->toBe(0)
        ->and((float) $job->fresh()->parts_total)->toBe(0.0)
        ->and((float) StoreStock::first()->quantity)->toBe(1.0);
});

test('staff repair supports four different monthly recoveries without cash income', function () {
    $technician = Technician::create(['name' => 'Audit Staff', 'phone' => '0700000099', 'basic_salary' => 60000, 'salary_type' => 'Monthly', 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'epf_eligible' => false, 'etf_eligible' => false, 'active' => true]);
    $job = financialAuditJob(16000, 8000);
    $job->intake->bike->update(['is_technician_bike' => true, 'technician_id' => $technician->id]);
    $invoice = app(WorkshopService::class)->invoice($job, 0, 'Cash');
    $this->post(route('technicians.bike-deductions.store'), ['technician_id' => $technician->id, 'bike_id' => $job->intake->bike_id, 'job_card_id' => $job->id, 'total_amount' => 24000, 'installment_amount' => 4000, 'installments' => 4, 'deduction_type' => 'Monthly installment', 'start_month' => '2026-06'])->assertSessionHasNoErrors();
    $plan = TechnicianBikeDeduction::firstOrFail();
    $remaining = 24000;
    foreach (['2026-06' => 4000, '2026-07' => 6000, '2026-08' => 5000, '2026-09' => 9000] as $month => $deduction) {
        $this->post(route('technicians.salaries.store'), ['technician_id' => $technician->id, 'month' => $month, 'bike_installments' => [$plan->id => $deduction]])->assertSessionHasNoErrors();
        $sheet = TechnicianSalarySheet::latest('id')->firstOrFail();
        expect((float) $sheet->own_bike_deduction)->toBe((float) $deduction);
        $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
        $remaining -= $deduction;
        expect((float) $plan->fresh()->total_amount - (float) $plan->fresh()->deducted_amount)->toBe((float) $remaining)
            ->and((float) $invoice->fresh()->due_amount)->toBe((float) $remaining);
    }
    expect($plan->fresh()->status)->toBe('Completed')
        ->and(Payment::where('method', 'Salary Deduction')->count())->toBe(4)
        ->and(AccountTransaction::count())->toBe(0);
});

test('finalize and pay rolls back deductions when the payment data is invalid', function () {
    $technician = Technician::create(['name' => 'Rollback Staff', 'phone' => '0700000098', 'basic_salary' => 60000, 'salary_type' => 'Monthly', 'commission_percentage' => 0, 'commission_type' => 'Manual only', 'active' => true]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $technician->id, 'month' => '2026-06'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::firstOrFail();
    $this->post(route('technicians.salaries.finalize', $sheet), ['amount' => 1000])->assertSessionHasErrors('account_id');
    expect($sheet->fresh()->status)->toBe('Draft')
        ->and(AccountTransaction::count())->toBe(0);
});

test('operational account scenario reconciles receipts purchase drawing expense and salary', function () {
    app(WorkshopService::class)->invoice(financialAuditJob(20000, 10000), 30000, 'Cash');
    $supplier = Supplier::create(['name' => 'Audit Supplier', 'active' => true]);
    $store = Store::create(['name' => 'Audit Purchase Store', 'active' => true]);
    $product = Product::create(['name' => 'Audit Purchase Part', 'code' => 'AUDIT-PURCHASE', 'cost_price' => 500, 'selling_price' => 700]);
    $account = Account::systemAccount('main');
    $this->post(route('purchases.store'), ['supplier_id' => $supplier->id, 'invoice_number' => 'AUDIT-PUR-001', 'purchase_date' => today()->toDateString(), 'store_id' => $store->id, 'payment_method' => 'Cash', 'account_id' => $account->id, 'paid_amount' => 5000, 'items' => [['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 500, 'selling_price' => 700]]])->assertSessionHasNoErrors();
    $this->post(route('drawings.store'), ['drawing_date' => today()->toDateString(), 'person_name' => 'Owner', 'drawing_type' => 'Owner withdrawal', 'account_id' => $account->id, 'amount' => 2000, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $this->post(route('expenses.store'), ['expense_date' => today()->toDateString(), 'category' => 'Utility', 'description' => 'Audit utility', 'account_id' => $account->id, 'amount' => 3000, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    $technician = Technician::create(['name' => 'Scenario Staff', 'phone' => '0700000097', 'basic_salary' => 60000, 'active' => true]);
    $this->post(route('technicians.salaries.store'), ['technician_id' => $technician->id, 'month' => '2026-09'])->assertSessionHasNoErrors();
    $sheet = TechnicianSalarySheet::latest('id')->firstOrFail();
    $this->post(route('technicians.salaries.finalize', $sheet))->assertSessionHasNoErrors();
    $this->post(route('technicians.salaries.pay', $sheet), ['amount' => 4000, 'account_id' => $account->id, 'payment_method' => 'Cash'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(103000.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(113000.0)
        ->and(app(AccountService::class)->ledgerOpeningBalance('cash', today()->addDay()))->toBe(16000.0);
});

test('populated customer bike and opening due lists render without repair relationships', function () {
    $job = financialAuditJob(0, 1000);
    $this->get(route('customers.bikes.index'))->assertOk()->assertSee($job->intake->bike->number);
    Invoice::create(['number' => 'AUDIT-OPENING', 'invoice_type' => 'Opening Due', 'customer_id' => $job->intake->bike->customer_id, 'subtotal' => 1000, 'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_by' => auth()->id()]);
    $this->get(route('customers.dues'))->assertOk()->assertSeeText('AUDIT-OPENING');
});

test('report searches use real relationships and remain within the selected ledger', function () {
    $job = financialAuditJob(1000, 1000);
    $invoice = app(WorkshopService::class)->invoice($job, 2000, 'Cash');
    ServiceReminder::create(['bike_id' => $job->intake->bike_id, 'job_card_id' => $job->id, 'due_date' => today(), 'status' => 'Pending']);
    foreach (['reports.job-cards', 'reports.spare-parts', 'reports.profit-loss', 'reports.service-reminders', 'reports.main-account', 'reports.expenses', 'reports.drawings'] as $route) {
        $this->get(route($route, ['search' => 'Audit', 'date_range' => 'this_month']))->assertOk();
    }
    $this->get(route('accounts.cash-book.index'))->assertOk()->assertViewHas('totals', fn ($totals) => $totals['credit'] === 2000.0);
    $this->get(route('accounts.daily-closing.index'))->assertOk()->assertViewHas('summary', fn ($summary) => $summary['closing'] === 202000.0);
});

test('opening replacement requires reason and retains one net opening balance', function () {
    $data = ['account_type' => 'main', 'opening_date' => today()->toDateString(), 'amount' => 1000, 'balance_type' => 'credit'];
    $this->post(route('accounts.opening-balances.store'), $data)->assertSessionHasNoErrors();
    $this->post(route('accounts.opening-balances.store'), $data)->assertSessionHasErrors('edit_reason');
    $data['amount'] = 1500;
    $data['edit_reason'] = 'Correct audited opening';
    $this->post(route('accounts.opening-balances.store'), $data)->assertSessionHasNoErrors();
    expect(Account::systemAccount('main')->balance)->toBe(101500.0);
});

test('backup actions use managed files and never falsely report a restore', function () {
    Storage::fake('local');
    Storage::disk('local')->put('database-backups/database-test-1.sql', 'SQL');
    $this->get(route('system-settings.backup-settings'))->assertOk()->assertSeeText('Database SQL')->assertDontSeeText('8.4 MB');
    $this->post(route('system-settings.delete-backup'), ['backup_id' => '../outside.sql'])->assertSessionHasErrors('backup_id');
    expect(Storage::disk('local')->exists('database-backups/database-test-1.sql'))->toBeTrue();
    $this->post(route('system-settings.restore-backup'), ['password' => 'invalid', 'reason' => 'Audit password gate'])->assertSessionHasErrors('password');
    $this->post(route('system-settings.delete-backup'), ['backup_id' => 'database-test-1.sql'])->assertSessionHasNoErrors();
    expect(Storage::disk('local')->exists('database-backups/database-test-1.sql'))->toBeFalse();
});

test('cent rounding and the customer dues endpoint preserve component totals', function () {
    $invoice = app(WorkshopService::class)->invoice(financialAuditJob(0.01, 0.02), 0.01, 'Cash');
    $data = ['amount' => 0.01, 'method' => 'Cash', 'account_id' => Account::systemAccount('main')->id];
    $this->post(route('customers.dues.payment', $invoice), $data)->assertSessionHasNoErrors();
    $this->post(route('customers.dues.payment', $invoice), $data)->assertSessionHasNoErrors();
    expect(Account::systemAccount('spare_parts')->balance)->toBe(100000.01)
        ->and(Account::systemAccount('main')->balance)->toBe(100000.02)
        ->and((float) $invoice->fresh()->due_amount)->toBe(0.0);
});

test('POS JSON retry returns the first sale and parts-only due remains in Spare Account', function () {
    $store = Store::create(['name' => 'POS Audit Store', 'active' => true]);
    $product = Product::create(['name' => 'POS Audit Part', 'code' => 'AUDIT-POS', 'cost_price' => 50, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 5]);
    $data = ['_submission_token' => (string) Str::uuid(), 'store_id' => $store->id, 'invoice_type' => 'Parts Sale', 'paid_amount' => 55, 'tax' => 10, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]]];
    $first = $this->postJson(route('sales.parts.store'), $data)->assertOk()->assertJsonPath('success', true);
    $this->postJson(route('sales.parts.store'), $data)->assertOk()->assertJsonPath('invoice_id', $first->json('invoice_id'));
    expect(Invoice::count())->toBe(1)->and((float) StoreStock::first()->quantity)->toBe(4.0);
    $invoice = Invoice::firstOrFail();
    $this->post(route('customers.dues.payment', $invoice), ['amount' => 55, 'method' => 'Cash', 'account_id' => Account::systemAccount('main')->id])->assertSessionHasNoErrors();
    expect(Account::systemAccount('spare_parts')->balance)->toBe(100110.0)->and(Account::systemAccount('main')->balance)->toBe(100000.0);
});

test('purchase cash refund retains unpaid due and cancellation does not refund twice', function () {
    $supplier = Supplier::create(['name' => 'Refund Supplier', 'active' => true]);
    $store = Store::create(['name' => 'Refund Store', 'active' => true]);
    $product = Product::create(['name' => 'Refund Part', 'code' => 'AUDIT-REFUND', 'cost_price' => 100, 'selling_price' => 150]);
    $this->post(route('purchases.store'), ['supplier_id' => $supplier->id, 'invoice_number' => 'AUDIT-REF-001', 'purchase_date' => today()->toDateString(), 'store_id' => $store->id, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('main')->id, 'paid_amount' => 500, 'items' => [['product_id' => $product->id, 'quantity' => 10, 'cost_price' => 100, 'selling_price' => 150]]])->assertSessionHasNoErrors();
    $purchase = Purchase::firstOrFail();
    $this->post(route('purchases.returns.store'), ['purchase_id' => $purchase->id, 'purchase_item_id' => $purchase->items()->first()->id, 'quantity' => 2, 'refund_type' => 'Cash refund', 'account_id' => Account::systemAccount('spare_parts')->id, 'return_date' => today()->toDateString(), 'reason' => 'Incorrect goods'])->assertSessionHasNoErrors();
    $return = PurchaseReturn::firstOrFail();
    $this->post(route('purchases.returns.approve', $return))->assertSessionHasNoErrors();
    expect((float) $purchase->fresh()->due_amount)->toBe(500.0)
        ->and($supplier->fresh()->balance)->toBe(500.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(99700.0);
    $this->delete(route('purchases.cancel', $purchase), ['reason' => 'Cancel incorrect purchase'])->assertSessionHasNoErrors();
    expect(Account::systemAccount('spare_parts')->balance)->toBe(100000.0)
        ->and($supplier->fresh()->balance)->toBe(0.0)
        ->and((float) StoreStock::first()->quantity)->toBe(0.0);
});
