<?php

namespace App\Services\Sms;

use App\Models\Invoice;
use App\Models\Setting;
use App\Models\SmsLog;
use App\Models\SmsSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerDueSmsService
{
    public const TEMPLATE = 'Dear {customer_name}, your outstanding balance for {invoice_numbers} is Rs. {due_amount}. Please arrange payment. Thank you. - {business_name}';

    public function prepare(array $invoiceIds, string $template): array
    {
        $this->checkGateway();
        $invoices = $this->invoices($invoiceIds);
        if ($invoices->count() !== count(array_unique($invoiceIds))) {
            throw ValidationException::withMessages(['invoice_ids' => 'Some selected invoices are no longer unpaid or available. Refresh the due list.']);
        }
        $recipients = $invoices->groupBy(fn ($invoice) => $this->customer($invoice)?->id ?? 'invoice-'.$invoice->id)
            ->map(fn ($group, $key) => $this->recipient($group, (string) $key, $template))->values()->all();
        $token = (string) Str::uuid();
        Cache::put($this->key($token), ['recipients' => $recipients, 'template' => $template], now()->addMinutes(10));

        return ['token' => $token, 'recipients' => $recipients];
    }

    public function send(string $token, string $recipientKey): array
    {
        $this->checkGateway();
        $batch = Cache::get($this->key($token));
        $recipient = collect($batch['recipients'] ?? [])->firstWhere('key', $recipientKey);
        if (! $recipient) {
            throw ValidationException::withMessages(['token' => 'This SMS preview has expired or is unavailable. Preview the messages again.']);
        }
        $resultKey = $this->key($token).':result:'.$recipientKey;

        return Cache::lock($resultKey.':lock', 60)->block(5, function () use ($resultKey, $recipient, $batch, $token) {
            if ($result = Cache::get($resultKey)) {
                return $result;
            }
            $fresh = $this->invoices($recipient['invoice_ids']);
            $current = $this->recipient($fresh, $recipient['key'], $batch['template']);
            if ($current['fingerprint'] !== $recipient['fingerprint']) {
                $result = ['success' => false, 'status' => 'skipped', 'error' => 'Balance or customer details changed. Refresh and preview again.'];
            } elseif ($recipient['error']) {
                $result = ['success' => false, 'status' => 'skipped', 'error' => $recipient['error']];
            } else {
                $reference = 'DUE-'.hash('sha256', $token.':'.$recipient['key']);
                $sent = SmsLog::where('reference_no', $reference)->whereIn('status', ['sent', 'delivered'])->first();
                $result = $sent ? ['success' => true, 'status' => 'sent', 'log_id' => $sent->id] : app(TextitSmsService::class)->send($recipient['phone'], $recipient['message'], [
                    'module' => 'Customer Due', 'message_type' => 'Due Reminder', 'recipient_type' => 'customer',
                    'recipient_name' => $recipient['name'], 'reference_no' => $reference,
                    'related_type' => $this->customer($fresh->first())?->getMorphClass(),
                    'related_id' => $this->customer($fresh->first())?->id,
                ]);
            }
            Cache::put($resultKey, $result, now()->addMinutes(15));

            return $result;
        });
    }

    private function key(string $token): string
    {
        return 'due-sms:'.auth()->id().':'.session('shop_mode', 'workshop').':'.$token;
    }

    private function checkGateway(): void
    {
        $settings = SmsSetting::instance();
        if (! $settings->enabled || ! $settings->manual_sms_enabled) {
            throw ValidationException::withMessages(['sms' => 'Enable the SMS gateway and manual SMS sending in SMS settings first.']);
        }
    }

    private function invoices(array $ids): Collection
    {
        return Invoice::with(['customer', 'bike.customer', 'jobCard.intake.bike.customer'])
            ->whereIn('id', $ids)->whereNotIn('status', ['Cancelled', 'Paid'])
            ->where('invoice_type', '!=', 'Quotation')->where('due_amount', '>', 0)->orderBy('id')->get();
    }

    private function customer(?Invoice $invoice): mixed
    {
        return $invoice?->customer ?? $invoice?->bike?->customer ?? $invoice?->jobCard?->intake?->bike?->customer;
    }

    private function recipient(Collection $invoices, string $key, string $template): array
    {
        $customer = $this->customer($invoices->first());
        $phone = (string) ($customer?->phone ?? '');
        $due = $invoices->sum(fn ($invoice) => (int) round((float) $invoice->due_amount * 100)) / 100;
        $message = strtr($template, [
            '{customer_name}' => $customer?->name ?: 'Customer', '{due_amount}' => number_format($due, 2),
            '{invoice_numbers}' => $invoices->pluck('number')->implode(', '),
            '{business_name}' => (string) Setting::valueFor('business_name', 'Fasmir Motors'),
        ]);
        $fingerprint = hash('sha256', json_encode([$customer?->id, $phone, $customer?->name, $invoices->map(fn ($invoice) => [$invoice->id, $this->customer($invoice)?->id, $invoice->due_amount])->all()]));

        return ['key' => $key, 'invoice_ids' => $invoices->pluck('id')->all(), 'name' => $customer?->name ?: 'Walk-in', 'phone' => $phone,
            'due' => number_format($due, 2), 'message' => $message, 'fingerprint' => $fingerprint,
            'error' => ! $phone ? 'Customer has no phone number.' : (! PhoneFormatter::isValid(PhoneFormatter::format($phone)) ? 'Customer phone number is invalid.' : (mb_strlen($message) > 1000 ? 'Message exceeds 1,000 characters. Select fewer invoices or shorten the message.' : null))];
    }
}
