<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\AccountTransaction;
use Illuminate\Http\Request;

class BaseAccountController extends Controller
{
    /**
     * Get base query with global date filters.
     */
    protected function getLedgerQuery(Request $request, string $accountType)
    {
        $query = AccountTransaction::with(['creator', 'reference'])
            ->where('account_type', $accountType);

        if ($request->filled('date_range')) {
            switch ($request->date_range) {
                case 'today':
                    $query->whereDate('transaction_date', today());
                    break;
                case 'yesterday':
                    $query->whereDate('transaction_date', today()->subDay());
                    break;
                case 'this_week':
                    $query->whereBetween('transaction_date', [now()->startOfWeek(), now()->endOfWeek()]);
                    break;
                case 'last_week':
                    $query->whereBetween('transaction_date', [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()]);
                    break;
                case 'this_month':
                    $query->whereBetween('transaction_date', [now()->startOfMonth(), now()->endOfMonth()]);
                    break;
                case 'last_month':
                    $query->whereBetween('transaction_date', [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()]);
                    break;
                case 'this_year':
                    $query->whereBetween('transaction_date', [now()->startOfYear(), now()->endOfYear()]);
                    break;
                case 'last_year':
                    $query->whereBetween('transaction_date', [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()]);
                    break;
                case 'custom':
                    if ($request->filled('start_date') && $request->filled('end_date')) {
                        $query->whereBetween('transaction_date', [$request->start_date . ' 00:00:00', $request->end_date . ' 23:59:59']);
                    }
                    break;
            }
        }

        if ($request->filled('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        if ($request->filled('search')) {
            $query->where(function($q) use ($request) {
                $q->where('transaction_no', 'like', '%' . $request->search . '%')
                  ->orWhere('description', 'like', '%' . $request->search . '%');
            });
        }

        return $query;
    }
}
