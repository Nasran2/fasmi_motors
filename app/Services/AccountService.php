<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountService
{
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
                $accountId = Account::where('code', match ($accountType) {
                    'main' => 'MAIN', 'spare_parts' => 'SPARE', 'bank' => 'BANK', default => null,
                })->value('id');
            }

            // Lock the last entry so two simultaneous payments cannot calculate the same balance.
            $lastTransaction = AccountTransaction::where('account_type', $accountType)
                ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
                ->latest('transaction_date')
                ->latest('id')
                ->lockForUpdate()
                ->first();

            $previousBalance = $lastTransaction ? (float) $lastTransaction->running_balance : (float) (Account::find($accountId)?->opening_balance ?? 0);
            $newBalance = $previousBalance + $credit - $debit;

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
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference ? $reference->id : null,
                'description' => $description,
                'note' => $note,
                'status' => 'active',
                'created_by' => auth()->id() ?? 1,
            ]);

            return $transaction;
        });
    }

    public function getRunningBalance(string $accountType): float
    {
        $code = match ($accountType) {
            'main', 'cash' => 'MAIN', 'spare_parts' => 'SPARE', 'bank' => 'BANK', default => null,
        };
        $opening = (float) (Account::where('code', $code)->value('opening_balance') ?? 0);

        return $opening
            + (float) AccountTransaction::where('account_type', $accountType)->where('status', 'active')->sum('credit')
            - (float) AccountTransaction::where('account_type', $accountType)->where('status', 'active')->sum('debit');
    }

    /**
     * Soft reverse a transaction with a reason.
     */
    public function reverseTransaction(AccountTransaction $transaction, string $reason): bool
    {
        return DB::transaction(function () use ($transaction, $reason) {
            if ($transaction->status !== 'active') {
                return false;
            }

            $last = AccountTransaction::where('account_type', $transaction->account_type)->latest('transaction_date')->latest('id')->lockForUpdate()->first();
            $previous = (float) ($last?->running_balance ?? 0);
            AccountTransaction::create([
                'transaction_no' => 'REV-'.strtoupper(Str::random(8)), 'transaction_date' => now(),
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
}
