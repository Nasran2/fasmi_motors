<?php

use App\Models\Account;
use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Role;
use App\Models\User;
use App\Support\InvoiceDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Account::create(['code' => 'MAIN', 'name' => 'Main Account', 'type' => 'main', 'active' => true]);
    $customer = Customer::create(['name' => 'Balance Test Customer', 'phone' => '0771234510', 'active' => true]);
    $bike = $customer->bikes()->create(['number' => 'OLD-DUE-100', 'model' => 'Yamaha FZ']);
    $intake = BikeIntake::create(['intake_number' => 'IN-PREVIOUS', 'bike_id' => $bike->id, 'complaint' => 'Service', 'created_by' => $this->user->id]);
    $this->job = JobCard::create(['number' => 'JOB-CURRENT', 'bike_intake_id' => $intake->id, 'status' => 'Received', 'priority' => 'Normal', 'service_total' => 5000, 'labor_total' => 500, 'created_by' => $this->user->id]);
    $oldIntake = BikeIntake::create(['intake_number' => 'IN-OLD', 'bike_id' => $bike->id, 'complaint' => 'Old service', 'created_by' => $this->user->id]);
    $oldJob = JobCard::create(['number' => 'JOB-OLD-DATED', 'bike_intake_id' => $oldIntake->id, 'status' => 'Billed', 'priority' => 'Normal', 'created_by' => $this->user->id]);
    DB::table('job_card_status_logs')->insert(['job_card_id' => $oldJob->id, 'new_status' => 'Completed', 'changed_by' => $this->user->id, 'created_at' => '2026-09-15 10:00:00', 'updated_at' => '2026-09-15 10:00:00']);
    $this->previous = Invoice::create([
        'number' => 'INV-OLD-DATED', 'invoice_type' => 'Job Card Bill', 'job_card_id' => $oldJob->id,
        'customer_id' => $customer->id, 'subtotal' => 12000, 'total' => 12000, 'paid_amount' => 1850,
        'due_amount' => 10150, 'status' => 'Partially Paid', 'public_token' => Str::random(48),
        'created_at' => '2026-09-16 10:00:00', 'created_by' => $this->user->id,
    ]);
});

test('previous balance preference defaults on and saves on both editable and billed jobs', function () {
    expect($this->job->fresh()->show_previous_balance_on_bill)->toBeTrue();
    $this->get(route('jobs.show', $this->job))->assertOk()
        ->assertSeeText('Include outstanding balances on bill')->assertSeeText('Rs. 10,150.00');
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'show_previous_balance_on_bill' => 1])
        ->assertSessionHasNoErrors();
    expect($this->job->fresh()->show_previous_balance_on_bill)->toBeTrue();

    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 500, 'payment_method' => 'Cash'])
        ->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $this->put(route('jobs.update', $this->job), ['status' => 'Billed', 'show_previous_balance_on_bill' => 0])
        ->assertSessionHasNoErrors();
    expect($this->job->fresh()->show_previous_balance_on_bill)->toBeFalse()
        ->and((float) $invoice->fresh()->total)->toBe(5500.0)
        ->and((float) $invoice->fresh()->due_amount)->toBe(5000.0)
        ->and((float) $this->previous->fresh()->due_amount)->toBe(10150.0);
});

test('selected previous balances show dated jobs and correct totals in every bill format', function () {
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 500, 'payment_method' => 'Cash', 'show_previous_balance_on_bill' => 1])
        ->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $data = InvoiceDocument::data($invoice);
    expect($data['previousBalance'])->toBe(10150.0)
        ->and($data['grandTotal'])->toBe(15650.0)->and($data['totalBalanceDue'])->toBe(15150.0)
        ->and((float) $invoice->total)->toBe(5500.0)->and((float) $invoice->due_amount)->toBe(5000.0);
    foreach (['a4', 'a5', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertSeeText('Earlier unpaid balances')->assertSeeText('Grand Total')
            ->assertSeeText('JOB-OLD-DATED')->assertSeeText('15 Sep 2026')
            ->assertSeeText('15,650.00')->assertSeeText('15,150.00');
        $this->get(route('invoices.public.pdf', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
    $this->put(route('jobs.update', $this->job), ['status' => 'Billed', 'show_previous_balance_on_bill' => 0])
        ->assertSessionHasNoErrors();
    foreach (['a4', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertDontSeeText('Earlier unpaid balances')->assertDontSeeText('Grand Total')
            ->assertDontSeeText('JOB-OLD-DATED')->assertDontSeeText('15 Sep 2026')->assertSeeText('5,500.00');
    }
});

test('new invoice printing hides only total balance due while later viewing still shows it', function () {
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 500, 'payment_method' => 'Cash'])
        ->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $this->get(route('jobs.show', $this->job))->assertOk()
        ->assertSee(route('invoices.public.pdf', ['token' => $invoice->public_token, 'hide_total_balance_due' => 1]))
        ->assertSee('format=${format}&hide_total_balance_due=1', false)
        ->assertSee(route('invoices.public', $invoice->public_token));

    foreach (['a4', 'a5', '80mm'] as $format) {
        $response = $this->get(route('invoices.public', [
            'token' => $invoice->public_token, 'format' => $format, 'hide_total_balance_due' => 1,
        ]))->assertOk()->assertDontSeeText('Total balance due')
            ->assertSeeText('Earlier unpaid balances')->assertSeeText('Grand Total')
            ->assertSeeText('JOB-OLD-DATED')->assertSeeText('15,650.00')->assertSeeText('5,000.00')
            ->assertSee(route('invoices.public.pdf', [
                'token' => $invoice->public_token, 'format' => $format, 'hide_total_balance_due' => 1,
            ]));
        foreach (['a4', 'a5', '80mm'] as $paper) {
            $response->assertSee(route('invoices.public', [
                'token' => $invoice->public_token, 'format' => $paper, 'hide_total_balance_due' => 1,
            ]));
        }
        $view = $format === '80mm' ? 'invoices.thermal' : 'invoices.public';
        $pdfHtml = view($view, InvoiceDocument::data($invoice, pdfMode: true, showTotalBalanceDue: false))->render();
        expect($pdfHtml)->not->toContain('Total balance due')->toContain('Grand Total');
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertSeeText('Total balance due')->assertSeeText('15,150.00');
    }
    expect($this->job->fresh()->show_previous_balance_on_bill)->toBeTrue()
        ->and((float) $invoice->fresh()->due_amount)->toBe(5000.0)
        ->and((float) $this->previous->fresh()->due_amount)->toBe(10150.0);
});

test('previous balance excludes other customers shops quotations cancelled settled and later bills', function () {
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due', 'show_previous_balance_on_bill' => 1])
        ->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $otherCustomer = Customer::create(['name' => 'Other Customer', 'phone' => '0771234511', 'active' => true]);
    foreach ([
        ['status' => 'Cancelled'], ['invoice_type' => 'Quotation'], ['due_amount' => 0],
        ['customer_id' => $otherCustomer->id], ['shop_type' => 'helmet'],
        ['created_at' => $invoice->created_at->copy()->addDay()],
        ['created_at' => $invoice->created_at, 'invoice_type' => 'Parts Sale'],
    ] as $index => $overrides) {
        Invoice::create(array_merge([
            'number' => 'INV-EXCLUDED-'.$index, 'invoice_type' => 'Opening Due',
            'customer_id' => $invoice->customer_id, 'subtotal' => 900, 'total' => 900, 'due_amount' => 900,
            'status' => 'Unpaid', 'public_token' => Str::random(48), 'created_at' => '2026-09-17 10:00:00',
            'created_by' => $this->user->id,
        ], $overrides));
    }
    $opening = Invoice::create([
        'number' => 'INV-OPENING-BALANCE', 'invoice_type' => 'Opening Due', 'customer_id' => $invoice->customer_id,
        'subtotal' => 0.15, 'total' => 0.15, 'due_amount' => 0.15, 'status' => 'Unpaid',
        'public_token' => Str::random(48), 'created_at' => '2026-09-10 10:00:00', 'created_by' => $this->user->id,
    ]);
    // Public links must use the invoice's shop even when the visitor has another shop selected.
    $this->withSession(['shop_mode' => 'helmet']);
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()
        ->assertSeeText('15,650.15')->assertSeeText($opening->number)->assertSeeText('10 Sep 2026')
        ->assertDontSeeText('INV-EXCLUDED');
    $this->previous->update(['paid_amount' => 12000, 'due_amount' => 0, 'status' => 'Paid']);
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()
        ->assertSeeText('5,500.15')->assertDontSeeText('JOB-OLD-DATED');
});

test('invalid preferences and failed invoice creation do not change the saved option', function () {
    $this->job->update(['show_previous_balance_on_bill' => false]);
    $this->put(route('jobs.update', $this->job), ['status' => 'Received', 'show_previous_balance_on_bill' => 'invalid'])
        ->assertSessionHasErrors('show_previous_balance_on_bill');
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due', 'show_previous_balance_on_bill' => 'invalid'])
        ->assertSessionHasErrors('show_previous_balance_on_bill');
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 99999, 'payment_method' => 'Bank', 'show_previous_balance_on_bill' => 1])
        ->assertSessionHasErrors();
    expect($this->job->fresh()->show_previous_balance_on_bill)->toBeFalse()
        ->and($this->job->fresh()->invoice)->toBeNull();
});

test('a forgotten previous balance joins customer dues and the bill without changing current job charges', function () {
    $this->job->update(['show_previous_balance_on_bill' => false]);
    $this->get(route('jobs.show', $this->job))->assertOk()->assertSeeText('Add opening balance');
    $payload = [
        'previous_balance_amount' => '1234.56', 'previous_balance_date' => '2026-09-20',
        '_submission_token' => (string) Str::uuid(), 'customer_id' => 999999,
    ];
    $this->post(route('jobs.previous-balance.store', $this->job), $payload)
        ->assertRedirect(route('jobs.show', $this->job))->assertSessionHasNoErrors();
    $opening = Invoice::where('invoice_type', 'Opening Due')->sole();
    expect($opening->customer_id)->toBe($this->previous->customer_id)
        ->and($opening->bike_id)->toBe($this->job->intake->bike_id)
        ->and($opening->job_card_id)->toBeNull()
        ->and($opening->created_at->toDateString())->toBe('2026-09-20')
        ->and((float) $opening->due_amount)->toBe(1234.56)
        ->and($opening->shop_type)->toBe('workshop')
        ->and($this->job->fresh()->show_previous_balance_on_bill)->toBeTrue()
        ->and($this->job->fresh()->total)->toBe(5500.0);
    $this->get(route('jobs.show', $this->job))->assertOk()
        ->assertSeeText('Rs. 11,384.56')->assertSeeText('Rs. 16,884.56')->assertSeeText('20 Sep 2026');
    $this->get(route('customers.dues'))->assertOk()->assertSeeText($opening->number)->assertSeeText('1,234.56');
    $this->get(route('reports.profit-loss', ['date_range' => 'all_time']))->assertOk()->assertViewHas('otherIncome', 0);
    $this->post(route('jobs.previous-balance.store', $this->job), $payload)->assertRedirect();
    expect(Invoice::where('invoice_type', 'Opening Due')->count())->toBe(1);
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])
        ->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $this->get(route('invoices.public', $invoice->public_token))->assertOk()
        ->assertSeeText('16,884.56')->assertSeeText('20 Sep 2026')->assertSeeText($opening->number);
    expect((float) $invoice->total)->toBe(5500.0)->and((float) $invoice->due_amount)->toBe(5500.0);
});

test('a forgotten balance can be added to an already billed job including on the same date', function () {
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 500, 'payment_method' => 'Cash'])
        ->assertSessionHasNoErrors();
    $invoice = $this->job->fresh()->invoice;
    $invoice->update(['created_at' => $invoice->created_at->copy()->startOfDay()]);
    $this->job->update(['show_previous_balance_on_bill' => false]);
    $this->post(route('jobs.previous-balance.store', $this->job), [
        'previous_balance_amount' => 2500, 'previous_balance_date' => $invoice->created_at->toDateString(),
    ])->assertSessionHasNoErrors();
    foreach (['a4', '80mm'] as $format) {
        $this->get(route('invoices.public', ['token' => $invoice->public_token, 'format' => $format]))
            ->assertOk()->assertSeeText('Grand Total')->assertSeeText('18,150.00')->assertSeeText('17,650.00');
    }
    expect($this->job->fresh()->status)->toBe('Billed')
        ->and((float) $invoice->fresh()->total)->toBe(5500.0)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(500.0)
        ->and((float) $invoice->fresh()->due_amount)->toBe(5000.0);
});

test('forgotten balances reject invalid amounts and dates without recording any debt', function ($amount, $date, $field) {
    $this->post(route('jobs.previous-balance.store', $this->job), [
        'previous_balance_amount' => $amount, 'previous_balance_date' => $date,
    ])->assertSessionHasErrors($field);
    expect(Invoice::where('invoice_type', 'Opening Due')->count())->toBe(0);
})->with([
    [0, '2026-09-20', 'previous_balance_amount'],
    [-50, '2026-09-20', 'previous_balance_amount'],
    ['1.234', '2026-09-20', 'previous_balance_amount'],
    [1000000000000, '2026-09-20', 'previous_balance_amount'],
    [100, '2099-01-01', 'previous_balance_date'],
    [100, '', 'previous_balance_date'],
]);

test('forgotten balances cannot be dated after the current bill or added from a cancelled job', function () {
    $this->post(route('jobs.invoice', $this->job), ['paid_amount' => 0, 'payment_method' => 'Credit/Due'])
        ->assertSessionHasNoErrors();
    $this->job->fresh()->invoice->update(['created_at' => '2026-09-25 10:00:00']);
    $this->post(route('jobs.previous-balance.store', $this->job), [
        'previous_balance_amount' => 100, 'previous_balance_date' => '2026-09-26',
    ])->assertSessionHasErrors('previous_balance_date');
    $this->job->update(['status' => 'Cancelled']);
    $this->post(route('jobs.previous-balance.store', $this->job), [
        'previous_balance_amount' => 100, 'previous_balance_date' => '2026-09-20',
    ])->assertSessionHasErrors('previous_balance_amount');
    expect(Invoice::where('invoice_type', 'Opening Due')->count())->toBe(0);
});

test('viewing jobs does not grant permission to add previous debt', function () {
    $role = Role::create(['name' => 'Job balance viewer', 'permissions' => ['job_card.view']]);
    $this->actingAs(User::factory()->create(['role_id' => $role->id]));
    $this->get(route('jobs.show', $this->job))->assertOk()->assertDontSeeText('Add opening balance');
    $this->post(route('jobs.previous-balance.store', $this->job), [
        'previous_balance_amount' => 100, 'previous_balance_date' => '2026-09-20',
    ])->assertForbidden();
    expect(Invoice::where('invoice_type', 'Opening Due')->count())->toBe(0);
});
