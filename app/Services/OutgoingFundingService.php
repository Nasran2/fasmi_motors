<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\SupplierPayment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Five spendable pools backed by the existing three ledgers. */
class OutgoingFundingService
{
    public const SOURCES = [
        'main' => 'Main Account — opening balance',
        'main_cashier' => 'Main Account Cashier — today’s cash',
        'spare_parts' => 'Spare Parts Account — opening balance',
        'spare_parts_cashier' => 'Spare Parts Account Cashier — today’s cash',
        'bank' => 'Bank Account',
    ];

    public function type(string $source): string
    {
        return str_replace('_cashier', '', $source);
    }

    public function defaultSource(?int $accountId, string $method = 'Cash', string $fallback = 'main_cashier'): string
    {
        if ($method !== 'Cash') {
            return 'bank';
        }
        foreach (['main', 'spare_parts', 'bank'] as $type) {
            if ($accountId === Account::systemAccount($type)->id) {
                return $type;
            }
        }

        return $fallback;
    }

    public function options(): array
    {
        $open = CashierSession::isOpenToday();
        $balances = $this->balances(today()->toDateString());

        return collect(self::SOURCES)->map(fn ($label, $source) => [
            'value' => $source, 'label' => $label, 'account_id' => Account::systemAccount($this->type($source))->id,
            'balance' => $balances[$source], 'disabled' => str_ends_with($source, '_cashier') && ! $open,
        ])->values()->all();
    }

    public function balances(string $date, bool $lock = false): array
    {
        $accounts = collect(['main', 'spare_parts', 'bank'])->mapWithKeys(function ($type) use ($lock) {
            $query = Account::whereKey(Account::systemAccount($type)->id);

            return [$type => ($lock ? $query->lockForUpdate() : $query)->firstOrFail()];
        });
        $query = AccountTransaction::posted()->whereIn('account_id', $accounts->pluck('id'))->orderBy('account_id')->orderBy('id');
        // Current reads after the shared account locks prevent two payouts using the same funds.
        $all = ($lock ? $query->lockForUpdate() : $query)->get();
        $start = Carbon::parse($date)->startOfDay();
        $end = $start->copy()->endOfDay();
        $result = [];
        foreach (['main', 'spare_parts', 'bank'] as $type) {
            $account = $accounts[$type];
            $entries = $all->where('account_id', $account->id);
            if ($type === 'bank') {
                $result['bank'] = round((float) $account->opening_balance + $entries->sum('credit') - $entries->sum('debit'), 2);

                continue;
            }
            $prior = $entries->filter(fn ($entry) => $entry->transaction_date->lt($start));
            $today = $entries->filter(fn ($entry) => $entry->transaction_date->betweenIncluded($start, $end));
            $openingCorrections = $today->whereIn('category', ['cashier_opening', 'cashier_account_opening']);
            $spent = $today->where('funding_source', $type)->whereNotIn('category', ['cashier_opening', 'cashier_account_opening']);
            $result[$type] = round((float) $account->opening_balance + $prior->sum('credit') - $prior->sum('debit')
                + $openingCorrections->sum('credit') - $openingCorrections->sum('debit') + $spent->sum('credit') - $spent->sum('debit'), 2);
            $result[$type.'_cashier'] = $this->cashierAmount($today, $type);
            $ledgerBalance = round((float) $account->opening_balance + $entries->sum('credit') - $entries->sum('debit'), 2);
            $result[$type] = min($result[$type], $ledgerBalance);
            $result[$type.'_cashier'] = min($result[$type.'_cashier'], $ledgerBalance);
        }
        $reserved = SupplierPayment::where('status', 'Hold')->where('funding_source', 'bank')->where('account_id', $accounts['bank']->id);
        $reserved = ($lock ? $reserved->lockForUpdate() : $reserved)->get()->sum('amount');
        $result['bank'] = round($result['bank'] - $reserved, 2);

        return $result;
    }

    /** Net received cash and cash outgoings for one day's account, without opening funds. */
    public function cashierAmount(Collection $entries, string $type): float
    {
        $cash = $entries->filter(fn ($entry) => ($entry->funding_source === $type.'_cashier'
                || ($entry->funding_source === null && ($entry->payment_method === 'Cash' || $entry->account_type === 'cash')))
            && ! in_array($entry->category, ['opening_balance', 'cashier_opening', 'cashier_cash_reconciliation', 'cashier_opening_rebase', 'cashier_account_opening', 'cashier_account_reconciliation'], true));

        return round($cash->sum('credit') - $cash->sum('debit'), 2);
    }

    public static function rules(): array
    {
        return ['funding' => 'nullable|array|min:1|max:5', 'funding.*' => 'required|array:source,amount',
            'funding.*.source' => ['required', Rule::in(array_keys(self::SOURCES))],
            'funding.*.amount' => 'required|numeric|decimal:0,2|min:0|max:999999999999.99'];
    }

    public function plan(array $input, float $amount, string $default = 'main'): array
    {
        $rows = $input['funding'] ?? [['source' => $input['funding_source'] ?? $default, 'amount' => $amount]];
        $validated = Validator::make(['funding' => $rows], self::rules())->validate();
        $rows = array_values(array_filter($validated['funding'], fn ($row) => (float) $row['amount'] > 0));
        $total = array_sum(array_map(fn ($row) => (int) round((float) $row['amount'] * 100), $rows));
        if ($total !== (int) round($amount * 100)) {
            throw ValidationException::withMessages(['funding' => 'The payment source amounts must add up to the total payment.']);
        }

        return array_map(fn ($row) => ['source' => $row['source'], 'amount' => number_format((float) $row['amount'], 2, '.', '')], $rows);
    }

    public function post(array $rows, float $amount, string $description, Model $reference, string $method = 'Cash', $date = null, ?string $category = null, ?string $note = null, string $transactionType = 'expense'): void
    {
        $rows = $this->plan(['funding' => $rows], $amount);
        $date = Carbon::parse($date ?? now());
        DB::transaction(function () use ($rows, $description, $reference, $method, $date, $category, $note, $transactionType) {
            app(CashierService::class)->lock();
            $session = CashierSession::whereDate('session_date', today())->lockForUpdate()->first();
            foreach ($rows as $row) {
                $source = $row['source'];
                if (str_ends_with($source, '_cashier') && (! $date->isToday() || $session?->status !== 'open')) {
                    throw ValidationException::withMessages(['funding' => 'Cashier sources are available only for today while the cashier is open.']);
                }
                if ($source !== 'bank' && $method !== 'Cash') {
                    throw ValidationException::withMessages(['funding' => 'Use Cash for Main/Spare Parts sources. Select Bank Account for bank, card or cheque payments.']);
                }
                $available = $this->balances($date->toDateString(), true)[$source];
                if ((int) round($row['amount'] * 100) > (int) round($available * 100)) {
                    throw ValidationException::withMessages(['funding' => self::SOURCES[$source].' has only Rs. '.number_format(max(0, $available), 2).' available.']);
                }
                app(AccountService::class)->recordTransaction($this->type($source), $transactionType, (float) $row['amount'], $description, $reference,
                    $source === 'bank' && $method === 'Cash' ? 'Bank Transfer' : $method, $note, transactionDate: $date, category: $category, fundingSource: $source);
            }
        });
    }
}
