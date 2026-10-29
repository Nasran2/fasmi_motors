<?php

namespace App\Http\Controllers\Accounts;

use App\Exports\DailyClosingExport;
use App\Http\Controllers\CashierController;
use App\Models\Account;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\CashPositionService;
use App\Support\PrintDocument;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class DailyClosingController extends BaseAccountController
{
    public function index(Request $request)
    {
        return app(CashierController::class)->index($request, app(CashierService::class), app(AccountService::class));
    }

    public function store(Request $request)
    {
        return redirect()->route('cashier.index')->with('error', 'Review the cashier balances and use Confirm & Close Cashier to close the day.');
    }

    public function reopen(Request $request, DailyClosing $closing)
    {
        return app(CashierController::class)->reopen($request, CashierSession::whereDate('session_date', $closing->closing_date)->firstOrFail(), app(CashierService::class));
    }

    public function adjustment(Request $request, AccountService $service)
    {
        $v = $request->validate(['account_type' => 'required|in:main,spare_parts,bank,washing,cash', 'action' => 'required|in:deduction,increase,decrease', 'amount' => 'required|numeric|gt:0', 'date' => 'required|date|before_or_equal:today', 'reason' => 'required|string|min:5|max:1000', 'proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $account = Account::systemAccount($v['account_type']);
        $path = $request->file('proof')?->store('account-proofs', 'public');
        $transactionType = match ($v['action']) {
            'increase' => 'adjustment_increase','decrease' => 'adjustment_decrease',default => 'expense'
        };
        $service->recordTransaction($v['account_type'], $transactionType, (float) $v['amount'], ($v['action'] === 'deduction' ? 'Daily closing deduction: ' : 'Daily closing adjustment: ').$v['reason'], null, 'Manual', $path ? "Proof: {$path}" : null, $account?->id, $v['date'], $v['action'] === 'deduction' ? 'closing_deduction' : 'closing_adjustment');

        return redirect()->route('accounts.daily-closing.index', ['date' => $v['date']])->with('success', ucfirst($v['action']).' saved. The balance and audit trail were updated.');
    }

    public function export(Request $request)
    {
        $date = $request->input('date', today()->toDateString());
        $request->validate(['date' => 'nullable|date_format:Y-m-d|before_or_equal:today']);
        $closing = DailyClosing::whereDate('closing_date', $date)->first();
        $summary = app(CashierService::class)->summary($date);
        $cashier = CashierSession::whereDate('session_date', $date)->first();
        if (! $closing || $cashier?->cash_balances) {
            $snapshot = $cashier?->status === 'closed' ? $cashier->closing_summary : null;
            $cash = $snapshot['cash_accounts'] ?? $summary['cash_accounts'];
            $withdrawal = (float) ($snapshot['closing_withdrawal'] ?? 0);
            $washingTransfer = (float) ($snapshot['closing_washing_transfer'] ?? 0);
            $rows = collect(CashPositionService::ACCOUNTS)->map(function ($label, $type) use ($cash, $cashier, $withdrawal, $washingTransfer) {
                $account = $cash[$type];
                $sweepChange = match ($type) {
                    'main' => $washingTransfer, 'washing' => -$washingTransfer, default => 0
                };
                $expected = $account['closing'] - ($type === 'spare_parts' ? $withdrawal : 0) + $sweepChange;
                $actual = $cashier?->status === 'closed' ? ($cashier->cash_balances[$type] ?? null) : $expected;

                return [$label.' (Cash)', $account['opening'], $account['income'] + $account['other_in'] + max(0, $sweepChange),
                    $account['outgoing'] + ($type === 'spare_parts' ? $withdrawal : 0) + max(0, -$sweepChange), $expected, $actual, $actual === null ? null : $actual - $expected];
            })->values()->all();
            $bank = $snapshot['accounts']['bank'] ?? $summary['accounts']['bank'];
            $actualBank = $cashier?->status === 'closed'
                ? ($snapshot['closing_bank_balance'] ?? $cashier->actual_balances['bank'] ?? null)
                : $bank['closing'];
            $rows[] = ['Bank Account (Non-cash)', $bank['opening'], $bank['income'], $bank['outgoing'], $bank['closing'], $actualBank,
                $actualBank === null ? null : round($actualBank - $bank['closing'], 2)];
        } else {
            $rows = collect(CashierService::ACCOUNTS)->map(function ($label, $type) use ($summary, $closing) {
                $account = $summary['accounts'][$type];
                $expected = $closing ? (float) $closing->{$type.'_closing_balance'} : $account['closing'];
                $actual = $closing ? ($closing->{$type.'_actual_balance'} === null ? null : (float) $closing->{$type.'_actual_balance'}) : $expected;

                return [$label, $closing ? (float) $closing->{$type.'_opening_balance'} : $account['opening'],
                    $closing ? (float) $closing->{$type.'_income'} : $account['income'],
                    $closing ? (float) $closing->{$type.'_expense'} : $account['outgoing'], $expected, $actual, $actual === null ? null : $actual - $expected];
            })->values()->all();
        }
        if ($request->input('format') === 'excel') {
            return Excel::download(new DailyClosingExport($rows), "daily_closing_{$date}.xlsx");
        }
        $pdf = PrintDocument::pdf('accounts.exports.daily-closing-pdf', compact('date', 'rows', 'closing') + ['printedBy' => $request->user()]);

        return $request->boolean('print') ? $pdf->stream("daily_closing_{$date}.pdf") : $pdf->download("daily_closing_{$date}.pdf");
    }
}
