<?php

namespace App\Http\Controllers\Accounts;

use App\Models\AccountTransaction;
use App\Services\AccountService;
use Illuminate\Http\Request;

class BankAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $d = $this->ledgerData($request, 'bank');
        $p = $d['periodTransactions'];
        $cardMode = $request->routeIs('accounts.card-payments.index');
        if ($cardMode) {
            $d['transactions'] = $d['transactions']->appends(request()->query());
        }$d += ['title' => $cardMode ? 'Card / Bank Payments' : 'Bank Account Ledger', 'subtitle' => $cardMode ? 'Track card, bank transfer, QR, and settlement payments' : 'Card, bank transfer, QR, cheque, and online payment records', 'type' => 'bank', 'cardMode' => $cardMode, 'cards' => ['Opening Balance' => $d['totals']['opening'], 'Bank Transfers Received' => $p->where('payment_method', 'Bank Transfer')->sum('credit'), 'Card Payments' => $p->where('payment_method', 'Card')->sum('credit'), 'QR Payments' => $p->where('payment_method', 'QR')->sum('credit'), 'Cheque Payments' => $p->where('payment_method', 'Cheque')->sum('credit'), 'Bank Expenses' => $d['totals']['expense'], 'Closing Balance' => $d['totals']['closing']]];

        return view('accounts.ledger', $d);
    }

    public function reverse(Request $r, AccountTransaction $transaction, AccountService $s)
    {
        $r->validate(['reason' => 'required|min:5|max:500']);
        abort_unless($transaction->account_type === 'bank', 403);
        $s->reverseTransaction($transaction, $r->reason);

        return back()->with('success', 'Transaction reversed with a compensating entry.');
    }
}
