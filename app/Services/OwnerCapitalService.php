<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\OwnerCapital;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OwnerCapitalService
{
    public const ACCOUNTS = ['spare_parts' => 'Spare Parts Account', 'main' => 'Main Account', 'bank' => 'Bank Account'];

    public function __construct(private readonly AccountService $ledger) {}

    public function save(array $values, ?OwnerCapital $capital = null): OwnerCapital
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($values, $capital) {
            app(CashierService::class)->lock();
            $account = Account::whereKey($values['account_id'])->where('active', true)->firstOrFail();
            $type = $this->type($account);
            if ($type !== 'bank' && $values['payment_method'] !== 'Cash') {
                throw ValidationException::withMessages(['payment_method' => 'Select Bank Account for a bank transfer. Main and Spare Parts receive cash.']);
            }
            $before = null;
            if ($capital) {
                $capital = OwnerCapital::lockForUpdate()->findOrFail($capital->id);
                $this->checkVersion($capital, $values['record_version']);
                $before = $capital->getAttributes();
                $this->reverse($capital, $values['reason']);
            }
            $data = collect($values)->only(['capital_date', 'owner_name', 'account_id', 'amount', 'payment_method', 'reference_number', 'note'])->all();
            if ($capital) {
                $capital->update($data + ['change_reason' => $values['reason'], 'updated_by' => auth()->id()]);
            } else {
                $capital = OwnerCapital::create($data + ['number' => 'CAP-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'created_by' => auth()->id()]);
            }
            // Capital funds the account directly. It is neither a sale nor today's cashier receipts.
            $this->ledger->recordTransaction($type, 'owner_capital', (float) $capital->amount,
                'Owner capital by '.$capital->owner_name.' · '.$capital->number, $capital,
                $capital->payment_method, $capital->note, transactionDate: $capital->capital_date,
                category: 'owner_capital', fundingSource: $type);
            $this->audit($capital, $before ? 'Owner Capital Updated' : 'Owner Capital Added', $before);

            return $capital;
        }, 3);
    }

    public function delete(OwnerCapital $capital, string $reason, string $version): void
    {
        DB::transaction(function () use ($capital, $reason, $version) {
            app(CashierService::class)->lock();
            $capital = OwnerCapital::lockForUpdate()->findOrFail($capital->id);
            $this->checkVersion($capital, $version);
            $before = $capital->getAttributes();
            $this->reverse($capital, $reason);
            $capital->update(['change_reason' => $reason, 'deleted_by' => auth()->id()]);
            $capital->delete();
            $this->audit($capital, 'Owner Capital Deleted', $before);
        }, 3);
    }

    private function reverse(OwnerCapital $capital, string $reason): void
    {
        $entries = AccountTransaction::where('reference_type', $capital->getMorphClass())
            ->where('reference_id', $capital->id)->where('transaction_type', 'owner_capital')->where('status', 'active')->lockForUpdate()->get();
        if ($entries->count() !== 1) {
            throw ValidationException::withMessages(['reason' => 'The original capital posting changed. Review its ledger before editing or deleting.']);
        }
        foreach ($entries as $entry) {
            // Correct the original effective date while preserving the old posting and audit history.
            $this->ledger->reverseTransaction($entry, $reason, $entry->transaction_date);
        }
    }

    private function type(Account $account): string
    {
        foreach (array_keys(self::ACCOUNTS) as $type) {
            if ($account->code === Account::systemCode($type)) {
                return $type;
            }
        }
        throw ValidationException::withMessages(['account_id' => 'Select an active Main, Spare Parts or Bank account for this shop.']);
    }

    private function checkVersion(OwnerCapital $capital, string $version): void
    {
        if (! hash_equals($capital->version(), $version)) {
            throw ValidationException::withMessages(['record_version' => 'This capital entry changed. Reload it before saving.']);
        }
    }

    private function audit(OwnerCapital $capital, string $event, ?array $before): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'event' => $event, 'auditable_type' => $capital->getMorphClass(),
            'auditable_id' => $capital->id, 'old_values' => $before, 'new_values' => $capital->getAttributes(),
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }
}
