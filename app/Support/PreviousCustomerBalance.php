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
    /** @return Collection<int, array{reference: string, date: non-falsy-string, amount: float, invoice_id: int, invoice_number: string, paid: float, hold: float, status: string}> */
    public static function forJob(JobCard $job, ?Invoice $current = null, bool $includeSettled = false): Collection
    {
        $job->loadMissing('intake.bike');
        $current ??= $job->invoice;
        $customerId = $current?->customer_id ?? $job->customer_id;
        if (! $customerId) {
            return collect();
        }
        $invoices = Invoice::withoutGlobalScope(ShopScope::class)
            ->with('jobCard')
            ->where('shop_type', $current->shop_type ?? 'workshop')
            ->where('customer_id', $customerId)
            ->where('status', '!=', 'Cancelled')
            ->where('invoice_type', '!=', 'Quotation')
            ->when(! $includeSettled, fn ($query) => $query->where('due_amount', '>', 0))
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
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->number,
            'paid' => (float) $invoice->paid_amount,
            'hold' => (float) $invoice->hold_amount,
            'status' => $invoice->status,
        ]);
    }

    /** @param Collection<int, array{reference: string, date: non-falsy-string, amount: float, invoice_id: int, invoice_number: string, paid: float, hold: float, status: string}> $balances */
    public static function total(Collection $balances): float
    {
        return $balances->sum(fn ($balance) => (int) round($balance['amount'] * 100)) / 100;
    }
}
