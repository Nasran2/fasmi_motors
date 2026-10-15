<?php

namespace App\Services;

use App\Models\SalaryCycleSetting;
use App\Models\Technician;
use App\Models\TechnicianSalarySheet;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class SalaryCycleService
{
    public function getSalaryPeriod(CarbonInterface|string $salaryMonth, ?Technician $technician = null): array
    {
        $month = Carbon::parse($salaryMonth)->startOfMonth();
        $mode = $technician->salary_period_mode ?? 'system_default';
        if ($mode === 'normal_calendar') {
            return $this->getNormalCalendarPeriod($month) + ['salary_period_source' => 'staff'];
        }
        if ($mode === 'custom_start') {
            return $this->getCustomStartPeriod($month, (int) $technician->salary_start_day) + ['salary_period_source' => 'staff'];
        }
        $setting = SalaryCycleSetting::current();

        $period = $setting->isCustomCutoffActive()
            ? $this->getCustomCutoffPeriod($month, $setting)
            : $this->getNormalCalendarPeriod($month);

        return $period + ['salary_period_source' => 'system_default'];
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

    public function getCustomStartPeriod(CarbonInterface|string $salaryMonth, int $startDay): array
    {
        if ($startDay < 1 || $startDay > 31) {
            throw ValidationException::withMessages(['salary_start_day' => 'Choose a salary start day from 1 to 31.']);
        }
        $month = Carbon::parse($salaryMonth)->startOfMonth();
        if ($startDay === 1) {
            return $this->getNormalCalendarPeriod($month);
        }
        $previous = $month->copy()->subMonthNoOverflow();
        $start = $previous->copy()->day(min($startDay, $previous->daysInMonth));
        $end = $month->copy()->day(min($startDay, $month->daysInMonth))->subDay();

        return $this->periodPayload(
            month: $month, start: $start, end: $end, mode: 'custom_start',
            label: 'Custom Start Day Salary Cycle', excludedDates: $this->getExcludedDates($month, $end),
        ) + ['salary_start_day' => $startDay];
    }

    public function getCarriedForwardDates(CarbonInterface|string $salaryMonth, ?Technician $technician = null): array
    {
        return $this->getSalaryPeriod($salaryMonth, $technician)['excluded_dates'];
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

    public function previewSalaryPeriod(CarbonInterface|string $salaryMonth, ?Technician $technician = null): array
    {
        $period = $this->getSalaryPeriod($salaryMonth, $technician);
        $this->validateSalaryPeriod($period);

        return $period;
    }

    public function sheetSalaryMonth(TechnicianSalarySheet $sheet): string
    {
        return Carbon::parse(data_get($sheet->calculation_snapshot, 'salary_cycle.salary_month', $sheet->period_end))->format('Y-m');
    }

    /** @return Collection<int, TechnicianSalarySheet> */
    public function conflictingSheets(int $technicianId, array $period, bool $lock = false): Collection
    {
        return TechnicianSalarySheet::where('technician_id', $technicianId)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()
            ->filter(fn (TechnicianSalarySheet $sheet) => $this->sheetSalaryMonth($sheet) === Carbon::parse($period['salary_month'])->format('Y-m')
                || (Carbon::parse($sheet->period_start)->toDateString() <= $period['salary_period_end']
                    && Carbon::parse($sheet->period_end)->toDateString() >= $period['salary_period_start']));
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
            'salary_period_days' => (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1,
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
