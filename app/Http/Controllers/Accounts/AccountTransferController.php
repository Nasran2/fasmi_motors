<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AccountTransaction;
use App\Services\AccountService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class AccountTransferController extends Controller
{
    public function index(Request $request)
    {
        $query = AccountTransaction::whereIn('transaction_type', ['transfer_in', 'transfer_out']);

        if ($request->filled('date_range')) {
            // standard date logic here
        }

        $transfers = $query->latest('transaction_date')->latest('id')->paginate(50);
        return view('accounts.transfers', compact('transfers'));
    }

    public function store(Request $request, AccountService $service)
    {
        $v = $request->validate([
            'from_account' => 'required|in:main,spare_parts,bank,cash',
            'to_account' => 'required|in:main,spare_parts,bank,cash|different:from_account',
            'amount' => 'required|numeric|gt:0',
            'transfer_date' => 'required|date',
            'payment_method' => 'nullable|string',
            'reference' => 'nullable|string',
            'note' => 'nullable|string',
        ]);

        DB::transaction(function() use ($v, $service) {
            $refStr = $v['reference'] ? 'Ref: ' . $v['reference'] : 'Internal Transfer';
            
            // Deduct from Source
            $service->recordTransaction(
                $v['from_account'],
                'transfer_out',
                $v['amount'],
                "Transfer out to " . ucfirst(str_replace('_', ' ', $v['to_account'])) . " - " . $refStr,
                null,
                $v['payment_method'],
                $v['note']
            );

            // Add to Destination
            $service->recordTransaction(
                $v['to_account'],
                'transfer_in',
                $v['amount'],
                "Transfer in from " . ucfirst(str_replace('_', ' ', $v['from_account'])) . " - " . $refStr,
                null,
                $v['payment_method'],
                $v['note']
            );
        });

        return redirect()->route('accounts.transfers.index')->with('success', 'Transfer completed successfully.');
    }
}
