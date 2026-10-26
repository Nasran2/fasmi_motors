<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Payment;
use App\Support\PreviousCustomerBalance;
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

    public function correct(JobCard $job, JobAdvance $advance, array $data, array $row): JobAdvance
    {
        return DB::transaction(function () use ($job, $advance, $data, $row) {
            $before = $advance->getAttributes();
            $this->cancel($job, $advance, $data['advance_reason'], 'corrected');
            $replacement = $this->receive($job, $data, $row);
            $this->audit($job, $advance, 'Advance corrected', $data['advance_reason'], $before, $replacement->getAttributes());

            return $replacement;
        });
    }

    public function cancel(JobCard $job, JobAdvance $advance, string $reason, string $action = 'deleted'): void
    {
        DB::transaction(function () use ($job, $advance, $reason, $action) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $advance = JobAdvance::lockForUpdate()->findOrFail($advance->id);
            abort_unless($advance->job_card_id === $job->id, 404);
            if ($job->invoice || $advance->payment_id || ! in_array($advance->status, ['Completed', 'Hold'], true)) {
                throw ValidationException::withMessages(['advance_reason' => 'Only active advances on an unbilled job can be changed here. Correct billed receipts through Payments received.']);
            }
            $before = $advance->getAttributes();
            if ($advance->cheque_id) {
                $cheque = Cheque::withTrashed()->lockForUpdate()->findOrFail($advance->cheque_id);
                if ($cheque->trashed() || ! in_array($cheque->status, ['Pending', 'Deposited'], true) || $cheque->forwarded_supplier_id || $cheque->payments()->exists() || $cheque->supplierPayments()->exists()) {
                    throw ValidationException::withMessages(['advance_reason' => 'A settled, forwarded or shared cheque must be handled in the cheque register.']);
                }
                app(SettlementService::class)->resolve($cheque, 'Cancelled', reason: $reason);
            } else {
                AccountTransaction::where('reference_type', $advance->getMorphClass())->where('reference_id', $advance->id)
                    ->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()
                    ->each(fn ($entry) => app(AccountService::class)->reverseTransaction($entry, $reason));
            }
            $advance->update(['status' => 'Cancelled']);
            if ($action === 'deleted') {
                $this->audit($job, $advance, 'Advance deleted', $reason, $before, $advance->getAttributes());
            }
        });
    }

    private function audit(JobCard $job, JobAdvance $advance, string $event, string $reason, array $before, array $after): void
    {
        AuditLog::create([
            'user_id' => auth()->id(), 'event' => $event, 'auditable_type' => $advance->getMorphClass(), 'auditable_id' => $advance->id,
            'old_values' => $before, 'new_values' => $after + ['reason' => $reason], 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);
        $summary = function ($values) {
            $account = Account::find($values['account_id']);
            $cheque = empty($values['cheque_id']) ? null : Cheque::withTrashed()->find($values['cheque_id']);

            return ($values['number'] ?? '').' · Rs. '.number_format($values['amount'], 2).' · '.$values['method'].' · '.$values['paid_at'].' · '.$values['status']
                .' · '.($account?->name ?? 'Account '.$values['account_id'])
                .($cheque ? " · Cheque {$cheque->cheque_number} · {$cheque->bank_name} · ".$cheque->cheque_date->toDateString() : '');
        };
        DB::table('job_card_status_logs')->insert([
            'job_card_id' => $job->id, 'old_status' => $job->status, 'new_status' => $job->status, 'changed_by' => auth()->id(),
            'note' => $event.'. Before: '.$summary($before).'. After: '.$summary($after).'. Reason: '.$reason,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function applyToInvoice(JobCard $job, Invoice $invoice, bool $includePrevious = true): void
    {
        $advances = JobAdvance::where('job_card_id', $job->id)->whereNull('payment_id')->whereIn('status', ['Completed', 'Hold'])->orderBy('id')->lockForUpdate()->get();
        if ($advances->isEmpty()) {
            return;
        }
        $ids = collect([$invoice->id]);
        if ($includePrevious) {
            $ids = $ids->merge(PreviousCustomerBalance::forJob($job, $invoice)->pluck('invoice_id'));
        }
        $invoices = Invoice::whereIn('id', $ids)->where('status', '!=', 'Cancelled')->where('invoice_type', '!=', 'Quotation')->orderBy('id')->lockForUpdate()->get()
            ->sortBy(fn ($bill) => ($bill->id === $invoice->id ? '0' : '1').$bill->created_at->format('Y-m-d H:i:s').sprintf('%020d', $bill->id))->values();
        if (round((float) $advances->where('method', '!=', 'Cash')->sum('amount'), 2) > round((float) $invoices->sum('due_amount'), 2)) {
            throw ValidationException::withMessages(['advance_amount' => 'Non-cash advances exceed the available current and selected old balances. Review the advances before billing.']);
        }
        // Use non-cash first so only unused cash is returned as change.
        foreach ($advances->sortBy(fn ($advance) => $advance->method === 'Cash' ? 1 : 0) as $advance) {
            $remaining = min((float) $advance->amount, round((float) $invoices->sum('due_amount'), 2));
            $change = round((float) $advance->amount - $remaining, 2);
            $group = (string) Str::uuid();
            $pieces = collect();
            foreach ($invoices as $bill) {
                $amount = min($remaining, (float) $bill->due_amount);
                if ($amount <= 0) {
                    continue;
                }
                $pieces->push($this->invoicePiece($job, $advance, $bill, $amount, $group, $pieces->isEmpty()));
                $column = $advance->status === 'Hold' ? 'hold_amount' : 'paid_amount';
                $bill->$column = round((float) $bill->$column + $amount, 2);
                $bill->due_amount = round((float) $bill->due_amount - $amount, 2);
                app(SettlementService::class)->saveStatus($bill);
                $remaining = round($remaining - $amount, 2);
            }
            if ($pieces->isEmpty()) {
                $pieces->push($this->invoicePiece($job, $advance, $invoice, 0, $group, true));
            }
            if ($change > 0) {
                $last = $pieces->last();
                $last->updateQuietly(['received_amount' => round((float) $last->amount + $change, 2), 'change_amount' => $change]);
            }
            // Preserve the original receipt date and bank movement without posting new money.
            AccountTransaction::where('reference_type', $advance->getMorphClass())->where('reference_id', $advance->id)
                ->update(['reference_type' => (new Payment)->getMorphClass(), 'reference_id' => $pieces->first()->id]);
            $advance->update(['payment_id' => $pieces->first()->id]);
        }
        foreach ($invoices as $bill) {
            app(AccountService::class)->reallocateInvoiceReceipts($bill);
        }
        $invoice->refresh();
    }

    private function invoicePiece(JobCard $job, JobAdvance $advance, Invoice $invoice, float $amount, string $group, bool $first): Payment
    {
        // Allocation of an existing advance must not send a new payment SMS.
        return Payment::withoutEvents(fn () => $invoice->payments()->create([
            'number' => $first ? $advance->number : 'RCPT-'.Str::upper(Str::random(12)), 'amount' => $amount,
            'received_amount' => $amount, 'change_amount' => 0, 'receipt_group_id' => $group,
            'method' => $advance->method, 'account_id' => $advance->account_id, 'cheque_id' => $advance->cheque_id,
            'paid_at' => $advance->paid_at, 'status' => $advance->status, 'source_job_card_id' => $job->id,
            'balance_after' => round((float) $invoice->due_amount - $amount, 2),
            'note' => $advance->note, 'created_by' => $advance->created_by,
        ]));
    }
}
