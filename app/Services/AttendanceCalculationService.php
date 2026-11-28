<?php

namespace App\Services;

use App\Models\OtSlabRule;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAttendance;
use App\Support\StaffPayRate;
use App\Support\StaffWorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceCalculationService
{
    public const NON_WORK_STATUSES = ['Absent', 'No Pay Leave', 'Leave', 'Paid Leave', 'Holiday', 'Holiday Leave', 'Weekly Leave', 'Poya Leave', 'No Shift', 'Not Worked'];

    public const HALF_DAY_STATUSES = ['Half Day', 'Morning Half Day', 'Evening Half Day'];

    private HolidayService $holidayService;

    public function __construct(HolidayService $holidayService)
    {
        $this->holidayService = $holidayService;
    }

    public function calculateDailyAttendance(Technician $technician, string|CarbonInterface $date, ?string $status, ?string $checkIn, ?string $checkOut, array $overrides = []): array
    {
        $settings = SalarySetting::firstOrCreate([])->refresh();
        $dateString = Carbon::parse($date)->toDateString();
        $statusKey = strtolower(trim((string) $status));
        $notWorked = $statusKey === 'not worked';
        $nonWorking = in_array($statusKey, array_map('strtolower', self::NON_WORK_STATUSES), true);
        // An explicit non-working status takes precedence over stale punches.
        $in = $nonWorking ? null : $this->time($dateString, $checkIn);
        $out = $nonWorking ? null : $this->time($dateString, $checkOut);
        if ($in && $out && $out->lt($in)) {
            throw ValidationException::withMessages(['out_time' => 'Checkout must be after check-in.']);
        }
        $schedule = StaffWorkSchedule::resolve($technician, $settings);
        $open = $this->time($dateString, $schedule['start']);
        $close = $this->time($dateString, $schedule['end']);
        $dailySalary = $this->dailySalary($technician);

        $result = [
            'status' => $status,
            'working_minutes' => 0,
            'working_hours' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'break_minutes' => max(0, (int) ($overrides['break_minutes'] ?? $settings->lunch_break_minutes ?? 0)),
            'absent_minutes' => 0,
            'actual_ot_minutes' => 0,
            'ot_minutes' => 0,
            'ot_hours' => 0,
            'ot_amount' => 0,
            'is_ot_eligible' => false,
            'late_deduction_amount' => 0,
            'early_leave_deduction_amount' => 0,
            'half_day_deduction_amount' => 0,
            'deduction_amount' => 0,
            'is_auto_checkout' => false,
            'auto_checkout_reason' => null,
            'is_leave_day' => false,
            'leave_type' => null,
            'holiday_id' => null,
            'worked_on_leave_day' => false,
        ];

        // 1. Manual overrrides are checked inside normalizeStatus/applyOverrides but wait,
        // let's apply the holiday check first.
        $cDate = Carbon::parse($date);
        $holiday = $this->holidayService->getHolidayForDate($cDate);
        $isWeeklyLeave = $this->holidayService->isWeeklyLeave($cDate);

        if ($holiday && $holiday->is_leave_enabled) {
            $result['is_leave_day'] = true;
            $result['holiday_id'] = $holiday->id;
            $result['leave_type'] = $holiday->holiday_type.' Holiday';
        } elseif ($isWeeklyLeave) {
            $result['is_leave_day'] = true;
            $result['leave_type'] = 'Weekly Leave';
        }

        if ($notWorked) {
            if (! $result['is_leave_day']) {
                throw ValidationException::withMessages(['status' => 'Not worked is for a shop-closed or holiday date. On a working day choose Absent, Paid Leave or No Pay Leave.']);
            }

            // An explicit correction must remain non-working through every recalculation.
            // Old punches and manual money overrides cannot restore accidental work.
            return array_replace($result, ['status' => 'Not Worked', 'in_time' => null, 'out_time' => null, 'break_minutes' => 0, 'is_manual_edit' => false]);
        }

        if ($nonWorking) {
            $result['break_minutes'] = 0;
            $overrides['ot_amount'] = null;
            $overrides['auto_filled_checkout'] = false;
        }

        if ($result['is_leave_day'] && strtolower(trim((string) $status)) === 'worked on leave day') {
            $in ??= $open->copy();
            if (! $out) {
                $out = $close->copy();
                $overrides['auto_filled_checkout'] = true;
            }
        }

        // Apply auto checkout logic: if starting time is given, but outer time is missing
        if ($in && (! $out || ! empty($overrides['auto_filled_checkout']))) {
            $out = $close;
            $result['is_auto_checkout'] = true;
            $result['auto_checkout_reason'] = 'Missing checkout time, used '.($schedule['mode'] === 'custom' ? 'staff scheduled' : 'system').' closing time';
        }

        $result['in_time'] = $in ? $in->format('H:i:s') : null;
        $result['out_time'] = $out ? $out->format('H:i:s') : null;

        if ($in && $out && $out->lt($in)) {
            throw ValidationException::withMessages(['out_time' => 'Checkout must be after check-in. Enter an actual checkout time for a check-in after closing.']);
        }

        $status = $this->normalizeStatus($status, $in, $out, $result['is_leave_day'], $result['leave_type'], $result['is_auto_checkout']);
        $result['status'] = $status;

        if (in_array($status, ['Absent', 'No Pay Leave'], true)) {
            if (! $result['is_leave_day']) {
                $result['absent_minutes'] = (int) round((float) $schedule['working_hours'] * 60);
                $result['deduction_amount'] = $technician->salary_type === 'Hourly' ? 0 : $dailySalary;
            } else {
                $result['status'] = $result['leave_type'];
            }

            return $this->applyOverrides($result, $overrides);
        }

        // An explicitly selected half day still costs half a day when a fingerprint is missing.
        // Punches determine hours and timing metrics, not whether this deduction exists.
        if (in_array($status, self::HALF_DAY_STATUSES, true) && ! $result['is_leave_day'] && $technician->salary_type !== 'Hourly') {
            $result['half_day_deduction_amount'] = round($dailySalary / 2, 2);
            $result['deduction_amount'] = $result['half_day_deduction_amount'];
        }

        if (in_array($status, ['Leave', 'Paid Leave', 'Holiday', 'Holiday Leave', 'Weekly Leave', 'Poya Leave', 'No Shift'], true) || ! $in || ! $out || ! $open || ! $close) {
            return $this->applyOverrides($result, $overrides);
        }

        if ($result['is_leave_day'] && in_array($status, ['Present', 'Late', 'Early Leave', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Auto Closed', 'Worked on Leave Day'])) {
            $result['worked_on_leave_day'] = true;
            $result['status'] = 'Worked on Leave Day';
        }

        $worked = max(0, (int) floor(($out->getTimestamp() - $in->getTimestamp()) / 60) - $result['break_minutes']);
        $result['working_minutes'] = $worked;
        $result['working_hours'] = round($worked / 60, 2);
        $result['late_minutes'] = $this->calculateLateMinutes($in, $open, (int) $settings->late_grace_minutes);
        $result['early_leave_minutes'] = $this->calculateEarlyLeaveMinutes($out, $close, (int) $settings->early_leave_grace_minutes);
        if ($settings->late_deduction_type === 'Half day per occurrences') {
            $result['late_minutes'] = $in->gt($this->time($dateString, $schedule['late_cutoff'])) ? max(1, (int) $open->diffInMinutes($in)) : 0;
        }
        if ($settings->early_leave_deduction_type === 'Half day per occurrences') {
            $result['early_leave_minutes'] = $out->lt($this->time($dateString, $schedule['early_cutoff'])) ? max(1, (int) $out->diffInMinutes($close)) : 0;
        }

        if ($settings->enable_half_day && ($in->gt($this->time($dateString, $schedule['morning_cutoff'])) || $out->lt($this->time($dateString, $schedule['evening_cutoff'])))) {
            $result['status'] = $in->gt($this->time($dateString, $schedule['morning_cutoff'])) ? 'Morning Half Day' : 'Evening Half Day';
        }

        if (in_array($result['status'], self::HALF_DAY_STATUSES, true)) {
            $result['half_day_deduction_amount'] = round($dailySalary / 2, 2);
        } else {
            if ($settings->enable_late_deduction && $result['late_minutes'] > 0) {
                if ($settings->late_deduction_type !== 'Half day per occurrences') {
                    $result['late_deduction_amount'] = $this->deduction((string) $settings->late_deduction_type, (float) $settings->late_deduction_amount, $result['late_minutes'], $dailySalary);
                }
                if (in_array($result['status'], ['Present', 'Auto Closed'], true)) {
                    $result['status'] = 'Late';
                }
            }
            if ($settings->enable_early_leave_deduction && $result['early_leave_minutes'] > 0) {
                if ($settings->early_leave_deduction_type !== 'Half day per occurrences') {
                    $result['early_leave_deduction_amount'] = $this->deduction((string) $settings->early_leave_deduction_type, (float) $settings->early_leave_deduction_amount, $result['early_leave_minutes'], $dailySalary);
                }
                if ($result['status'] === 'Present') {
                    $result['status'] = 'Early Leave';
                }
            }
        }

        $result['is_ot_eligible'] = ! $result['is_auto_checkout'] && $this->calculateOTEligibility($in, $out, $open, $close, (int) $settings->ot_grace_minutes_after_closing);
        if ($settings->ot_round_method === 'Started interval' && (int) $settings->ot_grace_minutes_after_closing === 0) {
            $result['is_ot_eligible'] = ! $result['is_auto_checkout'] && $in->lte($open) && $out->gte($close);
        }
        if ($settings->enable_ot && $result['is_ot_eligible']) {
            $result['actual_ot_minutes'] = $this->calculateOTMinutes($out, $close);
            $result['ot_minutes'] = $this->payableOTMinutes($result['actual_ot_minutes'], $settings);
            $result['ot_hours'] = round($result['ot_minutes'] / 60, 2);
            $result['ot_amount'] = $this->calculateOTAmount($technician, $result['ot_minutes'], $settings);
        }

        // Only apply deductions if not a leave day
        if (! $result['is_leave_day'] && $technician->salary_type !== 'Hourly') {
            $result['deduction_amount'] = $result['late_deduction_amount'] + $result['early_leave_deduction_amount'] + $result['half_day_deduction_amount'];
        } else {
            $result['late_deduction_amount'] = 0;
            $result['early_leave_deduction_amount'] = 0;
            $result['half_day_deduction_amount'] = 0;
            $result['deduction_amount'] = 0;
        }

        if ($result['worked_on_leave_day']) {
            $result['status'] = 'Worked on Leave Day';
        }

        return $this->applyOverrides($result, $overrides);
    }

    public function calculateLateMinutes(CarbonInterface $checkIn, CarbonInterface $opening, int $graceMinutes): int
    {
        return $checkIn->gt($opening->copy()->addMinutes($graceMinutes)) ? $opening->diffInMinutes($checkIn) : 0;
    }

    public function calculateEarlyLeaveMinutes(CarbonInterface $checkOut, CarbonInterface $closing, int $graceMinutes): int
    {
        return $checkOut->lt($closing->copy()->subMinutes($graceMinutes)) ? $checkOut->diffInMinutes($closing) : 0;
    }

    public function calculateOTEligibility(CarbonInterface $checkIn, CarbonInterface $checkOut, CarbonInterface $opening, CarbonInterface $closing, int $graceMinutes): bool
    {
        return $checkIn->lte($opening) && $checkOut->gt($closing->copy()->addMinutes($graceMinutes));
    }

    public function calculateOTMinutes(CarbonInterface $checkOut, CarbonInterface $closing): int
    {
        return max(0, (int) floor(($checkOut->getTimestamp() - $closing->getTimestamp()) / 60));
    }

    public function payableOTMinutes(int $actualMinutes, ?SalarySetting $settings = null): int
    {
        $settings ??= SalarySetting::firstOrCreate([]);
        $minutes = max(0, $actualMinutes);
        $interval = max(1, (int) $settings->ot_slab_minutes);
        $blocks = match ($settings->ot_round_method) {
            'Started interval' => floor($minutes / $interval) + 1,
            'Floor slab' => floor($minutes / $interval),
            'Nearest slab' => round($minutes / $interval, 0, PHP_ROUND_HALF_UP),
            'Exact minutes' => $minutes / $interval,
            default => ceil($minutes / $interval),
        };

        return (int) ($blocks * $interval);
    }

    public function calculateAttendanceMonthSummary(Technician $technician, CarbonInterface|string $month): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $records = TechnicianAttendance::where('technician_id', $technician->id)->whereBetween('date', [$start, $start->copy()->endOfMonth()])->get();

        return $this->summarize($records);
    }

    public function summarize(Collection $records): array
    {
        $presentStatuses = ['Present', 'Late', 'Early Leave', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Worked on Leave Day', 'Auto Closed'];
        $absentStatuses = ['Absent', 'No Pay Leave'];
        $leaveStatuses = ['Leave', 'Paid Leave', 'Holiday Leave', 'Weekly Leave', 'Poya Leave', 'Holiday'];

        $summary = [
            'working_days' => $records->count(),
            'worked_days' => round((float) $records->whereIn('status', $presentStatuses)->sum(fn ($row) => in_array($row->status, ['Half Day', 'Morning Half Day', 'Evening Half Day'], true) ? 0.5 : 1), 2),
            'present_days' => $records->whereIn('status', $presentStatuses)->count(),
            'absent_days' => $records->whereIn('status', $absentStatuses)->count(),
            'half_days' => $records->whereIn('status', ['Half Day', 'Morning Half Day', 'Evening Half Day'])->count(),

            'weekly_leave_days' => $records->where('leave_type', 'Weekly Leave')->count(),
            'public_holiday_days' => $records->where('leave_type', 'Public Holiday')->count(),
            'poya_holiday_days' => $records->where('leave_type', 'Poya Holiday')->count(),
            'custom_holiday_days' => $records->where('leave_type', 'Custom Holiday')->count(),
            'approved_leave_days' => $records->whereIn('status', ['Leave', 'Paid Leave'])->whereNull('leave_type')->count(),
            'auto_checkout_days' => $records->where('is_auto_checkout', true)->count(),

            'worked_minutes' => (int) $records->sum('working_minutes'),
            'ot_minutes' => (int) $records->sum('ot_minutes'),
            'actual_ot_minutes' => (int) $records->sum(fn ($row) => $row->actual_ot_minutes ?? $row->ot_minutes),
            'ot_amount' => (float) $records->sum('ot_amount'),
            'late_deduction' => (float) $records->sum('late_deduction_amount'),
            'early_leave_deduction' => (float) $records->sum('early_leave_deduction_amount'),
            'half_day_deduction' => (float) $records->sum('half_day_deduction_amount'),
            'attendance_deduction' => (float) $records->sum('deduction_amount'),
        ];

        return $summary;
    }

    private function calculateOTAmount(Technician $technician, int $minutes, SalarySetting $settings): float
    {
        if ($minutes <= 0) {
            return 0;
        }
        if ($settings->ot_calculation_type === 'Fixed amount per slab') {
            return round($minutes / max(1, (int) $settings->ot_slab_minutes) * (float) $settings->ot_amount_per_slab, 2);
        }
        if ($settings->ot_calculation_type === 'Salary-based hourly rate') {
            $hours = max(0.01, (float) StaffWorkSchedule::resolve($technician, $settings)['working_hours']);

            return round($minutes / 60 * $this->dailySalary($technician) / $hours, 2);
        }
        if ((float) $technician->ot_rate > 0) {
            return round($minutes / 60 * (float) $technician->ot_rate, 2);
        }
        if ($technician->salary_type === 'Hourly') {
            return round($minutes / 60 * (float) $technician->hourly_salary, 2);
        }
        $slabs = OtSlabRule::where('status', 'Active')->orderBy('from_minutes')->get();
        if ($slabs->isNotEmpty()) {
            $slab = $slabs->first(fn ($rule) => $minutes >= $rule->from_minutes && $minutes <= $rule->to_minutes)
                ?? $slabs->filter(fn ($rule) => $rule->to_minutes < $minutes)->last();

            if (! $slab) {
                return 0;
            }
            $amount = 0;
            foreach ($slabs as $rule) {
                $amount = $rule->calculation_rule === 'Add previous slab amount'
                    ? $amount + (float) $rule->amount : (float) $rule->amount;
                if ($rule->id === $slab->id) {
                    break;
                }
            }

            return round($amount, 2);
        }
        $slabMinutes = max(1, (int) $settings->ot_slab_minutes);
        $count = match ((string) $settings->ot_round_method) {
            'Floor slab' => floor($minutes / $slabMinutes),
            'Nearest slab' => round($minutes / $slabMinutes),
            default => ceil($minutes / $slabMinutes),
        };

        return round($count * (float) $settings->ot_amount_per_slab, 2);
    }

    private function deduction(string $type, float $amount, int $minutes, float $dailySalary): float
    {
        $type = strtolower($type);
        if (str_contains($type, 'minute')) {
            return $minutes * $amount;
        }
        if (str_contains($type, 'percentage')) {
            return $dailySalary * $amount / 100;
        }

        return $amount;
    }

    private function dailySalary(Technician $technician): float
    {
        return StaffPayRate::daily($technician);
    }

    private function normalizeStatus(?string $status, ?CarbonInterface $in, ?CarbonInterface $out, bool $isLeaveDay = false, ?string $leaveType = null, bool $isAutoCheckout = false): string
    {
        $status = strtolower(trim((string) $status));
        // File/default statuses with a punch normalize to worked attendance.
        // Explicit absence and leave were already cleared before reaching this step.
        if ($in && ($status === 'normal' || empty($status))) {
            $status = $isAutoCheckout ? 'auto closed' : 'present';
        }

        // Work statuses containing "leave" must not become approved leave.
        if (in_array($status, ['absent', 'early leave', 'half day', 'morning half day', 'evening half day', 'worked on leave day', 'auto closed'], true)) {
            return $status === 'worked on leave day' ? 'Worked on Leave Day' : ucwords($status);
        }
        if (str_contains($status, 'no shift')) {
            return 'No Shift';
        }
        if (str_contains($status, 'leave') || str_contains($status, 'holiday')) {
            // Keep provided manual leave status if explicitly set
            if (str_contains($status, 'no pay')) {
                return 'No Pay Leave';
            }
            if (str_contains($status, 'paid')) {
                return 'Paid Leave';
            }
            if (str_contains($status, 'holiday')) {
                return 'Holiday Leave';
            }
            if (str_contains($status, 'poya')) {
                return 'Poya Leave';
            }

            return 'Leave';
        }
        if (! $in && ! $out) {
            if ($isLeaveDay) {
                return $leaveType ?? 'Leave';
            }

            return 'Absent';
        }
        if (str_contains($status, 'absence') && (! $in || ! $out)) {
            if ($isLeaveDay) {
                return $leaveType ?? 'Leave';
            }

            return 'Absent';
        }
        if ($isAutoCheckout) {
            return 'Auto Closed';
        }

        return match ($status) {
            'late' => 'Late',
            'early' => 'Early Leave',
            'half day' => 'Half Day',
            default => 'Present',
        };
    }

    private function time(string $date, ?string $value): ?Carbon
    {
        if (! $value || in_array(trim($value), ['-', '—'], true)) {
            return null;
        }

        return Carbon::parse($date.' '.$value);
    }

    private function applyOverrides(array $result, array $overrides): array
    {
        if (array_key_exists('ot_amount', $overrides) && $overrides['ot_amount'] !== null) {
            $result['ot_amount'] = max(0, (float) $overrides['ot_amount']);
        }
        if (array_key_exists('deduction_amount', $overrides) && $overrides['deduction_amount'] !== null) {
            $result['deduction_amount'] = max(0, (float) $overrides['deduction_amount']);
        }

        return $result;
    }
}
