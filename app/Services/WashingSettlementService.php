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
        // Move the physical allocation separately from the non-cash allocation.
        // Only the cash leg belongs in drawer calculations; together they clear Washing.
        foreach (['Cash' => round($cash, 2), 'Adjustment' => round($balance - $cash, 2)] as $method => $amount) {
            if ($amount == 0) {
                continue;
            }
            $from = $amount > 0 ? 'washing' : 'main';
            $to = $amount > 0 ? 'main' : 'washing';
            $description = 'Washing '.($method === 'Cash' ? 'cash' : 'non-cash balance').' settlement at cashier '.$action;
            $transfer = AccountTransfer::create([
                'transfer_no' => 'AT-CASHIER-'.$session->id.'-'.Str::upper(Str::random(6)),
                'from_account_id' => Account::systemAccount($from)->id, 'to_account_id' => Account::systemAccount($to)->id,
                'amount' => abs($amount), 'transfer_date' => $at, 'payment_method' => $method, 'status' => 'Completed',
                'reference_number' => 'Cashier #'.$session->id, 'note' => $description, 'created_by' => auth()->id(),
            ]);
            foreach ([$from => 'transfer_out', $to => 'transfer_in'] as $type => $transactionType) {
                app(AccountService::class)->recordTransaction($type, $transactionType, abs($amount), $description,
                    $transfer, $method, transactionDate: $at, category: 'cashier_washing_sweep');
            }
        }
    }
}
