<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->travelTo(Carbon::parse('2026-10-28 15:00:00'));
    Account::ensureSystemAccounts();
    Account::systemAccount('main')->update(['opening_balance' => 1000]);
    Account::systemAccount('spare_parts')->update(['opening_balance' => 9000]);
    $this->entry = fn (string $type, string $action, float $amount, string $description, string $method = 'Cash', mixed $date = null) => app(AccountService::class)->recordTransaction($type, $action, $amount, $description, paymentMethod: $method, transactionDate: $date ?? now());
});

test('daily cash book includes every cash entry in insertion order with main opening and closing balances', function () {
    ($this->entry)('main', 'income', 200, 'Earlier cash', date: today()->subDay());
    ($this->entry)('main', 'income', 600, 'Earlier card', 'Card', today()->subDay());
    $sale = ($this->entry)('main', 'income', 100, 'Cash sale entered first', date: today()->setTime(12, 0));
    $expense = ($this->entry)('main', 'expense', 40, 'Cash expense backdated today', date: today()->startOfDay());
    $parts = ($this->entry)('spare_parts', 'income', 250, 'Spare cash sale');
    ($this->entry)('spare_parts', 'drawing', 25, 'Owner cash drawing');
    ($this->entry)('main', 'income', 999, 'Card sale excluded', 'Card');
    ($this->entry)('bank', 'income', 999, 'Bank copy excluded', 'Card');
    ($this->entry)('main', 'income', 999, 'Future cash excluded', date: today()->addDay());
    $response = $this->get(route('reports.cash-book'))->assertOk()
        ->assertViewHas('mainOpening', 1000)->assertViewHas('broughtForward', 200)->assertViewHas('opening', 1200)->assertViewHas('closing', 1485)
        ->assertViewHas('stats', fn ($s) => $s['entry_count'] === 4 && $s['cash_in_amount'] === 350 && $s['cash_out_amount'] === 65)
        ->assertViewHas('rows', fn ($r) => $r->pluck('id')->take(3)->all() === [$sale->id, $expense->id, $parts->id] && $r->pluck('cash_book_balance')->all() === [1300, 1260, 1510, 1485])
        ->assertSeeInOrder(['Main Account opening carried forward', 'Cash sale entered first', 'Cash expense backdated today', 'Spare cash sale', 'Owner cash drawing', 'Closing cash balance'])
        ->assertDontSeeText('Card sale excluded')->assertDontSeeText('Future cash excluded');
});

test('cash book includes main opening setup entries and compensating reversals once', function () {
    $service = app(AccountService::class);
    $opening = $service->recordTransaction('main', 'opening_balance', 400, 'Opening balance setup', transactionDate: today()->subDay(), category: 'opening_balance');
    ($this->entry)('main', 'income', 100, 'Previous cash', date: today()->subDay());
    $service->reverseTransaction($opening, 'Replace main opening amount');
    $service->recordTransaction('main', 'opening_balance', 600, 'Opening balance setup', category: 'opening_balance');
    $service->recordTransaction('bank', 'opening_balance', 9000, 'Bank opening excluded', transactionDate: today()->subDay(), category: 'opening_balance');
    $this->get(route('reports.cash-book'))->assertOk()->assertViewHas('mainOpening', 1400)->assertViewHas('opening', 1500)->assertViewHas('closing', 1700)
        ->assertViewHas('stats', fn ($s) => $s['entry_count'] === 2 && $s['cash_in_amount'] === 600 && $s['cash_out_amount'] === 400);
});

test('cash opening posted through cash book is not counted again as an earlier cash receipt', function () {
    app(AccountService::class)->recordTransaction('cash', 'opening_balance', 300, 'Opening balance setup', accountId: Account::systemAccount('main')->id, transactionDate: today()->subDay(), category: 'opening_balance');
    $this->get(route('reports.cash-book'))->assertOk()->assertViewHas('mainOpening', 1300)->assertViewHas('broughtForward', 0)->assertViewHas('opening', 1300)->assertViewHas('closing', 1300);
});

test('cash book retains reversed entries and shows their compensating entry in entry order', function () {
    $sale = ($this->entry)('main', 'income', 100, 'Cash sale to reverse');
    app(AccountService::class)->reverseTransaction($sale, 'Customer cancelled receipt');
    $this->get(route('reports.cash-book'))->assertOk()->assertViewHas('closing', 1000)
        ->assertViewHas('rows', fn ($r) => $r->count() === 2 && $r->pluck('cash_book_balance')->all() === [1100, 1000]);
});

test('cash book excludes cancelled ledger entries and keeps other shops separate', function () {
    $cancelled = ($this->entry)('main', 'income', 100, 'Cancelled entry excluded');
    $cancelled->update(['status' => 'cancelled']);
    Account::ensureSystemAccounts('helmet');
    $helmet = Account::withoutGlobalScopes()->where('code', 'HELMET_MAIN')->firstOrFail();
    $helmet->update(['opening_balance' => 2500]);
    AccountTransaction::create(['transaction_no' => 'HELMET-ENTRY', 'transaction_date' => now(), 'account_type' => 'main', 'account_id' => $helmet->id, 'transaction_type' => 'income', 'credit' => 90, 'amount' => 90, 'payment_method' => 'Cash', 'description' => 'Helmet cash', 'status' => 'active', 'created_by' => auth()->id()]);
    $this->get(route('reports.cash-book'))->assertOk()->assertViewHas('closing', 1000)->assertViewHas('rows', fn ($r) => $r->isEmpty());
    $this->withSession(['shop_mode' => 'helmet'])->get(route('reports.cash-book'))->assertOk()->assertViewHas('opening', 2500)->assertViewHas('closing', 2590)->assertSeeText('Helmet cash');
});

test('cash book filters preserve complete running balances and show matching totals separately', function () {
    ($this->entry)('main', 'income', 150, 'Visible receipt');
    ($this->entry)('main', 'expense', 50, 'Hidden cash expense');
    ($this->entry)('spare_parts', 'income', 100, 'Visible receipt parts');
    $this->get(route('reports.cash-book', ['search' => 'Visible receipt', 'direction' => 'in']))->assertOk()->assertViewHas('opening', 1000)->assertViewHas('closing', 1200)
        ->assertViewHas('rows', fn ($r) => $r->pluck('cash_book_balance')->all() === [1150, 1200])
        ->assertViewHas('stats', fn ($s) => $s['matching_entry_count'] === 2 && (float) $s['matching_in_amount'] === 250.0 && $s['cash_out_amount'] === 50)
        ->assertSeeText('including entries hidden by these filters');
});

test('cash book supports entry type actor and custom date filters', function () {
    $user = User::factory()->create();
    $receipt = ($this->entry)('main', 'income', 100, 'Selected actor receipt', date: '2026-10-20');
    $receipt->update(['created_by' => $user->id]);
    ($this->entry)('main', 'income', 200, 'Other actor receipt', date: '2026-10-20');
    $this->get(route('reports.cash-book', ['from' => '2026-10-20', 'to' => '2026-10-20', 'type' => 'income', 'created_by' => $user->id]))->assertOk()
        ->assertViewHas('rows', fn ($r) => $r->count() === 1 && $r->first()->id === $receipt->id)->assertViewHas('closing', 1300);
});

test('cash book records both cash shares of a repair receipt without a bank duplicate', function () {
    $customer = Customer::create(['name' => 'Cash Customer', 'phone' => '0770000001']);
    $invoice = Invoice::create(['number' => 'CASH-REPAIR', 'invoice_type' => 'Job Card Bill', 'customer_id' => $customer->id, 'subtotal' => 1000, 'total' => 1000, 'parts_amount' => 600, 'paid_amount' => 1000, 'due_amount' => 0, 'status' => 'Paid', 'public_token' => Str::random(48), 'created_by' => auth()->id()]);
    $payment = $invoice->payments()->create(['number' => 'CASH-RECEIPT', 'amount' => 1000, 'method' => 'Cash', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    app(AccountService::class)->receiveInvoicePayment($invoice, $payment);
    $this->get(route('reports.cash-book'))->assertOk()->assertSeeText('CASH-RECEIPT')->assertViewHas('closing', 2000)
        ->assertViewHas('stats', fn ($s) => $s['entry_count'] === 2 && $s['cash_in_amount'] === 1000);
});

test('cash book print and CSV include all entries and opening and closing table rows', function () {
    foreach (range(1, 30) as $index) {
        ($this->entry)('main', 'income', 1, 'CASH-ROW-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT));
    }
    $this->get(route('reports.cash-book', ['print' => 1, 'page' => 2]))->assertOk()->assertViewHas('rows', fn ($r) => $r->count() === 30)->assertSeeText('1,030.00');
    $csv = $this->get(route('reports.cash-book', ['export_csv' => 1, 'page' => 2]))->assertOk()->streamedContent();
    expect($csv)->toContain('Opening', 'CASH-ROW-01', 'CASH-ROW-30', 'Period totals', 'Closing', '1,030.00');
});

test('cash book requires report and export permissions', function () {
    $role = Role::create(['name' => 'Cash report viewer', 'permissions' => ['reports.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('reports.cash-book'))->assertOk();
    $this->get(route('reports.cash-book', ['export_csv' => 1]))->assertForbidden();
    $this->get(route('reports.cash-book', ['print' => 1]))->assertForbidden();
    $role->update(['permissions' => ['dashboard.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('reports.cash-book'))->assertForbidden();
});

test('main opening setup dated at the start of today appears in the top balance rather than a duplicate cash receipt', function () {
    app(AccountService::class)->recordTransaction('main', 'opening_balance', 600, 'Opening balance setup', transactionDate: today()->toDateString(), category: 'opening_balance');
    $this->get(route('reports.cash-book'))->assertOk()->assertViewHas('mainOpening', 1600)->assertViewHas('opening', 1600)->assertViewHas('closing', 1600)
        ->assertViewHas('stats', fn ($s) => $s['entry_count'] === 0 && $s['cash_in_amount'] === 0);
    $this->get(route('reports.cash-book', ['from' => today()->subDay()->toDateString(), 'to' => today()->toDateString()]))->assertOk()
        ->assertViewHas('opening', 1000)->assertViewHas('closing', 1600)->assertViewHas('stats', fn ($s) => $s['entry_count'] === 1 && $s['cash_in_amount'] === 600);
});
