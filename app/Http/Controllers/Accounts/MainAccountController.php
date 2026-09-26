<?php

namespace App\Http\Controllers\Accounts;

use App\Models\AccountTransaction;
use App\Services\AccountService;
use Illuminate\Http\Request;

class MainAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $d = $this->ledgerData($request, 'main');
        $p = $d['periodTransactions'];
        $d += ['title' => 'Main Account Ledger', 'subtitle' => 'Service income, other income, expenses, and transfers', 'type' => 'main', 'cards' => ['Opening Balance' => $d['totals']['opening'], 'Service Income' => $p->whereIn('category', ['service', 'labor', 'washing'])->sum('credit'), 'Other Income' => $p->whereNotIn('category', ['service', 'labor', 'washing'])->where('transaction_type', 'income')->sum('credit'), 'Expenses' => $p->where('transaction_type', 'expense')->sum('debit'), 'Drawings' => $p->where('transaction_type', 'drawing')->sum('debit'), 'Transfer In' => $d['totals']['transfer_in'], 'Transfer Out' => $d['totals']['transfer_out'], 'Closing Balance' => $d['totals']['closing']]];

        return view('accounts.ledger', $d);
    }

    public function reverse(Request $r, AccountTransaction $transaction, AccountService $s)
    {
        $r->validate(['reason' => 'required|min:5|max:500']);
        abort_unless($transaction->account_type === 'main', 403);
        abort_if($transaction->reference_type !== null, 422, 'Cancel the source record to reverse its ledger and related balances together.');
        $s->reverseTransaction($transaction, $r->reason);

        return back()->with('success', 'Transaction reversed with a compensating entry.');
    }
}
