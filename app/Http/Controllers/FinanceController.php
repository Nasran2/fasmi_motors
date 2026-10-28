<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Accounts\AccountTransferController;
use App\Models\Account;
use App\Models\Drawing;
use App\Models\Expense;
use App\Services\AccountService;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    public function index(Request $r)
    {
        $accounts = Account::all();
        $active = $r->account ? Account::where('code', $r->account)->firstOrFail() : $accounts->first();
        $q = $active->transactions()->latest('transaction_date');
        if ($r->from) {
            $q->whereDate('transaction_date', '>=', $r->from);
        }if ($r->to) {
            $q->whereDate('transaction_date', '<=', $r->to);
        }

        return view('finance.index', ['accounts' => $accounts, 'active' => $active, 'transactions' => $q->paginate(25), 'expenses' => Expense::with('account')->latest('expense_date')->limit(8)->get(), 'drawings' => Drawing::with('account')->latest('drawing_date')->limit(8)->get()]);
    }

    public function expense(Request $r, WorkshopService $service)
    {
        $v = $r->validate(['expense_date' => 'required|date', 'category' => 'required|max:100', 'account_id' => 'required|exists:accounts,id', 'amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|max:50']);
        DB::transaction(function () use ($v, $r) {
            $e = Expense::create($v + ['vendor' => $r->vendor, 'note' => $r->note, 'created_by' => auth()->id()]);

            $accountService = app(AccountService::class);
            $account = Account::where('active', true)->findOrFail($v['account_id']);
            $type = collect(['main', 'spare_parts', 'bank', 'washing'])->first(fn ($type) => $account->id === Account::systemAccount($type)?->id);
            abort_unless($type, 422, 'Select a configured system account.');
            $accountService->outgoing($type, (float) $v['amount'], "{$v['category']} expense", $e, $v['payment_method'], $v['expense_date'], $v['category'], $type !== 'bank');
        });

        return back()->with('success', 'Expense recorded in the ledger.');
    }

    public function drawing(Request $r, WorkshopService $service)
    {
        $v = $r->validate(['drawing_date' => 'required|date', 'person_name' => 'required|max:100', 'drawing_type' => 'required|max:100', 'account_id' => 'required|exists:accounts,id', 'amount' => 'required|numeric|min:0.01']);
        DB::transaction(function () use ($v, $r) {
            $d = Drawing::create($v + ['note' => $r->note, 'created_by' => auth()->id()]);

            $accountService = app(AccountService::class);
            // Defaulting to cash for drawing if no method is specified in form, or bank if it's a bank account. We assume Cash here unless it's known to be bank.
            $accountService->outgoing('spare_parts', (float) $v['amount'], "Drawing by {$v['person_name']}", $d, 'Cash', $v['drawing_date'], 'drawing');
        });

        return back()->with('success', 'Drawing recorded in the ledger.');
    }

    public function transfer(Request $r, WorkshopService $service)
    {
        $v = $r->validate(['from_account_id' => ['required', 'exists:accounts,id', Rule::notIn([$r->to_account_id])], 'to_account_id' => 'required|exists:accounts,id', 'amount' => 'required|numeric|min:0.01', 'transfer_date' => 'required|date']);
        $types = collect(['main', 'spare_parts', 'bank', 'washing', 'cash'])->mapWithKeys(fn ($type) => [Account::systemAccount($type)?->id => $type]);
        abort_unless($types->has($v['from_account_id']) && $types->has($v['to_account_id']), 422, 'Use the configured system accounts.');
        $r->merge(['from_account' => $types[$v['from_account_id']], 'to_account' => $types[$v['to_account_id']], 'reference' => $r->reference_number]);

        return app(AccountTransferController::class)->store($r, app(AccountService::class));
    }
}
