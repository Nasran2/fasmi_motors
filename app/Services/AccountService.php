<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function ledgerOpeningBalance(string $type, CarbonInterface|string $date): float
    {
        $prior = AccountTransaction::ledger($type)->posted()->where('transaction_date', '<', $date);
        $base = $type === 'cash' ? 0 : (float) (Account::systemAccount($type)->opening_balance ?? 0);

        return round($base + (float) (clone $prior)->sum('credit') - (float) (clone $prior)->sum('debit'), 2);
    }

    /**
     * Record a transaction ensuring running balance is calculated correctly.
     */
    public function recordTransaction(
        string $accountType,
        string $transactionType,
        float $amount,
        string $description,
        ?Model $reference = null,
        ?string $paymentMethod = null,
        ?string $note = null,
        ?int $accountId = null,
        CarbonInterface|string|null $transactionDate = null,
        ?string $category = null
    ): AccountTransaction {
        return DB::transaction(function () use ($accountType, $transactionType, $amount, $description, $reference, $paymentMethod, $note, $accountId, $transactionDate, $category) {

            // Determine debit/credit logic based on transaction type and account
            // Income/receipts increase balance
            // Expenses/payments decrease balance

            $isIncrease = in_array($transactionType, [
                'income', 'payment', 'transfer_in', 'payment_received', 'opening_balance', 'adjustment_increase',
            ]);

            $debit = 0;
            $credit = 0;

            // Accounting rule: For asset accounts (Bank/Cash), debit increases balance. For income accounts, credit increases balance.
            // However, to keep it simple and understandable for the ERP users:
            // "Credit" is money IN (increase). "Debit" is money OUT (decrease).

            if ($isIncrease) {
                $credit = $amount;
            } else {
                $debit = $amount;
            }

            // Logical ledgers always post to their own account. Cash may use a selected
            // physical account, while MAIN/SPARE/BANK must never inherit a UI account id.
            if ($accountType !== 'cash') {
                $accountId = Account::systemAccount($accountType)?->id;
            }

            $account = $accountId ? Account::lockForUpdate()->findOrFail($accountId) : Account::systemAccount($accountType);
            if (! $account || ! $account->active || $amount < 0 || ! is_finite($amount)) {
                throw ValidationException::withMessages(['account_id' => 'Select an active account and a valid amount.']);
            }
            $accountId = $account->id;
            if ($reference instanceof Payment && $transactionType === 'income') {
                $existing = AccountTransaction::where('account_id', $accountId)->where('account_type', $accountType)
                    ->where('reference_type', $reference->getMorphClass())->where('reference_id', $reference->id)
                    ->where('transaction_type', $transactionType)->where('status', 'active')->first();
                if ($existing) {
                    return $existing;
                }
            }
            $amount = round($amount, 2);
            $debit = round($debit, 2);
            $credit = round($credit, 2);

            // Lock the last entry so two simultaneous payments cannot calculate the same balance.
            $lastTransaction = AccountTransaction::where('account_type', $accountType)
                ->where('account_id', $accountId)
                ->latest('transaction_date')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $previousBalance = (float) $account->opening_balance
                + (float) $account->transactions()->posted()->sum('credit')
                - (float) $account->transactions()->posted()->sum('debit');
            $newBalance = round($previousBalance + $credit - $debit, 2);

            $transaction = AccountTransaction::create([
                'transaction_no' => 'TRX-'.strtoupper(Str::random(8)),
                'transaction_date' => $transactionDate ?: now(),
                'account_type' => $accountType,
                'account_id' => $accountId,
                'transaction_type' => $transactionType,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
                'category' => $category,
                'running_balance' => $newBalance,
                'payment_method' => $paymentMethod,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'note' => $note,
                'status' => 'active',
                'created_by' => auth()->id() ?? 1,
            ]);

            $this->recalculateBalances($account);

            return $transaction->refresh();
        });
    }

    public function getRunningBalance(string $accountType): float
    {
        $account = Account::systemAccount($accountType);
        if ($accountType === 'cash') {
            return $this->ledgerOpeningBalance('cash', '9999-12-31');
        }

        return (float) ($account->balance ?? 0);
    }

    /**
     * Soft reverse a transaction with a reason.
     */
    public function reverseTransaction(AccountTransaction $transaction, string $reason, CarbonInterface|string|null $effectiveDate = null): bool
    {
        return DB::transaction(function () use ($transaction, $reason, $effectiveDate) {
            $account = Account::lockForUpdate()->findOrFail($transaction->account_id);
            $transaction = AccountTransaction::lockForUpdate()->findOrFail($transaction->id);
            if ($transaction->status !== 'active' || $transaction->transaction_type === 'reversal') {
                return false;
            }

            $last = AccountTransaction::where('account_type', $transaction->account_type)->where('account_id', $transaction->account_id)->latest('transaction_date')->latest('id')->lockForUpdate()->first();
            $previous = (float) ($last->running_balance ?? 0);
            AccountTransaction::create([
                'transaction_no' => 'REV-'.strtoupper(Str::random(8)), 'transaction_date' => $effectiveDate ?? now(),
                'account_type' => $transaction->account_type, 'account_id' => $transaction->account_id,
                'transaction_type' => 'reversal', 'debit' => $transaction->credit, 'credit' => $transaction->debit,
                'amount' => $transaction->amount, 'running_balance' => $previous + (float) $transaction->debit - (float) $transaction->credit,
                'payment_method' => $transaction->payment_method, 'reference_type' => $transaction->reference_type,
                'reference_id' => $transaction->reference_id, 'description' => "Reversal of {$transaction->transaction_no}",
                'note' => "Reason: {$reason}", 'status' => 'active', 'created_by' => auth()->id() ?? 1,
            ]);

            // Mark original as reversed
            $transaction->update([
                'status' => 'reversed',
                'reversed_by' => auth()->id() ?? 1,
                'reversal_reason' => $reason,
            ]);
            $this->recalculateBalances($account);

            AuditLog::create([
                'user_id' => auth()->id() ?? 1,
                'event' => 'ledger_reversal',
                'auditable_type' => AccountTransaction::class,
                'auditable_id' => $transaction->id,
                'old_values' => json_encode(['status' => 'active']),
                'new_values' => json_encode(['status' => 'reversed', 'reason' => $reason]),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            return true;
        });
    }

    private function recalculateBalances(Account $account): void
    {
        $balance = (int) round((float) $account->opening_balance * 100);
        foreach ($account->transactions()->posted()->orderBy('transaction_date')->orderBy('id')->get() as $entry) {
            $balance += (int) round((float) $entry->credit * 100) - (int) round((float) $entry->debit * 100);
            $entry->update(['running_balance' => $balance / 100]);
        }
    }

    public function outgoing(string $type, float $amount, string $description, Model $reference, string $method, CarbonInterface|string|null $date = null, ?string $category = null, bool $postBank = true): void
    {
        DB::transaction(function () use ($type, $amount, $description, $reference, $method, $date, $category, $postBank) {
            $this->recordTransaction($type, 'expense', $amount, $description, $reference, $method, null, null, $date, $category);
            if ($postBank && $method !== 'Cash') {
                $this->recordTransaction('bank', 'expense', $amount, $description, $reference, $method, null, null, $date, $category);
            }
        });
    }

    public function receiveInvoicePayment(Invoice $invoice, Payment $payment, bool $postBank = true, CarbonInterface|string|null $settledAt = null): void
    {
        $amount = (int) round((float) $payment->amount * 100);
        foreach ($this->paymentReceiptAllocation($invoice, $payment, $settledAt) as $type => $cents) {
            if ($cents > 0) {
                $category = $invoice->isOpeningDue() ? 'opening_due' : match ($type) {
                    'main' => 'service', 'washing' => 'washing', default => 'parts'
                };
                $this->recordTransaction($type, 'income', $cents / 100, "Receipt for {$invoice->number}", $payment, $payment->method, null, null, ($settledAt ?? $payment->paid_at), $category);
            }
        }
        if ($postBank && $payment->method !== 'Cash') {
            $this->recordTransaction('bank', 'income', $amount / 100, "Bank receipt for {$invoice->number}", $payment, $payment->method, null, null, ($settledAt ?? $payment->paid_at));
        }
        $this->reallocateInvoiceReceipts($invoice);
    }

    public function reallocateInvoiceReceipts(Invoice $invoice, CarbonInterface|string|null $fromDate = null, CarbonInterface|string|null $correctionDate = null): void
    {
        $cumulative = 0;
        $previous = ['spare_parts' => 0, 'washing' => 0, 'main' => 0];
        foreach ($this->orderedReceipts($invoice) as $payment) {
            $cumulative += (int) round((float) $payment->amount * 100);
            $target = $this->receiptAllocation($invoice, $cumulative);
            $allocation = [];
            foreach ($target as $type => $cents) {
                $allocation[$type] = ($cents - $previous[$type]) / 100;
            }
            $previous = $target;
            // Cheques post on clearance; all other receipts post on their selected payment date.
            $effectiveDate = AccountTransaction::posted()->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('transaction_type', 'income')->oldest('transaction_date')->value('transaction_date') ?? $payment->paid_at;
            if ($fromDate && Carbon::parse($effectiveDate)->lt($fromDate)) {
                continue;
            }
            foreach ($allocation as $type => $expected) {
                $entries = AccountTransaction::posted()->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('account_type', $type);
                $actual = (float) (clone $entries)->sum('credit') - (float) (clone $entries)->sum('debit');
                $difference = round($expected - $actual, 2);
                if ($difference != 0) {
                    $this->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference), "Invoice allocation correction {$invoice->number}", $payment, $payment->method, null, null, $correctionDate ?? $effectiveDate, 'invoice_revision');
                }
            }
        }
    }

    /** Component budgets include the bill's discount/tax and always sum to its total. */
    public function receiptComponentBudgets(Invoice $invoice): array
    {
        $total = max(0, (int) round((float) $invoice->total * 100));
        if ($invoice->isOpeningDue()) {
            return ['service' => 0, 'labor' => 0, 'washing' => 0, 'other' => $total, 'spare_parts' => 0];
        }
        $parts = max(0, (int) round((float) $invoice->parts_amount * 100));
        $washing = max(0, (int) round((float) $invoice->washing_amount * 100));
        $partsOnly = $invoice->invoice_type === 'Parts Sale' && $parts > 0
            && (float) $invoice->service_amount + (float) $invoice->labor_amount + (float) $invoice->washing_amount + (float) $invoice->other_amount == 0;
        $parts = $partsOnly ? $total : min($total, $parts);
        $washing = min($total - $parts, $washing);
        $main = $total - $parts - $washing;
        $service = min($main, max(0, (int) round((float) $invoice->service_amount * 100)));
        $labor = min($main - $service, max(0, (int) round((float) $invoice->labor_amount * 100)));

        // Keep the existing full-bill account totals, including discount/tax
        // treatment. Only the order of collecting those balances changes.
        return ['service' => $service, 'labor' => $labor, 'washing' => $washing,
            'other' => $main - $service - $labor, 'spare_parts' => $parts];
    }

    public function receiptComponentAllocation(Invoice $invoice, int $amount): array
    {
        $remaining = max(0, $amount);
        $allocation = [];
        foreach ($this->receiptComponentBudgets($invoice) as $type => $budget) {
            $allocation[$type] = min($remaining, $budget);
            $remaining -= $allocation[$type];
        }
        // Legacy zero-total receipts must still reconcile to their recorded money.
        $allocation['other'] += $remaining;

        return $allocation;
    }

    public function receiptAllocation(Invoice $invoice, int $amount): array
    {
        $parts = $this->receiptComponentAllocation($invoice, $amount);

        return ['spare_parts' => $parts['spare_parts'], 'washing' => $parts['washing'],
            'main' => $parts['service'] + $parts['labor'] + $parts['other']];
    }

    public function orderedReceipts(Invoice $invoice, ?Payment $current = null, CarbonInterface|string|null $settledAt = null)
    {
        $payments = $invoice->payments()->where('status', 'Completed')
            ->whereNotIn('method', ['Salary Deduction', 'Manual Clearance'])->get();
        $dates = AccountTransaction::posted()->where('reference_type', (new Payment)->getMorphClass())
            ->whereIn('reference_id', $payments->modelKeys())->where('transaction_type', 'income')
            ->selectRaw('reference_id, MIN(transaction_date) as receipt_date')->groupBy('reference_id')->pluck('receipt_date', 'reference_id');

        return $payments->sortBy(fn ($payment) => [
            Carbon::parse($current?->id === $payment->id && $settledAt ? $settledAt : ($dates[$payment->id] ?? $payment->paid_at))->format('Y-m-d H:i:s'), $payment->id,
        ])->values();
    }

    public function paymentComponentAllocation(Invoice $invoice, Payment $payment, CarbonInterface|string|null $settledAt = null): array
    {
        $cumulative = 0;
        foreach ($this->orderedReceipts($invoice, $payment, $settledAt) as $receipt) {
            $before = $this->receiptComponentAllocation($invoice, $cumulative);
            $cumulative += (int) round((float) $receipt->amount * 100);
            if ($receipt->id === $payment->id) {
                $after = $this->receiptComponentAllocation($invoice, $cumulative);

                $allocation = [];
                foreach ($after as $type => $value) {
                    $allocation[$type] = $value - $before[$type];
                }

                return $allocation;
            }
        }

        return $this->receiptComponentAllocation($invoice, (int) round((float) $payment->amount * 100));
    }

    private function paymentReceiptAllocation(Invoice $invoice, Payment $payment, CarbonInterface|string|null $settledAt = null): array
    {
        $parts = $this->paymentComponentAllocation($invoice, $payment, $settledAt);

        return ['spare_parts' => $parts['spare_parts'], 'washing' => $parts['washing'],
            'main' => $parts['service'] + $parts['labor'] + $parts['other']];
    }
}
