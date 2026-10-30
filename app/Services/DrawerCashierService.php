<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Physical drawer cash is independent of the operational account balances. */
class DrawerCashierService
{
    public function __construct(private AccountService $accounts) {}

    public function carriedOpening(string $date, bool $includeLegacy = false): ?float
    {
        if (! $includeLegacy && ! CashierSession::whereDate('session_date', '<', $date)->whereNotNull('drawer_opening_balance')->exists()) {
            return null;
        }
        $previous = CashierSession::whereDate('session_date', '<', $date)->latest('session_date')->first();
        if (! $previous) {
            return null;
        }
        $cash = app(CashierService::class)->summary($previous->session_date->toDateString())['cash_in_hand'];
        $from = $previous->session_date->copy()->addDay();
        $to = Carbon::parse($date)->subDay();
        if ($from->lte($to)) {
            $cash += collect(app(CashPositionService::class)->accounts($from->toDateString(), null, null, true, $to->toDateString()))->sum('closing');
        }

        return round($cash, 2);
    }

    public function cycleContext(?CashierSession $session, bool $lock = false): ?array
    {
        if (! $session) {
            return null;
        }
        $query = $session->events()->whereIn('action', ['opened', 'reopened', 'opening_corrected'])->latest('id');
        $events = ($lock ? $query->lockForUpdate() : $query)->get();
        foreach ($events as $event) {
            $context = $event->details['opening_context'] ?? null;
            if (is_array($context) && ($context['version'] ?? null) === 1) {
                return $context;
            }
        }

        return null;
    }

    public function openingPlan(string $date, array $summary, ?CashierSession $session): array
    {
        $previous = $session ?: CashierSession::whereDate('session_date', '<', $date)->latest('session_date')->first();
        $recordedCash = $previous?->drawer_closing_balance !== null
            ? (float) $previous->drawer_closing_balance
            : ($previous?->cash_balances ? round(array_sum($previous->cash_balances), 2) : null);
        $cash = $session ? $summary['cash_in_hand'] : round(($this->carriedOpening($date, true) ?? 0)
            + $summary['cashier_funds']['main'] + $summary['cashier_funds']['spare_parts'], 2);
        $accounts = $this->accountDefaults($summary, true);
        $ids = collect(['main', 'spare_parts'])->map(fn ($type) => Account::systemAccount($type)->id);
        $from = $previous?->session_date->toDateString() ?? $date;
        $cashRows = AccountTransaction::posted()->whereIn('account_id', $ids)
            ->whereBetween('transaction_date', [Carbon::parse($from)->startOfDay(), Carbon::parse($date)->endOfDay()])->get();
        $savedSnapshot = $previous?->closing_summary['closing_ledger_snapshot']['cashier'] ?? null;
        if ($previous && ! is_array($savedSnapshot)) {
            $cutoff = $previous->closed_at?->toDateString() === $from ? $previous->closed_at : Carbon::parse($from)->endOfDay();
            $cashRows = $cashRows->filter(fn ($entry) => $entry->transaction_date->gt($cutoff) || ($previous->closed_at && $entry->created_at?->gt($previous->closed_at)));
        }
        $unallocated = [];
        foreach (['main', 'spare_parts'] as $type) {
            $amount = app(OutgoingFundingService::class)->cashierAmount($cashRows->where('account_id', Account::systemAccount($type)->id), $type);
            $unallocated[$type] = round($amount - ($savedSnapshot[$type] ?? 0), 2);
            // Cash collected after the last close is already part of cashier opening cash.
            $accounts[$type] = round($accounts[$type] - $unallocated[$type], 2);
        }

        return [
            'cash' => $cash, 'accounts' => $accounts, 'unallocated_cash' => $unallocated,
            'reopening' => (bool) $session,
            'previous_date' => $previous?->session_date->toDateString(),
            'previous_cash' => $recordedCash,
            'previous_accounts' => $previous?->actual_balances ?? [],
        ];
    }

    public function accountDefaults(array $summary, bool $reopening): array
    {
        return collect(CashierService::ACCOUNTS)->map(fn ($label, $type) => $reopening
            ? $summary['accounts'][$type]['closing']
            : ($summary['account_opening_balances'][$type] ?? $summary['accounts'][$type]['opening']))->all();
    }

    public function ledgerSnapshot(Collection $transactions): array
    {
        $snapshot = ['cashier' => [], 'daily_allocation' => [], 'opening_funding' => [], 'opening_corrections' => []];
        foreach (['main', 'spare_parts'] as $type) {
            $rows = $transactions->where('account_id', Account::systemAccount($type)->id);
            $snapshot['cashier'][$type] = app(OutgoingFundingService::class)->cashierAmount($rows, $type);
            foreach (['daily_allocation' => $rows->where('category', 'cashier_daily_allocation'),
                'opening_funding' => $rows->where('funding_source', $type)->whereNotIn('category', ['cashier_opening', 'cashier_account_opening']),
                'opening_corrections' => $rows->whereIn('category', ['cashier_opening', 'cashier_account_opening'])] as $key => $entries) {
                $snapshot[$key][$type] = round($entries->sum('credit') - $entries->sum('debit'), 2);
            }
        }

        return $snapshot;
    }

    private function openingContext(float $cash, array $accounts, array $summary): array
    {
        return ['version' => 1, 'cash' => round($cash, 2), 'accounts' => $accounts,
            'snapshot' => $this->ledgerSnapshot($summary['transactions']),
            'cash_movements' => ['income' => round($summary['cash_received'] + $summary['cash_other_in'], 2), 'paid' => $summary['cash_paid'],
                'washing_cash' => $summary['cash_accounts']['washing']['closing']]];
    }

    private function validate(float $cash, ?array $balances, array $source): array
    {
        if (! is_finite($cash) || $cash < 0 || $cash > 999999999999.99) {
            throw ValidationException::withMessages(['opening_balance' => 'Enter the physical cash in the cashier.']);
        }
        $result = [];
        foreach (CashierService::ACCOUNTS as $type => $label) {
            $amount = $balances[$type] ?? $source[$type];
            if (! is_numeric($amount) || ! is_finite((float) $amount) || $amount < -999999999999.99 || $amount > 999999999999.99) {
                throw ValidationException::withMessages(['opening_balances.'.$type => 'Enter a valid balance for '.$label.'.']);
            }
            $result[$type] = round((float) $amount, 2);
        }

        return $result;
    }

    private function checkVersion(array $summary, ?string $version): void
    {
        if ($version !== null && ! hash_equals(app(CashierService::class)->fingerprint($summary), $version)) {
            throw ValidationException::withMessages(['balance_version' => 'Balances changed. Close and reopen this popup to review the latest amounts.']);
        }
    }

    private function postAccountChanges(CashierSession $session, array $changes, ?string $reason, bool $reopening = false, bool $currentBalance = false): void
    {
        foreach ($changes as $type => $difference) {
            if ($difference != 0) {
                $this->accounts->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference),
                    'Cashier verified account balance correction', $session, 'Adjustment', $reason,
                    transactionDate: $reopening || $currentBalance ? now() : $session->session_date->copy()->startOfDay(),
                    category: $reopening || $currentBalance ? 'cashier_account_reconciliation' : 'cashier_account_opening');
            }
        }
    }

    public function open(?CashierSession $session, float $cash, ?string $reason, array $washing, bool $saveDefault, ?array $balances, ?string $version): CashierSession
    {
        $service = app(CashierService::class);
        $summary = $service->summary(today()->toDateString());
        $this->checkVersion($summary, $version);
        $plan = $this->openingPlan(today()->toDateString(), $summary, $session);
        $source = $plan['accounts'];
        $confirmed = $this->validate($cash, $balances, $source);
        $changes = collect($confirmed)->map(fn ($amount, $type) => round($amount - $source[$type], 2))->all();
        $suggested = $plan['cash'];
        if ((array_filter($changes) || ($suggested !== null && round($cash - $suggested, 2) != 0)) && blank($reason)) {
            throw ValidationException::withMessages(['opening_reason' => 'Explain changed cashier cash or account balances.']);
        }
        $reopening = (bool) $session;
        $root = $session ? (float) ($session->drawer_opening_balance ?? $session->opening_balance) : round($cash, 2);
        $moves = collect(app(CashPositionService::class)->accounts(today()->toDateString(), null, null, true))->sum('closing');
        $adjustment = round($cash - $root - $moves, 2);
        $washing += ['washing_person_id' => null, 'washing_commission_amount' => app(WashingCommissionService::class)->defaultAmount()];
        $values = ['opening_balance' => $root, 'drawer_opening_balance' => $root, 'drawer_adjustment' => $adjustment,
            'account_opening_balances' => $session ? array_replace($this->accountDefaults($summary, false), $session->account_opening_balances ?? []) : $confirmed,
            'status' => 'open', 'notes' => $reason, 'closed_at' => null, 'closed_by' => null,
            'washing_person_id' => $washing['washing_person_id'], 'washing_commission_amount' => $washing['washing_commission_amount']];
        if ($session) {
            $session->update($values);
            DailyClosing::whereDate('closing_date', today())->update(['status' => 'reopened', 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => $reason ?: 'Cashier opened again']);
        } else {
            $session = CashierSession::create($values + ['session_date' => today(), 'opened_at' => now(), 'opened_by' => auth()->id()]);
        }
        foreach (['main', 'spare_parts'] as $type) {
            $difference = round($source[$type] - $summary['accounts'][$type]['closing'], 2);
            if ($difference != 0) {
                $this->accounts->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference),
                    'Cash retained in opening cashier balance', $session, 'Adjustment', 'Cash received after the previous close is carried in the cashier.',
                    transactionDate: now(), category: 'cashier_opening_allocation');
            }
        }
        $this->postAccountChanges($session, $changes, $reason, $reopening, true);
        if ($saveDefault) {
            Setting::updateOrCreate(['key' => WashingCommissionService::settingKey()], ['value' => $washing['washing_commission_amount'], 'group' => 'technician', 'type' => 'number']);
        }
        $session->events()->create(['action' => $reopening ? 'reopened' : 'opened', 'cash_amount' => $cash,
            'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
            'details' => ['reason' => $reason, 'separate_cash_balance' => true, 'cash_balances' => $confirmed, 'bank_balance' => $confirmed['bank'], 'drawer_adjustment' => $adjustment,
                'opening_context' => $this->openingContext($cash, $confirmed, $summary), 'carried_cash' => $suggested, 'previous_close_date' => $plan['previous_date']], 'created_by' => auth()->id()]);

        return $session;
    }

    public function correct(CashierSession $session, float $cash, array $balances, string $reason, string $version, bool $recalculate): void
    {
        $service = app(CashierService::class);
        if (! $service->canCorrectOpening($session) || ($recalculate && (! $session->session_date->isToday() || $session->status !== 'open'))) {
            throw ValidationException::withMessages(['cashier' => 'Choose a saved cashier date. Recalculation is available for today’s open cashier.']);
        }
        $date = $session->session_date->toDateString();
        $summary = $service->summary($date);
        $this->checkVersion($summary, $version);
        if (blank($reason)) {
            throw ValidationException::withMessages(['opening_reason' => 'Explain changed cashier cash or account balances.']);
        }
        $source = $this->accountDefaults($summary, false);
        $confirmed = $this->validate($cash, $balances, $source);
        $changes = collect($confirmed)->map(fn ($amount, $type) => round($amount - $source[$type], 2))->all();
        $oldCash = (float) ($session->drawer_opening_balance ?? $session->opening_balance);
        $cashChange = round($cash - $oldCash, 2);
        $closing = DailyClosing::whereDate('closing_date', $date)->lockForUpdate()->first();
        $closeEvents = $session->events()->where('action', 'closed')->orderBy('id')->get();
        $hasCount = $closeEvents->isNotEmpty() || $closing?->cash_actual_balance !== null || $session->cash_balances || $session->drawer_closing_balance !== null;
        $context = $this->cycleContext($session);
        $moves = collect(app(CashPositionService::class)->accounts($date, null, null, true))->sum('closing');
        // An account-only edit must not reset a physical drawer count variance.
        $adjustment = $recalculate && $cashChange != 0 ? 0.0
            : (($hasCount || $session->drawer_opening_balance === null) ? round($summary['cash_in_hand'] - $cash - $moves, 2) : (float) $session->drawer_adjustment);
        if ($context !== null) {
            $affectsCurrent = $recalculate || ! $hasCount;
            $adjustment = round($summary['cash_in_hand'] + ($affectsCurrent ? $cashChange : 0) - $cash - $moves, 2);
            if ($affectsCurrent) {
                $context['cash'] = round($context['cash'] + $cashChange, 2);
                foreach ($changes as $type => $difference) {
                    $context['accounts'][$type] = round($context['accounts'][$type] + $difference, 2);
                }
            }
            foreach (['main', 'spare_parts'] as $type) {
                $context['snapshot']['opening_corrections'][$type] = round(($context['snapshot']['opening_corrections'][$type] ?? 0) + $changes[$type], 2);
            }
        }
        if ($session->drawer_opening_balance !== null && $cashChange == 0 && ! array_filter($changes) && $adjustment == (float) $session->drawer_adjustment) {
            return;
        }
        $previous = ['cash_opening' => $oldCash, 'accounts' => $source, 'closing' => $closing?->getAttributes()];
        $this->postAccountChanges($session, $changes, $reason);
        if (! $recalculate) {
            $next = $hasCount ? null : CashierSession::whereDate('session_date', '>', $date)->oldest('session_date')->first();
            $at = $hasCount ? ($closeEvents->first()?->created_at ?? $session->closed_at ?? $session->session_date->copy()->endOfDay()) : $next?->session_date;
            if ($at && $hasCount && $at->toDateString() !== $date) {
                $at = $session->session_date->copy()->endOfDay();
            }
            if ($at) {
                foreach ($changes as $type => $difference) {
                    if ($difference != 0) {
                        $this->accounts->recordTransaction($type, $difference > 0 ? 'adjustment_decrease' : 'adjustment_increase', abs($difference),
                            'Account correction reconciled to recorded balance', $session, 'Adjustment', $reason,
                            transactionDate: $at, category: $hasCount ? 'cashier_account_reconciliation' : 'cashier_account_opening');
                    }
                }
            }
        }
        $this->refreshClosing($closing, $changes, $cashChange, ! $recalculate && $closeEvents->count() > 1);
        $session->update(['opening_balance' => $cash, 'drawer_opening_balance' => $cash, 'drawer_adjustment' => $adjustment,
            'drawer_closing_balance' => $session->drawer_closing_balance ?? ($session->cash_balances ? array_sum($session->cash_balances) : null),
            'account_opening_balances' => $confirmed]);
        $session->events()->create(['action' => 'opening_corrected', 'cash_amount' => $cash,
            'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
            'details' => ['reason' => $reason, 'separate_cash_balance' => true, 'recalculate_today' => $recalculate,
                'previous_opening' => $source, 'previous_drawer_opening' => $oldCash, 'cash_balances' => $confirmed,
                'previous_closing' => $previous['closing'], 'bank_balance' => $confirmed['bank'], 'drawer_adjustment' => $adjustment, 'opening_context' => $context], 'created_by' => auth()->id()]);
    }

    private function refreshClosing(?DailyClosing $closing, array $changes, float $cashChange, bool $beforeLast): void
    {
        if (! $closing) {
            return;
        }
        foreach ($changes as $type => $difference) {
            $closing->{$type.'_opening_balance'} = round((float) $closing->{$type.'_opening_balance'} + $difference, 2);
            if ($beforeLast) {
                $closing->{$type.'_income'} = round((float) $closing->{$type.'_income'} + max(0, -$difference), 2);
                $closing->{$type.'_expense'} = round((float) $closing->{$type.'_expense'} + max(0, $difference), 2);
            } else {
                $closing->{$type.'_closing_balance'} = round((float) $closing->{$type.'_closing_balance'} + $difference, 2);
            }
            if ($closing->{$type.'_actual_balance'} !== null) {
                $closing->{$type.'_difference'} = round((float) $closing->{$type.'_actual_balance'} - (float) $closing->{$type.'_closing_balance'}, 2);
            }
        }
        $closing->cash_opening_balance = round((float) $closing->cash_opening_balance + $cashChange, 2);
        if ($beforeLast) {
            $closing->cash_income = round((float) $closing->cash_income + max(0, -$cashChange), 2);
            $closing->cash_expense = round((float) $closing->cash_expense + max(0, $cashChange), 2);
        } else {
            $closing->cash_closing_balance = round((float) $closing->cash_closing_balance + $cashChange, 2);
        }
        if ($closing->cash_actual_balance !== null) {
            $closing->cash_difference = round((float) $closing->cash_actual_balance - (float) $closing->cash_closing_balance, 2);
        }
        $closing->save();
    }
}
