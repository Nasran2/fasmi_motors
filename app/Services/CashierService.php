<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
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
            $openingRows = $rows->where('category', 'cashier_opening');
            $opening += $openingRows->sum('credit') - $openingRows->sum('debit');
            $rows = $rows->where('category', '!=', 'cashier_opening');
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
        $cashAccounts = app(CashPositionService::class)->accounts($date, $session, $collections);

        return [
            'accounts' => $accounts, 'transactions' => $transactions, 'cash_accounts' => $cashAccounts, 'collections' => $collections,
            'cash_opening' => round(collect($cashAccounts)->sum('opening'), 2),
            'cash_in_hand' => round(collect($cashAccounts)->sum('closing'), 2),
            'cash_paid' => round(collect($cashAccounts)->sum(fn ($account) => $account['outgoing'] - $account['internal_out']), 2),
            'cash_other_in' => round(collect($cashAccounts)->sum(fn ($account) => $account['other_in'] - $account['internal_in']), 2),
            'cash_received' => round($collections->where('channel', 'cash')->sum('amount'), 2),
            'bank_received' => round($collections->where('channel', 'bank')->sum('amount'), 2),
            'total_received' => round($collections->sum('amount'), 2),
            'total_paid' => round(collect($cashAccounts)->sum(fn ($account) => $account['outgoing'] - $account['internal_out']) + ($accounts['bank']['outgoing'] ?? 0), 2),
            'transaction_count' => $collections->count(),
        ];
    }

    public function open(float $opening, ?string $reason, array $washing = [], bool $saveDefault = false, ?array $allocations = null, ?string $balanceVersion = null): CashierSession
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($opening, $reason, $washing, $saveDefault, $allocations, $balanceVersion) {
            $this->lock();
            if (CashierSession::active()) {
                throw ValidationException::withMessages(['cashier' => 'A cashier is already open. Close it before opening again.']);
            }
            $existing = CashierSession::whereDate('session_date', today())->lockForUpdate()->first();
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
        return round(array_sum($this->openingDefaults()), 2);
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

    public function correctOpening(CashierSession $session, float $opening, array $allocations, string $reason, string $balanceVersion, bool $recalculateToday = false): void
    {
        Account::ensureSystemAccounts();
        DB::transaction(function () use ($session, $opening, $allocations, $reason, $balanceVersion, $recalculateToday) {
            $this->lock();
            $session = CashierSession::lockForUpdate()->findOrFail($session->id);
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

    public function recordHistoricalOpening(string $date, float $opening, array $allocations, string $reason, string $balanceVersion): CashierSession
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($date, $opening, $allocations, $reason, $balanceVersion) {
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
            $this->correctOpening($session, $opening, $allocations, $reason, $balanceVersion);
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
            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['cashier' => 'This cashier session is already closed.']);
            }
            $date = $session->session_date->toDateString();
            $summary = $this->summary($date);
            if (! hash_equals($this->fingerprint($summary), $values['balance_version'])) {
                throw ValidationException::withMessages(['balance_version' => 'Transactions changed while this form was open. Review the refreshed balances and close again.']);
            }
            $withdrawal = round((float) $values['owner_withdrawal'], 2);
            $expected = collect($summary['cash_accounts'])->map(fn ($account) => $account['closing'])->all();
            $expected['spare_parts'] = round($expected['spare_parts'] - $withdrawal, 2);
            if ($withdrawal > 0 && ($expected['spare_parts'] < 0 || array_sum($expected) < 0)) {
                throw ValidationException::withMessages(['owner_withdrawal' => 'The withdrawal exceeds the Spare Parts cash in hand.']);
            }
            $washingTransfer = round($expected['washing'], 2);
            $expected['main'] = round($expected['main'] + $washingTransfer, 2);
            $expected['washing'] = 0.0;
            $bankExpected = $summary['accounts']['bank']['closing'];
            $bankActual = round((float) ($values['balances']['bank'] ?? $bankExpected), 2);
            $bankDifference = round($bankActual - $bankExpected, 2);
            $counted = round((float) $values['closing_balance'], 2);
            $verified = $expected;
            if (isset($values['balances'])) {
                $verified['main'] = round((float) $values['balances']['main'], 2);
                $verified['spare_parts'] = round((float) $values['balances']['spare_parts'], 2);
                if ((int) round(array_sum($verified) * 100) !== (int) round($counted * 100)) {
                    throw ValidationException::withMessages(['closing_balance' => 'Main Account and Spare Parts closing balances must add up to the counted cashier balance.']);
                }
            } else {
                // Older single-count forms put their variance into Main Account.
                $verified['main'] = round($expected['main'] + $counted - array_sum($expected), 2);
            }
            $differences = [];
            foreach (CashPositionService::ACCOUNTS as $type => $label) {
                $differences[$type] = round($verified[$type] - $expected[$type], 2);
            }
            $values['balances'] = $verified;
            if (($bankDifference != 0 || collect($differences)->contains(fn ($difference) => $difference != 0)) && blank($values['difference_reason'] ?? null)) {
                throw ValidationException::withMessages(['difference_reason' => 'Explain changed account balances or a difference in the counted cash.']);
            }
            $effectiveAt = $session->session_date->isToday() ? now() : $session->session_date->endOfDay();
            if ($withdrawal > 0) {
                $drawing = Drawing::create([
                    'drawing_date' => $date, 'person_name' => ($values['owner_name'] ?? null) ?: 'Owner',
                    'drawing_type' => 'Owner withdrawal', 'account_id' => Account::systemAccount('spare_parts')->id,
                    'amount' => $withdrawal, 'number' => 'DRW-CASHIER-'.$session->id.'-'.Str::upper(Str::random(6)),
                    'note' => 'Cashier closing #'.$session->id, 'created_by' => auth()->id(),
                ]);
                $this->accounts->recordTransaction('spare_parts', 'drawing', $withdrawal, 'Owner withdrawal at cashier closing', $drawing, 'Cash', transactionDate: $effectiveAt, category: 'owner_withdrawal');
            }
            if ($washingTransfer != 0) {
                $fromType = $washingTransfer > 0 ? 'washing' : 'main';
                $toType = $washingTransfer > 0 ? 'main' : 'washing';
                $from = Account::systemAccount($fromType);
                $to = Account::systemAccount($toType);
                $transfer = AccountTransfer::create([
                    'transfer_no' => 'AT-CASHIER-'.$session->id.'-'.Str::upper(Str::random(6)),
                    'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => abs($washingTransfer),
                    'transfer_date' => $effectiveAt, 'payment_method' => 'Cash', 'status' => 'Completed',
                    'reference_number' => 'Cashier #'.$session->id, 'note' => 'Washing cash settled into Main at cashier closing', 'created_by' => auth()->id(),
                ]);
                $this->accounts->recordTransaction($fromType, 'transfer_out', abs($washingTransfer), 'Cashier closing: washing cash settlement', $transfer, 'Cash', transactionDate: $effectiveAt, category: 'cashier_washing_sweep');
                $this->accounts->recordTransaction($toType, 'transfer_in', abs($washingTransfer), 'Cashier closing: washing cash settlement', $transfer, 'Cash', transactionDate: $effectiveAt, category: 'cashier_washing_sweep');
            }
            foreach ($differences as $type => $difference) {
                if ($difference != 0) {
                    $this->accounts->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference), 'Cashier cash count reconciliation', $session, 'Cash', $values['difference_reason'], transactionDate: $effectiveAt, category: 'cashier_cash_reconciliation');
                }
            }
            if ($bankDifference != 0) {
                $this->accounts->recordTransaction('bank', $bankDifference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($bankDifference), 'Cashier bank balance reconciliation', $session, 'Bank Transfer', $values['difference_reason'], transactionDate: $effectiveAt, category: 'cashier_bank_reconciliation');
            }
            $operational = [];
            $closingData = ['closed_by' => auth()->id(), 'status' => 'closed', 'notes' => 'Cash-only cashier session #'.$session->id,
                'difference_reason' => $values['difference_reason'] ?? null, 'difference' => array_sum($differences) + $bankDifference];
            foreach (self::ACCOUNTS as $type => $label) {
                $account = $summary['accounts'][$type];
                $sweepChange = match ($type) {
                    'main' => $washingTransfer, 'washing' => -$washingTransfer, default => 0
                };
                $logicalExpected = $account['closing'] - ($type === 'spare_parts' ? $withdrawal : 0) + $sweepChange;
                $accountDifference = $type === 'bank' ? $bankDifference : ($differences[$type] ?? 0);
                $operational[$type] = round($logicalExpected + $accountDifference, 2);
                $closingData[$type.'_opening_balance'] = $account['opening'];
                $closingData[$type.'_income'] = $account['income'] + max(0, $sweepChange);
                $closingData[$type.'_expense'] = $account['outgoing'] + ($type === 'spare_parts' ? $withdrawal : 0) + max(0, -$sweepChange);
                $closingData[$type.'_closing_balance'] = $logicalExpected;
                $closingData[$type.'_actual_balance'] = $operational[$type];
                $closingData[$type.'_difference'] = $accountDifference;
            }
            $closingData += ['cash_opening_balance' => $summary['cash_opening'], 'cash_income' => $summary['cash_received'] + $summary['cash_other_in'],
                'cash_expense' => $summary['cash_paid'] + $withdrawal, 'cash_closing_balance' => round(array_sum($expected), 2),
                'cash_actual_balance' => round(array_sum($values['balances']), 2), 'cash_difference' => array_sum($differences)];
            DailyClosing::updateOrCreate(['closing_date' => $date], $closingData);
            $summary['closing_withdrawal'] = $withdrawal;
            $summary['closing_washing_transfer'] = $washingTransfer;
            $summary['closing_bank_balance'] = $bankActual;
            unset($summary['transactions'], $summary['collections']);
            $session->events()->create(['action' => 'closed', 'cash_amount' => round(array_sum($values['balances']), 2),
                'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
                'details' => ['owner_withdrawal' => $withdrawal, 'washing_to_main' => $washingTransfer, 'closing_balance' => $counted, 'balances' => $values['balances'], 'bank_balance' => $bankActual, 'bank_difference' => $bankDifference, 'reason' => $values['difference_reason'] ?? null], 'created_by' => auth()->id()]);
            $session->update([
                'status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now(),
                'owner_withdrawal' => (float) $session->owner_withdrawal + $withdrawal,
                'closing_summary' => $summary, 'actual_balances' => $operational, 'cash_balances' => $values['balances'],
                'notes' => $values['difference_reason'] ?? null,
            ]);
        });
    }

    public function fingerprint(array $summary): string
    {
        return hash('sha256', json_encode([$summary['accounts'], $summary['cash_accounts'], $summary['transactions']->map(fn ($row) => [$row->id, $row->status, $row->credit, $row->debit])->all()]));
    }
}
