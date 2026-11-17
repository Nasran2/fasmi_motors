<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\SupplierPayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Payment choices backed by the existing ledgers and cashier cash tracking. */
class OutgoingFundingService
{
    public const SOURCES = [
        'main' => 'Main Account — opening balance',
        'main_cashier' => 'Main Account Cashier — today’s cash',
        'spare_parts' => 'Spare Parts Account — opening balance',
        'spare_parts_cashier' => 'Spare Parts Account Cashier — today’s cash',
        'bank' => 'Bank Account',
    ];

    public function type(string $source): string
    {
        return str_replace('_cashier', '', $source);
    }

    public function defaultSource(?int $accountId, string $method = 'Cash', string $fallback = 'main'): string
    {
        if ($method !== 'Cash') {
            return 'bank';
        }
        foreach (['main', 'spare_parts', 'bank'] as $type) {
            if ($accountId === Account::systemAccount($type)->id) {
                return $type;
            }
        }

        return $this->canonicalSource($fallback);
    }

    public function canonicalSource(string $source): string
    {
        return $source === 'main_cashier' ? 'main' : $source;
    }

    public function options(?string $date = null, bool $closing = false): array
    {
        $date ??= today()->toDateString();
        $selectedDate = Carbon::parse($date)->startOfDay();
        $open = ! $selectedDate->isFuture()
            && CashierSession::whereDate('session_date', $date)->where('status', 'open')->exists();
        $balances = $this->balances($date);
        $unavailableReason = 'No open cashier for '.$selectedDate->format('d M Y');

        return collect(self::SOURCES)->except('main_cashier')->map(fn ($label, $source) => [
            'value' => $source, 'label' => $selectedDate->isToday() ? $label : str_replace('today’s cash', $selectedDate->format('d M Y').' cash', $label),
            'account_id' => Account::systemAccount($this->type($source))->id,
            'balance' => $balances[$source],
            'cashier_balance' => 0,
            'disabled' => str_ends_with($source, '_cashier') && ! $open,
            'disabled_reason' => str_ends_with($source, '_cashier') && ! $open ? $unavailableReason : null,
        ])->values()->all();
    }

    public function balances(string $date, bool $lock = false): array
    {
        $accounts = collect(['main', 'spare_parts', 'bank'])->mapWithKeys(function ($type) use ($lock) {
            $query = Account::whereKey(Account::systemAccount($type)->id);

            return [$type => ($lock ? $query->lockForUpdate() : $query)->firstOrFail()];
        });
        $query = AccountTransaction::posted()->whereIn('account_id', $accounts->pluck('id'))->orderBy('account_id')->orderBy('id');
        // Current reads after the shared account locks prevent two payouts using the same funds.
        $all = ($lock ? $query->lockForUpdate() : $query)->get();
        $start = Carbon::parse($date)->startOfDay();
        $end = $start->copy()->endOfDay();
        $sessionQuery = CashierSession::whereDate('session_date', $date);
        $session = ($lock ? $sessionQuery->lockForUpdate() : $sessionQuery)->first();
        $context = app(DrawerCashierService::class)->cycleContext($session, $lock);
        $result = [];
        foreach (['main', 'spare_parts', 'bank'] as $type) {
            $account = $accounts[$type];
            $entries = $all->where('account_id', $account->id);
            if ($type === 'bank') {
                $result['bank'] = round((float) $account->opening_balance + $entries->sum('credit') - $entries->sum('debit'), 2);

                continue;
            }
            $today = $entries->filter(fn ($entry) => $entry->transaction_date->betweenIncluded($start, $end));
            $cashierBalance = round($this->cashierAmount($today, $type) - ($context['snapshot']['cashier'][$type] ?? 0), 2);
            $ledgerBalance = round((float) $account->opening_balance + $entries->sum('credit') - $entries->sum('debit'), 2);
            // Use the full current account total, including capital, transfers and corrections.
            // Reserve the current cycle's cash for its separate Cashier source. A negative
            // cashier position must not increase the amount available from the account.
            $result[$type] = round($ledgerBalance - max(0, $cashierBalance), 2);
            $result[$type.'_cashier'] = min($cashierBalance, $ledgerBalance);
        }
        $reserved = SupplierPayment::where('status', 'Hold')->where('funding_source', 'bank')->where('account_id', $accounts['bank']->id);
        $reserved = ($lock ? $reserved->lockForUpdate() : $reserved)->get()->sum('amount');
        $result['bank'] = round($result['bank'] - $reserved, 2);

        return $result;
    }

    /** Net received cash and cash outgoings for one day's account, without opening funds. */
    public function cashierAmount(Collection $entries, string $type): float
    {
        if ($type === 'main') {
            // Main account expenses and cashouts always go from the opening balance, never from the cashier.
            // Cashier receipts for Main track active cash collected today from customers
            // minus any same-channel invoice revision decreases.
            $credits = $entries->filter(fn ($entry) => $entry->status === 'active'
                && $entry->transaction_type !== 'reversal'
                && $entry->funding_source === null
                && $entry->converted_from_method === null
                && ($entry->payment_method === 'Cash' || $entry->account_type === 'cash')
                && $entry->credit > 0
                && ! in_array($entry->category, ['opening_balance', 'cashier_opening', 'cashier_cash_reconciliation', 'cashier_opening_rebase', 'cashier_account_opening', 'cashier_account_reconciliation', 'transfer'], true));
            $revisionDebits = $entries->filter(fn ($entry) => $entry->status === 'active'
                && $entry->category === 'invoice_revision'
                && $entry->transaction_type === 'adjustment_decrease'
                && $entry->funding_source === null
                && $entry->converted_from_method === null
                && ($entry->payment_method === 'Cash' || $entry->account_type === 'cash')
                && $entry->debit > 0);

            return round((float) $credits->sum('credit') - (float) $revisionDebits->sum('debit'), 2);
        }

        $cash = $entries->filter(fn ($entry) => $entry->status === 'active'
            && $entry->transaction_type !== 'reversal'
            && ($entry->funding_source === $type.'_cashier'
                || ($entry->funding_source === null && ($entry->payment_method === 'Cash' || $entry->account_type === 'cash') && $entry->category !== 'transfer'))
            && ! in_array($entry->category, ['opening_balance', 'cashier_opening', 'cashier_cash_reconciliation', 'cashier_opening_rebase', 'cashier_account_opening', 'cashier_account_reconciliation'], true));

        return round($cash->sum('credit') - $cash->sum('debit'), 2);
    }

    /** Active non-cash customer/sale receipts (Bank Transfer, Card, QR, Cheque) credited to an account. */
    public function nonCashIncome(Collection $entries, string $type): float
    {
        $excludedCategories = [
            'opening_balance', 'cashier_opening', 'cashier_cash_reconciliation',
            'cashier_opening_rebase', 'cashier_account_opening', 'cashier_account_reconciliation',
            'cashier_account_balance_correction', 'cashier_closing_correction', 'cashier_daily_allocation',
            'cashier_washing_sweep', 'transfer', 'bank_to_main_cash', 'closing_adjustment', 'closing_deduction',
        ];
        $isNonCash = fn ($entry) => $entry->status === 'active'
            && $entry->transaction_type !== 'reversal'
            && $entry->transaction_type !== 'owner_capital'
            && $entry->converted_from_method === null
            && ! ($entry->payment_method === 'Cash' || $entry->account_type === 'cash')
            && ! in_array($entry->payment_method, ['Adjustment', 'Manual'], true)
            && ! in_array($entry->reference_type, [SupplierPayment::class, \App\Models\PurchaseReturn::class], true)
            && ! in_array($entry->category, $excludedCategories, true);

        $credits = $entries->filter(fn ($entry) => $isNonCash($entry)
            && $entry->funding_source === null
            && $entry->credit > 0)->sum('credit');
        $revisionDebits = $entries->filter(fn ($entry) => $isNonCash($entry)
            && $entry->funding_source === null
            && $entry->category === 'invoice_revision'
            && $entry->transaction_type === 'adjustment_decrease'
            && $entry->debit > 0)->sum('debit');

        return round(max(0.0, (float) $credits - (float) $revisionDebits), 2);
    }

    /** Bank-transfer / non-cash Main share transferred from Spare Parts. */
    public function bankConvertedMainCash(Collection $entries): float
    {
        $credits = $entries->filter(fn ($entry) => $entry->status === 'active'
            && $entry->transaction_type !== 'reversal'
            && in_array($entry->funding_source, [null, 'spare_parts', 'spare_parts_cashier'], true)
            && $entry->converted_from_method !== null
            && $entry->credit > 0)->sum('credit');
        $debits = $entries->filter(fn ($entry) => $entry->status === 'active'
            && $entry->category === 'invoice_revision'
            && $entry->transaction_type === 'adjustment_decrease'
            && in_array($entry->funding_source, [null, 'spare_parts', 'spare_parts_cashier'], true)
            && $entry->converted_from_method !== null
            && $entry->debit > 0)->sum('debit');

        return round(max(0.0, (float) $credits - (float) $debits), 2);
    }

    /** Spare Parts opening-balance deduction used to convert non-cash Main shares (when funded from opening balance). */
    public function sparePartsBankConversionDeduction(Collection $entries): float
    {
        $rows = $entries->filter(fn ($entry) => $entry->status === 'active'
            && $entry->transaction_type !== 'reversal'
            && $entry->funding_source === 'spare_parts'
            && ($entry->category === 'bank_to_main_cash'
                || ($entry->category === 'invoice_revision' && $entry->converted_from_method !== null)));

        return round((float) $rows->sum('debit') - (float) $rows->sum('credit'), 2);
    }

    /** Active account-funded expenses and debits (excluding reversals, adjustments, transfers, and cashier-funded payouts). */
    public function cycleExpenses(Collection $entries, string $type): float
    {
        $debits = $entries->filter(fn ($entry) => $entry->status === 'active'
            && ! in_array($entry->transaction_type, ['reversal', 'adjustment_increase', 'adjustment_decrease'], true)
            && $entry->funding_source !== $type.'_cashier'
            && $entry->debit > 0
            && ! in_array($entry->category, [
                'opening_balance', 'cashier_opening', 'cashier_cash_reconciliation',
                'cashier_opening_rebase', 'cashier_account_opening', 'cashier_account_reconciliation',
                'cashier_account_balance_correction', 'cashier_closing_correction', 'cashier_daily_allocation',
                'cashier_washing_sweep', 'closing_adjustment', 'transfer', 'bank_to_main_cash',
            ], true));

        return round($debits->sum('debit'), 2);
    }

    public static function rules(): array
    {
        return ['funding' => 'nullable|array|min:1|max:5', 'funding.*' => 'required|array:source,amount',
            'funding.*.source' => ['required', Rule::in(array_keys(self::SOURCES))],
            'funding.*.amount' => 'required|numeric|decimal:0,2|min:0|max:999999999999.99'];
    }

    public function plan(array $input, float $amount, string $default = 'main'): array
    {
        $rows = $input['funding'] ?? [['source' => $input['funding_source'] ?? $default, 'amount' => $amount]];
        $validated = Validator::make(['funding' => $rows], self::rules())->validate();
        $rows = array_values(array_filter($validated['funding'], fn ($row) => (float) $row['amount'] > 0));
        $total = array_sum(array_map(fn ($row) => (int) round((float) $row['amount'] * 100), $rows));
        if ($total !== (int) round($amount * 100)) {
            throw ValidationException::withMessages(['funding' => 'The payment source amounts must add up to the total payment.']);
        }

        return array_map(fn ($row) => ['source' => $this->canonicalSource($row['source']), 'amount' => number_format((float) $row['amount'], 2, '.', '')], $rows);
    }

    /** Main outgoing payments use carried funds, never cashier collections. */
    public function resolveMainFunding(array $rows, string $date, bool $lock = false, bool $enforceAvailability = true): array
    {
        $balances = $this->balances($date, $lock);
        $available = (int) round($balances['main'] * 100);
        $requested = array_sum(array_map(fn ($row) => $this->canonicalSource($row['source']) === 'main' ? (int) round((float) $row['amount'] * 100) : 0, $rows));
        if ($enforceAvailability && $requested > max(0, $available)) {
            throw ValidationException::withMessages(['funding' => 'Main Account opening balance has only Rs. '.number_format(max(0, $available) / 100, 2).' available outside the cashier.']);
        }
        return array_map(fn ($row) => array_replace($row, [
            'source' => $this->canonicalSource($row['source']),
        ]), $rows);
    }

    public function post(array $rows, float $amount, string $description, Model $reference, string $method = 'Cash', $date = null, ?string $category = null, ?string $note = null, string $transactionType = 'expense', ?CashierSession $closingSession = null, bool $allowOpenDate = false): void
    {
        $rows = $this->plan(['funding' => $rows], $amount);
        $date = Carbon::parse($date ?? now());
        if ($date->format('H:i:s') === '00:00:00') {
            $date = $date->copy()->setTimeFrom($reference?->created_at ?? now());
        }
        DB::transaction(function () use ($rows, $description, $reference, $method, $date, $category, $note, $transactionType) {
            app(CashierService::class)->lock();
            // All outgoing screens use the transaction's cash date, including
            // an earlier day whose cashier has deliberately been left open.
            $session = CashierSession::whereDate('session_date', $date->toDateString())->lockForUpdate()->first();
            $rows = $this->resolveMainFunding($rows, $date->toDateString(), true);
            foreach ($rows as $row) {
                $source = $row['source'];
                if (str_ends_with($source, '_cashier') && ($date->copy()->startOfDay()->isFuture() || $session?->status !== 'open')) {
                    throw ValidationException::withMessages(['funding' => 'Cashier sources require an open cashier for the selected payment date. Refresh the funding options or choose the open cashier date.']);
                }
                if ($source !== 'bank' && $method !== 'Cash') {
                    throw ValidationException::withMessages(['funding' => 'Use Cash for Main/Spare Parts sources. Select Bank Account for bank, card or cheque payments.']);
                }
                $available = $this->balances($date->toDateString(), true)[$source];
                if ((int) round($row['amount'] * 100) > (int) round($available * 100)) {
                    throw ValidationException::withMessages(['funding' => self::SOURCES[$source].' has only Rs. '.number_format(max(0, $available), 2).' available.']);
                }
                app(AccountService::class)->recordTransaction($this->type($source), $transactionType, (float) $row['amount'], $description, $reference,
                    $source === 'bank' && $method === 'Cash' ? 'Bank Transfer' : $method, $note, transactionDate: $date, category: $category, fundingSource: $source);
            }
        });
    }
}
