<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\InvoiceItem;

final class ReportAccounting
{
    public static function revenue(Invoice $invoice): float
    {
        // Stored totals already reflect returns. Opening balances and quotations are not revenue.
        return round(max(0, (float) $invoice->total - (float) $invoice->tax), 2);
    }

    public static function partsRevenue(Invoice $invoice): float
    {
        $subtotal = (float) $invoice->subtotal;

        return $subtotal > 0 ? round(self::revenue($invoice) * min(1, max(0, (float) $invoice->parts_amount / $subtotal)), 2) : 0;
    }

    public static function remainingLine(InvoiceItem $item): float
    {
        return (float) $item->quantity > 0 ? round((float) $item->total - round((float) $item->total * (float) $item->returned_quantity / (float) $item->quantity, 2), 2) : 0;
    }

    public static function itemRevenue(InvoiceItem $item): float
    {
        $invoice = $item->invoice;
        if (! $invoice || (float) $invoice->parts_amount <= 0) {
            return 0;
        }
        $lines = $invoice->items->filter(fn ($line) => self::remainingLine($line) > 0)->sortBy('id');
        $budget = self::partsRevenue($invoice);
        $denominator = $lines->sum(fn ($line) => self::remainingLine($line));
        if ($denominator <= 0) {
            return 0;
        }
        $allocated = 0;
        $weight = 0;
        foreach ($lines as $line) {
            $weight += self::remainingLine($line);
            // Round cumulative shares so heavily discounted invoices never allocate a negative final line.
            $amount = $line->id === $lines->last()->id ? round($budget - $allocated, 2) : round(round($budget * $weight / $denominator, 2) - $allocated, 2);
            if ($line->id === $item->id) {
                return $amount;
            }
            $allocated += $amount;
        }

        return 0;
    }
}
