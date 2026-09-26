<?php

namespace App\Http\Controllers\Accounts;

use App\Exports\AccountLedgerExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Services\AccountService;
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

        $request->validate([
            'format' => ['nullable', 'in:pdf,excel'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
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
            ->ledger($type)
            ->posted()
            ->whereBetween('transaction_date', [$from, $to])
            ->when($request->payment_method, fn (Builder $q, $method) => $q->where('payment_method', $method))
            ->when($request->transaction_type, fn (Builder $q, $value) => $q->where('transaction_type', $value))
            ->orderBy('transaction_date', 'asc')
            ->orderBy('id', 'asc');

        $transactions = $query->get();
        $account = Account::systemAccount($type);
        $opening = app(AccountService::class)->ledgerOpeningBalance($type, $from);
        if ($type === 'cash') {
            $balance = $opening;
            foreach ($transactions as $transaction) {
                $balance += (float) $transaction->credit - (float) $transaction->debit;
                $transaction->running_balance = round($balance, 2);
            }
        }
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
