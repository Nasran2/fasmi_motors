<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Models\CashierSession;
use App\Models\Payment;
use Carbon\Carbon;

class CashPositionService
{
    public const ACCOUNTS = ['main' => 'Main Account', 'washing' => 'Washing Account', 'spare_parts' => 'Spare Parts Account'];

    public function accounts(string $date, ?CashierSession $session = null, $collections = null): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $collections ??= app(CollectionReportService::class)->rows($date, $date);
        $collectedPaymentIds = $collections->where('channel', 'cash')->pluck('payment_ids')->flatten()->all();
        $bankId = Account::systemAccount('bank')->id;
        $result = [];
        foreach (self::ACCOUNTS as $type => $label) {
            $account = Account::systemAccount($type);
            $base = AccountTransaction::posted()->where('account_id', $account->id)->where(function ($query) use ($bankId) {
                $query->where('payment_method', 'Cash')->orWhere('account_type', 'cash')
                    ->orWhereIn('category', ['opening_balance', 'cashier_opening', 'cashier_cash_reconciliation'])
                    ->orWhere(function ($query) use ($bankId) {
                        $query->where('reference_type', (new AccountTransfer)->getMorphClass())
                            ->whereIn('reference_id', AccountTransfer::where(fn ($q) => $q->where('from_account_id', $bankId)->orWhere('to_account_id', $bankId))->select('id'));
                    });
            });
            $prior = (clone $base)->where('transaction_date', '<', $start);
            $opening = (float) $account->opening_balance + (float) (clone $prior)->sum('credit') - (float) (clone $prior)->sum('debit');
            $rows = (clone $base)->whereBetween('transaction_date', [$start, $start->copy()->endOfDay()])->get();
            $openingCorrections = $rows->where('category', 'cashier_opening');
            $opening += $openingCorrections->sum('credit') - $openingCorrections->sum('debit');
            if (isset($session?->cash_opening_balances[$type])) {
                $opening = (float) $session->cash_opening_balances[$type];
            }
            $rows = $rows->where('category', '!=', 'cashier_opening');
            $income = $expenses = $outgoing = $otherIn = $internalIn = $internalOut = 0.0;
            $groups = $rows->groupBy(fn ($row) => $row->reference_id ? $row->reference_type.'|'.$row->reference_id : 'entry-'.$row->id);
            foreach ($groups as $entries) {
                $net = round($entries->sum('credit') - $entries->sum('debit'), 2);
                $receipt = $entries->contains(fn ($row) => $row->reference_type === (new Payment)->getMorphClass()
                    ? in_array($row->reference_id, $collectedPaymentIds) : in_array($row->transaction_type, ['income', 'payment_received', 'payment'], true));
                if ($entries->contains(fn ($row) => $row->category === 'cashier_washing_sweep')) {
                    $internalIn += max(0, $net);
                    $internalOut += max(0, -$net);
                }
                if ($net > 0) {
                    if ($receipt) {
                        $income += $net;
                    } else {
                        $otherIn += $net;
                    }
                } elseif ($net < 0) {
                    $outgoing -= $net;
                    if ($entries->contains(fn ($row) => $row->transaction_type === 'expense')) {
                        $expenses -= $net;
                    }
                }
            }
            $result[$type] = ['opening' => round($opening, 2), 'income' => round($income, 2), 'other_in' => round($otherIn, 2),
                'internal_in' => round($internalIn, 2), 'internal_out' => round($internalOut, 2), 'expenses' => round($expenses, 2), 'outgoing' => round($outgoing, 2),
                'closing' => round($opening + $rows->sum('credit') - $rows->sum('debit'), 2)];
        }

        return $result;
    }

    public function suggestedOpening(string $date): float
    {
        return round(collect($this->accounts($date))->sum('opening'), 2);
    }
}
