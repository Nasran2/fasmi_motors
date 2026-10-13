<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerReceiptService
{
    public function receive(Customer $customer, array $rows, string $paidAt, ?int $invoiceId = null, ?array $invoiceIds = null): void
    {
        DB::transaction(function () use ($customer, $rows, $paidAt, $invoiceId, $invoiceIds) {
            $settlement = app(SettlementService::class);
            $invoices = Invoice::customerAccount($customer->id)->where('status', '!=', 'Cancelled')
                ->where('invoice_type', '!=', 'Quotation')->where('due_amount', '>', 0)
                ->when($invoiceId, fn ($query) => $query->whereKey($invoiceId))
                ->when($invoiceIds !== null, fn ($query) => $query->whereKey($invoiceIds))
                ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            if ($invoiceIds !== null && $invoices->count() !== count(array_unique($invoiceIds))) {
                throw ValidationException::withMessages(['invoice_ids' => 'A selected bill is no longer payable. Refresh the list and select the bills again.']);
            }
            if (! $rows || $invoices->isEmpty() || $settlement->total($rows) > round((float) $invoices->sum('due_amount'), 2)) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount up to the customer’s outstanding due.']);
            }
            foreach ($rows as $row) {
                $remaining = (float) $row['amount'];
                $first = $invoices->first(fn (Invoice $invoice) => (float) $invoice->fresh()->due_amount > 0);
                if (! $first->customer_id) {
                    $first->update(['customer_id' => $customer->id]);
                }
                $cheque = $settlement->prepareCheque($first, $row);
                foreach ($invoices as $invoice) {
                    $invoice->refresh();
                    $applied = min($remaining, (float) $invoice->due_amount);
                    if ($applied > 0) {
                        // Legacy job bills may only identify the customer through their bike.
                        if (! $invoice->customer_id) {
                            $invoice->update(['customer_id' => $customer->id]);
                        }
                        $settlement->receive($invoice, array_replace($row, ['amount' => $applied]), $paidAt, $cheque);
                    }
                    $remaining = round($remaining - $applied, 2);
                    if ($remaining <= 0) {
                        break;
                    }
                }
            }
        });
    }
}
