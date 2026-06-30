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

        return view('technicians.attendance', ['records' => $records, 'technicians' => $this->technicians(), 'date' => $date, 'stats' => [
            'present' => TechnicianAttendance::whereDate('date', today())->whereIn('status', ['Present', 'Late'])->count(),
            'absent' => TechnicianAttendance::whereDate('date', today())->where('status', 'Absent')->count(),
            'late' => TechnicianAttendance::whereDate('date', today())->where('status', 'Late')->count(),
            'leave' => TechnicianAttendance::whereDate('date', today())->where('status', 'Leave')->count(),
            'ot' => TechnicianAttendance::whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->sum('ot_hours'),
        ]]);
    }

    public function attendanceStore(Request $request)
    {
        $data = $request->validate(['technician_id' => ['required', 'exists:technicians,id'], 'date' => ['required', 'date'], 'status' => ['required', Rule::in(['Present', 'Absent', 'Half Day', 'Leave', 'Late', 'Holiday'])], 'in_time' => ['nullable', 'date_format:H:i'], 'out_time' => ['nullable', 'date_format:H:i'], 'ot_hours' => ['nullable', 'numeric', 'min:0'], 'late_minutes' => ['nullable', 'integer', 'min:0'], 'note' => ['nullable', 'max:1000'], 'edit_reason' => ['nullable', 'max:500']]);
        if ($data['in_time'] && $data['out_time']) {
            $data['working_hours'] = max(0, Carbon::parse($data['in_time'])->diffInMinutes(Carbon::parse($data['out_time']), false) / 60);
            $data['ot_hours'] = $data['ot_hours'] ?? max(0, $data['working_hours'] - 8);
        }
        TechnicianAttendance::updateOrCreate(['technician_id' => $data['technician_id'], 'date' => $data['date']], $data + ['created_by' => auth()->id(), 'edited_by' => auth()->id()]);

        return back()->with('success', 'Attendance saved and working hours recalculated.');
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
        $present = $attendance->whereIn('status', ['Present', 'Late'])->count();
        $absent = $attendance->where('status', 'Absent')->count();
        $otHours = (float) $attendance->sum('ot_hours');
        $commission = (float) TechnicianCommission::where('technician_id', $technician->id)->whereBetween('earned_date', [$start, $end])->where('status', 'Pending')->sum('amount');
        $advances = (float) TechnicianAdvance::where('technician_id', $technician->id)->where('status', 'Pending Deduction')->whereDate('deduct_month', $start)->sum('amount');
        $bikeDeduction = (float) TechnicianBikeDeduction::where('technician_id', $technician->id)->where('status', 'Active')->whereDate('next_deduction_month', '<=', $start)->sum('installment_amount');
        $basic = $technician->salary_type === 'Daily' ? (float) $technician->daily_salary * $present : (float) $technician->basic_salary;
        $ot = $otHours * (float) $technician->ot_rate;
        $late = (float) $attendance->sum('late_minutes') * (float) $technician->late_deduction_rate;
        $noPay = $absent * (float) $technician->no_pay_deduction_rate;
        $employeeEpf = $technician->epf_eligible ? $basic * (float) $technician->epf_employee_percentage / 100 : 0;
        $companyEpf = $technician->epf_eligible ? $basic * (float) $technician->epf_company_percentage / 100 : 0;
        $companyEtf = $technician->etf_eligible ? $basic * (float) $technician->etf_company_percentage / 100 : 0;
        $gross = $basic + $ot + $commission;
        $deductions = $advances + $bikeDeduction + $late + $noPay + (float) ($data['other_deductions'] ?? 0);
        $net = max(0, $gross - $deductions - $employeeEpf);
        $paid = min($net, (float) ($data['paid_amount'] ?? 0));
        $sheet = DB::transaction(function () use ($data, $technician, $start, $end, $attendance, $present, $absent, $otHours, $basic, $ot, $commission, $advances, $bikeDeduction, $late, $noPay, $employeeEpf, $companyEpf, $companyEtf, $gross, $deductions, $net, $paid) {
            $sheet = TechnicianSalarySheet::create(['number' => 'PAY-'.now()->format('ymd').'-'.Str::upper(Str::random(5)), 'technician_id' => $technician->id, 'period_start' => $start, 'period_end' => $end, 'working_days' => $attendance->count(), 'present_days' => $present, 'absent_days' => $absent, 'ot_hours' => $otHours, 'basic_salary' => $basic, 'ot_amount' => $ot, 'commission' => $commission, 'gross_salary' => $gross, 'advances' => $advances, 'late_deduction' => $late, 'no_pay_deduction' => $noPay, 'own_bike_deduction' => $bikeDeduction, 'other_deductions' => $data['other_deductions'] ?? 0, 'deductions' => $deductions, 'epf_employee' => $employeeEpf, 'epf_company' => $companyEpf, 'etf_company' => $companyEtf, 'net_payable' => $net, 'paid_amount' => $paid, 'balance_amount' => $net - $paid, 'status' => $paid >= $net ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Draft'), 'account_id' => $data['account_id'] ?? null, 'payment_method' => $data['payment_method'] ?? null, 'created_by' => auth()->id()]);
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
