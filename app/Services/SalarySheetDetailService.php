<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalarySheetDetailService
{
    public function attendanceRows(TechnicianSalarySheet $sheet): array
    {
        return data_get($sheet->calculation_snapshot, 'attendance_records')
            ?? $this->captureAttendance($sheet->technician_id, $sheet->period_start->toDateString(), $sheet->period_end->toDateString());
    }

    public function captureAttendance(int $technicianId, string $start, string $end): array
    {
        return TechnicianAttendance::where('technician_id', $technicianId)->whereBetween('date', [$start, $end])->orderBy('date')->get()
            ->map(fn ($row) => ['date' => $row->date->toDateString(), 'in_time' => $row->in_time, 'out_time' => $row->out_time,
                'actual_ot_minutes' => $row->actual_ot_minutes, 'status' => $row->status, 'working_hours' => (float) $row->working_hours, 'ot_minutes' => (int) $row->ot_minutes,
                'ot_amount' => (float) $row->ot_amount, 'deduction_amount' => (float) $row->deduction_amount,
                'is_auto_checkout' => (bool) $row->is_auto_checkout, 'is_manual_edit' => (bool) $row->is_manual_edit, 'note' => $row->note])->all();
    }

    public function addAdjustment(TechnicianSalarySheet $sheet, array $data): void
    {
        DB::transaction(function () use ($sheet, $data) {
            Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($sheet->status, ['Draft', 'Finalized', 'Partially Paid'], true), 422, 'Adjustments are available before full payment.');
            $before = $sheet->getAttributes();
            $addition = $data['type'] === 'addition';
            $amount = round((float) $data['amount'], 2);
            $gross = round((float) $sheet->gross_salary + ($addition ? $amount : 0), 2);
            $deductions = round((float) $sheet->total_deductions + ($addition ? 0 : $amount), 2);
            if (max($gross, $deductions) > 999999999999.99) {
                throw ValidationException::withMessages(['amount' => 'The adjusted salary total exceeds the supported amount.']);
            }
            $net = round($gross - $deductions, 2);
            if ($net < 0 || $net < (float) $sheet->paid_amount) {
                throw ValidationException::withMessages(['amount' => 'This deduction exceeds the remaining salary available.']);
            }
            ($addition ? $sheet->additions() : $sheet->paymentDeductions())->create([
                'technician_id' => $sheet->technician_id, $addition ? 'addition_name' : 'deduction_name' => $data['description'],
                'amount' => $amount, 'reason' => $data['reason'] ?? null,
                $addition ? 'addition_date' : 'deduction_date' => today(), 'created_by' => auth()->id(),
            ]);
            $snapshot = $sheet->calculation_snapshot ?? [];
            $field = $addition ? 'manual_additions' : 'manual_deductions';
            $manual = round((float) $sheet->$field + $amount, 2);
            $snapshot['inputs'][$field] = $manual;
            $snapshot['earnings']['gross'] = $gross;
            if (! $addition) {
                $snapshot['deductions']['manual'] = $manual;
            }
            $balance = round($net - (float) $sheet->paid_amount, 2);
            $snapshot = app(SalaryBalanceService::class)->restate($snapshot, $gross, $deductions);
            $sheet->update([$field => $manual, 'gross_salary' => $gross, 'total_deductions' => $deductions,
                'deductions' => round($deductions - (float) $sheet->epf_employee, 2), 'net_payable' => $net, 'balance_amount' => $balance,
                'status' => $sheet->status === 'Partially Paid' && $balance == 0 ? 'Paid' : $sheet->status,
                'paid_at' => $sheet->status === 'Partially Paid' && $balance == 0 ? now() : $sheet->paid_at,
                'calculation_snapshot' => $snapshot]);
            $this->audit($sheet, 'Salary Adjustment Added', $before, $data);
        }, 3);
    }

    public function refreshAttendance(TechnicianSalarySheet $sheet, string $reason): void
    {
        DB::transaction(function () use ($sheet, $reason) {
            $staff = Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::whereKey($sheet->id)->lockForUpdate()->firstOrFail();
            abort_unless($sheet->status === 'Draft' && (float) $sheet->paid_amount === 0.0, 422, 'Reopen an unpaid finalized sheet before refreshing attendance.');
            if (TechnicianSalarySheet::where('technician_id', $staff->id)->whereKeyNot($sheet->id)
                ->whereDate('period_start', '<=', $sheet->period_end)->whereDate('period_end', '>=', $sheet->period_start)
                ->whereIn('status', ['Finalized', 'Partially Paid', 'Paid'])->exists()) {
                throw ValidationException::withMessages(['reason' => 'This period overlaps locked salary attendance.']);
            }
            $before = $sheet->getAttributes();
            $calculator = app(AttendanceCalculationService::class);
            foreach (TechnicianAttendance::where('technician_id', $staff->id)->whereBetween('date', [$sheet->period_start, $sheet->period_end])->lockForUpdate()->get() as $row) {
                $old = $row->getAttributes();
                $overrides = ['break_minutes' => $row->break_minutes, 'auto_filled_checkout' => $row->is_auto_checkout];
                if ($row->is_manual_edit) {
                    $overrides += ['ot_amount' => $row->ot_amount, 'deduction_amount' => $row->deduction_amount];
                }
                $metrics = $calculator->calculateDailyAttendance($staff, $row->date, $row->status, $row->in_time, $row->out_time, $overrides);
                $row->update($metrics + ['edit_reason' => $reason, 'edited_by' => auth()->id(), 'updated_by' => auth()->id()]);
                AuditLog::create(['user_id' => auth()->id(), 'event' => 'Salary Attendance Refreshed', 'auditable_type' => $row->getMorphClass(), 'auditable_id' => $row->id,
                    'old_values' => $old, 'new_values' => $row->getAttributes(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
            }
            $inputs = data_get($sheet->calculation_snapshot, 'inputs', []);
            $inputs = array_replace($inputs, Arr::only($sheet->getAttributes(), ['allowances', 'manual_additions', 'other_deductions', 'manual_deductions', 'worked_on_leave_pay']));
            $inputs['paid_amount'] = 0; // A draft refresh cannot create a payment.
            $month = data_get($sheet->calculation_snapshot, 'salary_cycle.salary_month', $sheet->period_end);
            $values = app(SalaryCalculationService::class)->generateMonthlySalary($staff, $month, $inputs);
            if ($values['period_start']->toDateString() !== $sheet->period_start->toDateString() || $values['period_end']->toDateString() !== $sheet->period_end->toDateString()) {
                throw ValidationException::withMessages(['reason' => 'The salary cycle changed. Regenerate the draft for the correct period.']);
            }
            $sheet->update(Arr::only($values, array_keys($sheet->getAttributes())));
            TechnicianContribution::where('salary_sheet_id', $sheet->id)->update($values['calculation_snapshot']['contributions']);
            $this->audit($sheet, 'Salary Draft Refreshed', $before, ['reason' => $reason]);
        }, 3);
    }

    private function audit(TechnicianSalarySheet $sheet, string $event, array $before, array $details): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'event' => $event, 'auditable_type' => $sheet->getMorphClass(), 'auditable_id' => $sheet->id,
            'old_values' => $before, 'new_values' => $sheet->getAttributes() + ['adjustment_details' => $details],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }
}
