<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CommissionPayment;
use App\Models\Technician;
use App\Models\TechnicianCommission;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CommissionSettlementService
{
    public function query(?int $technicianId, array $period)
    {
        return TechnicianCommission::query()->where('status', '!=', 'Cancelled')
            ->where(fn ($q) => $q->whereNull('invoice_id')->orWhereHas('invoice'))
            ->where(fn ($q) => $q->whereNull('job_card_id')->orWhereHas('jobCard'))
            ->when($technicianId, fn ($q) => $q->where('technician_id', $technicianId))
            ->when($period['from'], fn ($q, $date) => $q->whereDate('earned_date', '>=', $date))
            ->when($period['to'], fn ($q, $date) => $q->whereDate('earned_date', '<=', $date));
    }

    public function rows(?int $technicianId, array $period, bool $lock = false): Collection
    {
        $query = $this->query($technicianId, $period)->with(['technician', 'jobCard.intake.bike', 'invoice.bike', 'deductions', 'paymentItems.payment.account'])->orderBy('earned_date')->orderBy('id');

        return ($lock ? $query->lockForUpdate() : $query)->get();
    }

    public function totals(Collection $rows): array
    {
        return ['earned' => round((float) $rows->sum('amount'), 2), 'deducted' => round((float) $rows->sum('deducted_amount'), 2),
            'paid' => round((float) $rows->sum('paid_amount'), 2), 'covered' => round((float) $rows->sum('previously_covered'), 2),
            'balance' => round((float) $rows->sum('separate_balance'), 2)];
    }

    public function version(Collection $rows, array $period): string
    {
        return hash('sha256', json_encode([$period, $rows->map(fn ($row) => [$row->id, $row->technician_id, $row->amount, $row->paid_amount,
            $row->deducted_amount, $row->status, $row->salary_sheet_id, $row->earned_date->toDateString(), $row->invoice?->status, $row->jobCard?->status])->all()], JSON_THROW_ON_ERROR));
    }

    public function settle(Technician $technician, array $period, array $data, bool $payment): ?CommissionPayment
    {
        return DB::transaction(function () use ($technician, $period, $data, $payment) {
            Technician::whereKey($technician->id)->lockForUpdate()->firstOrFail();
            $rows = $this->rows($technician->id, $period, true);
            if (! hash_equals($this->version($rows, $period), $data['balance_version'])) {
                throw ValidationException::withMessages(['balance_version' => 'Commission changed while this popup was open. Reopen View and check the balance.']);
            }
            $remaining = (int) round((float) $data['amount'] * 100);
            $balance = (int) round($rows->sum('separate_balance') * 100);
            if ($remaining <= 0 || $remaining > $balance) {
                throw ValidationException::withMessages(['amount' => 'Enter an amount up to the commission balance available for this period.']);
            }
            $payout = null;
            if ($payment) {
                $account = Account::whereKey($data['account_id'])->where('active', true)->lockForUpdate()->firstOrFail();
                if ($account->code === Account::systemCode('bank') && $data['payment_method'] === 'Cash') {
                    throw ValidationException::withMessages(['payment_method' => 'Choose Bank Transfer or another non-cash method for the Bank Account.']);
                }
                $payout = CommissionPayment::create(['number' => 'COM-'.Str::upper(Str::random(12)), 'technician_id' => $technician->id,
                    'amount' => $remaining / 100, 'account_id' => $account->id, 'payment_method' => $data['payment_method'],
                    'payment_date' => $data['payment_date'], 'note' => $data['note'] ?? null, 'created_by' => auth()->id()]);
            }
            foreach ($rows as $row) {
                $available = (int) round($row->separate_balance * 100);
                if ($available <= 0 || $remaining <= 0) {
                    continue;
                }
                if ($payment && $row->earned_date->gt(Carbon::parse($data['payment_date'])->endOfDay())) {
                    throw ValidationException::withMessages(['payment_date' => 'Payment date cannot precede the commission earned date.']);
                }
                $amount = min($remaining, $available) / 100;
                $before = $row->getAttributes();
                if ($payment) {
                    $payout->items()->create(['technician_commission_id' => $row->id, 'amount' => $amount]);
                    $row->paid_amount = round((float) $row->paid_amount + $amount, 2);
                } else {
                    $row->deductions()->create(['amount' => $amount, 'description' => $data['description'], 'deduction_date' => today(), 'created_by' => auth()->id()]);
                    $row->deducted_amount = round((float) $row->deducted_amount + $amount, 2);
                }
                $outstanding = round((float) $row->amount - (float) $row->paid_amount - (float) $row->deducted_amount, 2);
                $row->status = $outstanding <= 0 ? ((float) $row->paid_amount > 0 ? 'Paid' : 'Settled') : ((float) $row->paid_amount > 0 ? 'Partially Paid' : 'Pending');
                $row->save();
                AuditLog::create(['event' => $payment ? 'Commission Paid' : 'Commission Deducted', 'user_id' => auth()->id(),
                    'auditable_type' => $row->getMorphClass(), 'auditable_id' => $row->id, 'old_values' => $before, 'new_values' => $row->getAttributes(),
                    'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
                $remaining -= (int) round($amount * 100);
            }
            if ($payout) {
                $type = match ($account->code) {
                    Account::systemCode('main') => 'main', Account::systemCode('bank') => 'bank',
                    Account::systemCode('spare_parts') => 'spare_parts', Account::systemCode('washing') => 'washing', default => 'cash',
                };
                $ledger = app(AccountService::class);
                $ledger->recordTransaction($type, 'expense', (float) $payout->amount, "Commission payment {$payout->number} · {$technician->name}", $payout, $payout->payment_method, $payout->note, $account->id, $payout->payment_date, 'commission');
                if ($type !== 'bank' && $payout->payment_method !== 'Cash') {
                    $ledger->recordTransaction('bank', 'expense', (float) $payout->amount, "Commission payment {$payout->number} · {$technician->name}", $payout, $payout->payment_method, null, null, $payout->payment_date, 'commission');
                }
            }

            return $payout;
        }, 3);
    }
}
