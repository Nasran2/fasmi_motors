<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\PaymentCorrection;
use App\Support\PreviousCustomerBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentCorrectionService
{
    public function correct(JobCard $job, Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($job, $payment, $data) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = $invoice->payments()->lockForUpdate()->findOrFail($payment->id);
            $job->loadMissing('invoice', 'intake.bike');
            $related = $invoice->job_card_id === $job->id || PreviousCustomerBalance::forJob($job, includeSettled: true)->contains('invoice_id', $invoice->id);
            abort_unless($related, 404);
            $receipts = $payment->receipt_group_id ? Payment::where('receipt_group_id', $payment->receipt_group_id)->orderBy('id')->lockForUpdate()->get() : collect([$payment]);
            $allowed = PreviousCustomerBalance::forJob($job, includeSettled: true)->pluck('invoice_id')->push($job->invoice?->id);
            foreach ($receipts as $receipt) {
                abort_unless($allowed->contains($receipt->invoice_id), 404);
                $document = Invoice::lockForUpdate()->findOrFail($receipt->invoice_id);
                $receipt->setRelation('invoice', $document);
                if ($document->status === 'Cancelled' || $document->invoice_type === 'Quotation') {
                    throw ValidationException::withMessages(['payment' => 'Payments on cancelled bills or quotations cannot be edited.']);
                }
                if ($receipt->cheque_id) {
                    $receipt->setRelation('cheque', Cheque::withTrashed()->lockForUpdate()->findOrFail($receipt->cheque_id));
                }
                if ($reason = $receipt->correction_block_reason) {
                    throw ValidationException::withMessages(['payment' => $reason]);
                }
            }
            $settlement = app(SettlementService::class);
            $rows = $settlement->rows($data + ['reference' => $payment->reference, 'note' => $payment->note]);
            if (count($rows) !== 1) {
                throw ValidationException::withMessages(['amount' => 'Enter one positive replacement payment amount.']);
            }
            $row = $rows[0];
            $before = $this->receiptSnapshot($receipts);
            $accounts = app(AccountService::class);
            foreach ($receipts->pluck('cheque_id')->filter()->unique() as $chequeId) {
                $settlement->resolve(Cheque::findOrFail($chequeId), 'Cancelled', reason: $data['reason']);
            }
            foreach ($receipts as $receipt) {
                $document = $receipt->invoice->refresh();
                if (! $receipt->cheque_id) {
                    AccountTransaction::where('reference_type', $receipt->getMorphClass())->where('reference_id', $receipt->id)
                        ->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()
                        ->each(fn ($entry) => $accounts->reverseTransaction($entry, $data['reason'], $entry->transaction_date));
                    $document->paid_amount = round((float) $document->paid_amount - (float) $receipt->amount, 2);
                    $document->due_amount = round((float) $document->due_amount + (float) $receipt->amount, 2);
                }
                $receipt->update(['status' => 'Cancelled', 'cancel_reason' => $data['reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
                $settlement->saveStatus($document);
                $accounts->reallocateInvoiceReceipts($document);
            }
            $primary = $payment->source_job_card_id === $job->id ? ($job->invoice ?? $invoice) : $invoice;
            $replacements = app(JobPaymentAllocationService::class)->receive($job, $primary->refresh(), [$row], Carbon::parse($data['paid_at'])->startOfDay(), includePrevious: (bool) ($data['include_previous_balance'] ?? true));
            $replacement = $replacements->first();
            PaymentCorrection::create([
                'job_card_id' => $job->id, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                'replacement_payment_id' => $replacement->id, 'before' => $before, 'after' => $this->receiptSnapshot($replacements) + ['include_previous_balance' => (bool) ($data['include_previous_balance'] ?? true)],
                'reason' => $data['reason'], 'changed_by' => auth()->id(),
            ]);

            return $replacement;
        }, 3);
    }

    public function receiptSnapshot(Collection $payments): array
    {
        $snapshot = $this->snapshot($payments->first());
        foreach (['amount', 'received_amount', 'change_amount'] as $field) {
            $snapshot[$field] = round($payments->sum(fn ($payment) => (float) ($payment->$field ?? ($field === 'received_amount' ? $payment->amount : 0))), 2);
        }
        $snapshot['allocations'] = $payments->map(fn ($payment) => $this->snapshot($payment))->all();

        return $snapshot;
    }

    public function snapshot(Payment $payment): array
    {
        return [
            'number' => $payment->number, 'invoice_number' => $payment->invoice->number,
            'method' => $payment->method, 'amount' => (float) $payment->amount,
            'received_amount' => (float) ($payment->received_amount ?? $payment->amount), 'change_amount' => (float) $payment->change_amount,
            'paid_at' => $payment->paid_at->toDateString(), 'account_id' => $payment->account_id, 'account_name' => $payment->receiving_account_label,
            'status' => $payment->status, 'cheque_id' => $payment->cheque_id,
            'cheque_date' => $payment->cheque?->cheque_date->toDateString(), 'cheque_number' => $payment->cheque?->cheque_number,
            'bank_name' => $payment->cheque?->bank_name,
        ];
    }
}
