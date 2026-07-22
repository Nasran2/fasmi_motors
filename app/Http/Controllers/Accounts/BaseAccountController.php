<?php

namespace App\Http\Controllers\Accounts;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class BaseAccountController extends Controller
{
    protected function ledgerData(Request $request, string $accountType): array
    {
        [$from, $to, $range] = $this->range($request);
        $account = Account::systemAccount($accountType);
        $base = AccountTransaction::with(['creator', 'account', 'reference'])->where('account_type', $accountType)
            ->where('account_id', $account?->id)
            ->whereBetween('transaction_date', [$from, $to])->where('status', 'active')
            ->when($request->transaction_type, fn (Builder $q, string $type) => $q->where('transaction_type', $type))
            ->when($request->payment_method, fn (Builder $q, string $method) => $q->where('payment_method', $method))
            ->when($request->created_by, fn (Builder $q, string $user) => $q->where('created_by', $user))
            ->when($request->search, fn (Builder $q, string $term) => $q->where(fn (Builder $nested) => $nested->where('transaction_no', 'like', "%{$term}%")->orWhere('description', 'like', "%{$term}%")));
        $period = (clone $base)->orderBy('transaction_date')->orderBy('id')->get();
        $opening = (float) ($account?->opening_balance ?? 0) + (float) AccountTransaction::where('account_type', $accountType)->where('account_id', $account?->id)->where('status', 'active')->where('transaction_date', '<', $from)->sum('credit') - (float) AccountTransaction::where('account_type', $accountType)->where('account_id', $account?->id)->where('status', 'active')->where('transaction_date', '<', $from)->sum('debit');
        $credit = (float) $period->sum('credit');
        $debit = (float) $period->sum('debit');

        return ['transactions' => (clone $base)->latest('transaction_date')->latest('id')->paginate(25)->withQueryString(), 'periodTransactions' => $period, 'account' => $account, 'users' => User::orderBy('name')->get(['id', 'name']), 'from' => $from, 'to' => $to, 'range' => $range, 'totals' => ['opening' => $opening, 'debit' => $debit, 'credit' => $credit, 'income' => (float) $period->whereIn('transaction_type', ['income', 'payment_received'])->sum('credit'), 'expense' => (float) $period->whereIn('transaction_type', ['expense', 'drawing', 'purchase_payment', 'supplier_payment'])->sum('debit'), 'transfer_in' => (float) $period->where('transaction_type', 'transfer_in')->sum('credit'), 'transfer_out' => (float) $period->where('transaction_type', 'transfer_out')->sum('debit'), 'closing' => $opening + $credit - $debit]];
    }

    protected function range(Request $request): array
    {
        $request->validate([
            'date_range' => ['nullable', 'in:today,yesterday,this_week,last_week,this_month,last_month,this_year,last_year,custom'],
            'start_date' => ['nullable', 'required_if:date_range,custom', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'required_if:date_range,custom', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $range = $request->date_range ?: 'today';
        [$from,$to] = match ($range) {
            'yesterday' => [today()->subDay()->startOfDay(), today()->subDay()->endOfDay()],
            'this_week' => [now()->startOfWeek(), now()->endOfWeek()], 'last_week' => [now()->subWeek()->startOfWeek(), now()->subWeek()->endOfWeek()],
            'this_month' => [now()->startOfMonth(), now()->endOfMonth()], 'last_month' => [now()->subMonth()->startOfMonth(), now()->subMonth()->endOfMonth()],
            'this_year' => [now()->startOfYear(), now()->endOfYear()], 'last_year' => [now()->subYear()->startOfYear(), now()->subYear()->endOfYear()],
            'custom' => [Carbon::parse($request->start_date ?: today())->startOfDay(), Carbon::parse($request->end_date ?: today())->endOfDay()],
            default => [today()->startOfDay(), today()->endOfDay()],
        };
        $request->merge(['date_range' => $range, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return [$from, $to, $range];
    }
}
