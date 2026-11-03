<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\AccountService;
use App\Support\WashingCharges;
use Illuminate\Http\Request;

class WashingAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $data = $this->ledgerData($request, 'washing');
        $charges = WashingCharges::forPeriod($data['from'], $data['to']);
        $data += [
            'title' => 'Washing Account Ledger', 'type' => 'washing',
            'subtitle' => 'New washing receipts credit Main immediately. Washing received is an information total; ledger balances below show legacy Washing funds and their movements.',
            'cards' => [
                'Opening Balance' => $data['totals']['opening'],
                'Washing Charges Billed' => $charges['washing_charges_billed'],
                'Washing Received · Information Only' => WashingCharges::receivedForPeriod($data['from'], $data['to']),
                'Unpaid / Held Washing Charges' => $charges['washing_charges_unpaid'],
                'Legacy Washing Money In' => $data['totals']['credit'],
                'Expenses' => $data['totals']['expense'],
                'Transfer In' => $data['totals']['transfer_in'],
                'Transfer Out' => $data['totals']['transfer_out'],
                'Closing Balance' => $data['totals']['closing'],
            ],
        ];

        return view('accounts.ledger', $data);
    }

    public function reverse(Request $request, AccountTransaction $transaction, AccountService $service)
    {
        $request->validate(['reason' => 'required|string|min:5|max:500']);
        abort_unless($transaction->account_type === 'washing' && $transaction->account_id === Account::systemAccount('washing')->id, 403);
        abort_if($transaction->reference_type !== null, 422, 'Cancel the source record to reverse its related balances together.');
        $service->reverseTransaction($transaction, $request->reason);

        return back()->with('success', 'Washing transaction reversed with a compensating entry.');
    }
}
