<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\Technician;
use App\Models\TechnicianSalarySheet;
use App\Models\User;
use App\Services\AccountService;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $this->account = Account::systemAccount('main');
    $this->ledger = fn ($source = null, $kind = 'expense', $type = 'main') => app(AccountService::class)->recordTransaction($type, $kind, 125, 'Linked report entry', $source, 'Cash', transactionDate: now());
    $this->invoice = Invoice::create(['number' => 'SOURCE-INV', 'invoice_type' => 'Parts Sale', 'bill_date' => today(), 'subtotal' => 1000, 'total' => 1000,
        'paid_amount' => 125, 'due_amount' => 875, 'status' => 'Partially Paid', 'public_token' => 'source-invoice-token', 'created_by' => auth()->id()]);
});

it('opens invoices for income receipts and their reversal entries', function () {
    $payment = $this->invoice->payments()->create(['number' => 'SOURCE-RECEIPT', 'amount' => 125, 'method' => 'Cash', 'paid_at' => now(), 'created_by' => auth()->id()]);
    $transaction = ($this->ledger)($payment, 'income');
    $this->get(route('reports.transaction-source', $transaction))->assertRedirect(route('sales.invoices.show', $this->invoice));
    app(AccountService::class)->reverseTransaction($transaction, 'Check reversal link');
    $reversal = AccountTransaction::where('transaction_type', 'reversal')->firstOrFail();
    $this->get(route('reports.transaction-source', $reversal))->assertRedirect(route('sales.invoices.show', $this->invoice));
});

it('shows clickable references and descriptions in each account report without changing amounts', function ($type, $report) {
    $transaction = ($this->ledger)($this->invoice, 'income', $type);
    $this->get(route('reports.'.$report))->assertOk()->assertSee(route('reports.transaction-source', $transaction), false)
        ->assertSee('target="_blank"', false)->assertViewHas('stats', fn ($stats) => (float) $stats['filtered_in_amount'] === 125.0);
    $csv = $this->get(route('reports.'.$report, ['export_csv' => 1]))->assertOk()->streamedContent();
    expect($csv)->not->toContain('account-transactions/');
    if ($type !== 'washing') {
        expect($csv)->toContain($transaction->transaction_no, 'Linked report entry');
    }
})->with([['main', 'main-account'], ['spare_parts', 'spare-parts-account'], ['bank', 'bank-account'], ['washing', 'washing-account']]);

it('opens exact expense and drawing details including cancelled or archived source records', function () {
    $expense = Expense::create(['number' => 'EXP-SOURCE', 'expense_date' => today(), 'account_id' => $this->account->id, 'category' => 'Workshop',
        'description' => 'Tea time', 'amount' => 125, 'payment_method' => 'Cash', 'note' => 'Expense receipt note', 'created_by' => auth()->id()]);
    $entry = ($this->ledger)($expense);
    $this->get(route('reports.transaction-source', $entry))->assertRedirect(route('expenses.show', $expense));
    $this->get(route('expenses.show', $expense))->assertOk()->assertSeeText('Tea time')->assertSeeText('Expense receipt note')->assertSeeText('125.00');
    $expense->update(['status' => 'Cancelled', 'cancel_reason' => 'Cancelled test expense']);
    $expense->delete();
    $this->get(route('reports.transaction-source', $entry))->assertRedirect(route('expenses.show', $expense));
    $this->get(route('expenses.show', $expense))->assertOk()->assertSeeText('Cancelled test expense')->assertSeeText('Archived');
    $drawing = Drawing::create(['number' => 'DRAW-SOURCE', 'drawing_date' => today(), 'person_name' => 'Owner', 'drawing_type' => 'Personal',
        'account_id' => $this->account->id, 'amount' => 125, 'payment_method' => 'Cash', 'created_by' => auth()->id()]);
    $this->get(route('reports.transaction-source', ($this->ledger)($drawing, 'drawing')))->assertRedirect(route('drawings.show', $drawing));
    $this->get(route('drawings.show', $drawing))->assertOk()->assertSeeText('Owner')->assertSeeText('Personal');
});

it('opens job cards and customer advance sources', function () {
    $job = JobCard::create(['number' => 'SOURCE-JOB', 'is_quick_job' => true, 'quick_complaint' => 'Work', 'status' => 'Assigned', 'created_by' => auth()->id()]);
    $this->get(route('reports.transaction-source', ($this->ledger)($job, 'income')))->assertRedirect(route('jobs.show', $job));
    $advance = JobAdvance::create(['number' => 'SOURCE-ADV', 'account_id' => $this->account->id, 'job_card_id' => $job->id, 'amount' => 125, 'method' => 'Cash', 'paid_at' => today(), 'created_by' => auth()->id()]);
    $this->get(route('reports.transaction-source', ($this->ledger)($advance, 'income')))->assertRedirect(route('jobs.show', $job));
});

it('opens cashier records for opening and closing adjustments', function () {
    $session = CashierSession::create(['session_date' => today(), 'status' => 'open', 'opening_balance' => 125, 'opened_by' => auth()->id(), 'opened_at' => now()]);
    $this->get(route('reports.transaction-source', ($this->ledger)($session, 'adjustment_increase')))->assertRedirect(route('cashier.index', ['date' => today()->toDateString()]));
    $closing = DailyClosing::create(['closing_date' => today(), 'closed_by' => auth()->id()]);
    $this->get(route('reports.transaction-source', ($this->ledger)($closing, 'adjustment_decrease')))->assertRedirect(route('cashier.index', ['date' => today()->toDateString()]));
});

it('opens saved salary details for salary expenses', function () {
    $staff = Technician::create(['name' => 'Source Staff', 'phone' => '0771112000']);
    $sheet = TechnicianSalarySheet::create(['number' => 'SOURCE-SALARY', 'technician_id' => $staff->id, 'period_start' => today()->startOfMonth(), 'period_end' => today()->endOfMonth(),
        'basic_salary' => 1000, 'net_payable' => 1000, 'created_by' => auth()->id()]);
    $this->get(route('reports.transaction-source', ($this->ledger)($sheet)))->assertRedirect(route('technicians.salaries.show', $sheet));
});

it('falls back to transaction details for manual unknown and missing references', function () {
    $transaction = ($this->ledger)();
    $this->get(route('reports.transaction-source', $transaction))->assertOk()->assertSeeText('Linked report entry')->assertSeeText('125.00');
    $transaction->update(['reference_type' => 'LegacyExternalRecord', 'reference_id' => 55]);
    $this->get(route('reports.transaction-source', $transaction))->assertOk()->assertSeeText($transaction->transaction_no);
    $transaction->update(['reference_type' => Invoice::class, 'reference_id' => 99999999]);
    $this->get(route('reports.transaction-source', $transaction))->assertOk()->assertSeeText('Linked report entry');
});

it('requires source permissions while allowing report-only users to view the existing ledger entry', function () {
    $entry = ($this->ledger)($this->invoice, 'income');
    $role = Role::create(['name' => 'Reports only', 'permissions' => ['reports.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('reports.transaction-source', $entry))->assertOk()->assertSeeText($entry->transaction_no);
    $this->get(route('sales.invoices.show', $this->invoice))->assertForbidden();
    $this->actingAs(User::factory()->create(['role_id' => Role::create(['name' => 'No report access', 'permissions' => []])->id]));
    $this->get(route('reports.transaction-source', $entry))->assertForbidden();
});

it('does not expose ledger entries or expense details from the other shop', function () {
    $expense = Expense::create(['number' => 'EXP-OTHER-SHOP', 'expense_date' => today(), 'account_id' => $this->account->id, 'category' => 'Workshop', 'description' => 'Private expense', 'amount' => 125, 'payment_method' => 'Cash', 'created_by' => auth()->id()]);
    $entry = ($this->ledger)($expense);
    session(['shop_mode' => 'helmet']);
    $this->get(route('reports.transaction-source', $entry))->assertNotFound();
    $this->get(route('expenses.show', $expense))->assertNotFound();
});

it('opens the job when a report reader has job permission but cannot view internal invoices', function () {
    $job = JobCard::create(['number' => 'SOURCE-JOB-FALLBACK', 'is_quick_job' => true, 'quick_complaint' => 'Work', 'status' => 'Assigned', 'created_by' => auth()->id()]);
    $this->invoice->update(['job_card_id' => $job->id]);
    $entry = ($this->ledger)($this->invoice, 'income');
    $role = Role::create(['name' => 'Reports and jobs', 'permissions' => ['reports.view', 'job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('reports.transaction-source', $entry))->assertRedirect(route('jobs.show', $job));
});
