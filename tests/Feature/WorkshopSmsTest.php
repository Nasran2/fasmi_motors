<?php

use App\Models\BikeIntake;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\Technician;
use App\Models\User;
use App\Services\Sms\TextitSmsService;
use App\Services\Sms\WorkshopSmsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::sequence()->push('OK:TEST-MSG', 200)->whenEmpty(Http::response('OK:TEST-MSG', 200))]);
    config(['app.url' => 'https://bills.example.com']);
    $this->actingAs(User::factory()->create())->withOpenCashier();
    SmsSetting::instance()->update(['enabled' => true, 'auto_sms_enabled' => true, 'textit_id' => 'test-id', 'textit_password' => 'test-secret']);
    $this->customer = Customer::create(['name' => 'SMS Customer', 'phone' => '0771234567', 'active' => true]);
    $this->bike = $this->customer->bikes()->create(['number' => 'WP SMS 100', 'model' => 'Honda']);
});

function smsTestJob(): JobCard
{
    $intake = BikeIntake::create(['intake_number' => 'IN-SMS-100', 'bike_id' => test()->bike->id, 'complaint' => 'Engine service', 'created_by' => auth()->id()]);

    return JobCard::create(['number' => 'JOB-SMS-100', 'bike_intake_id' => $intake->id, 'priority' => 'Normal', 'status' => 'Received', 'created_by' => auth()->id()]);
}

function smsTestInvoice(?JobCard $job = null): Invoice
{
    return Invoice::create(['number' => 'INV-SMS-100', 'job_card_id' => $job?->id, 'customer_id' => test()->customer->id, 'bike_id' => test()->bike->id,
        'subtotal' => 1000, 'total' => 1000, 'paid_amount' => 0, 'due_amount' => 1000, 'payment_method' => 'Credit/Due', 'created_by' => auth()->id(), 'status' => 'Unpaid', 'public_token' => str_repeat('a', 48), 'link_enabled' => true, 'link_expires_at' => now()->addDays(90)]);
}

test('intake and job creation send separate customer and technician messages after commit', function () {
    $technician = Technician::create(['name' => 'SMS Technician', 'phone' => '0712345678', 'active' => true]);
    DB::transaction(function () use ($technician) {
        $job = smsTestJob();
        $job->update(['main_technician_id' => $technician->id]);
        Http::assertNothingSent();
    });
    Http::assertSentCount(3);
    expect(SmsLog::where('message_type', 'event:received')->first()->message)->toContain('IN-SMS-100')
        ->and(SmsLog::where('message_type', 'event:job_created')->first()->formatted_phone)->toBe('94771234567')
        ->and(SmsLog::where('message_type', 'event:assigned')->first()->formatted_phone)->toBe('94712345678')
        ->and(SmsLog::where('message_type', 'event:assigned')->first()->message)->toContain('Model: Honda')->not->toContain('Priority:', '{bike_model}');
});

test('saved assignment templates replace priority with the bike model in settings and messages', function () {
    Setting::updateOrCreate(['key' => 'sms_tmpl_assigned'], ['group' => 'sms', 'value' => 'Hello {technician_name}. Priority: {priority}. Complaint: {complaint}. Keep your tools ready.']);
    $this->get(route('system-settings.sms-settings'))->assertOk()
        ->assertSeeText('Model: {bike_model}. Complaint: {complaint}. Keep your tools ready.')
        ->assertDontSeeText('Priority: {priority}');
    $technician = Technician::create(['name' => 'Assigned Staff', 'phone' => '0712345678', 'active' => true]);
    $job = smsTestJob();
    $job->update(['main_technician_id' => $technician->id]);
    expect(SmsLog::where('message_type', 'event:assigned')->firstOrFail()->message)
        ->toBe('Hello Assigned Staff. Model: Honda. Complaint: Engine service. Keep your tools ready.');
});

test('quick job assignment uses the optional bike model or a clear missing model label', function (?string $model, string $expected) {
    $technician = Technician::create(['name' => 'Quick Staff', 'phone' => '0712345678', 'active' => true]);
    JobCard::create(['number' => 'JOB-QUICK-SMS', 'is_quick_job' => true, 'quick_bike_details' => ['model' => $model],
        'quick_complaint' => 'Washing', 'main_technician_id' => $technician->id, 'status' => 'Assigned', 'created_by' => auth()->id()]);
    expect(SmsLog::where('message_type', 'event:assigned')->firstOrFail()->message)
        ->toContain('Model: '.$expected, 'Complaint: Washing')->not->toContain('Priority:', '{bike_model}');
})->with([['Yamaha Ray ZR', 'Yamaha Ray ZR'], [null, 'Not recorded']]);

test('rolled back intake never sends SMS', function () {
    try {
        DB::transaction(function () {
            smsTestJob();
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }
    Http::assertNothingSent();
    expect(BikeIntake::count())->toBe(0)->and(SmsLog::count())->toBe(0);
});

test('each event switch and automatic master actually stop requests', function () {
    foreach (WorkshopSmsService::events() as $event => $definition) {
        Setting::updateOrCreate(['key' => 'sms_event_'.$event], ['group' => 'sms', 'value' => '0']);
    }
    $job = smsTestJob();
    $job->update(['status' => 'Completed']);
    smsTestInvoice($job);
    Http::assertNothingSent();
    Setting::where('key', 'sms_event_received')->update(['value' => '1']);
    SmsSetting::instance()->update(['auto_sms_enabled' => false]);
    BikeIntake::create(['intake_number' => 'IN-SMS-200', 'bike_id' => $this->bike->id, 'complaint' => 'Test', 'created_by' => auth()->id()]);
    Http::assertNothingSent();
});

test('invoice SMS uses APP_URL and the linked PDF works without login', function () {
    $invoice = smsTestInvoice();
    $log = SmsLog::where('message_type', 'event:billed')->firstOrFail();
    $pdfUrl = 'https://bills.example.com'.route('invoices.public.pdf', $invoice->public_token, false);
    expect($log->message)->toContain('View bill PDF: '.$pdfUrl)
        ->and($invoice->fresh()->sms_sent_at)->not->toBeNull();
    Http::assertSent(fn ($request) => $request['text'] === $log->message);
    auth()->logout();
    $response = $this->get($pdfUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertDownload('Bill-'.$invoice->number.'.pdf');
    expect(substr($response->getContent(), 0, 4))->toBe('%PDF');
    config(['app.url' => 'https://new.example.com']);
    app(WorkshopSmsService::class)->send('billed', $invoice, true);
    expect(SmsLog::latest('id')->first()->message)->toContain('https://new.example.com'.route('invoices.public.pdf', $invoice->public_token, false));
});

test('saved invoice SMS templates include a PDF link even when the placeholder is missing', function (string $template) {
    Setting::updateOrCreate(['key' => 'sms_tmpl_billed'], ['group' => 'sms', 'value' => $template]);
    config(['app.url' => 'https://bills.example.com/workshop/']);
    $invoice = smsTestInvoice();
    $pdfUrl = 'https://bills.example.com/workshop'.route('invoices.public.pdf', $invoice->public_token, false);
    $message = SmsLog::where('message_type', 'event:billed')->firstOrFail()->message;
    expect($message)->toContain($invoice->number, $pdfUrl)->not->toContain('{bill_link}');
    Http::assertSent(fn ($request) => $request['text'] === $message);
})->with(['Bill {invoice_no}. View bill: {bill_link}', 'Bill {invoice_no}. Thank you.']);

test('bill PDF SMS is blocked when public PDF downloads are disabled', function () {
    Setting::updateOrCreate(['key' => 'public_pdf_download'], ['group' => 'invoice', 'value' => '0']);
    $invoice = smsTestInvoice();
    expect($invoice->fresh()->sms_sent_at)->toBeNull()
        ->and(SmsLog::firstOrFail()->failure_reason)->toContain('Enable public PDF downloads');
    $this->post(route('sales.public-links.sms', $invoice))->assertSessionHas('error');
    Http::assertNothingSent();
    Setting::where('key', 'public_pdf_download')->update(['value' => '1']);
    $this->post(route('sales.public-links.sms', $invoice))->assertSessionHas('success');
    expect($invoice->fresh()->sms_sent_at)->not->toBeNull();
    Http::assertSentCount(1);
});

test('local or disabled invoice links are never sent or marked sent', function () {
    config(['app.url' => 'http://127.0.0.1:8001']);
    $invoice = smsTestInvoice();
    Http::assertNothingSent();
    expect($invoice->fresh()->sms_sent_at)->toBeNull()
        ->and(SmsLog::first()->failure_reason)->toContain('APP_URL');
    config(['app.url' => 'https://bills.example.com']);
    $invoice->update(['link_enabled' => false]);
    expect(app(WorkshopSmsService::class)->send('billed', $invoice, true)['success'])->toBeFalse();
    Http::assertNothingSent();
    $this->get(route('invoices.public', $invoice->public_token))->assertStatus(410);
});

test('provider errors do not mark invoices sent and do not undo billing', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::sequence()->push('OK:NOT-ACCEPTED', 500)->push('OK:RETRY', 200)]);
    $invoice = DB::transaction(fn () => smsTestInvoice());
    expect($invoice->fresh()->sms_sent_at)->toBeNull()->and(SmsLog::first()->status)->toBe('failed')->and(SmsLog::first()->sent_at)->toBeNull();
    expect(app(WorkshopSmsService::class)->send('billed', $invoice)['success'])->toBeTrue()
        ->and(app(WorkshopSmsService::class)->send('billed', $invoice)['status'])->toBe('duplicate');
    Http::assertSentCount(2);
});

test('completion avoids duplicate ready alerts and assignment reaches new technician', function () {
    $job = smsTestJob();
    $job->update(['status' => 'Completed']);
    $job->update(['status' => 'Ready for Delivery']);
    expect(SmsLog::where('message_type', 'event:completed')->count())->toBe(1);
    foreach (['0711111111', '0722222222'] as $phone) {
        $technician = Technician::create(['name' => 'Technician '.$phone, 'phone' => $phone, 'active' => true]);
        $job->update(['main_technician_id' => $technician->id]);
    }
    expect(SmsLog::where('message_type', 'event:assigned')->count())->toBe(2);
});

test('payment notification excludes salary deductions and failed payments', function () {
    Setting::updateOrCreate(['key' => 'sms_event_payment'], ['group' => 'sms', 'value' => '1']);
    $invoice = smsTestInvoice();
    foreach (['Salary Deduction', 'Manual Clearance', 'Credit/Due', 'Cash'] as $index => $method) {
        Payment::create(['number' => 'RCPT-SMS-'.$index, 'invoice_id' => $invoice->id, 'amount' => 200, 'balance_after' => 800, 'method' => $method, 'status' => 'Completed', 'paid_at' => now(), 'created_by' => auth()->id()]);
    }
    Payment::create(['number' => 'RCPT-SMS-FAILED', 'invoice_id' => $invoice->id, 'amount' => 100, 'method' => 'Cash', 'status' => 'Cancelled', 'paid_at' => now(), 'created_by' => auth()->id()]);
    expect(SmsLog::where('message_type', 'event:payment')->count())->toBe(1)
        ->and(SmsLog::where('message_type', 'event:payment')->first()->message)->toContain('200.00', '800.00');
    $pending = Payment::create(['number' => 'RCPT-SMS-PENDING', 'invoice_id' => $invoice->id, 'amount' => 100, 'balance_after' => 700, 'method' => 'Cash', 'status' => 'Pending', 'paid_at' => now(), 'created_by' => auth()->id()]);
    $pending->update(['status' => 'Completed']);
    expect(SmsLog::where('message_type', 'event:payment')->count())->toBe(2);
});

test('settings save updates real gateway keeps password and test button calls provider', function () {
    $data = ['group' => 'sms', 'sms_provider' => 'Textit.biz', 'sms_gateway_url' => 'https://textit.biz/sendmsg', 'textit_id' => 'new-id',
        'textit_password' => '', 'sms_api_method' => 'GET', 'sms_country_code' => '94', 'sms_gateway_enabled' => '1', 'sms_auto_enabled' => '1', 'sms_reminders_enabled' => '1'];
    foreach (WorkshopSmsService::events() as $event => $definition) {
        $data['sms_event_'.$event] = '0';
        $data['sms_tmpl_'.$event] = $definition['template'];
    }
    $this->post(route('system-settings.save'), $data)->assertSessionHasNoErrors()->assertSessionHas('success');
    expect(SmsSetting::instance()->textit_password)->toBe('test-secret')
        ->and(SmsSetting::instance()->textit_id)->toBe('new-id')
        ->and(Setting::valueFor('sms_event_billed'))->toBe('0')
        ->and(Setting::valueFor('reminder_auto_send'))->toBe('1');
    $this->get(route('system-settings.sms-settings'))->assertOk()->assertDontSee('test-secret')->assertSee('role="switch"', false);
    $this->post(route('system-settings.test-sms'), ['phone' => '0771234567', 'message' => 'Authorized test'])->assertSessionHas('success');
    Http::assertSent(fn ($request) => $request['id'] === 'new-id' && $request['text'] === 'Authorized test');
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('ERROR:login failed', 200)]);
    $this->post(route('system-settings.test-sms'), ['phone' => '0771234567', 'message' => 'Failure test'])->assertSessionHas('error');
});

test('POST is form encoded and invalid numbers never call gateway', function () {
    SmsSetting::instance()->update(['api_method' => 'POST']);
    expect(app(TextitSmsService::class)->send('invalid', 'Test')['success'])->toBeFalse();
    Http::assertNothingSent();
    expect(app(TextitSmsService::class)->send('0771234567', 'Test')['success'])->toBeTrue();
    Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->header('Content-Type')[0], 'application/x-www-form-urlencoded'));
});

test('real intake job creation and billing routes send exactly one message per event', function () {
    $technician = Technician::create(['name' => 'Assigned Staff', 'phone' => '0712345678', 'active' => true]);
    $this->post(route('intakes.store'), ['bike_number' => $this->bike->number, 'bike_model' => 'Honda', 'phone' => $this->customer->phone,
        'customer_name' => $this->customer->name, 'complaint' => 'Service'])->assertSessionHasNoErrors();
    $intake = BikeIntake::firstOrFail();
    $this->post(route('jobs.store'), ['bike_intake_id' => $intake->id, 'main_technician_id' => $technician->id, 'priority' => 'Normal'])->assertSessionHasNoErrors();
    $job = JobCard::firstOrFail();
    $this->post(route('sales.job-billing.store', $job), ['service_total' => 1000, 'paid_amount' => 0, 'payment_method' => 'Credit/Due', 'account_id' => null])->assertSessionHasNoErrors();
    Http::assertSentCount(4);
    expect(SmsLog::where('message_type', 'event:billed')->count())->toBe(1)
        ->and($job->fresh()->invoice->sms_sent_at)->not->toBeNull();
});

test('optional progress handover and cancellation events send only when enabled', function () {
    $job = smsTestJob();
    foreach (['progress', 'delivered', 'cancelled'] as $event) {
        Setting::updateOrCreate(['key' => 'sms_event_'.$event], ['group' => 'sms', 'value' => '1']);
    }
    $job->update(['status' => 'In Progress']);
    $job->update(['internal_notes' => 'Non-status update']);
    $job->update(['status' => 'Delivered']);
    $job->update(['status' => 'Cancelled']);
    expect(SmsLog::where('message_type', 'event:progress:In Progress')->count())->toBe(1)
        ->and(SmsLog::where('message_type', 'event:delivered')->count())->toBe(1)
        ->and(SmsLog::where('message_type', 'event:cancelled')->count())->toBe(1);
});

test('connection failure never leaks credentials into SMS logs', function () {
    Http::swap(new Factory);
    Http::fake(fn () => throw new ConnectionException('Connection failed pw=test-secret&id=test-id'));
    $invoice = DB::transaction(fn () => smsTestInvoice());
    $log = SmsLog::firstOrFail();
    expect($invoice->fresh()->sms_sent_at)->toBeNull()->and($log->api_response)->toBe('CONNECTION_FAILED')
        ->and($log->failure_reason)->not->toContain('test-secret')->and($log->api_url)->not->toContain('test-secret');
});

test('bill retry refreshes APP_URL and marks successful timestamp', function () {
    config(['app.url' => 'http://localhost']);
    $invoice = smsTestInvoice();
    $failed = SmsLog::firstOrFail();
    config(['app.url' => 'https://live.example.com']);
    Setting::updateOrCreate(['key' => 'sms_tmpl_billed'], ['group' => 'sms', 'value' => 'Bill {invoice_no}: Rs. {bill_amount}.']);
    $this->post(route('sms.resend', $failed))->assertSessionHas('success');
    expect($invoice->fresh()->sms_sent_at)->not->toBeNull()
        ->and(SmsLog::latest('id')->first()->message)->toContain('View bill PDF: https://live.example.com'.route('invoices.public.pdf', $invoice->public_token, false))
        ->and(SmsLog::latest('id')->first()->retry_count)->toBe(1);
    $invoice->update(['link_expires_at' => now()->subDay()]);
    $this->post(route('sales.public-links.sms', $invoice))->assertSessionHas('error');
    Http::assertSentCount(1);
});
