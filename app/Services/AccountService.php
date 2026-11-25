<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\Payment;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
        ?string $category = null,
        ?string $fundingSource = null,
        ?string $convertedFromMethod = null
    ): AccountTransaction {
        return DB::transaction(function () use ($accountType, $transactionType, $amount, $description, $reference, $paymentMethod, $note, $accountId, $transactionDate, $category, $fundingSource, $convertedFromMethod) {

            // Determine debit/credit logic based on transaction type and account
            // Income/receipts increase balance
            // Expenses/payments decrease balance

            $isIncrease = in_array($transactionType, [
                'income', 'payment', 'transfer_in', 'payment_received', 'opening_balance', 'adjustment_increase', 'owner_capital',
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
            if ($reference instanceof Payment && in_array($transactionType, ['income', 'transfer_out'], true)) {
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

            if ($fundingSource === 'main_cashier') {
                $fundingSource = 'main';
            }
            if ($accountType === 'main' && $debit > 0 && ! $fundingSource && $category !== 'invoice_revision') {
                $fundingSource = 'main';
            }

            if ($transactionDate) {
                $parsedDate = Carbon::parse($transactionDate);
                if ($parsedDate->format('H:i:s') === '00:00:00') {
                    $refTime = $reference?->created_at ?? now();
                    $effectiveDate = $parsedDate->copy()->setTimeFrom($refTime);
                } else {
                    $effectiveDate = $parsedDate;
                }
            } else {
                $effectiveDate = now();
            }

            $transaction = AccountTransaction::create([
                'transaction_no' => 'TRX-'.strtoupper(Str::random(8)),
                'transaction_date' => $effectiveDate,
                'account_type' => $accountType,
                'account_id' => $accountId,
                'transaction_type' => $transactionType,
                'debit' => $debit,
                'credit' => $credit,
                'amount' => $amount,
                'category' => $category,
                'funding_source' => $fundingSource,
                'converted_from_method' => $convertedFromMethod,
                'running_balance' => $newBalance,
                'payment_method' => $paymentMethod,
                'reference_type' => $reference?->getMorphClass(),
                'reference_id' => $reference?->getKey(),
                'description' => $description,
                'note' => $fundingSource && ! in_array($category, ['customer_receipt', 'extra_income'], true)
                    ? trim(($note ? $note.' · ' : '').'Source: '.OutgoingFundingService::SOURCES[$fundingSource])
                    : $note,
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
            $reversalDate = now();
            if ($effectiveDate) {
                $parsed = Carbon::parse($effectiveDate);
                $reversalDate = $parsed->format('H:i:s') === '00:00:00' ? $parsed->setTimeFrom(now()) : $parsed;
            }
            AccountTransaction::create([
                'transaction_no' => 'REV-'.strtoupper(Str::random(8)), 'transaction_date' => $reversalDate,
                'account_type' => $transaction->account_type, 'account_id' => $transaction->account_id,
                'transaction_type' => 'reversal', 'debit' => $transaction->credit, 'credit' => $transaction->debit,
                'amount' => $transaction->amount, 'running_balance' => $previous + (float) $transaction->debit - (float) $transaction->credit,
                'payment_method' => $transaction->payment_method, 'reference_type' => $transaction->reference_type,
                'funding_source' => $transaction->funding_source, 'category' => $transaction->category,
                'converted_from_method' => $transaction->converted_from_method,
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

    public function recalculateBalances(Account $account): void
    {
        $balance = (int) round((float) $account->opening_balance * 100);
        foreach ($account->transactions()->posted()->orderBy('transaction_date')->orderBy('id')->get() as $entry) {
            $balance += (int) round((float) $entry->credit * 100) - (int) round((float) $entry->debit * 100);
            $entry->updateQuietly(['running_balance' => $balance / 100]);
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

    public function usesBankPoolConversion(Payment $payment, bool $postBank = true): bool
    {
        return $postBank && $payment->method !== 'Cash' && (bool) $payment->spare_parts_to_bank;
    }

    /** Expected net cents per logical ledger account for one receipt. */
    public function expectedAccountAllocation(Invoice $invoice, Payment $payment, bool $postBank = true, CarbonInterface|string|null $settledAt = null): array
    {
        if ($payment->receiving_account && $postBank) {
            $type = app(OutgoingFundingService::class)->type($payment->receiving_account);

            return $type === 'bank' ? [] : [$type => (int) round((float) $payment->amount * 100)];
        }
        $allocation = $this->paymentReceiptAllocation($invoice, $payment, $settledAt);
        if ($this->usesBankPoolConversion($payment, $postBank)) {
            $main = (int) ($allocation['main'] ?? 0);

            return ['spare_parts' => -$main, 'washing' => 0, 'main' => $main];
        }

        return $allocation;
    }

    public function receiveInvoicePayment(Invoice $invoice, Payment $payment, bool $postBank = true, CarbonInterface|string|null $settledAt = null, bool $reallocate = true, bool $preserveFlags = false): void
    {
        if ($payment->receiving_account && $postBank) {
            $type = app(OutgoingFundingService::class)->type($payment->receiving_account);
            $this->recordTransaction($type, 'income', (float) $payment->amount, "Receipt for {$invoice->number}",
                $payment, $payment->method, "Received into {$payment->receiving_account_label}",
                transactionDate: $settledAt ?? $payment->paid_at, category: 'customer_receipt',
                fundingSource: $type === 'bank' ? null : $payment->receiving_account);
            if ($reallocate) {
                $this->reallocateInvoiceReceipts($invoice);
            }

            return;
        }
        // Preserve the routing of receipts already posted under the old policy.
        // New bank receipts credit the full amount to Bank and convert Main's share
        // into Main cashier cash from the Spare Parts cashier allocation.
        $alreadyPosted = $preserveFlags || AccountTransaction::posted()->where('reference_type', $payment->getMorphClass())
            ->where('reference_id', $payment->id)->where('transaction_type', 'income')->exists();
        if (! $alreadyPosted) {
            $payment->forceFill([
                'washing_to_main' => true,
                'opening_due_to_spare_parts' => $invoice->isOpeningDue(),
                'spare_parts_to_bank' => $postBank && $payment->method !== 'Cash',
            ])->save();
        }
        $amount = (int) round((float) $payment->amount * 100);
        $effectiveDate = $settledAt ?? $payment->paid_at;
        $allocation = $this->paymentReceiptAllocation($invoice, $payment, $settledAt);

        if ($this->usesBankPoolConversion($payment, $postBank)) {
            $mainCents = (int) ($allocation['main'] ?? 0);
            if ($mainCents > 0) {
                $mainAmount = $mainCents / 100;
                $category = $invoice->isOpeningDue() ? 'opening_due' : 'service';
                $this->recordTransaction(
                    'main',
                    'income',
                    $mainAmount,
                    "Receipt for {$invoice->number}",
                    $payment,
                    $payment->method,
                    "Main share for {$payment->method} · Transferred from Spare Parts cashier",
                    null,
                    $effectiveDate,
                    $category,
                    fundingSource: null,
                    convertedFromMethod: $payment->method,
                );
                $this->recordTransaction(
                    'spare_parts',
                    'transfer_out',
                    $mainAmount,
                    "Bank transfer Main share for {$invoice->number}",
                    $payment,
                    $payment->method,
                    'Transferred Rs. '.number_format($mainAmount, 2)." to Main Account for {$payment->method} share from cashier",
                    null,
                    $effectiveDate,
                    'bank_to_main_cash',
                    fundingSource: 'spare_parts_cashier',
                    convertedFromMethod: $payment->method,
                );
            }
            $this->recordTransaction('bank', 'income', $amount / 100, "Bank receipt for {$invoice->number}", $payment, $payment->method, null, null, $effectiveDate);
        } else {
            foreach ($allocation as $type => $cents) {
                if ($cents > 0) {
                    $category = $invoice->isOpeningDue() ? 'opening_due' : match ($type) {
                        'main' => 'service', 'washing' => 'washing', default => 'parts'
                    };
                    $this->recordTransaction($type, 'income', $cents / 100, "Receipt for {$invoice->number}", $payment, $payment->method, null, null, $effectiveDate, $category);
                }
            }
            if ($postBank && $payment->method !== 'Cash') {
                $this->recordTransaction('bank', 'income', $amount / 100, "Bank receipt for {$invoice->number}", $payment, $payment->method, null, null, $effectiveDate);
            }
        }
        if ($reallocate) {
            $this->reallocateInvoiceReceipts($invoice);
        }
        app(PaymentChargeService::class)->syncForPayment($invoice, $payment, $effectiveDate);
    }

    /**
     * Cleanly rebuild ledger allocations for remaining receipts on an invoice after a receipt is deleted,
     * avoiding adjustment_increase / adjustment_decrease clutter while preserving routing flags.
     *
     * @return array<int, int>
     */
    public function rebuildInvoiceReceipts(Invoice $invoice): array
    {
        $payments = $this->orderedReceipts($invoice);
        if ($payments->isEmpty()) {
            return [];
        }

        $allocations = $this->allocateReceipts($invoice, $payments);
        $affectedAccountIds = [];
        $needsFallbackReallocate = false;

        foreach ($payments as $payment) {
            if ($payment->receiving_account && ! $payment->cheque?->forwarded_supplier_id) {
                continue;
            }
            if ($payment->job_advance_id || $payment->cheque?->forwarded_supplier_id) {
                $needsFallbackReallocate = true;

                continue;
            }

            $rawAllocation = array_map(
                fn ($cents) => $cents / 100,
                $this->componentAccounts(
                    $invoice,
                    $allocations[$payment->id],
                    (bool) $payment->washing_to_main,
                    (bool) $payment->opening_due_to_spare_parts,
                )
            );
            $postBank = ! $payment->cheque_id || ! $payment->cheque?->forwarded_supplier_id;
            $convertedBank = $this->usesBankPoolConversion($payment, $postBank);
            $expected = $convertedBank
                ? ['spare_parts' => -$rawAllocation['main'], 'washing' => 0.0, 'main' => $rawAllocation['main']]
                : $rawAllocation;

            $existingEntries = AccountTransaction::where('reference_type', $payment->getMorphClass())
                ->where('reference_id', $payment->id)
                ->get();

            $hasAdjustments = $existingEntries->contains(fn (AccountTransaction $entry) => $entry->category === 'invoice_revision'
                || in_array($entry->transaction_type, AccountTransaction::BALANCE_ADJUSTMENT_TYPES, true)
                || $entry->status !== 'active'
                || $entry->transaction_type === 'reversal');

            $matches = ! $hasAdjustments && $existingEntries->isNotEmpty();
            if ($matches) {
                foreach ($expected as $type => $expAmount) {
                    $typeEntries = $existingEntries->where('status', 'active')->where('account_type', $type);
                    $actual = round((float) $typeEntries->sum('credit') - (float) $typeEntries->sum('debit'), 2);
                    if (round($expAmount - $actual, 2) != 0.0) {
                        $matches = false;
                        break;
                    }
                }
            }

            if ($matches) {
                continue;
            }

            $effectiveDate = $existingEntries->where('transaction_type', 'income')->min('transaction_date') ?? $payment->paid_at;
            $existingIds = $existingEntries->pluck('id')->all();
            if ($existingIds) {
                $affectedAccountIds = array_merge($affectedAccountIds, $existingEntries->pluck('account_id')->all());
                if (\Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
                    AuditLog::where('auditable_type', (new AccountTransaction)->getMorphClass())
                        ->whereIn('auditable_id', $existingIds)
                        ->delete();
                }
                AccountTransaction::whereIn('id', $existingIds)->delete();
            }

            $this->receiveInvoicePayment($invoice, $payment, $postBank, $effectiveDate, reallocate: false, preserveFlags: true);

            $newAccountIds = AccountTransaction::where('reference_type', $payment->getMorphClass())
                ->where('reference_id', $payment->id)
                ->pluck('account_id')
                ->all();
            $affectedAccountIds = array_merge($affectedAccountIds, $newAccountIds);
        }

        if ($needsFallbackReallocate) {
            $this->reallocateInvoiceReceipts($invoice);
        }

        return array_values(array_unique(array_filter($affectedAccountIds)));
    }

    public function reallocateInvoiceReceipts(Invoice $invoice, CarbonInterface|string|null $fromDate = null, CarbonInterface|string|null $correctionDate = null): void
    {
        $payments = $this->orderedReceipts($invoice);
        $advanceReceiptIds = JobAdvance::whereIn('id', $payments->pluck('job_advance_id')->filter()->unique())
            ->whereNotNull('payment_id')->pluck('payment_id', 'id');
        $groupReceiptIds = Payment::whereIn('receipt_group_id', $payments->pluck('receipt_group_id')->filter()->unique())
            ->pluck('id');
        $receiptDates = AccountTransaction::posted()
            ->where('reference_type', (new Payment)->getMorphClass())
            ->whereIn('reference_id', array_unique([...$payments->modelKeys(), ...$advanceReceiptIds->values(), ...$groupReceiptIds->values()]))
            ->where('transaction_type', 'income')
            ->selectRaw('reference_id, MIN(transaction_date) as receipt_date')->groupBy('reference_id')->pluck('receipt_date', 'reference_id');
        $allocations = $this->allocateReceipts($invoice, $payments);
        foreach ($payments as $payment) {
            $groupIdDate = $payment->receipt_group_id ? Payment::where('receipt_group_id', $payment->receipt_group_id)->pluck('id')->map(fn ($id) => $receiptDates[$id] ?? null)->filter()->first() : null;
            $effectiveDate = $receiptDates[$payment->id] ?? $receiptDates[$advanceReceiptIds[$payment->job_advance_id] ?? 0] ?? $groupIdDate ?? null;
            if ($effectiveDate === null || ($payment->receiving_account && ! $payment->cheque?->forwarded_supplier_id)) {
                continue;
            }
            $rawAllocation = array_map(fn ($cents) => $cents / 100,
                $this->componentAccounts($invoice, $allocations[$payment->id], (bool) $payment->washing_to_main, (bool) $payment->opening_due_to_spare_parts));
            $postBank = ! $payment->cheque_id || ! $payment->cheque?->forwarded_supplier_id;
            $convertedBank = $this->usesBankPoolConversion($payment, $postBank);
            $allocation = $convertedBank
                ? ['spare_parts' => -$rawAllocation['main'], 'washing' => 0.0, 'main' => $rawAllocation['main']]
                : $rawAllocation;
            if ($fromDate && Carbon::parse($effectiveDate)->lt($fromDate)) {
                continue;
            }
            foreach ($allocation as $type => $expected) {
                $entries = AccountTransaction::posted()->where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('account_type', $type);
                $actual = (float) (clone $entries)->sum('credit') - (float) (clone $entries)->sum('debit');
                $difference = round($expected - $actual, 2);
                if ($difference != 0) {
                    $adjType = $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease';
                    if ($convertedBank && $type === 'main') {
                        $this->recordTransaction($type, $adjType, abs($difference), "Invoice allocation correction {$invoice->number}", $payment, $payment->method, "Adjusted {$payment->method} Main share from Spare Parts cashier", null, $correctionDate ?? $effectiveDate, 'invoice_revision', convertedFromMethod: $payment->method);
                    } elseif ($convertedBank && $type === 'spare_parts') {
                        $this->recordTransaction($type, $adjType, abs($difference), "Invoice allocation correction {$invoice->number}", $payment, $payment->method, "Adjusted Spare Parts cashier deduction for {$payment->method} Main share", null, $correctionDate ?? $effectiveDate, 'invoice_revision', fundingSource: 'spare_parts_cashier', convertedFromMethod: $payment->method);
                    } else {
                        $this->recordTransaction($type, $adjType, abs($difference), "Invoice allocation correction {$invoice->number}", $payment, $payment->method, null, null, $correctionDate ?? $effectiveDate, 'invoice_revision');
                    }
                }
            }
        }
    }

    /** Service/labour and washing are protected; Spare Parts bears bill discounts. */
    public function receiptComponentBudgets(Invoice $invoice): array
    {
        $total = max(0, (int) round((float) $invoice->total * 100));
        if ($invoice->isOpeningDue()) {
            return ['service' => 0, 'labor' => 0, 'washing' => 0, 'other' => $total, 'spare_parts' => 0];
        }
        $service = max(0, (int) round((float) $invoice->service_amount * 100));
        $labor = max(0, (int) round((float) $invoice->labor_amount * 100));
        $washing = max(0, (int) round((float) $invoice->washing_amount * 100));
        // The residual includes Other, net parts (already less line discounts),
        // bill discount and tax. Never push a discount into Main or Washing,
        // even when it exceeds the bill's parts/Other charges.
        $spare = $total - $service - $labor - $washing;
        $other = min(max(0, $spare), max(0, (int) round((float) $invoice->other_amount * 100)));

        return ['service' => $service, 'labor' => $labor, 'washing' => $washing,
            'other' => $other, 'spare_parts' => $spare - $other];
    }

    public function receiptComponentAllocation(Invoice $invoice, int $amount): array
    {
        $remaining = max(0, $amount);
        $allocation = [];
        $budgets = $this->receiptComponentBudgets($invoice);
        $total = max(0, (int) round((float) $invoice->total * 100));
        if ($total > 0 && $remaining >= $total && $budgets['spare_parts'] < 0) {
            // Settle an excessive discount from Spare Parts with the final
            // receipt. Before settlement, keep actual collections in priority
            // order; an unpaid bill must not create an account debit.
            $budgets['other'] += $remaining - $total;

            return $budgets;
        }
        foreach ($budgets as $type => $budget) {
            $allocation[$type] = min($remaining, max(0, $budget));
            $remaining -= $allocation[$type];
        }
        // Legacy zero-total receipts must still reconcile to their recorded money.
        $allocation['other'] += $remaining;

        return $allocation;
    }

    public function receiptAllocation(Invoice $invoice, int $amount): array
    {
        $parts = $this->receiptComponentAllocation($invoice, $amount);

        return $this->componentAccounts($invoice, $parts);
    }

    private function componentAccounts(Invoice $invoice, array $parts, bool $washingToMain = false, bool $openingDueToSpareParts = false): array
    {
        // Keep historical opening-due receipts in Main. Each new receipt saves
        // its Spare Parts routing so later invoice recalculation preserves it.
        $openingInMain = $invoice->isOpeningDue() && ! $openingDueToSpareParts;

        return ['spare_parts' => $parts['spare_parts'] + ($openingInMain ? 0 : $parts['other']),
            'washing' => $washingToMain ? 0 : $parts['washing'],
            'main' => $parts['service'] + $parts['labor'] + ($washingToMain ? $parts['washing'] : 0) + ($openingInMain ? $parts['other'] : 0)];
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
        $receipts = $this->orderedReceipts($invoice, $payment, $settledAt);

        return $this->allocateReceipts($invoice, $receipts)[$payment->id]
            ?? $this->receiptComponentAllocation($invoice, (int) round((float) $payment->amount * 100));
    }

    private function allocateReceipts(Invoice $invoice, Collection $receipts): array
    {
        $budgets = $this->receiptComponentBudgets($invoice);
        $total = max(0, (int) round((float) $invoice->total * 100));
        $discountDeficit = min(0, $budgets['spare_parts']);
        $budgets['spare_parts'] -= $discountDeficit;
        $paid = 0;
        $result = [];
        // Apply settled advances before ordinary receipts. Each uses only the remaining component budgets.
        foreach ($receipts->sortBy(fn ($payment) => $payment->job_advance_id === null) as $receipt) {
            $amount = max(0, (int) round((float) $receipt->amount * 100));
            $remaining = $amount;
            $allocation = array_fill_keys(array_keys($budgets), 0);
            $priority = $receipt->job_advance_id
                ? ['other', 'spare_parts', 'service', 'labor', 'washing']
                : array_keys($budgets);
            foreach ($priority as $type) {
                $allocation[$type] = min($remaining, $budgets[$type]);
                $budgets[$type] -= $allocation[$type];
                $remaining -= $allocation[$type];
            }
            $allocation['other'] += $remaining;
            if ($discountDeficit < 0 && $total > 0 && $paid < $total && $paid + $amount >= $total) {
                // Finish the bill with the full Main share and charge the discount to Spare Parts.
                $allocation = array_map(fn ($amount, $budget) => $amount + $budget, $allocation, $budgets);
                $allocation['spare_parts'] += $discountDeficit;
                $budgets = array_fill_keys(array_keys($budgets), 0);
            }
            $paid += $amount;
            $result[$receipt->id] = $allocation;
        }

        return $result;
    }

    public function paymentReceiptAllocation(Invoice $invoice, Payment $payment, CarbonInterface|string|null $settledAt = null): array
    {
        $parts = $this->paymentComponentAllocation($invoice, $payment, $settledAt);

        return $this->componentAccounts($invoice, $parts, (bool) $payment->washing_to_main, (bool) $payment->opening_due_to_spare_parts);
    }
}
