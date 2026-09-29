<?php

namespace App\Services\Sms;

use App\Models\SmsLog;
use App\Models\SmsSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TextitSmsService
{
    protected SmsSetting $settings;

    public function __construct()
    {
        $this->settings = SmsSetting::instance();
    }

    /**
     * Send an SMS via Textit.biz.
     *
     * @param  string  $phone  Raw phone number (will be formatted)
     * @param  string  $message  Message body
     * @param  array{
     *   module?: string,
     *   message_type?: string,
     *   recipient_name?: string,
     *   recipient_type?: string,
     *   reference_no?: string,
     *   related_type?: string,
     *   related_id?: int,
     *   sent_by?: int,
     * }  $options
     * @return array{success: bool, status: string, message_id?: string, error?: string, log_id: int}
     */
    public function send(string $phone, string $message, array $options = []): array
    {
        $formatted = PhoneFormatter::format($phone);

        $logData = [
            'phone' => $phone,
            'phone_number' => $phone,
            'formatted_phone' => $formatted,
            'message' => $message,
            'module' => $options['module'] ?? 'manual',
            'message_type' => $options['message_type'] ?? 'Manual SMS',
            'recipient_name' => $options['recipient_name'] ?? null,
            'recipient_type' => $options['recipient_type'] ?? 'custom',
            'reference_no' => $options['reference_no'] ?? null,
            'reference_type' => $options['related_type'] ?? null,
            'reference_id' => $options['related_id'] ?? null,
            'provider' => 'textit',
            'retry_count' => $options['retry_count'] ?? 0,
            'sent_by' => $options['sent_by'] ?? auth()->id(),
            'sent_at' => null,
        ];

        // Check if SMS is enabled
        if (! $this->settings->enabled) {
            $logData['status'] = 'failed';
            $logData['failure_reason'] = 'SMS gateway is disabled in settings.';
            $logData['api_response'] = 'DISABLED';
            $log = SmsLog::create($logData);

            return ['success' => false, 'status' => 'failed', 'error' => 'SMS gateway is disabled.', 'log_id' => $log->id];
        }

        // Validate phone
        if (! PhoneFormatter::isValid($formatted)) {
            $logData['status'] = 'failed';
            $logData['failure_reason'] = "Invalid phone number: {$phone}";
            $logData['api_response'] = 'INVALID_NUMBER';
            $log = SmsLog::create($logData);

            return ['success' => false, 'status' => 'failed', 'error' => 'Invalid phone number.', 'log_id' => $log->id];
        }

        // Build params
        $params = [
            $this->settings->id_param => $this->settings->textit_id,
            $this->settings->password_param => $this->settings->textit_password,
            $this->settings->to_param => $formatted,
            $this->settings->text_param => $message,
        ];

        if (! empty($options['reference_no'])) {
            $params[$this->settings->ref_param] = substr($options['reference_no'], 0, 15);
        }

        $apiUrl = $this->settings->gateway_url;
        $logData['api_url'] = $apiUrl.'?'.http_build_query(array_merge($params, [$this->settings->id_param => '***', $this->settings->password_param => '***']));

        try {
            $http = Http::timeout(20);

            if (strtoupper($this->settings->api_method) === 'POST') {
                $response = $http->asForm()->post($apiUrl, $params);
            } else {
                $response = $http->get($apiUrl, $params);
            }

            $body = trim($response->body());
            $parts = explode(':', $body, 2);
            $responseStatus = trim($parts[0] ?? '');
            $responsePayload = trim($parts[1] ?? '');

            $logData['api_response'] = $body;

            if ($response->successful() && $responseStatus === $this->settings->success_keyword) {
                $logData['status'] = 'sent';
                $logData['sent_at'] = now();
                $logData['provider_message_id'] = $responsePayload;
                $log = SmsLog::create($logData);

                return ['success' => true, 'status' => 'sent', 'message_id' => $responsePayload, 'log_id' => $log->id];
            } else {
                $friendly = $response->successful() ? $this->friendlyError($responsePayload) : 'SMS provider returned HTTP '.$response->status().'.';
                $logData['status'] = 'failed';
                $logData['failure_reason'] = $friendly;
                $log = SmsLog::create($logData);

                return ['success' => false, 'status' => 'failed', 'error' => $friendly, 'log_id' => $log->id];
            }
        } catch (\Throwable $e) {
            Log::error('Textit SMS connection failed.', ['exception' => $e::class]);
            $logData['status'] = 'failed';
            $logData['api_response'] = 'CONNECTION_FAILED';
            $logData['failure_reason'] = 'Gateway connection failed. Check connectivity and gateway configuration.';
            $log = SmsLog::create($logData);

            return ['success' => false, 'status' => 'failed', 'error' => 'SMS gateway connection failed.', 'log_id' => $log->id];
        }
    }

    /**
     * Resend a failed SMS log entry (creates a new log).
     */
    public function resend(SmsLog $smsLog, ?int $sentBy = null): array
    {
        return $this->send($smsLog->phone_number ?? $smsLog->phone, $smsLog->message, [
            'module' => $smsLog->module,
            'message_type' => $smsLog->message_type,
            'recipient_name' => $smsLog->recipient_name,
            'recipient_type' => $smsLog->recipient_type,
            'reference_no' => $smsLog->reference_no,
            'related_type' => $smsLog->reference_type,
            'related_id' => $smsLog->reference_id,
            'retry_count' => $smsLog->retry_count + 1,
            'sent_by' => $sentBy ?? auth()->id(),
        ]);
    }

    /**
     * Map provider error codes to user-friendly messages.
     */
    protected function friendlyError(string $raw): string
    {
        $map = [
            'login failed' => 'Invalid SMS gateway credentials. Check your Textit ID and password.',
            'authentication' => 'SMS provider authentication failed.',
            'insufficient' => 'Insufficient SMS balance in Textit account.',
            'invalid dest' => 'Invalid destination phone number.',
            'invalid number' => 'Invalid phone number format.',
            'blacklisted' => 'Phone number is blacklisted.',
            'credit' => 'Insufficient SMS credit.',
        ];

        $lower = strtolower($raw);
        foreach ($map as $key => $message) {
            if (str_contains($lower, $key)) {
                return $message;
            }
        }

        return empty($raw) ? 'Provider returned an unknown error.' : "Provider error: {$raw}";
    }
}
