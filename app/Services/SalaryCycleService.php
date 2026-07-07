<?php

namespace App\Services;

use App\Models\SalaryCycleSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SalaryCycleService
{
    public function getSalaryPeriod(CarbonInterface|string $salaryMonth): array
    {
        $setting = SalaryCycleSetting::current();
        $month = Carbon::parse($salaryMonth)->startOfMonth();

        return $setting->isCustomCutoffActive()
            ? $this->getCustomCutoffPeriod($month, $setting)
            : $this->getNormalCalendarPeriod($month);
    }

    public function getNormalCalendarPeriod(CarbonInterface|string $salaryMonth): array
    {
        $month = Carbon::parse($salaryMonth)->startOfMonth();
        $start = $month->copy();
        $end = $month->copy()->endOfMonth();

        return $this->periodPayload(
            month: $month,
            start: $start,
            end: $end,
            mode: 'normal_calendar',
            label: 'Calendar Month Salary',
            excludedDates: collect()
        );
    }

    public function getCustomCutoffPeriod(CarbonInterface|string $salaryMonth, ?SalaryCycleSetting $setting = null): array
    {
        $setting ??= SalaryCycleSetting::current();
        $month = Carbon::parse($salaryMonth)->startOfMonth();
        $end = $this->customEndDateForMonth($month, (int) ($setting->cycle_end_day ?: 30));
        $previousEnd = $this->customEndDateForMonth($month->copy()->subMonthNoOverflow(), (int) ($setting->cycle_end_day ?: 30));
        $start = $previousEnd->copy()->addDay();
        $excludedDates = $this->getExcludedDates($month, $end, (bool) $setting->carry_missing_days);

        return $this->periodPayload(
            month: $month,
            start: $start,
            end: $end,
            mode: 'custom_cutoff',
            label: 'Custom Cut-Off Salary Cycle',
            excludedDates: $excludedDates,
            cycleEndDay: (int) ($setting->cycle_end_day ?: 30),
            carryMissingDays: (bool) $setting->carry_missing_days
        );
    }

    public function getCarriedForwardDates(CarbonInterface|string $salaryMonth): array
    {
        return $this->getSalaryPeriod($salaryMonth)['excluded_dates'];
    }

    public function getExcludedDates(CarbonInterface $month, CarbonInterface $periodEnd, bool $carryMissingDays = true): Collection
    {
        if (! $carryMissingDays) {
            return collect();
        }

        $lastDay = $month->copy()->endOfMonth();
        if ($periodEnd->gte($lastDay)) {
            return collect();
        }

        $dates = collect();
        for ($date = $periodEnd->copy()->addDay(); $date->lte($lastDay); $date->addDay()) {
            $dates->push($date->copy()->toDateString());
        }

        return $dates;
    }

    public function validateSalaryPeriod(array $period): void
    {
        if (Carbon::parse($period['salary_period_start'])->gt(Carbon::parse($period['salary_period_end']))) {
            throw ValidationException::withMessages(['month' => 'Salary period start date cannot be after end date.']);
        }
    }

    public function previewSalaryPeriod(CarbonInterface|string $salaryMonth): array
    {
        $period = $this->getSalaryPeriod($salaryMonth);
        $this->validateSalaryPeriod($period);

        return $period;
    }

    private function customEndDateForMonth(CarbonInterface $month, int $cycleEndDay): Carbon
    {
        $month = Carbon::parse($month)->startOfMonth();
        $cycleEndDay = max(1, min(31, $cycleEndDay));
        $previousMonthDays = $month->copy()->subMonthNoOverflow()->daysInMonth;
        $overflowDaysFromPreviousMonth = max(0, $previousMonthDays - $cycleEndDay);
        $endDay = max(1, $cycleEndDay - $overflowDaysFromPreviousMonth);
        $endDay = min($endDay, $month->daysInMonth);

        return $month->copy()->day($endDay);
    }

    private function periodPayload(
        CarbonInterface $month,
        CarbonInterface $start,
        CarbonInterface $end,
        string $mode,
        string $label,
        Collection $excludedDates,
        ?int $cycleEndDay = null,
        bool $carryMissingDays = true
    ): array {
        $previousMonthDays = $this->countDatesInMonth($start, $end, $month->copy()->subMonthNoOverflow());
        $currentMonthDays = $this->countDatesInMonth($start, $end, $month);

        return [
            'salary_cycle_mode' => $mode,
            'salary_cycle_label' => $label,
            'salary_month' => $month->toDateString(),
            'salary_month_label' => $month->format('F Y'),
            'salary_period_start' => $start->copy()->toDateString(),
            'salary_period_end' => $end->copy()->toDateString(),
            'period_start' => $start->copy(),
            'period_end' => $end->copy(),
            'salary_period_days' => $start->diffInDays($end) + 1,
            'carried_from_previous_month_days' => $previousMonthDays,
            'current_month_days_included' => $currentMonthDays,
            'carried_to_next_month_days' => $excludedDates->count(),
            'excluded_dates' => $excludedDates->values()->all(),
            'excluded_date_labels' => $excludedDates->map(fn (string $date) => Carbon::parse($date)->format('d M Y'))->values()->all(),
            'cycle_end_day' => $cycleEndDay,
            'carry_missing_days' => $carryMissingDays,
        ];
    }

    private function countDatesInMonth(CarbonInterface $start, CarbonInterface $end, CarbonInterface $month): int
    {
        $count = 0;
        $monthKey = $month->format('Y-m');
        for ($date = Carbon::parse($start)->copy(); $date->lte($end); $date->addDay()) {
            if ($date->format('Y-m') === $monthKey) {
                $count++;
            }
        }

        return $count;
    }
}
