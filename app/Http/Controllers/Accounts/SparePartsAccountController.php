<?php

namespace App\Http\Controllers\Accounts;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\AccountTransaction;
use App\Services\AccountService;

class SparePartsAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $query = $this->getLedgerQuery($request, 'spare_parts');
        
        $transactions = $query->latest('transaction_date')->latest('id')->paginate(50);
        $allTransactions = $query->get();

        $openingBalance = $allTransactions->where('transaction_type', 'opening_balance')->sum('credit') 
                        - $allTransactions->where('transaction_type', 'opening_balance')->sum('debit');

        $summary = [
            'opening_balance' => $openingBalance,
            'parts_sales' => $allTransactions->where('transaction_type', 'income')->sum('credit'),
            'purchase_payments' => $allTransactions->where('transaction_type', 'purchase_payment')->sum('debit'),
            'drawings' => $allTransactions->where('transaction_type', 'drawing')->sum('debit'),
            'transfer_in' => $allTransactions->where('transaction_type', 'transfer_in')->sum('credit'),
            'transfer_out' => $allTransactions->where('transaction_type', 'transfer_out')->sum('debit'),
        ];
        
        $summary['closing_balance'] = $summary['opening_balance'] 
                                    + $summary['parts_sales'] 
                                    + $summary['transfer_in']
                                    - $summary['purchase_payments']
                                    - $summary['drawings']
                                    - $summary['transfer_out'];

        return view('accounts.spare-parts-ledger', compact('transactions', 'summary'));
    }

    public function reverse(Request $request, AccountTransaction $transaction, AccountService $service)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        
        if ($transaction->account_type !== 'spare_parts') {
            abort(403, 'Unauthorized');
        }

        $service->reverseTransaction($transaction, $request->reason);

        return back()->with('success', 'Transaction reversed successfully.');
    }
}
