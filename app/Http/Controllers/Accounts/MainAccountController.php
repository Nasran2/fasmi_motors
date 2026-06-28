<?php

namespace App\Http\Controllers\Accounts;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\AccountTransaction;
use App\Services\AccountService;

class MainAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $query = $this->getLedgerQuery($request, 'main');
        
        $transactions = $query->latest('transaction_date')->latest('id')->paginate(50);
        $allTransactions = $query->get();

        $openingBalance = $allTransactions->where('transaction_type', 'opening_balance')->sum('credit') 
                        - $allTransactions->where('transaction_type', 'opening_balance')->sum('debit');

        $summary = [
            'opening_balance' => $openingBalance,
            'service_income' => $allTransactions->where('transaction_type', 'income')->where('category', 'service')->sum('credit'),
            'other_income' => $allTransactions->where('transaction_type', 'income')->where('category', '!=', 'service')->sum('credit'),
            'expenses' => $allTransactions->where('transaction_type', 'expense')->sum('debit'),
            'transfer_in' => $allTransactions->where('transaction_type', 'transfer_in')->sum('credit'),
            'transfer_out' => $allTransactions->where('transaction_type', 'transfer_out')->sum('debit'),
        ];
        
        $summary['closing_balance'] = $summary['opening_balance'] 
                                    + $summary['service_income'] 
                                    + $summary['other_income'] 
                                    + $summary['transfer_in']
                                    - $summary['expenses']
                                    - $summary['transfer_out'];

        return view('accounts.main-ledger', compact('transactions', 'summary'));
    }

    public function reverse(Request $request, AccountTransaction $transaction, AccountService $service)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        
        if ($transaction->account_type !== 'main') {
            abort(403, 'Unauthorized');
        }

        $service->reverseTransaction($transaction, $request->reason);

        return back()->with('success', 'Transaction reversed successfully.');
    }
}
