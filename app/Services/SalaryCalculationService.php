<?php

namespace App\Services;

use App\Models\HolidayDate;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianCommission;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
            'salary_type' => $technician->salary_type,
            'daily_salary' => $this->dailySalary($technician),
            'ot_rate' => (float) $technician->ot_rate,
            'late_deduction_rate' => (float) $technician->late_deduction_rate,
            'no_pay_per_day' => (float) ($technician->no_pay_deduction_rate ?: $this->dailySalary($technician)),
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

        $calendar = ['weekly_leave_days' => 0, 'public_holiday_days' => 0, 'poya_holiday_days' => 0, 'custom_holiday_days' => 0, 'total_working_days' => 0];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            $holiday = $holidayDates->get($key);
            if ($holiday) {
                $type = strtolower((string) $holiday->holiday_type);
                $calendar[str_contains($type, 'poya') ? 'poya_holiday_days' : (str_contains($type, 'public') ? 'public_holiday_days' : 'custom_holiday_days')]++;

                continue;
            }
            if ($this->holidays->isWeeklyLeave($date)) {
                $calendar['weekly_leave_days']++;

                continue;
            }
            $calendar['total_working_days']++;
        }

        $recordSummary = $this->attendance->summarize($records);
        $explicitAbsent = (int) $recordSummary['absent_days'];
        $approvedLeave = $records->whereIn('status', ['Leave', 'Paid Leave'])->where('is_leave_day', false)->count();
        $presentStatuses = ['Present', 'Late', 'Early Leave', 'Auto Closed', 'Worked on Leave Day'];
        $fullPresent = $records->whereIn('status', $presentStatuses)->count();
        $halfDays = $records->whereIn('status', ['Half Day', 'Morning Half Day', 'Evening Half Day'])->count();

        return [
            'total_month_days' => $start->diffInDays($end) + 1,
            'salary_period_days' => $start->diffInDays($end) + 1,
            'total_working_days' => $calendar['total_working_days'],
            'weekly_leave_days' => $calendar['weekly_leave_days'],
            'public_holiday_days' => $calendar['public_holiday_days'],
            'poya_leave_days' => $calendar['poya_holiday_days'],
            'custom_holiday_days' => $calendar['custom_holiday_days'],
            'approved_leave_days' => $approvedLeave,
            'leave_days' => $calendar['weekly_leave_days'] + $calendar['public_holiday_days'] + $calendar['poya_holiday_days'] + $calendar['custom_holiday_days'] + $approvedLeave,
            'present_days' => $fullPresent + $halfDays,
            'worked_days' => $fullPresent + ($halfDays * .5),
            // Missing rows are not silently treated as absence: imports/manual attendance must
            // explicitly create an Absent row so an incomplete import cannot underpay staff.
            'absent_days' => $explicitAbsent,
            'half_days' => $halfDays,
            'late_days' => $records->where('late_minutes', '>', 0)->count(),
            'early_leave_days' => $records->where('early_leave_minutes', '>', 0)->count(),
            'auto_checkout_days' => $records->where('is_auto_checkout', true)->count(),
            'worked_on_leave_days' => $records->where('worked_on_leave_day', true)->count(),
            'total_worked_hours' => round((float) $records->sum('working_minutes') / 60, 2),
            'total_ot_hours' => round((float) $records->sum('ot_minutes') / 60, 2),
            'total_ot_amount' => round((float) $records->sum('ot_amount'), 2),
            'late_deduction' => round((float) $records->sum('late_deduction_amount'), 2),
            'early_leave_deduction' => round((float) $records->sum('early_leave_deduction_amount'), 2),
            'half_day_deduction' => round((float) $records->sum('half_day_deduction_amount'), 2),
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
            ->whereDate('deduction_date', '<=', $end)->orderBy('deduction_date')->get();
        $bike = TechnicianBikeDeduction::where('technician_id', $technicianId)->where('status', 'Active')
            ->whereDate('next_deduction_month', '<=', $start)->orderBy('next_deduction_month')->get();

        $items = [];
        foreach ($manual as $row) {
            $items[] = [
                'id' => $row->id, 'kind' => 'technician_deduction', 'source' => $row->source_type ?: 'manual',
                'reference_no' => $row->reference_no, 'name' => $row->deduction_name,
                'date' => Carbon::parse($row->deduction_date)->toDateString(), 'reason' => $row->reason,
                'amount' => (float) $row->amount, 'status' => $row->status,
            ];
        }
        foreach ($bike as $row) {
            $remaining = max(0, (float) $row->total_amount - (float) $row->deducted_amount);
            $amount = min($remaining, (float) $row->installment_amount);
            if ($amount > 0) {
                $items[] = [
                    'id' => $row->id, 'kind' => 'bike_installment', 'source' => 'own_bike_repair',
                    'reference_no' => $row->number, 'name' => 'Own bike repair instalment',
                    'date' => Carbon::parse($row->next_deduction_month)->toDateString(), 'reason' => $row->note,
                    'amount' => $amount, 'status' => 'pending',
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
        return $this->generateMonthlySalary(Technician::findOrFail($technicianId), $month, $custom);
    }

    public function generateMonthlySalary(Technician $technician, CarbonInterface|string $month, array $custom = []): array
    {
        $salaryMonth = Carbon::parse($month)->startOfMonth();
        $cycle = $this->salaryCycle->previewSalaryPeriod($salaryMonth);
        $start = Carbon::parse($cycle['salary_period_start'])->startOfDay();
        $end = Carbon::parse($cycle['salary_period_end'])->startOfDay();
        $attendance = $this->getAttendanceSummary($technician->id, $start, $end);
        $advance = $this->getAdvanceSummary($technician->id, $salaryMonth);
        $pendingDeductions = $this->getTechnicianPendingDeductions($technician->id, $salaryMonth, $end);
        $dailySalary = $this->dailySalary($technician);
        $attendanceSalary = $technician->salary_type === 'Daily'
            ? $dailySalary * ($attendance['worked_days'] + $attendance['approved_leave_days'])
            : (float) $technician->basic_salary;
        $commission = (float) TechnicianCommission::where('technician_id', $technician->id)
            ->whereBetween('earned_date', [$start, $end])->where('status', 'Pending')->sum('amount');
        $allowances = max(0, (float) ($custom['allowances'] ?? $custom['other_allowances'] ?? 0));
        $manualAdditions = max(0, (float) ($custom['manual_additions'] ?? $custom['manual_addition'] ?? 0));
        $otherDeductions = max(0, (float) ($custom['other_deductions'] ?? 0));
        $manualDeductions = max(0, (float) ($custom['manual_deductions'] ?? $custom['manual_deduction'] ?? 0));
        $workedOnLeavePay = max(0, (float) ($custom['worked_on_leave_pay'] ?? 0));
        $paidAmount = max(0, (float) ($custom['paid_amount'] ?? 0));
        $noPay = $technician->salary_type === 'Daily' ? 0 : $attendance['absent_days'] * (float) ($technician->no_pay_deduction_rate ?: $dailySalary);
        $contributions = $this->calculateEPFETF($technician, $attendanceSalary);
        $gross = $this->calculateGrossSalary($attendanceSalary, $attendance['total_ot_amount'], $commission, $allowances, $manualAdditions, $workedOnLeavePay);
        $deductionParts = [
            'late' => $attendance['late_deduction'], 'early_leave' => $attendance['early_leave_deduction'],
            'no_pay' => $noPay, 'half_day' => $attendance['half_day_deduction'], 'advance' => $advance['total_amount'],
            'employee_epf' => $contributions['employee_epf_amount'], 'job_bike' => $pendingDeductions['total_amount'],
            'other' => $otherDeductions, 'manual' => $manualDeductions,
        ];
        $totalDeductions = $this->calculateTotalDeductions($deductionParts);
        $net = $this->calculateNetPayable($gross, $totalDeductions);
        $balance = $this->calculateBalance($net, $paidAmount);
        $technicianData = $this->getTechnicianSalaryData($technician->id);

        return [
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
            'ot_hours' => $attendance['total_ot_hours'], 'ot_amount' => $attendance['total_ot_amount'], 'commission' => $commission,
            'allowances' => $allowances, 'manual_additions' => $manualAdditions, 'worked_on_leave_pay' => $workedOnLeavePay,
            'gross_salary' => $gross, 'advances' => $advance['total_amount'], 'total_advance_deduction' => $advance['total_amount'],
            'late_deduction' => $attendance['late_deduction'], 'early_leave_deduction' => $attendance['early_leave_deduction'],
            'half_day_deduction' => $attendance['half_day_deduction'], 'no_pay_deduction' => round($noPay, 2),
            'own_bike_deduction' => $pendingDeductions['total_amount'], 'total_job_deduction' => $pendingDeductions['total_amount'],
            'other_deductions' => $otherDeductions, 'total_other_deduction' => $otherDeductions,
            'manual_deductions' => $manualDeductions, 'deductions' => round($totalDeductions - $contributions['employee_epf_amount'], 2),
            'total_deductions' => $totalDeductions, 'epf_employee' => $contributions['employee_epf_amount'],
            'epf_company' => $contributions['company_epf_amount'], 'etf_company' => $contributions['company_etf_amount'],
            'net_payable' => $net, 'paid_amount' => $paidAmount, 'balance_amount' => $balance['balance'], 'overpaid_amount' => $balance['overpaid_amount'],
            'technician' => $technicianData, 'salary_cycle' => $cycle, 'attendance' => $attendance, 'attendance_summary' => $attendance, 'advance_summary' => $advance,
            'pending_deductions' => $pendingDeductions, 'earnings' => ['attendance_salary' => round($attendanceSalary, 2), 'ot' => $attendance['total_ot_amount'], 'commission' => $commission, 'allowances' => $allowances, 'manual_additions' => $manualAdditions, 'worked_on_leave_pay' => $workedOnLeavePay, 'gross' => $gross],
            'deduction_breakdown' => $deductionParts,
            'job_deductions' => $pendingDeductions,
            'employee_epf' => $contributions['employee_epf_amount'],
            'company_epf' => $contributions['company_epf_amount'],
            'company_etf' => $contributions['company_etf_amount'],
            'calculation_snapshot' => ['generated_at' => now()->toIso8601String(), 'salary_cycle' => $cycle, 'technician' => $technicianData, 'attendance_summary' => $attendance, 'advance_summary' => $advance, 'pending_deductions' => $pendingDeductions, 'inputs' => $custom, 'earnings' => ['gross' => $gross], 'deductions' => $deductionParts, 'contributions' => $contributions],
        ];
    }

    public function finalizeSalaryPayment(TechnicianSalarySheet $sheet, ?int $userId = null): TechnicianSalarySheet
    {
        return DB::transaction(function () use ($sheet, $userId) {
            $sheet->refresh();
            abort_unless($sheet->status === 'Draft', 422);
            $snapshot = $sheet->calculation_snapshot ?? [];
            $advanceIds = collect(data_get($snapshot, 'advance_summary.items', []))->pluck('id')->filter();
            TechnicianAdvance::whereIn('id', $advanceIds)->where('status', 'Pending Deduction')
                ->update(['status' => 'Deducted', 'salary_sheet_id' => $sheet->id]);

            $deductionItems = collect(data_get($snapshot, 'pending_deductions.items', []));
            $manualIds = $deductionItems->where('kind', 'technician_deduction')->pluck('id')->filter();
            TechnicianDeduction::whereIn('id', $manualIds)->where('status', 'pending')
                ->update(['status' => 'deducted', 'salary_payment_id' => $sheet->id, 'updated_by' => $userId]);
            foreach ($deductionItems->where('kind', 'bike_installment') as $item) {
                $plan = TechnicianBikeDeduction::lockForUpdate()->find($item['id']);
                if (! $plan || $plan->status !== 'Active') {
                    continue;
                }
                $deducted = min((float) $plan->total_amount, (float) $plan->deducted_amount + (float) $item['amount']);
                $plan->update([
                    'deducted_amount' => $deducted,
                    'status' => $deducted >= (float) $plan->total_amount ? 'Completed' : 'Active',
                    'next_deduction_month' => $deducted >= (float) $plan->total_amount ? null : Carbon::parse($sheet->period_start)->addMonth(),
                ]);
            }
            $sheet->update(['status' => 'Finalized', 'finalized_at' => now(), 'finalized_by' => $userId]);

            return $sheet->fresh();
        });
    }

    public function reopenSalaryPayment(TechnicianSalarySheet $sheet, string $reason, ?int $userId = null): TechnicianSalarySheet
    {
        return DB::transaction(function () use ($sheet, $reason, $userId) {
            $sheet->refresh();
            abort_unless($sheet->status === 'Finalized', 422);
            TechnicianAdvance::where('salary_sheet_id', $sheet->id)->update(['status' => 'Pending Deduction', 'salary_sheet_id' => null]);
            TechnicianDeduction::where('salary_payment_id', $sheet->id)->update(['status' => 'pending', 'salary_payment_id' => null, 'updated_by' => $userId]);
            foreach (collect(data_get($sheet->calculation_snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment') as $item) {
                $plan = TechnicianBikeDeduction::lockForUpdate()->find($item['id']);
                if ($plan) {
                    $plan->update([
                        'deducted_amount' => max(0, (float) $plan->deducted_amount - (float) $item['amount']),
                        'status' => 'Active',
                        'next_deduction_month' => $sheet->period_start,
                    ]);
                }
            }
            $sheet->update(['status' => 'Draft', 'reopen_reason' => $reason, 'finalized_at' => null, 'finalized_by' => null]);

            return $sheet->fresh();
        });
    }

    public function calculateEPFETF(Technician $technician, float $eligibleSalary): array
    {
        return [
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
        if ((float) $technician->daily_salary > 0) {
            return (float) $technician->daily_salary;
        }

        return round((float) $technician->basic_salary / 26, 2);
    }
}
