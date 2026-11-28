<?php

namespace App\Services;

use App\Models\SalarySetting;
use App\Models\Technician;
use App\Support\StaffPayRate;
use App\Support\StaffWorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class AttendanceTimingPenaltyService
{
    public function summarize(Technician $staff, Collection $records, array $closedDates, SalarySetting $settings): array
    {
        $schedule = StaffWorkSchedule::resolve($staff, $settings);
        $amount = round(StaffPayRate::daily($staff) / 2, 2);
        $result = ['late' => 0, 'early' => 0, 'penalties' => []];
        foreach ($records->sortBy('date') as $row) {
            if ($row->is_leave_day || isset($closedDates[$row->date->toDateString()])
                || ! in_array($row->status, ['Present', 'Late', 'Early Leave', 'Auto Closed'], true)) {
                continue;
            }
            $date = $row->date->toDateString();
            foreach (['late' => ['in_time', 'late_cutoff', 'enable_late_deduction', 'late_deduction_type', 'late_occurrences_per_half_day'],
                'early' => ['out_time', 'early_cutoff', 'enable_early_leave_deduction', 'early_leave_deduction_type', 'early_occurrences_per_half_day']] as $kind => [$punch, $cutoff, $enabled, $type, $frequency]) {
                if (! $row->$punch) {
                    continue;
                }
                $time = Carbon::parse($date.' '.$row->$punch);
                $threshold = Carbon::parse($date.' '.$schedule[$cutoff]);
                if (! ($kind === 'late' ? $time->gt($threshold) : $time->lt($threshold))) {
                    continue;
                }
                $result[$kind]++;
                if ($staff->salary_type !== 'Hourly' && $settings->$enabled && $settings->$type === 'Half day per occurrences'
                    && $result[$kind] % max(1, (int) $settings->$frequency) === 0) {
                    $result['penalties'][] = ['date' => $date, 'kind' => $kind, 'occurrence' => $result[$kind],
                        'description' => ($kind === 'late' ? 'Late arrival' : 'Early departure').' #'.$result[$kind],
                        'days' => 0.5, 'amount' => $amount];
                }
            }
        }

        return $result;
    }
}
