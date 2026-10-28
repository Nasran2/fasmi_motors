<?php

namespace App\Support;

use App\Models\Invoice;
use App\Services\AccountService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

final class WashingCharges
{
    public static function queryForPeriod(CarbonInterface|string $from, CarbonInterface|string $to): Builder
    {
        return Invoice::whereNotIn('invoice_type', ['Quotation', 'Opening Due', 'Opening Balance'])
            ->where('status', '!=', 'Cancelled')->where('washing_amount', '>', 0)
            ->billDatedBetween([$from, $to]);
    }

    public static function unpaid(Invoice $invoice): float
    {
        $service = app(AccountService::class);
        $settled = (int) round($invoice->payments()->where('status', 'Completed')
            ->whereNotIn('method', ['Salary Deduction', 'Manual Clearance'])->sum('amount') * 100);
        $budget = $service->receiptComponentBudgets($invoice)['washing'];
        $received = $service->receiptComponentAllocation($invoice, $settled)['washing'];

        return round(max(0, $budget - $received) / 100, 2);
    }

    public static function forPeriod(CarbonInterface|string $from, CarbonInterface|string $to): array
    {
        $invoices = self::queryForPeriod($from, $to)->get();

        return [
            'washing_charges_billed' => round($invoices->sum('washing_amount'), 2),
            'washing_charges_unpaid' => round($invoices->sum(fn ($invoice) => self::unpaid($invoice)), 2),
        ];
    }
}
