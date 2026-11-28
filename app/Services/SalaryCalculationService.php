<?php

namespace App\Services;

use App\Models\HolidayDate;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianCommission;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use App\Support\StaffPayRate;
use App\Support\StaffWorkSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalaryCalculationService
{
    public function __construct(
        private readonly AttendanceCalculationService $attendance,
        private readonly HolidayService $holidays,
        private readonly SalaryCycleService $salaryCycle,
    ) {}

    public function getTechnicianSalaryData(int $technicianId): array
    {
        $technician = Technician::findOrFail($technicianId);

        return [
            'id' => $technician->id,
            'name' => $technician->name,
            'basic_salary' => (float) $technician->basic_salary,
            'epf_etf_basic_salary' => (float) $technician->epf_etf_basic_salary,
            'salary_type' => $technician->salary_type,
            'hourly_salary' => (float) $technician->hourly_salary,
            'salary_period_mode' => $technician->salary_period_mode ?? 'system_default',
            'salary_start_day' => $technician->salary_start_day,
            'work_schedule' => StaffWorkSchedule::resolve($technician),
            'daily_salary' => $this->dailySalary($technician),
            'ot_rate' => (float) $technician->ot_rate,
            'late_deduction_rate' => (float) $technician->late_deduction_rate,
            'no_pay_per_day' => $this->dailySalary($technician),
            'epf_eligible' => (bool) $technician->epf_eligible,
            'etf_eligible' => (bool) $technician->etf_eligible,
            'employee_epf_percentage' => (float) $technician->epf_employee_percentage,
            'company_epf_percentage' => (float) $technician->epf_company_percentage,
            'company_etf_percentage' => (float) $technician->etf_company_percentage,
        ];
    }

    public function getAttendanceSummary(int $technicianId, CarbonInterface|string $periodStart, CarbonInterface|string|null $periodEnd = null): array
    {
        $start = Carbon::parse($periodStart)->startOfDay();
        $end = $periodEnd ? Carbon::parse($periodEnd)->startOfDay() : $start->copy()->endOfMonth();
        $records = TechnicianAttendance::where('technician_id', $technicianId)
            ->whereBetween('date', [$start, $end])->get();
        $holidayDates = HolidayDate::whereBetween('holiday_date', [$start, $end])
            ->where('is_active', true)->where('is_leave_enabled', true)->get()
            ->keyBy(fn (HolidayDate $holiday) => $holiday->holiday_date->toDateString());

        $closedDates = [];
        $calendar = ['weekly_leave_days' => 0, 'public_holiday_days' => 0, 'poya_holiday_days' => 0, 'custom_holiday_days' => 0, 'total_working_days' => 0];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $holiday = $holidayDates->get($key);
            if ($holiday) {
                $closedDates[$key] = true;
                $type = strtolower((string) $holiday->holiday_type);
                $calendar[str_contains($type, 'poya') ? 'poya_holiday_days' : (str_contains($type, 'public') ? 'public_holiday_days' : 'custom_holiday_days')]++;

                continue;
            }
            if ($this->holidays->isWeeklyLeave($date)) {
                $closedDates[$key] = true;
                $calendar['weekly_leave_days']++;

                continue;
            }
            $calendar['total_working_days']++;
        }

        $recordSummary = $this->attendance->summarize($records);
        $unpaidRows = $records->filter(fn ($row) => in_array($row->status, ['Absent', 'No Pay Leave'], true)
            && ! $row->is_leave_day && ! isset($closedDates[$row->date->toDateString()]));
        $explicitAbsent = $unpaidRows->count();
        $approvedLeave = $records->whereIn('status', ['Leave', 'Paid Leave'])->where('is_leave_day', false)->count();
        $presentStatuses = ['Present', 'Late', 'Early Leave', 'Auto Closed', 'Worked on Leave Day'];
        $fullPresent = $records->whereIn('status', $presentStatuses)->count();
        $halfDays = $records->whereIn('status', ['Half Day', 'Morning Half Day', 'Evening Half Day'])->count();
        $technician = Technician::findOrFail($technicianId);
        $noPayRate = $this->dailySalary($technician);
        $settings = SalarySetting::firstOrCreate([]);
        $timing = app(AttendanceTimingPenaltyService::class)->summarize($technician, $records, $closedDates, $settings);
        $timingHalfDays = count($timing['penalties']);
        $halfDayRate = round($noPayRate / 2, 2);
        $halfDayRows = $records->whereIn('status', AttendanceCalculationService::HALF_DAY_STATUSES)
            ->filter(fn ($row) => ! $row->is_leave_day && ! isset($closedDates[$row->date->toDateString()]));
        $overrideAdjustment = $records->where('is_manual_edit', true)->sum(function ($record) use ($noPayRate, $halfDayRate, $closedDates, $settings) {
            $closed = $record->is_leave_day || isset($closedDates[$record->date->toDateString()]);
            if (in_array($record->status, ['Absent', 'No Pay Leave'], true)) {
                $calculated = $closed ? 0 : $noPayRate;
            } elseif (in_array($record->status, AttendanceCalculationService::HALF_DAY_STATUSES, true)) {
                $calculated = $closed ? 0 : $halfDayRate;
            } else {
                $calculated = ($settings->late_deduction_type === 'Half day per occurrences' ? 0 : (float) $record->late_deduction_amount)
                    + ($settings->early_leave_deduction_type === 'Half day per occurrences' ? 0 : (float) $record->early_leave_deduction_amount)
                    + (float) $record->half_day_deduction_amount;
            }

            return (float) $record->deduction_amount - $calculated;
        });

        $baseWorkingDays = (float) $calendar['total_working_days'];
        $baseLeaveDays = (float) ($calendar['weekly_leave_days'] + $calendar['public_holiday_days'] + $calendar['poya_holiday_days'] + $calendar['custom_holiday_days'] + $approvedLeave);
        $halfDayAdjustment = ($halfDays + $timingHalfDays) * 0.5;
        $leaveDays = $baseLeaveDays + $halfDayAdjustment;
        $presentDays = max(0, $fullPresent + $halfDays * 0.5 - $timingHalfDays * 0.5);

        return [
            'total_month_days' => $start->diffInDays($end) + 1,
            'salary_period_days' => $start->diffInDays($end) + 1,
            'calendar_working_days' => $calendar['total_working_days'],
            'total_working_days' => $calendar['total_working_days'],
            'weekly_leave_days' => $calendar['weekly_leave_days'],
            'public_holiday_days' => $calendar['public_holiday_days'],
            'poya_leave_days' => $calendar['poya_holiday_days'],
            'custom_holiday_days' => $calendar['custom_holiday_days'],
            'approved_leave_days' => $approvedLeave,
            'leave_days' => $leaveDays,
            'present_days' => $presentDays,
            'worked_days' => $fullPresent + ($halfDays * .5),
            // Missing rows are not silently treated as absence: imports/manual attendance must
            // explicitly create an Absent row so an incomplete import cannot underpay staff.
            'absent_days' => $explicitAbsent,
            'half_days' => $halfDays + $timingHalfDays,
            'attendance_half_days' => $halfDays,
            'timing_half_day_penalties' => $timing['penalties'],
            'late_days' => $settings->late_deduction_type === 'Half day per occurrences' ? $timing['late'] : $records->where('late_minutes', '>', 0)->count(),
            'early_leave_days' => $settings->early_leave_deduction_type === 'Half day per occurrences' ? $timing['early'] : $records->where('early_leave_minutes', '>', 0)->count(),
            'auto_checkout_days' => $records->where('is_auto_checkout', true)->count(),
            'worked_on_leave_days' => $records->where('worked_on_leave_day', true)->count(),
            'total_worked_hours' => round((float) $records->sum('working_minutes') / 60, 2),
            'total_ot_hours' => round((float) $records->sum('ot_minutes') / 60, 2),
            'total_ot_amount' => round((float) $records->sum('ot_amount'), 2),
            'leave_work_regular_minutes' => (int) $records->where('worked_on_leave_day', true)->sum(fn ($row) => max(0, (int) $row->working_minutes - (int) ($row->actual_ot_minutes ?? $row->ot_minutes))),
            'total_worked_minutes' => (int) $records->sum('working_minutes'),
            'total_ot_minutes' => (int) $records->sum('ot_minutes'),
            'total_actual_ot_minutes' => $recordSummary['actual_ot_minutes'],
            'hourly_manual_deduction' => round((float) $records->where('is_manual_edit', true)->sum('deduction_amount'), 2),
            'hourly_ot_amount' => round((float) $records->sum(fn ($record) => $record->is_manual_edit || $settings->ot_calculation_type !== 'Fixed amount per hour'
                ? (float) $record->ot_amount
                : round((float) $record->ot_minutes / 60 * (float) ((float) $technician->ot_rate > 0 ? $technician->ot_rate : $technician->hourly_salary), 2)), 2),
            // Rebuild status-based deductions from current staff rates, including older rows
            // saved with zero half-day amounts. Do not also deduct late/early fines on half days.
            'late_deduction' => $settings->late_deduction_type === 'Half day per occurrences' ? 0.0 : round((float) $records->whereNotIn('status', AttendanceCalculationService::HALF_DAY_STATUSES)->sum('late_deduction_amount'), 2),
            'early_leave_deduction' => $settings->early_leave_deduction_type === 'Half day per occurrences' ? 0.0 : round((float) $records->whereNotIn('status', AttendanceCalculationService::HALF_DAY_STATUSES)->sum('early_leave_deduction_amount'), 2),
            'half_day_deduction' => round($halfDayRows->count() * $halfDayRate + array_sum(array_column($timing['penalties'], 'amount')), 2),
            'attendance_override_adjustment' => round((float) $overrideAdjustment, 2),
            'payment_rules' => $settings->only(['enable_late_deduction', 'late_deduction_type', 'late_grace_minutes', 'late_occurrences_per_half_day',
                'enable_early_leave_deduction', 'early_leave_deduction_type', 'early_leave_grace_minutes', 'early_occurrences_per_half_day',
                'enable_ot', 'ot_calculation_type', 'ot_slab_minutes', 'ot_amount_per_slab', 'ot_round_method', 'ot_grace_minutes_after_closing', 'leave_work_salary_divisor']),
        ];
    }

    public function getAdvanceSummary(int $technicianId, CarbonInterface|string $month): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $rows = TechnicianAdvance::where('technician_id', $technicianId)
            ->where('status', 'Pending Deduction')->whereDate('deduct_month', $start)->orderBy('payment_date')->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => $row->id, 'number' => $row->number, 'date' => Carbon::parse($row->payment_date)->toDateString(),
                'note' => $row->note, 'amount' => (float) $row->amount,
            ];
        }

        return ['total_amount' => round((float) $rows->sum('amount'), 2), 'count' => $rows->count(), 'items' => $items];
    }

    public function getTechnicianPendingDeductions(int $technicianId, CarbonInterface|string $month, CarbonInterface|string|null $periodEnd = null): array
    {
        $start = Carbon::parse($month)->startOfMonth();
        $end = $periodEnd ? Carbon::parse($periodEnd)->endOfDay() : $start->copy()->endOfMonth();
        $manual = TechnicianDeduction::where('technician_id', $technicianId)->where('status', 'pending')
            ->where(fn ($query) => $query->whereNull('source_type')->orWhereNotIn('source_type', SalaryBalanceService::SOURCES))
            ->whereDate('deduction_date', '<=', $end)->orderBy('deduction_date')->get();
        $bike = TechnicianBikeDeduction::with(['invoice', 'jobCard', 'bike'])->where('technician_id', $technicianId)->where('status', 'Active')
            ->whereDate('next_deduction_month', '<=', $start)->orderBy('next_deduction_month')->orderBy('id')->get();

        $items = [];
        foreach ($manual as $row) {
            $items[] = [
                'id' => $row->id, 'kind' => 'technician_deduction', 'source' => $row->source_type ?: 'manual',
                'reference_no' => $row->reference_no, 'name' => $row->deduction_name,
                'date' => Carbon::parse($row->deduction_date)->toDateString(), 'reason' => $row->reason,
                'amount' => (float) $row->amount, 'status' => $row->status,
            ];
        }
        $invoiceBalances = [];
        foreach ($bike as $row) {
            $remaining = max(0, (float) $row->total_amount - (float) $row->deducted_amount);
            $repairTotal = (float) $row->total_amount;
            $invoiceBalance = $remaining;
            $invoiceNumber = null;
            if ($row->invoice_id) {
                if (! $row->invoice || $row->invoice->status === 'Cancelled') {
                    continue;
                }
                $invoiceBalances[$row->invoice_id] ??= max(0, (float) $row->invoice->due_amount);
                $repairTotal = (float) $row->invoice->total;
                $invoiceBalance = (float) $row->invoice->due_amount;
                $invoiceNumber = $row->invoice->number;
                $remaining = min($remaining, $invoiceBalances[$row->invoice_id]);
                $invoiceBalances[$row->invoice_id] -= $remaining;
            }
            $amount = $row->deduction_type === 'Full deduction' ? $remaining : min($remaining, (float) $row->installment_amount);
            if ($remaining > 0) {
                $items[] = [
                    'id' => $row->id, 'kind' => 'bike_installment', 'source' => 'own_bike_repair',
                    'reference_no' => $row->number, 'name' => 'Own bike repair instalment',
                    'date' => Carbon::parse($row->next_deduction_month)->toDateString(), 'reason' => $row->note,
                    'amount' => $amount, 'status' => 'pending',
                    'job_number' => $row->jobCard?->number, 'bike_number' => $row->bike?->number ?? $row->jobCard?->bike?->number,
                    'invoice_number' => $invoiceNumber, 'repair_total' => $repairTotal,
                    'remaining_balance' => round($remaining, 2), 'invoice_balance' => $invoiceBalance,
                    'next_deduction_month' => Carbon::parse($row->next_deduction_month)->toDateString(),
                ];
            }
        }

        return ['total_amount' => round((float) collect($items)->sum('amount'), 2), 'count' => count($items), 'items' => $items];
    }

    public function calculateGrossSalary(float $attendanceSalary, float $ot, float $commission, float $allowances, float $manualAdditions, float $workedOnLeavePay = 0): float
    {
        return round($attendanceSalary + $ot + $commission + $allowances + $manualAdditions + $workedOnLeavePay, 2);
    }

    public function calculateTotalDeductions(array $deductions): float
    {
        return round((float) collect($deductions)->sum(), 2);
    }

    public function calculateNetPayable(float $grossSalary, float $totalDeductions): float
    {
        return max(0, round($grossSalary - $totalDeductions, 2));
    }

    public function calculateBalance(float $netPayable, float $paidAmount): array
    {
        $balance = round($netPayable - $paidAmount, 2);

        return ['balance' => $balance, 'overpaid_amount' => max(0, -$balance)];
    }

    public function generateSalaryPreview(int $technicianId, CarbonInterface|string $month, array $custom = []): array
    {
        return $this->generateMonthlySalary(Technician::findOrFail($technicianId), $month, $custom, isPreview: true);
    }

    public function generateMonthlySalary(Technician $technician, CarbonInterface|string $month, array $custom = [], bool $isPreview = false, ?TechnicianSalarySheet $settledSheet = null): array
    {
        $salaryMonth = Carbon::parse($month)->startOfMonth();
        $cycle = $this->salaryCycle->previewSalaryPeriod($salaryMonth, $technician);
        $start = Carbon::parse($cycle['salary_period_start'])->startOfDay();
        $end = Carbon::parse($cycle['salary_period_end'])->startOfDay();
        $attendance = $this->getAttendanceSummary($technician->id, $start, $end);
        // A finalized sheet already recovered these amounts. Recalculation must
        // retain them without selecting new debts or settling the same bill twice.
        $advance = $settledSheet
            ? array_replace(data_get($settledSheet->calculation_snapshot, 'advance_summary', ['items' => [], 'count' => 0]), ['total_amount' => (float) $settledSheet->total_advance_deduction])
            : $this->getAdvanceSummary($technician->id, $salaryMonth);
        $pendingDeductions = $settledSheet
            ? data_get($settledSheet->calculation_snapshot, 'pending_deductions', ['items' => []])
            : $this->getTechnicianPendingDeductions($technician->id, $salaryMonth, $end);
        if (! $settledSheet) {
            $pendingDeductions['items'] = $this->selectBikeAmounts($pendingDeductions['items'], $custom['bike_installments'] ?? []);
        }
        $pendingDeductions['total_amount'] = round((float) collect($pendingDeductions['items'])->sum('amount'), 2);
        $dailySalary = $this->dailySalary($technician);
        $regularHours = max(0, $attendance['total_worked_minutes'] - $attendance['total_actual_ot_minutes'] - ($technician->salary_type === 'Hourly' ? $attendance['leave_work_regular_minutes'] : 0)) / 60;
        $paidLeaveHours = $attendance['approved_leave_days'] * (float) StaffWorkSchedule::resolve($technician)['working_hours'];
        if ($technician->salary_type === 'Hourly' && (float) $technician->hourly_salary <= 0) {
            throw ValidationException::withMessages(['hourly_salary' => 'Set this staff member’s hourly pay rate before generating salary.']);
        }
        $attendanceSalary = match ($technician->salary_type) {
            'Daily' => (float) $technician->basic_salary,
            'Weekly' => round((float) $technician->basic_salary * $attendance['salary_period_days'] / 7, 2),
            'Hourly' => round((float) $technician->hourly_salary * ($regularHours + $paidLeaveHours), 2),
            default => (float) $technician->basic_salary,
        };
        $otAmount = $technician->salary_type === 'Hourly' ? $attendance['hourly_ot_amount'] : $attendance['total_ot_amount'];
        $attendanceAdjustment = $attendance['attendance_override_adjustment'];
        // Commission has its own deductions and payout ledger; it is never salary earnings.
        $commissions = collect();
        $commission = 0.0;
        $allowances = max(0, (float) ($custom['allowances'] ?? $custom['other_allowances'] ?? 0));
        $manualAdditions = max(0, (float) ($custom['manual_additions'] ?? $custom['manual_addition'] ?? 0));
        $otherDeductions = max(0, (float) ($custom['other_deductions'] ?? 0));
        $manualDeductions = max(0, (float) ($custom['manual_deductions'] ?? $custom['manual_deduction'] ?? 0));
        $paymentSettings = SalarySetting::firstOrCreate([]);
        $leaveWorkRate = round((float) $technician->basic_salary / max(0.01, (float) $paymentSettings->leave_work_salary_divisor), 2);
        $workedOnLeaveAuto = round($attendance['worked_on_leave_days'] * $leaveWorkRate, 2);
        $workedOnLeaveManual = max(0, (float) ($custom['worked_on_leave_pay'] ?? 0));
        $workedOnLeavePay = round($workedOnLeaveAuto + $workedOnLeaveManual, 2);
        $paidAmount = max(0, (float) ($custom['paid_amount'] ?? 0));
        $noPayRate = $dailySalary;
        $noPay = $technician->salary_type === 'Hourly' ? 0 : round($attendance['absent_days'] * $noPayRate, 2);
        $contributions = $this->calculateEPFETF($technician);
        $gross = $this->calculateGrossSalary($attendanceSalary, $otAmount, $commission, $allowances, $manualAdditions, $workedOnLeavePay);
        $deductionParts = [
            'late' => $attendance['late_deduction'], 'early_leave' => $attendance['early_leave_deduction'],
            'no_pay' => $noPay, 'half_day' => $attendance['half_day_deduction'], 'advance' => $advance['total_amount'],
            'employee_epf' => $contributions['employee_epf_amount'], 'job_bike' => $pendingDeductions['total_amount'],
            'other' => $otherDeductions, 'manual' => $manualDeductions,
            'attendance_adjustment' => $attendanceAdjustment,
        ];
        if ($technician->salary_type === 'Hourly') {
            // Actual hours already account for late arrivals, absence and half days.
            $deductionParts['late'] = $deductionParts['early_leave'] = $deductionParts['half_day'] = 0;
            $deductionParts['attendance_adjustment'] = $attendance['hourly_manual_deduction'];
        }
        $manualItems = collect($pendingDeductions['items'])->where('kind', 'technician_deduction');
        $deductionParts['job_bike'] = (float) $manualItems->sum('amount');
        $fixedDeductions = $this->calculateTotalDeductions($deductionParts);
        if (! $isPreview && $fixedDeductions > $gross) {
            throw ValidationException::withMessages(['bike_installments' => 'Deductions exceed gross salary. Reduce the selected deductions before saving.']);
        }
        $availableForDebt = round(max(0, $gross - $fixedDeductions), 2);
        $debt = $settledSheet ? [
            'items' => data_get($settledSheet->calculation_snapshot, 'old_balances', []),
            'repairs' => collect($pendingDeductions['items'])->where('kind', 'bike_installment')->values()->all(),
            'summary' => data_get($settledSheet->calculation_snapshot, 'debt_summary', []) + ['applied_old_balance' => $settledSheet->old_balance_deduction],
        ] : app(SalaryBalanceService::class)->allocate($technician->id, $salaryMonth, $custom,
            collect($pendingDeductions['items'])->where('kind', 'bike_installment')->values()->all(), $availableForDebt);
        $pendingDeductions['items'] = $manualItems->concat($debt['repairs'])->values()->all();
        $pendingDeductions['total_amount'] = round((float) collect($pendingDeductions['items'])->sum('amount'), 2);
        $deductionParts['job_bike'] = $pendingDeductions['total_amount'];
        $deductionParts['old_balance'] = $debt['summary']['applied_old_balance'];
        if ($settledSheet) {
            $deductionParts['job_bike'] = $pendingDeductions['total_amount'] = (float) $settledSheet->total_job_deduction;
        }
        $totalDeductions = $this->calculateTotalDeductions($deductionParts);
        if ($settledSheet && $totalDeductions > $gross) {
            throw ValidationException::withMessages(['reason' => 'Recalculation would make deductions exceed gross salary. Correct the applied deductions before recalculating; no changes were saved.']);
        }
        $net = $this->calculateNetPayable($gross, $totalDeductions);
        $balance = $this->calculateBalance($net, $paidAmount);
        $technicianData = $this->getTechnicianSalaryData($technician->id);
        $otBasis = $paymentSettings->ot_calculation_type === 'Fixed amount per slab'
            ? 'OT Rs. '.number_format((float) $paymentSettings->ot_amount_per_slab, 2).' per '.$paymentSettings->ot_slab_minutes.'-minute interval'
            : 'OT uses '.$paymentSettings->ot_calculation_type;
        $payBasis = match ($technician->salary_type) {
            'Weekly' => 'Rs. '.number_format((float) $technician->basic_salary, 2).' per week × '.$attendance['salary_period_days'].' calendar days ÷ 7',
            'Hourly' => number_format($regularHours, 2).' regular hours + '.number_format($paidLeaveHours, 2).' paid leave hours × Rs. '.number_format((float) $technician->hourly_salary, 2).'/hour; '.$otBasis,
            'Daily' => 'Rs. '.number_format((float) $technician->basic_salary, 2).' full basic salary; unpaid leave / absence: '.$attendance['absent_days'].' day(s) × Rs. '.number_format($noPayRate, 2),
            default => 'Rs. '.number_format((float) $technician->basic_salary, 2).' monthly basic salary',
        };

        return [
            'pay_basis' => $payBasis,
            'old_balances' => $debt['items'], 'debt_summary' => $debt['summary'],
            'period_start' => $start, 'period_end' => $end,
            'salary_cycle_mode' => $cycle['salary_cycle_mode'],
            'salary_cycle_label' => $cycle['salary_cycle_label'],
            'salary_month' => $salaryMonth->toDateString(),
            'salary_month_label' => $cycle['salary_month_label'],
            'salary_period_start' => $cycle['salary_period_start'],
            'salary_period_end' => $cycle['salary_period_end'],
            'salary_period_days' => $cycle['salary_period_days'],
            'carried_from_previous_month_days' => $cycle['carried_from_previous_month_days'],
            'current_month_days_included' => $cycle['current_month_days_included'],
            'carried_to_next_month_days' => $cycle['carried_to_next_month_days'],
            'excluded_dates' => $cycle['excluded_dates'],
            'excluded_date_labels' => $cycle['excluded_date_labels'],
            'basic_salary' => (float) $technician->basic_salary, 'daily_salary' => $dailySalary,
            'attendance_salary_amount' => round($attendanceSalary, 2),
            'working_days' => $attendance['total_working_days'], 'worked_days' => $attendance['worked_days'],
            'present_days' => $attendance['present_days'], 'absent_days' => $attendance['absent_days'], 'half_days' => $attendance['half_days'],
            'weekly_leave_days' => $attendance['weekly_leave_days'], 'public_holiday_days' => $attendance['public_holiday_days'],
            'poya_holiday_days' => $attendance['poya_leave_days'], 'custom_holiday_days' => $attendance['custom_holiday_days'],
            'approved_leave_days' => $attendance['approved_leave_days'], 'auto_checkout_days' => $attendance['auto_checkout_days'],
            'salary_month_days' => $attendance['total_month_days'], 'total_working_days' => $attendance['total_working_days'],
            'total_present_days' => $attendance['present_days'], 'total_absent_days' => $attendance['absent_days'],
            'leave_days' => $attendance['leave_days'], 'late_days' => $attendance['late_days'],
            'early_leave_days' => $attendance['early_leave_days'], 'worked_on_leave_days' => $attendance['worked_on_leave_days'],
            'total_worked_hours' => $attendance['total_worked_hours'], 'total_ot_hours' => $attendance['total_ot_hours'],
            'leave_summary' => ['weekly_leaves' => $attendance['weekly_leave_days'], 'public_holidays' => $attendance['public_holiday_days'], 'poya_holidays' => $attendance['poya_leave_days'], 'custom_holidays' => $attendance['custom_holiday_days'], 'approved_leaves' => $attendance['approved_leave_days']],
            'ot_hours' => $attendance['total_ot_hours'], 'ot_amount' => $otAmount, 'commission' => $commission,
            'allowances' => $allowances, 'manual_additions' => $manualAdditions, 'worked_on_leave_pay' => $workedOnLeavePay,
            'gross_salary' => $gross, 'advances' => $advance['total_amount'], 'total_advance_deduction' => $advance['total_amount'],
            'late_deduction' => $deductionParts['late'], 'early_leave_deduction' => $deductionParts['early_leave'],
            'half_day_deduction' => $deductionParts['half_day'], 'no_pay_deduction' => round($noPay, 2),
            'own_bike_deduction' => $pendingDeductions['total_amount'], 'total_job_deduction' => $pendingDeductions['total_amount'],
            'other_deductions' => $otherDeductions, 'total_other_deduction' => $otherDeductions,
            'manual_deductions' => $manualDeductions, 'deductions' => round($totalDeductions - $contributions['employee_epf_amount'], 2),
            'total_deductions' => $totalDeductions, 'epf_employee' => $contributions['employee_epf_amount'],
            'epf_company' => $contributions['company_epf_amount'], 'etf_company' => $contributions['company_etf_amount'],
            'net_payable' => $net, 'paid_amount' => $paidAmount, 'balance_amount' => $balance['balance'], 'overpaid_amount' => $balance['overpaid_amount'],
            'technician' => $technicianData, 'salary_cycle' => $cycle, 'attendance' => $attendance, 'attendance_summary' => $attendance, 'advance_summary' => $advance,
            'pending_deductions' => $pendingDeductions, 'earnings' => ['attendance_salary' => round($attendanceSalary, 2), 'ot' => $otAmount, 'commission' => $commission, 'allowances' => $allowances, 'manual_additions' => $manualAdditions, 'worked_on_leave_pay' => $workedOnLeavePay, 'gross' => $gross],
            'deduction_breakdown' => $deductionParts,
            'job_deductions' => $pendingDeductions,
            'employee_epf' => $contributions['employee_epf_amount'],
            'company_epf' => $contributions['company_epf_amount'],
            'company_etf' => $contributions['company_etf_amount'],
            'calculation_snapshot' => ['attendance_records' => app(SalarySheetDetailService::class)->captureAttendance($technician->id, $start->toDateString(), $end->toDateString()), 'old_balances' => $debt['items'], 'debt_summary' => $debt['summary'], 'generated_at' => now()->toIso8601String(), 'salary_cycle' => $cycle, 'technician' => $technicianData, 'attendance_summary' => $attendance, 'advance_summary' => $advance, 'pending_deductions' => $pendingDeductions, 'commissions' => $commissions->map(fn ($row) => ['id' => $row->id, 'amount' => round((float) $row->amount, 2)])->all(), 'inputs' => $custom, 'earnings' => ['worked_on_leave_auto' => $workedOnLeaveAuto, 'worked_on_leave_manual' => $workedOnLeaveManual, 'worked_on_leave_days' => $attendance['worked_on_leave_days'], 'worked_on_leave_rate' => $leaveWorkRate, 'pay_basis' => $payBasis, 'gross' => $gross, 'salary_type' => $technician->salary_type, 'salary_basis' => in_array($technician->salary_type, ['Daily', 'Monthly'], true) ? 'full_basic' : 'period_earnings', 'unpaid_leave_days' => $attendance['absent_days'], 'unpaid_leave_rate' => $noPayRate, 'regular_hours' => round($regularHours, 4), 'paid_leave_hours' => $paidLeaveHours, 'hourly_rate' => (float) $technician->hourly_salary, 'weekly_rate' => $technician->salary_type === 'Weekly' ? (float) $technician->basic_salary : null], 'deductions' => $deductionParts, 'contributions' => $contributions],
        ];
    }

    private function selectBikeAmounts(array $items, array $amounts): array
    {
        $bikeIds = collect($items)->where('kind', 'bike_installment')->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (array_diff(array_map('strval', array_keys($amounts)), $bikeIds)) {
            throw ValidationException::withMessages(['bike_installments' => 'Select an eligible repair belonging to this staff member and salary month.']);
        }
        foreach ($items as &$item) {
            if ($item['kind'] !== 'bike_installment' || ! array_key_exists($item['id'], $amounts)) {
                continue;
            }
            $amount = round((float) $amounts[$item['id']], 2);
            if ($amount < 0 || $amount > $item['remaining_balance']) {
                throw ValidationException::withMessages(['bike_installments' => 'Selected repair deduction exceeds the remaining bill balance.']);
            }
            $item['amount'] = $amount;
        }
        unset($item);

        return $items;
    }

    public function updateDraftRepairAmounts(TechnicianSalarySheet $sheet, array $amounts): TechnicianSalarySheet
    {
        abort_unless($sheet->status === 'Draft', 422, 'Repair deductions are locked after salary finalization.');
        $snapshot = $sheet->calculation_snapshot ?? [];
        $month = data_get($snapshot, 'salary_cycle.salary_month', $sheet->period_end);
        $current = $this->getTechnicianPendingDeductions($sheet->technician_id, $month, $sheet->period_end);
        foreach ($current['items'] as $item) {
            if ($item['kind'] === 'bike_installment' && ! array_key_exists($item['id'], $amounts)) {
                $amounts[$item['id']] = 0;
            }
        }
        $selected = $this->selectBikeAmounts($current['items'], $amounts);
        $oldItems = collect(data_get($snapshot, 'pending_deductions.items', []));
        $oldRepair = (float) $oldItems->where('kind', 'bike_installment')->sum('amount');
        $oldBalance = (float) collect($snapshot['old_balances'] ?? [])->sum('amount');
        $fixed = round((float) $sheet->total_deductions - $oldRepair - $oldBalance, 2);
        $debt = app(SalaryBalanceService::class)->allocate($sheet->technician_id, $month, $snapshot['inputs'] ?? [],
            collect($selected)->where('kind', 'bike_installment')->values()->all(), round((float) $sheet->gross_salary - $fixed, 2));
        $items = $oldItems->reject(fn ($item) => $item['kind'] === 'bike_installment')->concat($debt['repairs'])->values();
        $snapshot['old_balances'] = $debt['items'];
        $snapshot['debt_summary'] = $debt['summary'];
        $snapshot['pending_deductions'] = ['items' => $items->all(), 'count' => $items->count(), 'total_amount' => round((float) $items->sum('amount'), 2)];
        $snapshot['inputs']['bike_installments'] = collect($debt['repairs'])->pluck('requested_amount', 'id')->all();
        $snapshot['deductions']['job_bike'] = $snapshot['pending_deductions']['total_amount'];
        $snapshot['deductions']['old_balance'] = $debt['summary']['applied_old_balance'];
        $delta = round($debt['summary']['applied_repair'] - $oldRepair, 2);
        $total = round($fixed + $debt['summary']['deducted_this_month'], 2);
        $net = round((float) $sheet->gross_salary - $total, 2);
        $sheet->update([
            'own_bike_deduction' => round((float) $sheet->own_bike_deduction + $delta, 2),
            'total_job_deduction' => round((float) $sheet->total_job_deduction + $delta, 2),
            'deductions' => round($total - (float) $sheet->epf_employee, 2), 'total_deductions' => $total,
            'net_payable' => $net, 'balance_amount' => $net, 'calculation_snapshot' => $snapshot,
        ]);

        return $sheet->fresh();
    }

    public function finalizeSalaryPayment(TechnicianSalarySheet $sheet, ?int $userId = null): TechnicianSalarySheet
    {
        return DB::transaction(function () use ($sheet, $userId) {
            $technician = Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless($sheet->status === 'Draft', 422);
            $snapshot = $sheet->calculation_snapshot ?? [];
            if ($technician->salary_type === 'Daily' && data_get($snapshot, 'earnings.salary_basis') !== 'full_basic') {
                throw ValidationException::withMessages(['month' => 'Refresh this draft to show full basic salary and separate unpaid leave/absence deductions before finalizing.']);
            }
            if (isset($snapshot['technician']) && ! array_key_exists('epf_etf_basic_salary', $snapshot['technician']) && ($technician->epf_eligible || $technician->etf_eligible)) {
                throw ValidationException::withMessages(['month' => 'Refresh this draft to calculate EPF and ETF from the separate salary basic before finalizing.']);
            }
            if (isset($snapshot['attendance_summary']) && $snapshot['attendance_summary'] != $this->getAttendanceSummary($sheet->technician_id, $sheet->period_start, $sheet->period_end)) {
                throw ValidationException::withMessages(['month' => 'Attendance changed after this draft was calculated. Regenerate the salary draft.']);
            }
            if (isset($snapshot['attendance_records']) && $snapshot['attendance_records'] != app(SalarySheetDetailService::class)->captureAttendance($sheet->technician_id, $sheet->period_start->toDateString(), $sheet->period_end->toDateString())) {
                throw ValidationException::withMessages(['month' => 'Attendance times changed after this draft was calculated. Refresh the draft attendance.']);
            }
            if (isset($snapshot['technician']) && $snapshot['technician'] != Arr::only($this->getTechnicianSalaryData($sheet->technician_id), array_keys($snapshot['technician']))) {
                throw ValidationException::withMessages(['month' => 'Staff salary settings changed. Regenerate the salary draft.']);
            }
            $salaryMonth = data_get($snapshot, 'salary_cycle.salary_month', Carbon::parse($sheet->period_end)->startOfMonth());
            $currentCycle = $this->salaryCycle->previewSalaryPeriod($salaryMonth, $technician);
            if ($currentCycle['salary_period_start'] !== Carbon::parse($sheet->period_start)->toDateString()
                || $currentCycle['salary_period_end'] !== Carbon::parse($sheet->period_end)->toDateString()) {
                throw ValidationException::withMessages(['month' => 'The salary period changed. Regenerate this draft before finalizing.']);
            }
            if ($this->salaryCycle->conflictingSheets($sheet->technician_id, $currentCycle, true)->contains(fn ($other) => $other->id !== $sheet->id)) {
                throw ValidationException::withMessages(['month' => 'Another salary sheet covers this salary month or overlaps these attendance dates. Resolve the duplicate before finalizing.']);
            }
            if (isset($snapshot['advance_summary']) && $snapshot['advance_summary'] != $this->getAdvanceSummary($sheet->technician_id, $salaryMonth)) {
                throw ValidationException::withMessages(['month' => 'Salary advances changed. Regenerate the salary draft.']);
            }
            $currentDeductions = $this->getTechnicianPendingDeductions($sheet->technician_id, $salaryMonth, $sheet->period_end);
            $currentManual = collect($currentDeductions['items'])->where('kind', 'technician_deduction')->values()->all();
            $snapshotManual = collect(data_get($snapshot, 'pending_deductions.items', []))->where('kind', 'technician_deduction')->values()->all();
            if (isset($snapshot['pending_deductions']) && $snapshotManual != $currentManual) {
                throw ValidationException::withMessages(['month' => 'Staff deductions changed. Regenerate the salary draft.']);
            }
            if (isset($snapshot['debt_summary'])) {
                $repairBalances = fn ($items) => collect($items)->where('kind', 'bike_installment')
                    ->mapWithKeys(fn ($item) => [(string) $item['id'] => round((float) $item['remaining_balance'], 2)])->sortKeys()->all();
                if ($repairBalances(data_get($snapshot, 'pending_deductions.items', [])) != $repairBalances($currentDeductions['items'])) {
                    throw ValidationException::withMessages(['month' => 'Repair balances changed after this draft was prepared. Refresh the draft before finalizing.']);
                }
            }
            if ((float) $sheet->commission !== 0.0) {
                throw ValidationException::withMessages(['month' => 'Commission is paid separately now. Refresh this draft to remove commission before finalizing salary.']);
            }
            app(SalaryBalanceService::class)->finalize($sheet, $userId);
            $advanceIds = collect(data_get($snapshot, 'advance_summary.items', []))->pluck('id')->filter();
            $advances = TechnicianAdvance::whereIn('id', $advanceIds)->lockForUpdate()->get();
            if ($advances->contains(fn ($advance) => $advance->status !== 'Pending Deduction')) {
                throw ValidationException::withMessages(['month' => 'An advance was already recovered. Regenerate this draft.']);
            }
            TechnicianAdvance::whereIn('id', $advanceIds)->where('status', 'Pending Deduction')
                ->update(['status' => 'Deducted', 'salary_sheet_id' => $sheet->id]);

            $deductionItems = collect(data_get($snapshot, 'pending_deductions.items', []));
            $manualIds = $deductionItems->where('kind', 'technician_deduction')->pluck('id')->filter();
            if (TechnicianDeduction::whereIn('id', $manualIds)->lockForUpdate()->get()->contains(fn ($deduction) => $deduction->status !== 'pending')) {
                throw ValidationException::withMessages(['month' => 'A deduction was already recovered. Regenerate this draft.']);
            }
            TechnicianDeduction::whereIn('id', $manualIds)->where('status', 'pending')
                ->update(['status' => 'deducted', 'salary_payment_id' => $sheet->id, 'updated_by' => $userId]);
            foreach ($deductionItems->where('kind', 'bike_installment') as $item) {
                $plan = TechnicianBikeDeduction::lockForUpdate()->find($item['id']);
                if (! $plan || $plan->status !== 'Active' || (int) $plan->technician_id !== (int) $sheet->technician_id) {
                    throw ValidationException::withMessages(['month' => 'The repair recovery plan changed. Regenerate this draft.']);
                }
                if (isset($item['next_deduction_month']) && Carbon::parse($plan->next_deduction_month)->toDateString() !== $item['next_deduction_month']) {
                    throw ValidationException::withMessages(['month' => 'This repair installment was already recovered. Regenerate this draft.']);
                }
                $amount = round((float) $item['amount'], 2);
                if ($amount > (float) $plan->total_amount - (float) $plan->deducted_amount) {
                    throw ValidationException::withMessages(['month' => 'The repair balance changed. Regenerate this draft.']);
                }
                if ($amount <= 0) {
                    continue;
                }
                if ($plan->invoice_id) {
                    $invoice = Invoice::lockForUpdate()->findOrFail($plan->invoice_id);
                    if ($invoice->status === 'Cancelled' || $amount > (float) $invoice->due_amount) {
                        throw ValidationException::withMessages(['month' => 'The staff repair invoice due changed. Regenerate this draft.']);
                    }
                    $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + $amount, 2), 'due_amount' => round((float) $invoice->due_amount - $amount, 2), 'status' => $amount >= (float) $invoice->due_amount ? 'Paid' : 'Partially Paid']);
                    app(SettlementService::class)->saveStatus($invoice);
                    // Salary offsets settle the receivable without another cash receipt.
                    $invoice->payments()->create(['number' => 'OFFSET-'.Str::upper(Str::random(12)), 'amount' => $amount, 'balance_after' => $invoice->due_amount, 'method' => 'Salary Deduction', 'reference' => "salary:{$sheet->id}:plan:{$plan->id}", 'note' => "Salary sheet {$sheet->number}", 'received_amount' => 0, 'salary_sheet_id' => $sheet->id, 'technician_bike_deduction_id' => $plan->id, 'paid_at' => now(), 'status' => 'Completed', 'created_by' => $userId]);
                }
                $deducted = min((float) $plan->total_amount, (float) $plan->deducted_amount + (float) $item['amount']);
                $plan->update([
                    'deducted_amount' => $deducted,
                    'status' => $deducted >= (float) $plan->total_amount ? 'Completed' : 'Active',
                    'next_deduction_month' => $deducted >= (float) $plan->total_amount ? null : Carbon::parse(data_get($snapshot, 'salary_cycle.salary_month', $sheet->period_start))->startOfMonth()->addMonth(),
                ]);
            }
            $sheet->update(['status' => 'Finalized', 'finalized_at' => now(), 'finalized_by' => $userId]);

            return $sheet->fresh();
        });
    }

    public function reopenSalaryPayment(TechnicianSalarySheet $sheet, string $reason, ?int $userId = null): TechnicianSalarySheet
    {
        return DB::transaction(function () use ($sheet, $reason, $userId) {
            Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless($sheet->status === 'Finalized' && (float) $sheet->paid_amount === 0.0, 422, 'A salary with payments cannot be reopened.');
            app(SalaryBalanceService::class)->reopen($sheet);
            TechnicianAdvance::where('salary_sheet_id', $sheet->id)->update(['status' => 'Pending Deduction', 'salary_sheet_id' => null]);
            TechnicianDeduction::where('salary_payment_id', $sheet->id)->update(['status' => 'pending', 'salary_payment_id' => null, 'updated_by' => $userId]);
            TechnicianCommission::where('salary_sheet_id', $sheet->id)->update(['status' => 'Pending', 'salary_sheet_id' => null]);
            foreach (collect(data_get($sheet->calculation_snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment') as $item) {
                if ((float) $item['amount'] <= 0) {
                    Payment::where('salary_sheet_id', $sheet->id)->where('technician_bike_deduction_id', $item['id'])
                        ->where('method', 'Salary Deduction')->where('status', 'Completed')->where('amount', 0)
                        ->update(['status' => 'Cancelled', 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'cancelled_at' => now()]);

                    continue;
                }
                $plan = TechnicianBikeDeduction::lockForUpdate()->find($item['id']);
                if ($plan) {
                    $payment = Payment::where('reference', "salary:{$sheet->id}:plan:{$plan->id}")->where('status', 'Completed')->lockForUpdate()->first();
                    if ($payment) {
                        $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
                        $invoice->update(['paid_amount' => max(0, round((float) $invoice->paid_amount - (float) $payment->amount, 2)), 'due_amount' => round((float) $invoice->due_amount + (float) $payment->amount, 2), 'status' => (float) $invoice->paid_amount > (float) $payment->amount ? 'Partially Paid' : 'Unpaid']);
                        app(SettlementService::class)->saveStatus($invoice);
                        $payment->update(['status' => 'Cancelled', 'cancel_reason' => $reason, 'cancelled_by' => $userId, 'cancelled_at' => now()]);
                    }
                    $plan->update([
                        'deducted_amount' => max(0, (float) $plan->deducted_amount - (float) $item['amount']),
                        'status' => 'Active',
                        'next_deduction_month' => $item['next_deduction_month'] ?? $sheet->period_start,
                    ]);
                }
            }
            $sheet->update(['status' => 'Draft', 'reopen_reason' => $reason, 'finalized_at' => null, 'finalized_by' => null]);

            return $sheet->fresh();
        });
    }

    public function calculateEPFETF(Technician $technician): array
    {
        $eligibleSalary = (float) $technician->epf_etf_basic_salary;

        return [
            'basic_salary' => $eligibleSalary,
            'employee_epf_percentage' => (float) $technician->epf_employee_percentage,
            'company_epf_percentage' => (float) $technician->epf_company_percentage,
            'company_etf_percentage' => (float) $technician->etf_company_percentage,
            'employee_epf_amount' => round($technician->epf_eligible ? $eligibleSalary * (float) $technician->epf_employee_percentage / 100 : 0, 2),
            'company_epf_amount' => round($technician->epf_eligible ? $eligibleSalary * (float) $technician->epf_company_percentage / 100 : 0, 2),
            'company_etf_amount' => round($technician->etf_eligible ? $eligibleSalary * (float) $technician->etf_company_percentage / 100 : 0, 2),
        ];
    }

    public function calculateNetSalary(float $grossSalary, float $deductions, float $employeeEpf): float
    {
        return $this->calculateNetPayable($grossSalary, $deductions + $employeeEpf);
    }

    private function dailySalary(Technician $technician): float
    {
        return StaffPayRate::daily($technician);
    }
}
