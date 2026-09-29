<?php

namespace App\Support;

use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceNumber
{
    public static function next(string $invoiceType, ?Carbon $issuedAt = null): string
    {
        $issuedAt ??= now();
        $periodKey = $invoiceType === 'Quotation'
            ? 'QTN-'.$issuedAt->format('Ym')
            : 'INV-'.$issuedAt->format('Ymd');

        return DB::transaction(function () use ($periodKey) {
            DB::table('invoice_number_sequences')->insertOrIgnore([
                'period_key' => $periodKey,
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $sequence = DB::table('invoice_number_sequences')->where('period_key', $periodKey)->lockForUpdate()->first();
            $lastNumber = (int) $sequence->last_number;
            if ($lastNumber === 0) {
                $pattern = '/^'.preg_quote($periodKey, '/').'-(\d{4,})$/';
                $lastNumber = Invoice::withoutGlobalScopes()->where('number', 'like', $periodKey.'-%')
                    ->pluck('number')
                    ->reduce(function (int $max, string $number) use ($pattern) {
                        return preg_match($pattern, $number, $matches) ? max($max, (int) $matches[1]) : $max;
                    }, 0);
            }

            $next = $lastNumber + 1;
            DB::table('invoice_number_sequences')->where('period_key', $periodKey)
                ->update(['last_number' => $next, 'updated_at' => now()]);

            return $periodKey.'-'.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }
}
