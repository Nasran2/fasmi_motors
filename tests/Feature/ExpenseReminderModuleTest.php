<?php

use App\Models\Account;
use App\Models\User;

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
