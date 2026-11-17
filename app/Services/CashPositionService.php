<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Models\CashierSession;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CashPositionService
{
    public const ACCOUNTS = ['main' => 'Main Account', 'washing' => 'Washing Account', 'spare_parts' => 'Spare Parts Account'];

    private function cashQuery(string $type, bool $movementsOnly)
    {
        $bankId = Account::systemAccount('bank')->id;
        $query = AccountTransaction::where('status', 'active')
            ->where('transaction_type', '!=', 'reversal')
            ->where('account_id', Account::systemAccount($type)->id);

        if ($type === 'main') {
            // Main account expenses and cashouts always go from the opening balance, never from the cashier.
            // Cashier drawer movements for Main account only include incoming cash collections.
            $query->whereNull('funding_source')
                ->whereNull('converted_from_method')
                ->where(function ($query) {
                    $query->where('credit', '>', 0)
                        ->orWhere('debit', '<=', 0)
                        ->orWhere('category', 'invoice_revision');
                });
        } elseif ($type === 'washing') {
            // All washing cash receipts are credited to Main Account immediately.
            // Washing account itself has zero separate drawer cash.
            return $query->whereRaw('1 = 0');
        } else {
            // Spare parts outgoings can go from cashier cash (spare_parts_cashier) or opening balance (spare_parts)
            $query->where(fn ($query) => $query->whereNull('funding_source')->orWhere('funding_source', 'spare_parts_cashier'));
        }

        $query->where(function ($query) use ($bankId) {
            $query->where(function ($q) use ($bankId) {
                $q->where(fn ($sub) => $sub->where('payment_method', 'Cash')->orWhere('account_type', 'cash')->orWhere('funding_source', 'spare_parts_cashier'))
                    ->where(function ($sub) use ($bankId) {
                        // If it's an account transfer, it must involve bank or cashier
                        $sub->where('reference_type', '!=', (new AccountTransfer)->getMorphClass())
                            ->orWhereNull('reference_type')
                            ->orWhere(function ($transferQuery) use ($bankId) {
                                $transferQuery->where('reference_type', (new AccountTransfer)->getMorphClass())
                                    ->where(function ($tq) use ($bankId) {
                                        $tq->whereIn('reference_id', AccountTransfer::where(fn ($q) => $q->where('from_account_id', $bankId)->orWhere('to_account_id', $bankId))->select('id'))
                                            ->orWhere('funding_source', 'spare_parts_cashier');
                                    });
                            });
                    });
            })
            ->orWhereIn('category', ['opening_balance', 'cashier_opening', 'cashier_cash_reconciliation']);
        });
        if ($movementsOnly) {
            $query->where(fn ($query) => $query->whereNull('category')->orWhereNotIn('category', [
                'opening_balance', 'cashier_opening', 'cashier_cash_reconciliation', 'cashier_opening_rebase',
                'cashier_account_opening', 'cashier_account_reconciliation',
            ]));
        }

        return $query;
    }

    private function groups(Collection $rows, array $collectedPaymentIds): Collection
    {
        return $rows->groupBy(fn ($row) => $row->reference_id ? $row->reference_type.'|'.$row->reference_id : 'entry-'.$row->id)
            ->map(fn ($entries) => [
                'entries' => $entries,
                'net' => round($entries->sum('credit') - $entries->sum('debit'), 2),
                'receipt' => $entries->contains(fn ($row) => $row->converted_from_method === null
                    && ($row->reference_type === (new Payment)->getMorphClass()
                        ? in_array($row->reference_id, $collectedPaymentIds)
                        : in_array($row->transaction_type, ['income', 'payment_received', 'payment'], true))),
                'internal' => $entries->contains(fn ($row) => $row->category === 'cashier_washing_sweep'),
            ]);
    }

    /** The exact net cash movements used in accounts(), with their source entries. */
    public function movementDetails(string $date, ?CashierSession $session, Collection $collections): Collection
    {
        $paymentIds = $collections->where('in_drawer', true)->pluck('payment_ids')->flatten()->all();
        $result = collect();
        foreach (self::ACCOUNTS as $type => $label) {
            $rows = $this->cashQuery($type, $session?->drawer_opening_balance !== null)
                ->whereDate('transaction_date', $date)->orderBy('transaction_date')->orderBy('id')->get()
                ->where('category', '!=', 'cashier_opening');
            foreach ($this->groups($rows, $paymentIds) as $group) {
                if ($group['internal'] || $group['net'] == 0) {
                    continue;
                }
                $receiptAllocation = $group['receipt'] && ($group['net'] > 0 || $group['entries']->first()->reference_type === (new Payment)->getMorphClass());
                $result->push($group + ['account' => $label, 'kind' => $receiptAllocation ? 'receipt' : ($group['net'] < 0 ? 'paid' : 'other')]);
            }
        }

        return $result;
    }

    public function accounts(string $date, ?CashierSession $session = null, $collections = null, bool $movementsOnly = false, ?string $through = null): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $collections ??= app(CollectionReportService::class)->rows($date, $through ?? $date);
        $collectedPaymentIds = $collections->where('in_drawer', true)->pluck('payment_ids')->flatten()->all();
        $result = [];
        foreach (self::ACCOUNTS as $type => $label) {
            $account = Account::systemAccount($type);
            $base = $this->cashQuery($type, $movementsOnly);
            $prior = (clone $base)->where('transaction_date', '<', $start);
            $opening = (float) $account->opening_balance + (float) (clone $prior)->sum('credit') - (float) (clone $prior)->sum('debit');
            $rows = (clone $base)->whereBetween('transaction_date', [$start, Carbon::parse($through ?? $date)->endOfDay()])->get();
            $openingCorrections = $rows->where('category', 'cashier_opening');
            $opening += $openingCorrections->sum('credit') - $openingCorrections->sum('debit');
            if (isset($session?->cash_opening_balances[$type])) {
                $opening = (float) $session->cash_opening_balances[$type];
            }
            if ($movementsOnly) {
                $opening = 0.0;
            }
            $rows = $rows->where('category', '!=', 'cashier_opening');
            $income = $expenses = $outgoing = $otherIn = $internalIn = $internalOut = 0.0;
            foreach ($this->groups($rows, $collectedPaymentIds) as $group) {
                ['entries' => $entries, 'net' => $net, 'receipt' => $receipt] = $group;
                if ($group['internal']) {
                    $internalIn += max(0, $net);
                    $internalOut += max(0, -$net);
                }
                if ($receipt && $entries->first()->reference_type === (new Payment)->getMorphClass()) {
                    // A bill discount can make the Spare Parts share negative.
                    // This offsets the receipt allocation; it is not cash paid out.
                    $income += $net;
                } elseif ($net > 0) {
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
