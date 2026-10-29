<?php

namespace App\Services;

use App\Models\SalaryBalanceRecovery;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class SalaryBalanceService
{
    public const SOURCES = ['salary_old_balance', 'salary_overpayment'];

    public function restate(array $snapshot, float $gross, float $deductions): array
    {
        if (! isset($snapshot['debt_summary'])) {
            return $snapshot;
        }
        $old = collect($snapshot['old_balances'] ?? []);
        $repairs = collect(data_get($snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment');
        $appliedOld = (float) $old->sum('amount');
        $appliedRepair = (float) $repairs->sum('amount');
        $requested = (float) $old->sum(fn ($row) => $row['requested_amount'] ?? $row['amount'])
            + (float) $repairs->sum(fn ($row) => $row['requested_amount'] ?? $row['amount']);
        $total = (float) $old->sum('remaining_balance') + (float) $repairs->sum('remaining_balance');
        $raw = round($gross - ($deductions - $appliedOld - $appliedRepair) - $requested, 2);
        $snapshot['debt_summary'] = array_replace($snapshot['debt_summary'], [
            'total_owed' => round($total, 2), 'applied_old_balance' => round($appliedOld, 2), 'applied_repair' => round($appliedRepair, 2),
            'deducted_this_month' => round($appliedOld + $appliedRepair, 2), 'requested_recovery' => round($requested, 2),
            'remaining_old_balance' => round((float) $old->sum('remaining_balance') - $appliedOld, 2),
            'remaining_repair_balance' => round((float) $repairs->sum('remaining_balance') - $appliedRepair, 2),
            'balance_carried_forward' => round($total - $appliedOld - $appliedRepair, 2), 'requested_salary_balance' => $raw,
            'shortfall' => max(0, -$raw),
        ]);

        return $snapshot;
    }

    public function items(int $staffId, $month): array
    {
        return TechnicianDeduction::where('technician_id', $staffId)->whereIn('source_type', self::SOURCES)
            ->where('status', 'pending')->whereDate('deduction_date', '<=', Carbon::parse($month)->endOfMonth())
            ->orderBy('deduction_date')->orderBy('id')->get()->filter(fn ($row) => $row->amount > $row->recovered_amount)
            ->map(fn ($row) => ['id' => $row->id, 'description' => $row->deduction_name, 'reference' => $row->reference_no,
                'note' => $row->reason, 'remaining_balance' => round($row->amount - $row->recovered_amount, 2),
                'amount' => 0.0, 'requested_amount' => 0.0])->values()->all();
    }

    public function allocate(int $staffId, $month, array $inputs, array $repairs, float $available): array
    {
        $items = $this->items($staffId, $month);
        $choices = $inputs['old_balance_installments'] ?? [];
        if (array_diff(array_map('strval', array_keys($choices)), array_map('strval', array_column($items, 'id')))) {
            throw ValidationException::withMessages(['old_balance_installments' => 'An old balance changed or does not belong to this staff member and month. Refresh the preview.']);
        }
        $new = round((float) ($inputs['old_balance_amount'] ?? 0), 2);
        if ($new > 0) {
            $items[] = ['id' => 'new', 'description' => ($inputs['old_balance_description'] ?? null) ?: 'Old balance owed to shop',
                'note' => $inputs['old_balance_note'] ?? null, 'reference' => 'Opening old balance', 'remaining_balance' => $new,
                'amount' => 0.0, 'requested_amount' => 0.0];
        }
        $capacity = max(0, (int) round($available * 100));
        $requested = 0;
        foreach ($items as &$item) {
            $choice = $item['id'] === 'new' ? ($inputs['old_balance_deduction'] ?? 0) : ($choices[$item['id']] ?? 0);
            $amount = (int) round((float) $choice * 100);
            if ($amount < 0 || $amount > (int) round($item['remaining_balance'] * 100)) {
                throw ValidationException::withMessages(['old_balance_installments' => 'Choose an old balance deduction between zero and its remaining balance.']);
            }
            $requested += $amount;
            $item['requested_amount'] = $amount / 100;
            $item['amount'] = min($capacity, $amount) / 100;
            $capacity -= (int) round($item['amount'] * 100);
        }
        unset($item);
        foreach ($repairs as &$item) {
            $amount = (int) round($item['amount'] * 100);
            $requested += $amount;
            $item['requested_amount'] = $amount / 100;
            $item['amount'] = min($capacity, $amount) / 100;
            $capacity -= (int) round($item['amount'] * 100);
        }
        unset($item);
        $oldTotal = round(array_sum(array_column($items, 'remaining_balance')), 2);
        $repairTotal = round(array_sum(array_column($repairs, 'remaining_balance')), 2);
        $oldApplied = round(array_sum(array_column($items, 'amount')), 2);
        $repairApplied = round(array_sum(array_column($repairs, 'amount')), 2);

        return ['items' => $items, 'repairs' => $repairs, 'summary' => [
            'opening_old_balance' => $oldTotal, 'opening_repair_balance' => $repairTotal, 'total_owed' => round($oldTotal + $repairTotal, 2),
            'requested_recovery' => $requested / 100, 'applied_old_balance' => $oldApplied, 'applied_repair' => $repairApplied,
            'deducted_this_month' => round($oldApplied + $repairApplied, 2), 'remaining_old_balance' => round($oldTotal - $oldApplied, 2),
            'remaining_repair_balance' => round($repairTotal - $repairApplied, 2),
            'balance_carried_forward' => round($oldTotal + $repairTotal - $oldApplied - $repairApplied, 2),
            'requested_salary_balance' => round($available - $requested / 100, 2), 'shortfall' => max(0, round($requested / 100 - $available, 2)),
        ]];
    }

    public function finalize(TechnicianSalarySheet $sheet, ?int $userId): void
    {
        $snapshot = $sheet->calculation_snapshot ?? [];
        $month = data_get($snapshot, 'salary_cycle.salary_month', $sheet->period_end);
        $saved = collect($snapshot['old_balances'] ?? []);
        $current = collect($this->items($sheet->technician_id, $month));
        $balances = fn ($rows) => $rows->where('id', '!=', 'new')->mapWithKeys(fn ($item) => [(string) $item['id'] => $item['remaining_balance']])->sortKeys()->all();
        if ($balances($saved) != $balances($current)) {
            throw ValidationException::withMessages(['month' => 'Old balances changed after this draft was prepared. Refresh the draft before finalizing.']);
        }
        foreach ($saved as $item) {
            if ($item['id'] === 'new') {
                $balance = TechnicianDeduction::create(['technician_id' => $sheet->technician_id, 'source_type' => 'salary_old_balance',
                    'source_id' => $sheet->id, 'reference_no' => $sheet->number, 'deduction_name' => $item['description'],
                    'amount' => $item['remaining_balance'], 'recovered_amount' => 0, 'reason' => $item['note'],
                    'deduction_date' => Carbon::parse($month)->startOfMonth()->addMonth(), 'status' => 'pending', 'created_by' => $userId]);
            } else {
                $balance = TechnicianDeduction::where('technician_id', $sheet->technician_id)->lockForUpdate()->findOrFail($item['id']);
            }
            $amount = round((float) $item['amount'], 2);
            if ($amount <= 0) {
                continue;
            }
            $recovered = round((float) $balance->recovered_amount + $amount, 2);
            if ($recovered > (float) $balance->amount) {
                throw ValidationException::withMessages(['month' => 'An old balance has already been recovered. Refresh the draft.']);
            }
            SalaryBalanceRecovery::create(['technician_deduction_id' => $balance->id, 'salary_sheet_id' => $sheet->id, 'amount' => $amount]);
            $complete = $recovered >= (float) $balance->amount;
            $balance->update(['recovered_amount' => $recovered, 'status' => $complete ? 'deducted' : 'pending',
                'salary_payment_id' => $complete ? $sheet->id : null, 'updated_by' => $userId]);
        }
    }

    public function reopen(TechnicianSalarySheet $sheet): void
    {
        $own = TechnicianDeduction::where('source_type', 'salary_old_balance')->where('source_id', $sheet->id)->first();
        if ($own && TechnicianSalarySheet::where('technician_id', $sheet->technician_id)->where('period_end', '>', $sheet->period_end)
            ->whereIn('status', ['Finalized', 'Partially Paid', 'Paid'])->exists()) {
            throw ValidationException::withMessages(['reason' => 'A later finalized paysheet uses this old balance. Reopen the later paysheet first.']);
        }
        foreach (SalaryBalanceRecovery::where('salary_sheet_id', $sheet->id)->get() as $recovery) {
            $balance = TechnicianDeduction::lockForUpdate()->findOrFail($recovery->technician_deduction_id);
            $balance->update(['recovered_amount' => max(0, round($balance->recovered_amount - $recovery->amount, 2)),
                'status' => 'pending', 'salary_payment_id' => null, 'updated_by' => auth()->id()]);
            $recovery->delete();
        }
        $own?->delete();
    }
}
