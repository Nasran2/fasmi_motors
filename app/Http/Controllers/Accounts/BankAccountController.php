<?php

namespace App\Http\Controllers\Accounts;

use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\AccountTransaction;
use App\Services\AccountService;

class BankAccountController extends BaseAccountController
{
    public function index(Request $request)
    {
        $query = $this->getLedgerQuery($request, 'bank');
        
        $transactions = $query->latest('transaction_date')->latest('id')->paginate(50);
        $allTransactions = $query->get();

        $openingBalance = $allTransactions->where('transaction_type', 'opening_balance')->sum('credit') 
                        - $allTransactions->where('transaction_type', 'opening_balance')->sum('debit');

        $summary = [
            'opening_balance' => $openingBalance,
            'bank_receipts' => $allTransactions->whereIn('payment_method', ['bank_transfer', 'qr', 'card', 'bank_deposit', 'online_transfer'])->sum('credit'),
            'card_payments' => $allTransactions->where('payment_method', 'card')->sum('credit'),
            'cheque_payments' => $allTransactions->where('payment_method', 'cheque')->sum('credit'),
            'bank_expenses' => $allTransactions->where('transaction_type', 'expense')->sum('debit'),
            'transfers' => $allTransactions->where('transaction_type', 'transfer_in')->sum('credit') - $allTransactions->where('transaction_type', 'transfer_out')->sum('debit'),
        ];
        
        $summary['closing_balance'] = $summary['opening_balance'] 
                                    + $summary['bank_receipts'] 
                                    + $summary['cheque_payments']
                                    + $allTransactions->where('transaction_type', 'transfer_in')->sum('credit')
                                    - $summary['bank_expenses']
                                    - $allTransactions->where('transaction_type', 'transfer_out')->sum('debit');

        return view('accounts.bank-ledger', compact('transactions', 'summary'));
    }

    public function reverse(Request $request, AccountTransaction $transaction, AccountService $service)
    {
        $request->validate(['reason' => 'required|string|max:255']);
        
        if ($transaction->account_type !== 'bank') {
            abort(403, 'Unauthorized');
        }

        $service->reverseTransaction($transaction, $request->reason);

        return back()->with('success', 'Transaction reversed successfully.');
    }
}
