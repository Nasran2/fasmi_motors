<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\CashierSession;
use App\Models\Cheque;
use App\Models\ExtraIncome;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExtraIncomeService
{
    public function __construct(private readonly AccountService $ledger) {}

    public function accountType(string $destination): string
    {
        return match ($destination) {
            'main' => 'main',
            'spare_parts', 'spare_parts_cashier' => 'spare_parts',
            'bank' => 'bank',
            default => throw ValidationException::withMessages(['destination_account' => 'Select Main Account, Spare Parts Account, Spare Parts Cashier, or Bank Account.']),
        };
    }

    public function options(?string $date = null): array
    {
        Account::ensureSystemAccounts();
        $date ??= today()->toDateString();
        $selectedDate = Carbon::parse($date)->startOfDay();
        $cashierOpen = ! $selectedDate->isFuture()
            && CashierSession::whereDate('session_date', $date)->where('status', 'open')->exists();
        $balances = app(OutgoingFundingService::class)->balances($date);
        $unavailableReason = 'Cashier closed for '.$selectedDate->format('d M Y');

        $options = [];
        foreach (ExtraIncome::DESTINATIONS as $value => $label) {
            $isCashier = $value === 'spare_parts_cashier';
            $account = Account::systemAccount($this->accountType($value));
            $options[] = [
                'value' => $value,
                'label' => $label,
                'account_id' => $account?->id,
                'balance' => $balances[$value] ?? 0.0,
                'disabled' => $isCashier && ! $cashierOpen,
                'disabled_reason' => $isCashier && ! $cashierOpen ? $unavailableReason : null,
            ];
        }

        $message = 'Cashier for '.$selectedDate->format('d M Y').' is '.($cashierOpen
            ? 'open. Spare Parts Cashier is available.'
            : 'closed. Spare Parts Cashier is unavailable.');
        if (! $cashierOpen && ($active = CashierSession::active())) {
            $message .= ' Open cashier date: '.$active->session_date->format('d M Y').'.';
        }

        return [
            'date' => $date,
            'cashier_open' => $cashierOpen,
            'message' => $message,
            'options' => $options,
        ];
    }

    public function save(array $values, ?ExtraIncome $extraIncome = null): ExtraIncome
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($values, $extraIncome) {
            app(CashierService::class)->lock();

            $destination = $values['destination_account'];
            $accountType = $this->accountType($destination);
            $account = Account::whereKey(Account::systemAccount($accountType)?->id)
                ->where('active', true)
                ->lockForUpdate()
                ->first();

            if (! $account) {
                throw ValidationException::withMessages(['destination_account' => 'Select an active account for this shop.']);
            }

            $incomeDate = Carbon::parse($values['income_date'])->startOfDay();
            if ($destination === 'spare_parts_cashier') {
                $session = CashierSession::whereDate('session_date', $incomeDate->toDateString())
                    ->lockForUpdate()
                    ->first();
                if ($incomeDate->isFuture() || $session?->status !== 'open') {
                    throw ValidationException::withMessages([
                        'destination_account' => 'Spare Parts Cashier can only be selected when the cashier is open for the selected date.',
                    ]);
                }
            }

            if ($accountType !== 'bank' && $values['payment_method'] !== 'Cash') {
                throw ValidationException::withMessages([
                    'payment_method' => 'Select Bank Account for non-cash payment methods. Main Account, Spare Parts Account, and Spare Parts Cashier receive Cash.',
                ]);
            }

            $isCheque = $values['payment_method'] === 'Cheque';
            $chequeStatus = $isCheque ? ($values['cheque_status'] ?? 'Pending') : null;
            if ($isCheque && $chequeStatus === 'Cleared') {
                $chequeDate = Carbon::parse($values['cheque_date'])->startOfDay();
                $realizedDate = Carbon::parse($values['realized_date'])->startOfDay();
                if ($chequeDate->isFuture() || $realizedDate->lt($chequeDate)) {
                    throw ValidationException::withMessages([
                        'realized_date' => 'A cheque can only pass on or after its cheque date, and not on a future date.',
                    ]);
                }
            }

            $before = null;
            if ($extraIncome) {
                $extraIncome = ExtraIncome::lockForUpdate()->findOrFail($extraIncome->id);
                $this->checkVersion($extraIncome, $values['record_version']);
                $before = $extraIncome->getAttributes();
                if ($extraIncome->cheque_id) {
                    $existingCheque = Cheque::withTrashed()->lockForUpdate()->find($extraIncome->cheque_id);
                    if (! $existingCheque || $existingCheque->trashed() || ! in_array($existingCheque->status, ['Pending', 'Deposited'], true) || $existingCheque->forwarded_supplier_id || $existingCheque->payments()->exists() || $existingCheque->supplierPayments()->exists()) {
                        throw ValidationException::withMessages([
                            'reason' => 'This entry is linked to a settled or managed cheque. Update, return, or cancel it in Cheque Management.',
                        ]);
                    }
                    $existingCheque->update([
                        'status' => 'Cancelled',
                        'status_reason' => 'Replaced via Extra Money edit: '.$values['reason'],
                        'updated_by' => auth()->id(),
                    ]);
                } elseif ($extraIncome->status === 'Completed') {
                    $this->reverse($extraIncome, $values['reason']);
                }
            }

            $data = collect($values)->only([
                'income_date',
                'title',
                'source_name',
                'destination_account',
                'amount',
                'payment_method',
                'reference_number',
                'note',
            ])->all() + [
                'account_id' => $account->id,
                'cheque_id' => null,
                'status' => $isCheque ? 'Hold' : 'Completed',
            ];

            if ($extraIncome) {
                $extraIncome->update($data + [
                    'change_reason' => $values['reason'],
                    'updated_by' => auth()->id(),
                ]);
            } else {
                $extraIncome = ExtraIncome::create($data + [
                    'number' => 'INC-'.now()->format('ymd').'-'.Str::upper(Str::random(8)),
                    'created_by' => auth()->id(),
                ]);
            }

            if ($isCheque) {
                $cheque = Cheque::create([
                    'cheque_type' => 'Received',
                    'party_name' => $extraIncome->source_name ?: $extraIncome->title,
                    'reference_type' => $extraIncome->getMorphClass(),
                    'reference_id' => $extraIncome->id,
                    'related_reference' => $extraIncome->number,
                    'cheque_number' => $values['cheque_number'],
                    'bank_name' => $values['bank_name'],
                    'branch' => $values['branch'] ?? null,
                    'cheque_date' => $values['cheque_date'],
                    'amount' => $extraIncome->amount,
                    'bank_account_id' => $account->id,
                    'status' => 'Pending',
                    'note' => $extraIncome->note,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);

                $extraIncome->update(['cheque_id' => $cheque->id]);

                if ($chequeStatus !== 'Pending') {
                    app(SettlementService::class)->resolve($cheque, $chequeStatus, $values['realized_date'] ?? null);
                    $extraIncome->refresh();
                }
            } else {
                $this->postLedgerIncome($extraIncome);
            }

            $this->audit($extraIncome, $before ? 'Extra Money Updated' : 'Extra Money Added', $before);

            return $extraIncome;
        }, 3);
    }

    public function postLedgerIncome(ExtraIncome $extraIncome, CarbonInterface|string|null $date = null): AccountTransaction
    {
        $accountType = $this->accountType($extraIncome->destination_account);
        $cheque = $extraIncome->cheque_id ? $extraIncome->cheque : null;

        $ledgerNote = trim(implode(' · ', array_filter([
            $extraIncome->source_name ? 'From: '.$extraIncome->source_name : null,
            $cheque ? "Cheque {$cheque->cheque_number} ({$cheque->bank_name})" : null,
            'Added to '.$extraIncome->destination_label,
            $extraIncome->note,
        ])));

        // Recorded explicitly as income (credit), never as an expense.
        return $this->ledger->recordTransaction(
            accountType: $accountType,
            transactionType: 'income',
            amount: (float) $extraIncome->amount,
            description: 'Extra money: '.$extraIncome->title.' · '.$extraIncome->number,
            reference: $extraIncome,
            paymentMethod: $extraIncome->payment_method,
            note: $ledgerNote,
            transactionDate: $date ?? $extraIncome->income_date,
            category: 'extra_income',
            fundingSource: $extraIncome->destination_account,
        );
    }

    public function delete(ExtraIncome $extraIncome, string $reason, string $version): void
    {
        DB::transaction(function () use ($extraIncome, $reason, $version) {
            app(CashierService::class)->lock();
            $extraIncome = ExtraIncome::lockForUpdate()->findOrFail($extraIncome->id);
            $this->checkVersion($extraIncome, $version);
            $before = $extraIncome->getAttributes();

            if ($extraIncome->cheque_id) {
                $cheque = Cheque::withTrashed()->lockForUpdate()->find($extraIncome->cheque_id);
                if ($cheque && ! $cheque->trashed()) {
                    if ($cheque->forwarded_supplier_id || $cheque->payments()->exists() || $cheque->supplierPayments()->exists()) {
                        throw ValidationException::withMessages([
                            'reason' => 'A forwarded or shared cheque must be handled in Cheque Management.',
                        ]);
                    }
                    if ($cheque->status === 'Cleared') {
                        throw ValidationException::withMessages([
                            'reason' => 'This cheque is already passed. Return, cancel, or delete it in Cheque Management first.',
                        ]);
                    }
                    if (in_array($cheque->status, ['Pending', 'Deposited'], true)) {
                        app(SettlementService::class)->resolve($cheque, 'Cancelled', reason: $reason);
                        $extraIncome->refresh();
                    }
                }
            } elseif ($extraIncome->status === 'Completed') {
                $this->reverse($extraIncome, $reason);
            }

            $extraIncome->update([
                'change_reason' => $reason,
                'deleted_by' => auth()->id(),
            ]);
            $extraIncome->delete();
            $this->audit($extraIncome, 'Extra Money Deleted', $before);
        }, 3);
    }

    private function reverse(ExtraIncome $extraIncome, string $reason): void
    {
        $entries = AccountTransaction::where('reference_type', $extraIncome->getMorphClass())
            ->where('reference_id', $extraIncome->id)
            ->where('transaction_type', 'income')
            ->where('status', 'active')
            ->lockForUpdate()
            ->get();

        if ($entries->count() !== 1) {
            throw ValidationException::withMessages([
                'reason' => 'The original extra money income posting changed. Review its ledger before editing or deleting.',
            ]);
        }

        foreach ($entries as $entry) {
            $this->ledger->reverseTransaction($entry, $reason, $entry->transaction_date);
        }
    }

    private function checkVersion(ExtraIncome $extraIncome, string $version): void
    {
        if (! hash_equals($extraIncome->version(), $version)) {
            throw ValidationException::withMessages([
                'record_version' => 'This extra money entry changed. Reload it before saving.',
            ]);
        }
    }

    private function audit(ExtraIncome $extraIncome, string $event, ?array $before): void
    {
        AuditLog::create([
            'user_id' => auth()->id(),
            'event' => $event,
            'auditable_type' => $extraIncome->getMorphClass(),
            'auditable_id' => $extraIncome->id,
            'old_values' => $before,
            'new_values' => $extraIncome->getAttributes(),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
