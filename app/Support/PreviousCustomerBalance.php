<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\JobCard;
use App\Scopes\ShopScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class PreviousCustomerBalance
{
    /** @return Collection<int, array{reference: string, date: non-falsy-string, amount: float}> */
    public static function forJob(JobCard $job, ?Invoice $current = null): Collection
    {
        $job->loadMissing('intake.bike');
        $current ??= $job->invoice;
        if (! $job->intake->bike->customer_id) {
            return collect();
        }
        $invoices = Invoice::withoutGlobalScope(ShopScope::class)
            ->with('jobCard')
            ->where('shop_type', $current->shop_type ?? 'workshop')
            ->where('customer_id', $job->intake->bike->customer_id)
            ->where('status', '!=', 'Cancelled')
            ->where('invoice_type', '!=', 'Quotation')
            ->where('due_amount', '>', 0)
            ->when($current, function ($query) use ($current) {
                // Reopening a bill must not include bills issued after it.
                $query->where(function ($query) use ($current) {
                    $query->where('created_at', '<', $current->created_at)
                        ->orWhere(function ($query) use ($current) {
                            $query->where('created_at', $current->created_at)->where('id', '<', $current->id);
                        })
                        ->orWhere(function ($query) use ($current) {
                            // An old balance can be recorded later, including on the bill's date.
                            $query->whereIn('invoice_type', ['Opening Balance', 'Opening Due'])
                                ->whereDate('created_at', '<=', $current->created_at->toDateString());
                        });
                });
            })
            ->orderBy('created_at')->orderBy('id')->get();

        $completedDates = DB::table('job_card_status_logs')
            ->whereIn('job_card_id', $invoices->pluck('job_card_id')->filter())
            ->whereIn('new_status', ['Repair Ready', 'Ready for Delivery', 'Completed'])
            ->orderByDesc('created_at')->get()->unique('job_card_id')->pluck('created_at', 'job_card_id');

        return $invoices->map(fn (Invoice $invoice) => [
            'reference' => $invoice->jobCard->number ?? $invoice->number,
            'date' => Carbon::parse($completedDates->get($invoice->job_card_id) ?? $invoice->created_at)->format('d M Y'),
            'amount' => (float) $invoice->due_amount,
        ]);
    }

    /** @param Collection<int, array{reference: string, date: non-falsy-string, amount: float}> $balances */
    public static function total(Collection $balances): float
    {
        return $balances->sum(fn ($balance) => (int) round($balance['amount'] * 100)) / 100;
    }
}
