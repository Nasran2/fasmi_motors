<?php

namespace App\Support;

use App\Models\SalarySetting;
use App\Models\Technician;
use Carbon\Carbon;

final class StaffWorkSchedule
{
    public static function resolve(?Technician $staff = null, ?SalarySetting $settings = null): array
    {
        $settings ??= SalarySetting::firstOrCreate([]);
        $systemStart = Carbon::parse($settings->opening_time ?: '08:00')->format('H:i:s');
        $systemEnd = Carbon::parse($settings->closing_time ?: '17:00')->format('H:i:s');
        $custom = $staff?->work_schedule_mode === 'custom' && filled($staff->work_start_time) && filled($staff->work_end_time);
        $start = $custom ? Carbon::parse($staff->work_start_time)->format('H:i:s') : $systemStart;
        $end = $custom ? Carbon::parse($staff->work_end_time)->format('H:i:s') : $systemEnd;
        $day = '2000-01-01 ';
        $open = Carbon::parse($day.$start);
        $close = Carbon::parse($day.$end);
        $systemOpen = Carbon::parse($day.$systemStart);
        $systemClose = Carbon::parse($day.$systemEnd);
        $morning = Carbon::parse($day.($settings->morning_half_day_cutoff ?: '12:00'));
        $evening = Carbon::parse($day.($settings->evening_half_day_cutoff ?: '13:00'));
        if ($custom) {
            $morning = $open->copy()->addSeconds($morning->getTimestamp() - $systemOpen->getTimestamp());
            $evening = $close->copy()->subSeconds($systemClose->getTimestamp() - $evening->getTimestamp());
            $morning = $morning->max($open)->min($close);
            $evening = $evening->max($open)->min($close);
        }
        $hours = $custom ? max(0, ($close->getTimestamp() - $open->getTimestamp()) / 3600 - (int) $settings->lunch_break_minutes / 60)
            : (float) ($settings->working_hours_per_day ?: 8);

        return ['mode' => $custom ? 'custom' : 'system_default', 'start' => $start, 'end' => $end,
            'working_hours' => round($hours, 4), 'break_minutes' => (int) $settings->lunch_break_minutes,
            'morning_cutoff' => $morning->format('H:i:s'), 'evening_cutoff' => $evening->format('H:i:s')];
    }

    public static function importSchedules(): array
    {
        $settings = SalarySetting::firstOrCreate([]);

        return ['default' => self::resolve(null, $settings), 'staff' => Technician::orderBy('id')->get()->mapWithKeys(fn ($staff) => [$staff->id => self::resolve($staff, $settings)])->all()];
    }

    public static function version(?array $schedules = null): string
    {
        return hash('sha256', json_encode($schedules ?? self::importSchedules()));
    }
}
