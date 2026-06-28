<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DailyClosing;
use App\Models\AccountTransaction;
use Illuminate\Support\Facades\DB;

class DailyClosingController extends Controller
{
    public function index(Request $request)
    {
        $closings = DailyClosing::latest('closing_date')->paginate(30);
        
        $todayStr = now()->format('Y-m-d');
        $isClosed = DailyClosing::whereDate('closing_date', $todayStr)->exists();

        // Calculate expected balances for today if not closed
        $expected = [];
        if (!$isClosed) {
            foreach (['main', 'spare_parts', 'bank', 'cash'] as $accType) {
                $expected[$accType] = $this->calculateExpectedClosing($accType, $todayStr);
            }
        }

        return view('accounts.daily-closing', compact('closings', 'isClosed', 'expected'));
    }

    public function store(Request $request)
    {
        $todayStr = now()->format('Y-m-d');
        if (DailyClosing::whereDate('closing_date', $todayStr)->exists()) {
            return back()->with('error', 'Today is already closed.');
        }

        $v = $request->validate([
            'difference' => 'required|numeric',
            'difference_reason' => 'required_if:difference,!=,0',
            'notes' => 'nullable|string'
        ]);

        DB::transaction(function() use ($v, $todayStr) {
            $expected = [];
            foreach (['main', 'spare_parts', 'bank', 'cash'] as $accType) {
                $expected[$accType] = $this->calculateExpectedClosing($accType, $todayStr);
            }

            DailyClosing::create([
                'closing_date' => $todayStr,
                
                'main_opening_balance' => $expected['main']['opening'],
                'main_income' => $expected['main']['income'],
                'main_expense' => $expected['main']['expense'],
                'main_closing_balance' => $expected['main']['closing'],

                'spare_parts_opening_balance' => $expected['spare_parts']['opening'],
                'spare_parts_income' => $expected['spare_parts']['income'],
                'spare_parts_expense' => $expected['spare_parts']['expense'],
                'spare_parts_closing_balance' => $expected['spare_parts']['closing'],

                'bank_opening_balance' => $expected['bank']['opening'],
                'bank_income' => $expected['bank']['income'],
                'bank_expense' => $expected['bank']['expense'],
                'bank_closing_balance' => $expected['bank']['closing'],

                'cash_opening_balance' => $expected['cash']['opening'],
                'cash_income' => $expected['cash']['income'],
                'cash_expense' => $expected['cash']['expense'],
                'cash_closing_balance' => $expected['cash']['closing'],

                'difference' => $v['difference'],
                'difference_reason' => $v['difference_reason'] ?? null,
                'notes' => $v['notes'],
                'closed_by' => auth()->id() ?? 1,
            ]);

            // Next day opening balances are implicitly handled because running_balance in account_transactions is continuous.
            // Opening balance logic can use the latest running_balance or last closing.
        });

        return redirect()->route('accounts.daily-closing.index')->with('success', 'Day closed successfully.');
    }

    private function calculateExpectedClosing(string $accountType, string $date)
    {
        $transactions = AccountTransaction::where('account_type', $accountType)
            ->whereDate('transaction_date', $date)
            ->where('status', 'active')
            ->get();
        
        $opening = 0; // Normally fetch from yesterday's closing or latest running balance before today
        $prevClosing = DailyClosing::whereDate('closing_date', '<', $date)->latest('closing_date')->first();
        if ($prevClosing) {
            $key = $accountType . '_closing_balance';
            $opening = $prevClosing->$key;
        }

        $income = $transactions->whereIn('transaction_type', ['income', 'transfer_in'])->sum('credit');
        $expense = $transactions->whereIn('transaction_type', ['expense', 'transfer_out', 'drawing', 'purchase_payment'])->sum('debit');

        return [
            'opening' => $opening,
            'income' => $income,
            'expense' => $expense,
            'closing' => $opening + $income - $expense
        ];
    }
}
