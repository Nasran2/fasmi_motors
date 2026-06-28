<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\AccountTransaction;
use Illuminate\Http\Request;
use Barryvdh\DomPDF\Facade\Pdf;
use App\Exports\AccountLedgerExport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Database\Eloquent\Builder;

class AccountExportController extends Controller
{
    public function export(Request $request, string $type)
    {
        $validTypes = ['main', 'spare_parts', 'bank', 'cash'];
        if (!in_array($type, $validTypes)) {
            abort(404);
        }

        $format = $request->input('format', 'pdf');
        
        if ($format === 'excel') {
            return Excel::download(
                new AccountLedgerExport($type, $request->from, $request->to, $request->method), 
                "{$type}_ledger_" . now()->format('Ymd_His') . ".xlsx"
            );
        }

        // PDF Generation
        $query = AccountTransaction::query()
            ->where('account_type', $type)
            ->when($request->from, fn(Builder $q, $date) => $q->whereDate('transaction_date', '>=', $date))
            ->when($request->to, fn(Builder $q, $date) => $q->whereDate('transaction_date', '<=', $date))
            ->when($request->method, fn(Builder $q, $method) => $q->where('payment_method', $method))
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');
            
        $transactions = $query->get();
        
        $title = match ($type) {
            'main' => 'Main Account Ledger',
            'spare_parts' => 'Spare Parts Ledger',
            'bank' => 'Bank Account Ledger',
            'cash' => 'Cash Book',
            default => 'Ledger',
        };

        $pdf = Pdf::loadView('accounts.exports.ledger-pdf', [
            'transactions' => $transactions,
            'title' => $title,
            'from' => $request->from,
            'to' => $request->to,
        ])->setPaper('a4', 'portrait');

        if ($request->has('print')) {
            return $pdf->stream("{$type}_ledger_" . now()->format('Ymd_His') . ".pdf");
        }

        return $pdf->download("{$type}_ledger_" . now()->format('Ymd_His') . ".pdf");
    }
}
