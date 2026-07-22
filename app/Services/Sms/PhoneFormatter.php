<?php

namespace App\Services\Sms;

class PhoneFormatter
{
    /**
     * Convert a Sri Lankan phone number to international format (94xxxxxxxxx).
     * Strips spaces, dashes, parentheses, etc.
     */
    public static function format(string $phone): string
    {
        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);

        // If starts with +94 (now just 94 after stripping +)
        if (str_starts_with($digits, '94') && strlen($digits) === 11) {
            return $digits;
        }

        // If starts with 0 and 10 digits total (local Sri Lankan format)
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '94'.substr($digits, 1);
        }

        // Already 9 digits without country code — prepend 94
        if (strlen($digits) === 9) {
            return '94'.$digits;
        }

        // Return as-is (let the provider reject it if invalid)
        return $digits;
    }

    /**
     * Validate that a formatted number looks like a Sri Lankan mobile.
     */
    public static function isValid(string $formatted): bool
    {
        return (bool) preg_match('/^94[0-9]{9}$/', $formatted);
    }
}
