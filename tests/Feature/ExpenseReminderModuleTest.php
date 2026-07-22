<?php

use App\Models\Account;
use App\Models\Customer;
use App\Models\ServiceReminder;
use App\Models\Setting;
use App\Models\SmsSetting;
use App\Models\User;
use App\Services\ServiceReminderService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->actingAs(User::factory()->create(['email_verified_at' => now()]));
    foreach ([['MAIN', 'Main Account', 'main'], ['SPARE', 'Spare Parts Account', 'spare_parts'], ['BANK', 'Bank Account', 'bank']] as [$code, $name, $type]) {
        Account::firstOrCreate(['code' => $code], ['name' => $name, 'type' => $type, 'active' => true]);
    }
});

test('expense drawing and reminder pages render without placeholders', function () {
    $urls = [route('expenses.index'), route('expenses.create'), route('expenses.categories'), route('drawings.index'), route('drawings.create'), route('drawings.categories'), route('service-reminders.page', ['page' => 'upcoming']), route('service-reminders.page', ['page' => 'sent']), route('service-reminders.page', ['page' => 'overdue']), route('service-reminders.page', ['page' => 'sms-logs']), route('service-reminders.page', ['page' => 'settings'])];
    foreach ($urls as $url) {
        $this->get($url)->assertOk()->assertDontSeeText('Page available')->assertDontSeeText('No records to display yet');
    }
});

test('successful reminder messages are not sent twice', function () {
    Http::fake(['*' => Http::response('OK:MSG-100', 200)]);
    SmsSetting::instance()->update(['enabled' => true, 'textit_id' => 'sandbox', 'textit_password' => 'sandbox-secret']);
    $customer = Customer::create(['name' => 'Reminder Customer', 'phone' => '0771234567', 'active' => true]);
    $bike = $customer->bikes()->create(['number' => 'WP REM 100', 'model' => 'Honda Dio']);
    $reminder = ServiceReminder::create(['bike_id' => $bike->id, 'due_date' => today(), 'status' => 'Pending', 'sms_status' => 'Not Sent']);
    $service = app(ServiceReminderService::class);

    expect($service->send($reminder)['success'])->toBeTrue()
        ->and($service->send($reminder->fresh())['status'])->toBe('duplicate')
        ->and($reminder->fresh()->sent_at)->not->toBeNull();
    Http::assertSentCount(1);
});

test('scheduled reminder command sends due reminders using the configured lead time', function () {
    Http::fake(['*' => Http::response('OK:MSG-200', 200)]);
    SmsSetting::instance()->update(['enabled' => true, 'textit_id' => 'sandbox', 'textit_password' => 'sandbox-secret']);
    foreach (['reminders_enabled' => '1', 'reminder_auto_send' => '1', 'reminder_before_days' => '3'] as $key => $value) {
        Setting::updateOrCreate(['key' => $key], ['group' => 'service_reminders', 'value' => $value]);
    }
    $customer = Customer::create(['name' => 'Scheduled Customer', 'phone' => '0712345678', 'active' => true]);
    $bike = $customer->bikes()->create(['number' => 'WP REM 200', 'model' => 'Yamaha FZ']);
    $reminder = ServiceReminder::create(['bike_id' => $bike->id, 'due_date' => today()->addDays(2), 'status' => 'Pending', 'sms_status' => 'Not Sent']);

    $this->artisan('reminders:send-due')->assertSuccessful();

    expect($reminder->fresh()->sms_status)->toBe('sent');
    Http::assertSentCount(1);
});
