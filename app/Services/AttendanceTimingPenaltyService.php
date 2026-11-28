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
        $result = ['late' => 0, 'early' => 0, 'penalties' => [], 'late_fee' => 0.0, 'early_fee' => 0.0, 'fee_items' => []];
        $pending = ['late' => [], 'early' => []];
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
                if (! ($kind === 'late' ? $time->gte($threshold) : $time->lte($threshold))) {
                    continue;
                }
                $result[$kind]++;
                if ($staff->salary_type === 'Hourly' || ! $settings->$enabled || $settings->$type !== 'Half day per occurrences') {
                    continue;
                }
                $pending[$kind][] = ['date' => $date, 'kind' => $kind, 'attendance_id' => $row->id,
                    'amount' => $schedule[$kind.'_fee']];
                if (count($pending[$kind]) === max(1, (int) $settings->$frequency)) {
                    $result['penalties'][] = ['date' => $date, 'kind' => $kind, 'occurrence' => $result[$kind],
                        'description' => ($kind === 'late' ? 'Late arrival' : 'Early departure').' #'.$result[$kind],
                        'days' => 0.5, 'amount' => $amount, 'waived_fees' => round(array_sum(array_column($pending[$kind], 'amount')), 2),
                        'dates' => array_column($pending[$kind], 'date')];
                    $pending[$kind] = [];
                }
            }
        }

        foreach ($pending as $kind => $items) {
            $result[$kind.'_fee'] = round(array_sum(array_column($items, 'amount')), 2);
            $result['fee_items'] = array_merge($result['fee_items'], $items);
        }

        return $result;
    }
}
