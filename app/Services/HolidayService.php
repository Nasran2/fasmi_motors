<?php

namespace App\Services;

use App\Models\HolidayDate;
use App\Models\LeaveHolidaySetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class HolidayService
{
    private ?Collection $weeklyLeaves = null;

    public function __construct()
    {
        $this->loadWeeklyLeaves();
    }

    private function loadWeeklyLeaves(): void
    {
        if ($this->weeklyLeaves !== null) return;
        
        $setting = LeaveHolidaySetting::where('key', 'weekly_leave_days')->first();
        if ($setting && $setting->value) {
            $this->weeklyLeaves = collect(json_decode($setting->value, true));
        } else {
            // Default Friday
            $this->weeklyLeaves = collect(['Friday']);
        }
    }

    public function getHolidayForDate(CarbonInterface $date): ?HolidayDate
    {
        return HolidayDate::whereDate('holiday_date', $date->toDateString())
            ->where('is_active', true)
            ->first();
    }

    public function isWeeklyLeave(CarbonInterface $date): bool
    {
        return $this->weeklyLeaves->contains($date->format('l'));
    }

    public function getWeeklyLeaveDays(): array
    {
        return $this->weeklyLeaves->toArray();
    }
}
