<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
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
        $total = (int) round((float) $invoice->total * 100);
        // Opening dues have no reliable parts/service breakdown and belong entirely to MAIN.
        $parts = $invoice->isOpeningDue() ? 0 : min($total, (int) round((float) $invoice->parts_amount * 100));
        $amount = (int) round((float) $payment->amount * 100);
        $share = $total > 0 ? (int) round($amount * $parts / $total) : 0;
        foreach (['spare_parts' => $share, 'main' => $amount - $share] as $type => $cents) {
            if ($cents > 0) {
                $category = $invoice->isOpeningDue() ? 'opening_due' : ($type === 'main' ? 'service' : 'parts');
                $this->recordTransaction($type, 'income', $cents / 100, "Receipt for {$invoice->number}", $payment, $payment->method, null, null, ($settledAt ?? $payment->paid_at), $category);
            }
        }
        if ($postBank && $payment->method !== 'Cash') {
            $this->recordTransaction('bank', 'income', $amount / 100, "Bank receipt for {$invoice->number}", $payment, $payment->method, null, null, ($settledAt ?? $payment->paid_at));
        }
        $this->reallocateInvoiceReceipts($invoice);
    }

    public function reallocateInvoiceReceipts(Invoice $invoice): void
    {
        $cumulative = 0;
        $previousParts = 0;
        foreach ($invoice->payments()->where('status', 'Completed')->whereNotIn('method', ['Salary Deduction', 'Manual Clearance'])->orderBy('id')->get() as $payment) {
            $cumulative += (int) round((float) $payment->amount * 100);
            $targetParts = ! $invoice->isOpeningDue() && (float) $invoice->total > 0 ? (int) round($cumulative * min(1, (float) $invoice->parts_amount / (float) $invoice->total)) : 0;
            $parts = ($targetParts - $previousParts) / 100;
            $previousParts = $targetParts;
            // Cheques post on clearance; all other receipts post on their selected payment date.
            $effectiveDate = AccountTransaction::posted()->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('transaction_type', 'income')->oldest('transaction_date')->value('transaction_date') ?? $payment->paid_at;
            foreach (['spare_parts' => $parts, 'main' => round((float) $payment->amount - $parts, 2)] as $type => $expected) {
                $entries = AccountTransaction::posted()->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('account_type', $type);
                $actual = (float) (clone $entries)->sum('credit') - (float) (clone $entries)->sum('debit');
                $difference = round($expected - $actual, 2);
                if ($difference != 0) {
                    $this->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference), "Invoice allocation correction {$invoice->number}", $payment, $payment->method, null, null, $effectiveDate, 'invoice_revision');
                }
            }
        }
    }
}
