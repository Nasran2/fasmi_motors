<?php

namespace App\Http\Controllers\Accounts;

use App\Exports\AccountLedgerExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransaction;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class AccountExportController extends Controller
{
    public function export(Request $request, string $type)
    {
        $validTypes = ['main', 'spare_parts', 'bank', 'cash'];
        if (! in_array($type, $validTypes)) {
            abort(404);
        }

        $format = $request->input('format', 'pdf');
        $from = Carbon::parse($request->input('from', today()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->input('to', today()->toDateString()))->endOfDay();

        if ($format === 'excel') {
            return Excel::download(
                new AccountLedgerExport($type, $from->toDateString(), $to->toDateString(), $request->payment_method),
                "{$type}_ledger_".now()->format('Ymd_His').'.xlsx'
            );
        }

        // PDF Generation
        $query = AccountTransaction::query()
            ->where('account_type', $type)
            ->where('status', 'active')
            ->whereBetween('transaction_date', [$from, $to])
            ->when($request->payment_method, fn (Builder $q, $method) => $q->where('payment_method', $method))
            ->when($request->transaction_type, fn (Builder $q, $value) => $q->where('transaction_type', $value))
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        $transactions = $query->get();
        $accountCode = match ($type) {
            'main','cash' => 'MAIN', 'spare_parts' => 'SPARE', default => 'BANK'
        };
        $opening = (float) (Account::where('code', $accountCode)->value('opening_balance') ?? 0)
            + (float) AccountTransaction::where('account_type', $type)->where('status', 'active')->where('transaction_date', '<', $from)->sum('credit')
            - (float) AccountTransaction::where('account_type', $type)->where('status', 'active')->where('transaction_date', '<', $from)->sum('debit');
        $totals = ['opening' => $opening, 'debit' => (float) $transactions->sum('debit'), 'credit' => (float) $transactions->sum('credit')];
        $totals['closing'] = $opening + $totals['credit'] - $totals['debit'];

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
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'printedBy' => $request->user(),
        ])->setPaper('a4', 'portrait');

        if ($request->has('print')) {
            return $pdf->stream("{$type}_ledger_".now()->format('Ymd_His').'.pdf');
        }

        return $pdf->download("{$type}_ledger_".now()->format('Ymd_His').'.pdf');
    }
}
