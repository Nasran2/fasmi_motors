<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        DB::transaction(function () use ($v, $cheque, $service) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $was = $cheque->status;
            abort_if($was === 'Cleared' && in_array($v['status'], ['Pending', 'Deposited'], true), 422, 'Reverse or return a cleared cheque before recording a new settlement.');
            if ($v['status'] === 'Cleared' && $was !== 'Cleared') {
                $this->postLedger($cheque, $service, $v['realized_date'] ?? today());
            }if (in_array($v['status'], ['Returned', 'Cancelled']) && $was === 'Cleared') {
                AccountTransaction::where('reference_type', $cheque->getMorphClass())->where('reference_id', $cheque->id)->where('status', 'active')->get()->each(fn ($t) => $service->reverseTransaction($t, $v['reason']));
            }$cheque->update(['status' => $v['status'], 'realized_date' => $v['realized_date'] ?? $cheque->realized_date, 'status_reason' => $v['reason'] ?? null, 'updated_by' => auth()->id()]);
        });

        return back()->with('success', 'Cheque status and bank ledger updated.');
    }

    private function postLedger(Cheque $cheque, AccountService $service, $date = null): void
    {
        $service->recordTransaction('bank', $cheque->cheque_type === 'Received' ? 'income' : 'expense', (float) $cheque->amount, "{$cheque->cheque_type} cheque {$cheque->cheque_number} cleared", $cheque, 'Cheque', $cheque->note, $cheque->bank_account_id, $date ?: $cheque->realized_date ?: today(), 'cheque');
    }
}
