<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\AccountService;
use App\Models\AccountTransaction;

class OpeningBalanceController extends Controller
{
    public function index()
    {
        $transactions = AccountTransaction::where('transaction_type', 'opening_balance')
            ->latest('transaction_date')
            ->paginate(50);

        return view('accounts.opening-balances', compact('transactions'));
    }

    public function store(Request $request, AccountService $service)
    {
        $v = $request->validate([
            'account_type' => 'required|in:main,spare_parts,bank,cash',
            'opening_date' => 'required|date',
            'amount' => 'required|numeric|gt:0',
            'balance_type' => 'required|in:debit,credit',
            'note' => 'nullable|string'
        ]);

        // Typically opening balance is credit for income/equity, debit for assets.
        // For our simplified model, we just record an 'opening_balance' transaction_type.
        // If balance_type is debit, it means it's an expense/negative opening, so we will use adjustment_decrease or similar.
        // Since AccountService handles it via $isIncrease logic, we assume credit = positive opening balance.

        $service->recordTransaction(
            $v['account_type'],
            $v['balance_type'] === 'credit' ? 'opening_balance' : 'adjustment_decrease',
            $v['amount'],
            "Opening Balance Setup",
            null,
            null,
            $v['note']
        );

        return back()->with('success', 'Opening balance saved successfully.');
    }
}
