<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AttendanceImportBatch;
use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\OtSlabRule;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianCommission;
use App\Models\TechnicianContribution;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use App\Services\AccountService;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceImportService;
use App\Services\SalaryCalculationService;
use App\Services\SalaryCycleService;
use App\Services\WorkshopService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
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

    public function edit(Technician $technician)
    {
        return view('technicians.edit', compact('technician'));
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
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $date = $request->date ? Carbon::parse($request->date) : today();
        if ($request->filled('month')) {
            $from = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
            $to = $from->copy()->endOfMonth();
        } elseif ($request->filled('date')) {
            $from = Carbon::parse($request->date);
            $to = $from->copy();
        } elseif ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? Carbon::parse($request->from) : today()->startOfMonth();
            $to = $request->filled('to') ? Carbon::parse($request->to) : today()->endOfMonth();
        } else {
            $from = today()->startOfMonth();
            $to = today()->endOfMonth();
        }
        $records = TechnicianAttendance::with('technician')->when($request->technician, fn (Builder $q, $id) => $q->where('technician_id', $id))
            ->when($request->status, fn (Builder $q, $status) => $q->where('status', $status))
            ->whereBetween('date', [$from, $to])->orderByDesc('date')->paginate(31)->withQueryString();

        $presentStatuses = ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave'];
        $batch = $request->filled('batch') ? AttendanceImportBatch::where('imported_by', auth()->id())->find($request->batch) : null;
        $recentBatches = AttendanceImportBatch::with('importer')->latest()->limit(8)->get();

        return view('technicians.attendance', [
            'records' => $records, 'technicians' => $this->technicians(), 'date' => $date, 'from' => $from, 'to' => $to,
            'previewBatch' => $batch, 'recentBatches' => $recentBatches,
            'salarySettings' => SalarySetting::firstOrCreate([])->refresh(), 'otSlabs' => OtSlabRule::where('status', 'Active')->orderBy('from_minutes')->get(),
            'stats' => [
                'present' => TechnicianAttendance::whereBetween('date', [$from, $to])->whereIn('status', $presentStatuses)->count(),
                'absent' => TechnicianAttendance::whereBetween('date', [$from, $to])->whereIn('status', ['Absent', 'No Pay Leave'])->count(),
                'late' => TechnicianAttendance::whereBetween('date', [$from, $to])->where('late_minutes', '>', 0)->count(),
                'leave' => TechnicianAttendance::whereBetween('date', [$from, $to])->whereIn('status', ['Leave', 'Paid Leave'])->count(),
                'ot' => TechnicianAttendance::whereBetween('date', [$from, $to])->sum('ot_hours'),
            ],
        ]);
    }

    public function attendanceStore(Request $request, AttendanceCalculationService $calculator)
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
            'is_manual_edit' => ['nullable'],
        ]);

        if (! $bulk) {
            $request->validate([
                'technician_id' => ['required', 'exists:technicians,id'],
            ]);
        }

        $date = $request->input('date');
        $savedCount = 0;

        foreach ($ids as $techId) {
            if (! $techId) {
                continue;
            }

            $technician = Technician::findOrFail($techId);

            // 1. Check finalized salary sheets
            $isFinalized = TechnicianSalarySheet::where('technician_id', $technician->id)
                ->whereDate('period_start', '<=', $date)
                ->whereDate('period_end', '>=', $date)
                ->whereIn('status', ['Finalized', 'Partially Paid', 'Paid'])
                ->exists();
            if ($isFinalized) {
                if ($bulk) {
                    continue;
                }
                throw ValidationException::withMessages(['date' => "This attendance for {$technician->name} is included in finalized salary. Reopen paysheet before editing."]);
            }

            $oldRecord = TechnicianAttendance::where('technician_id', $technician->id)->whereDate('date', $date)->first();

            // Edit reason is required if updating existing
            if ($oldRecord && empty($request->edit_reason) && ! $bulk) {
                throw ValidationException::withMessages(['edit_reason' => 'Edit Reason is required when updating attendance record.']);
            }

            $status = $request->input('status');
            $isManual = $request->has('is_manual_edit') && $request->input('is_manual_edit') == '1';

            $metrics = $calculator->calculateDailyAttendance(
                $technician,
                $date,
                $status,
                $request->input('in_time'),
                $request->input('out_time'),
                $isManual ? [
                    'ot_amount' => $request->filled('ot_amount') ? (float) $request->input('ot_amount') : null,
                    'deduction_amount' => $request->filled('deduction_amount') ? (float) $request->input('deduction_amount') : null,
                ] : []
            );
            $status = $metrics['status'];

            $payload = array_merge($request->only(['date', 'in_time', 'out_time', 'note', 'edit_reason']), $metrics, [
                'technician_id' => $technician->id,
                'status' => $status,
                'is_manual_edit' => $isManual,
                'is_manual_entry' => true,
                'edited_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            if (! $oldRecord) {
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

    public function attendanceImportPreview(Request $request, AttendanceImportService $importer)
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,xlsx,xls', 'max:20480'],
            'technician_id' => ['nullable', 'exists:technicians,id'],
        ]);
        try {
            $batch = $importer->preview($data['file'], auth()->id(), $data['technician_id'] ?? null);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        return redirect()->route('technicians.attendance.index', ['batch' => $batch->id, 'month' => $batch->month?->format('Y-m')])
            ->with('success', "Preview ready: {$batch->total_rows} attendance rows found.");
    }

    public function attendanceRangeStore(Request $request, AttendanceCalculationService $calculator)
    {
        $data = $request->validate([
            'technician_ids' => ['required', 'array', 'min:1'], 'technician_ids.*' => ['required', 'distinct', 'exists:technicians,id'],
            'date_from' => ['required', 'date'], 'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'status' => ['required', Rule::in(['Present', 'Absent', 'Half Day', 'Leave', 'Paid Leave', 'No Pay Leave', 'Holiday'])],
            'in_time' => ['nullable', 'date_format:H:i'], 'out_time' => ['nullable', 'date_format:H:i'], 'note' => ['nullable', 'max:1000'],
        ]);
        $from = Carbon::parse($data['date_from']);
        $to = Carbon::parse($data['date_to']);
        if ($from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['date_to' => 'Bulk attendance is limited to 63 days at a time.']);
        }
        $saved = 0;
        foreach ($from->copy()->daysUntil($to) as $date) {
            foreach ($data['technician_ids'] as $technicianId) {
                $technician = Technician::findOrFail($technicianId);
                if (TechnicianSalarySheet::where('technician_id', $technicianId)
                    ->whereDate('period_start', '<=', $date)
                    ->whereDate('period_end', '>=', $date)
                    ->whereIn('status', ['Finalized', 'Paid', 'Partially Paid'])
                    ->exists()) {
                    continue;
                }
                $existing = TechnicianAttendance::where('technician_id', $technicianId)->whereDate('date', $date)->first();
                $metrics = $calculator->calculateDailyAttendance($technician, $date, $data['status'], $data['in_time'] ?? null, $data['out_time'] ?? null);
                TechnicianAttendance::updateOrCreate(['technician_id' => $technicianId, 'date' => $date->toDateString()], array_merge($metrics, [
                    'in_time' => $data['in_time'] ?? null, 'out_time' => $data['out_time'] ?? null, 'note' => $data['note'] ?? 'Bulk attendance entry',
                    'is_manual_entry' => true, 'is_manual_edit' => (bool) $existing, 'created_by' => $existing?->created_by ?? auth()->id(), 'edited_by' => $existing ? auth()->id() : null, 'updated_by' => auth()->id(),
                ]));
                $saved++;
            }
        }

        return back()->with('success', "{$saved} daily attendance records saved across the selected period.");
    }

    public function attendanceImportConfirm(Request $request, AttendanceImportBatch $batch, AttendanceImportService $importer, AttendanceCalculationService $calculator)
    {
        abort_unless($batch->imported_by === auth()->id() && $batch->status === 'Preview', 403);

        if ($request->has('rows') && is_array($request->input('rows'))) {
            $submittedRows = collect($request->input('rows'))->keyBy('row');
            $updatedPreviewData = [];
            foreach ($batch->preview_data as $item) {
                $rowId = $item['row'];
                if ($submittedRows->has($rowId)) {
                    $rowInput = $submittedRows->get($rowId);
                    $item['date'] = $rowInput['date'] ?? $item['date'];
                    $item['check_in'] = empty($rowInput['check_in']) ? null : $rowInput['check_in'].(strlen($rowInput['check_in']) == 5 ? ':00' : '');
                    $item['check_out'] = empty($rowInput['check_out']) ? null : $rowInput['check_out'].(strlen($rowInput['check_out']) == 5 ? ':00' : '');
                    if (! empty($rowInput['technician_id'])) {
                        $item['technician_id'] = (int) $rowInput['technician_id'];
                        $item['will_create_technician'] = false;
                    } else {
                        $item['technician_id'] = null;
                        $item['will_create_technician'] = true;
                    }
                }
                $updatedPreviewData[] = $item;
            }
            $batch->update(['preview_data' => $updatedPreviewData]);
        }

        $batch = $importer->confirm($batch, $calculator, auth()->id());
        AuditLog::create(['user_id' => auth()->id(), 'event' => 'Attendance Import Completed', 'auditable_type' => $batch->getMorphClass(), 'auditable_id' => $batch->id, 'old_values' => null, 'new_values' => $batch->only(['file_name', 'total_rows', 'success_rows', 'skipped_rows', 'duplicate_rows', 'error_rows', 'status']), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return redirect()->route('technicians.attendance.index', ['month' => $batch->month?->format('Y-m')])
            ->with('success', "Import complete: {$batch->success_rows} saved, {$batch->duplicate_rows} updated duplicates, {$batch->skipped_rows} skipped, {$batch->error_rows} errors.");
    }

    private function calculateAttendance($technician, $date, &$status, $inTime, $outTime, $manualOtAmount = null, $manualDeductionAmount = null, $isManualEdit = false)
    {
        $salarySetting = SalarySetting::firstOrCreate([]);

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
        if (! $inTime || ! $outTime) {
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
                $slabRules = OtSlabRule::where('status', 'Active')->orderBy('from_minutes')->get();
                if ($slabRules->isNotEmpty()) {
                    $matchedSlab = null;

                    if ($salarySetting->ot_round_method === 'Ceiling slab') {
                        $matchedSlab = $slabRules->first(fn ($s) => $diff <= $s->to_minutes);
                        if (! $matchedSlab) {
                            $matchedSlab = $slabRules->last();
                        }
                    } elseif ($salarySetting->ot_round_method === 'Floor slab') {
                        $matchedSlab = $slabRules->last(fn ($s) => $diff >= $s->from_minutes);
                        if (! $matchedSlab) {
                            $matchedSlab = $slabRules->first();
                        }
                    } elseif ($salarySetting->ot_round_method === 'Nearest slab') {
                        $matchedSlab = $slabRules->sortBy(fn ($s) => abs(($s->from_minutes + $s->to_minutes) / 2 - $diff))->first();
                    } else { // Exact minutes
                        $matchedSlab = $slabRules->first(fn ($s) => $diff >= $s->from_minutes && $diff <= $s->to_minutes);
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
            if ($manualOtAmount !== null) {
                $metrics['ot_amount'] = $manualOtAmount;
            }
            if ($manualDeductionAmount !== null) {
                $metrics['deduction_amount'] = $manualDeductionAmount;
            }
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
            'overpaid' => TechnicianSalarySheet::whereMonth('period_start', now()->month)->sum('overpaid_amount'),
        ]]);
    }

    public function salaryPreview(Request $request, SalaryCalculationService $calculator)
    {
        $data = $request->validate([
            'technician_id' => ['required', 'exists:technicians,id'], 'month' => ['required', 'date_format:Y-m'],
            'other_allowances' => ['nullable', 'numeric', 'min:0'], 'manual_addition' => ['nullable', 'numeric', 'min:0'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'], 'manual_deduction' => ['nullable', 'numeric', 'min:0'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'], 'worked_on_leave_pay' => ['nullable', 'numeric', 'min:0'],
            'bike_installments' => ['nullable', 'array'], 'bike_installments.*' => ['numeric', 'min:0'],
        ]);
        $response = $calculator->generateSalaryPreview((int) $data['technician_id'], $data['month'].'-01', $data);
        $existing = TechnicianSalarySheet::where('technician_id', $data['technician_id'])
            ->whereDate('period_start', $response['period_start'])
            ->whereDate('period_end', $response['period_end'])
            ->first();
        $response['existing_sheet_status'] = $existing ? $existing->status : null;

        return response()->json($response);
    }

    public function salaryStore(Request $request, SalaryCalculationService $calculator)
    {
        $data = $request->validate([
            'technician_id' => ['required', 'exists:technicians,id'], 'month' => ['required', 'date_format:Y-m'],
            'allowances' => ['nullable', 'numeric', 'min:0'], 'manual_additions' => ['nullable', 'numeric', 'min:0'],
            'other_deductions' => ['nullable', 'numeric', 'min:0'],
            'bike_installments' => ['nullable', 'array'], 'bike_installments.*' => ['numeric', 'min:0'],
            'manual_deductions' => ['nullable'],
            'manual_deductions.*.name' => ['required_with:manual_deductions.*.amount', 'string', 'max:150'],
            'manual_deductions.*.amount' => ['required_with:manual_deductions.*.name', 'numeric', 'min:0'],
            'addition_name' => ['nullable', 'max:150'], 'addition_reason' => ['nullable', 'max:500'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'], 'payment_method' => ['nullable', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Card', 'Online', 'Other'])],
            'payment_date' => ['nullable', 'date'], 'payment_note' => ['nullable', 'max:1000'], 'worked_on_leave_pay' => ['nullable', 'numeric', 'min:0'],
            'action' => ['nullable', Rule::in(['preview', 'save'])],
        ]);

        $manualDeductionsInput = $data['manual_deductions'] ?? null;
        $manualDeductionsList = is_array($manualDeductionsInput) ? $manualDeductionsInput : [];
        $data['manual_deductions'] = is_array($manualDeductionsInput)
            ? collect($manualDeductionsList)->sum('amount')
            : max(0, (float) ($manualDeductionsInput ?? 0));
        $technician = Technician::findOrFail($data['technician_id']);
        $calculation = $calculator->generateMonthlySalary($technician, $data['month'].'-01', $data);

        if (($data['action'] ?? 'save') === 'preview') {
            return back()->withInput()->with('salary_preview', $calculation + ['technician_name' => $technician->name]);
        }
        $sheet = DB::transaction(function () use ($data, $technician, $calculation, $manualDeductionsList) {
            Technician::whereKey($technician->id)->lockForUpdate()->firstOrFail();
            $existing = TechnicianSalarySheet::where('technician_id', $technician->id)->whereDate('period_start', $calculation['period_start'])->lockForUpdate()->first();
            if ($existing) {
                if ($existing->status === 'Draft') {
                    $existing->additions()->delete();
                    $existing->paymentDeductions()->delete();
                    TechnicianContribution::where('salary_sheet_id', $existing->id)->delete();
                    $existing->delete();
                } else {
                    throw ValidationException::withMessages(['month' => 'A finalized or paid salary sheet already exists for this technician and month. Please reopen it first to edit.']);
                }
            }

            $sheetFields = [
                'period_start', 'period_end', 'salary_cycle_mode', 'salary_period_days', 'carried_from_previous_month_days',
                'carried_to_next_month_days', 'excluded_dates', 'basic_salary', 'daily_salary', 'attendance_salary_amount', 'working_days', 'worked_days',
                'present_days', 'absent_days', 'half_days', 'weekly_leave_days', 'public_holiday_days', 'poya_holiday_days',
                'custom_holiday_days', 'approved_leave_days', 'auto_checkout_days', 'salary_month_days', 'total_working_days',
                'total_present_days', 'total_absent_days', 'leave_days', 'late_days', 'early_leave_days', 'worked_on_leave_days',
                'total_worked_hours', 'total_ot_hours', 'leave_summary', 'ot_hours', 'ot_amount', 'commission', 'allowances',
                'manual_additions', 'worked_on_leave_pay', 'gross_salary', 'advances', 'total_advance_deduction', 'late_deduction',
                'early_leave_deduction', 'half_day_deduction', 'no_pay_deduction', 'own_bike_deduction', 'total_job_deduction',
                'other_deductions', 'total_other_deduction', 'manual_deductions', 'deductions', 'total_deductions', 'epf_employee',
                'epf_company', 'etf_company', 'net_payable', 'paid_amount', 'balance_amount', 'overpaid_amount', 'calculation_snapshot',
            ];
            // Drafts never record money movement. Payments are posted only through salaryPay,
            // which also creates the account-ledger transaction.
            $calculation['paid_amount'] = 0;
            $calculation['balance_amount'] = $calculation['net_payable'];
            $calculation['overpaid_amount'] = 0;
            $sheet = TechnicianSalarySheet::create(Arr::only($calculation, $sheetFields) + [
                'number' => 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'technician_id' => $technician->id,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_date' => $data['payment_date'] ?? null,
                'payment_note' => $data['payment_note'] ?? null,
                'status' => 'Draft',
                'created_by' => auth()->id(),
            ]);
            TechnicianContribution::create([
                'technician_id' => $technician->id, 'salary_sheet_id' => $sheet->id, 'month' => $calculation['salary_month'] ?? $calculation['period_start'],
                'basic_salary' => $calculation['basic_salary'], 'employee_epf_percentage' => $technician->epf_employee_percentage,
                'employee_epf_amount' => $calculation['epf_employee'], 'company_epf_percentage' => $technician->epf_company_percentage,
                'company_epf_amount' => $calculation['epf_company'], 'company_etf_percentage' => $technician->etf_company_percentage,
                'company_etf_amount' => $calculation['etf_company'],
            ]);
            if ((float) ($data['manual_additions'] ?? 0) > 0) {
                $sheet->additions()->create(['technician_id' => $technician->id, 'addition_name' => ($data['addition_name'] ?? null) ?: 'Manual addition', 'amount' => $data['manual_additions'], 'reason' => $data['addition_reason'] ?? null, 'addition_date' => today(), 'created_by' => auth()->id()]);
            }
            if (count($manualDeductionsList) > 0) {
                foreach ($manualDeductionsList as $md) {
                    if (! empty($md['amount']) && $md['amount'] > 0) {
                        $sheet->paymentDeductions()->create(['technician_id' => $technician->id, 'deduction_name' => $md['name'] ?? 'Manual deduction', 'amount' => $md['amount'], 'reason' => null, 'deduction_date' => today(), 'created_by' => auth()->id()]);
                    }
                }
            } elseif ((float) ($data['manual_deductions'] ?? 0) > 0) {
                $sheet->paymentDeductions()->create(['technician_id' => $technician->id, 'deduction_name' => 'Manual deduction', 'amount' => $data['manual_deductions'], 'reason' => null, 'deduction_date' => today(), 'created_by' => auth()->id()]);
            }

            return $sheet;
        });

        return redirect()->route('technicians.salaries')->with('success', "Salary sheet {$sheet->number} saved as draft.");
    }

    public function salaryFinalize(Request $request, TechnicianSalarySheet $sheet, SalaryCalculationService $calculator)
    {
        abort_unless($sheet->status === 'Draft', 422, 'Only Draft salary sheets can be finalized.');

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_date' => ['nullable', 'date'],
            'payment_note' => ['nullable', 'max:1000'],
        ]);

        return DB::transaction(function () use ($sheet, $data, $request, $calculator) {
            $sheet = $calculator->finalizeSalaryPayment($sheet, auth()->id());
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'Salary Finalized', 'auditable_type' => $sheet->getMorphClass(), 'auditable_id' => $sheet->id, 'new_values' => ['status' => 'Finalized'], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

            if (! empty($data['amount']) && $data['amount'] > 0) {
                $request->validate(['account_id' => 'required', 'payment_method' => 'required']);

                return $this->salaryPay($request, $sheet);
            }

            return back()->with('success', "{$sheet->number} finalized and attendance locked.");
        });
    }

    public function salaryPay(Request $request, TechnicianSalarySheet $sheet)
    {
        abort_unless(in_array($sheet->status, ['Finalized', 'Partially Paid'], true), 422);
        $data = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'account_id' => ['required', 'exists:accounts,id'], 'payment_method' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Card', 'Online', 'Other'])], 'payment_date' => ['nullable', 'date'], 'payment_note' => ['nullable', 'max:1000']]);
        DB::transaction(function () use ($sheet, $data) {
            $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless(in_array($sheet->status, ['Finalized', 'Partially Paid'], true), 422);
            $paid = (float) $sheet->paid_amount + (float) $data['amount'];
            $balance = round((float) $sheet->net_payable - $paid, 2);
            $overpaid = max(0, -$balance);
            $sheet->update(['paid_amount' => $paid, 'balance_amount' => $balance, 'overpaid_amount' => $overpaid, 'status' => $balance <= 0 ? 'Paid' : 'Partially Paid', 'account_id' => $data['account_id'], 'payment_method' => $data['payment_method'], 'payment_date' => $data['payment_date'] ?? today(), 'payment_note' => $data['payment_note'] ?? null, 'paid_at' => $balance <= 0 ? now() : null, 'paid_by' => auth()->id()]);
            if ($overpaid > 0) {
                TechnicianDeduction::updateOrCreate(
                    ['technician_id' => $sheet->technician_id, 'source_type' => 'salary_overpayment', 'source_id' => $sheet->id],
                    ['reference_no' => $sheet->number, 'deduction_name' => 'Previous salary overpayment adjustment', 'amount' => $overpaid, 'reason' => 'Automatically carried forward from an overpaid salary.', 'deduction_date' => $sheet->period_start->copy()->addMonth(), 'status' => 'pending', 'created_by' => auth()->id(), 'updated_by' => auth()->id()]
                );
            }
            app(AccountService::class)->outgoing('main', (float) $data['amount'], "Salary payment {$sheet->number}", $sheet, $data['payment_method'], $data['payment_date'] ?? today(), 'salary');
        });
        AuditLog::create(['user_id' => auth()->id(), 'event' => 'Salary Payment Recorded', 'auditable_type' => $sheet->getMorphClass(), 'auditable_id' => $sheet->id, 'new_values' => ['amount' => $data['amount'], 'status' => $sheet->fresh()->status], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return back()->with('success', 'Salary payment recorded.');
    }

    public function salaryReopen(Request $request, TechnicianSalarySheet $sheet, SalaryCalculationService $calculator)
    {
        $data = $request->validate(['reason' => ['required', 'min:5', 'max:1000']]);
        $calculator->reopenSalaryPayment($sheet, $data['reason'], auth()->id());
        AuditLog::create(['user_id' => auth()->id(), 'event' => 'Salary Reopened', 'auditable_type' => $sheet->getMorphClass(), 'auditable_id' => $sheet->id, 'new_values' => ['status' => 'Draft', 'reason' => $data['reason']], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return back()->with('success', "{$sheet->number} reopened for correction.");
    }

    public function salaryDestroy(TechnicianSalarySheet $sheet)
    {
        abort_unless($sheet->status === 'Draft', 422, 'Only Draft salary sheets can be deleted.');

        DB::transaction(function () use ($sheet) {
            TechnicianContribution::where('salary_sheet_id', $sheet->id)->delete();
            $sheet->additions()->delete();
            $sheet->paymentDeductions()->delete();
            $sheet->delete();
        });

        AuditLog::create(['user_id' => auth()->id(), 'event' => 'Salary Sheet Deleted', 'auditable_type' => $sheet->getMorphClass(), 'auditable_id' => $sheet->id, 'new_values' => ['status' => 'Deleted'], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return back()->with('success', "Salary sheet {$sheet->number} deleted successfully.");
    }

    public function salarySlip(TechnicianSalarySheet $sheet)
    {
        return view('technicians.salary-slip', ['sheet' => $sheet->load(['technician', 'preparedBy', 'additions', 'paymentDeductions', 'sourceDeductions', 'advancesUsed'])]);
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

            app(AccountService::class)->outgoing('main', (float) $data['amount'], "Technician advance {$advance->number}", $advance, $data['payment_method'], $data['payment_date'], 'salary_advance');
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
        $bike = Bike::findOrFail($data['bike_id']);
        if (! $bike->is_technician_bike || (int) $bike->technician_id !== (int) $data['technician_id']) {
            throw ValidationException::withMessages(['bike_id' => 'Select a bike belonging to this staff member.']);
        }
        if ($job && ($job->intake->bike_id !== $bike->id || ! $job->invoice || $job->invoice->status === 'Cancelled')) {
            throw ValidationException::withMessages(['job_card_id' => 'Select a billed job for this staff bike.']);
        }
        DB::transaction(function () use ($data, $job) {
            if ($job) {
                $invoice = Invoice::lockForUpdate()->findOrFail($job->invoice->id);
                $assigned = TechnicianBikeDeduction::where('invoice_id', $invoice->id)->whereIn('status', ['Active', 'Completed'])->sum(DB::raw('total_amount-deducted_amount'));
                if ((float) $data['total_amount'] + (float) $assigned > (float) $invoice->due_amount) {
                    throw ValidationException::withMessages(['total_amount' => 'Salary recovery exceeds the unassigned invoice due.']);
                }
            }
            $data['start_month'] .= '-01';
            TechnicianBikeDeduction::create($data + ['number' => 'TBD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'invoice_id' => $job?->invoice?->id, 'next_deduction_month' => $data['start_month'], 'created_by' => auth()->id()]);
        });

        return back()->with('success', 'Own-bike salary deduction plan created.');
    }

    public function contributions(Request $request)
    {
        $records = TechnicianContribution::with('technician')->latest('month')->paginate(20);

        return view('technicians.contributions', ['records' => $records, 'technicians' => $this->technicians(), 'stats' => ['employee' => TechnicianContribution::whereMonth('month', now()->month)->sum('employee_epf_amount'), 'company' => TechnicianContribution::whereMonth('month', now()->month)->sum('company_epf_amount'), 'etf' => TechnicianContribution::whereMonth('month', now()->month)->sum('company_etf_amount'), 'total' => TechnicianContribution::whereMonth('month', now()->month)->sum(DB::raw('employee_epf_amount+company_epf_amount+company_etf_amount'))]]);
    }

    public function history(Request $request, SalaryCycleService $salaryCycle)
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $technician = null;
        $historyPeriod = null;
        if ($request->technician) {
            $from = null;
            $to = null;
            if ($request->month) {
                $historyPeriod = $salaryCycle->previewSalaryPeriod($request->month.'-01');
                $from = Carbon::parse($historyPeriod['salary_period_start'])->startOfDay();
                $to = Carbon::parse($historyPeriod['salary_period_end'])->endOfDay();
            } elseif ($request->from || $request->to) {
                $from = $request->from ? Carbon::parse($request->from)->startOfDay() : null;
                $to = $request->to ? Carbon::parse($request->to)->endOfDay() : null;
                $historyPeriod = [
                    'salary_cycle_label' => 'Custom Date Range',
                    'salary_month_label' => 'Custom Range',
                    'salary_period_start' => $from?->toDateString(),
                    'salary_period_end' => $to?->toDateString(),
                    'salary_period_days' => $from && $to ? $from->diffInDays($to) + 1 : null,
                    'carried_from_previous_month_days' => 0,
                    'carried_to_next_month_days' => 0,
                    'excluded_date_labels' => [],
                ];
            } else {
                $historyPeriod = $salaryCycle->previewSalaryPeriod(now()->startOfMonth());
                $from = Carbon::parse($historyPeriod['salary_period_start'])->startOfDay();
                $to = Carbon::parse($historyPeriod['salary_period_end'])->endOfDay();
            }

            $technician = Technician::with([
                'jobs' => function ($q) use ($from, $to) {
                    if ($from) {
                        $q->where('created_at', '>=', $from);
                    }
                    if ($to) {
                        $q->where('created_at', '<=', $to);
                    }
                    $q->with('intake.bike.customer', 'invoice');
                },
                'attendance' => function ($q) use ($from, $to) {
                    if ($from) {
                        $q->where('date', '>=', $from);
                    }
                    if ($to) {
                        $q->where('date', '<=', $to);
                    }
                },
                'salarySheets' => function ($q) use ($from, $to) {
                    if ($from && $to) {
                        $q->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from);
                    } elseif ($from) {
                        $q->whereDate('period_end', '>=', $from);
                    } elseif ($to) {
                        $q->whereDate('period_start', '<=', $to);
                    }
                },
                'advances' => function ($q) use ($from, $to) {
                    if ($from) {
                        $q->where('payment_date', '>=', $from);
                    }
                    if ($to) {
                        $q->where('payment_date', '<=', $to);
                    }
                },
                'commissions' => function ($q) use ($from, $to) {
                    if ($from) {
                        $q->where('earned_date', '>=', $from);
                    }
                    if ($to) {
                        $q->where('earned_date', '<=', $to);
                    }
                    $q->with('jobCard');
                },
                'bikeDeductions.bike',
                'contributions',
            ])->find($request->technician);
        }

        return view('technicians.history', ['technicians' => $this->technicians(), 'technician' => $technician, 'historyPeriod' => $historyPeriod, 'auditLogs' => $technician ? AuditLog::where('auditable_type', $technician->getMorphClass())->where('auditable_id', $technician->id)->latest()->limit(50)->get() : collect()]);
    }

    private function technicians()
    {
        return Technician::where('active', true)->orderBy('name')->get();
    }

    private function technicianData(Request $request, ?Technician $technician = null): array
    {
        return $request->validate(['attendance_person_id' => ['nullable', 'max:80', Rule::unique('technicians', 'attendance_person_id')->ignore($technician)], 'department' => ['nullable', 'max:150'], 'name' => ['required', 'max:150'], 'phone' => ['required', 'max:30', Rule::unique('technicians', 'phone')->ignore($technician)], 'alternative_phone' => ['nullable', 'max:30'], 'nic' => ['nullable', 'max:30'], 'address' => ['nullable', 'max:1000'], 'emergency_contact' => ['nullable', 'max:100'], 'joining_date' => ['nullable', 'date'], 'active' => ['required', 'boolean'], 'basic_salary' => ['required', 'numeric', 'min:0'], 'salary_type' => ['required', Rule::in(['Monthly', 'Daily', 'Weekly'])], 'daily_salary' => ['nullable', 'numeric', 'min:0'], 'ot_rate' => ['nullable', 'numeric', 'min:0'], 'late_deduction_rate' => ['nullable', 'numeric', 'min:0'], 'no_pay_deduction_rate' => ['nullable', 'numeric', 'min:0'], 'commission_enabled' => ['nullable', 'boolean'], 'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'commission_type' => ['required', 'max:100'], 'epf_number' => ['nullable', 'max:50'], 'etf_number' => ['nullable', 'max:50'], 'epf_eligible' => ['nullable', 'boolean'], 'etf_eligible' => ['nullable', 'boolean'], 'epf_employee_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'epf_company_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'etf_company_percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'role' => ['nullable', Rule::in(['technician', 'staff'])], 'designation' => ['nullable', 'max:100']]);
    }
}
