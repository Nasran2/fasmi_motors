<?php

namespace App\Services;

use App\Models\HolidayDate;
use App\Models\LeaveHolidaySetting;
use App\Models\SalarySetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class HolidayService
{
    public const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function getHolidayForDate(CarbonInterface $date): ?HolidayDate
    {
        return HolidayDate::whereDate('holiday_date', $date->toDateString())->where('is_active', true)->first();
    }

    public function isWeeklyLeave(CarbonInterface $date): bool
    {
        return in_array($date->format('l'), $this->getWeeklyLeaveDays(), true);
    }

    public function getWeeklyLeaveDays(): array
    {
        $workDays = SalarySetting::first()?->work_days;
        if ($workDays !== null) {
            return array_values(array_diff(self::DAYS, $workDays));
        }
        $value = LeaveHolidaySetting::where('key', 'weekly_leave_days')->value('value');

        return $value !== null ? array_values(array_intersect(self::DAYS, json_decode($value, true) ?? [])) : ['Friday'];
    }

    public function calendar(): array
    {
        return ['weekly_closed' => $this->getWeeklyLeaveDays(), 'today' => today()->toDateString(),
            'special_closed' => HolidayDate::where('is_active', true)->where('is_leave_enabled', true)->orderBy('holiday_date')->get()
                ->mapWithKeys(fn ($holiday) => [$holiday->holiday_date->toDateString() => ['name' => $holiday->holiday_name, 'type' => $holiday->holiday_type]])->all()];
    }

    public function calendarVersion(): string
    {
        $calendar = $this->calendar();

        return hash('sha256', json_encode($calendar));
    }

    public function dayInfo(string $date, ?array $calendar = null): array
    {
        $calendar ??= $this->calendar();
        $day = Carbon::parse($date);
        $special = $calendar['special_closed'][$day->toDateString()] ?? null;
        $weekly = in_array($day->format('l'), $calendar['weekly_closed'], true);

        return ['closed' => (bool) $special || $weekly, 'kind' => $special ? 'special' : ($weekly ? 'weekly' : 'working'),
            'label' => $special ? $special['name'] : ($weekly ? 'Weekly shop closed' : 'Working day')];
    }

    public function importRow(array $row, ?array $calendar = null): array
    {
        $calendar ??= $this->calendar();
        $info = $this->dayInfo($row['date'], $calendar);
        $hasPunch = filled($row['check_in'] ?? null) || filled($row['check_out'] ?? null);
        $future = $row['date'] > $calendar['today'];
        $workExplicit = $row['work_choice_explicit'] ?? array_key_exists('mark_worked', $row);
        $markWorked = ! $future && ($workExplicit ? (bool) ($row['mark_worked'] ?? false) : ($info['closed'] && $hasPunch));
        $explicit = $row['leave_choice_explicit'] ?? array_key_exists('mark_leave', $row);
        $markLeave = ! $info['closed'] && ! $hasPunch && ! $markWorked
            && ($explicit ? (bool) $row['mark_leave'] : ! $future);

        $absence = ! $info['closed'] && ! $hasPunch && ! $future && ! $markLeave && ! $markWorked;
        $colour = match (true) {
            $markWorked && $info['closed'] => 'bg-emerald-50',
            $info['kind'] === 'special' => 'bg-violet-50',
            $info['kind'] === 'weekly' => 'bg-sky-50',
            $markLeave => 'bg-amber-50',
            $absence => 'bg-rose-50',
            default => 'bg-white',
        };
        $note = match (true) {
            $markWorked && $info['closed'] => 'Worked on shop-closed day · extra daily pay',
            $markWorked => 'Worked · missing punches filled from staff schedule',
            $info['closed'] => 'Shop closed · not worked, no extra daily pay',
            $hasPunch => 'Attendance from punches',
            $markLeave => 'Paid leave · no absence deduction',
            $future => 'Future empty day · skipped',
            default => 'Absent · normal deduction',
        };

        return array_merge($row, ['shop_day' => $info, 'mark_worked' => $markWorked, 'work_choice_explicit' => $workExplicit, 'mark_leave' => $markLeave, 'future_day' => $future, 'leave_choice_explicit' => $explicit, 'row_colour' => $colour, 'leave_note' => $note]);
    }
}
