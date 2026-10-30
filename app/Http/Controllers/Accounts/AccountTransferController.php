<?php

namespace App\Http\Controllers\Accounts;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AccountTransfer;
use App\Services\AccountService;
use App\Services\CashierService;
use App\Services\OutgoingFundingService;
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
        $balances = collect(['main', 'spare_parts', 'bank', 'washing', 'cash'])->mapWithKeys(fn ($type) => [$type => $this->balance($type)]);

        return view('accounts.transfers', compact('transfers', 'accounts', 'balances', 'from', 'to', 'range'));
    }

    public function store(Request $request, AccountService $service)
    {
        $v = $request->validate(['from_account' => 'nullable|in:main,spare_parts,bank,washing,cash', 'to_account' => 'required|in:main,spare_parts,bank,washing,cash|different:from_account', 'amount' => 'required|numeric|gt:0', 'transfer_date' => 'required|date|before_or_equal:now', 'payment_method' => 'nullable|string|max:50', 'reference' => 'nullable|string|max:100', 'note' => 'nullable|string|max:1000'] + OutgoingFundingService::rules());
        DB::transaction(function () use ($v, $service) {
            $funding = app(OutgoingFundingService::class);
            $plan = $funding->plan($v, (float) $v['amount'], $v['from_account'] ?? 'main');
            $from = $this->account($funding->type($plan[0]['source']));
            $to = $this->account($v['to_account']);
            foreach ($plan as $row) {
                if ($this->account($funding->type($row['source']))->id === $to->id) {
                    throw ValidationException::withMessages(['to_account' => 'Choose a destination different from every selected payment source.']);
                }
            }
            app(CashierService::class)->lock();
            $transfer = AccountTransfer::create(['transfer_no' => 'AT-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'from_account_id' => $from->id, 'to_account_id' => $to->id, 'amount' => $v['amount'], 'transfer_date' => $v['transfer_date'], 'payment_method' => $v['payment_method'] ?? null, 'reference_number' => $v['reference'] ?? null, 'note' => $v['note'] ?? null, 'status' => 'Completed', 'created_by' => auth()->id()]);
            $funding->post($plan, (float) $v['amount'], "Transfer to {$to->name}", $transfer, $v['payment_method'] ?? 'Cash', $v['transfer_date'], 'transfer', $v['note'] ?? null, 'transfer_out');
            $destination = $v['to_account'] === 'cash' ? 'main' : $v['to_account'];
            $service->recordTransaction($destination, 'transfer_in', (float) $v['amount'], "Transfer from {$from->name}", $transfer, $destination === 'bank' ? 'Bank Transfer' : 'Cash', $v['note'] ?? null, $to->id, $v['transfer_date'], 'transfer', in_array($destination, ['main', 'spare_parts', 'bank']) ? $destination : null);
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
