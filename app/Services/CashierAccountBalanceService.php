<?php

namespace App\Services;

use App\Models\Account;
use App\Models\CashierSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CashierAccountBalanceService
{
    public function preview(CashierSession $session, array $values): array
    {
        if ($session->status !== 'open' || $session->session_date->isFuture()) {
            throw ValidationException::withMessages(['cashier' => 'Choose an open cashier to correct the account total. Use closing history for a saved closing.']);
        }
        $cashier = app(CashierService::class);
        $summary = $cashier->summary($session->session_date->toDateString());
        if (! hash_equals($cashier->fingerprint($summary), $values['balance_version'])) {
            throw ValidationException::withMessages(['balance_version' => 'Balances changed. Refresh the cashier page before correcting an account total.']);
        }
        $type = $values['account'];
        $withdrawal = (float) $values['owner_withdrawal'];
        $plan = $withdrawal > 0 ? app(OutgoingFundingService::class)->plan($values, $withdrawal, 'spare_parts') : [];
        $openingWithdrawal = collect($plan)->where('source', $type)->sum('amount');
        $addition = round((float) $values['account_addition'], 2);
        $total = round($summary['allocation_accounts'][$type]['base'] - $openingWithdrawal + $addition, 2);

        return ['account' => $type, 'label' => CashierService::ACCOUNTS[$type], 'total' => $total,
            'ledger_balance' => $summary['accounts'][$type]['closing'], 'addition' => $addition,
            'opening_withdrawal' => $openingWithdrawal, 'summary' => $summary];
    }

    public function correct(CashierSession $session, array $values): array
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($session, $values) {
            $cashier = app(CashierService::class);
            $cashier->lock();
            $session = CashierSession::lockForUpdate()->findOrFail($session->id);
            $preview = $this->preview($session, $values);
            $type = $values['account'];
            $amount = round((float) $values['total_balance'], 2);
            $change = round($amount - $preview['total'], 2);
            if (abs(round($preview['ledger_balance'] + $change, 2)) > 999999999999.99) {
                throw ValidationException::withMessages(['total_balance' => 'The resulting account balance is too large.']);
            }
            if ($change != 0) {
                // This changes carried account funds, never today's physical cashier receipts.
                app(AccountService::class)->recordTransaction($type, $change > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($change),
                    'Verified '.$preview['label'].' total correction', $session, 'Adjustment', $values['reason'],
                    transactionDate: $session->session_date->isToday() ? now() : $session->session_date->copy()->endOfDay(),
                    category: 'cashier_account_balance_correction', fundingSource: $type);
            }
            $session->events()->create(['action' => 'account_balance_corrected', 'cash_amount' => $preview['summary']['cash_in_hand'],
                'washing_person_id' => $session->washing_person_id, 'washing_commission_amount' => $session->washing_commission_amount,
                'details' => ['account' => $type, 'label' => $preview['label'], 'previous_total' => $preview['total'], 'corrected_total' => $amount,
                    'ledger_before' => $preview['ledger_balance'], 'ledger_after' => round($preview['ledger_balance'] + $change, 2),
                    'change' => $change, 'pending_addition' => $preview['addition'], 'pending_opening_withdrawal' => $preview['opening_withdrawal'],
                    'reason' => $values['reason']], 'created_by' => auth()->id()]);
            $summary = $cashier->summary($session->session_date->toDateString());
            $expected = collect($summary['cash_accounts'])->map(fn ($account) => $account['closing'])->all();
            foreach (['main', 'spare_parts', 'bank'] as $account) {
                $expected[$account] = $summary['accounts'][$account]['closing'];
            }

            return ['success' => true, 'account' => $type, 'total' => $amount, 'change' => $change,
                'balance_version' => $cashier->fingerprint($summary), 'allocation_accounts' => $summary['allocation_accounts'],
                'expected' => $expected, 'cashier_funds' => $summary['cashier_funds'], 'cash_in_hand' => $summary['cash_in_hand'],
                'funding_options' => app(OutgoingFundingService::class)->options($session->session_date->toDateString(), true)];
        });
    }
}
