<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\SaleReturn;
use App\Models\ServiceReminder;
use App\Models\SmsLog;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Supplier;
use App\Models\Technician;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AccountService;
use App\Support\ReportAccounting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->customer = Customer::create(['name' => 'Report Customer', 'phone' => '0771230000']);
    $this->bike = $this->customer->bikes()->create(['number' => 'REP-1234', 'model' => 'Honda']);
    $this->invoice = fn (array $attributes = []) => Invoice::create(array_replace([
        'number' => 'REPORT-'.Str::random(10), 'invoice_type' => 'Job Card Bill', 'customer_id' => $this->customer->id,
        'bike_id' => $this->bike->id, 'subtotal' => 1000, 'total' => 1000, 'paid_amount' => 300, 'hold_amount' => 200,
        'due_amount' => 500, 'status' => 'Partially Paid', 'public_token' => Str::random(48), 'created_by' => auth()->id(),
    ], $attributes));
});

test('every report renders and exports its complete table', function (string $report) {
    $this->get(route('reports.'.$report))->assertOk()->assertSee('id="reportTable"', false);
    $this->get(route('reports.'.$report, ['export_csv' => 1]))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
})->with(['index', 'job-cards', 'spare-parts', 'profit-loss', 'stock', 'purchases', 'expenses', 'drawings', 'due-bills', 'customer-due', 'technicians', 'attendance', 'salary', 'epf', 'etf', 'main-account', 'spare-parts-account', 'bank-account', 'daily-closing', 'service-reminders', 'sms', 'collections', 'supplier-dues', 'sales-returns']);

test('sales filters rows summaries and type breakdown together and shows suggestions inside the form', function () {
    $invoice = ($this->invoice)();
    ($this->invoice)(['customer_id' => null, 'bike_id' => null, 'invoice_type' => 'Parts Sale']);
    foreach (['Quotation', 'Opening Due', 'Opening Balance'] as $type) {
        ($this->invoice)(['invoice_type' => $type]);
    }
    ($this->invoice)(['status' => 'Cancelled']);
    $response = $this->get(route('reports.index', ['bike_id' => $this->bike->id, 'customer_id' => $this->customer->id]));
    $response->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && $rows->first()->id === $invoice->id)
        ->assertViewHas('stats', fn ($s) => $s['invoice_count'] === 1 && (float) $s['total_sales'] === 1000.0 && (float) $s['paid_amount'] === 300.0 && (float) $s['held_amount'] === 200.0 && (float) $s['due_amount'] === 500.0)
        ->assertViewHas('byType', fn ($types) => $types->count() === 1 && (float) $types['Job Card Bill'] === 1000.0)
        ->assertSeeText('Report Customer')->assertSeeText('REP-1234');
    preg_match('/<form method="GET".*?<\/form>/s', $response->getContent(), $form);
    expect($form[0])->toContain('name="bike_id"', 'name="customer_id"', 'tom-select', '0771230000');
});

test('mixed receipts can be filtered by an individual payment method', function () {
    $invoice = ($this->invoice)(['payment_method' => 'Mixed']);
    $invoice->payments()->create(['amount' => 300, 'method' => 'Cash', 'status' => 'Completed', 'paid_at' => now(), 'created_by' => auth()->id()]);
    $this->get(route('reports.index', ['payment_method' => 'Cash']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1);
});

test('quick and intake jobs support customer and bike filtering with legacy invoices', function (bool $quick) {
    $intake = BikeIntake::create(['intake_number' => Str::random(12), 'bike_id' => $this->bike->id, 'complaint' => 'Report', 'created_by' => auth()->id()]);
    $job = JobCard::create(['number' => Str::random(12), 'is_quick_job' => $quick, 'quick_customer_id' => $quick ? $this->customer->id : null, 'quick_bike_id' => $quick ? $this->bike->id : null, 'bike_intake_id' => $quick ? null : $intake->id, 'status' => 'Assigned', 'created_by' => auth()->id()]);
    $invoice = ($this->invoice)(['job_card_id' => $job->id, 'customer_id' => null, 'bike_id' => null]);
    $filters = ['customer_id' => $this->customer->id, 'bike_id' => $this->bike->id];
    $this->get(route('reports.index', $filters))->assertOk()->assertViewHas('rows', fn ($r) => $r->first()->id === $invoice->id)->assertSeeText('Report Customer');
    $this->get(route('reports.job-cards', $filters))->assertOk()->assertViewHas('rows', fn ($r) => $r->first()->id === $job->id)->assertViewHas('stats', fn ($s) => (float) $s['billed_amount'] === 1000.0);
    $this->get(route('reports.customer-due', $filters))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_due'] === 500.0);
})->with([true, false]);

test('due reports apply invoice dates and grouped searches without admitting cancelled or fully paid bills', function () {
    $old = ($this->invoice)(['created_at' => today()->subMonths(2)]);
    ($this->invoice)(['status' => 'Cancelled', 'due_amount' => 9000]);
    ($this->invoice)(['status' => 'Paid', 'paid_amount' => 1000, 'hold_amount' => 0, 'due_amount' => 0]);
    ($this->invoice)(['invoice_type' => 'Quotation', 'due_amount' => 9000]);
    $this->get(route('reports.due-bills', ['search' => 'Report Customer']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1 && $r->first()->id === $old->id)->assertViewHas('stats', fn ($s) => (float) $s['total_due'] === 500.0);
    $this->get(route('reports.due-bills', ['date_range' => 'today']))->assertOk()->assertViewHas('rows', fn ($r) => $r->isEmpty())->assertViewHas('stats', fn ($s) => (float) $s['total_due'] === 0.0);
    $this->get(route('reports.customer-due'))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_due'] === 500.0 && $s['due_customers'] === 1);
});

test('parts sales allocate invoice discounts after line discounts and returns and exclude tax', function () {
    $store = Store::create(['name' => 'Report store']);
    $product = Product::create(['name' => 'Report brake pad', 'code' => 'REP-PAD', 'cost_price' => 40, 'selling_price' => 100]);
    // Two units at 100, less a 20 line discount; one returned. Remaining 90, less 10% invoice discount, plus 8.10 tax.
    $invoice = ($this->invoice)(['invoice_type' => 'Parts Sale', 'subtotal' => 90, 'parts_amount' => 90, 'discount' => 9, 'tax' => 8.10, 'total' => 89.10]);
    $item = $invoice->items()->create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 2, 'returned_quantity' => 1, 'unit_price' => 100, 'cost_price' => 40, 'discount' => 20, 'total' => 180]);
    $this->get(route('reports.spare-parts', ['search' => 'brake']))->assertOk()->assertViewHas('stats', fn ($s) => $s['total_sales'] === 81.0 && $s['items_sold'] === 1.0 && $s['total_cost'] === 40.0 && $s['gross_profit'] === 41.0)->assertSeeText('Rs. 81.00');
    $this->get(route('reports.spare-parts', ['search' => 'not-found']))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_sales'] === 0.0);
    $this->get(route('reports.profit-loss'))->assertOk()->assertViewHas('totalRevenue', 81)->assertViewHas('partsCost', 40)->assertViewHas('netProfit', 41);
});

test('profit loss allocates discounted job revenue and excludes opening balances quotations and drawings from profit', function () {
    ($this->invoice)(['subtotal' => 1000, 'parts_amount' => 600, 'discount' => 100, 'tax' => 90, 'total' => 990]);
    foreach (['Opening Due', 'Opening Balance', 'Quotation'] as $type) {
        ($this->invoice)(['invoice_type' => $type, 'total' => 9000]);
    }
    $account = Account::systemAccount('main');
    Expense::create(['expense_date' => today(), 'category' => 'Rent', 'account_id' => $account->id, 'payment_method' => 'Cash', 'amount' => 100, 'created_by' => auth()->id()]);
    Drawing::create(['drawing_date' => today(), 'person_name' => 'Owner', 'drawing_type' => 'Cash', 'account_id' => $account->id, 'amount' => 50, 'created_by' => auth()->id()]);
    $this->get(route('reports.profit-loss'))->assertOk()->assertViewHas('serviceIncome', 360)->assertViewHas('partsSales', 540)->assertViewHas('otherIncome', 0)->assertViewHas('netProfit', 800)->assertViewHas('totalDrawings', 50);
});

test('stock totals follow product rack status and store filters and use selling_price', function () {
    $store = Store::create(['name' => 'Report store']);
    $other = Store::create(['name' => 'Other store']);
    foreach ([['Selected', 'A.1', 2], ['Other', 'B', 10]] as [$name, $rack, $quantity]) {
        $product = Product::create(['name' => $name, 'code' => $name, 'rack_number' => $rack, 'minimum_stock' => 3, 'cost_price' => 40, 'selling_price' => 100]);
        StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => $quantity]);
        StoreStock::create(['store_id' => $other->id, 'product_id' => $product->id, 'quantity' => 20]);
    }
    $this->get(route('reports.stock', ['store_id' => $store->id, 'racks' => ['A'], 'status' => 'low']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1)->assertViewHas('stats', fn ($s) => $s['product_count'] === 1 && $s['value_cost'] === 80.0 && $s['value_selling'] === 200.0)->assertDontSee('name="from"', false);
});

test('purchase search cannot bypass dates status or supplier and totals are net of returns', function () {
    $store = Store::create(['name' => 'Purchase report store']);
    $supplier = Supplier::create(['name' => 'Report Supplier', 'phone' => '0772220000']);
    $make = fn ($extra) => Purchase::create(array_replace(['number' => Str::random(12), 'invoice_number' => Str::random(12), 'supplier_id' => $supplier->id, 'store_id' => $store->id, 'purchase_date' => today(), 'subtotal' => 1000, 'total_amount' => 1000, 'returned_amount' => 200, 'paid_amount' => 300, 'hold_amount' => 100, 'due_amount' => 400, 'created_by' => auth()->id()], $extra));
    $make([]);
    $make(['purchase_date' => today()->subMonth()]);
    $make(['status' => 'Cancelled']);
    $this->get(route('reports.purchases', ['search' => 'Report Supplier']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1)->assertViewHas('stats', fn ($s) => (float) $s['total_amount'] === 800.0 && (float) $s['due_amount'] === 400.0);
    $this->get(route('reports.supplier-dues'))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['due_amount'] === 800.0);
});

test('expenses and drawings exclude cancelled rows and respect filters in summary totals', function () {
    $account = Account::systemAccount('main');
    foreach ([['Rent', 100, 'Active'], ['Utilities', 200, 'Active'], ['Rent', 999, 'Cancelled']] as [$category, $amount, $status]) {
        Expense::create(['expense_date' => today(), 'category' => $category, 'vendor' => 'Report Vendor', 'note' => 'Report note', 'account_id' => $account->id, 'payment_method' => 'Cash', 'amount' => $amount, 'status' => $status, 'created_by' => auth()->id()]);
        Drawing::create(['drawing_date' => today(), 'person_name' => $category, 'drawing_type' => 'Cash', 'note' => 'Report note', 'account_id' => $account->id, 'amount' => $amount, 'status' => $status, 'created_by' => auth()->id()]);
    }
    $this->get(route('reports.expenses', ['category' => 'Rent', 'search' => 'Report']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1)->assertViewHas('stats', fn ($s) => (float) $s['total_amount'] === 100.0)->assertViewHas('byCategory', fn ($c) => $c->count() === 1);
    $this->get(route('reports.drawings', ['search' => 'Rent']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1)->assertViewHas('stats', fn ($s) => (float) $s['total_amount'] === 100.0);
});

test('account period balances include history and reversals but filtered totals use visible transactions', function () {
    $account = Account::systemAccount('main');
    $account->update(['opening_balance' => 100]);
    $service = app(AccountService::class);
    $service->recordTransaction('main', 'income', 50, 'Prior receipt', transactionDate: today()->subDay());
    $transaction = $service->recordTransaction('main', 'income', 30, 'Reversed receipt');
    $service->reverseTransaction($transaction, 'Report reversal');
    $service->recordTransaction('main', 'expense', 20, 'Report expense');
    $service->recordTransaction('main', 'income', 900, 'Future receipt', transactionDate: today()->addDay());
    $this->get(route('reports.main-account', ['type' => 'expense']))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['filtered_in_amount'] === 0.0 && (float) $s['filtered_out_amount'] === 20.0 && (float) $s['opening_balance'] === 150.0 && (float) $s['closing_balance'] === 130.0);
});

test('daily closing summary matches table without adding mirrored bank and cash expenses', function () {
    DailyClosing::create(['closing_date' => today(), 'main_expense' => 100, 'spare_parts_expense' => 200, 'bank_expense' => 150, 'cash_expense' => 150, 'closed_by' => auth()->id()]);
    $this->get(route('reports.daily-closing'))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_expenses'] === 300.0)->assertSeeText('Rs. 300.00');
});

test('reminder and SMS totals use the same filters as displayed records', function () {
    foreach (['Pending', 'Sent'] as $status) {
        ServiceReminder::create(['bike_id' => $this->bike->id, 'due_date' => today(), 'status' => $status]);
    }
    $this->get(route('reports.service-reminders', ['status' => 'Sent', 'customer_id' => $this->customer->id]))->assertOk()->assertViewHas('stats', fn ($s) => $s['reminder_count'] === 1 && $s['sent'] === 1 && $s['upcoming'] === 0);
    foreach (['sent', 'failed'] as $status) {
        SmsLog::create(['phone' => '0771230000', 'message' => 'Report', 'module' => 'Report', 'status' => $status]);
    }
    $this->get(route('reports.sms', ['status' => 'sent', 'module' => 'Report']))->assertOk()->assertViewHas('stats', fn ($s) => $s['message_count'] === 1 && $s['failed'] === 0);
});

test('collections use receipt dates and separate held cheques refunds and salary offsets', function () {
    $invoice = ($this->invoice)(['created_at' => today()->subMonth()]);
    foreach ([[100, 20, 'Cash', 'Completed'], [200, 0, 'Cheque', 'Hold'], [50, 0, 'Salary Deduction', 'Completed'], [999, 0, 'Cash', 'Cancelled']] as [$amount, $refunded, $method, $status]) {
        $invoice->payments()->create(['amount' => $amount, 'refunded_amount' => $refunded, 'method' => $method, 'status' => $status, 'paid_at' => now(), 'created_by' => auth()->id()]);
    }
    $this->get(route('reports.collections'))->assertOk()->assertViewHas('stats', fn ($s) => $s['receipt_count'] === 3 && (float) $s['collected_amount'] === 80.0 && (float) $s['held_amount'] === 200.0 && (float) $s['offset_amount'] === 50.0);
});

test('return report filters by return date and customer and sums credits and refunds separately', function () {
    $invoice = ($this->invoice)(['created_at' => today()->subMonth()]);
    SaleReturn::create(['invoice_id' => $invoice->id, 'amount' => 200, 'refund_amount' => 50, 'items' => [], 'reason' => 'Report return']);
    $this->get(route('reports.sales-returns', ['customer_id' => $this->customer->id]))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['credit_amount'] === 200.0 && (float) $s['refund_amount'] === 50.0);
});

test('CSV and print include all filtered records even when requested from page two', function () {
    foreach (range(1, 31) as $i) {
        ($this->invoice)(['number' => 'EXPORT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
    }
    ($this->invoice)(['number' => 'EXCLUDED', 'customer_id' => null]);
    $filters = ['customer_id' => $this->customer->id, 'page' => 2];
    $this->get(route('reports.index', $filters + ['print' => 1]))->assertOk()->assertViewHas('rows', fn ($r) => $r->count() === 31);
    $response = $this->get(route('reports.index', $filters + ['export_csv' => 1]))->assertOk();
    $csv = $response->streamedContent();
    expect($csv)->toContain('EXPORT-001', 'EXPORT-031')->not->toContain('EXCLUDED');
});

test('last month is the previous calendar month even on the 31st and quick tabs clear custom dates', function () {
    $this->travelTo(Carbon::parse('2026-03-31 12:00:00'));
    $this->get(route('reports.index', ['date_range' => 'last_month']))->assertOk()->assertViewHas('from', fn ($date) => $date->toDateString() === '2026-02-01')->assertViewHas('to', fn ($date) => $date->toDateString() === '2026-02-28');
    $response = $this->get(route('reports.index', ['from' => '2026-01-01', 'to' => '2026-01-02', 'customer_id' => $this->customer->id]))->assertOk();
    preg_match('/href="([^"]*date_range=today[^"]*)"/', $response->getContent(), $link);
    expect(html_entity_decode($link[1]))->not->toContain('from=', 'to=')->toContain('customer_id=');
});

test('archiving a customer preserves their outstanding balances in reports', function () {
    ($this->invoice)();
    $this->customer->delete();
    $this->get(route('reports.customer-due'))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_due'] === 500.0)->assertSeeText('Report Customer');
});

test('parts allocation preserves the last cent across filtered product lines', function () {
    $store = Store::create(['name' => 'Penny Store']);
    $invoice = ($this->invoice)(['invoice_type' => 'Parts Sale', 'subtotal' => 3, 'parts_amount' => 3, 'discount' => 2, 'total' => 1]);
    foreach (['First', 'Second', 'Last'] as $name) {
        $product = Product::create(['code' => $name, 'name' => $name, 'cost_price' => 0, 'selling_price' => 1]);
        $invoice->items()->create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 1, 'unit_price' => 1, 'cost_price' => 0, 'total' => 1]);
    }
    $this->get(route('reports.spare-parts'))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_sales'] === 1.0);
    $this->get(route('reports.spare-parts', ['search' => 'Second']))->assertOk()->assertViewHas('stats', fn ($s) => (float) $s['total_sales'] === 0.34);
});

test('reminder delivery counts the SMS delivery field independently of reminder completion', function () {
    ServiceReminder::create(['bike_id' => $this->bike->id, 'due_date' => today(), 'status' => 'Pending', 'sms_status' => 'sent']);
    $this->get(route('reports.service-reminders', ['sms_status' => 'sent']))->assertOk()->assertViewHas('stats', fn ($s) => $s['sent'] === 1 && $s['upcoming'] === 1);
});

test('finalized salary expenses include employer contributions and exclude drafts', function () {
    $staff = Technician::create(['name' => 'Report Staff', 'phone' => '0771000000']);
    foreach (['Finalized', 'Draft'] as $i => $status) {
        $staff = Technician::create(['name' => 'Salary Staff '.$i, 'phone' => '077200000'.$i]);
        TechnicianSalarySheet::create(['number' => 'REPORT-SALARY-'.$i, 'technician_id' => $staff->id, 'period_start' => today()->startOfMonth(), 'period_end' => today(), 'basic_salary' => 1000, 'gross_salary' => 1000, 'net_payable' => 920, 'epf_company' => 120, 'etf_company' => 30, 'status' => $status, 'created_by' => auth()->id()]);
    }
    $this->get(route('reports.profit-loss'))->assertOk()->assertViewHas('totalExpenses', 1150);
    $this->get(route('reports.salary'))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1);
    $this->get(route('reports.salary', ['status' => 'Draft']))->assertOk()->assertViewHas('rows', fn ($r) => $r->total() === 1);
});

test('report exports and print enforce their permissions on the backend', function () {
    $role = Role::create(['name' => 'Report viewer', 'permissions' => ['reports.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('reports.index'))->assertOk();
    $this->get(route('reports.index', ['export_csv' => 1]))->assertForbidden();
    $this->get(route('reports.index', ['print' => 1]))->assertForbidden();
});

test('heavily discounted invoices never allocate negative parts revenue to the final line', function () {
    $store = Store::create(['name' => 'Discount Store']);
    $product = Product::create(['name' => 'Discount Part', 'code' => 'DISC-REPORT', 'cost_price' => 0, 'selling_price' => 1]);
    $invoice = ($this->invoice)(['invoice_type' => 'Parts Sale', 'subtotal' => 100, 'parts_amount' => 100, 'discount' => 99.49, 'total' => 0.51]);
    foreach (range(1, 100) as $index) {
        $invoice->items()->create(['product_id' => $product->id, 'store_id' => $store->id, 'quantity' => 1, 'unit_price' => 1, 'cost_price' => 0, 'total' => 1]);
    }
    $items = $invoice->items()->with('invoice.items')->get();
    $amounts = $items->map(fn ($item) => ReportAccounting::itemRevenue($item));
    expect($amounts->min())->toBeGreaterThanOrEqual(0)->and(round($amounts->sum(), 2))->toBe(0.51);
});
