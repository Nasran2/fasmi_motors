<?php

use App\Models\Account;
use App\Models\Bike;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\User;
use App\Services\WorkshopService;
use Illuminate\Support\Str;

beforeEach(function () {
    Account::firstOrCreate(['code' => 'MAIN'], ['name' => 'Main Account', 'type' => 'main', 'active' => true]);
    Account::firstOrCreate(['code' => 'SPARE'], ['name' => 'Spare Parts Account', 'type' => 'spare', 'active' => true]);
    Account::firstOrCreate(['code' => 'BANK'], ['name' => 'Bank Account', 'type' => 'bank', 'active' => true]);
});

test('public invoice link displays cancelled notice and watermark instead of throwing 410 error', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'John Public', 'phone' => '0771234567', 'active' => true]);
    $invoice = Invoice::create([
        'shop_type' => 'workshop',
        'number' => 'INV-TEST-001',
        'invoice_type' => 'Job Card Bill',
        'bill_date' => now(),
        'customer_id' => $customer->id,
        'subtotal' => 2000,
        'discount' => 0,
        'tax' => 0,
        'total' => 2000,
        'paid_amount' => 0,
        'due_amount' => 0,
        'status' => 'Cancelled',
        'cancel_reason' => 'Customer requested cancellation',
        'cancelled_at' => now(),
        'public_token' => Str::random(48),
        'link_enabled' => false,
        'link_expires_at' => now()->addDays(30),
        'created_by' => $user->id,
    ]);

    $this->get(route('invoices.public', $invoice->public_token))
        ->assertOk()
        ->assertSeeText('This Invoice Has Been Cancelled')
        ->assertSeeText('Customer requested cancellation')
        ->assertSee('CANCELLED');
});

test('public invoice link with expired link returns friendly 410 page', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Jane Expired', 'phone' => '0771234568', 'active' => true]);
    $invoice = Invoice::create([
        'shop_type' => 'workshop',
        'number' => 'INV-TEST-002',
        'invoice_type' => 'Job Card Bill',
        'bill_date' => now()->subDays(40),
        'customer_id' => $customer->id,
        'subtotal' => 1500,
        'discount' => 0,
        'tax' => 0,
        'total' => 1500,
        'paid_amount' => 0,
        'due_amount' => 1500,
        'status' => 'Unpaid',
        'public_token' => Str::random(48),
        'link_enabled' => true,
        'link_expires_at' => now()->subDay(),
        'created_by' => $user->id,
    ]);

    $this->get(route('invoices.public', $invoice->public_token))
        ->assertStatus(410)
        ->assertSeeText('Invoice Link Unavailable');
});

test('job card list renders cancelled job card with cut strikethrough styling', function () {
    $user = User::factory()->create();
    $customer = Customer::create(['name' => 'Cancelled Job Customer', 'phone' => '0771234569', 'active' => true]);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP-ABC-9999', 'model' => 'FZ-V3']);
    $job = JobCard::create([
        'number' => 'JOB-CANCELLED-001',
        'quick_customer_id' => $customer->id,
        'quick_bike_id' => $bike->id,
        'status' => 'Cancelled',
        'is_quick_job' => true,
        'parts_total' => 2000,
        'labor_total' => 500,
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('jobs.index'))
        ->assertOk()
        ->assertSee('line-through text-rose-600', false)
        ->assertSee($job->number);
});

test('cancelling an invoice frees the job card so staff can generate a new bill and receive payment again', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withOpenCashier();

    $customer = Customer::create(['name' => 'Rebill Customer', 'phone' => '0771234570', 'active' => true]);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP-XYZ-8888', 'model' => 'Pulsar']);
    $mainAccount = Account::where('code', 'MAIN')->firstOrFail();

    $job = JobCard::create([
        'number' => 'JOB-REBILL-001',
        'quick_customer_id' => $customer->id,
        'quick_bike_id' => $bike->id,
        'status' => 'In Progress',
        'is_quick_job' => true,
        'parts_total' => 3000,
        'labor_total' => 1000,
        'created_by' => $user->id,
    ]);

    // 1. Initial billing
    $service = app(WorkshopService::class);
    $firstInvoice = $service->invoice($job, 4000, 'Cash', null, [['method' => 'Cash', 'amount' => 4000, 'account_id' => $mainAccount->id]]);
    expect($firstInvoice->status)->toBe('Paid');
    expect($job->fresh()->status)->toBe('Billed');
    expect($job->fresh()->invoice->id)->toBe($firstInvoice->id);

    // 2. Cancel the first invoice
    app(\App\Http\Controllers\SalesBillingController::class)->cancelInvoice(
        request()->merge(['reason' => 'Wrong parts added, rebilling needed']),
        $firstInvoice,
        $service
    );

    $job = $job->fresh();
    expect($firstInvoice->fresh()->status)->toBe('Cancelled');
    expect($job->status)->toBe('Completed');
    expect($job->invoice)->toBeNull();
    expect($job->cancelledInvoice?->id)->toBe($firstInvoice->id);

    // 3. Visiting the job card show view shows re-billing form and previous cancelled invoice banner
    $response = $this->get(route('jobs.show', $job));
    $response->assertOk();
    $response->assertSeeText('Previous Bill ('.$firstInvoice->number.') Cancelled');
    $response->assertSeeText('Generate invoice');

    // 4. Generate a new bill for the same job card
    $secondInvoice = $service->invoice($job, 4000, 'Cash', null, [['method' => 'Cash', 'amount' => 4000, 'account_id' => $mainAccount->id]]);
    expect($secondInvoice->id)->not->toBe($firstInvoice->id);
    expect($secondInvoice->status)->toBe('Paid');

    $job = $job->fresh();
    expect($job->status)->toBe('Billed');
    expect($job->invoice->id)->toBe($secondInvoice->id);
    expect($job->invoices)->toHaveCount(2);

    // 5. The job card show view now links to the new active invoice
    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSeeText('View '.$secondInvoice->number);
});

test('completely cancelled job card displays cancelled notice and forbids re-billing', function () {
    $user = User::factory()->create();
    $this->actingAs($user)->withOpenCashier();

    $customer = Customer::create(['name' => 'Completely Cancelled', 'phone' => '0771234571', 'active' => true]);
    $bike = Bike::create(['customer_id' => $customer->id, 'number' => 'WP-DEF-7777', 'model' => 'Dio']);
    $job = JobCard::create([
        'number' => 'JOB-COMPLETELY-CANCELLED',
        'quick_customer_id' => $customer->id,
        'quick_bike_id' => $bike->id,
        'status' => 'Cancelled',
        'is_quick_job' => true,
        'created_by' => $user->id,
    ]);

    $this->get(route('jobs.show', $job))
        ->assertOk()
        ->assertSeeText('This Job Card Has Been Cancelled')
        ->assertSeeText('Job Card Cancelled')
        ->assertDontSeeText('Generate invoice');
});
