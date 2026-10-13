<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;

final class JobPaymentSummary
{
    public static function data(JobCard $job, ?Invoice $invoice = null): array
    {
        $invoice ??= $job->invoice;
        $balances = PreviousCustomerBalance::forJob($job, $invoice);
        $olderIds = PreviousCustomerBalance::forJob($job, $invoice, includeSettled: true)->pluck('invoice_id');
        $allocated = Payment::where('source_job_card_id', $job->id)->whereIn('invoice_id', $olderIds)
            ->whereIn('status', ['Completed', 'Hold'])->get();
        $oldApplied = round((float) $allocated->sum('amount'), 2);
        $oldDue = PreviousCustomerBalance::total($balances);

        return [
            'previousDue' => $oldDue,
            'previousBalance' => round($oldDue + $oldApplied, 2),
            'paid' => round((float) $invoice?->paid_amount + $allocated->where('status', 'Completed')->sum('amount'), 2),
            'hold' => round((float) $invoice?->hold_amount + $allocated->where('status', 'Hold')->sum('amount'), 2),
            'received' => round((float) $invoice?->received_amount + $allocated->sum(fn ($payment) => $payment->received_amount ?? $payment->amount), 2),
            'change' => round((float) $invoice?->change_amount + $allocated->sum('change_amount'), 2),
            'due' => round((float) ($invoice?->due_amount ?? $job->total) + $oldDue, 2),
        ];
    }
}
