<?php

use App\Services\ServiceReminderService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reminders:send-due', function () {
    $result = app(ServiceReminderService::class)->sendDue();
    $this->info("Processed {$result['processed']} reminders: {$result['sent']} sent, {$result['failed']} failed.");
})->purpose('Send due service reminders without duplicating successful messages');

Schedule::command('reminders:send-due')->dailyAt('09:00')->withoutOverlapping();
Schedule::command('database:backup --scheduled')->dailyAt('02:00')->withoutOverlapping();
