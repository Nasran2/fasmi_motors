<?php

namespace App\Support;

use App\Models\Technician;

final class StaffPayRate
{
    public static function daily(Technician $staff): float
    {
        return match ($staff->salary_type) {
            'Weekly' => round((float) $staff->basic_salary / 7, 2),
            'Hourly' => round((float) $staff->hourly_salary * (float) StaffWorkSchedule::resolve($staff)['working_hours'], 2),
            default => (float) $staff->daily_salary > 0 ? (float) $staff->daily_salary : round((float) $staff->basic_salary / 26, 2),
        };
    }
}
