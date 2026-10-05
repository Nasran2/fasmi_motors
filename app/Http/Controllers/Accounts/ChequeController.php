<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\Cheque;
use App\Services\AccountService;
use App\Services\SettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChequeController extends BaseAccountController
{
    public function index(Request $request)
    {
        [$from,$to,$range] = $this->range($request);
        $base = Cheque::with(['creator', 'bankAccount'])->whereBetween('cheque_date', [$from->toDateString(), $to->toDateString()])->when($request->status, fn ($q, $v) => $q->where('status', $v))->when($request->type, fn ($q, $v) => $q->where('cheque_type', $v))->when($request->search, fn ($q, $v) => $q->where(fn ($n) => $n->where('cheque_number', 'like', "%$v%")->orWhere('party_name', 'like', "%$v%")));
        $all = (clone $base)->get();
        $cheques = (clone $base)->latest('cheque_date')->paginate(25)->withQueryString();
        $summary = ['Received' => (float) $all->where('cheque_type', 'Received')->sum('amount'), 'Issued' => (float) $all->where('cheque_type', 'Issued')->sum('amount'), 'Pending' => (float) $all->whereIn('status', ['Pending', 'Deposited'])->sum('amount'), 'Cleared' => (float) $all->where('status', 'Cleared')->sum('amount'), 'Returned' => (float) $all->where('status', 'Returned')->sum('amount')];
        $accounts = Account::where('active', true)->get();

        return view('accounts.cheques', compact('cheques', 'summary', 'accounts', 'range', 'from', 'to'));
    }

    public function store(Request $request)
    {
        $v = $request->validate(['cheque_type' => 'required|in:Received,Issued', 'party_name' => 'required|string|max:120', 'related_reference' => 'nullable|string|max:120', 'cheque_number' => 'required|string|max:80', 'bank_name' => 'required|string|max:120', 'branch' => 'nullable|string|max:120', 'cheque_date' => 'required|date', 'amount' => 'required|numeric|gt:0', 'status' => 'required|in:Pending,Deposited,Cleared', 'realized_date' => 'nullable|required_if:status,Cleared|date', 'bank_account_id' => 'nullable|exists:accounts,id', 'note' => 'nullable|string|max:1000']);
        if ($v['status'] === 'Cleared' && (Carbon::parse($v['cheque_date'])->startOfDay()->isFuture() || Carbon::parse($v['realized_date'])->lt(Carbon::parse($v['cheque_date'])))) {
            throw ValidationException::withMessages(['cheque_date' => 'A cheque can only pass on or after its cheque date.']);
        }
        DB::transaction(function () use ($v) {
            $cheque = Cheque::create($v + ['created_by' => auth()->id(), 'updated_by' => auth()->id()]);
            if ($cheque->status === 'Cleared') {
                $this->postLedger($cheque, app(AccountService::class));
            }
        });

        return back()->with('success', 'Cheque recorded successfully. Pending cheques do not affect the bank ledger.');
    }

    public function updateStatus(Request $request, Cheque $cheque, AccountService $service)
    {
        $v = $request->validate(['status' => 'required|in:Pending,Deposited,Cleared,Returned,Cancelled', 'realized_date' => 'nullable|required_if:status,Cleared|date', 'reason' => 'nullable|required_if:status,Returned|required_if:status,Cancelled|string|min:5|max:500']);
        app(SettlementService::class)->resolve($cheque, $v['status'], $v['realized_date'] ?? null, $v['reason'] ?? null);

        return back()->with('success', 'Cheque status and bank ledger updated.');
    }

    private function postLedger(Cheque $cheque, AccountService $service, $date = null): void
    {
        $service->recordTransaction('bank', $cheque->cheque_type === 'Received' ? 'income' : 'expense', (float) $cheque->amount, "{$cheque->cheque_type} cheque {$cheque->cheque_number} cleared", $cheque, 'Cheque', $cheque->note, $cheque->bank_account_id, $date ?: $cheque->realized_date ?: today(), 'cheque');
    }
}
