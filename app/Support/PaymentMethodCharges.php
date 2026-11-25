<?php

namespace App\Support;

use App\Models\Setting;

final class PaymentMethodCharges
{
    public const METHODS = [
        'Cash' => ['key' => 'pm_cash', 'default_rate' => 0.0, 'default_account' => 'bank'],
        'Card' => ['key' => 'pm_card', 'default_rate' => 2.5, 'default_account' => 'bank'],
        'Bank Transfer' => ['key' => 'pm_bank', 'default_rate' => 0.0, 'default_account' => 'bank'],
        'Cheque' => ['key' => 'pm_cheque', 'default_rate' => 0.0, 'default_account' => 'bank'],
        'QR' => ['key' => 'pm_qr', 'default_rate' => 0.0, 'default_account' => 'bank'],
    ];

    public const CHARGE_ACCOUNTS = [
        'main' => 'Main Account — opening balance',
        'spare_parts' => 'Spare Parts Account — opening balance',
        'spare_parts_cashier' => 'Spare Parts Account Cashier — today’s cash',
        'bank' => 'Bank Account',
    ];

    public static function normalizeMethod(?string $method): string
    {
        return $method === 'QR Code' ? 'QR' : (string) $method;
    }

    public static function rateFor(?string $method): float
    {
        $normalized = self::normalizeMethod($method);
        $meta = self::METHODS[$normalized] ?? null;
        if (! $meta) {
            return 0.0;
        }

        $stored = Setting::valueFor($meta['key'].'_charge_percent');
        $rate = $stored === null || $stored === '' ? $meta['default_rate'] : (float) $stored;

        return max(0.0, min(100.0, round($rate, 2)));
    }

    public static function chargeAccountType(?string $method): string
    {
        $normalized = self::normalizeMethod($method);
        $meta = self::METHODS[$normalized] ?? null;
        if (! $meta) {
            return 'bank';
        }

        $stored = Setting::valueFor($meta['key'].'_charge_account', $meta['default_account']);

        return array_key_exists((string) $stored, self::CHARGE_ACCOUNTS)
            ? (string) $stored
            : $meta['default_account'];
    }

    public static function calculate(?string $method, float $amount): float
    {
        if ($amount <= 0) {
            return 0.0;
        }
        $rate = self::rateFor($method);
        if ($rate <= 0) {
            return 0.0;
        }

        return round($amount * ($rate / 100), 2);
    }

    /** @return array{rates: array<string, float>, accounts: array<string, string>} */
    public static function configForUi(): array
    {
        $rates = [];
        $accounts = [];
        foreach (array_keys(self::METHODS) as $method) {
            $rates[$method] = self::rateFor($method);
            $type = self::chargeAccountType($method);
            $accounts[$method] = self::CHARGE_ACCOUNTS[$type] ?? 'Bank Account';
        }
        $rates['QR Code'] = $rates['QR'];
        $accounts['QR Code'] = $accounts['QR'];

        return ['rates' => $rates, 'accounts' => $accounts];
    }
}
