<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\JobCard;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianCommission;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Services\AccountService;
use App\Services\WorkshopService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $month = now()->startOfMonth();
        $query = Technician::query()->withCount(['jobs as month_jobs_count' => fn (Builder $q) => $q->where('created_at', '>=', $month)])
            ->withSum(['commissions as month_commission' => fn (Builder $q) => $q->where('earned_date', '>=', $month)], 'amount')
            ->withSum(['advances as advance_balance' => fn (Builder $q) => $q->where('status', 'Pending Deduction')], 'amount')
            ->withSum(['bikeDeductions as bike_balance' => fn (Builder $q) => $q->where('status', 'Active')], DB::raw('total_amount-deducted_amount'))
            ->with(['attendance' => fn ($q) => $q->whereDate('date', today())])
            ->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))
            ->when($request->status !== null && $request->status !== '', fn (Builder $q) => $q->where('active', $request->status === 'active'))
            ->when($request->salary_type, fn (Builder $q, string $type) => $q->where('salary_type', $type))
            ->when($request->commission !== null && $request->commission !== '', fn (Builder $q) => $q->where('commission_enabled', $request->commission === 'yes'));

        return view('technicians.index', [
            'technicians' => $query->latest()->paginate(15)->withQueryString(),
            'stats' => [
                'total' => Technician::count(), 'active' => Technician::where('active', true)->count(),
                'present' => TechnicianAttendance::whereDate('date', today())->whereIn('status', ['Present', 'Late'])->count(),
                'salary_pending' => TechnicianSalarySheet::whereIn('status', ['Draft', 'Finalized', 'Partially Paid'])->sum('balance_amount'),
                'commission' => TechnicianCommission::where('earned_date', '>=', $month)->sum('amount'),
            ],
        ]);
    }

    public function create()
    {
        return view('technicians.create');
    }

    public function store(Request $request)
    {
        $data = $this->technicianData($request);
        $technician = Technician::create($data);
        if ($request->action === 'attendance') {
            return redirect()->route('technicians.attendance.index', ['technician' => $technician->id])->with('success', 'Technician saved. Mark today’s attendance below.');
        }

        return redirect()->route('technicians.show', $technician)->with('success', 'Technician profile created.');
    }

    public function update(Request $request, Technician $technician)
    {
        $technician->update($this->technicianData($request, $technician));

        return back()->with('success', 'Technician profile updated.');
    }

    public function show(Technician $technician)
    {
        return redirect()->route('technicians.history', ['technician' => $technician->id]);
    }

    public function attendanceIndex(Request $request)
    {
        $date = $request->date ? Carbon::parse($request->date) : today();
        $records = TechnicianAttendance::with('technician')->when($request->technician, fn (Builder $q, $id) => $q->where('technician_id', $id))
            ->when($request->status, fn (Builder $q, $status) => $q->where('status', $status))
            ->whereDate('date', $date)->latest('date')->paginate(20)->withQueryString();

        $presentStatuses = ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave'];

        return view('technicians.attendance', ['records' => $records, 'technicians' => $this->technicians(), 'date' => $date, 'stats' => [
            'present' => TechnicianAttendance::whereDate('date', $date)->whereIn('status', $presentStatuses)->count(),
            'absent' => TechnicianAttendance::whereDate('date', $date)->whereIn('status', ['Absent', 'No Pay Leave'])->count(),
            'late' => TechnicianAttendance::whereDate('date', $date)->where('status', 'Late')->count(),
            'leave' => TechnicianAttendance::whereDate('date', $date)->whereIn('status', ['Leave', 'Paid Leave'])->count(),
            'ot' => TechnicianAttendance::whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->sum('ot_hours'),
        ]]);
    }

    public function attendanceStore(Request $request)
    {
        $bulk = $request->has('technician_ids') && is_array($request->input('technician_ids'));
        $ids = $bulk ? $request->input('technician_ids') : [$request->input('technician_id')];

        $request->validate([
            'date' => ['required', 'date'],
            'status' => ['required', Rule::in(['Present', 'Absent', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Leave', 'Paid Leave', 'No Pay Leave', 'Holiday', 'Early Leave', 'Late'])],
            'in_time' => ['nullable', 'date_format:H:i'],
            'out_time' => ['nullable', 'date_format:H:i'],
            'ot_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'max:1000'],
            'edit_reason' => ['nullable', 'max:500'],
            'is_manual_edit' => ['nullable']
        ]);

        if (!$bulk) {
            $request->validate([
                'technician_id' => ['required', 'exists:technicians,id']
            ]);
        }

        $date = $request->input('date');
        $start = Carbon::parse($date)->startOfMonth();
        
        $savedCount = 0;

        foreach ($ids as $techId) {
            if (!$techId) continue;
            
            $technician = Technician::findOrFail($techId);

            // 1. Check finalized salary sheets
            $isFinalized = TechnicianSalarySheet::where('technician_id', $technician->id)
                ->whereDate('period_start', $start)
                ->where('status', 'Finalized')
                ->exists();
            if ($isFinalized) {
                if ($bulk) continue;
                throw ValidationException::withMessages(['date' => "This attendance for {$technician->name} is included in finalized salary. Reopen paysheet before editing."]);
            }

            $oldRecord = TechnicianAttendance::where('technician_id', $technician->id)->whereDate('date', $date)->first();

            // Edit reason is required if updating existing
            if ($oldRecord && empty($request->edit_reason) && !$bulk) {
                throw ValidationException::withMessages(['edit_reason' => 'Edit Reason is required when updating attendance record.']);
            }

            $status = $request->input('status');
            $isManual = $request->has('is_manual_edit') && $request->input('is_manual_edit') == '1';

            // Calculate metrics
            $metrics = $this->calculateAttendance(
                $technician,
                $date,
                $status,
                $request->input('in_time'),
                $request->input('out_time'),
                $request->filled('ot_amount') ? (float)$request->input('ot_amount') : null,
                $request->filled('deduction_amount') ? (float)$request->input('deduction_amount') : null,
                $isManual
            );

            $payload = array_merge($request->only(['date', 'in_time', 'out_time', 'note', 'edit_reason']), $metrics, [
                'technician_id' => $technician->id,
                'status' => $status,
                'is_manual_edit' => $isManual,
                'edited_by' => auth()->id(),
                'updated_by' => auth()->id()
            ]);

            if (!$oldRecord) {
                $payload['created_by'] = auth()->id();
            } else {
                // Log Audit Log
                AuditLog::create([
                    'user_id' => auth()->id(),
                    'event' => 'Attendance Edited',
                    'auditable_type' => $technician->getMorphClass(),
                    'auditable_id' => $technician->id,
                    'old_values' => [
                        'date' => $oldRecord->date->toDateString(),
                        'in_time' => $oldRecord->in_time,
                        'out_time' => $oldRecord->out_time,
                        'ot_amount' => $oldRecord->ot_amount,
                        'deduction_amount' => $oldRecord->deduction_amount,
                        'status' => $oldRecord->status,
                        'edit_reason' => $oldRecord->edit_reason,
                    ],
                    'new_values' => [
                        'date' => $date,
                        'in_time' => $request->input('in_time'),
                        'out_time' => $request->input('out_time'),
                        'ot_amount' => $metrics['ot_amount'],
                        'deduction_amount' => $metrics['deduction_amount'],
                        'status' => $status,
                        'edit_reason' => $request->input('edit_reason'),
                    ],
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ]);
            }

            TechnicianAttendance::updateOrCreate(['technician_id' => $technician->id, 'date' => $date], $payload);
            $savedCount++;
        }

        return back()->with('success', "Attendance saved for {$savedCount} technician(s) and metrics calculated.");
    }

    private function calculateAttendance($technician, $date, &$status, $inTime, $outTime, $manualOtAmount = null, $manualDeductionAmount = null, $isManualEdit = false)
    {
        $salarySetting = \App\Models\SalarySetting::firstOrCreate([]);
        
        $metrics = [
            'working_minutes' => 0,
            'working_hours' => 0,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'ot_minutes' => 0,
            'ot_hours' => 0,
            'ot_amount' => 0,
            'deduction_amount' => 0,
            'late_deduction_amount' => 0,
            'early_leave_deduction_amount' => 0,
            'half_day_deduction_amount' => 0,
        ];

        // 1. If leave or non-work status, return empty metrics
        $nonWorkStatuses = ['Absent', 'Leave', 'Paid Leave', 'No Pay Leave', 'Holiday'];
        if (in_array($status, $nonWorkStatuses)) {
            if ($status === 'Absent' || $status === 'No Pay Leave') {
                $metrics['deduction_amount'] = (float) $technician->no_pay_deduction_rate;
            }
            return $metrics;
        }

        // 2. We need In/Out times for calculation
        if (!$inTime || !$outTime) {
            return $metrics;
        }

        $in = Carbon::parse($inTime);
        $out = Carbon::parse($outTime);
        
        $working_minutes = max(0, $in->diffInMinutes($out, false));
        $metrics['working_minutes'] = $working_minutes;
        $metrics['working_hours'] = $working_minutes / 60;

        // 3. Late coming calculation
        $open = Carbon::parse($salarySetting->opening_time);
        if ($salarySetting->enable_late_deduction && $in->gt($open)) {
            $diff = $in->diffInMinutes($open);
            if ($diff > $salarySetting->late_grace_minutes) {
                $metrics['late_minutes'] = $diff;
                if ($salarySetting->late_deduction_type === 'Fixed amount') {
                    $metrics['late_deduction_amount'] = (float) $salarySetting->late_deduction_amount;
                } elseif ($salarySetting->late_deduction_type === 'Per minute amount') {
                    $metrics['late_deduction_amount'] = $diff * (float) $salarySetting->late_deduction_amount;
                }
            }
        }

        // 4. Early leave calculation
        $close = Carbon::parse($salarySetting->closing_time);
        if ($salarySetting->enable_early_leave_deduction && $out->lt($close)) {
            $diff = $close->diffInMinutes($out);
            if ($diff > $salarySetting->early_leave_grace_minutes) {
                $metrics['early_leave_minutes'] = $diff;
                if ($salarySetting->early_leave_deduction_type === 'Fixed amount') {
                    $metrics['early_leave_deduction_amount'] = (float) $salarySetting->early_leave_deduction_amount;
                } elseif ($salarySetting->early_leave_deduction_type === 'Per minute amount') {
                    $metrics['early_leave_deduction_amount'] = $diff * (float) $salarySetting->early_leave_deduction_amount;
                }
            }
        }

        // 5. Half Day cutoffs check
        if ($salarySetting->enable_half_day) {
            $morningCutoff = Carbon::parse($salarySetting->morning_half_day_cutoff);
            $eveningCutoff = Carbon::parse($salarySetting->evening_half_day_cutoff);
            
            $isHalfDay = false;
            if ($in->gt($morningCutoff)) {
                $status = 'Morning Half Day';
                $isHalfDay = true;
            } elseif ($out->lt($eveningCutoff)) {
                $status = 'Evening Half Day';
                $isHalfDay = true;
            }

            if ($isHalfDay || $status === 'Half Day') {
                $dailyRate = $technician->salary_type === 'Daily' ? (float) $technician->daily_salary : ((float) $technician->basic_salary / 26);
                
                if ($salarySetting->half_day_deduction_type === 'Half of daily salary') {
                    $metrics['half_day_deduction_amount'] = $dailyRate / 2;
                } elseif ($salarySetting->half_day_deduction_type === 'Fixed amount') {
                    $metrics['half_day_deduction_amount'] = (float) $salarySetting->half_day_deduction_amount;
                }
                
                // If it is half day, we nullify late and early deductions since half day deduction covers it
                $metrics['late_deduction_amount'] = 0;
                $metrics['early_leave_deduction_amount'] = 0;
            }
        }

        // 6. Overtime (OT) calculation
        if ($salarySetting->enable_ot && $out->gt($close)) {
            $diff = $out->diffInMinutes($close);
            if ($diff > $salarySetting->ot_grace_minutes_after_closing) {
                $metrics['ot_minutes'] = $diff;
                $metrics['ot_hours'] = $diff / 60;
                
                // Slab matching logic
                $slabRules = \App\Models\OtSlabRule::where('status', 'Active')->orderBy('from_minutes')->get();
                if ($slabRules->isNotEmpty()) {
                    $matchedSlab = null;
                    
                    if ($salarySetting->ot_round_method === 'Ceiling slab') {
                        $matchedSlab = $slabRules->first(fn($s) => $diff <= $s->to_minutes);
                        if (!$matchedSlab) $matchedSlab = $slabRules->last();
                    } elseif ($salarySetting->ot_round_method === 'Floor slab') {
                        $matchedSlab = $slabRules->last(fn($s) => $diff >= $s->from_minutes);
                        if (!$matchedSlab) $matchedSlab = $slabRules->first();
                    } elseif ($salarySetting->ot_round_method === 'Nearest slab') {
                        $matchedSlab = $slabRules->sortBy(fn($s) => abs(($s->from_minutes + $s->to_minutes)/2 - $diff))->first();
                    } else { // Exact minutes
                        $matchedSlab = $slabRules->first(fn($s) => $diff >= $s->from_minutes && $diff <= $s->to_minutes);
                    }

                    if ($matchedSlab) {
                        if ($matchedSlab->calculation_rule === 'Fixed for this range') {
                            $metrics['ot_amount'] = (float) $matchedSlab->amount;
                        } else { // Add previous slab amount
                            $metrics['ot_amount'] = $slabRules->where('from_minutes', '<=', $matchedSlab->from_minutes)->sum('amount');
                        }
                    }
                } else {
                    // Fallback to settings: "Every X minutes = Y Rs"
                    $slabMinutes = max(1, $salarySetting->ot_slab_minutes);
                    $amountPerSlab = (float) $salarySetting->ot_amount_per_slab;
                    
                    if ($salarySetting->ot_round_method === 'Ceiling slab') {
                        $slabCount = ceil($diff / $slabMinutes);
                    } elseif ($salarySetting->ot_round_method === 'Floor slab') {
                        $slabCount = floor($diff / $slabMinutes);
                    } elseif ($salarySetting->ot_round_method === 'Nearest slab') {
                        $slabCount = round($diff / $slabMinutes);
                    } else {
                        $slabCount = $diff / $slabMinutes;
                    }
                    $metrics['ot_amount'] = $slabCount * $amountPerSlab;
                }
            }
        }

        // 7. Sum up deductions
        $metrics['deduction_amount'] = $metrics['late_deduction_amount'] + $metrics['early_leave_deduction_amount'] + $metrics['half_day_deduction_amount'];

        // Respect Manual Overrides
        if ($isManualEdit) {
            if ($manualOtAmount !== null) $metrics['ot_amount'] = $manualOtAmount;
            if ($manualDeductionAmount !== null) $metrics['deduction_amount'] = $manualDeductionAmount;
        }

        return $metrics;
    }

    public function attendance(Request $request, Technician $technician)
    {
        $request->merge(['technician_id' => $technician->id]);
        return $this->attendanceStore($request);
    }

    public function salaries(Request $request)
    {
        $sheets = TechnicianSalarySheet::with('technician')->when($request->technician, fn (Builder $q, $id) => $q->where('technician_id', $id))->latest('period_start')->paginate(15)->withQueryString();

        return view('technicians.salaries', ['sheets' => $sheets, 'technicians' => $this->technicians(), 'accounts' => Account::where('active', true)->get(), 'stats' => [
            'payable' => TechnicianSalarySheet::whereMonth('period_start', now()->month)->sum('net_payable'),
            'paid' => TechnicianSalarySheet::whereMonth('period_start', now()->month)->sum('paid_amount'),
            'balance' => TechnicianSalarySheet::whereMonth('period_start', now()->month)->sum('balance_amount'),
            'employee_epf' => TechnicianSalarySheet::whereMonth('period_start', now()->month)->sum('epf_employee'),
            'company' => TechnicianSalarySheet::whereMonth('period_start', now()->month)->sum(DB::raw('epf_company+etf_company')),
        ]]);
    }

    public function salaryStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate(['technician_id' => ['required', 'exists:technicians,id'], 'month' => ['required', 'date_format:Y-m'], 'other_deductions' => ['nullable', 'numeric', 'min:0'], 'paid_amount' => ['nullable', 'numeric', 'min:0'], 'account_id' => ['nullable', 'required_if:paid_amount,>0', 'exists:accounts,id'], 'payment_method' => ['nullable', 'max:50']]);
        $technician = Technician::findOrFail($data['technician_id']);
        $start = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $end = $start->copy()->endOfMonth();
        if (TechnicianSalarySheet::where('technician_id', $technician->id)->whereDate('period_start', $start)->exists()) {
            throw ValidationException::withMessages(['month' => 'A salary sheet already exists for this technician and month.']);
        }
        
        $attendance = TechnicianAttendance::where('technician_id', $technician->id)->whereBetween('date', [$start, $end])->get();
        
        $presentStatuses = ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave'];
        $present = $attendance->whereIn('status', $presentStatuses)->count();
        $absent = $attendance->whereIn('status', ['Absent', 'No Pay Leave'])->count();
        
        $otHours = (float) $attendance->sum('ot_hours');
        $otAmount = (float) $attendance->sum('ot_amount');
        
        $commission = (float) TechnicianCommission::where('technician_id', $technician->id)->whereBetween('earned_date', [$start, $end])->where('status', 'Pending')->sum('amount');
        $advances = (float) TechnicianAdvance::where('technician_id', $technician->id)->where('status', 'Pending Deduction')->whereDate('deduct_month', $start)->sum('amount');
        $bikeDeduction = (float) TechnicianBikeDeduction::where('technician_id', $technician->id)->where('status', 'Active')->whereDate('next_deduction_month', '<=', $start)->sum('installment_amount');
        
        $basic = $technician->salary_type === 'Daily' ? (float) $technician->daily_salary * $present : (float) $technician->basic_salary;
        
        // Sum deductions directly from daily attendance details
        $lateDeduction = (float) $attendance->sum('late_deduction_amount');
        $earlyLeaveDeduction = (float) $attendance->sum('early_leave_deduction_amount');
        $halfDayDeduction = (float) $attendance->sum('half_day_deduction_amount');
        $noPay = $absent * (float) $technician->no_pay_deduction_rate;
        
        $employeeEpf = $technician->epf_eligible ? $basic * (float) $technician->epf_employee_percentage / 100 : 0;
        $companyEpf = $technician->epf_eligible ? $basic * (float) $technician->epf_company_percentage / 100 : 0;
        $companyEtf = $technician->etf_eligible ? $basic * (float) $technician->etf_company_percentage / 100 : 0;
        
        $gross = $basic + $otAmount + $commission;
        $deductions = $advances + $bikeDeduction + $lateDeduction + $earlyLeaveDeduction + $halfDayDeduction + $noPay + (float) ($data['other_deductions'] ?? 0);
        $net = max(0, $gross - $deductions - $employeeEpf);
        $paid = min($net, (float) ($data['paid_amount'] ?? 0));
        
        $sheet = DB::transaction(function () use ($data, $technician, $start, $end, $attendance, $present, $absent, $otHours, $otAmount, $basic, $commission, $advances, $bikeDeduction, $lateDeduction, $earlyLeaveDeduction, $halfDayDeduction, $noPay, $employeeEpf, $companyEpf, $companyEtf, $gross, $deductions, $net, $paid) {
            $sheet = TechnicianSalarySheet::create([
                'number' => 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'technician_id' => $technician->id,
                'period_start' => $start,
                'period_end' => $end,
                'working_days' => $attendance->count(),
                'present_days' => $present,
                'absent_days' => $absent,
                'ot_hours' => $otHours,
                'basic_salary' => $basic,
                'ot_amount' => $otAmount,
                'commission' => $commission,
                'gross_salary' => $gross,
                'advances' => $advances,
                'late_deduction' => $lateDeduction,
                'early_leave_deduction' => $earlyLeaveDeduction,
                'half_day_deduction' => $halfDayDeduction,
                'no_pay_deduction' => $noPay,
                'own_bike_deduction' => $bikeDeduction,
                'other_deductions' => $data['other_deductions'] ?? 0,
                'deductions' => $deductions,
                'epf_employee' => $employeeEpf,
                'epf_company' => $companyEpf,
                'etf_company' => $companyEtf,
                'net_payable' => $net,
                'paid_amount' => $paid,
                'balance_amount' => $net - $paid,
                'status' => $paid >= $net ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Draft'),
                'account_id' => $data['account_id'] ?? null,
                'payment_method' => $data['payment_method'] ?? null,
                'created_by' => auth()->id()
            ]);
            
            TechnicianContribution::create(['technician_id' => $technician->id, 'salary_sheet_id' => $sheet->id, 'month' => $start, 'basic_salary' => $basic, 'employee_epf_percentage' => $technician->epf_employee_percentage, 'employee_epf_amount' => $employeeEpf, 'company_epf_percentage' => $technician->epf_company_percentage, 'company_epf_amount' => $companyEpf, 'company_etf_percentage' => $technician->etf_company_percentage, 'company_etf_amount' => $companyEtf]);
            
            if ($paid > 0) {
                $accountService = app(AccountService::class);
                if (in_array($data['payment_method'], ['Cash'])) {
                    $accountService->recordTransaction('cash', 'expense', $paid, "Salary payment {$sheet->number}", $sheet, $data['payment_method'], null, $data['account_id'] ?? null);
                } else {
                    $accountService->recordTransaction('bank', 'expense', $paid, "Salary payment {$sheet->number}", $sheet, $data['payment_method'], null, $data['account_id'] ?? null);
                }
            }

            return $sheet;
        });

        return redirect()->route('technicians.salaries')->with('success', "Salary sheet {$sheet->number} generated.");
    }

    public function advances(Request $request)
    {
        return view('technicians.advances', ['advances' => TechnicianAdvance::with(['technician', 'account'])->latest('payment_date')->paginate(15), 'technicians' => $this->technicians(), 'accounts' => Account::where('active', true)->get(), 'stats' => ['today' => TechnicianAdvance::whereDate('payment_date', today())->where('status', '!=', 'Cancelled')->sum('amount'), 'month' => TechnicianAdvance::whereMonth('payment_date', now()->month)->where('status', '!=', 'Cancelled')->sum('amount'), 'pending' => TechnicianAdvance::where('status', 'Pending Deduction')->sum('amount'), 'balance' => TechnicianAdvance::where('status', 'Pending Deduction')->sum('amount')]]);
    }

    public function advanceStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate(['technician_id' => ['required', 'exists:technicians,id'], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'account_id' => ['required', 'exists:accounts,id'], 'payment_method' => ['required', 'max:50'], 'deduct_month' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'max:1000']]);
        DB::transaction(function () use ($data) {
            $advance = TechnicianAdvance::create($data + ['number' => 'ADV-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'deduct_month' => $data['deduct_month'].'-01', 'created_by' => auth()->id()]);

            $accountService = app(AccountService::class);
            if (in_array($data['payment_method'], ['Cash'])) {
                $accountService->recordTransaction('cash', 'expense', (float) $data['amount'], "Technician advance {$advance->number}", $advance, $data['payment_method'], null, $data['account_id'], $data['payment_date'], 'salary_advance');
            } else {
                $accountService->recordTransaction('bank', 'expense', (float) $data['amount'], "Technician advance {$advance->number}", $advance, $data['payment_method'], null, $data['account_id'], $data['payment_date'], 'salary_advance');
            }
        });

        return back()->with('success', 'Advance recorded and account ledger updated.');
    }

    public function commissions(Request $request)
    {
        $records = TechnicianCommission::with(['technician', 'jobCard.intake.bike.customer', 'invoice'])->latest('earned_date')->paginate(15);

        return view('technicians.commissions', ['records' => $records, 'technicians' => $this->technicians(), 'jobs' => JobCard::with('invoice')->whereNotNull('main_technician_id')->latest()->limit(100)->get(), 'stats' => ['total' => TechnicianCommission::whereMonth('earned_date', now()->month)->sum('amount'), 'paid' => TechnicianCommission::where('status', 'Paid')->sum('amount'), 'pending' => TechnicianCommission::where('status', 'Pending')->sum('amount'), 'highest' => TechnicianCommission::selectRaw('technician_id,sum(amount) total')->whereMonth('earned_date', now()->month)->groupBy('technician_id')->orderByDesc('total')->with('technician')->first()]]);
    }

    public function commissionStore(Request $request)
    {
        $data = $request->validate(['technician_id' => ['required', 'exists:technicians,id'], 'job_card_id' => ['nullable', 'exists:job_cards,id'], 'commission_type' => ['required', 'max:100'], 'base_amount' => ['required', 'numeric', 'min:0'], 'percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'amount' => ['nullable', 'numeric', 'min:0'], 'earned_date' => ['required', 'date'], 'note' => ['nullable', 'max:1000']]);
        $job = ! empty($data['job_card_id']) ? JobCard::with('invoice')->find($data['job_card_id']) : null;
        $amount = (float) ($data['amount'] ?? 0) ?: (float) $data['base_amount'] * (float) $data['percentage'] / 100;
        TechnicianCommission::create($data + ['invoice_id' => $job?->invoice?->id, 'amount' => $amount, 'created_by' => auth()->id()]);

        return back()->with('success', 'Commission record added.');
    }

    public function bikeDeductions(Request $request)
    {
        return view('technicians.bike-deductions', ['records' => TechnicianBikeDeduction::with(['technician', 'bike', 'jobCard', 'invoice'])->latest()->paginate(15), 'technicians' => $this->technicians(), 'bikes' => Bike::with('intakes.jobCard.invoice')->where('is_technician_bike', true)->get(), 'stats' => ['total' => TechnicianBikeDeduction::sum('total_amount'), 'deducted' => TechnicianBikeDeduction::sum('deducted_amount'), 'pending' => TechnicianBikeDeduction::sum(DB::raw('total_amount-deducted_amount')), 'active' => TechnicianBikeDeduction::where('status', 'Active')->count()]]);
    }

    public function bikeDeductionStore(Request $request)
    {
        $data = $request->validate(['technician_id' => ['required', 'exists:technicians,id'], 'bike_id' => ['required', 'exists:bikes,id'], 'job_card_id' => ['nullable', 'exists:job_cards,id'], 'total_amount' => ['required', 'numeric', 'gt:0'], 'deduction_type' => ['required', Rule::in(['Full deduction', 'Partial deduction', 'Monthly installment'])], 'installment_amount' => ['required', 'numeric', 'gt:0'], 'installments' => ['required', 'integer', 'min:1'], 'start_month' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'max:1000']]);
        $job = ! empty($data['job_card_id']) ? JobCard::with('invoice')->find($data['job_card_id']) : null;
        TechnicianBikeDeduction::create($data + ['number' => 'TBD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'invoice_id' => $job?->invoice?->id, 'start_month' => $data['start_month'].'-01', 'next_deduction_month' => $data['start_month'].'-01', 'created_by' => auth()->id()]);

        return back()->with('success', 'Own-bike salary deduction plan created.');
    }

    public function contributions(Request $request)
    {
        $records = TechnicianContribution::with('technician')->latest('month')->paginate(20);

        return view('technicians.contributions', ['records' => $records, 'technicians' => $this->technicians(), 'stats' => ['employee' => TechnicianContribution::whereMonth('month', now()->month)->sum('employee_epf_amount'), 'company' => TechnicianContribution::whereMonth('month', now()->month)->sum('company_epf_amount'), 'etf' => TechnicianContribution::whereMonth('month', now()->month)->sum('company_etf_amount'), 'total' => TechnicianContribution::whereMonth('month', now()->month)->sum(DB::raw('employee_epf_amount+company_epf_amount+company_etf_amount'))]]);
    }

    public function history(Request $request)
    {
        $technician = $request->technician ? Technician::with(['jobs.intake.bike.customer', 'jobs.invoice', 'attendance', 'salarySheets', 'advances', 'commissions.jobCard', 'bikeDeductions.bike', 'contributions'])->find($request->technician) : null;

        return view('technicians.history', ['technicians' => $this->technicians(), 'technician' => $technician, 'auditLogs' => $technician ? AuditLog::where('auditable_type', $technician->getMorphClass())->where('auditable_id', $technician->id)->latest()->limit(50)->get() : collect()]);
    }

    private function technicians()
    {
        return Technician::where('active', true)->orderBy('name')->get();
    }

    private function technicianData(Request $request, ?Technician $technician = null): array
    {
        return $request->validate(['name' => ['required', 'max:150'], 'phone' => ['required', 'max:30', Rule::unique('technicians', 'phone')->ignore($technician)], 'alternative_phone' => ['nullable', 'max:30'], 'nic' => ['nullable', 'max:30'], 'address' => ['nullable', 'max:1000'], 'emergency_contact' => ['nullable', 'max:100'], 'joining_date' => ['nullable', 'date'], 'active' => ['required', 'boolean'], 'basic_salary' => ['required', 'numeric', 'min:0'], 'salary_type' => ['required', Rule::in(['Monthly', 'Daily', 'Weekly'])], 'daily_salary' => ['nullable', 'numeric', 'min:0'], 'ot_rate' => ['nullable', 'numeric', 'min:0'], 'late_deduction_rate' => ['nullable', 'numeric', 'min:0'], 'no_pay_deduction_rate' => ['nullable', 'numeric', 'min:0'], 'commission_enabled' => ['nullable', 'boolean'], 'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'commission_type' => ['required', 'max:100'], 'epf_number' => ['nullable', 'max:50'], 'etf_number' => ['nullable', 'max:50'], 'epf_eligible' => ['nullable', 'boolean'], 'etf_eligible' => ['nullable', 'boolean'], 'epf_employee_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'epf_company_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'etf_company_percentage' => ['required', 'numeric', 'min:0', 'max:100']]);
    }
}
