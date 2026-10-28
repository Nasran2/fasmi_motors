<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\CashierSession;
use App\Models\DailyClosing;
use App\Models\Drawing;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashierService
{
    public const ACCOUNTS = ['main' => 'Main Account', 'bank' => 'Bank Account', 'washing' => 'Washing Account', 'spare_parts' => 'Spare Parts Account'];

    public function __construct(private AccountService $accounts) {}

    // Acquire current reads in a stable order before taking a balance snapshot.
    // Opening, closing and protected sales share these account locks.
    public function lock(): void
    {
        $codes = array_map(fn ($type) => Account::systemCode($type), array_keys(self::ACCOUNTS));
        Account::whereIn('code', $codes)->orderBy('id')->lockForUpdate()->get();
    }

    public function summary(string $date): array
    {
        $start = Carbon::parse($date)->startOfDay();
        $ids = collect(array_keys(self::ACCOUNTS))->map(fn ($type) => Account::systemAccount($type)->id);
        $transactions = AccountTransaction::with('account')->posted()->whereIn('account_id', $ids)
            ->whereBetween('transaction_date', [$start, $start->copy()->endOfDay()])->orderBy('transaction_date')->orderBy('id')->get();
        $accounts = [];
        foreach (self::ACCOUNTS as $type => $label) {
            $rows = $transactions->where('account_id', Account::systemAccount($type)->id);
            $opening = $this->accounts->ledgerOpeningBalance($type, $start);
            // Opening corrections belong to opening, rather than today's sales.
            $openingRows = $rows->where('category', 'cashier_opening');
            $opening += $openingRows->sum('credit') - $openingRows->sum('debit');
            $rows = $rows->where('category', '!=', 'cashier_opening');
            $income = (float) $rows->sum('credit');
            $outgoing = (float) $rows->sum('debit');
            $accounts[$type] = [
                'opening' => round($opening, 2), 'income' => $income, 'outgoing' => $outgoing,
                'expenses' => (float) $rows->where('transaction_type', 'expense')->sum('debit'),
                'drawings' => (float) $rows->where('transaction_type', 'drawing')->sum('debit'),
                'closing' => round($opening + $income - $outgoing, 2),
            ];
        }

        $session = CashierSession::whereDate('session_date', $date)->first();
        $collections = app(CollectionReportService::class)->rows($date, $date);
        $cashAccounts = app(CashPositionService::class)->accounts($date, $session, $collections);

        return [
            'accounts' => $accounts, 'transactions' => $transactions, 'cash_accounts' => $cashAccounts, 'collections' => $collections,
            'cash_opening' => round(collect($cashAccounts)->sum('opening'), 2),
            'cash_in_hand' => round(collect($cashAccounts)->sum('closing'), 2),
            'cash_paid' => round(collect($cashAccounts)->sum('outgoing'), 2),
            'cash_other_in' => round(collect($cashAccounts)->sum('other_in'), 2),
            'cash_received' => round($collections->where('channel', 'cash')->sum('amount'), 2),
            'bank_received' => round($collections->where('channel', 'bank')->sum('amount'), 2),
            'total_received' => round($collections->sum('amount'), 2),
            'total_paid' => round(collect($cashAccounts)->sum('outgoing') + ($accounts['bank']['outgoing'] ?? 0), 2),
            'transaction_count' => $collections->count(),
        ];
    }

    public function open(float $opening, ?string $reason, array $washing = [], bool $saveDefault = false): CashierSession
    {
        Account::ensureSystemAccounts();

        return DB::transaction(function () use ($opening, $reason, $washing, $saveDefault) {
            $this->lock();
            if (CashierSession::active()) {
                throw ValidationException::withMessages(['cashier' => 'A cashier is already open. Close it before opening again.']);
            }
            $existing = CashierSession::whereDate('session_date', today())->lockForUpdate()->first();
            $positions = app(CashPositionService::class)->accounts(today()->toDateString(), $existing);
            $openingBalances = collect($positions)->map(fn ($account) => $account['opening'])->all();
            $suggested = round(collect($positions)->sum($existing ? 'closing' : 'opening'), 2);
            $difference = round($opening - $suggested, 2);
            if ($difference != 0 && blank($reason)) {
                throw ValidationException::withMessages(['opening_reason' => 'Explain the change to the automatic opening balance.']);
            }
            $openingBalances['main'] = round($openingBalances['main'] + $difference, 2);
            $washing += ['washing_person_id' => null, 'washing_commission_percentage' => app(WashingCommissionService::class)->defaultPercentage()];
            $values = [
                'opening_balance' => round(array_sum($openingBalances), 2), 'cash_opening_balances' => $openingBalances,
                'status' => 'open', 'notes' => $reason, 'closed_at' => null, 'closed_by' => null,
                'washing_person_id' => $washing['washing_person_id'], 'washing_commission_percentage' => $washing['washing_commission_percentage'],
            ];
            if ($existing) {
                $existing->update($values);
                DailyClosing::whereDate('closing_date', today())->update(['status' => 'reopened', 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => $reason ?: 'Cashier opened again']);
                $session = $existing;
            } else {
                $session = CashierSession::create($values + [
                    'session_date' => today()->toDateString(), 'opened_by' => auth()->id(), 'opened_at' => now(),
                ]);
            }
            if ($saveDefault) {
                Setting::updateOrCreate(['key' => WashingCommissionService::settingKey()], ['value' => $washing['washing_commission_percentage'], 'group' => 'technician', 'type' => 'number']);
            }
            // On rollout, separate washing on receipts already posted today by
            // the previous version. Preserve historical days and closing records.
            $paymentIds = AccountTransaction::posted()->where('reference_type', (new Payment)->getMorphClass())
                ->where('transaction_type', 'income')->where('transaction_date', '>=', today()->startOfDay())->select('reference_id');
            Invoice::where('washing_amount', '>', 0)->where('status', '!=', 'Cancelled')
                ->whereHas('payments', fn ($query) => $query->whereIn('id', $paymentIds))
                ->each(fn ($invoice) => $this->accounts->reallocateInvoiceReceipts($invoice, today()->startOfDay()));
            if ($difference != 0) {
                $this->accounts->recordTransaction('main', $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference), 'Cashier opening balance correction', $session, 'Cash', $reason, transactionDate: today()->startOfDay(), category: 'cashier_opening');
            }

            $session->events()->create(['action' => $existing ? 'reopened' : 'opened', 'cash_amount' => $opening,
                'washing_person_id' => $session->washing_person_id, 'washing_commission_percentage' => $session->washing_commission_percentage,
                'details' => ['reason' => $reason, 'opening_correction' => $difference, 'saved_default' => $saveDefault], 'created_by' => auth()->id()]);

            return $session;
        });
    }

    public function suggestedOpening(): float
    {
        $session = CashierSession::whereDate('session_date', today())->first();
        $positions = app(CashPositionService::class)->accounts(today()->toDateString(), $session);

        return round(collect($positions)->sum($session ? 'closing' : 'opening'), 2);
    }

    public function close(CashierSession $session, array $values): void
    {
        Account::ensureSystemAccounts();
        DB::transaction(function () use ($session, $values) {
            $this->lock();
            $session = CashierSession::lockForUpdate()->findOrFail($session->id);
            if ($session->status !== 'open') {
                throw ValidationException::withMessages(['cashier' => 'This cashier session is already closed.']);
            }
            $date = $session->session_date->toDateString();
            $summary = $this->summary($date);
            if (! hash_equals($this->fingerprint($summary), $values['balance_version'])) {
                throw ValidationException::withMessages(['balance_version' => 'Transactions changed while this form was open. Review the refreshed balances and close again.']);
            }
            $withdrawal = round((float) $values['owner_withdrawal'], 2);
            $expected = collect($summary['cash_accounts'])->map(fn ($account) => $account['closing'])->all();
            $expected['spare_parts'] = round($expected['spare_parts'] - $withdrawal, 2);
            if ($withdrawal > 0 && ($expected['spare_parts'] < 0 || array_sum($expected) < 0)) {
                throw ValidationException::withMessages(['owner_withdrawal' => 'The withdrawal exceeds the Spare Parts cash in hand.']);
            }
            $differences = [];
            foreach (CashPositionService::ACCOUNTS as $type => $label) {
                $differences[$type] = round((float) $values['balances'][$type] - $expected[$type], 2);
            }
            if (collect($differences)->contains(fn ($difference) => $difference != 0) && blank($values['difference_reason'] ?? null)) {
                throw ValidationException::withMessages(['difference_reason' => 'Explain changed balances before closing the cashier.']);
            }
            $effectiveAt = $session->session_date->isToday() ? now() : $session->session_date->endOfDay();
            if ($withdrawal > 0) {
                $drawing = Drawing::create([
                    'drawing_date' => $date, 'person_name' => ($values['owner_name'] ?? null) ?: 'Owner',
                    'drawing_type' => 'Owner withdrawal', 'account_id' => Account::systemAccount('spare_parts')->id,
                    'amount' => $withdrawal, 'number' => 'DRW-CASHIER-'.$session->id.'-'.Str::upper(Str::random(6)),
                    'note' => 'Cashier closing #'.$session->id, 'created_by' => auth()->id(),
                ]);
                $this->accounts->recordTransaction('spare_parts', 'drawing', $withdrawal, 'Owner withdrawal at cashier closing', $drawing, 'Cash', transactionDate: $effectiveAt, category: 'owner_withdrawal');
            }
            foreach ($differences as $type => $difference) {
                if ($difference != 0) {
                    $this->accounts->recordTransaction($type, $difference > 0 ? 'adjustment_increase' : 'adjustment_decrease', abs($difference), 'Cashier cash count reconciliation', $session, 'Cash', $values['difference_reason'], transactionDate: $effectiveAt, category: 'cashier_cash_reconciliation');
                }
            }
            $operational = [];
            $closingData = ['closed_by' => auth()->id(), 'status' => 'closed', 'notes' => 'Cash-only cashier session #'.$session->id,
                'difference_reason' => $values['difference_reason'] ?? null, 'difference' => array_sum($differences)];
            foreach (self::ACCOUNTS as $type => $label) {
                $account = $summary['accounts'][$type];
                $logicalExpected = $account['closing'] - ($type === 'spare_parts' ? $withdrawal : 0);
                $operational[$type] = round($logicalExpected + ($differences[$type] ?? 0), 2);
                $closingData[$type.'_opening_balance'] = $account['opening'];
                $closingData[$type.'_income'] = $account['income'];
                $closingData[$type.'_expense'] = $account['outgoing'] + ($type === 'spare_parts' ? $withdrawal : 0);
                $closingData[$type.'_closing_balance'] = $logicalExpected;
                $closingData[$type.'_actual_balance'] = $operational[$type];
                $closingData[$type.'_difference'] = $differences[$type] ?? 0;
            }
            $closingData += ['cash_opening_balance' => $summary['cash_opening'], 'cash_income' => $summary['cash_received'] + $summary['cash_other_in'],
                'cash_expense' => $summary['cash_paid'] + $withdrawal, 'cash_closing_balance' => round(array_sum($expected), 2),
                'cash_actual_balance' => round(array_sum($values['balances']), 2), 'cash_difference' => array_sum($differences)];
            DailyClosing::updateOrCreate(['closing_date' => $date], $closingData);
            $summary['closing_withdrawal'] = $withdrawal;
            unset($summary['transactions'], $summary['collections']);
            $session->events()->create(['action' => 'closed', 'cash_amount' => round(array_sum($values['balances']), 2),
                'washing_person_id' => $session->washing_person_id, 'washing_commission_percentage' => $session->washing_commission_percentage,
                'details' => ['owner_withdrawal' => $withdrawal, 'balances' => $values['balances'], 'reason' => $values['difference_reason'] ?? null], 'created_by' => auth()->id()]);
            $session->update([
                'status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => now(),
                'owner_withdrawal' => (float) $session->owner_withdrawal + $withdrawal,
                'closing_summary' => $summary, 'actual_balances' => $operational, 'cash_balances' => $values['balances'],
                'notes' => $values['difference_reason'] ?? null,
            ]);
        });
    }

    public function fingerprint(array $summary): string
    {
        return hash('sha256', json_encode([$summary['accounts'], $summary['cash_accounts'], $summary['transactions']->map(fn ($row) => [$row->id, $row->status, $row->credit, $row->debit])->all()]));
    }
}
