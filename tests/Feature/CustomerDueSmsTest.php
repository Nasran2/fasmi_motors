<?php

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use App\Models\User;
use App\Services\Sms\CustomerDueSmsService;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response('OK:DUE-TEST', 200)]);
    $this->actingAs(User::factory()->create());
    SmsSetting::instance()->update(['enabled' => true, 'manual_sms_enabled' => true, 'auto_sms_enabled' => false, 'textit_id' => 'test', 'textit_password' => 'secret']);
    $this->customer = Customer::create(['name' => 'Due Customer', 'phone' => '0771234567', 'active' => true]);
});

function dueSmsInvoice(Customer $customer, array $overrides = []): Invoice
{
    return Invoice::create($overrides + ['number' => 'DUE-'.Str::random(10), 'customer_id' => $customer->id, 'subtotal' => 1000, 'total' => 1000,
        'paid_amount' => 500, 'due_amount' => 500, 'status' => 'Partially Paid', 'public_token' => Str::random(48), 'created_by' => auth()->id()]);
}

function dueSmsPreview(array $ids): array
{
    return test()->postJson(route('customers.dues.sms.preview'), ['invoice_ids' => $ids, 'template' => CustomerDueSmsService::TEMPLATE])->assertOk()->json();
}

test('due page renders individual and bulk SMS controls', function () {
    $invoice = dueSmsInvoice($this->customer);
    $this->get(route('customers.dues'))->assertOk()->assertSee('SMS Selected')->assertSee('SMS due reminder for '.$invoice->number)
        ->assertSee('Preview Messages')->assertSee('data-due-sms-all', false);
    Http::assertNothingSent();
});

test('single reminder previews current balance and sends only once per preview', function () {
    $invoice = dueSmsInvoice($this->customer);
    $preview = dueSmsPreview([$invoice->id]);
    Http::assertNothingSent();
    expect($preview['recipients'][0]['message'])->toContain('Due Customer', $invoice->number, '500.00');
    $payload = ['token' => $preview['token'], 'recipient_key' => $preview['recipients'][0]['key']];
    $this->postJson(route('customers.dues.sms.send'), $payload)->assertOk()->assertJsonPath('success', true);
    $this->postJson(route('customers.dues.sms.send'), $payload)->assertOk()->assertJsonPath('success', true);
    Http::assertSentCount(1);
    expect(SmsLog::first()->module)->toBe('Customer Due')->and(SmsLog::first()->recipient_type)->toBe('customer');
});

test('bulk groups selected bills into one SMS per customer without merging shared phone customers', function () {
    $other = Customer::create(['name' => 'Different Customer', 'phone' => $this->customer->phone, 'active' => true]);
    $one = dueSmsInvoice($this->customer);
    $two = dueSmsInvoice($this->customer, ['due_amount' => 125.50]);
    $three = dueSmsInvoice($other, ['due_amount' => 300]);
    $preview = dueSmsPreview([$one->id, $two->id, $three->id]);
    expect($preview['recipients'])->toHaveCount(2)
        ->and($preview['recipients'][0]['message'])->toContain('625.50', $one->number, $two->number);
    foreach ($preview['recipients'] as $recipient) {
        $this->postJson(route('customers.dues.sms.send'), ['token' => $preview['token'], 'recipient_key' => $recipient['key']])->assertJsonPath('success', true);
    }
    Http::assertSentCount(2);
});

test('paid cancelled and foreign shop invoices cannot be included', function () {
    foreach ([['status' => 'Paid', 'due_amount' => 0], ['status' => 'Cancelled'], ['shop_type' => 'helmet'], ['invoice_type' => 'Quotation']] as $overrides) {
        $invoice = dueSmsInvoice($this->customer, $overrides);
        $this->postJson(route('customers.dues.sms.preview'), ['invoice_ids' => [$invoice->id], 'template' => CustomerDueSmsService::TEMPLATE])->assertUnprocessable();
    }
    Http::assertNothingSent();
});

test('balance and phone changes after preview prevent stale reminders', function () {
    $invoice = dueSmsInvoice($this->customer);
    $preview = dueSmsPreview([$invoice->id]);
    $invoice->update(['due_amount' => 100]);
    $this->postJson(route('customers.dues.sms.send'), ['token' => $preview['token'], 'recipient_key' => $preview['recipients'][0]['key']])->assertJsonPath('status', 'skipped');
    $preview = dueSmsPreview([$invoice->id]);
    $this->customer->update(['phone' => '0712345678']);
    $this->postJson(route('customers.dues.sms.send'), ['token' => $preview['token'], 'recipient_key' => $preview['recipients'][0]['key']])->assertJsonPath('status', 'skipped');
    Http::assertNothingSent();
});

test('invalid phone is skipped while valid bulk recipient succeeds', function () {
    $invalid = Customer::create(['name' => 'Invalid Phone', 'phone' => 'bad-number', 'active' => true]);
    $preview = dueSmsPreview([dueSmsInvoice($invalid)->id, dueSmsInvoice($this->customer)->id]);
    foreach ($preview['recipients'] as $recipient) {
        $response = $this->postJson(route('customers.dues.sms.send'), ['token' => $preview['token'], 'recipient_key' => $recipient['key']]);
        $response->assertJsonPath('success', $recipient['error'] === null);
    }
    Http::assertSentCount(1);
});

test('preview tokens are private to the user expire and respect gateway switches', function () {
    $preview = dueSmsPreview([dueSmsInvoice($this->customer)->id]);
    $user = auth()->user();
    $payload = ['token' => $preview['token'], 'recipient_key' => $preview['recipients'][0]['key']];
    $this->actingAs(User::factory()->create())->postJson(route('customers.dues.sms.send'), $payload)->assertUnprocessable();
    $this->actingAs($user);
    $this->travel(11)->minutes();
    $this->postJson(route('customers.dues.sms.send'), $payload)->assertUnprocessable();
    $this->travelBack();
    SmsSetting::instance()->update(['manual_sms_enabled' => false]);
    $this->postJson(route('customers.dues.sms.preview'), ['invoice_ids' => [Invoice::first()->id], 'template' => CustomerDueSmsService::TEMPLATE])->assertUnprocessable();
    Http::assertNothingSent();
});

test('provider failure is logged accurately and replay does not retry the same batch', function () {
    Http::swap(new Factory);
    Http::fake(['*' => Http::response('ERROR:insufficient credit', 200)]);
    $preview = dueSmsPreview([dueSmsInvoice($this->customer)->id]);
    $payload = ['token' => $preview['token'], 'recipient_key' => $preview['recipients'][0]['key']];
    $this->postJson(route('customers.dues.sms.send'), $payload)->assertJsonPath('success', false)->assertJsonPath('status', 'failed');
    $this->postJson(route('customers.dues.sms.send'), $payload)->assertJsonPath('success', false);
    Http::assertSentCount(1);
    $log = SmsLog::first();
    expect($log->failure_reason)->toContain('Insufficient')->and($log->sent_at)->toBeNull();
    $this->post(route('sms.resend', $log))->assertSessionHas('error');
    Http::assertSentCount(1);
});
