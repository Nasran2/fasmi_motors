<?php

namespace App\Http\Controllers\Accounts;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\AccountTransaction;
use App\Services\AccountService;

class CashBookController extends BaseAccountController
{
    public function index(Request $request)
    {
        $query = $this->getLedgerQuery($request, 'cash');
        
        $transactions = $query->latest('transaction_date')->latest('id')->paginate(50);
        $allTransactions = $query->get();

        $openingBalance = $allTransactions->where('transaction_type', 'opening_balance')->sum('credit') 
                        - $allTransactions->where('transaction_type', 'opening_balance')->sum('debit');

        $summary = [
            'opening_balance' => $openingBalance,
            'cash_collections' => $allTransactions->where('transaction_type', 'income')->sum('credit'),
            'cash_sales' => $allTransactions->where('transaction_type', 'income')->where('category', 'parts_sale')->sum('credit'),
            'cash_expenses' => $allTransactions->where('transaction_type', 'expense')->sum('debit'),
            'cash_drawings' => $allTransactions->where('transaction_type', 'drawing')->sum('debit'),
        ];
        
        $summary['closing_balance'] = $summary['opening_balance'] 
                                    + $summary['cash_collections'] 
                                    + $allTransactions->where('transaction_type', 'transfer_in')->sum('credit')
                                    - $summary['cash_expenses']
                                    - $summary['cash_drawings']
                                    - $allTransactions->where('transaction_type', 'transfer_out')->sum('debit');

        return view('accounts.cash-book', compact('transactions', 'summary'));
    }

    public function reverse(Request $request, AccountTransaction $transaction, AccountService $service)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        
        if ($transaction->account_type !== 'cash') {
            abort(403, 'Unauthorized');
        }

        $service->reverseTransaction($transaction, $request->reason);

        return back()->with('success', 'Transaction reversed successfully.');
    }
}
