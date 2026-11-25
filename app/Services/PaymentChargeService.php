<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\CashierSession;
use App\Models\Expense;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\Payment;
use App\Support\PaymentMethodCharges;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PaymentChargeService
{
    public const EXPENSE_CATEGORY = 'Card & Payment Charges';

    public function syncForPayment(Invoice $invoice, Payment $payment, CarbonInterface|string|null $effectiveDate = null): void
    {
        if ($payment->status !== 'Completed'
            || (float) $payment->amount <= 0
            || $payment->charge_expense_id
            || $payment->job_advance_id
            || in_array($payment->method, ['Salary Deduction', 'Manual Clearance', 'Credit/Due'], true)) {
            return;
        }

        $rate = PaymentMethodCharges::rateFor($payment->method);
        $chargeAmount = PaymentMethodCharges::calculate($payment->method, (float) $payment->amount);
        if ($rate <= 0 || $chargeAmount <= 0) {
            return;
        }

        $accountType = PaymentMethodCharges::chargeAccountType($payment->method);
        $expense = $this->createChargeExpense(
            shopType: $invoice->shop_type ?? session('shop_mode', 'workshop'),
            method: $payment->method,
            rate: $rate,
            baseAmount: (float) $payment->amount,
            chargeAmount: $chargeAmount,
            accountType: $accountType,
            documentNumber: $invoice->number,
            receiptNumber: $payment->number ?: ('Receipt #'.$payment->id),
            date: $effectiveDate ?? $payment->paid_at ?? now(),
            createdBy: $payment->created_by,
        );

        $payment->updateQuietly([
            'charge_rate' => $rate,
            'charge_amount' => $chargeAmount,
            'charge_expense_id' => $expense->id,
        ]);
    }

    public function syncForAdvance(JobAdvance $advance, CarbonInterface|string|null $effectiveDate = null): void
    {
        if ($advance->status !== 'Completed'
            || (float) $advance->amount <= 0
            || $advance->charge_expense_id
            || in_array($advance->method, ['Salary Deduction', 'Manual Clearance', 'Credit/Due'], true)) {
            return;
        }

        $rate = PaymentMethodCharges::rateFor($advance->method);
        $chargeAmount = PaymentMethodCharges::calculate($advance->method, (float) $advance->amount);
        if ($rate <= 0 || $chargeAmount <= 0) {
            return;
        }

        $accountType = PaymentMethodCharges::chargeAccountType($advance->method);
        $expense = $this->createChargeExpense(
            shopType: session('shop_mode', 'workshop'),
            method: $advance->method,
            rate: $rate,
            baseAmount: (float) $advance->amount,
            chargeAmount: $chargeAmount,
            accountType: $accountType,
            documentNumber: $advance->jobCard?->number ?? ('Job #'.$advance->job_card_id),
            receiptNumber: $advance->number,
            date: $effectiveDate ?? $advance->paid_at ?? now(),
            createdBy: $advance->created_by,
        );

        $advance->updateQuietly([
            'charge_rate' => $rate,
            'charge_amount' => $chargeAmount,
            'charge_expense_id' => $expense->id,
        ]);
    }

    public function reverseForRecord(Payment|JobAdvance $record, string $reason): void
    {
        if (! $record->charge_expense_id) {
            return;
        }

        $expense = Expense::withoutGlobalScopes()->lockForUpdate()->find($record->charge_expense_id);
        if (! $expense || $expense->status === 'Cancelled') {
            return;
        }

        $accounts = app(AccountService::class);
        AccountTransaction::where('reference_type', $expense->getMorphClass())
            ->where('reference_id', $expense->id)
            ->where('status', 'active')
            ->where('transaction_type', '!=', 'reversal')
            ->lockForUpdate()
            ->get()
            ->each(fn (AccountTransaction $entry) => $accounts->reverseTransaction($entry, $reason, $entry->transaction_date));

        $expense->update([
            'status' => 'Cancelled',
            'cancel_reason' => $reason,
            'cancelled_by' => auth()->id() ?? 1,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Hard-delete expenses and their ledger entries when deleting payments completely.
     *
     * @param  array<int, int|null>  $expenseIds
     * @return array<int, int> Affected account IDs
     */
    public function purgeExpenses(array $expenseIds): array
    {
        $ids = array_values(array_unique(array_filter($expenseIds)));
        if (empty($ids)) {
            return [];
        }

        $expenseMorph = (new Expense)->getMorphClass();
        $txMorph = (new AccountTransaction)->getMorphClass();

        $txEntries = AccountTransaction::where('reference_type', $expenseMorph)
            ->whereIn('reference_id', $ids)
            ->lockForUpdate()
            ->get();

        $affectedAccountIds = $txEntries->pluck('account_id')->filter()->unique()->values()->all();
        if ($txEntries->isNotEmpty()) {
            $txIds = $txEntries->pluck('id')->all();
            if (Schema::hasTable('audit_logs')) {
                AuditLog::where('auditable_type', $txMorph)->whereIn('auditable_id', $txIds)->delete();
            }
            AccountTransaction::whereIn('id', $txIds)->delete();
        }

        if (Schema::hasTable('audit_logs')) {
            AuditLog::where('auditable_type', $expenseMorph)->whereIn('auditable_id', $ids)->delete();
        }

        Expense::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)->forceDelete();

        return $affectedAccountIds;
    }

    private function createChargeExpense(
        string $shopType,
        string $method,
        float $rate,
        float $baseAmount,
        float $chargeAmount,
        string $accountType,
        string $documentNumber,
        string $receiptNumber,
        CarbonInterface|string $date,
        ?int $createdBy = null,
    ): Expense {
        $parsedDate = Carbon::parse($date);
        $fundingSource = $accountType;
        if ($fundingSource === 'spare_parts_cashier') {
            $hasOpenCashier = CashierSession::whereDate('session_date', $parsedDate->toDateString())
                ->where('status', 'open')
                ->exists();
            if (! $hasOpenCashier) {
                $fundingSource = 'spare_parts';
            }
        }
        $ledgerType = app(OutgoingFundingService::class)->type($fundingSource);
        $account = Account::systemAccount($ledgerType);
        $isCashierDrawer = $fundingSource === 'spare_parts_cashier';
        $expensePaymentMethod = $isCashierDrawer ? 'Cash' : ($method === 'Cash' ? 'Bank Transfer' : $method);
        $rateText = rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.');
        $description = "{$method} charge ({$rateText}%) for {$documentNumber}";
        $sourceLabel = PaymentMethodCharges::CHARGE_ACCOUNTS[$fundingSource] ?? $account->name;
        $deductionNote = $isCashierDrawer
            ? 'Deducted from '.$sourceLabel
            : 'Deducted from '.$sourceLabel.' outside cashier';

        FinancialCategory::firstOrCreate(
            ['module' => 'expense', 'name' => self::EXPENSE_CATEGORY],
            ['default_account_id' => $account->id, 'description' => 'Payment processing fees (card & electronic receipts)', 'active' => true]
        );

        $expense = Expense::create([
            'shop_type' => $shopType,
            'number' => 'EXP-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
            'expense_date' => $parsedDate->toDateString(),
            'category' => self::EXPENSE_CATEGORY,
            'description' => $description,
            'amount' => $chargeAmount,
            'account_id' => $account->id,
            'payment_method' => $expensePaymentMethod,
            'reference_number' => $receiptNumber,
            'funding_allocations' => [['source' => $fundingSource, 'amount' => number_format($chargeAmount, 2, '.', '')]],
            'note' => "Auto {$rateText}% fee on {$method} receipt {$receiptNumber} (Rs. ".number_format($baseAmount, 2).") · {$deductionNote}",
            'status' => 'Active',
            'created_by' => auth()->id() ?? $createdBy ?? 1,
        ]);

        app(AccountService::class)->recordTransaction(
            $ledgerType,
            'expense',
            $chargeAmount,
            $description,
            $expense,
            $expensePaymentMethod,
            "Receipt {$receiptNumber} · {$deductionNote}",
            $account->id,
            $parsedDate,
            self::EXPENSE_CATEGORY,
            $fundingSource,
        );

        return $expense;
    }
}
