<?php

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\OpeningBalanceService;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Account::ensureSystemAccounts();
    $customer = Customer::create(['name' => 'Due Customer', 'phone' => '0771234444']);
    $this->bike = $customer->bikes()->create(['number' => 'DUE-BIKE-100', 'model' => 'Honda Dio']);
    $intake = BikeIntake::create(['intake_number' => 'IN-DUE-100', 'bike_id' => $this->bike->id, 'complaint' => 'Service', 'created_by' => auth()->id()]);
    $this->job = JobCard::create(['number' => 'JOB-DUE-100', 'bike_intake_id' => $intake->id, 'status' => 'Billed', 'priority' => 'Normal', 'service_total' => 1000, 'created_by' => auth()->id()]);
    $this->invoice = Invoice::create(['number' => 'INV-DUE-100', 'invoice_type' => 'Job Card Bill', 'job_card_id' => $this->job->id, 'customer_id' => $customer->id, 'bike_id' => $this->bike->id, 'subtotal' => 1000, 'total' => 1000, 'due_amount' => 1000, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_by' => auth()->id()]);
    $this->payment = ['method' => 'Cash', 'amount' => 300, 'account_id' => Account::systemAccount('main')->id];
});

test('bike and status filters combine and persist across job list pages', function () {
    $otherBike = $this->bike->customer->bikes()->create(['number' => 'OTHER-BIKE', 'model' => 'Yamaha']);
    $otherIntake = $otherBike->intakes()->create(['intake_number' => 'IN-OTHER', 'complaint' => 'Other repair', 'created_by' => auth()->id()]);
    $other = JobCard::create(['number' => 'JOB-OTHER', 'bike_intake_id' => $otherIntake->id, 'status' => 'Billed', 'priority' => 'Normal', 'created_by' => auth()->id()]);
    for ($i = 0; $i < 16; $i++) {
        $intake = $this->bike->intakes()->create(['intake_number' => 'IN-MATCH-'.$i, 'complaint' => 'Matching service', 'created_by' => auth()->id()]);
        JobCard::create(['number' => 'JOB-MATCH-'.$i, 'bike_intake_id' => $intake->id, 'status' => 'Billed', 'priority' => 'Normal', 'created_by' => auth()->id()]);
    }
    $url = route('jobs.index', ['bike_id' => $this->bike->id, 'status' => 'Completed']);
    $response = $this->get($url)->assertOk()->assertDontSeeText($other->number)->assertSeeText('Clear filters');
    $jobs = $response->viewData('jobs');
    expect($jobs->total())->toBe(17)->and($jobs->nextPageUrl())->toContain('bike_id='.$this->bike->id, 'status=Completed');
    $this->get(route('jobs.index', ['bike_id' => $this->bike->id, 'status' => 'Received']))->assertOk()->assertSeeText('No jobs found.');
});

test('job list shows due after total and popup defaults to today with a live preview', function () {
    $this->get(route('jobs.index'))->assertOk()->assertSeeInOrder(['<th>Total</th>', '<th>Due Balance</th>'], false)
        ->assertSee('data-job-id="'.$this->job->id.'"', false)->assertSee('data-due="1000"', false)
        ->assertSee('name="paid_at" value="'.today()->toDateString().'"', false)
        ->assertSeeText('Remaining Due')->assertSeeText('Payment status after saving')->assertSee('remainingDue()', false);
});

test('partial and final receipts update due status ledger date and keep workshop status', function () {
    $date = today()->subDays(2)->toDateString();
    $this->from(route('jobs.index'))->post(route('customers.dues.payment', $this->invoice), ['paid_at' => $date, 'payments' => [$this->payment]])->assertRedirect(route('jobs.index'))->assertSessionHasNoErrors();
    $this->invoice->refresh();
    $receipt = $this->invoice->payments()->firstOrFail();
    expect((float) $this->invoice->due_amount)->toBe(700.0)->and((float) $this->invoice->paid_amount)->toBe(300.0)
        ->and($this->invoice->status)->toBe('Partially Paid')->and($receipt->paid_at->toDateString())->toBe($date)
        ->and((float) $receipt->balance_after)->toBe(700.0)
        ->and(AccountTransaction::where('reference_id', $receipt->id)->firstOrFail()->transaction_date->toDateString())->toBe($date);
    $this->get(route('jobs.index'))->assertOk()->assertSeeText('Partially Paid')->assertSee('data-due="700"', false);
    $this->post(route('customers.dues.payment', $this->invoice), ['paid_at' => today()->toDateString(), 'payments' => [array_replace($this->payment, ['amount' => 700])]])->assertSessionHasNoErrors();
    expect((float) $this->invoice->fresh()->due_amount)->toBe(0.0)->and($this->invoice->fresh()->status)->toBe('Paid')
        ->and($this->job->fresh()->status)->toBe('Billed')->and(Account::systemAccount('main')->balance)->toBe(1000.0);
    $this->get(route('jobs.index'))->assertOk()->assertSeeText('Completed')->assertDontSee('data-invoice-id="'.$this->invoice->id.'"', false);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeInOrder(['Payment status', 'Completed']);
});

test('invalid or future collection dates do not record a receipt', function ($date) {
    $this->post(route('customers.dues.payment', $this->invoice), ['paid_at' => $date === 'future' ? today()->addDay()->toDateString() : $date, 'payments' => [$this->payment]])->assertSessionHasErrors('paid_at');
    expect(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0)->and((float) $this->invoice->fresh()->due_amount)->toBe(1000.0);
})->with(['future', '2026-99-01', 'not-a-date']);

test('existing callers without a payment date still collect using today', function () {
    $this->post(route('customers.dues.payment', $this->invoice), ['payments' => [$this->payment]])->assertSessionHasNoErrors();
    expect($this->invoice->payments()->firstOrFail()->paid_at->isToday())->toBeTrue();
});

test('previous balance can be paid from a fully paid current job without changing its receipt', function () {
    $previous = Invoice::create(['number' => 'OP-DUE-OLD', 'invoice_type' => 'Opening Due', 'customer_id' => $this->invoice->customer_id, 'bike_id' => $this->bike->id, 'subtotal' => 2000, 'total' => 2000, 'due_amount' => 2000, 'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_at' => today()->subDays(5), 'created_by' => auth()->id()]);
    $this->invoice->update(['paid_amount' => 1000, 'due_amount' => 0, 'status' => 'Paid']);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Total Due Balance')
        ->assertSee('data-invoice-id="'.$previous->id.'"', false)->assertDontSee('data-invoice-id="'.$this->invoice->id.'"', false);
    $this->post(route('customers.dues.payment', $previous), ['paid_at' => today()->toDateString(), 'payments' => [$this->payment]])->assertSessionHasNoErrors();
    expect((float) $previous->fresh()->due_amount)->toBe(1700.0)->and($previous->fresh()->status)->toBe('Partially Paid')
        ->and((float) $this->invoice->fresh()->paid_amount)->toBe(1000.0)->and($this->invoice->payments()->count())->toBe(0);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Rs. 1,700.00')->assertSeeText('Partially Paid');
});

test('cheques covering the due remain on hold instead of showing completed', function () {
    $row = array_replace($this->payment, ['amount' => 1000, 'method' => 'Cheque', 'cheque_date' => today()->toDateString(), 'cheque_number' => 'DUE-CHEQUE', 'bank_name' => 'Test Bank']);
    $this->post(route('customers.dues.payment', $this->invoice), ['paid_at' => today()->toDateString(), 'payments' => [$row]])->assertSessionHasNoErrors();
    expect($this->invoice->fresh()->status)->toBe('Hold')->and((float) $this->invoice->fresh()->paid_amount)->toBe(0.0)->and((float) $this->invoice->fresh()->hold_amount)->toBe(1000.0);
    $this->get(route('jobs.index'))->assertOk()->assertSeeText('Hold')->assertDontSee('data-invoice-id="'.$this->invoice->id.'"', false);
});

test('users without collection permission can view dues but cannot receive payments', function () {
    $role = Role::create(['name' => 'Job Viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('jobs.index'))->assertOk()->assertSeeText('Rs. 1,000.00')->assertDontSee('data-invoice-id="'.$this->invoice->id.'"', false);
    $this->post(route('customers.dues.payment', $this->invoice), ['payments' => [$this->payment]])->assertForbidden();
    expect(Payment::count())->toBe(0);
});

test('job list keeps bill details inside the popup and pays only selected bills', function () {
    $opening = app(OpeningBalanceService::class)->record($this->bike->customer, ['opening_balance' => 500, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $response = $this->get(route('jobs.index'))->assertOk()->assertSeeText('Select all bills')->assertSeeText('Selected due:')
        ->assertSee('data-due="1500"', false)->assertDontSeeText('Earlier unpaid balance ·');
    $document = new DOMDocument;
    @$document->loadHTML($response->getContent());
    $xpath = new DOMXPath($document);
    expect($xpath->query('//table/tbody/tr/td[7]//button')->length)->toBe(1);
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => [$this->invoice->id], 'payments' => [$this->payment]])->assertSessionHasNoErrors();
    expect((float) $this->invoice->fresh()->due_amount)->toBe(700.0)
        ->and((float) $opening->fresh()->due_amount)->toBe(500.0)->and($opening->payments()->count())->toBe(0);
});

test('selected bills share a partial payment oldest first and keep opening receipts in main account', function () {
    $opening = app(OpeningBalanceService::class)->record($this->bike->customer, ['opening_balance' => 500, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $date = today()->subDay()->toDateString();
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => $date, 'invoice_ids' => [$this->invoice->id, $opening->id], 'payments' => [array_replace($this->payment, ['amount' => 800])]])->assertSessionHasNoErrors();
    expect($opening->fresh()->status)->toBe('Paid')->and((float) $this->invoice->fresh()->due_amount)->toBe(700.0)
        ->and($this->invoice->fresh()->status)->toBe('Partially Paid')->and(Account::systemAccount('main')->balance)->toBe(800.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0)
        ->and($opening->payments()->sole()->paid_at->toDateString())->toBe($date);
});

test('selected cheque payments use one cheque and stay held until clearing', function () {
    $opening = app(OpeningBalanceService::class)->record($this->bike->customer, ['opening_balance' => 500, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $row = array_replace($this->payment, ['amount' => 1500, 'method' => 'Cheque', 'account_id' => Account::systemAccount('bank')->id, 'cheque_date' => today()->toDateString(), 'cheque_number' => 'SELECT-BILLS', 'bank_name' => 'Test Bank']);
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => [$opening->id, $this->invoice->id], 'payments' => [$row]])->assertSessionHasNoErrors();
    $cheque = $opening->payments()->sole()->cheque;
    expect($this->invoice->payments()->sole()->cheque_id)->toBe($cheque->id)->and($opening->fresh()->status)->toBe('Hold')
        ->and($this->invoice->fresh()->status)->toBe('Hold')->and(AccountTransaction::count())->toBe(0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Cleared', 'realized_date' => today()->toDateString()])->assertSessionHasNoErrors();
    expect($opening->fresh()->status)->toBe('Paid')->and($this->invoice->fresh()->status)->toBe('Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(1500.0);
    $this->patch(route('accounts.cheques.status', $cheque), ['status' => 'Returned', 'reason' => 'Bank rejection'])->assertSessionHasNoErrors();
    expect((float) $opening->fresh()->due_amount)->toBe(500.0)->and((float) $this->invoice->fresh()->due_amount)->toBe(1000.0)
        ->and(Account::systemAccount('main')->balance)->toBe(0.0);
});

test('bill selection rejects empty duplicate and unrelated invoices without recording payments', function ($selection) {
    $ids = match ($selection) {
        'empty' => [],
        'duplicate' => [$this->invoice->id, $this->invoice->id],
        default => [$this->invoice->id + 999],
    };
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => $ids, 'payments' => [$this->payment]])->assertSessionHasErrors();
    expect(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
})->with(['empty', 'duplicate', 'unrelated']);

test('non-cash payment cannot exceed selected dues or use a bill settled after the popup opened', function () {
    $opening = app(OpeningBalanceService::class)->record($this->bike->customer, ['opening_balance' => 100, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $payload = ['paid_at' => today()->toDateString(), 'invoice_ids' => [$opening->id], 'payments' => [array_replace($this->payment, ['method' => 'Card'])]];
    $this->post(route('jobs.dues.payment', $this->job), $payload)->assertSessionHasErrors('payments');
    $opening->update(['paid_amount' => 100, 'due_amount' => 0, 'status' => 'Paid']);
    $this->post(route('jobs.dues.payment', $this->job), $payload)->assertSessionHasErrors('invoice_ids.0');
    expect(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0);
});

test('selected bill cash collection records the tender and returns excess without inflating income', function ($due, $received, $change) {
    $this->job->update(['service_total' => $due]);
    $this->invoice->update(['subtotal' => $due, 'total' => $due, 'due_amount' => $due]);
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(), 'invoice_ids' => [$this->invoice->id],
        'payments' => [array_replace($this->payment, ['amount' => $received])],
    ])->assertSessionHasNoErrors();
    $receipt = $this->invoice->payments()->sole();
    expect((float) $receipt->amount)->toBe((float) $due)
        ->and((float) $receipt->received_amount)->toBe((float) $received)
        ->and((float) $receipt->change_amount)->toBe((float) $change)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(0.0)
        ->and($this->invoice->fresh()->status)->toBe('Paid')
        ->and(Account::systemAccount('main')->balance)->toBe((float) $due);
    $this->get(route('jobs.show', $this->job))->assertOk()
        ->assertSeeInOrder(['Amount received', number_format($received, 2), 'Change to return', number_format($change, 2)]);
})->with([[1450, 1500, 50], [24070, 24100, 30]]);

test('excess cash on a selected opening bill is returned without paying unselected bills', function () {
    $opening = app(OpeningBalanceService::class)->record($this->bike->customer, ['opening_balance' => 100, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(), 'invoice_ids' => [$opening->id], 'payments' => [$this->payment],
    ])->assertSessionHasNoErrors();
    $receipt = $opening->payments()->sole();
    expect((float) $receipt->amount)->toBe(100.0)->and((float) $receipt->received_amount)->toBe(300.0)
        ->and((float) $receipt->change_amount)->toBe(200.0)->and($opening->fresh()->status)->toBe('Paid')
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(1000.0)
        ->and($this->invoice->payments()->count())->toBe(0)
        ->and(Account::systemAccount('main')->balance)->toBe(100.0)
        ->and(Account::systemAccount('spare_parts')->balance)->toBe(0.0);
});

test('selected mixed cheque and cash collection returns only excess cash and keeps cheque on hold', function () {
    $opening = app(OpeningBalanceService::class)->record($this->bike->customer, ['opening_balance' => 500, 'opening_balance_date' => today()->subDay()->toDateString()]);
    $cheque = array_replace($this->payment, ['method' => 'Cheque', 'amount' => 500, 'account_id' => Account::systemAccount('bank')->id,
        'cheque_date' => today()->toDateString(), 'cheque_number' => 'CASH-CHANGE-HOLD', 'bank_name' => 'Test Bank']);
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(), 'invoice_ids' => [$this->invoice->id, $opening->id],
        'payments' => [array_replace($this->payment, ['amount' => 1100]), $cheque],
    ])->assertSessionHasNoErrors();
    expect((float) Payment::sum('received_amount'))->toBe(1600.0)
        ->and((float) Payment::sum('change_amount'))->toBe(100.0)
        ->and((float) Payment::where('method', 'Cash')->sum('amount'))->toBe(1000.0)
        ->and($opening->fresh()->status)->toBe('Hold')
        ->and((float) $opening->fresh()->hold_amount)->toBe(500.0)
        ->and($this->invoice->fresh()->status)->toBe('Paid')
        ->and(Account::systemAccount('main')->balance)->toBe(1000.0);
});

test('selected bills reject excess non-cash payments even when cash is also supplied', function ($method) {
    $nonCash = array_replace($this->payment, ['method' => $method, 'amount' => 1100, 'account_id' => Account::systemAccount('bank')->id,
        'cheque_date' => today()->toDateString(), 'cheque_number' => 'EXCESS-SELECTED', 'bank_name' => 'Test Bank']);
    $this->post(route('jobs.dues.payment', $this->job), [
        'paid_at' => today()->toDateString(), 'invoice_ids' => [$this->invoice->id], 'payments' => [$this->payment, $nonCash],
    ])->assertSessionHasErrors('payments');
    expect(Payment::count())->toBe(0)->and(AccountTransaction::count())->toBe(0)
        ->and((float) $this->invoice->fresh()->due_amount)->toBe(1000.0);
})->with(['Card', 'Bank Transfer', 'Cheque']);

test('the selected bill payment route requires collection permission', function () {
    $role = Role::create(['name' => 'Selected Job Viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('jobs.index'))->assertOk()->assertDontSee('data-job-id="'.$this->job->id.'"', false);
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => [$this->invoice->id], 'payments' => [$this->payment]])->assertForbidden();
    expect(Payment::count())->toBe(0);
});

test('bill selection cannot pay another customer or a later invoice not shown in the row', function ($otherCustomer) {
    $invoice = $this->invoice->replicate(['number', 'public_token', 'job_card_id']);
    $invoice->number = 'INV-NOT-SHOWN';
    $invoice->public_token = Str::random(48);
    $invoice->created_at = $this->invoice->created_at->copy()->addDay();
    if ($otherCustomer) {
        $invoice->customer_id = Customer::create(['name' => 'Foreign Customer', 'phone' => '0779009999'])->id;
    }
    $invoice->save();
    $this->post(route('jobs.dues.payment', $this->job), ['paid_at' => today()->toDateString(), 'invoice_ids' => [$invoice->id], 'payments' => [$this->payment]])->assertSessionHasErrors('invoice_ids.0');
    expect(Payment::count())->toBe(0)->and((float) $invoice->fresh()->due_amount)->toBe(1000.0);
})->with([true, false]);
