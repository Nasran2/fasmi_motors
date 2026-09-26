<?php

namespace App\Http\Controllers\Accounts;

use App\Models\AccountTransaction;
use App\Services\AccountService;
use Illuminate\Http\Request;

class CashBookController extends BaseAccountController
{
    public function index(Request $request)
    {
        $d = $this->ledgerData($request, 'cash');
        $p = $d['periodTransactions'];
        $balance = $d['totals']['opening'];
        $balances = [];
        foreach (AccountTransaction::ledger('cash')->posted()->whereBetween('transaction_date', [$d['from'], $d['to']])->orderBy('transaction_date')->orderBy('id')->get() as $entry) {
            $balance += (float) $entry->credit - (float) $entry->debit;
            $balances[$entry->id] = round($balance, 2);
        }
        foreach ($d['transactions'] as $entry) {
            $entry->running_balance = $balances[$entry->id];
        }
        $d += ['title' => 'Cash Book', 'subtitle' => 'Daily cash received, cash paid, and cash balance', 'type' => 'cash', 'cards' => ['Cash Opening Balance' => $d['totals']['opening'], 'Cash Sales' => $p->where('transaction_type', 'income')->sum('credit'), 'Cash Collections' => $p->where('transaction_type', 'payment_received')->sum('credit'), 'Cash Expenses' => $p->where('transaction_type', 'expense')->sum('debit'), 'Cash Drawings' => $p->where('transaction_type', 'drawing')->sum('debit'), 'Cash Closing Balance' => $d['totals']['closing']]];

        return view('accounts.ledger', $d);
    }

    public function reverse(Request $r, AccountTransaction $transaction, AccountService $s)
    {
        $r->validate(['reason' => 'required|min:5|max:500']);
        abort_unless($transaction->account_type === 'cash', 403);
        abort_if($transaction->reference_type !== null, 422, 'Cancel the source record to reverse its ledger and related balances together.');
        $s->reverseTransaction($transaction, $r->reason);

        return back()->with('success', 'Transaction reversed with a compensating entry.');
    }
}
