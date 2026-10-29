<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Services\AccountTransactionSourceService;
use Illuminate\Http\Request;

class AccountTransactionSourceController extends Controller
{
    public function __invoke(Request $request, AccountTransaction $transaction, AccountTransactionSourceService $sources)
    {
        // Account is shop-scoped; the transaction itself is not.
        abort_unless($transaction->account, 404);
        if ($url = $sources->url($transaction, $request->user())) {
            return redirect($url);
        }

        return view('reports.transaction-detail', ['transaction' => $transaction->load('creator')]);
    }
}
