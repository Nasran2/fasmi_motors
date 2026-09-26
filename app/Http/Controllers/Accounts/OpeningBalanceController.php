<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpeningBalanceController extends BaseAccountController
{
    public function index(Request $request)
    {
        [$from,$to,$range] = $this->range($request);
        $transactions = AccountTransaction::with('creator')->whereIn('account_id', Account::pluck('id'))->whereIn('transaction_type', ['opening_balance', 'adjustment_decrease'])->whereBetween('transaction_date', [$from, $to])->latest('transaction_date')->paginate(25)->withQueryString();

        return view('accounts.opening-balances', compact('transactions', 'range', 'from', 'to'));
    }

    public function store(Request $request, AccountService $service)
    {
        $v = $request->validate(['account_type' => 'required|in:main,spare_parts,bank,cash', 'opening_date' => 'required|date', 'amount' => 'required|numeric|gte:0', 'balance_type' => 'required|in:debit,credit', 'note' => 'nullable|string|max:1000', 'edit_reason' => 'nullable|string|min:5|max:500']);
        DB::transaction(function () use ($v, $service) {
            $account = Account::systemAccount($v['account_type']);
            abort_unless($account, 422, 'Configure the account first.');
            Account::whereKey($account->id)->lockForUpdate()->firstOrFail();
            $existing = AccountTransaction::where('account_id', $account->id)->where('category', 'opening_balance')->where('status', 'active')->where('transaction_type', '!=', 'reversal')->lockForUpdate()->first();
            if ($existing && empty($v['edit_reason'])) {
                throw ValidationException::withMessages(['edit_reason' => 'An active opening balance already exists. Enter a reason to replace it safely.']);
            }if ($existing) {
                $service->reverseTransaction($existing, $v['edit_reason']);
            }
            $service->recordTransaction($v['account_type'], $v['balance_type'] === 'credit' ? 'opening_balance' : 'adjustment_decrease', $v['amount'], 'Opening balance setup', null, null, $v['note'] ?? null, $account?->id, $v['opening_date'], 'opening_balance');
        });

        return redirect()->route('accounts.opening-balances.index', ['date_range' => 'today'])->with('success', 'Opening balance saved with an auditable ledger entry.');
    }
}
