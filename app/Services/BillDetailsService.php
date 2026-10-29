<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\TechnicianCommission;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillDetailsService
{
    public static function rules(): array
    {
        return [
            'bill_date' => 'required|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:today',
            'bike_number' => 'prohibited',
            'reason' => 'required|string|min:5|max:500',
        ];
    }

    public function updateJob(JobCard $job, array $data): void
    {
        DB::transaction(function () use ($job, $data) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $invoice = $job->invoice()->lockForUpdate()->first();
            if ($job->status === 'Cancelled' || $invoice?->status === 'Cancelled') {
                throw ValidationException::withMessages(['bill_date' => 'Cancelled bills cannot be changed.']);
            }
            $before = ['bill_date' => ($invoice->bill_date ?? $job->bill_date)?->toDateString()];
            if ($invoice) {
                $this->saveInvoice($invoice, $data['bill_date']);
            }
            $job->update(['bill_date' => $data['bill_date']]);
            $after = ['bill_date' => $data['bill_date']];
            $this->audit($job, $before, $after, $data['reason']);
            DB::table('job_card_status_logs')->insert([
                'job_card_id' => $job->id, 'old_status' => $job->status, 'new_status' => $job->status,
                'note' => 'Bill details: '.($before['bill_date'] ?: 'Not set').' → '.$after['bill_date'].'. Reason: '.$data['reason'],
                'changed_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, 3);
    }

    public function updateInvoice(Invoice $invoice, array $data): void
    {
        if ($invoice->job_card_id) {
            $this->updateJob($invoice->jobCard()->firstOrFail(), $data);

            return;
        }
        DB::transaction(function () use ($invoice, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'Cancelled' || $invoice->isOpeningDue() || $invoice->status === 'Converted') {
                throw ValidationException::withMessages(['bill_date' => 'Use the original workflow to change this document.']);
            }
            $before = ['bill_date' => $invoice->bill_date->toDateString()];
            $this->saveInvoice($invoice, $data['bill_date']);
            $this->audit($invoice, $before, ['bill_date' => $data['bill_date']], $data['reason']);
        }, 3);
    }

    private function saveInvoice(Invoice $invoice, string $date): void
    {
        $changedDate = $invoice->bill_date->toDateString() !== $date;
        $commissions = TechnicianCommission::where('invoice_id', $invoice->id)->lockForUpdate()->get();
        if ($changedDate && $commissions->contains(fn ($commission) => $commission->salary_sheet_id !== null || $commission->status === 'Paid' || (float) $commission->paid_amount > 0 || (float) $commission->deducted_amount > 0)) {
            throw ValidationException::withMessages(['bill_date' => 'This bill has settled commission or commission linked to a salary. Resolve that settlement before changing its date.']);
        }
        $values = ['bill_date' => Carbon::parse($date)->setTimeFrom($invoice->bill_date)];
        if ($changedDate && $invoice->due_date && $invoice->bill_date->copy()->addDays(30)->isSameDay($invoice->due_date)) {
            $values['due_date'] = Carbon::parse($date)->addDays(30);
        }
        $invoice->update($values);
        if ($changedDate) {
            TechnicianCommission::whereIn('id', $commissions->modelKeys())->where('status', '!=', 'Cancelled')->update(['earned_date' => $date]);
        }
    }

    private function audit(JobCard|Invoice $record, array $before, array $after, string $reason): void
    {
        AuditLog::create([
            'user_id' => auth()->id(), 'event' => 'bill_details_updated',
            'auditable_type' => $record->getMorphClass(), 'auditable_id' => $record->id,
            'old_values' => $before, 'new_values' => $after + ['reason' => $reason],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);
    }
}
