<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianSalarySheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceRecalculationService
{
    public function recalculate(int $id, string $reason): string
    {
        return DB::transaction(function () use ($id, $reason) {
            $record = TechnicianAttendance::findOrFail($id);
            $staff = Technician::whereKey($record->technician_id)->lockForUpdate()->firstOrFail();
            $record = TechnicianAttendance::whereKey($id)->lockForUpdate()->firstOrFail();
            if (TechnicianSalarySheet::where('technician_id', $staff->id)->whereDate('period_start', '<=', $record->date)
                ->whereDate('period_end', '>=', $record->date)->whereIn('status', ['Finalized', 'Partially Paid', 'Paid'])->exists()) {
                return 'locked';
            }
            $before = $record->getAttributes();
            $overrides = ['break_minutes' => $record->break_minutes, 'auto_filled_checkout' => $record->is_auto_checkout];
            if ($record->is_manual_edit) {
                $overrides += ['ot_amount' => $record->ot_amount, 'deduction_amount' => $record->deduction_amount];
            }
            $metrics = app(AttendanceCalculationService::class)->calculateDailyAttendance($staff, $record->date, $record->status, $record->in_time, $record->out_time, $overrides);
            $record->update($metrics + ['edit_reason' => $reason, 'edited_by' => auth()->id(), 'updated_by' => auth()->id()]);
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'Attendance Bulk Recalculated', 'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
                'old_values' => $before, 'new_values' => $record->getAttributes(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

            return $record->is_manual_edit ? 'manual' : 'recalculated';
        }, 3);
    }

    public function run(Builder $query, string $reason): array
    {
        $counts = ['recalculated' => 0, 'manual' => 0, 'locked' => 0, 'invalid' => 0];
        // ID-based chunks process every matching page even when recalculation changes a status.
        $query->select('id')->chunkById(200, function ($rows) use (&$counts, $reason) {
            foreach ($rows as $row) {
                try {
                    $counts[$this->recalculate($row->id, $reason)]++;
                } catch (ValidationException $exception) {
                    $counts['invalid']++;
                }
            }
        });

        return $counts;
    }
}
