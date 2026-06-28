<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
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
        ?int $accountId = null
    ): AccountTransaction {
        return DB::transaction(function () use ($accountType, $transactionType, $amount, $description, $reference, $paymentMethod, $note, $accountId) {
            
            // Determine debit/credit logic based on transaction type and account
            // Income/receipts increase balance
            // Expenses/payments decrease balance
            
            $isIncrease = in_array($transactionType, [
                'income', 'transfer_in', 'payment_received', 'opening_balance', 'adjustment_increase'
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

            // Get previous running balance
            $lastTransaction = AccountTransaction::where('account_type', $accountType)
                ->when($accountId, fn ($q) => $q->where('account_id', $accountId))
                ->latest('transaction_date')
                ->latest('id')
                ->first();

            $previousBalance = $lastTransaction ? $lastTransaction->running_balance : 0;
            $newBalance = $previousBalance + $credit - $debit;

            $transaction = AccountTransaction::create([
                'transaction_no' => 'TRX-' . strtoupper(Str::random(8)),
                'transaction_date' => now(),
                'account_type' => $accountType,
                'account_id' => $accountId,
                'transaction_type' => $transactionType,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
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

    /**
     * Soft reverse a transaction with a reason.
     */
    public function reverseTransaction(AccountTransaction $transaction, string $reason): bool
    {
        return DB::transaction(function () use ($transaction, $reason) {
            if ($transaction->status !== 'active') {
                return false;
            }

            // Create a compensating entry
            $this->recordTransaction(
                $transaction->account_type,
                'reversal',
                $transaction->amount,
                "Reversal of " . $transaction->transaction_no,
                $transaction->reference,
                $transaction->payment_method,
                "Reason: " . $reason,
                $transaction->account_id
            );

            // Mark original as reversed
            $transaction->update([
                'status' => 'reversed',
                'reversed_by' => auth()->id() ?? 1,
                'reversal_reason' => $reason
            ]);

            AuditLog::create([
                'user_id' => auth()->id() ?? 1,
                'event' => 'ledger_reversal',
                'auditable_type' => AccountTransaction::class,
                'auditable_id' => $transaction->id,
                'old_values' => json_encode(['status' => 'active']),
                'new_values' => json_encode(['status' => 'reversed', 'reason' => $reason]),
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent()
            ]);

            return true;
        });
    }
}
