<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransfer;
use App\Models\CashierSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

class WashingSettlementService
{
    /** Called inside the locked cashier transaction. Bank funds stay in Bank. */
    public function settle(CashierSession $session, float $balance, float $cash, CarbonInterface $at, string $action): void
    {
        // If there is no positive washing ledger balance to settle, do nothing.
        // Washing collections are credited directly to Main Account immediately.
        if ($balance <= 0.001) {
            return;
        }

        // Only transfer positive legacy washing funds to Main. Never transfer backwards from Main to Washing.
        $settleCash = min(round($balance, 2), max(0, round($cash, 2)));
        $settleAdjustment = round($balance - $settleCash, 2);

        foreach (['Cash' => $settleCash, 'Adjustment' => $settleAdjustment] as $method => $amount) {
            if ($amount <= 0.001) {
                continue;
            }
            $description = 'Washing '.($method === 'Cash' ? 'cash' : 'non-cash balance').' settlement at cashier '.$action;
            $transfer = AccountTransfer::create([
                'transfer_no' => 'AT-CASHIER-'.$session->id.'-'.Str::upper(Str::random(6)),
                'from_account_id' => Account::systemAccount('washing')->id, 'to_account_id' => Account::systemAccount('main')->id,
                'amount' => $amount, 'transfer_date' => $at, 'payment_method' => $method, 'status' => 'Completed',
                'reference_number' => 'Cashier #'.$session->id, 'note' => $description, 'created_by' => auth()->id(),
            ]);
            foreach (['washing' => 'transfer_out', 'main' => 'transfer_in'] as $type => $transactionType) {
                app(AccountService::class)->recordTransaction($type, $transactionType, $amount, $description,
                    $transfer, $method, transactionDate: $at, category: 'cashier_washing_sweep');
            }
        }
    }
}
