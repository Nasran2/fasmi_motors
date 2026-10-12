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
        ->assertSee('data-invoice-id="'.$this->invoice->id.'"', false)->assertSee('data-due="1000.00"', false)
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
    $this->get(route('jobs.index'))->assertOk()->assertSeeText('Partially Paid')->assertSee('data-due="700.00"', false);
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
