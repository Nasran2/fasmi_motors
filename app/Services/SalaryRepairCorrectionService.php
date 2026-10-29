<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Technician;
use App\Models\TechnicianBikeDeduction;
use App\Models\TechnicianDeduction;
use App\Models\TechnicianSalarySheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalaryRepairCorrectionService
{
    public function context(Payment $payment): array
    {
        $payment->loadMissing(['salarySheet.technician', 'bikeDeduction.jobCard', 'invoice']);
        $sheet = $payment->salarySheet;
        $plan = $payment->bikeDeduction;
        abort_unless($payment->method === 'Salary Deduction' && $payment->status === 'Completed' && $sheet && $plan, 422, 'Select an active salary repair deduction.');
        abort_unless(in_array($sheet->status, ['Finalized', 'Partially Paid', 'Paid'], true), 422, 'This salary deduction is no longer finalized.');
        abort_unless((int) $sheet->technician_id === (int) $plan->technician_id && (int) $plan->invoice_id === (int) $payment->invoice_id, 422);
        $invoice = $payment->invoice;
        abort_unless($invoice && $invoice->status !== 'Cancelled', 422, 'The repair bill is cancelled or unavailable.');
        $item = collect(data_get($sheet->calculation_snapshot, 'pending_deductions.items', []))
            ->first(fn ($item) => $item['kind'] === 'bike_installment' && (int) $item['id'] === (int) $plan->id);
        abort_unless($item && round((float) $item['amount'], 2) === round((float) $payment->amount, 2), 422, 'The salary deduction and paysheet do not match. Review this paysheet before correcting it.');
        $max = min(
            (float) $invoice->due_amount + (float) $payment->amount,
            (float) $plan->total_amount - (float) $plan->deducted_amount + (float) $payment->amount,
            (float) $sheet->net_payable + (float) $payment->amount,
            (float) ($item['remaining_balance'] ?? INF),
        );

        return compact('payment', 'sheet', 'plan', 'invoice', 'max', 'item');
    }

    public function correct(Payment $payment, float $amount, float $expectedAmount, string $reason): Payment
    {
        return DB::transaction(function () use ($payment, $amount, $expectedAmount, $reason) {
            $context = $this->context($payment);
            Technician::whereKey($context['sheet']->technician_id)->lockForUpdate()->firstOrFail();
            $sheet = TechnicianSalarySheet::lockForUpdate()->findOrFail($context['sheet']->id);
            $plan = TechnicianBikeDeduction::lockForUpdate()->findOrFail($context['plan']->id);
            $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = Payment::lockForUpdate()->findOrFail($payment->id);
            $payment->setRelation('salarySheet', $sheet)->setRelation('bikeDeduction', $plan)->setRelation('invoice', $invoice);
            $context = $this->context($payment);
            $amount = round($amount, 2);
            $old = round((float) $payment->amount, 2);
            if ($old !== round($expectedAmount, 2)) {
                throw ValidationException::withMessages(['amount' => 'This deduction changed while you were editing. Refresh and review it again.']);
            }
            if (trim($reason) === '' || mb_strlen(trim($reason)) < 5) {
                throw ValidationException::withMessages(['reason' => 'Enter a reason for correcting an applied salary deduction.']);
            }
            if ($amount < 0 || $amount > round($context['max'], 2) || (float) $payment->refunded_amount > 0) {
                throw ValidationException::withMessages(['amount' => 'The corrected deduction exceeds the available repair or salary balance, or this payment has a refund.']);
            }
            if ($amount === $old) {
                return $payment;
            }
            $delta = round($amount - $old, 2);
            $newPlanDeducted = round((float) $plan->deducted_amount + $delta, 2);
            if ($newPlanDeducted < 0 || (float) $invoice->paid_amount + $delta < 0) {
                throw ValidationException::withMessages(['amount' => 'The recorded repair totals do not match this deduction. Review the repair statement first.']);
            }
            $before = ['payment' => $payment->getAttributes(), 'plan' => $plan->getAttributes(), 'salary' => $sheet->getAttributes(), 'invoice' => $invoice->getAttributes()];
            $snapshot = $sheet->calculation_snapshot ?? [];
            foreach ($snapshot['pending_deductions']['items'] as &$item) {
                if ($item['kind'] === 'bike_installment' && (int) $item['id'] === (int) $plan->id) {
                    $item['amount'] = $amount;
                    $item['requested_amount'] = $amount;
                }
            }
            unset($item);
            $snapshot['pending_deductions']['total_amount'] = round((float) collect($snapshot['pending_deductions']['items'])->sum('amount'), 2);
            $snapshot['inputs']['bike_installments'][$plan->id] = $amount;
            $snapshot['deductions']['job_bike'] = $snapshot['pending_deductions']['total_amount'];
            $snapshot['repair_corrections'][] = ['payment_id' => $payment->id, 'plan_id' => $plan->id, 'old_amount' => $old, 'amount' => $amount, 'reason' => trim($reason), 'user_id' => auth()->id(), 'at' => now()->toIso8601String()];
            $net = round((float) $sheet->net_payable - $delta, 2);
            $balance = round($net - (float) $sheet->paid_amount, 2);
            $snapshot = app(SalaryBalanceService::class)->restate($snapshot, (float) $sheet->gross_salary, round((float) $sheet->total_deductions + $delta, 2));
            $status = (float) $sheet->paid_amount > 0 ? ($balance <= 0 ? 'Paid' : 'Partially Paid') : 'Finalized';
            $sheet->update([
                'own_bike_deduction' => round((float) $sheet->own_bike_deduction + $delta, 2),
                'total_job_deduction' => round((float) $sheet->total_job_deduction + $delta, 2),
                'deductions' => round((float) $sheet->deductions + $delta, 2),
                'total_deductions' => round((float) $sheet->total_deductions + $delta, 2),
                'net_payable' => $net, 'balance_amount' => $balance, 'overpaid_amount' => max(0, -$balance),
                'status' => $status, 'paid_at' => $status === 'Paid' ? ($sheet->paid_at ?? now()) : null,
                'calculation_snapshot' => $snapshot,
            ]);
            $this->syncOverpayment($sheet, trim($reason));
            $payment->update(['amount' => $amount, 'received_amount' => 0, 'balance_after' => round((float) $invoice->due_amount - $delta, 2)]);
            $invoice->update(['paid_amount' => round((float) $invoice->paid_amount + $delta, 2), 'due_amount' => round((float) $invoice->due_amount - $delta, 2)]);
            app(SettlementService::class)->saveStatus($invoice);
            $planStatus = $plan->status === 'Cancelled' ? 'Cancelled' : ($newPlanDeducted >= (float) $plan->total_amount ? 'Completed' : 'Active');
            $nextMonth = $plan->next_deduction_month ?? Carbon::parse(data_get($snapshot, 'salary_cycle.salary_month', $sheet->period_end))->startOfMonth()->addMonth();
            if ($amount === 0.0 && $planStatus === 'Active' && isset($context['item']['next_deduction_month'])) {
                $hasLaterRecovery = Payment::where('technician_bike_deduction_id', $plan->id)->where('method', 'Salary Deduction')
                    ->where('status', 'Completed')->where('amount', '>', 0)->where('id', '!=', $payment->id)
                    ->whereHas('salarySheet', fn ($query) => $query->whereDate('period_end', '>', $sheet->period_end))->exists();
                if (! $hasLaterRecovery) {
                    $nextMonth = min(Carbon::parse($nextMonth), Carbon::parse($context['item']['next_deduction_month']));
                }
            }
            $plan->update([
                'deducted_amount' => $newPlanDeducted, 'status' => $planStatus,
                'next_deduction_month' => $planStatus === 'Active' ? $nextMonth : null,
            ]);
            AuditLog::create([
                'user_id' => auth()->id(), 'event' => 'Staff Repair Salary Deduction Corrected',
                'auditable_type' => $payment->getMorphClass(), 'auditable_id' => $payment->id,
                'old_values' => $before, 'new_values' => ['reason' => trim($reason), 'old_amount' => $old, 'amount' => $amount,
                    'salary_number' => $sheet->number, 'salary_sheet_id' => $sheet->id, 'net_payable' => $net,
                    'salary_balance' => $balance, 'repair_balance' => (float) $invoice->due_amount],
                'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
            ]);

            return $payment->fresh();
        });
    }

    public function syncOverpayment(TechnicianSalarySheet $sheet, string $reason): void
    {
        $carry = TechnicianDeduction::where('technician_id', $sheet->technician_id)
            ->where('source_type', 'salary_overpayment')->where('source_id', $sheet->id)->lockForUpdate()->first();
        $overpaid = round((float) $sheet->overpaid_amount, 2);
        if ($carry && ($carry->status === 'deducted' || (float) $carry->recovered_amount > 0)) {
            if (round((float) $carry->amount, 2) !== $overpaid) {
                throw ValidationException::withMessages(['amount' => 'This salary overpayment was already recovered in another paysheet. Correct that later paysheet first.']);
            }

            return;
        }
        if ($overpaid > 0) {
            TechnicianDeduction::updateOrCreate(
                ['technician_id' => $sheet->technician_id, 'source_type' => 'salary_overpayment', 'source_id' => $sheet->id],
                ['reference_no' => $sheet->number, 'deduction_name' => 'Previous salary overpayment adjustment', 'amount' => $overpaid,
                    'reason' => $reason, 'deduction_date' => Carbon::parse(data_get($sheet->calculation_snapshot, 'salary_cycle.salary_month', $sheet->period_end))->startOfMonth()->addMonth(),
                    'status' => 'pending', 'created_by' => $carry->created_by ?? auth()->id(), 'updated_by' => auth()->id()],
            );
        } elseif ($carry) {
            $carry->update(['status' => 'cancelled', 'reason' => $reason, 'updated_by' => auth()->id()]);
        }
    }
}
