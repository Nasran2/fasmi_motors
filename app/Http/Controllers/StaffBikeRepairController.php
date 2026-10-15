<?php

namespace App\Http\Controllers;

use App\Models\JobCard;
use App\Models\Technician;
use App\Services\StaffBikeRepairService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StaffBikeRepairController extends Controller
{
    public function index(Request $request, StaffBikeRepairService $repairs)
    {
        $request->validate(['staff' => ['nullable', 'integer', 'exists:technicians,id'], 'q' => ['nullable', 'string', 'max:100']]);
        $jobs = $repairs->jobs()->with(['staffBikeOwner', 'intake.bike.technician', 'quickBike.technician', 'invoice.payments', 'bikeDeductionPlans'])
            ->when($request->staff, fn ($query, $staff) => $query->where(function ($query) use ($staff) {
                $query->where('staff_bike_owner_id', $staff)
                    ->orWhere(fn ($query) => $query->where('is_staff_bike', false)->where(function ($query) use ($staff) {
                        $query->whereHas('intake.bike', fn ($bike) => $bike->where('technician_id', $staff))
                            ->orWhereHas('quickBike', fn ($bike) => $bike->where('technician_id', $staff));
                    }));
            }))
            ->when($request->q, fn ($query, $search) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', '%'.$search.'%')->orWhereHas('invoice', fn ($invoice) => $invoice->where('number', 'like', '%'.$search.'%'))
                    ->orWhereHas('intake.bike', fn ($bike) => $bike->where('number', 'like', '%'.$search.'%'))
                    ->orWhereHas('quickBike', fn ($bike) => $bike->where('number', 'like', '%'.$search.'%'));
            }))->latest()->latest('id')->paginate(20)->withQueryString();

        return view('technicians.bike-repairs', ['jobs' => $jobs, 'technicians' => Technician::orderBy('name')->get()]);
    }

    public function show(JobCard $job, StaffBikeRepairService $repairs)
    {
        abort_unless($repairs->jobs()->whereKey($job->id)->exists(), 404);
        $job->load(['invoice.payments', 'bikeDeductionPlans', 'parts', 'staffBikeOwner', 'intake.bike.technician', 'quickBike.technician']);

        return view('technicians.bike-repair-show', ['job' => $job, 'staffRepairSummary' => $repairs->summary($job), 'repairChanges' => $repairs->history($job)]);
    }

    public function store(Request $request, JobCard $job, StaffBikeRepairService $repairs)
    {
        $data = $request->validate([
            'deduction_type' => ['required', Rule::in(['Full deduction', 'Partial deduction', 'Monthly installment'])],
            'installment_amount' => ['nullable', 'required_unless:deduction_type,Full deduction', 'numeric', 'gt:0', 'decimal:0,2'],
            'start_month' => ['required', 'date_format:Y-m'], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $repairs->createPlan($job, $data);

        return back()->with('success', 'Salary recovery selected. Choose the amount for each repair in Salary Payments.');
    }

    public function update(Request $request, JobCard $job, StaffBikeRepairService $repairs)
    {
        $data = $request->validate([
            'payment_mode' => ['required', Rule::in(['normal', 'salary'])],
            'deduction_type' => ['nullable', 'required_if:payment_mode,salary', Rule::in(['Full deduction', 'Partial deduction', 'Monthly installment'])],
            'installment_amount' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'start_month' => ['nullable', 'required_if:payment_mode,salary', 'date_format:Y-m'],
            'reason' => ['nullable', 'string', 'min:5', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $repairs->updateArrangement($job, $data);

        return back()->with('success', 'Payment arrangement updated. Amounts already deducted from salary remain recorded against their paysheets.');
    }
}
