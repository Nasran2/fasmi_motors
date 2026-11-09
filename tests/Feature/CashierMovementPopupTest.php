<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Bike;
use App\Models\BikeIntake;
use App\Models\CashierSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountService;
use App\Services\CashierService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->accounts = app(AccountService::class);
    $this->movements = fn ($side = 'credit', $type = 'main', $date = null, $page = 1) => route('cashier.movements', [
        'date' => $date ?? today()->toDateString(), 'account' => $type, 'side' => $side, 'page' => $page,
    ]);
    $this->invoice = Invoice::create(['number' => 'POPUP-INVOICE', 'invoice_type' => 'Job Card Bill', 'bill_date' => today(),
        'subtotal' => 1000, 'total' => 1000, 'public_token' => 'popup-invoice', 'created_by' => auth()->id()]);
});

it('shows the invoice job and bike for each receipt with separate new-tab document links', function ($quick) {
    $bike = Bike::create(['number' => 'BIJ-4321', 'model' => 'Test bike', 'customer_id' => Customer::create(['name' => 'Popup Customer', 'phone' => '0771234567'])->id]);
    $job = JobCard::create(['number' => 'POPUP-JOB', 'is_quick_job' => $quick,
        'quick_bike_id' => $quick ? $bike->id : null,
        'bike_intake_id' => $quick ? null : BikeIntake::create(['intake_number' => 'POPUP-INTAKE', 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => auth()->id()])->id,
        'status' => 'Assigned', 'created_by' => auth()->id()]);
    $this->invoice->update(['job_card_id' => $job->id]);
    $payment = $this->invoice->payments()->create(['number' => 'POPUP-RECEIPT', 'amount' => 125, 'method' => 'Cash', 'paid_at' => now(), 'status' => 'Completed', 'created_by' => auth()->id()]);
    $this->accounts->recordTransaction('main', 'income', 125, 'Receipt for POPUP-INVOICE', $payment, 'Cash');
    $this->get(($this->movements)())->assertOk()->assertSeeText('POPUP-INVOICE')->assertSeeText('POPUP-JOB')->assertSeeText('BIJ-4321')
        ->assertSee('href="'.route('sales.invoices.show', $this->invoice).'" target="_blank"', false)
        ->assertSee('href="'.route('jobs.show', $job).'" target="_blank"', false)->assertViewHas('total', 125.0);
})->with([true, false]);

it('shows an invoice number for a parts sale without a job and opens the invoice', function () {
    $this->invoice->update(['invoice_type' => 'Parts Sale']);
    $this->accounts->recordTransaction('spare_parts', 'income', 275.50, 'Parts receipt', $this->invoice, 'Bank Transfer');
    $this->get(($this->movements)('credit', 'spare_parts'))->assertOk()->assertSeeText('POPUP-INVOICE')->assertDontSeeText('Bike:')
        ->assertSee('href="'.route('sales.invoices.show', $this->invoice).'" target="_blank"', false)->assertViewHas('total', 275.5);
});

it('shows an expense source and a safe fallback for manual entries', function () {
    $expense = Expense::create(['number' => 'POPUP-EXPENSE', 'expense_date' => today(), 'account_id' => Account::systemAccount('main')->id,
        'category' => 'Workshop', 'description' => 'Workshop tea', 'amount' => 50, 'payment_method' => 'Cash', 'created_by' => auth()->id()]);
    $this->accounts->recordTransaction('main', 'expense', 50, 'Workshop tea', $expense, 'Cash');
    $manual = $this->accounts->recordTransaction('main', 'expense', 20, 'Manual expense', paymentMethod: 'Cash');
    $this->get(($this->movements)('debit'))->assertOk()->assertSeeText('Workshop tea')->assertSeeText('POPUP-EXPENSE')
        ->assertSee('href="'.route('expenses.show', $expense).'" target="_blank"', false)
        ->assertSee(route('cashier.transaction-source', $manual), false)->assertViewHas('total', 70.0);
    $this->get(route('cashier.transaction-source', $manual))->assertOk()->assertSeeText('Manual expense');
});

it('links job advances even before a job has an invoice', function () {
    $job = JobCard::create(['number' => 'POPUP-ADVANCE-JOB', 'is_quick_job' => true, 'quick_bike_details' => ['number' => 'ABC-9876'], 'status' => 'Assigned', 'created_by' => auth()->id()]);
    $advance = JobAdvance::create(['number' => 'POPUP-ADVANCE', 'job_card_id' => $job->id, 'account_id' => Account::systemAccount('main')->id,
        'amount' => 100, 'method' => 'Cash', 'paid_at' => today(), 'created_by' => auth()->id()]);
    $this->accounts->recordTransaction('main', 'income', 100, 'Customer advance', $advance, 'Cash');
    $this->get(($this->movements)())->assertOk()->assertSeeText('POPUP-ADVANCE-JOB')->assertSeeText('Bike: ABC-9876')
        ->assertSee(route('jobs.show', $job), false);
});

it('matches all four account credit and debit totals including reversals but excluding opening corrections', function ($type) {
    $entry = $this->accounts->recordTransaction($type, 'income', 120, 'Original receipt', $this->invoice, 'Cash');
    $this->accounts->reverseTransaction($entry, 'Receipt cancelled');
    $this->accounts->recordTransaction($type, 'income', 80, 'Replacement receipt', $this->invoice, 'Cash');
    $this->accounts->recordTransaction($type, 'expense', 30, 'Normal expense', paymentMethod: 'Cash');
    $this->accounts->recordTransaction($type, 'adjustment_increase', 1000, 'Opening correction', paymentMethod: 'Adjustment', category: 'cashier_account_opening');
    $this->accounts->recordTransaction($type, 'adjustment_decrease', 200, 'Old opening correction', paymentMethod: 'Adjustment', category: 'cashier_opening');
    $this->accounts->recordTransaction($type, 'income', 900, 'Yesterday receipt', paymentMethod: 'Cash', transactionDate: now()->subDay());
    $this->accounts->recordTransaction($type === 'main' ? 'bank' : 'main', 'expense', 45, 'Other account expense', paymentMethod: 'Cash');
    $void = $this->accounts->recordTransaction($type, 'income', 25, 'Voided entry', paymentMethod: 'Cash');
    $void->update(['status' => 'cancelled']);
    $summary = app(CashierService::class)->summary(today()->toDateString());
    foreach (['credit' => 'income', 'debit' => 'outgoing'] as $side => $key) {
        $this->get(($this->movements)($side, $type))->assertOk()->assertViewHas('total', $summary['accounts'][$type][$key])
            ->assertDontSeeText('Opening correction')->assertDontSeeText('Yesterday receipt')->assertDontSeeText('Other account expense')->assertDontSeeText('Voided entry');
    }
})->with(['main', 'spare_parts', 'bank', 'washing']);

it('paginates without dropping entries from the total and reports empty accounts clearly', function () {
    for ($i = 1; $i <= 27; $i++) {
        $this->accounts->recordTransaction('main', 'income', 10, 'Receipt '.$i, $this->invoice, 'Cash');
    }
    $this->get(($this->movements)())->assertOk()->assertViewHas('total', 270.0)->assertViewHas('rows', fn ($rows) => $rows->count() === 25 && $rows->total() === 27)
        ->assertSee('data-movement-pagination', false);
    $this->get(($this->movements)('credit', 'main', page: 2))->assertOk()->assertViewHas('total', 270.0)->assertViewHas('rows', fn ($rows) => $rows->count() === 2);
    $this->get(($this->movements)('debit', 'bank'))->assertOk()->assertViewHas('total', 0.0)->assertSeeText('No debits for this account on this date.');
});

it('displays reversed debits with reversed badge and shows net active debits in the header', function () {
    $expense = Expense::create([
        'number' => 'EXP-TEST-REVERSED', 'expense_date' => today(), 'account_id' => Account::systemAccount('main')->id,
        'category' => 'Advance', 'description' => 'Staff advance to reverse', 'amount' => 1500, 'payment_method' => 'Cash', 'created_by' => auth()->id(),
    ]);
    $entry = $this->accounts->recordTransaction('main', 'expense', 1500, 'Staff advance to reverse', $expense, 'Cash');
    $this->accounts->reverseTransaction($entry, 'Mistaken entry');
    $this->accounts->recordTransaction('main', 'expense', 500, 'Active advance', paymentMethod: 'Cash');

    $response = $this->get(($this->movements)('debit', 'main'))->assertOk();

    $response->assertViewHas('total', 2000.0)
        ->assertViewHas('reversedTotal', 1500.0)
        ->assertViewHas('activeTotal', 500.0)
        ->assertSeeText('Gross: Rs. 2,000.00')
        ->assertSeeText('−Rs. 1,500.00')
        ->assertSeeText('Net active debits: Rs. 500.00')
        ->assertSeeText('Reversed')
        ->assertSeeText('Reversal reason: Mistaken entry (excluded from net account balance)');
});

it('keeps cashier access separate from document permissions and the other shop', function () {
    $entry = $this->accounts->recordTransaction('main', 'income', 100, 'Private invoice', $this->invoice, 'Cash');
    $role = Role::create(['name' => 'Cashier only', 'permissions' => ['account.daily_closing']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(($this->movements)())->assertOk()->assertSeeText('POPUP-INVOICE')->assertDontSee(route('sales.invoices.show', $this->invoice), false);
    $this->get(route('cashier.transaction-source', $entry))->assertOk()->assertSeeText('Private invoice');
    session(['shop_mode' => 'helmet']);
    $this->get(($this->movements)())->assertOk()->assertSeeText('No credits')->assertDontSeeText('Private invoice');
    $this->get(route('cashier.transaction-source', $entry))->assertNotFound();
    $role->update(['permissions' => []]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(($this->movements)())->assertForbidden();
    $this->get(route('cashier.transaction-source', $entry))->assertForbidden();
});

it('validates account direction and date and does not post or edit transactions', function () {
    CashierSession::create(['session_date' => today(), 'status' => 'open', 'drawer_opening_balance' => 5000, 'opening_balance' => 5000, 'opened_at' => now(), 'opened_by' => auth()->id()]);
    $this->accounts->recordTransaction('main', 'income', 100, 'Preview receipt', $this->invoice, 'Cash');
    $before = AccountTransaction::all()->toArray();
    $this->get(route('cashier.index'))->assertOk()->assertSee('data-cashier-movements=', false)->assertSee('aria-label="View Main Account credits"', false);
    $this->get(($this->movements)())->assertOk();
    expect(AccountTransaction::all()->toArray())->toBe($before);
    foreach ([['account' => 'other'], ['side' => 'balance'], ['date' => today()->addDay()->toDateString()], ['page' => 0]] as $invalid) {
        $this->getJson(route('cashier.movements', $invalid + ['date' => today()->toDateString(), 'account' => 'main', 'side' => 'credit']))->assertUnprocessable();
    }
});
