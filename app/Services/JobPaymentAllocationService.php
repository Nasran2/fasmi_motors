<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\JobCard;
use App\Support\PreviousCustomerBalance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobPaymentAllocationService
{
    /** Current bill first, then earlier balances; selected-bill collections can use oldest first. */
    public function receive(JobCard $job, Invoice $primary, array $rows, $date = null, bool $includePrevious = true, ?array $invoiceIds = null, bool $oldestFirst = false, bool $allowCashChange = true): Collection
    {
        return DB::transaction(function () use ($job, $primary, $rows, $date, $includePrevious, $invoiceIds, $oldestFirst, $allowCashChange) {
            if ($invoiceIds !== null && ! $rows) {
                throw ValidationException::withMessages(['amount' => 'Enter a positive payment amount.']);
            }
            $ids = collect([$primary->id]);
            if ($includePrevious) {
                $ids = $ids->merge(PreviousCustomerBalance::forJob($job, $job->invoice)->pluck('invoice_id'));
            }
            if ($invoiceIds !== null) {
                $ids = $ids->intersect($invoiceIds);
            }
            $invoices = Invoice::whereIn('id', $ids->unique())->where('status', '!=', 'Cancelled')
                ->where('invoice_type', '!=', 'Quotation')->orderBy('id')->lockForUpdate()->get();
            if ($invoiceIds !== null && $invoices->where('due_amount', '>', 0)->count() !== count(array_unique($invoiceIds))) {
                throw ValidationException::withMessages(['invoice_ids' => 'A selected bill is no longer payable. Refresh and select the bills again.']);
            }
            $invoices = $invoices->sortBy(fn ($invoice) => ($oldestFirst || $invoice->id !== $primary->id ? '1' : '0').$invoice->bill_date->format('Y-m-d H:i:s').sprintf('%020d', $invoice->id))->values();
            $settlement = app(SettlementService::class);
            $nonCash = array_values(array_filter($rows, fn ($row) => $row['method'] !== 'Cash'));
            $cash = array_values(array_filter($rows, fn ($row) => $row['method'] === 'Cash'));
            $due = round((float) $invoices->sum('due_amount'), 2);
            if ($settlement->total($allowCashChange ? $nonCash : $rows) > $due) {
                throw ValidationException::withMessages([$allowCashChange ? 'payments' : 'amount' => 'Payment amounts cannot exceed the available balance. Only excess cash can be returned as change.']);
            }
            $payments = collect();
            foreach (array_merge($nonCash, $cash) as $row) {
                $available = round((float) $invoices->sum('due_amount'), 2);
                $received = round((float) $row['amount'], 2);
                $remaining = min($received, $available);
                $change = round($received - $remaining, 2);
                $group = (string) Str::uuid();
                $cheque = $remaining > 0 ? $settlement->prepareCheque($invoices->first(fn ($invoice) => $invoice->due_amount > 0), array_replace($row, ['amount' => $remaining])) : null;
                $pieces = collect();
                foreach ($invoices as $invoice) {
                    $amount = min($remaining, (float) $invoice->due_amount);
                    if ($amount <= 0) {
                        continue;
                    }
                    // Legacy bills can identify their customer only through the bike.
                    if (! $invoice->customer_id) {
                        $invoice->update(['customer_id' => $job->customer_id]);
                    }
                    $piece = $settlement->receive($invoice, array_replace($row, ['amount' => $amount]), $date, $cheque);
                    $piece->update(['receipt_group_id' => $group, 'source_job_card_id' => $job->id]);
                    $pieces->push($piece);
                    $invoice->refresh();
                    $remaining = round($remaining - $amount, 2);
                    if ($remaining <= 0) {
                        break;
                    }
                }
                if ($pieces->isEmpty() && $received > 0 && $row['method'] === 'Cash' && $allowCashChange) {
                    $piece = $settlement->receive($primary, $row, $date, allowCashChange: true);
                    $piece->update(['receipt_group_id' => $group, 'source_job_card_id' => $job->id]);
                    $pieces->push($piece);
                } elseif ($change > 0) {
                    $last = $pieces->last();
                    $last->update(['received_amount' => round((float) $last->amount + $change, 2), 'change_amount' => $change]);
                }
                $payments = $payments->concat($pieces);
            }

            return $payments;
        }, 3);
    }
}
