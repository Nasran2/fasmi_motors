<?php

namespace App\Services;

use App\Models\JobCard;
use App\Models\ServiceReminder;
use App\Models\Setting;
use App\Services\Sms\TextitSmsService;
use Illuminate\Support\Str;

class ServiceReminderService
{
    public function createForJob(JobCard $job): ?ServiceReminder
    {
        if (! $this->enabled()) {
            return null;
        }

        $job->loadMissing('intake.bike');
        $intake = $job->intake;
        if (! $intake?->bike) {
            return null;
        }

        $defaultDays = max(1, (int) Setting::valueFor('reminder_default_days', Setting::valueFor('default_reminder_days', 60)));
        $defaultKm = max(0, (int) Setting::valueFor('reminder_default_km', Setting::valueFor('default_reminder_km', 3000)));
        $dueDate = $intake->next_service_date ?: today()->addDays($defaultDays);
        $dueKm = $intake->next_service_km ?: ((int) ($intake->current_mileage ?? 0) + $defaultKm);

        return ServiceReminder::updateOrCreate(
            ['job_card_id' => $job->id],
            [
                'bike_id' => $intake->bike_id,
                'due_date' => $dueDate,
                'due_km' => $dueKm > 0 ? $dueKm : null,
                'reminder_type' => 'Date Based',
                'last_service_date' => today(),
                'last_service_km' => $intake->current_mileage,
                'status' => 'Pending',
                'sms_status' => 'Not Sent',
                'created_by' => auth()->id(),
            ],
        );
    }

    public function send(ServiceReminder $reminder, ?int $sentBy = null): array
    {
        $reminder->loadMissing('bike.customer');
        if ($reminder->sms_status === 'sent' && $reminder->sent_at) {
            return ['success' => false, 'status' => 'duplicate', 'error' => 'This reminder was already sent.'];
        }

        $phone = $reminder->bike?->customer?->phone;
        if (! $phone) {
            return ['success' => false, 'status' => 'failed', 'error' => 'This customer has no phone number.'];
        }

        $result = app(TextitSmsService::class)->send($phone, $this->message($reminder), [
            'module' => 'Service Reminder',
            'message_type' => 'Reminder SMS',
            'recipient_name' => $reminder->bike?->customer?->name,
            'recipient_type' => 'customer',
            'reference_no' => $reminder->bike?->number,
            'related_type' => $reminder->getMorphClass(),
            'related_id' => $reminder->id,
            'sent_by' => $sentBy,
        ]);

        $reminder->update([
            'sms_status' => $result['success'] ? 'sent' : 'failed',
            'sent_at' => $result['success'] ? now() : null,
        ]);

        return $result;
    }

    public function sendDue(): array
    {
        if (! $this->enabled() || ! $this->autoSendEnabled()) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0];
        }

        $leadDays = max(0, (int) Setting::valueFor('reminder_before_days', Setting::valueFor('reminder_send_before', 3)));
        $reminders = ServiceReminder::query()
            ->where('status', 'Pending')
            ->whereNull('sent_at')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today()->addDays($leadDays))
            ->orderBy('due_date')
            ->limit(500)
            ->get();

        $sent = 0;
        $failed = 0;
        foreach ($reminders as $reminder) {
            $result = $this->send($reminder);
            $result['success'] ? $sent++ : $failed++;
        }

        return ['processed' => $reminders->count(), 'sent' => $sent, 'failed' => $failed];
    }

    private function enabled(): bool
    {
        return filter_var(Setting::valueFor('reminders_enabled', Setting::valueFor('reminder_enabled', true)), FILTER_VALIDATE_BOOL);
    }

    private function autoSendEnabled(): bool
    {
        return filter_var(Setting::valueFor('reminder_auto_send', Setting::valueFor('reminder_auto_sms', false)), FILTER_VALIDATE_BOOL);
    }

    private function message(ServiceReminder $reminder): string
    {
        $template = Setting::valueFor('reminder_sms_template', 'Dear {customer_name}, your bike {bike_number} is due for service on {next_service_date}. Please visit {business_name}. Call {phone}.');

        return Str::replace(
            ['{customer_name}', '{bike_number}', '{bike_model}', '{next_service_date}', '{business_name}', '{phone}'],
            [$reminder->bike?->customer?->name, $reminder->bike?->number, $reminder->bike?->model, $reminder->due_date?->format('d M Y'), Setting::valueFor('business_name', 'Fasmir Motors'), Setting::valueFor('business_phone', '')],
            $template,
        );
    }
}
