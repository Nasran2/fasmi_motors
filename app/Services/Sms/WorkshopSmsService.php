<?php

namespace App\Services\Sms;

use App\Models\BikeIntake;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WorkshopSmsService
{
    public static function events(): array
    {
        return [
            'received' => ['label' => 'Bike intake received', 'recipient' => 'Customer', 'default' => 1, 'template' => 'Dear {customer_name}, we received your bike {bike_no}. Intake: {intake_no}. - {business_name}'],
            'job_created' => ['label' => 'Job card created', 'recipient' => 'Customer', 'default' => 1, 'template' => 'Dear {customer_name}, job card {job_no} was created for bike {bike_no}. - {business_name}'],
            'assigned' => ['label' => 'Technician assigned or reassigned', 'recipient' => 'Technician', 'default' => 1, 'template' => 'Dear {technician_name}, job {job_no}, bike {bike_no}, is assigned to you. Model: {bike_model}. Complaint: {complaint}. - {business_name}'],
            'progress' => ['label' => 'Repair progress updated', 'recipient' => 'Customer', 'default' => 0, 'template' => 'Dear {customer_name}, bike {bike_no}, job {job_no}: {status}. - {business_name}'],
            'completed' => ['label' => 'Repair completed / ready for pickup', 'recipient' => 'Customer', 'default' => 1, 'template' => 'Dear {customer_name}, bike {bike_no}, job {job_no}, is ready for pickup. - {business_name}'],
            'billed' => ['label' => 'Invoice issued with bill link', 'recipient' => 'Customer', 'default' => 1, 'template' => 'Dear {customer_name}, bill {invoice_no}: Rs. {bill_amount}. Balance: Rs. {due_amount}. View bill: {bill_link} - {business_name}'],
            'payment' => ['label' => 'Payment received', 'recipient' => 'Customer', 'default' => 0, 'template' => 'Dear {customer_name}, payment Rs. {paid_amount} received for {invoice_no}. Receipt: {receipt_no}. Balance: Rs. {due_amount}. - {business_name}'],
            'delivered' => ['label' => 'Bike handed over', 'recipient' => 'Customer', 'default' => 0, 'template' => 'Dear {customer_name}, bike {bike_no}, job {job_no}, has been handed over. Thank you. - {business_name}'],
            'cancelled' => ['label' => 'Job cancelled', 'recipient' => 'Customer', 'default' => 0, 'template' => 'Dear {customer_name}, job {job_no} for bike {bike_no} has been cancelled. - {business_name}'],
        ];
    }

    public static function normalizeTemplate(string $event, string $template): string
    {
        if ($event !== 'assigned') {
            return $template;
        }

        // Existing saved assignment templates must use the model too.
        $template = preg_replace('/\bPriority\s*[:\-]?\s*\{priority\}/i', 'Model: {bike_model}', $template);

        return str_replace('{priority}', '{bike_model}', $template);
    }

    public static function publicBaseUrl(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    public static function hasPublicBaseUrl(): bool
    {
        $url = self::publicBaseUrl();
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && $host !== '' && $host !== 'localhost' && ! str_ends_with($host, '.local')
            && ! str_ends_with($host, '.localhost')
            && (! filter_var($host, FILTER_VALIDATE_IP) || (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
    }

    public function send(string $event, Model $record, bool $manual = false, ?int $sentBy = null, int $retryCount = 0): array
    {
        $definition = self::events()[$event];
        $gateway = SmsSetting::instance();
        if (! $manual && (! $gateway->enabled || ! $gateway->auto_sms_enabled || ! (bool) Setting::valueFor('sms_event_'.$event, $definition['default']))) {
            return ['success' => false, 'status' => 'disabled', 'error' => 'This automatic SMS event is switched off.'];
        }
        if ($manual && ! $gateway->manual_sms_enabled) {
            return ['success' => false, 'status' => 'disabled', 'error' => 'Manual SMS sending is switched off.'];
        }

        $record->refresh();
        $job = $record instanceof JobCard ? $record : ($record instanceof Invoice ? $record->jobCard : null);
        $invoice = $record instanceof Invoice ? $record : ($record instanceof Payment ? $record->invoice : $job?->invoice);
        $intake = $record instanceof BikeIntake ? $record : $job?->intake;
        $bike = $job?->bike ?? $intake?->bike ?? $invoice?->bike;
        $customer = $job?->customer ?? $bike?->customer ?? $invoice?->customer;
        $recipient = $event === 'assigned' ? $job?->technician : $customer;
        $phone = (string) ($recipient?->phone ?? '');
        $status = (string) ($job?->status ?? '');
        $key = implode(':', [$event, $record->getMorphClass(), $record->id, $event === 'progress' ? $status : '', PhoneFormatter::format($phone)]);

        return Cache::lock('workshop-sms:'.hash('sha256', $key), 60)->block(5, function () use ($event, $record, $manual, $sentBy, $retryCount, $job, $invoice, $intake, $bike, $customer, $recipient, $phone, $status, $definition) {
            $query = SmsLog::where('message_type', $event === 'progress' ? 'event:progress:'.$status : 'event:'.$event)
                ->where('reference_type', $record->getMorphClass())->where('reference_id', $record->id)
                ->where('formatted_phone', PhoneFormatter::format($phone))->whereIn('status', ['sent', 'delivered']);
            if (! $manual && $query->exists()) {
                return ['success' => true, 'status' => 'duplicate'];
            }
            $values = [
                '{customer_name}' => $customer?->name ?? 'Customer', '{technician_name}' => $job?->technician?->name ?? '',
                '{bike_no}' => $bike?->number ?? '', '{intake_no}' => $intake?->intake_number ?? '', '{job_no}' => $job?->number ?? '',
                '{bike_model}' => $bike?->model ?: 'Not recorded',
                '{invoice_no}' => $invoice?->number ?? '', '{receipt_no}' => $record instanceof Payment ? $record->number : '',
                '{bill_amount}' => number_format((float) ($invoice?->total ?? $job?->total ?? 0), 2),
                '{paid_amount}' => number_format((float) ($record instanceof Payment ? $record->amount : $invoice?->paid_amount), 2),
                '{due_amount}' => number_format((float) ($record instanceof Payment ? $record->balance_after : $invoice?->due_amount), 2),
                '{priority}' => $job?->priority ?? '', '{complaint}' => $job?->complaint ?? $intake?->complaint ?? '', '{status}' => $status,
                '{business_name}' => Setting::valueFor('business_name', 'Fasmir Motors'),
                '{bill_link}' => $invoice?->public_token ? self::publicBaseUrl().route('invoices.public', $invoice->public_token, false) : '',
            ];
            $template = self::normalizeTemplate($event, (string) Setting::valueFor('sms_tmpl_'.$event, $definition['template']));
            if ($event === 'billed' && ! str_contains($template, '{bill_link}')) {
                $template .= ' View bill: {bill_link}';
            }
            $message = strtr($template, $values);
            $options = ['module' => 'Workshop automation', 'message_type' => $event === 'progress' ? 'event:progress:'.$status : 'event:'.$event,
                'recipient_name' => $recipient?->name, 'recipient_type' => strtolower($definition['recipient']), 'reference_no' => $record->number ?? $intake?->intake_number,
                'related_type' => $record->getMorphClass(), 'related_id' => $record->id, 'sent_by' => $sentBy ?? auth()->id(), 'retry_count' => $retryCount];
            $error = null;
            if ($event === 'billed' && (! $invoice?->public_token || ! $invoice->link_enabled || $invoice->link_expires_at?->isPast() || $invoice->status === 'Cancelled')) {
                $error = 'The invoice link is disabled, expired, or unavailable.';
            } elseif ($event === 'billed' && ! self::hasPublicBaseUrl()) {
                $error = 'Set APP_URL to the public HTTPS address in .env; localhost links cannot be opened by customers.';
            }
            if ($error) {
                $log = SmsLog::create(['phone' => $phone, 'phone_number' => $phone, 'formatted_phone' => PhoneFormatter::format($phone), 'message' => $message,
                    'module' => $options['module'], 'message_type' => $options['message_type'], 'recipient_name' => $options['recipient_name'], 'recipient_type' => $options['recipient_type'],
                    'reference_no' => $options['reference_no'], 'reference_type' => $options['related_type'], 'reference_id' => $record->id,
                    'status' => 'failed', 'failure_reason' => $error, 'sent_by' => $options['sent_by'], 'retry_count' => $retryCount]);

                return ['success' => false, 'status' => 'failed', 'error' => $error, 'log_id' => $log->id];
            }
            $result = app(TextitSmsService::class)->send($phone, $message, $options);
            if ($event === 'billed' && $result['success']) {
                $invoice->updateQuietly(['sms_sent_at' => now()]);
            }

            return $result;
        });
    }

    public function safelySend(string $event, Model $record, ?int $sentBy): void
    {
        try {
            $this->send($event, $record, false, $sentBy);
        } catch (\Throwable $e) {
            // Notification failures must not undo committed workshop or financial records.
            Log::error('Workshop SMS automation failed.', ['event' => $event, 'record_id' => $record->id, 'exception' => $e::class]);
        }
    }
}
