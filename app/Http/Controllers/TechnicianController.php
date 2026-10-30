<?php

namespace App\Http\Controllers;

use App\Exports\TechnicianAttendanceExport;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AttendanceImportBatch;
use App\Models\AuditLog;
use App\Models\Bike;
use App\Models\HolidayDate;
use App\Models\JobCard;
use App\Models\OtSlabRule;
use App\Models\SalarySetting;
use App\Models\Technician;
use App\Models\TechnicianAdvance;
use App\Models\TechnicianAttendance;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianCommission;
use App\Models\TechnicianContribution;
use App\Models\TechnicianSalarySheet;
use App\Services\AccountService;
use App\Services\AttendanceCalculationService;
use App\Services\AttendanceImportService;
use App\Services\AttendanceRecalculationService;
use App\Services\HolidayService;
use App\Services\JobCommissionService;
use App\Services\OutgoingFundingService;
use App\Services\SalaryBalanceService;
use App\Services\SalaryCalculationService;
use App\Services\SalaryCycleService;
use App\Services\SalaryRepairCorrectionService;
use App\Services\SalarySheetDetailService;
use App\Services\StaffBikeRepairService;
use App\Support\PrintDocument;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;

class TechnicianController extends Controller
{
    public function index(Request $request)
    {
        $month = now()->startOfMonth();
        $query = Technician::query()->withCount(['jobs as month_jobs_count' => fn (Builder $q) => $q->where('created_at', '>=', $month)])
            ->withSum(['commissions as month_commission' => fn (Builder $q) => $q->where('earned_date', '>=', $month)], 'amount')
            ->withSum(['advances as advance_balance' => fn (Builder $q) => $q->where('status', 'Pending Deduction')], 'amount')
            ->with('bikeDeductions.invoice')
            ->with(['attendance' => fn ($q) => $q->whereDate('date', today())])
            ->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $nested) => $nested->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))
            ->when($request->status !== null && $request->status !== '', fn (Builder $q) => $q->where('active', $request->status === 'active'))
            ->when($request->salary_type, fn (Builder $q, string $type) => $q->where('salary_type', $type))
            ->when($request->commission !== null && $request->commission !== '', fn (Builder $q) => $q->where('commission_enabled', $request->commission === 'yes'));

        $technicians = $query->latest()->paginate(15)->withQueryString();
        foreach ($technicians as $technician) {
            $technician->bike_balance = $technician->bikeDeductions->where('status', 'Active')->sum('recoverable_balance');
        }

        return view('technicians.index', [
            'technicians' => $technicians,
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
        $query = TechnicianAttendance::with('technician')->when($request->technician, fn (Builder $q, $id) => $q->where('technician_id', $id))
            ->when($request->status, fn (Builder $q, $status) => $q->where('status', $status))
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()]);
        $records = (clone $query)->orderByDesc('date')->paginate(31)->withQueryString();

        $presentStatuses = ['Present', 'Late', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Early Leave', 'Auto Closed', 'Worked on Leave Day'];
        $batch = $request->filled('batch') ? AttendanceImportBatch::where('imported_by', auth()->id())->find($request->batch) : null;
        $calendarService = app(HolidayService::class);
        $shopCalendar = $calendarService->calendar();
        $previewRows = collect($batch?->preview_data ?? [])->map(fn ($row) => $calendarService->importRow($row, $shopCalendar))->all();
        $calendarVersion = $calendarService->calendarVersion();
        $specialClosures = HolidayDate::whereBetween('holiday_date', [$from->copy()->startOfMonth(), $to->copy()->endOfMonth()])->where('is_active', true)->where('is_leave_enabled', true)->orderBy('holiday_date')->get();
        $recentBatches = AttendanceImportBatch::with('importer')->latest()->limit(8)->get();

        return view('technicians.attendance', [
            'records' => $records, 'technicians' => $this->technicians(), 'date' => $date, 'from' => $from, 'to' => $to,
            'previewBatch' => $batch, 'previewRows' => $previewRows, 'shopCalendar' => $shopCalendar, 'calendarVersion' => $calendarVersion, 'specialClosures' => $specialClosures, 'recentBatches' => $recentBatches,
            'salarySettings' => SalarySetting::firstOrCreate([])->refresh(), 'otSlabs' => OtSlabRule::where('status', 'Active')->orderBy('from_minutes')->get(),
            'stats' => [
                'present' => (clone $query)->whereIn('status', $presentStatuses)->count(),
                'absent' => (clone $query)->whereIn('status', ['Absent', 'No Pay Leave'])->count(),
                'late' => (clone $query)->where('late_minutes', '>', 0)->count(),
                'leave' => (clone $query)->whereIn('status', ['Leave', 'Paid Leave'])->count(),
                'ot' => (clone $query)->sum('ot_hours'),
            ],
        ]);
    }

    public function attendanceExport(Request $request)
    {
        $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['nullable', 'in:excel,pdf,csv'],
        ]);

        if ($request->filled('month')) {
            $from = Carbon::createFromFormat('Y-m', $request->month)->startOfMonth();
            $to = $from->copy()->endOfMonth();
            $label = $from->format('Y-m');
        } elseif ($request->filled('date')) {
            $from = Carbon::parse($request->date);
            $to = $from->copy();
            $label = $from->format('Y-m-d');
        } elseif ($request->filled('from') || $request->filled('to')) {
            $from = $request->filled('from') ? Carbon::parse($request->from) : today()->startOfMonth();
            $to = $request->filled('to') ? Carbon::parse($request->to) : today()->endOfMonth();
            $label = $from->format('Ymd').'-'.$to->format('Ymd');
        } else {
            $from = today()->startOfMonth();
            $to = today()->endOfMonth();
            $label = $from->format('Y-m');
        }

        $technician = $request->filled('technician') ? Technician::find($request->technician) : null;
        $status = $request->input('status');

        $query = TechnicianAttendance::with('technician')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($technician, fn (Builder $q) => $q->where('technician_id', $technician->id))
            ->when($status, fn (Builder $q, $s) => $q->where('status', $s))
            ->orderBy('date', 'asc')
            ->orderBy('technician_id', 'asc');

        $prefix = 'attendance';
        if ($technician) {
            $prefix .= '_'.Str::slug($technician->name);
        }
        if ($status) {
            $prefix .= '_'.Str::slug($status);
        }
        $filename = "{$prefix}_{$label}";

        $format = $request->input('format', 'excel');

        if ($format === 'pdf') {
            $records = $query->get();
            $pdf = PrintDocument::pdf('technicians.exports.attendance-pdf', [
                'records' => $records,
                'from' => $from,
                'to' => $to,
                'technician' => $technician,
                'status' => $status,
                'printedBy' => $request->user(),
            ]);

            return $request->boolean('print') ? $pdf->stream("{$filename}.pdf") : $pdf->download("{$filename}.pdf");
        }

        if ($format === 'csv') {
            return Excel::download(new TechnicianAttendanceExport($query), "{$filename}.csv", \Maatwebsite\Excel\Excel::CSV);
        }

        return Excel::download(new TechnicianAttendanceExport($query), "{$filename}.xlsx");
    }

    public function attendancePreview(Request $request, AttendanceCalculationService $calculator)
    {
        $data = $request->validate([
            'technician_id' => ['required', 'integer', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['Present', 'Absent', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Leave', 'Paid Leave', 'No Pay Leave', 'Holiday', 'Early Leave', 'Late', 'Worked on Leave Day', 'Not Worked'])],
            'in_time' => ['nullable', 'date_format:H:i'],
            'out_time' => ['nullable', 'date_format:H:i'],
        ]);

        return response()->json($calculator->calculateDailyAttendance(
            Technician::findOrFail($data['technician_id']), $data['date'], $data['status'], $data['in_time'] ?? null, $data['out_time'] ?? null,
        ));
    }

    public function attendanceStore(Request $request, AttendanceCalculationService $calculator)
    {
        $bulk = $request->has('technician_ids') && is_array($request->input('technician_ids'));
        $ids = $bulk ? $request->input('technician_ids') : [$request->input('technician_id')];

        $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['Present', 'Absent', 'Half Day', 'Morning Half Day', 'Evening Half Day', 'Leave', 'Paid Leave', 'No Pay Leave', 'Holiday', 'Early Leave', 'Late', 'Worked on Leave Day', 'Not Worked'])],
            'in_time' => ['nullable', 'date_format:H:i'],
            'out_time' => ['nullable', 'date_format:H:i'],
            'ot_amount' => ['nullable', 'numeric', 'min:0'],
            'deduction_amount' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'max:1000'],
            'edit_reason' => ['nullable', 'max:500'],
            'is_manual_edit' => ['nullable'],
        ]);

        if ($bulk) {
            $request->validate([
                'technician_ids' => ['required', 'array', 'min:1', 'max:500'],
                'technician_ids.*' => ['required', 'integer', 'distinct', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            ]);
        } else {
            $request->validate([
                'technician_id' => ['required', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
            ]);
        }

        $date = $request->input('date');
        $savedCount = DB::transaction(function () use ($ids, $bulk, $date, $request, $calculator) {
            $savedCount = 0;
            sort($ids, SORT_NUMERIC);

            foreach ($ids as $techId) {
                if (! $techId) {
                    continue;
                }

                $technician = Technician::lockForUpdate()->findOrFail($techId);

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
                if ($oldRecord && empty($request->edit_reason) && (! $bulk || $request->input('status') === 'Not Worked')) {
                    throw ValidationException::withMessages(['edit_reason' => 'Edit Reason is required when updating attendance record.']);
                }

                $status = $request->input('status');
                $isManual = $status !== 'Not Worked' && $request->has('is_manual_edit') && $request->input('is_manual_edit') == '1';

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
                            'in_time' => $payload['in_time'] ?? null,
                            'out_time' => $payload['out_time'] ?? null,
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

            return $savedCount;
        }, 3);

        return back()->with('success', "Attendance saved for {$savedCount} technician(s) and metrics calculated.");
    }

    public function attendanceRecalculateAll(Request $request, AttendanceRecalculationService $recalculator)
    {
        $data = $request->validate([
            'scope' => ['required', Rule::in(['filtered', 'all_dates'])],
            'from' => ['required_if:scope,filtered', 'nullable', 'date_format:Y-m-d'],
            'to' => ['required_if:scope,filtered', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'technician' => ['nullable', 'integer', 'exists:technicians,id'],
            'status' => ['nullable', 'string', 'max:80'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);
        $query = TechnicianAttendance::query()->whereHas('technician')
            ->when($data['technician'] ?? null, fn ($q, $id) => $q->where('technician_id', $id))
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status));
        if ($data['scope'] === 'filtered') {
            $query->whereBetween('date', [$data['from'], $data['to']]);
        }
        $counts = $recalculator->run($query, $data['reason']);
        $updated = $counts['recalculated'] + $counts['manual'];
        $message = "{$updated} attendance record(s) recalculated across all pages. {$counts['locked']} skipped (finalized salary); {$counts['manual']} retained manual amounts; {$counts['invalid']} skipped (invalid times).";
        $message .= ' Existing draft paysheets can be updated using Salary Payments → View → Refresh Attendance & OT.';

        return back()->with('success', $message)->with('attendance_recalculation', $counts);
    }

    public function attendanceRecalculate(Request $request, TechnicianAttendance $record, AttendanceCalculationService $calculator)
    {
        DB::transaction(function () use ($request, $record, $calculator) {
            $staff = Technician::whereKey($record->technician_id)->lockForUpdate()->firstOrFail();
            $record = TechnicianAttendance::lockForUpdate()->findOrFail($record->id);
            $date = Carbon::parse($record->date)->toDateString();
            if (TechnicianSalarySheet::where('technician_id', $staff->id)->whereDate('period_start', '<=', $date)->whereDate('period_end', '>=', $date)->whereIn('status', ['Finalized', 'Partially Paid', 'Paid'])->exists()) {
                throw ValidationException::withMessages(['date' => 'Reopen the finalized paysheet before recalculating this attendance.']);
            }
            $before = $record->getAttributes();
            $metrics = $calculator->calculateDailyAttendance($staff, $date, $record->status,
                $record->in_time ? substr($record->in_time, 0, 5) : null,
                $record->out_time ? substr($record->out_time, 0, 5) : null,
                ['break_minutes' => $record->break_minutes, 'auto_filled_checkout' => $record->is_auto_checkout]);
            $record->update($metrics + ['is_manual_edit' => false, 'edit_reason' => 'Recalculated using current attendance settings', 'edited_by' => auth()->id(), 'updated_by' => auth()->id()]);
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'Attendance Recalculated', 'auditable_type' => $staff->getMorphClass(), 'auditable_id' => $staff->id, 'old_values' => $before, 'new_values' => $record->getAttributes(), 'ip_address' => $request->ip(), 'user_agent' => $request->userAgent()]);
        }, 3);

        return back()->with('success', 'Attendance recalculated.');
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
            'date_from' => ['required', 'date_format:Y-m-d'], 'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'status' => ['required', Rule::in(['Present', 'Absent', 'Half Day', 'Leave', 'Paid Leave', 'No Pay Leave', 'Holiday'])],
            'in_time' => ['nullable', 'date_format:H:i'], 'out_time' => ['nullable', 'date_format:H:i'], 'note' => ['nullable', 'max:1000'],
        ]);
        $from = Carbon::parse($data['date_from']);
        $to = Carbon::parse($data['date_to']);
        if ($from->diffInDays($to) > 62) {
            throw ValidationException::withMessages(['date_to' => 'Bulk attendance is limited to 63 days at a time.']);
        }
        $saved = DB::transaction(function () use ($from, $to, $data, $calculator) {
            $saved = 0;
            $staff = Technician::whereIn('id', $data['technician_ids'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($from->copy()->daysUntil($to) as $date) {
                foreach ($data['technician_ids'] as $technicianId) {
                    $technician = $staff->get($technicianId);
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
                        'in_time' => $data['in_time'] ?? null, 'out_time' => $data['out_time'] ?? ($metrics['out_time'] ?? null), 'note' => $data['note'] ?? 'Bulk attendance entry',
                        'is_manual_entry' => true, 'is_manual_edit' => (bool) $existing, 'created_by' => $existing?->created_by ?? auth()->id(), 'edited_by' => $existing ? auth()->id() : null, 'updated_by' => auth()->id(),
                    ]));
                    $saved++;
                }
            }

            return $saved;
        }, 3);

        return back()->with('success', "{$saved} daily attendance records saved across the selected period.");
    }

    public function attendanceClosureStore(Request $request)
    {
        abort_unless($request->user()->canAccess('settings.salary'), 403);
        $data = $request->validate([
            'closure_date' => ['required', 'date_format:Y-m-d'],
            'closure_name' => ['required', 'string', 'max:255'],
            'month' => ['required', 'date_format:Y-m'],
            'batch' => ['nullable', 'integer'],
        ]);
        if (substr($data['closure_date'], 0, 7) !== $data['month']) {
            throw ValidationException::withMessages(['closure_date' => 'Choose a closed date within the displayed month.']);
        }
        DB::transaction(function () use ($data, $request) {
            $holiday = HolidayDate::firstOrNew(['holiday_date' => $data['closure_date']]);
            if ($holiday->exists && $holiday->is_active && $holiday->is_leave_enabled) {
                throw ValidationException::withMessages(['closure_date' => 'This date is already marked as shop closed.']);
            }
            if ($holiday->exists) {
                // Keep existing public-holiday identity when activating its closure.
                $holiday->fill(['is_active' => true, 'is_leave_enabled' => true, 'updated_by' => $request->user()->id]);
            } else {
                $holiday->fill(['holiday_name' => $data['closure_name'], 'holiday_type' => 'Company',
                    'is_active' => true, 'is_leave_enabled' => true, 'source' => 'Attendance', 'created_by' => $request->user()->id]);
            }
            $holiday->save();
        });

        return redirect()->route('technicians.attendance.index', array_filter(['month' => $data['month'], 'batch' => $data['batch'] ?? null]))
            ->with('success', 'Special shop closed day saved. Review the updated attendance preview before importing.');
    }

    public function attendanceImportConfirm(Request $request, AttendanceImportBatch $batch, AttendanceImportService $importer, AttendanceCalculationService $calculator)
    {
        $request->validate([
            'calendar_version' => ['nullable', 'string', 'size:64'],
            'rows.*.mark_leave' => ['nullable', 'boolean'],
            'rows' => ['nullable', 'array'], 'rows.*.row' => ['required', 'integer', 'distinct'],
            'rows.*.date' => ['required', 'date_format:Y-m-d'],
            'rows.*.check_in' => ['nullable', 'date_format:H:i,H:i:s'], 'rows.*.check_out' => ['nullable', 'date_format:H:i,H:i:s'],
            'rows.*.technician_id' => ['nullable', 'integer', Rule::exists('technicians', 'id')->whereNull('deleted_at')],
        ]);

        return DB::transaction(function () use ($request, $batch, $importer, $calculator) {
            $batch = AttendanceImportBatch::lockForUpdate()->findOrFail($batch->id);
            abort_unless($batch->imported_by === auth()->id() && $batch->status === 'Preview', 403);

            if ($request->filled('calendar_version') && ! hash_equals(app(HolidayService::class)->calendarVersion(), $request->input('calendar_version'))) {
                throw ValidationException::withMessages(['calendar_version' => 'The shop calendar changed or this preview is from an earlier day. Reload it and review the leave boxes before importing.']);
            }

            if ($request->has('rows') && is_array($request->input('rows'))) {
                $submittedRows = collect($request->input('rows'))->keyBy('row');
                $updatedPreviewData = [];
                foreach ($batch->preview_data as $item) {
                    $rowId = $item['row'];
                    if ($submittedRows->has($rowId)) {
                        $rowInput = $submittedRows->get($rowId);
                        if (array_key_exists('mark_leave', $rowInput)) {
                            $item['mark_leave'] = (bool) $rowInput['mark_leave'];
                            $item['leave_choice_explicit'] = true;
                        }
                        $item['date'] = $rowInput['date'] ?? $item['date'];
                        $item['check_in'] = empty($rowInput['check_in']) ? null : $rowInput['check_in'].(strlen($rowInput['check_in']) == 5 ? ':00' : '');
                        $checkout = empty($rowInput['check_out']) ? null : $rowInput['check_out'].(strlen($rowInput['check_out']) == 5 ? ':00' : '');
                        if ($checkout !== $item['check_out']) {
                            $item['auto_filled_checkout'] = false;
                        }
                        $item['check_out'] = $checkout;
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
        }, 3);
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

    public function attendance(Request $request, Technician $technician, AttendanceCalculationService $calculator)
    {
        $request->merge(['technician_id' => $technician->id]);

        return $this->attendanceStore($request, $calculator);
    }

    public function salaries(Request $request)
    {
        $sheets = TechnicianSalarySheet::with(['technician', 'additions', 'paymentDeductions'])->when($request->technician, fn (Builder $q, $id) => $q->where('technician_id', $id))->latest('period_start')->paginate(15)->withQueryString();
        $month = TechnicianSalarySheet::whereBetween('period_end', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])
            ->when($request->technician, fn (Builder $q, $id) => $q->where('technician_id', $id));

        return view('technicians.salaries', ['sheets' => $sheets, 'technicians' => $this->technicians(), 'accounts' => Account::where('active', true)->get(), 'stats' => [
            'payable' => (clone $month)->sum('net_payable'),
            'paid' => (clone $month)->sum('paid_amount'),
            'balance' => (clone $month)->sum('balance_amount'),
            'employee_epf' => (clone $month)->sum('epf_employee'),
            'company' => (clone $month)->sum(DB::raw('epf_company+etf_company')),
            'overpaid' => (clone $month)->sum('overpaid_amount'),
        ]]);
    }

    private function salaryBalanceRules(): array
    {
        return [
            'old_balance_amount' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'],
            'old_balance_deduction' => ['nullable', 'numeric', 'decimal:0,2', 'between:0,999999999999.99', 'lte:old_balance_amount'],
            'old_balance_description' => ['nullable', 'string', 'max:150'],
            'old_balance_note' => ['nullable', 'string', 'max:500'],
            'old_balance_installments' => ['nullable', 'array'],
            'old_balance_installments.*' => ['required', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'],
        ];
    }

    public function salaryPreview(Request $request, SalaryCalculationService $calculator)
    {
        try {
            $data = $request->validate([
                'technician_id' => ['required', 'exists:technicians,id'], 'month' => ['required', 'date_format:Y-m'],
                'other_allowances' => ['nullable', 'numeric', 'min:0'], 'manual_addition' => ['nullable', 'numeric', 'min:0'],
                'other_deductions' => ['nullable', 'numeric', 'min:0'], 'manual_deduction' => ['nullable', 'numeric', 'min:0'],
                'paid_amount' => ['nullable', 'numeric', 'min:0'], 'worked_on_leave_pay' => ['nullable', 'numeric', 'min:0'],
                'bike_installments' => ['nullable', 'array'], 'bike_installments.*' => ['numeric', 'min:0'],
            ] + $this->salaryBalanceRules());
            // Preview may receive stale choices while changing staff/month. Saving
            // still validates every submitted debt against the selected staff.
            $eligibleOldBalances = array_column(app(SalaryBalanceService::class)->items((int) $data['technician_id'], $data['month'].'-01'), 'id');
            $data['old_balance_installments'] = array_intersect_key($data['old_balance_installments'] ?? [], array_flip($eligibleOldBalances));
            try {
                $response = $calculator->generateSalaryPreview((int) $data['technician_id'], $data['month'].'-01', $data);
            } catch (ValidationException $exception) {
                if ($request->has('bike_installments') || ! isset($exception->errors()['bike_installments'])) {
                    throw $exception;
                }
                $pending = $calculator->getTechnicianPendingDeductions((int) $data['technician_id'], $data['month'].'-01');
                $data['bike_installments'] = collect($pending['items'])->where('kind', 'bike_installment')->mapWithKeys(fn ($item) => [$item['id'] => 0])->all();
                $response = $calculator->generateSalaryPreview((int) $data['technician_id'], $data['month'].'-01', $data);
                $response['repair_deduction_warning'] = 'Scheduled repairs exceed available salary. Enter a smaller amount for each repair before saving.';
            }
            $salaryCycle = app(SalaryCycleService::class);
            $conflicts = $salaryCycle->conflictingSheets((int) $data['technician_id'], $response['salary_cycle']);
            $existing = $conflicts->first(fn ($sheet) => $sheet->status !== 'Draft') ?? $conflicts->first();
            $response['existing_sheet_status'] = $existing?->status;
            $response['existing_sheet_can_replace'] = $conflicts->isNotEmpty() && $conflicts->every(fn ($sheet) => $sheet->status === 'Draft' && $salaryCycle->sheetSalaryMonth($sheet) === $data['month']);

            return response()->json($response);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first() ?: $exception->getMessage(),
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() ?: 'An error occurred while calculating the salary preview.',
            ], 500);
        }
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
            'manual_deductions.*.amount' => ['required_with:manual_deductions.*.name', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'manual_deductions.*.reason' => ['nullable', 'string', 'max:500'],
            'addition_name' => ['nullable', 'max:150'], 'addition_reason' => ['nullable', 'max:500'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'], 'payment_method' => ['nullable', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Card', 'Online', 'Other'])],
            'payment_date' => ['nullable', 'date'], 'payment_note' => ['nullable', 'max:1000'], 'worked_on_leave_pay' => ['nullable', 'numeric', 'min:0'],
            'action' => ['nullable', Rule::in(['preview', 'save'])],
        ] + $this->salaryBalanceRules());

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
        $sheet = DB::transaction(function () use ($data, $technician, $calculator, $manualDeductionsList) {
            $technician = Technician::whereKey($technician->id)->lockForUpdate()->firstOrFail();
            $calculation = $calculator->generateMonthlySalary($technician, $data['month'].'-01', $data);
            $salaryCycle = app(SalaryCycleService::class);
            $conflicts = $salaryCycle->conflictingSheets($technician->id, $calculation['salary_cycle'], true);
            foreach ($conflicts as $existing) {
                if ($existing->status !== 'Draft' || $salaryCycle->sheetSalaryMonth($existing) !== $data['month']) {
                    throw ValidationException::withMessages(['month' => "Salary sheet {$existing->number} already covers this month or overlaps this period. Resolve that sheet before generating another salary."]);
                }
                $existing->additions()->delete();
                $existing->paymentDeductions()->delete();
                TechnicianContribution::where('salary_sheet_id', $existing->id)->delete();
                $existing->delete();
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
            ] + $calculation['calculation_snapshot']['contributions']);
            if ((float) ($data['manual_additions'] ?? 0) > 0) {
                $sheet->additions()->create(['technician_id' => $technician->id, 'addition_name' => ($data['addition_name'] ?? null) ?: 'Manual addition', 'amount' => $data['manual_additions'], 'reason' => $data['addition_reason'] ?? null, 'addition_date' => today(), 'created_by' => auth()->id()]);
            }
            if (count($manualDeductionsList) > 0) {
                foreach ($manualDeductionsList as $md) {
                    if (! empty($md['amount']) && $md['amount'] > 0) {
                        $sheet->paymentDeductions()->create(['technician_id' => $technician->id, 'deduction_name' => $md['name'] ?? 'Manual deduction', 'amount' => $md['amount'], 'reason' => $md['reason'] ?? null, 'deduction_date' => today(), 'created_by' => auth()->id()]);
                    }
                }
            } elseif ((float) ($data['manual_deductions'] ?? 0) > 0) {
                $sheet->paymentDeductions()->create(['technician_id' => $technician->id, 'deduction_name' => 'Manual deduction', 'amount' => $data['manual_deductions'], 'reason' => null, 'deduction_date' => today(), 'created_by' => auth()->id()]);
            }

            return $sheet;
        });

        return redirect()->route('technicians.salaries')->with('success', "Salary sheet {$sheet->number} saved as draft.");
    }

    public function salaryRepairDeductions(TechnicianSalarySheet $sheet, SalaryCalculationService $calculator)
    {
        $sheet->load('repairPayments');
        $snapshotItems = collect(data_get($sheet->calculation_snapshot, 'pending_deductions.items', []))->where('kind', 'bike_installment');
        $editable = $sheet->status === 'Draft';
        $items = $editable
            ? collect($calculator->getTechnicianPendingDeductions($sheet->technician_id, data_get($sheet->calculation_snapshot, 'salary_cycle.salary_month', $sheet->period_end), $sheet->period_end)['items'])->where('kind', 'bike_installment')
            : $snapshotItems;
        $rows = $items->map(function ($item) use ($snapshotItems, $editable, $sheet) {
            $plan = TechnicianBikeDeduction::with(['invoice', 'jobCard', 'bike'])->find($item['id']);
            $remaining = $editable ? $item['remaining_balance'] : min(
                max(0, (float) ($plan?->total_amount ?? 0) - (float) ($plan?->deducted_amount ?? 0)),
                (float) ($plan?->invoice?->due_amount ?? 0),
            );
            $item['remaining_balance'] = $remaining;
            $item['job_number'] = $plan?->jobCard?->number;
            $item['bike_number'] = $plan?->bike?->number;
            $item['invoice_number'] = $plan?->invoice?->number;
            $item['salary_number'] = $sheet->number;
            $payment = $sheet->repairPayments->firstWhere('technician_bike_deduction_id', $item['id']);
            $item['correction_url'] = ! $editable && $payment ? route('technicians.salaries.repairs.edit', $payment) : null;
            if ($editable) {
                $item['amount'] = min((float) ($snapshotItems->firstWhere('id', $item['id'])['amount'] ?? $item['amount']), $remaining);
            }

            return $item;
        })->values();

        return response()->json([
            'editable' => $editable, 'rows' => $rows, 'paid_amount' => (float) $sheet->paid_amount,
            'net_before_repairs' => round((float) $sheet->net_payable + (float) $snapshotItems->sum('amount'), 2),
        ]);
    }

    public function salaryFinalize(Request $request, TechnicianSalarySheet $sheet, SalaryCalculationService $calculator)
    {
        abort_unless($sheet->status === 'Draft', 422, 'Only Draft salary sheets can be finalized.');

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2', 'max:999999999999.99'],
            'account_id' => ['nullable', 'exists:accounts,id'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'payment_note' => ['nullable', 'max:1000'],
            'repair_selection_reviewed' => ['nullable', 'boolean'],
            'bike_installments' => ['nullable', 'array'], 'bike_installments.*' => ['numeric', 'min:0', 'decimal:0,2'],
        ]);

        return DB::transaction(function () use ($sheet, $data, $request, $calculator) {
            if ($request->boolean('repair_selection_reviewed') || $request->has('bike_installments')) {
                Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
                $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($sheet->id);
                $sheet = $calculator->updateDraftRepairAmounts($sheet, $data['bike_installments'] ?? []);
            }
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
        if ($request->routeIs('technicians.salaries.pay') && $request->has('bike_installments')) {
            throw ValidationException::withMessages(['bike_installments' => 'Repair deductions were already applied when salary was finalized. Reopen an unpaid sheet to change them.']);
        }
        $data = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2', 'max:999999999999.99'], 'account_id' => ['required', Rule::exists('accounts', 'id')->where('active', true)->where('shop_type', session('shop_mode', 'workshop'))], 'payment_method' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Card', 'Online', 'Other'])], 'payment_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'payment_note' => ['nullable', 'max:1000']] + OutgoingFundingService::rules());
        DB::transaction(function () use ($sheet, $data) {
            Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless(in_array($sheet->status, ['Finalized', 'Partially Paid'], true), 422);
            $paid = ((int) round((float) $sheet->paid_amount * 100) + (int) round((float) $data['amount'] * 100)) / 100;
            $date = Carbon::parse($data['payment_date'] ?? today())->startOfDay();
            $balance = round((float) $sheet->net_payable - $paid, 2);
            $overpaid = max(0, -$balance);
            $funding = app(OutgoingFundingService::class);
            $plan = $funding->plan($data, (float) $data['amount'], $funding->defaultSource((int) $data['account_id'], $data['payment_method']));
            $data['account_id'] = Account::systemAccount($funding->type($plan[0]['source']))->id;
            $sheet->update(['paid_amount' => $paid, 'balance_amount' => $balance, 'overpaid_amount' => $overpaid, 'status' => $balance <= 0 ? 'Paid' : 'Partially Paid', 'account_id' => $data['account_id'], 'payment_method' => $data['payment_method'], 'payment_date' => $date, 'payment_note' => $data['payment_note'] ?? null, 'paid_at' => $balance <= 0 ? $date : null, 'paid_by' => auth()->id()]);
            app(SalaryRepairCorrectionService::class)->syncOverpayment($sheet, 'Automatically carried forward from an overpaid salary.');
            $funding->post($plan, (float) $data['amount'], "Salary payment {$sheet->number}", $sheet, $data['payment_method'], $date, 'salary', $data['payment_note'] ?? null);
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
            Technician::whereKey($sheet->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($sheet->id);
            abort_unless($sheet->status === 'Draft' && (float) $sheet->paid_amount === 0.0, 422, 'Only unpaid Draft salary sheets can be deleted.');
            TechnicianContribution::where('salary_sheet_id', $sheet->id)->delete();
            $sheet->additions()->delete();
            $sheet->paymentDeductions()->delete();
            $sheet->delete();
        });

        AuditLog::create(['user_id' => auth()->id(), 'event' => 'Salary Sheet Deleted', 'auditable_type' => $sheet->getMorphClass(), 'auditable_id' => $sheet->id, 'new_values' => ['status' => 'Deleted'], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return back()->with('success', "Salary sheet {$sheet->number} deleted successfully.");
    }

    public function salaryShow(TechnicianSalarySheet $sheet, SalarySheetDetailService $details)
    {
        return view('technicians.salary-show', ['sheet' => $sheet->load(['technician', 'additions', 'paymentDeductions']),
            'attendanceRows' => collect($details->attendanceRows($sheet))->keyBy('date')]);
    }

    public function salaryAdjustment(Request $request, TechnicianSalarySheet $sheet, SalarySheetDetailService $details)
    {
        $data = $request->validate(['type' => ['required', Rule::in(['addition', 'deduction'])],
            'description' => ['required', 'string', 'max:150'], 'reason' => ['nullable', 'string', 'max:500'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:999999999999.99']]);
        $details->addAdjustment($sheet, $data);

        return back()->with('success', 'Salary adjustment saved and included on the payslip.');
    }

    public function salaryRefreshAttendance(Request $request, TechnicianSalarySheet $sheet, SalarySheetDetailService $details)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $details->refreshAttendance($sheet, $data['reason']);

        return back()->with('success', 'Draft attendance and OT refreshed. Manual amounts and descriptions were retained.');
    }

    public function salarySlip(TechnicianSalarySheet $sheet)
    {
        return view('technicians.salary-slip', ['sheet' => $sheet->load(['technician', 'preparedBy', 'additions', 'paymentDeductions', 'sourceDeductions', 'advancesUsed', 'repairPayments'])]);
    }

    public function advances(Request $request)
    {
        $editingAdvance = null;
        if ($request->old('_editing_advance_id') && $request->user()->canAccess('technician.advances')) {
            $editingAdvance = TechnicianAdvance::where('status', 'Pending Deduction')->whereNull('salary_sheet_id')
                ->find($request->old('_editing_advance_id'));
        }

        return $this->advancePage($editingAdvance);
    }

    private function advancePage(?TechnicianAdvance $editingAdvance = null)
    {
        return view('technicians.advances', [
            'editingAdvance' => $editingAdvance,
            'advances' => TechnicianAdvance::with(['technician', 'account'])->where('status', '!=', 'Cancelled')->latest('payment_date')->latest('id')->paginate(15),
            'technicians' => Technician::where(fn ($query) => $query->where('active', true)->orWhere('id', $editingAdvance?->technician_id))->orderBy('name')->get(),
            'accounts' => Account::where(fn ($query) => $query->where('active', true)->orWhere('id', $editingAdvance?->account_id))->get(),
            'stats' => [
                'today' => TechnicianAdvance::whereDate('payment_date', today())->where('status', '!=', 'Cancelled')->sum('amount'),
                'month' => TechnicianAdvance::whereBetween('payment_date', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])->where('status', '!=', 'Cancelled')->sum('amount'),
                'pending' => TechnicianAdvance::where('status', 'Pending Deduction')->sum('amount'),
                'balance' => TechnicianAdvance::where('status', 'Pending Deduction')->sum('amount'),
            ],
        ]);
    }

    public function advanceStore(Request $request)
    {
        $data = $this->advanceData($request);
        DB::transaction(function () use ($data) {
            $advance = TechnicianAdvance::create(array_merge($data, ['number' => 'ADV-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'created_by' => auth()->id()]));

            $this->postAdvanceLedger($advance);
        });

        return redirect()->route('technicians.advances')->with('success', 'Advance recorded and account ledger updated.');
    }

    public function advanceEdit(TechnicianAdvance $advance)
    {
        $this->ensureAdvanceEditable($advance);

        return $this->advancePage($advance);
    }

    public function advanceUpdate(Request $request, TechnicianAdvance $advance)
    {
        $data = $this->advanceData($request, $advance);
        DB::transaction(function () use ($advance, $data) {
            $advance = TechnicianAdvance::lockForUpdate()->findOrFail($advance->id);
            $this->ensureAdvanceEditable($advance);
            $oldValues = $advance->getAttributes();
            $advance->fill($data);

            if ($advance->isDirty(['amount', 'account_id', 'payment_date', 'payment_method', 'funding_allocations'])) {
                $this->reverseAdvanceLedger($advance, 'Advance payment corrected');
                $advance->save();
                $this->postAdvanceLedger($advance);
            } else {
                $advance->save();
            }

            $this->auditAdvance($advance, 'Staff Advance Updated', $oldValues);
        });

        return redirect()->route('technicians.advances')->with('success', 'Advance updated and account ledger corrected.');
    }

    public function advanceDestroy(TechnicianAdvance $advance)
    {
        DB::transaction(function () use ($advance) {
            $advance = TechnicianAdvance::lockForUpdate()->findOrFail($advance->id);
            $this->ensureAdvanceEditable($advance);
            $oldValues = $advance->getAttributes();
            $this->reverseAdvanceLedger($advance, 'Advance payment deleted');
            $advance->update(['status' => 'Cancelled', 'cancel_reason' => 'Advance payment deleted', 'cancelled_at' => now()]);
            $this->auditAdvance($advance, 'Staff Advance Deleted', $oldValues);
        });

        return redirect()->route('technicians.advances')->with('success', 'Advance deleted and account ledger reversed.');
    }

    private function advanceData(Request $request, ?TechnicianAdvance $advance = null): array
    {
        $data = $request->validate([
            'technician_id' => ['required', Rule::exists('technicians', 'id')->whereNull('deleted_at')->where(fn ($query) => $query->where('active', true)->orWhere('id', $advance?->technician_id))],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01', 'decimal:0,2', 'max:999999999999.99'],
            'account_id' => ['required', Rule::exists('accounts', 'id')->where('shop_type', session('shop_mode', 'workshop'))->where(fn ($query) => $query->where('active', true)->orWhere('id', $advance?->account_id))],
            'payment_method' => ['required', 'string', 'max:50'],
            'deduct_month' => ['required', 'date_format:Y-m'],
            'note' => ['nullable', 'string', 'max:1000'],
        ] + OutgoingFundingService::rules(), [], ['technician_id' => 'staff member']);
        $funding = app(OutgoingFundingService::class);
        $data['funding_allocations'] = $funding->plan($data, (float) $data['amount'], $funding->defaultSource((int) $data['account_id'], $data['payment_method']));
        $data['account_id'] = Account::systemAccount($funding->type($data['funding_allocations'][0]['source']))->id;
        unset($data['funding']);
        $data['deduct_month'] .= '-01';
        $data['note'] = $data['note'] ?? null;

        return $data;
    }

    private function ensureAdvanceEditable(TechnicianAdvance $advance): void
    {
        if ($advance->status !== 'Pending Deduction' || $advance->salary_sheet_id !== null) {
            throw ValidationException::withMessages(['advance' => 'Only pending advances can be edited or deleted. Reopen the linked salary sheet before changing a deducted advance.']);
        }
    }

    private function postAdvanceLedger(TechnicianAdvance $advance): void
    {
        $funding = app(OutgoingFundingService::class);
        $plan = $advance->funding_allocations ?? $funding->plan([], (float) $advance->amount, $funding->defaultSource((int) $advance->account_id, $advance->payment_method));
        $funding->post($plan, (float) $advance->amount, "Staff advance {$advance->number}", $advance, $advance->payment_method, $advance->payment_date, 'salary_advance', $advance->note);
    }

    private function reverseAdvanceLedger(TechnicianAdvance $advance, string $reason): void
    {
        $entries = AccountTransaction::where('reference_type', $advance->getMorphClass())
            ->where('reference_id', $advance->id)->where('status', 'active')
            ->where('transaction_type', '!=', 'reversal')->orderBy('account_id')->orderBy('id')->get();
        foreach ($entries as $entry) {
            app(AccountService::class)->reverseTransaction($entry, $reason);
        }
    }

    private function auditAdvance(TechnicianAdvance $advance, string $event, array $oldValues): void
    {
        AuditLog::create([
            'user_id' => auth()->id(), 'event' => $event,
            'auditable_type' => $advance->getMorphClass(), 'auditable_id' => $advance->id,
            'old_values' => $oldValues, 'new_values' => $advance->getAttributes(),
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);
    }

    public function commissionStore(Request $request)
    {
        $data = $request->validate(['technician_id' => ['required', 'exists:technicians,id'], 'job_card_id' => ['nullable', 'exists:job_cards,id'], 'commission_type' => ['required', 'max:100'], 'base_amount' => ['required', 'numeric', 'min:0'], 'percentage' => ['required', 'numeric', 'min:0', 'max:100'], 'amount' => ['nullable', 'numeric', 'min:0'], 'earned_date' => ['required', 'date'], 'note' => ['nullable', 'max:1000']]);
        $job = ! empty($data['job_card_id']) ? JobCard::with('invoice')->find($data['job_card_id']) : null;
        if ($job && preg_match('/service|labou?r|bill/i', $data['commission_type'])) {
            $data['base_amount'] = $job->service_labor_commission_base;
            $data['commission_type'] = JobCommissionService::TYPE;
            unset($data['amount']);
        }
        $amount = isset($data['amount']) ? (float) $data['amount'] : (float) $data['base_amount'] * (float) $data['percentage'] / 100;
        TechnicianCommission::create(array_merge($data, ['invoice_id' => $job?->invoice?->id, 'amount' => round($amount, 2), 'created_by' => auth()->id()]));

        return back()->with('success', 'Commission record added.');
    }

    public function bikeDeductions(Request $request)
    {
        $plans = TechnicianBikeDeduction::with('invoice')->get();

        return view('technicians.bike-deductions', [
            'records' => TechnicianBikeDeduction::with(['technician', 'bike', 'jobCard', 'invoice'])->latest()->paginate(15),
            'technicians' => $this->technicians(),
            'bikes' => Bike::with('intakes.jobCard.invoice', 'quickJobs.invoice')->where('is_technician_bike', true)->get(),
            'stats' => ['total' => $plans->sum('total_amount'), 'deducted' => $plans->sum('deducted_amount'),
                'pending' => $plans->where('status', 'Active')->sum('recoverable_balance'),
                'active' => $plans->where('status', 'Active')->where('recoverable_balance', '>', 0)->count()],
        ]);
    }

    public function bikeDeductionStore(Request $request)
    {
        $data = $request->validate([
            'technician_id' => ['required', 'exists:technicians,id'], 'bike_id' => ['nullable', 'exists:bikes,id'],
            'job_card_id' => ['required', 'exists:job_cards,id'], 'total_amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'deduction_type' => ['required', Rule::in(['Full deduction', 'Partial deduction', 'Monthly installment'])],
            'installment_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'start_month' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'max:1000'],
        ]);
        $job = JobCard::findOrFail($data['job_card_id']);
        if ((int) $job->repair_staff?->id !== (int) $data['technician_id'] ||
            (! empty($data['bike_id']) && (int) $job->bike?->id !== (int) $data['bike_id'])) {
            throw ValidationException::withMessages(['job_card_id' => 'Select a repair job belonging to this staff member and bike.']);
        }
        app(StaffBikeRepairService::class)->createPlan($job, $data);

        return back()->with('success', 'Staff bike salary recovery plan created.');
    }

    public function contributions(Request $request)
    {
        $query = TechnicianContribution::with('technician')
            ->whereHas('salarySheet', fn ($q) => $q->whereIn('status', ['Finalized', 'Partially Paid', 'Paid']));
        $records = (clone $query)->latest('month')->paginate(20);
        $month = (clone $query)->whereBetween('month', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()]);

        return view('technicians.contributions', ['records' => $records, 'technicians' => $this->technicians(), 'stats' => ['employee' => (clone $month)->sum('employee_epf_amount'), 'company' => (clone $month)->sum('company_epf_amount'), 'etf' => (clone $month)->sum('company_etf_amount'), 'total' => (clone $month)->sum(DB::raw('employee_epf_amount+company_epf_amount+company_etf_amount'))]]);
    }

    public function history(Request $request, SalaryCycleService $salaryCycle)
    {
        $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'jobs_from' => ['nullable', 'date_format:Y-m-d'],
            'jobs_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('jobs_from'), 'after_or_equal:jobs_from')],
        ]);
        $technician = null;
        $historyPeriod = null;
        $workedJobs = collect();
        $financialHistory = collect();
        $jobCommissions = collect();
        $workedJobsFrom = null;
        $workedJobsTo = null;
        if ($request->technician) {
            $historyStaff = Technician::find($request->technician);
            $from = null;
            $to = null;
            if ($request->month) {
                $historyPeriod = $salaryCycle->previewSalaryPeriod($request->month.'-01', $historyStaff);
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
                $historyPeriod = $salaryCycle->previewSalaryPeriod(now()->startOfMonth(), $historyStaff);
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
                'bikeDeductions.invoice',
                'contributions',
            ])->find($request->technician);

            $separateJobsRange = $request->filled('jobs_from') || $request->filled('jobs_to');
            $workedJobsFrom = $separateJobsRange ? ($request->filled('jobs_from') ? Carbon::parse($request->jobs_from)->startOfDay() : null) : $from;
            $workedJobsTo = $separateJobsRange ? ($request->filled('jobs_to') ? Carbon::parse($request->jobs_to)->endOfDay() : null) : $to;
            if ($technician) {
                $financialHistory = $technician->advances->map(fn ($advance) => [
                    'date' => Carbon::parse($advance->payment_date)->toDateString(),
                    'display_date' => Carbon::parse($advance->payment_date)->format('d M Y'),
                    'label' => 'Advance '.$advance->number,
                    'amount' => $advance->amount,
                    'status' => $advance->status,
                    'key' => 'advance-'.sprintf('%020d', $advance->id),
                ])->toBase()->concat($technician->bikeDeductions->map(fn ($deduction) => [
                    'date' => Carbon::parse($deduction->start_month)->toDateString(),
                    'display_date' => Carbon::parse($deduction->start_month)->format('M Y'),
                    'label' => 'Bike '.$deduction->bike?->number,
                    'amount' => $deduction->recoverable_balance,
                    'status' => $deduction->status,
                    'key' => 'bike-'.sprintf('%020d', $deduction->id),
                ]))->sortBy([['date', 'asc'], ['key', 'asc']])->values();
                $workedJobs = $separateJobsRange
                    ? $technician->jobs()->with('intake.bike.customer', 'invoice')
                        ->when($workedJobsFrom, fn ($q) => $q->where('created_at', '>=', $workedJobsFrom))
                        ->when($workedJobsTo, fn ($q) => $q->where('created_at', '<=', $workedJobsTo))
                        ->latest('created_at')->latest('id')->get()
                    : $technician->jobs->sortByDesc('created_at')->values();
                $jobCommissions = TechnicianCommission::where('technician_id', $technician->id)
                    ->whereIn('job_card_id', $workedJobs->modelKeys())
                    ->selectRaw('job_card_id, sum(amount) as total')->groupBy('job_card_id')->pluck('total', 'job_card_id');
            }
        }

        return view('technicians.history', ['technicians' => $this->technicians(), 'technician' => $technician, 'historyPeriod' => $historyPeriod, 'workedJobs' => $workedJobs, 'financialHistory' => $financialHistory, 'jobCommissions' => $jobCommissions, 'workedJobsFrom' => $workedJobsFrom, 'workedJobsTo' => $workedJobsTo, 'auditLogs' => $technician ? AuditLog::where('auditable_type', $technician->getMorphClass())->where('auditable_id', $technician->id)->latest()->limit(50)->get() : collect()]);
    }

    private function technicians()
    {
        return Technician::where('active', true)->orderBy('name')->get();
    }

    private function technicianData(Request $request, ?Technician $technician = null): array
    {
        $data = $request->validate([
            'attendance_person_id' => ['nullable', 'max:80', Rule::unique('technicians', 'attendance_person_id')->ignore($technician)],
            'department' => ['nullable', 'max:150'],
            'name' => ['required', 'max:150'],
            'phone' => ['required', 'max:30', Rule::unique('technicians', 'phone')->ignore($technician)],
            'alternative_phone' => ['nullable', 'max:30'],
            'nic' => ['nullable', 'max:30'],
            'address' => ['nullable', 'max:1000'],
            'emergency_contact' => ['nullable', 'max:100'],
            'date_of_birth' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'joining_date' => ['nullable', 'date'],
            'active' => ['required', 'boolean'],
            'basic_salary' => ['required', 'numeric', 'min:0'],
            'salary_type' => ['required', Rule::in(['Monthly', 'Daily', 'Weekly', 'Hourly'])],
            'salary_period_mode' => ['sometimes', 'required', Rule::in(['system_default', 'normal_calendar', 'custom_start'])],
            'salary_start_day' => ['exclude_unless:salary_period_mode,custom_start', 'required', 'integer', 'min:1', 'max:31'],
            'daily_salary' => ['nullable', 'numeric', 'min:0'],
            'hourly_salary' => ['required_if:salary_type,Hourly', 'nullable', 'numeric', 'min:0', 'decimal:0,2', Rule::when($request->input('salary_type') === 'Hourly', 'gt:0')],
            'ot_rate' => ['nullable', 'numeric', 'min:0'],
            'late_deduction_rate' => ['nullable', 'numeric', 'min:0'],
            'no_pay_deduction_rate' => ['nullable', 'numeric', 'min:0'],
            'commission_enabled' => ['nullable', 'boolean'],
            'commission_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'parts_commission_percentage' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'commission_type' => ['required', 'max:100'],
            'epf_number' => ['nullable', 'max:50'],
            'etf_number' => ['nullable', 'max:50'],
            'epf_eligible' => ['nullable', 'boolean'],
            'etf_eligible' => ['nullable', 'boolean'],
            'epf_etf_basic_salary' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'between:0,999999999999.99'],
            'epf_employee_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'epf_company_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'etf_company_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'role' => ['nullable', Rule::in(['technician', 'staff'])],
            'designation' => ['nullable', 'max:100'],
        ]);
        if (isset($data['salary_period_mode']) && $data['salary_period_mode'] !== 'custom_start') {
            $data['salary_start_day'] = null;
        }

        return $data;
    }
}
