<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierClosingHistoryService
{
    public function version(CashierSession $session): string
    {
        return hash('sha256', json_encode([$session->getAttributes(), $session->events()->max('id')]));
    }

    public function correct(CashierSession $session, array $values): void
    {
        Account::ensureSystemAccounts();
        DB::transaction(function () use ($session, $values) {
            $cashier = app(CashierService::class);
            $cashier->lock();
            $session = CashierSession::lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'closed' || $session->session_date->isFuture()) {
                throw ValidationException::withMessages(['cashier' => 'Only a saved, closed cashier dated today or earlier can be corrected.']);
            }
            if (! hash_equals($this->version($session), $values['closing_version'])) {
                throw ValidationException::withMessages(['closing_version' => 'This closing changed. Close and reopen the details popup before editing again.']);
            }
            $date = $session->session_date->toDateString();
            $closing = DailyClosing::whereDate('closing_date', $date)->lockForUpdate()->first();
            $current = $cashier->summary($date);
            $rootCash = (float) ($session->drawer_opening_balance ?? $current['cash_opening']);
            $physicalMoves = collect(app(CashPositionService::class)->accounts($date, null, null, true))->sum('closing');
            $summary = $session->closing_summary ?? [];
            $previous = ['summary' => $summary, 'balances' => $session->actual_balances,
                'cash' => $session->drawer_closing_balance ?? $closing?->cash_actual_balance ?? ($session->cash_balances ? array_sum($session->cash_balances) : null),
                'daily_closing' => $closing?->getAttributes()];
            $balances = [];
            foreach (CashierService::ACCOUNTS as $type => $label) {
                $balances[$type] = round((float) $values['balances'][$type], 2);
            }
            $additions = $values['account_additions'] ?? null;
            $allocationMode = is_array($summary['closing_account_additions'] ?? null);
            if ($allocationMode && $additions === null) {
                throw ValidationException::withMessages(['account_additions' => 'Enter the Main and Spare Parts additions for this closing.']);
            }
            $expectedCash = (float) ($closing?->cash_closing_balance ?? $summary['cash_in_hand'] ?? 0);
            $allocated = (float) ($summary['closing_allocated_today'] ?? 0);
            $bankFundedMain = round((float) ($summary['allocation_accounts']['main']['bank_converted_cash'] ?? 0), 2);
            $sparePartsOpeningCash = round((float) ($summary['closing_spare_parts_opening_cash'] ?? 0), 2);
            $previousDrawerAllocated = round((float) ($summary['closing_drawer_allocated_today'] ?? ($allocated - $bankFundedMain + $sparePartsOpeningCash)), 2);
            $drawerAllocated = $previousDrawerAllocated;
            if ($allocationMode) {
                $allocated = 0.0;
                foreach (['main', 'spare_parts'] as $type) {
                    $additions[$type] = round((float) $additions[$type], 2);
                    $balances[$type] = round(($summary['closing_allocation_bases'][$type] ?? 0) + $additions[$type], 2);
                    if (abs($balances[$type]) > 999999999999.99) {
                        throw ValidationException::withMessages(['account_additions.'.$type => 'The resulting account balance is too large.']);
                    }
                    $allocated += $additions[$type];
                }
                $drawerAllocated = round($allocated - $bankFundedMain + $sparePartsOpeningCash, 2);
                $available = round(($summary['cashier_funds']['total'] ?? 0) - ($summary['closing_cash_withdrawal'] ?? 0), 2);
                if ((int) round($drawerAllocated * 100) > (int) round($available * 100)) {
                    throw ValidationException::withMessages(['account_additions' => 'The account additions exceed the cashier cash available at this closing.']);
                }
                $expectedCash = round($available - $drawerAllocated, 2);
            }
            $cash = round((float) $values['closing_balance'], 2);
            $cashChange = round($cash - (float) ($previous['cash'] ?? $current['cash_in_hand']), 2);
            $oldBalances = $session->actual_balances ?? [];
            $next = CashierSession::whereDate('session_date', '>', $date)->oldest('session_date')->first();
            $at = $session->closed_at && $session->closed_at->toDateString() === $date
                ? $session->closed_at : Carbon::parse($date)->endOfDay();
            $differences = [];
            $correctionIds = [];
            foreach ($balances as $type => $amount) {
                $old = (float) ($oldBalances[$type] ?? $closing?->{$type.'_actual_balance'} ?? $closing?->{$type.'_closing_balance'} ?? $summary['accounts'][$type]['closing'] ?? 0);
                $change = round($amount - $old, 2);
                $differences[$type] = $change;
                if ($change == 0) {
                    continue;
                }
                $correction = app(AccountService::class)->recordTransaction($type, $change > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($change),
                    'Cashier closing balance correction', $session, 'Adjustment', $values['reason'], transactionDate: $at, category: 'cashier_closing_correction');
                $correctionIds[] = $correction->id;
                // A later recorded opening remains a fixed checkpoint, not a new payment.
                if ($next) {
                    $category = app(DrawerCashierService::class)->cycleContext($next) === null ? 'cashier_account_opening' : 'cashier_closing_checkpoint';
                    app(AccountService::class)->recordTransaction($type, $change > 0 ? 'adjustment_decrease' : 'adjustment_increase', abs($change),
                        'Earlier closing correction reconciled to saved opening', $session, 'Adjustment', $values['reason'],
                        transactionDate: $next->session_date->copy()->startOfDay(), category: $category);
                }
            }
            if ($closing) {
                foreach ($balances as $type => $amount) {
                    if ($allocationMode && in_array($type, ['main', 'spare_parts'], true)) {
                        $closing->{$type.'_closing_balance'} = $amount;
                        $closing->{$type.'_income'} += max(0, $differences[$type]);
                        $closing->{$type.'_expense'} += max(0, -$differences[$type]);
                    }
                    $closing->{$type.'_actual_balance'} = $amount;
                    $closing->{$type.'_difference'} = round($amount - (float) $closing->{$type.'_closing_balance'}, 2);
                }
                if ($allocationMode) {
                    $closing->cash_expense = round((float) $closing->cash_expense + $drawerAllocated - $previousDrawerAllocated, 2);
                }
                $closing->cash_closing_balance = $expectedCash;
                $closing->cash_actual_balance = $cash;
                $closing->cash_difference = round($cash - $expectedCash, 2);
                $closing->difference = round($closing->cash_difference + collect(array_keys(CashierService::ACCOUNTS))->sum(fn ($type) => $closing->{$type.'_difference'}), 2);
                $closing->difference_reason = $values['reason'];
                $closing->save();
            }
            if (isset($summary['closing_transaction_ids'])) {
                $summary['closing_transaction_ids'] = array_values(array_unique(array_merge($summary['closing_transaction_ids'], $correctionIds)));
            }
            $summary['separate_cash_balance'] = true;
            if ($closing) {
                foreach (CashierService::ACCOUNTS as $type => $label) {
                    $summary['closing_account_totals'][$type] = ['opening' => $closing->{$type.'_opening_balance'}, 'income' => $closing->{$type.'_income'}, 'outgoing' => $closing->{$type.'_expense'}];
                }
            }
            $summary['closing_bank_balance'] = $balances['bank'];
            $summary['closing_verified_balances'] = $balances;
            foreach (CashierService::ACCOUNTS as $type => $label) {
                $summary['closing_ledger_balances'][$type] = app(AccountService::class)->ledgerOpeningBalance($type, Carbon::parse($date)->addDay()->startOfDay());
            }
            $summary['closing_counted_cash'] = $cash;
            $summary['closing_expected_cash'] = $expectedCash;
            $summary['closing_cash_difference'] = round($cash - $expectedCash, 2);
            $summary['closing_correction_reason'] = $values['reason'];
            if ($allocationMode) {
                $summary['closing_account_additions'] = $additions;
                $summary['closing_allocated_today'] = $allocated;
            }
            $session->update(['closing_summary' => $summary, 'actual_balances' => $balances,
                'drawer_closing_balance' => $cash,
                'drawer_opening_balance' => $rootCash,
                'drawer_adjustment' => round($current['cash_in_hand'] + $cashChange - $rootCash - $physicalMoves, 2)]);
            $session->events()->create(['action' => 'closing_corrected', 'cash_amount' => $cash,
                'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
                'details' => ['reason' => $values['reason'], 'previous_closing' => $previous, 'closing_snapshot' => $summary,
                    'cash_balances' => $balances, 'bank_balance' => $balances['bank'], 'account_changes' => $differences,
                    'later_recorded_opening_preserved' => $next?->session_date->toDateString()], 'created_by' => auth()->id()]);
        });
    }
}
