<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashierService
{
    public const ACCOUNTS = ['main' => 'Main Account', 'bank' => 'Bank Account', 'washing' => 'Washing Account', 'spare_parts' => 'Spare Parts Account'];

    public function __construct(private AccountService $accounts) {}

    // Acquire current reads in a stable order before taking a balance snapshot.
    // Opening, closing and protected sales share these account locks.
    public function lock(): void
    {
        $codes = array_map(fn ($type) => Account::systemCode($type), array_keys(self::ACCOUNTS));
        Account::whereIn('code', $codes)->orderBy('id')->lockForUpdate()->get();
    }

    public function summary(string $date): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $ids = collect(array_keys(self::ACCOUNTS))->map(fn ($type) => Account::systemAccount($type)->id);
        $transactions = AccountTransaction::with('account')->posted()->whereIn('account_id', $ids)
            ->whereBetween('transaction_date', [$start, $start->copy()->endOfDay()])->orderBy('transaction_date')->orderBy('id')->get();
        $accounts = [];
        foreach (self::ACCOUNTS as $type => $label) {
            $rows = $transactions->where('account_id', Account::systemAccount($type)->id);
            $opening = $this->accounts->ledgerOpeningBalance($type, $start);
            // Opening corrections belong to opening, rather than today's sales.
            $openingRows = $rows->whereIn('category', ['cashier_opening', 'cashier_account_opening']);
            $opening += $openingRows->sum('credit') - $openingRows->sum('debit');
            $rows = $rows->whereNotIn('category', ['cashier_opening', 'cashier_account_opening']);
            $income = (float) $rows->sum('credit');
            $outgoing = (float) $rows->sum('debit');
            $accounts[$type] = [
                'opening' => round($opening, 2), 'income' => $income, 'outgoing' => $outgoing,
                'expenses' => (float) $rows->where('transaction_type', 'expense')->sum('debit'),
                'drawings' => (float) $rows->where('transaction_type', 'drawing')->sum('debit'),
                'closing' => round($opening + $income - $outgoing, 2),
            ];
        }

        $session = CashierSession::whereDate('session_date', $date)->first();
        $collections = app(CollectionReportService::class)->rows($date, $date);
        // The displayed opening is the selected date's confirmed snapshot.
        // Keep the ledger closing intact and expose any reconciliation separately.
        $displayAccounts = $accounts;
        $adjustmentIds = AccountTransaction::whereIn('id', $transactions->modelKeys())
            ->balanceAdjustments()->pluck('id');
        foreach ($displayAccounts as $type => &$account) {
            $account['opening'] = (float) ($session?->account_opening_balances[$type] ?? $account['opening']);
            $account['reconciliation'] = round($account['closing'] - $account['opening'] - $account['income'] + $account['outgoing'], 2);
            $adjustments = $transactions->where('account_id', Account::systemAccount($type)->id)
                ->whereNotIn('category', ['cashier_opening', 'cashier_account_opening'])
                ->whereIn('id', $adjustmentIds);
            $account['income'] = round($account['income'] - $adjustments->sum('credit'), 2);
            $account['outgoing'] = round($account['outgoing'] - $adjustments->sum('debit'), 2);
            $account['adjustments'] = round($adjustments->sum('credit') - $adjustments->sum('debit') + $account['reconciliation'], 2);
        }
        unset($account);
        $washingReceived = $collections->sum(fn ($receipt) => (int) round(($receipt['components']['washing'] ?? 0) * 100)) / 100;
        $cashAccounts = app(CashPositionService::class)->accounts($date, $session, $collections);

        $independent = $session?->drawer_opening_balance !== null;
        $cashMovements = $independent ? app(CashPositionService::class)->accounts($date, null, $collections, true) : $cashAccounts;
        $cashOpening = $independent ? (float) $session->drawer_opening_balance : round(collect($cashAccounts)->sum('opening'), 2);
        $cashClosing = $independent ? round($cashOpening + collect($cashMovements)->sum('closing') + (float) $session->drawer_adjustment, 2) : round(collect($cashAccounts)->sum('closing'), 2);
        $funding = app(OutgoingFundingService::class);
        $openingContext = app(DrawerCashierService::class)->cycleContext($session);
        $cashierFunds = [
            'opening' => (float) ($openingContext['cash'] ?? $session?->drawer_opening_balance ?? $session?->opening_balance ?? 0),
            'main' => $funding->cashierAmount($transactions->where('account_id', Account::systemAccount('main')->id), 'main'),
            'spare_parts' => $funding->cashierAmount($transactions->where('account_id', Account::systemAccount('spare_parts')->id), 'spare_parts'),
        ];
        foreach (['main', 'spare_parts'] as $type) {
            $cashierFunds[$type] = round($cashierFunds[$type] - ($openingContext['snapshot']['cashier'][$type] ?? 0), 2);
        }
        $cashierFunds['total'] = array_sum(array_map(fn ($amount) => (int) round($amount * 100), $cashierFunds)) / 100;
        $allocationAccounts = [];
        foreach (['main', 'spare_parts'] as $type) {
            $priorAllocationRows = $transactions->where('account_id', Account::systemAccount($type)->id)->where('category', 'cashier_daily_allocation');
            $priorAllocation = round($priorAllocationRows->sum('credit') - $priorAllocationRows->sum('debit') - ($openingContext['snapshot']['daily_allocation'][$type] ?? 0), 2);
            $washingToMain = $type === 'main' ? $accounts['washing']['closing'] : 0;
            $allocationAccounts[$type] = [
                'opening' => $openingContext['accounts'][$type] ?? $accounts[$type]['opening'],
                'base' => round($accounts[$type]['closing'] + $washingToMain - $cashierFunds[$type] - $priorAllocation, 2),
                'suggested_addition' => max(0, round($cashierFunds[$type] + $priorAllocation, 2)),
                'prior_allocation' => $priorAllocation,
            ];
        }

        return [
            'separate_cash_balance' => $independent, 'drawer_adjustment' => (float) ($session?->drawer_adjustment ?? 0),
            'accounts' => $accounts, 'transactions' => $transactions, 'cash_accounts' => $cashAccounts, 'collections' => $collections,
            'display_accounts' => $displayAccounts, 'washing_received' => $washingReceived,
            'opening_context' => $openingContext, 'account_opening_balances' => $session?->account_opening_balances,
            'cashier_cycle' => ['income' => round($collections->where('channel', 'cash')->sum('amount') + collect($cashMovements)->sum(fn ($account) => $account['other_in'] - $account['internal_in']) - ($openingContext['cash_movements']['income'] ?? 0), 2),
                'paid' => round(collect($cashMovements)->sum(fn ($account) => $account['outgoing'] - $account['internal_out']) - ($openingContext['cash_movements']['paid'] ?? 0), 2)],
            'cashier_funds' => $cashierFunds, 'allocation_accounts' => $allocationAccounts,
            'cash_opening' => $cashOpening,
            'cash_in_hand' => $cashClosing,
            'cash_paid' => round(collect($cashMovements)->sum(fn ($account) => $account['outgoing'] - $account['internal_out']), 2),
            'cash_other_in' => round(collect($cashMovements)->sum(fn ($account) => $account['other_in'] - $account['internal_in']), 2),
            'cash_received' => round($collections->where('channel', 'cash')->sum('amount'), 2),
            'bank_received' => round($collections->where('channel', 'bank')->sum('amount'), 2),
            'total_received' => round($collections->sum('amount'), 2),
            'total_paid' => round(collect($cashMovements)->sum(fn ($account) => $account['outgoing'] - $account['internal_out']) + ($accounts['bank']['outgoing'] ?? 0), 2),
            'transaction_count' => $collections->count(),
        ];
    }

    public function open(float $opening, ?string $reason, array $washing = [], bool $saveDefault = false, ?array $allocations = null, ?string $balanceVersion = null, bool $separateCash = false): CashierSession
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($opening, $reason, $washing, $saveDefault, $allocations, $balanceVersion, $separateCash) {
            $this->lock();
            if (CashierSession::active()) {
                throw ValidationException::withMessages(['cashier' => 'A cashier is already open. Close it before opening again.']);
            }
            $existing = CashierSession::whereDate('session_date', today())->lockForUpdate()->first();
            if ($separateCash || $existing?->drawer_opening_balance !== null) {
                return app(DrawerCashierService::class)->open($existing, $opening, $reason, $washing, $saveDefault, $allocations, $balanceVersion);
            }
            $positions = app(CashPositionService::class)->accounts(today()->toDateString(), $existing);
            if ($balanceVersion !== null && ! hash_equals($this->fingerprint($this->summary(today()->toDateString())), $balanceVersion)) {
                throw ValidationException::withMessages(['balance_version' => 'Balances changed. Close and reopen this popup to review the latest amounts.']);
            }
            $source = collect($positions)->map(fn ($account) => $account[$existing ? 'closing' : 'opening'])->all();
            $suggested = round(array_sum($source), 2);
            $difference = round($opening - $suggested, 2);
            if ($difference != 0 && blank($reason)) {
                throw ValidationException::withMessages(['opening_reason' => 'Explain the change to the automatic opening balance.']);
            }
            $confirmed = $this->validateOpeningAllocation($opening, $allocations, $source);
            $changes = $this->openingDifferences($confirmed, $source);
            if (array_filter($changes) && blank($reason)) {
                throw ValidationException::withMessages(['opening_reason' => 'Explain the changed opening account balances.']);
            }
            // Reopening counts current cash. Preserve the original start-of-day snapshot.
            $openingBalances = $existing
                ? collect($positions)->map(fn ($account) => $account['opening'])->all()
                : $confirmed;
            $washing += ['washing_person_id' => null, 'washing_commission_amount' => app(WashingCommissionService::class)->defaultAmount()];
            $values = [
                'opening_balance' => round(array_sum($openingBalances), 2), 'cash_opening_balances' => $openingBalances,
                'status' => 'open', 'notes' => $reason, 'closed_at' => null, 'closed_by' => null,
                'washing_person_id' => $washing['washing_person_id'], 'washing_commission_amount' => $washing['washing_commission_amount'],
            ];
            if ($existing) {
                $existing->update($values);
                DailyClosing::whereDate('closing_date', today())->update(['status' => 'reopened', 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => $reason ?: 'Cashier opened again']);
                $session = $existing;
            } else {
                $session = CashierSession::create($values + [
                    'session_date' => today()->toDateString(), 'opened_by' => auth()->id(), 'opened_at' => now(),
                ]);
            }
            if ($saveDefault) {
                Setting::updateOrCreate(['key' => WashingCommissionService::settingKey()], ['value' => $washing['washing_commission_amount'], 'group' => 'technician', 'type' => 'number']);
            }
            // On rollout, separate washing on receipts already posted today by
            // the previous version. Preserve historical days and closing records.
            $paymentIds = AccountTransaction::posted()->where('reference_type', (new Payment)->getMorphClass())
                ->where('transaction_type', 'income')->where('transaction_date', '>=', today()->startOfDay())->select('reference_id');
            Invoice::where('washing_amount', '>', 0)->where('status', '!=', 'Cancelled')
                ->whereHas('payments', fn ($query) => $query->whereIn('id', $paymentIds))
                ->each(fn ($invoice) => $this->accounts->reallocateInvoiceReceipts($invoice, today()->startOfDay()));
            foreach ($changes as $type => $change) {
                if ($change != 0) {
                    $this->accounts->recordTransaction($type, $change > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($change),
                        $existing ? 'Cashier reopening cash count correction' : 'Cashier opening balance correction', $session, 'Cash', $reason,
                        transactionDate: $existing ? now() : today()->startOfDay(), category: $existing ? 'cashier_cash_reconciliation' : 'cashier_opening');
                }
            }

            $session->events()->create(['action' => $existing ? 'reopened' : 'opened', 'cash_amount' => $opening,
                'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
                'details' => ['reason' => $reason, 'opening_correction' => $difference, 'saved_default' => $saveDefault, 'cash_balances' => $confirmed, 'account_corrections' => $changes], 'created_by' => auth()->id()]);

            return $session;
        });
    }

    public function openingDefaults(): array
    {
        $session = CashierSession::whereDate('session_date', today())->first();
        $positions = app(CashPositionService::class)->accounts(today()->toDateString(), $session);

        return collect($positions)->map(fn ($account) => $account[$session ? 'closing' : 'opening'])->all();
    }

    public function suggestedOpening(): float
    {
        $todaySession = CashierSession::whereDate('session_date', today())->first();
        if ($todaySession?->drawer_opening_balance !== null) {
            return $this->summary(today()->toDateString())['cash_in_hand'];
        }
        $carried = app(DrawerCashierService::class)->carriedOpening(today()->toDateString());

        return $carried ?? round(array_sum($this->openingDefaults()), 2);
    }

    private function validateOpeningAllocation(float $total, ?array $allocations, array $source): array
    {
        $confirmed = $allocations ?? $source;
        foreach (CashPositionService::ACCOUNTS as $type => $label) {
            $value = $confirmed[$type] ?? ($type === 'washing' ? $source[$type] : null);
            if (! is_numeric($value) || ! is_finite((float) $value) || $value < 0 || $value > 999999999999.99) {
                throw ValidationException::withMessages(['opening_balances.'.$type => 'Enter a nonnegative cash amount for '.$label.'.']);
            }
            $confirmed[$type] = round((float) $value, 2);
        }
        $confirmed = array_intersect_key($confirmed, CashPositionService::ACCOUNTS);
        if (! is_finite($total) || $total < 0 || $total > 999999999999.99 || (int) round(array_sum($confirmed) * 100) !== (int) round($total * 100)) {
            throw ValidationException::withMessages(['opening_balances' => 'Enter each account’s opening cash. The account amounts must add up to the opening cash in hand.']);
        }

        return $confirmed;
    }

    private function openingDifferences(array $confirmed, array $source): array
    {
        return collect($confirmed)->map(fn ($amount, $type) => round($amount - $source[$type], 2))->all();
    }

    public function canCorrectOpening(?CashierSession $session): bool
    {
        return $session && $session->session_date->copy()->startOfDay()->lte(today())
            && in_array($session->status, ['open', 'closed'], true);
    }

    public function correctOpening(CashierSession $session, float $opening, array $allocations, string $reason, string $balanceVersion, bool $recalculateToday = false, bool $separateCash = false): void
    {
        Account::ensureSystemAccounts();
        DB::transaction(function () use ($session, $opening, $allocations, $reason, $balanceVersion, $recalculateToday, $separateCash) {
            $this->lock();
            $session = CashierSession::lockForUpdate()->findOrFail($session->id);
            if ($separateCash || $session->drawer_opening_balance !== null) {
                app(DrawerCashierService::class)->correct($session, $opening, $allocations, $reason, $balanceVersion, $recalculateToday);

                return;
            }
            if (! $this->canCorrectOpening($session)) {
                throw ValidationException::withMessages(['cashier' => 'Choose a saved cashier opening dated today or earlier.']);
            }
            if ($recalculateToday && (! $session->session_date->isToday() || $session->status !== 'open')) {
                throw ValidationException::withMessages(['recalculate_today' => 'Recalculating current cash is only available for today’s open cashier.']);
            }
            $summary = $this->summary($session->session_date->toDateString());
            if (! hash_equals($this->fingerprint($summary), $balanceVersion)) {
                throw ValidationException::withMessages(['balance_version' => 'Balances changed. Close and reopen this popup to review the latest amounts.']);
            }
            if (blank($reason)) {
                throw ValidationException::withMessages(['opening_reason' => 'Explain the opening balance correction.']);
            }
            $source = collect($summary['cash_accounts'])->map(fn ($account) => $account['opening'])->all();
            $confirmed = $this->validateOpeningAllocation($opening, $allocations, $source);
            $changes = $this->openingDifferences($confirmed, $source);
            $reconciliations = $recalculateToday ? $this->openingReconciliations($session) : [];
            if (! array_filter($changes) && ! array_filter($reconciliations)) {
                return;
            }
            $checkpoint = $recalculateToday ? null : $this->openingCorrectionCheckpoint($session);
            $previousClosing = DailyClosing::whereDate('closing_date', $session->session_date)->lockForUpdate()->first();
            $previousClosingValues = $previousClosing?->getAttributes();
            foreach ($changes as $type => $change) {
                if ($change != 0) {
                    // Compensating entries preserve the original audit trail and are
                    // opening corrections, never today's customer collections.
                    $this->accounts->recordTransaction($type, $change > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($change),
                        'Confirmed cashier opening allocation correction', $session, 'Cash', $reason,
                        transactionDate: $session->session_date->copy()->startOfDay(), category: 'cashier_opening');
                    if ($checkpoint) {
                        $this->accounts->recordTransaction($type, $change > 0 ? 'adjustment_decrease' : 'adjustment_increase', abs($change),
                            'Opening correction reconciled to recorded '.($checkpoint['kind'] === 'closing' ? 'cash count' : 'next opening'), $session, 'Cash', $reason,
                            transactionDate: $checkpoint['at'], category: $checkpoint['kind'] === 'closing' ? 'cashier_cash_reconciliation' : 'cashier_opening');
                    }
                }
            }
            foreach ($reconciliations as $type => $amount) {
                if ($amount != 0) {
                    $this->accounts->recordTransaction($type, $amount > 0 ? 'adjustment_decrease' : 'adjustment_increase', abs($amount),
                        'Recalculate today from confirmed opening balances', $session, 'Cash', $reason,
                        transactionDate: now(), category: 'cashier_opening_rebase');
                }
            }
            if ($previousClosing || ($checkpoint && $checkpoint['kind'] === 'closing')) {
                $this->refreshCorrectedClosing($session, $changes, $checkpoint['before_last_close'] ?? false, $previousClosing);
            }
            $session->update(['opening_balance' => round(array_sum($confirmed), 2), 'cash_opening_balances' => $confirmed]);
            $session->events()->create(['action' => 'opening_corrected', 'cash_amount' => $opening,
                'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
                'details' => ['reason' => $reason, 'previous_opening' => $source, 'cash_balances' => $confirmed, 'account_corrections' => $changes,
                    'checkpoint' => $checkpoint ? ['kind' => $checkpoint['kind'], 'date' => $checkpoint['at']->toDateTimeString()] : null,
                    'previous_closing' => $previousClosingValues, 'recorded_cash_balances' => $session->cash_balances,
                    'checkpoint_reconciliation' => $checkpoint ? array_map(fn ($change) => -$change, $changes) : null,
                    'recalculate_today' => $recalculateToday, 'removed_opening_reconciliations' => $reconciliations], 'created_by' => auth()->id()]);
        });
    }

    private function openingReconciliations(CashierSession $session): array
    {
        $rows = AccountTransaction::where('status', 'active')->where('reference_type', $session->getMorphClass())->where('reference_id', $session->id)
            ->whereDate('transaction_date', $session->session_date)->where(function ($query) {
                $query->where('category', 'cashier_opening_rebase')->orWhere(function ($query) {
                    $query->where('category', 'cashier_cash_reconciliation')->where('description', 'Opening correction reconciled to recorded cash count');
                });
            })->get();

        return collect(CashPositionService::ACCOUNTS)->map(function ($label, $type) use ($rows) {
            $entries = $rows->where('account_id', Account::systemAccount($type)->id);

            return round($entries->sum('credit') - $entries->sum('debit'), 2);
        })->all();
    }

    public function recordHistoricalOpening(string $date, float $opening, array $allocations, string $reason, string $balanceVersion, bool $separateCash = false): CashierSession
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($date, $opening, $allocations, $reason, $balanceVersion, $separateCash) {
            $this->lock();
            if (! Carbon::parse($date)->startOfDay()->lt(today())) {
                throw ValidationException::withMessages(['date' => 'Use Open Cashier to enter today’s first opening.']);
            }
            if (CashierSession::whereDate('session_date', $date)->exists()) {
                throw ValidationException::withMessages(['date' => 'An opening was already saved for this date. Reload the date and edit that record.']);
            }
            $summary = $this->summary($date);
            if (! hash_equals($this->fingerprint($summary), $balanceVersion)) {
                throw ValidationException::withMessages(['balance_version' => 'Balances changed. Close and reopen this popup to review the latest amounts.']);
            }
            $source = collect($summary['cash_accounts'])->map(fn ($account) => $account['opening'])->all();
            // This is an opening record, not a claim that cash was counted at closing.
            $session = CashierSession::create(['session_date' => $date, 'status' => 'closed',
                'opening_balance' => round(array_sum($source), 2), 'cash_opening_balances' => $source,
                'opened_at' => Carbon::parse($date)->startOfDay(), 'opened_by' => auth()->id()]);
            $this->correctOpening($session, $opening, $allocations, $reason, $balanceVersion, false, $separateCash);
            if (! $session->events()->where('action', 'opening_corrected')->exists()) {
                $session->events()->create(['action' => 'opening_recorded', 'cash_amount' => $opening,
                    'details' => ['reason' => $reason, 'cash_balances' => $allocations], 'created_by' => auth()->id()]);
            }

            return $session->fresh();
        });
    }

    /** Recorded counts anchor later cash, even when an earlier opening is corrected. */
    private function openingCorrectionCheckpoint(CashierSession $session): ?array
    {
        $closings = $session->events()->where('action', 'closed')->orderBy('id')->get();
        $closing = DailyClosing::whereDate('closing_date', $session->session_date)->lockForUpdate()->first();
        if ($closings->isNotEmpty() || $session->cash_balances || $closing?->cash_actual_balance !== null) {
            $date = $session->session_date->toDateString();
            $at = $closings->first()?->created_at ?? $session->closed_at
                ?? ($session->session_date->isToday() ? now() : $session->session_date->copy()->endOfDay());
            // Old imported close timestamps can differ from their accounting day.
            if ($at->toDateString() !== $date) {
                $at = $session->session_date->copy()->endOfDay();
            }

            return ['kind' => 'closing', 'at' => $at, 'before_last_close' => $closings->count() > 1];
        }
        $next = CashierSession::whereDate('session_date', '>', $session->session_date)->orderBy('session_date')->lockForUpdate()->first();

        return $next ? ['kind' => 'next_opening', 'at' => $next->session_date->copy()->startOfDay()] : null;
    }

    private function refreshCorrectedClosing(CashierSession $session, array $changes, bool $beforeLastClose, ?DailyClosing $closing): void
    {
        // Keep the last counted amounts and the original close event. Refresh
        // expected values so reports expose the corrected reconciliation gap.
        $snapshot = $session->closing_summary;
        $totalChange = round(array_sum($changes), 2);
        if ($snapshot) {
            foreach ($changes as $type => $change) {
                foreach (['accounts', 'cash_accounts'] as $group) {
                    if (! isset($snapshot[$group][$type])) {
                        continue;
                    }
                    $snapshot[$group][$type]['opening'] = round($snapshot[$group][$type]['opening'] + $change, 2);
                    if ($beforeLastClose) {
                        $incoming = $group === 'accounts' ? 'income' : 'other_in';
                        $snapshot[$group][$type][$incoming] = round(($snapshot[$group][$type][$incoming] ?? 0) + max(0, -$change), 2);
                        $snapshot[$group][$type]['outgoing'] = round($snapshot[$group][$type]['outgoing'] + max(0, $change), 2);
                    } else {
                        $snapshot[$group][$type]['closing'] = round($snapshot[$group][$type]['closing'] + $change, 2);
                    }
                }
            }
            $snapshot['cash_opening'] = round(($snapshot['cash_opening'] ?? 0) + $totalChange, 2);
            if ($beforeLastClose) {
                $snapshot['cash_paid'] = round(($snapshot['cash_paid'] ?? 0) + array_sum(array_map(fn ($change) => max(0, $change), $changes)), 2);
                $snapshot['cash_other_in'] = round(($snapshot['cash_other_in'] ?? 0) + array_sum(array_map(fn ($change) => max(0, -$change), $changes)), 2);
                $snapshot['total_paid'] = round(($snapshot['total_paid'] ?? 0) + array_sum(array_map(fn ($change) => max(0, $change), $changes)), 2);
            } else {
                $snapshot['cash_in_hand'] = round(($snapshot['cash_in_hand'] ?? 0) + $totalChange, 2);
            }
            $session->closing_summary = $snapshot;
        }
        if (! $closing) {
            return;
        }
        foreach ($changes as $type => $change) {
            $closing->{$type.'_opening_balance'} = round((float) $closing->{$type.'_opening_balance'} + $change, 2);
            if ($beforeLastClose) {
                $closing->{$type.'_income'} = round((float) $closing->{$type.'_income'} + max(0, -$change), 2);
                $closing->{$type.'_expense'} = round((float) $closing->{$type.'_expense'} + max(0, $change), 2);
            } else {
                $closing->{$type.'_closing_balance'} = round((float) $closing->{$type.'_closing_balance'} + $change, 2);
            }
            if ($closing->{$type.'_actual_balance'} !== null) {
                $closing->{$type.'_difference'} = round((float) $closing->{$type.'_actual_balance'} - (float) $closing->{$type.'_closing_balance'}, 2);
            }
        }
        $closing->cash_opening_balance = round((float) $closing->cash_opening_balance + $totalChange, 2);
        if ($beforeLastClose) {
            $closing->cash_income = round((float) $closing->cash_income + array_sum(array_map(fn ($change) => max(0, -$change), $changes)), 2);
            $closing->cash_expense = round((float) $closing->cash_expense + array_sum(array_map(fn ($change) => max(0, $change), $changes)), 2);
        } else {
            $closing->cash_closing_balance = round((float) $closing->cash_closing_balance + $totalChange, 2);
        }
        if ($closing->cash_actual_balance !== null) {
            $closing->cash_difference = round((float) $closing->cash_actual_balance - (float) $closing->cash_closing_balance, 2);
        }
        $closing->difference = round(collect(array_keys(self::ACCOUNTS))->sum(fn ($type) => (float) $closing->{$type.'_difference'}), 2);
        $closing->save();
    }

    public function close(CashierSession $session, array $values): void
    {
        Account::ensureSystemAccounts();
        DB::transaction(function () use ($session, $values) {
            $this->lock();
            $session = CashierSession::lockForUpdate()->findOrFail($session->id);
            if ($session->session_date->isFuture()) {
                throw ValidationException::withMessages(['cashier' => 'A future cashier date cannot be closed.']);
            }
            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['cashier' => 'This cashier session is already closed.']);
            }
            $date = $session->session_date->toDateString();
            $summary = $this->summary($date);
            if (! hash_equals($this->fingerprint($summary), $values['balance_version'])) {
                throw ValidationException::withMessages(['balance_version' => 'Transactions changed while this form was open. Review the refreshed balances and close again.']);
            }
            if ($session->drawer_opening_balance === null && (! empty($values['separate_cash_balance']) || isset($values['account_additions']))) {
                $moves = collect(app(CashPositionService::class)->accounts($date, null, null, true))->sum('closing');
                $session->update(['drawer_opening_balance' => $summary['cash_opening'],
                    'drawer_adjustment' => round($summary['cash_in_hand'] - $summary['cash_opening'] - $moves, 2)]);
                $summary = $this->summary($date);
            }
            $independent = $session->drawer_opening_balance !== null;
            $withdrawal = round((float) $values['owner_withdrawal'], 2);
            $expected = collect(CashPositionService::ACCOUNTS)->map(fn ($label, $type) => $summary[$independent ? 'accounts' : 'cash_accounts'][$type]['closing'])->all();
            $fundedWithdrawal = isset($values['funding']);
            $withdrawalPlan = $withdrawal > 0 && $fundedWithdrawal ? app(OutgoingFundingService::class)->plan($values, $withdrawal, 'spare_parts') : [];
            $withdrawalAccounts = ['main' => 0.0, 'spare_parts' => $fundedWithdrawal ? 0.0 : $withdrawal, 'bank' => 0.0];
            $cashWithdrawal = $fundedWithdrawal ? 0.0 : $withdrawal;
            foreach ($withdrawalPlan as $row) {
                $type = app(OutgoingFundingService::class)->type($row['source']);
                $withdrawalAccounts[$type] += (float) $row['amount'];
                if (str_ends_with($row['source'], '_cashier')) {
                    $cashWithdrawal += (float) $row['amount'];
                }
            }
            foreach (['main', 'spare_parts'] as $type) {
                $expected[$type] = round($expected[$type] - $withdrawalAccounts[$type], 2);
            }
            if (! $fundedWithdrawal && $withdrawal > 0 && ($expected['spare_parts'] < 0 || ($independent ? $summary['cash_in_hand'] - $withdrawal : array_sum($expected)) < 0)) {
                throw ValidationException::withMessages(['owner_withdrawal' => 'The withdrawal exceeds the Spare Parts cash in hand.']);
            }
            $washingTransfer = round($expected['washing'], 2);
            $washingCashTransfer = round($summary['cash_accounts']['washing']['closing'], 2);
            $expected['main'] = round($expected['main'] + $washingTransfer, 2);
            $expected['washing'] = round($expected['washing'] - $washingTransfer, 2);
            $bankExpected = round($summary['accounts']['bank']['closing'] - $withdrawalAccounts['bank'], 2);
            $bankActual = round((float) ($values['balances']['bank'] ?? $bankExpected), 2);
            $bankDifference = round($bankActual - $bankExpected, 2);
            $counted = round((float) $values['closing_balance'], 2);
            $dailyAdditions = $values['account_additions'] ?? null;
            $allocationBases = [];
            $allocatedToday = 0.0;
            $verified = $expected;
            if ($dailyAdditions !== null) {
                foreach (['main', 'spare_parts'] as $type) {
                    $cashierWithdrawal = collect($withdrawalPlan)->where('source', $type.'_cashier')->sum('amount');
                    if (! $fundedWithdrawal && $type === 'spare_parts') {
                        $cashierWithdrawal = $withdrawal;
                    }
                    $allocationBases[$type] = round($summary['allocation_accounts'][$type]['base'] - $withdrawalAccounts[$type] + $cashierWithdrawal, 2);
                    $addition = round((float) $dailyAdditions[$type], 2);
                    $verified[$type] = round($allocationBases[$type] + $addition, 2);
                    if (abs($verified[$type]) > 999999999999.99) {
                        throw ValidationException::withMessages(['account_additions.'.$type => 'The resulting account balance is too large.']);
                    }
                    $allocatedToday += $addition;
                }
                $allocatedToday = round($allocatedToday, 2);
                if ((int) round($allocatedToday * 100) > (int) round(($summary['cashier_funds']['total'] - $cashWithdrawal) * 100)) {
                    throw ValidationException::withMessages(['account_additions' => 'Today’s account additions exceed the available cashier cash.']);
                }
            } elseif (isset($values['balances'])) {
                $verified['main'] = round((float) $values['balances']['main'], 2);
                $verified['spare_parts'] = round((float) $values['balances']['spare_parts'], 2);
                if (! $independent && (int) round(array_sum($verified) * 100) !== (int) round($counted * 100)) {
                    throw ValidationException::withMessages(['closing_balance' => 'Main Account and Spare Parts closing balances must add up to the counted cashier balance.']);
                }
            } elseif (! $independent) {
                // Older single-count forms put their variance into Main Account.
                $verified['main'] = round($expected['main'] + $counted - array_sum($expected), 2);
            }
            $differences = [];
            foreach (CashPositionService::ACCOUNTS as $type => $label) {
                $differences[$type] = round($verified[$type] - $expected[$type], 2);
            }
            $drawerExpected = $dailyAdditions !== null
                ? round($summary['cashier_funds']['total'] - $cashWithdrawal - $allocatedToday, 2)
                : ($independent ? round($summary['cash_in_hand'] - $cashWithdrawal, 2) : round(array_sum($expected), 2));
            $drawerDifference = $independent ? round($counted - $drawerExpected, 2) : array_sum($differences);
            $drawerAdjustmentDelta = $independent ? round($counted - ($summary['cash_in_hand'] - $cashWithdrawal), 2) : 0;
            $values['balances'] = $verified;
            if (($drawerDifference != 0 || $bankDifference != 0 || ($dailyAdditions === null && collect($differences)->contains(fn ($difference) => $difference != 0))) && blank($values['difference_reason'] ?? null)) {
                throw ValidationException::withMessages(['difference_reason' => 'Explain changed account balances or a difference in the counted cash.']);
            }
            $effectiveAt = $session->session_date->isToday() ? now() : $session->session_date->endOfDay();
            if ($withdrawal > 0) {
                $drawing = Drawing::create([
                    'drawing_date' => $date, 'person_name' => ($values['owner_name'] ?? null) ?: 'Owner',
                    'drawing_type' => 'Owner withdrawal', 'account_id' => Account::systemAccount($fundedWithdrawal ? app(OutgoingFundingService::class)->type($withdrawalPlan[0]['source']) : 'spare_parts')->id,
                    'amount' => $withdrawal, 'number' => 'DRW-CASHIER-'.$session->id.'-'.Str::upper(Str::random(6)),
                    'note' => 'Cashier closing #'.$session->id, 'created_by' => auth()->id(),
                ]);
                if ($fundedWithdrawal) {
                    app(OutgoingFundingService::class)->post($withdrawalPlan, $withdrawal, 'Owner withdrawal at cashier closing', $drawing, 'Cash', $effectiveAt, 'owner_withdrawal', transactionType: 'drawing', closingSession: $session);
                } else {
                    $this->accounts->recordTransaction('spare_parts', 'drawing', $withdrawal, 'Owner withdrawal at cashier closing', $drawing, 'Cash', transactionDate: $effectiveAt, category: 'owner_withdrawal');
                }
            }
            app(WashingSettlementService::class)->settle($session, $washingTransfer, $washingCashTransfer, $effectiveAt, 'closing');
            foreach ($differences as $type => $difference) {
                if ($difference != 0) {
                    $this->accounts->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference),
                        $dailyAdditions !== null ? 'Cashier daily account allocation' : 'Cashier cash count reconciliation', $session, $independent ? 'Adjustment' : 'Cash',
                        $dailyAdditions !== null ? 'Today added: '.number_format((float) $dailyAdditions[$type], 2).'; account base: '.number_format($allocationBases[$type], 2) : ($values['difference_reason'] ?? null),
                        transactionDate: $effectiveAt, category: $dailyAdditions !== null ? 'cashier_daily_allocation' : ($independent ? 'cashier_account_reconciliation' : 'cashier_cash_reconciliation'));
                }
            }
            if ($bankDifference != 0) {
                $this->accounts->recordTransaction('bank', $bankDifference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($bankDifference), 'Cashier bank balance reconciliation', $session, 'Bank Transfer', $values['difference_reason'], transactionDate: $effectiveAt, category: 'cashier_bank_reconciliation');
            }
            $operational = [];
            $closingData = ['closed_by' => auth()->id(), 'status' => 'closed', 'notes' => $independent ? 'Cashier cash is separate from account balances. Prior cash count adjustment: '.number_format($summary['drawer_adjustment'], 2) : 'Cash-only cashier session #'.$session->id,
                'difference_reason' => $values['difference_reason'] ?? null, 'difference' => ($dailyAdditions !== null ? 0 : array_sum($differences)) + $bankDifference + ($independent ? $drawerDifference : 0)];
            foreach (self::ACCOUNTS as $type => $label) {
                $account = $summary['accounts'][$type];
                $sweepChange = match ($type) {
                    'main' => $washingTransfer, 'washing' => -$washingTransfer, default => 0
                };
                $logicalExpected = $account['closing'] - ($withdrawalAccounts[$type] ?? 0) + $sweepChange;
                $accountDifference = $type === 'bank' ? $bankDifference : ($differences[$type] ?? 0);
                $operational[$type] = round($logicalExpected + $accountDifference, 2);
                $closingData[$type.'_opening_balance'] = $account['opening'];
                $closingData[$type.'_income'] = $account['income'] + max(0, $sweepChange);
                $closingData[$type.'_expense'] = $account['outgoing'] + ($withdrawalAccounts[$type] ?? 0) + max(0, -$sweepChange);
                $closingData[$type.'_closing_balance'] = $dailyAdditions !== null && $type !== 'bank' ? $operational[$type] : $logicalExpected;
                $closingData[$type.'_actual_balance'] = $operational[$type];
                $closingData[$type.'_difference'] = $dailyAdditions !== null && $type !== 'bank' ? 0 : $accountDifference;
                if ($dailyAdditions !== null && $type !== 'bank') {
                    $closingData[$type.'_income'] += max(0, $accountDifference);
                    $closingData[$type.'_expense'] += max(0, -$accountDifference);
                }
            }
            $closingData += ['cash_opening_balance' => $summary['cash_opening'], 'cash_income' => $summary['cash_received'] + $summary['cash_other_in'] + ($independent ? max(0, $summary['drawer_adjustment']) : 0),
                'cash_expense' => $summary['cash_paid'] + $cashWithdrawal + ($independent ? max(0, -$summary['drawer_adjustment']) : 0), 'cash_closing_balance' => $drawerExpected,
                'cash_actual_balance' => $counted, 'cash_difference' => $drawerDifference];
            if ($dailyAdditions !== null) {
                $closingData['cash_income'] = $summary['cash_received'] + $summary['cash_other_in'] + max(0, -$washingCashTransfer);
                $closingData['cash_expense'] = $summary['cash_paid'] + $cashWithdrawal + $allocatedToday + max(0, $washingCashTransfer);
                if ($summary['opening_context'] !== null) {
                    $cycleWashing = $washingCashTransfer - ($summary['opening_context']['cash_movements']['washing_cash'] ?? 0);
                    $closingData['cash_opening_balance'] = $summary['cashier_funds']['opening'];
                    $closingData['cash_income'] = $summary['cashier_cycle']['income'] + max(0, -$cycleWashing);
                    $closingData['cash_expense'] = $summary['cashier_cycle']['paid'] + $cashWithdrawal + $allocatedToday + max(0, $cycleWashing);
                }
            }
            DailyClosing::updateOrCreate(['closing_date' => $date], $closingData);
            $summary['closing_withdrawal'] = $withdrawal;
            $summary['closing_withdrawal_funding'] = $withdrawalPlan;
            $summary['closing_cash_withdrawal'] = $cashWithdrawal;
            $summary['closing_washing_transfer'] = $washingTransfer;
            $summary['closing_washing_cash_transfer'] = $washingCashTransfer;
            $summary['closing_washing_non_cash_transfer'] = round($washingTransfer - $washingCashTransfer, 2);
            $summary['closing_bank_balance'] = $bankActual;
            $summary['closing_account_additions'] = $dailyAdditions;
            $summary['closing_allocation_bases'] = $allocationBases;
            $summary['closing_allocated_today'] = $allocatedToday;
            $closingRows = AccountTransaction::posted()->whereIn('account_id', collect(self::ACCOUNTS)->map(fn ($label, $type) => Account::systemAccount($type)->id))->whereDate('transaction_date', $date)->get();
            $summary['closing_ledger_snapshot'] = app(DrawerCashierService::class)->ledgerSnapshot($closingRows);
            foreach (self::ACCOUNTS as $type => $label) {
                $summary['closing_account_totals'][$type] = ['opening' => $closingData[$type.'_opening_balance'], 'income' => $closingData[$type.'_income'], 'outgoing' => $closingData[$type.'_expense']];
            }
            $summary['closing_transaction_ids'] = $closingRows->pluck('id')->all();
            $summary['closing_verified_balances'] = $operational;
            foreach (self::ACCOUNTS as $type => $label) {
                $summary['closing_ledger_balances'][$type] = $this->accounts->ledgerOpeningBalance($type, Carbon::parse($date)->addDay()->startOfDay());
            }
            $summary['closing_counted_cash'] = $counted;
            $summary['closing_expected_cash'] = $drawerExpected;
            $summary['closing_cash_difference'] = $drawerDifference;
            unset($summary['transactions'], $summary['collections']);
            $session->events()->create(['action' => 'closed', 'cash_amount' => $counted,
                'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
                'details' => ['closing_snapshot' => $summary, 'separate_cash_balance' => $independent, 'owner_withdrawal' => $withdrawal, 'withdrawal_funding' => $withdrawalPlan, 'cash_withdrawal' => $cashWithdrawal, 'account_additions' => $dailyAdditions, 'allocation_bases' => $allocationBases, 'allocated_today' => $allocatedToday, 'washing_to_main' => $washingTransfer, 'washing_cash_to_main' => $washingCashTransfer, 'closing_balance' => $counted, 'balances' => $values['balances'], 'bank_balance' => $bankActual, 'bank_difference' => $bankDifference, 'reason' => $values['difference_reason'] ?? null], 'created_by' => auth()->id()]);
            $session->update([
                'status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now(),
                'owner_withdrawal' => (float) $session->owner_withdrawal + $withdrawal,
                'closing_summary' => $summary, 'actual_balances' => $operational, 'cash_balances' => $independent ? null : $values['balances'],
                'drawer_closing_balance' => $independent ? $counted : null,
                'drawer_adjustment' => $independent ? round((float) $session->drawer_adjustment + $drawerAdjustmentDelta, 2) : (float) $session->drawer_adjustment,
                'notes' => $values['difference_reason'] ?? null,
            ]);
        });
    }

    public function fingerprint(array $summary): string
    {
        return hash('sha256', json_encode([$summary['cash_opening'], $summary['cash_in_hand'], $summary['drawer_adjustment'], $summary['accounts'], $summary['cash_accounts'], $summary['transactions']->map(fn ($row) => [$row->id, $row->status, $row->credit, $row->debit])->all()]));
    }
}
