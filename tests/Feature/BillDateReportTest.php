<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Product;
use App\Models\Role;
use App\Models\Store;
use App\Models\StoreStock;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use App\Models\User;
use App\Support\CashBook;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->customer = Customer::create(['name' => 'Date Customer', 'phone' => '0772234455']);
    $this->bike = $this->customer->bikes()->create(['number' => 'DATE-100', 'model' => 'Dio']);
    $intake = BikeIntake::create(['intake_number' => 'IN-DATE', 'bike_id' => $this->bike->id, 'complaint' => 'Repair', 'created_by' => auth()->id()]);
    $this->job = JobCard::create(['number' => 'JOB-DATE', 'bike_intake_id' => $intake->id, 'status' => 'Received', 'priority' => 'Normal', 'service_total' => 600, 'parts_total' => 400, 'created_by' => auth()->id()]);
    $this->billDate = today()->subDays(5)->toDateString();
    $this->paidDate = today()->subDays(2)->toDateString();
    $this->billing = ['bill_date' => $this->billDate, 'paid_at' => $this->paidDate, 'include_previous_balance' => false, 'payments' => [['method' => 'Cash', 'amount' => 1000, 'account_id' => Account::systemAccount('main')->id]]];
    $this->details = ['bill_date' => $this->billDate, 'reason' => 'Correct original bill details'];
    $this->dateFilter = fn ($date) => ['from' => $date, 'to' => $date];
});

test('billing keeps bill payment and audit dates independent across reports', function () {
    $this->post(route('jobs.invoice', $this->job), $this->billing)->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $payment = $invoice->payments()->firstOrFail();
    expect($invoice->bill_date->toDateString())->toBe($this->billDate)->and($invoice->created_at->isToday())->toBeTrue()
        ->and($payment->paid_at->toDateString())->toBe($this->paidDate)->and($payment->created_at->isToday())->toBeTrue();
    expect(AccountTransaction::where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->pluck('transaction_date')->map(fn ($date) => substr($date, 0, 10))->unique()->all())->toBe([$this->paidDate]);
    $sales = $this->get(route('reports.index', ($this->dateFilter)($this->billDate)))->assertOk();
    expect((float) $sales->viewData('stats')['total_sales'])->toBe(1000.0);
    expect((float) $this->get(route('reports.index'))->assertOk()->viewData('stats')['total_sales'])->toBe(0.0);
    $collections = $this->get(route('reports.collections', ($this->dateFilter)($this->paidDate)))->assertOk();
    expect((float) $collections->viewData('stats')['collected_amount'])->toBe(1000.0);
    expect((float) $this->get(route('reports.collections'))->assertOk()->viewData('stats')['collected_amount'])->toBe(0.0);
    $cash = $this->get(route('reports.cash-book', ($this->dateFilter)($this->paidDate)))->assertOk();
    expect((float) $cash->viewData('closing'))->toBe(1000.0)->and((float) $cash->viewData('opening'))->toBe(0.0);
    $todayCash = $this->get(route('reports.cash-book'))->assertOk();
    expect((float) $todayCash->viewData('opening'))->toBe(1000.0)->and($todayCash->viewData('rows')->count())->toBe(0);
    $this->get(route('reports.collections', ($this->dateFilter)($this->paidDate) + ['print' => 1, 'preview' => 1]))->assertOk()->assertSeeText($payment->number);
    expect($this->get(route('reports.collections', ['export_csv' => 1]))->assertOk()->streamedContent())->not->toContain($payment->number);
});

test('bill date corrections keep the existing bike and payment dates', function () {
    $this->post(route('jobs.invoice', $this->job), $this->billing)->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $newDate = today()->subDays(6)->toDateString();
    $this->put(route('jobs.bill-details', $this->job), array_replace($this->details, ['bill_date' => $newDate]))->assertSessionHasNoErrors();
    expect($this->job->fresh()->bike->id)->toBe($this->bike->id)
        ->and($invoice->fresh()->bike_id)->toBe($this->bike->id)->and($invoice->fresh()->bill_date->toDateString())->toBe($newDate)
        ->and($invoice->fresh()->created_at->isToday())->toBeTrue()->and($invoice->payments()->firstOrFail()->paid_at->toDateString())->toBe($this->paidDate)
        ->and(AuditLog::where('event', 'bill_details_updated')->exists())->toBeTrue();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Edit bill date')->assertSeeText('Edit bike number');
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()->assertSeeText($this->bike->number)->assertSeeText(Carbon::parse($newDate)->format('d M Y'));
    expect($this->get(route('reports.index', ($this->dateFilter)($newDate) + ['bike_id' => $this->bike->id]))->assertOk()->viewData('rows')->total())->toBe(1);
    expect($this->get(route('reports.index', ($this->dateFilter)($this->billDate)))->assertOk()->viewData('rows')->total())->toBe(0);
});

test('bill corrections require edit permission and a valid date and reason', function () {
    $this->put(route('jobs.bill-details', $this->job), array_replace($this->details, ['bill_date' => today()->addDay()->toDateString()]))->assertSessionHasErrors('bill_date');
    $this->put(route('jobs.bill-details', $this->job), array_replace($this->details, ['reason' => '']))->assertSessionHasErrors('reason');
    $role = Role::create(['name' => 'Read only dates', 'permissions' => ['job_card.view', 'sales.view_invoices']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->put(route('jobs.bill-details', $this->job), $this->details)->assertForbidden();
    $this->get(route('jobs.show', $this->job))->assertOk()->assertDontSeeText('Save bill details');
});

test('correcting a backdated receipt removes its old period and never posts a cash reversal today', function () {
    $this->post(route('jobs.invoice', $this->job), $this->billing)->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $payment = $invoice->payments()->firstOrFail();
    $replacementDate = today()->subDay()->toDateString();
    $this->put(route('jobs.payments.update', [$this->job, $payment]), ['reason' => 'Correct selected receipt date', 'method' => 'Cash', 'amount' => 500, 'account_id' => Account::systemAccount('main')->id, 'paid_at' => $replacementDate, 'include_previous_balance' => false])->assertSessionHasNoErrors();
    $net = fn ($date) => round((float) CashBook::query()->whereDate('transaction_date', $date)->sum(DB::raw('credit - debit')), 2);
    expect($net($this->paidDate))->toBe(0.0)->and($net($replacementDate))->toBe(500.0)->and($net(today()))->toBe(0.0);
    expect((float) $this->get(route('reports.collections', ($this->dateFilter)($this->paidDate)))->assertOk()->viewData('stats')['collected_amount'])->toBe(0.0);
    expect((float) $this->get(route('reports.collections', ($this->dateFilter)($replacementDate)))->assertOk()->viewData('stats')['collected_amount'])->toBe(500.0);
});

test('legacy invoice dates fall back to creation and standalone bills can be corrected', function () {
    $invoice = Invoice::create(['number' => 'LEGACY-DATE', 'invoice_type' => 'Parts Sale', 'customer_id' => $this->customer->id, 'bike_id' => $this->bike->id, 'subtotal' => 200, 'parts_amount' => 200, 'total' => 200, 'due_amount' => 200, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_by' => auth()->id()]);
    expect($invoice->bill_date->isToday())->toBeTrue();
    $this->put(route('sales.invoices.details', $invoice), $this->details)->assertSessionHasNoErrors();
    expect($invoice->fresh()->bill_date->toDateString())->toBe($this->billDate)->and($invoice->fresh()->bike_id)->toBe($this->bike->id);
    expect((float) $this->get(route('reports.index', ($this->dateFilter)($this->billDate)))->assertOk()->viewData('stats')['total_sales'])->toBe(200.0);
});

test('counter sales use the selected bill date and payment date in reports and payment lists', function () {
    $store = Store::create(['name' => 'Date Store', 'active' => true]);
    $product = Product::create(['code' => 'DATE-PART', 'name' => 'Date Part', 'cost_price' => 50, 'selling_price' => 100, 'active' => true]);
    StoreStock::create(['store_id' => $store->id, 'product_id' => $product->id, 'quantity' => 10]);
    $this->post(route('sales.parts.store'), ['customer_mode' => 'existing', 'customer_id' => $this->customer->id, 'store_id' => $store->id, 'bill_date' => $this->billDate, 'paid_at' => $this->paidDate, 'paid_amount' => 100, 'payment_method' => 'Cash', 'account_id' => Account::systemAccount('spare_parts')->id, 'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]]])->assertSessionHasNoErrors();
    $invoice = Invoice::firstOrFail();
    $receipt = $invoice->payments()->firstOrFail();
    expect($invoice->bill_date->toDateString())->toBe($this->billDate)->and($receipt->paid_at->toDateString())->toBe($this->paidDate);
    expect((float) $this->get(route('reports.spare-parts', ($this->dateFilter)($this->billDate)))->assertOk()->viewData('stats')['total_sales'])->toBe(100.0);
    expect((float) $this->get(route('reports.spare-parts'))->assertOk()->viewData('stats')['total_sales'])->toBe(0.0);
    $this->get(route('sales.payments', ($this->dateFilter)($this->paidDate)))->assertOk()->assertSeeText($receipt->number);
    $this->get(route('sales.payments', ($this->dateFilter)(today()->toDateString())))->assertOk()->assertDontSeeText($receipt->number);
    $this->get(route('sales.invoices', ($this->dateFilter)($this->billDate)))->assertOk()->assertSeeText($invoice->number);
    $this->get(route('sales.invoices', ($this->dateFilter)(today()->toDateString())))->assertOk()->assertDontSeeText($invoice->number);
});

test('bill date changes move pending commissions and protect paid commission periods', function () {
    $this->post(route('jobs.invoice', $this->job), $this->billing)->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $staff = Technician::create(['name' => 'Date Staff', 'phone' => '0776664455', 'active' => true]);
    $commission = TechnicianCommission::create(['technician_id' => $staff->id, 'job_card_id' => $this->job->id, 'invoice_id' => $invoice->id, 'commission_type' => 'Job Card', 'percentage' => 10, 'base_amount' => 1000, 'amount' => 100, 'earned_date' => $this->billDate, 'status' => 'Pending', 'created_by' => auth()->id()]);
    $newDate = today()->subDays(4)->toDateString();
    $this->put(route('jobs.bill-details', $this->job), array_replace($this->details, ['bill_date' => $newDate]))->assertSessionHasNoErrors();
    expect($commission->fresh()->earned_date->toDateString())->toBe($newDate);
    $commission->update(['status' => 'Paid']);
    $this->put(route('jobs.bill-details', $this->job), $this->details)->assertSessionHasErrors('bill_date');
    expect($invoice->fresh()->bill_date->toDateString())->toBe($newDate);
});

test('invalid selected dates are rejected before generating a bill or receipt', function ($field) {
    $this->post(route('jobs.invoice', $this->job), array_replace($this->billing, [$field => today()->addDay()->toDateString()]))->assertSessionHasErrors($field);
    expect(Invoice::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
})->with(['bill_date', 'paid_at']);

test('standard due dates follow the selected bill date while custom due dates stay explicit', function () {
    $billing = $this->billing;
    $billing['payments'][0]['amount'] = 300;
    $this->post(route('jobs.invoice', $this->job), $billing)->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    expect($invoice->due_date->toDateString())->toBe(Carbon::parse($this->billDate)->addDays(30)->toDateString());
    $newDate = today()->subDays(7)->toDateString();
    $this->put(route('jobs.bill-details', $this->job), array_replace($this->details, ['bill_date' => $newDate]))->assertSessionHasNoErrors();
    expect($invoice->fresh()->due_date->toDateString())->toBe(Carbon::parse($newDate)->addDays(30)->toDateString());
    $custom = today()->addDays(45)->toDateString();
    $invoice->refresh()->update(['due_date' => $custom]);
    $this->put(route('jobs.bill-details', $this->job), $this->details)->assertSessionHasNoErrors();
    expect($invoice->fresh()->due_date->toDateString())->toBe($custom);
});
