<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Drawing;
use App\Models\Expense;
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
        DB::transaction(function () use ($v, $r, $service) {
            $e = Expense::create($v + ['vendor' => $r->vendor, 'note' => $r->note, 'created_by' => auth()->id()]);
            $service->ledger(Account::findOrFail($v['account_id']), 'expense', (float) $v['amount'], "{$v['category']} expense", $e, $v['category']);
        });

        return back()->with('success', 'Expense recorded in the ledger.');
    }

    public function drawing(Request $r, WorkshopService $service)
    {
        $v = $r->validate(['drawing_date' => 'required|date', 'person_name' => 'required|max:100', 'drawing_type' => 'required|max:100', 'account_id' => 'required|exists:accounts,id', 'amount' => 'required|numeric|min:0.01']);
        DB::transaction(function () use ($v, $r, $service) {
            $d = Drawing::create($v + ['note' => $r->note, 'created_by' => auth()->id()]);
            $service->ledger(Account::findOrFail($v['account_id']), 'drawing', (float) $v['amount'], "Drawing by {$v['person_name']}", $d, 'drawing');
        });

        return back()->with('success', 'Drawing recorded in the ledger.');
    }

    public function transfer(Request $r, WorkshopService $service)
    {
        $v = $r->validate(['from_account_id' => ['required', 'exists:accounts,id', Rule::notIn([$r->to_account_id])], 'to_account_id' => 'required|exists:accounts,id', 'amount' => 'required|numeric|min:0.01', 'transfer_date' => 'required|date']);
        DB::transaction(function () use ($v, $r, $service) {
            $id = DB::table('account_transfers')->insertGetId($v + ['reference_number' => $r->reference_number, 'note' => $r->note, 'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now()]);
            $service->ledger(Account::findOrFail($v['from_account_id']), 'transfer_out', (float) $v['amount'], "Transfer #{$id} out", null, 'transfer');
            $service->ledger(Account::findOrFail($v['to_account_id']), 'transfer_in', (float) $v['amount'], "Transfer #{$id} in", null, 'transfer');
        });

        return back()->with('success','Account transfer completed.');
    }
}
