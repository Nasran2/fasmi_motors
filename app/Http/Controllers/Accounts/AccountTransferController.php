<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Services\AccountService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountTransferController extends BaseAccountController
{
    public function index(Request $request)
    {
        [$from,$to,$range] = $this->range($request);
        $transfers = AccountTransfer::with(['fromAccount', 'toAccount', 'creator'])->whereBetween('transfer_date', [$from, $to])
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))->latest('transfer_date')->paginate(25)->withQueryString();
        $accounts = Account::where('active', true)->orderBy('name')->get();
        $balances = collect(['main', 'spare_parts', 'bank', 'cash'])->mapWithKeys(fn ($type) => [$type => $this->balance($type)]);

        return view('accounts.transfers', compact('transfers', 'accounts', 'balances', 'from', 'to', 'range'));
    }

    public function store(Request $request, AccountService $service)
    {
        $v = $request->validate(['from_account' => 'required|in:main,spare_parts,bank,cash', 'to_account' => 'required|in:main,spare_parts,bank,cash|different:from_account', 'amount' => 'required|numeric|gt:0', 'transfer_date' => 'required|date', 'payment_method' => 'nullable|string|max:50', 'reference' => 'nullable|string|max:100', 'note' => 'nullable|string|max:1000']);
        DB::transaction(function () use ($v, $service) {
            $from = $this->account($v['from_account']);
            $to = $this->account($v['to_account']);
            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to_account' => 'These selections refer to the same account. Cash Book is a reporting view, not a separate transfer destination.']);
            }
            Account::whereIn('id', [$from->id, $to->id])->orderBy('id')->lockForUpdate()->get();
            if ($this->balance($v['from_account']) < (float) $v['amount']) {
                throw ValidationException::withMessages(['amount' => 'The source account does not have enough available balance.']);
            }
            $transfer = AccountTransfer::create(['transfer_no' => 'AT-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => $v['amount'], 'transfer_date' => $v['transfer_date'], 'payment_method' => $v['payment_method'] ?? null, 'reference_number' => $v['reference'] ?? null, 'note' => $v['note'] ?? null, 'status' => 'Completed', 'created_by' => auth()->id()]);
            $service->recordTransaction($v['from_account'], 'transfer_out', $v['amount'], "Transfer to {$to->name}", $transfer, $v['payment_method'] ?? null, $v['note'] ?? null, $from->id, $v['transfer_date'], 'transfer');
            $service->recordTransaction($v['to_account'], 'transfer_in', $v['amount'], "Transfer from {$from->name}", $transfer, $v['payment_method'] ?? null, $v['note'] ?? null, $to->id, $v['transfer_date'], 'transfer');
        });

        return back()->with('success', 'Account transfer completed and both ledgers were updated.');
    }

    public function reverse(Request $request, AccountTransfer $transfer, AccountService $service)
    {
        $v = $request->validate(['reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($transfer, $service, $v) {
            $transfer = AccountTransfer::lockForUpdate()->findOrFail($transfer->id);
            if ($transfer->status !== 'Completed') {
                return;
            }
            AccountTransaction::where('reference_type', $transfer->getMorphClass())->where('reference_id', $transfer->id)->where('status', 'active')->get()->each(fn ($t) => $service->reverseTransaction($t, $v['reason']));
            $transfer->update(['status' => 'Cancelled', 'cancel_reason' => $v['reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        });

        return back()->with('success', 'Transfer cancelled with compensating ledger entries.');
    }

    private function account(string $type): Account
    {
        return Account::systemAccount($type) ?? abort(404);
    }

    private function balance(string $type): float
    {
        $account = $this->account($type);

        return app(AccountService::class)->getRunningBalance($type);
    }
}
