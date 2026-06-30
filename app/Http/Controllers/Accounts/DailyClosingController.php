<?php

namespace App\Http\Controllers\Accounts;

use App\Exports\DailyClosingExport;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\DailyClosing;
use App\Models\Invoice;
use App\Services\AccountService;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class DailyClosingController extends BaseAccountController
{
    public function index(Request $request)
    {
        $date = $request->input('date', today()->toDateString());
        $expected = collect(['main', 'spare_parts', 'bank', 'cash'])->mapWithKeys(fn ($type) => [$type => $this->calculate($type, $date)])->all();
        $closing = DailyClosing::whereDate('closing_date', $date)->first();
        $closings = DailyClosing::with('closer')->whereDate('closing_date', $date)->latest()->paginate(20)->withQueryString();
        $bills = Invoice::with(['customer', 'bike', 'jobCard.intake.bike'])->whereDate('created_at', $date)->where('status', '!=', 'Cancelled')->orderByDesc('created_at')->get();
        $billGroups = collect([
            'Job Income' => $bills->where('invoice_type', 'Job Card Bill'),
            'Spare Parts Sales' => $bills->where('invoice_type', 'Parts Sale'),
            'Other Sales / Service' => $bills->whereNotIn('invoice_type', ['Job Card Bill', 'Parts Sale']),
        ]);
        $summary = ['job_income' => (float) $billGroups['Job Income']->sum('total'), 'parts_sales' => (float) $billGroups['Spare Parts Sales']->sum('total'), 'other_income' => (float) $billGroups['Other Sales / Service']->sum('total'), 'expenses' => collect($expected)->sum('expenses_only'), 'drawings' => collect($expected)->sum('drawings'), 'transfer_in' => collect($expected)->sum('transfer_in'), 'transfer_out' => collect($expected)->sum('transfer_out'), 'deductions' => collect($expected)->sum('deductions'), 'adjustments' => collect($expected)->sum('adjustments'), 'closing' => collect($expected)->sum('closing'), 'bill_count' => $bills->count()];

        return view('accounts.daily-closing', compact('date', 'expected', 'closing', 'closings', 'bills', 'billGroups', 'summary'));
    }

    public function store(Request $request)
    {
        $v = $request->validate(['closing_date' => 'required|date|before_or_equal:today', 'main_actual' => 'required|numeric', 'spare_parts_actual' => 'required|numeric', 'bank_actual' => 'required|numeric', 'cash_actual' => 'required|numeric', 'difference_reason' => 'nullable|string|max:1000', 'notes' => 'nullable|string|max:1000']);
        $expectedDifferences = collect(['main', 'spare_parts', 'bank', 'cash'])->sum(fn ($type) => abs((float) $v["{$type}_actual"] - $this->calculate($type, $v['closing_date'])['closing']));
        if ($expectedDifferences > 0.009 && blank($v['difference_reason'] ?? null)) {
            throw ValidationException::withMessages(['difference_reason' => 'Explain the difference before closing the day.']);
        }
        $existing = DailyClosing::whereDate('closing_date', $v['closing_date'])->first();
        abort_if($existing && $existing->status === 'closed', 422, 'This date is already closed. Reopen it before closing again.');
        DB::transaction(function () use ($v, $existing) {
            $data = ['closing_date' => $v['closing_date'], 'closed_by' => auth()->id(), 'status' => 'closed', 'notes' => $v['notes'] ?? null, 'difference_reason' => $v['difference_reason'] ?? null, 'reopen_reason' => null, 'reopened_by' => null, 'reopened_at' => null];
            $totalDifference = 0;
            foreach (['main', 'spare_parts', 'bank', 'cash'] as $type) {
                $e = $this->calculate($type, $v['closing_date']);
                $actual = (float) $v["{$type}_actual"];
                $difference = $actual - $e['closing'];
                $totalDifference += $difference;
                $data["{$type}_opening_balance"] = $e['opening'];
                $data["{$type}_income"] = $e['income'];
                $data["{$type}_expense"] = $e['expense'];
                $data["{$type}_closing_balance"] = $e['closing'];
                $data["{$type}_actual_balance"] = $actual;
                $data["{$type}_difference"] = $difference;
            }$data['difference'] = $totalDifference;
            ($existing?->update($data)) ?? DailyClosing::create($data);
        });

        return redirect()->route('accounts.daily-closing.index', ['date' => $v['closing_date']])->with('success', 'Daily closing saved with expected and actual balances.');
    }

    public function reopen(Request $request, DailyClosing $closing)
    {
        $v = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $closing->update(['status' => 'reopened', 'reopen_reason' => $v['reason'], 'reopened_by' => auth()->id(), 'reopened_at' => now()]);

        return back()->with('success', 'Daily closing reopened. The reason remains in the audit trail.');
    }

    public function adjustment(Request $request, AccountService $service)
    {
        $v = $request->validate(['account_type' => 'required|in:main,spare_parts,bank,cash', 'action' => 'required|in:deduction,increase,decrease', 'amount' => 'required|numeric|gt:0', 'date' => 'required|date|before_or_equal:today', 'reason' => 'required|string|min:5|max:1000', 'proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120']);
        $account = Account::where('code', match ($v['account_type']) {
            'spare_parts' => 'SPARE','bank' => 'BANK',default => 'MAIN'
        })->first();
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
        $closing = DailyClosing::whereDate('closing_date', $date)->first();
        $rows = collect(['main' => 'Main Account', 'spare_parts' => 'Spare Parts', 'bank' => 'Bank Account', 'cash' => 'Cash Book'])->map(function ($label, $type) use ($date, $closing) {
            $expected = $this->calculate($type, $date);
            $actual = (float) ($closing?->{"{$type}_actual_balance"} ?? $expected['closing']);

            return [$label, $expected['opening'], $expected['income'], $expected['expense'], $expected['closing'], $actual, $actual - $expected['closing']];
        })->values()->all();
        if ($request->input('format') === 'excel') {
            return Excel::download(new DailyClosingExport($rows), "daily_closing_{$date}.xlsx");
        }
        $pdf = Pdf::loadView('accounts.exports.daily-closing-pdf', compact('date', 'rows', 'closing') + ['printedBy' => $request->user()]);

        return $request->boolean('print') ? $pdf->stream("daily_closing_{$date}.pdf") : $pdf->download("daily_closing_{$date}.pdf");
    }

    private function calculate(string $type, string $date): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $end = $start->copy()->endOfDay();
        $code = match ($type) {
            'spare_parts' => 'SPARE','bank' => 'BANK',default => 'MAIN'
        };
        $base = (float) (Account::where('code', $code)->value('opening_balance') ?? 0);
        $prior = AccountTransaction::where('account_type', $type)->where('status', 'active')->where('transaction_date', '<', $start);
        $opening = $base + (float) (clone $prior)->sum('credit') - (float) (clone $prior)->sum('debit');
        $period = AccountTransaction::where('account_type', $type)->where('status', 'active')->whereBetween('transaction_date', [$start, $end])->get();
        $income = (float) $period->sum('credit');
        $expense = (float) $period->sum('debit');
        $drawings = (float) $period->where('transaction_type', 'drawing')->sum('debit');
        $transferIn = (float) $period->where('transaction_type', 'transfer_in')->sum('credit');
        $transferOut = (float) $period->where('transaction_type', 'transfer_out')->sum('debit');
        $deductions = (float) $period->where('category', 'closing_deduction')->sum('debit');
        $increase = (float) $period->where('category', 'closing_adjustment')->sum('credit');
        $decrease = (float) $period->where('category', 'closing_adjustment')->sum('debit');

        return compact('opening', 'income', 'expense', 'drawings', 'deductions') + ['expenses_only' => (float) $period->where('transaction_type', 'expense')->where('category', '!=', 'closing_deduction')->sum('debit'), 'transfer_in' => $transferIn, 'transfer_out' => $transferOut, 'adjustments' => $increase - $decrease, 'closing' => $opening + $income - $expense, 'count' => $period->count(), 'transactions' => $period];
    }
}
