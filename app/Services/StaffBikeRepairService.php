<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Technician;
use App\Models\TechnicianBikeDeduction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StaffBikeRepairService
{
    public function summary(JobCard $job): array
    {
        $job->loadMissing(['bikeDeductionPlans', 'invoice.payments.salarySheet']);
        $payments = $job->invoice?->payments->where('method', 'Salary Deduction') ?? collect();
        $deducted = round((float) $payments->where('status', 'Completed')->sum('amount'), 2);
        $plan = $job->bikeDeductionPlans->where('status', 'Active')->sortByDesc('id')->first();

        return ['plan' => $plan, 'payments' => $payments, 'deducted' => $deducted,
            'mode' => $plan ? 'salary' : 'normal', 'requires_reason' => $deducted > 0,
            'balance' => (float) ($job->invoice->due_amount ?? $job->total)];
    }

    public function history(JobCard $job)
    {
        $job->loadMissing(['bikeDeductionPlans', 'invoice.payments']);

        return AuditLog::query()->whereIn('event', ['Staff Bike Payment Arrangement Changed', 'Staff Repair Salary Deduction Corrected'])
            ->where(function ($query) use ($job) {
                $query->where(fn ($q) => $q->where('auditable_type', $job->getMorphClass())->where('auditable_id', $job->id))
                    ->orWhere(fn ($q) => $q->where('auditable_type', (new Payment)->getMorphClass())->whereIn('auditable_id', $job->invoice?->payments->pluck('id') ?? []));
            })->leftJoin('users', 'audit_logs.user_id', '=', 'users.id')
            ->select('audit_logs.*', 'users.name as changed_by_name')->latest('audit_logs.id')->get();
    }

    public function jobs(): Builder
    {
        return JobCard::query()->where(function (Builder $query) {
            $query->where(fn (Builder $q) => $q->where('is_staff_bike', true)->whereNotNull('staff_bike_owner_id'))
                ->orWhere(fn (Builder $q) => $q->where('is_staff_bike', false)->where(function (Builder $q) {
                    $q->whereHas('intake.bike', fn (Builder $bike) => $bike->where('is_technician_bike', true)->whereNotNull('technician_id'))
                        ->orWhereHas('quickBike', fn (Builder $bike) => $bike->where('is_technician_bike', true)->whereNotNull('technician_id'));
                }));
        });
    }

    public function createPlan(JobCard $job, array $data): TechnicianBikeDeduction
    {
        return DB::transaction(function () use ($job, $data) {
            if ($job->repair_staff) {
                Technician::whereKey($job->repair_staff->id)->lockForUpdate()->firstOrFail();
            }
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $staff = $job->repair_staff;
            $invoice = Invoice::where('job_card_id', $job->id)->lockForUpdate()->first();
            if (! $staff || ! $invoice || $invoice->status === 'Cancelled' || $job->status === 'Cancelled') {
                throw ValidationException::withMessages(['job_card_id' => 'Select a billed staff bike repair with an outstanding balance.']);
            }
            if (TechnicianBikeDeduction::where('invoice_id', $invoice->id)->where('status', 'Active')->exists()) {
                throw ValidationException::withMessages(['job_card_id' => 'This bill already has an active salary recovery plan. Choose its deduction amount in Salary Payments.']);
            }
            $total = round((float) ($data['total_amount'] ?? $invoice->due_amount), 2);
            if ($total <= 0 || $total > round((float) $invoice->due_amount, 2)) {
                throw ValidationException::withMessages(['total_amount' => 'Salary recovery must be greater than zero and cannot exceed the bill balance.']);
            }
            $installment = $data['deduction_type'] === 'Full deduction' ? $total : round((float) ($data['installment_amount'] ?? 0), 2);
            if ($installment <= 0 || $installment > $total) {
                throw ValidationException::withMessages(['installment_amount' => 'Enter a deduction amount between Rs. 0.01 and the repair balance.']);
            }
            $plan = TechnicianBikeDeduction::create([
                'number' => 'TBD-'.now()->format('ymd').'-'.Str::upper(Str::random(5)),
                'technician_id' => $staff->id, 'bike_id' => $job->bike?->id, 'job_card_id' => $job->id,
                'invoice_id' => $invoice->id, 'total_amount' => $total, 'deduction_type' => $data['deduction_type'],
                'installment_amount' => $installment, 'installments' => (int) ceil($total / $installment),
                'start_month' => $data['start_month'].'-01', 'next_deduction_month' => $data['start_month'].'-01',
                'note' => $data['note'] ?? null, 'created_by' => auth()->id(),
            ]);
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'Staff Bike Recovery Plan Created', 'auditable_type' => $plan->getMorphClass(), 'auditable_id' => $plan->id, 'new_values' => $plan->toArray(), 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

            return $plan;
        });
    }

    public function updateArrangement(JobCard $job, array $data): void
    {
        DB::transaction(function () use ($job, $data) {
            $staff = $job->repair_staff;
            if (! $staff) {
                throw ValidationException::withMessages(['payment_mode' => 'Select a staff bike repair.']);
            }
            Technician::whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $plans = TechnicianBikeDeduction::where('job_card_id', $job->id)->orderBy('id')->lockForUpdate()->get();
            $invoice = Invoice::where('job_card_id', $job->id)->lockForUpdate()->firstOrFail();
            if ($invoice->status === 'Cancelled' || $job->status === 'Cancelled') {
                throw ValidationException::withMessages(['payment_mode' => 'A cancelled repair cannot change its payment arrangement.']);
            }
            $deducted = (float) $invoice->payments()->where('method', 'Salary Deduction')->where('status', 'Completed')->sum('amount');
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($deducted > 0 && mb_strlen($reason) < 5) {
                throw ValidationException::withMessages(['reason' => 'Enter a reason to change a repair that has already been deducted from salary.']);
            }
            $before = $plans->toArray();
            $active = $plans->where('status', 'Active');
            if ($data['payment_mode'] === 'normal') {
                foreach ($active as $plan) {
                    $plan->update(['status' => 'Cancelled', 'next_deduction_month' => null, 'cancel_reason' => $reason ?: 'Changed to normal payment before salary deduction.']);
                }
            } else {
                $remaining = round((float) $invoice->due_amount, 2);
                $installment = $data['deduction_type'] === 'Full deduction' ? $remaining : round((float) ($data['installment_amount'] ?? 0), 2);
                if ($remaining <= 0 || $installment <= 0 || $installment > $remaining) {
                    throw ValidationException::withMessages(['installment_amount' => 'Choose a deduction between Rs. 0.01 and the current repair balance.']);
                }
                if ($active->count() > 1) {
                    throw ValidationException::withMessages(['payment_mode' => 'This repair has multiple older recovery plans. Change to normal payment first, then select one salary recovery plan.']);
                }
                $plan = $active->first();
                if ($plan) {
                    $plan->update([
                        'total_amount' => round((float) $plan->deducted_amount + $remaining, 2),
                        'deduction_type' => $data['deduction_type'], 'installment_amount' => $installment,
                        'installments' => (int) ceil($remaining / $installment), 'next_deduction_month' => $data['start_month'].'-01',
                        'note' => $data['note'] ?? $plan->note,
                    ]);
                } else {
                    $this->createPlan($job, $data);
                }
            }
            AuditLog::create(['user_id' => auth()->id(), 'event' => 'Staff Bike Payment Arrangement Changed',
                'auditable_type' => $job->getMorphClass(), 'auditable_id' => $job->id,
                'old_values' => ['plans' => $before], 'new_values' => ['payment_mode' => $data['payment_mode'], 'reason' => $reason,
                    'already_deducted' => $deducted, 'plans' => TechnicianBikeDeduction::where('job_card_id', $job->id)->get()->toArray()],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
        });
    }
}
