<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class JobAdvanceService
{
    public function receive(JobCard $job, array $data, array $row): JobAdvance
    {
        return DB::transaction(function () use ($job, $data, $row) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            if ($job->invoice || in_array($job->status, ['Cancelled', 'Delivered'], true)) {
                throw ValidationException::withMessages(['advance_amount' => 'Advances can only be added before billing an active job. Use Pay Due for an existing invoice.']);
            }
            $cheque = null;
            if ($row['method'] === 'Cheque') {
                $customer = $job->customer;
                if (! $customer) {
                    throw ValidationException::withMessages(['advance_method' => 'Select a customer before receiving a cheque.']);
                }
                $cheque = Cheque::create([
                    'cheque_type' => 'Received', 'party_type' => $customer->getMorphClass(), 'party_id' => $customer->id,
                    'party_name' => $customer->name, 'reference_type' => $job->getMorphClass(), 'reference_id' => $job->id,
                    'related_reference' => $job->number, 'cheque_number' => $row['cheque_number'], 'bank_name' => $row['bank_name'],
                    'cheque_date' => $row['cheque_date'], 'amount' => $row['amount'], 'bank_account_id' => $row['account_id'],
                    'status' => 'Pending', 'created_by' => auth()->id(), 'updated_by' => auth()->id(),
                ]);
            }
            $advance = JobAdvance::create([
                'job_card_id' => $job->id, 'number' => 'ADV-'.Str::upper(Str::random(12)),
                'amount' => $row['amount'], 'method' => $row['method'], 'account_id' => $row['account_id'],
                'cheque_id' => $cheque?->id, 'status' => $cheque ? 'Hold' : 'Completed',
                'paid_at' => $data['advance_paid_at'], 'send_sms' => $data['send_advance_sms'] ?? false,
                'note' => $data['advance_note'] ?? null, 'created_by' => auth()->id(),
            ]);
            if (! $cheque) {
                $this->postReceipt($advance);
            }
            DB::table('job_card_status_logs')->insert([
                'job_card_id' => $job->id, 'old_status' => $job->status, 'new_status' => $job->status,
                'changed_by' => auth()->id(), 'note' => "Advance {$advance->number}: Rs. ".number_format($advance->amount, 2)." · {$advance->method} · {$advance->status}. Customer SMS: ".($advance->send_sms ? 'requested' : 'not requested').'.',
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $advance;
        });
    }

    public function postReceipt(JobAdvance $advance, ?string $date = null): void
    {
        $accounts = app(AccountService::class);
        $description = "Advance for {$advance->jobCard->number} · {$advance->number}";
        $accounts->recordTransaction('main', 'income', (float) $advance->amount, $description, $advance, $advance->method, $advance->note, null, $date ?? $advance->paid_at, 'job_advance');
        if ($advance->method !== 'Cash') {
            $accounts->recordTransaction('bank', 'income', (float) $advance->amount, $description, $advance, $advance->method, null, null, $date ?? $advance->paid_at, 'job_advance');
        }
    }

    public function applyToInvoice(JobCard $job, Invoice $invoice): void
    {
        $advances = JobAdvance::where('job_card_id', $job->id)->whereNull('payment_id')->whereIn('status', ['Completed', 'Hold'])->lockForUpdate()->get();
        if (round((float) $advances->sum('amount'), 2) > round((float) $invoice->total, 2)) {
            throw ValidationException::withMessages(['advance_amount' => 'The final bill cannot be less than its received advances. Review the bill total before generating the invoice.']);
        }
        foreach ($advances as $advance) {
            // This is the existing receipt moving onto its bill, not a second collection or SMS.
            $payment = Payment::withoutEvents(fn () => $invoice->payments()->create([
                'number' => $advance->number, 'amount' => $advance->amount, 'received_amount' => $advance->amount, 'change_amount' => 0,
                'method' => $advance->method, 'account_id' => $advance->account_id, 'cheque_id' => $advance->cheque_id,
                'paid_at' => $advance->paid_at, 'status' => $advance->status, 'source_job_card_id' => $job->id,
                'balance_after' => round((float) $invoice->due_amount - (float) $advance->amount, 2),
                'note' => $advance->note, 'created_by' => $advance->created_by,
            ]));
            // Keep the original money/date; invoice allocation adjusts only the parts/service share.
            AccountTransaction::where('reference_type', $advance->getMorphClass())->where('reference_id', $advance->id)
                ->update(['reference_type' => $payment->getMorphClass(), 'reference_id' => $payment->id]);
            $column = $advance->status === 'Hold' ? 'hold_amount' : 'paid_amount';
            $invoice->$column = round((float) $invoice->$column + (float) $advance->amount, 2);
            $invoice->due_amount = round((float) $invoice->due_amount - (float) $advance->amount, 2);
            $advance->update(['payment_id' => $payment->id]);
        }
        $invoice->save();
        app(AccountService::class)->reallocateInvoiceReceipts($invoice);
    }
}
