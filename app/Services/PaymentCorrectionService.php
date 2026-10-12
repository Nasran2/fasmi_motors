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
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentCorrectionService
{
    public function correct(JobCard $job, Payment $payment, array $data): Payment
    {
        return DB::transaction(function () use ($job, $payment, $data) {
            $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = $invoice->payments()->lockForUpdate()->findOrFail($payment->id);
            $job->loadMissing('invoice', 'intake.bike');
            $related = $invoice->job_card_id === $job->id || PreviousCustomerBalance::forJob($job, includeSettled: true)->contains('invoice_id', $invoice->id);
            abort_unless($related, 404);
            $cheque = $payment->cheque_id ? Cheque::withTrashed()->lockForUpdate()->findOrFail($payment->cheque_id) : null;
            if ($cheque) {
                $payment->setRelation('cheque', $cheque);
            }
            if ($invoice->status === 'Cancelled' || $invoice->invoice_type === 'Quotation') {
                throw ValidationException::withMessages(['payment' => 'Payments on cancelled bills or quotations cannot be edited.']);
            }
            if ($reason = $payment->correction_block_reason) {
                throw ValidationException::withMessages(['payment' => $reason]);
            }
            $settlement = app(SettlementService::class);
            $rows = $settlement->rows($data + ['reference' => $payment->reference, 'note' => $payment->note]);
            if (count($rows) !== 1) {
                throw ValidationException::withMessages(['amount' => 'Enter one positive replacement payment amount.']);
            }
            $row = $rows[0];
            $before = $this->snapshot($payment);
            $accounts = app(AccountService::class);
            if ($cheque) {
                $settlement->resolve($cheque, 'Cancelled', reason: $data['reason']);
                $invoice->refresh();
            } else {
                AccountTransaction::where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)
                    ->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()
                    ->each(fn ($entry) => $accounts->reverseTransaction($entry, $data['reason']));
                $invoice->paid_amount = round((float) $invoice->paid_amount - (float) $payment->amount, 2);
                $invoice->due_amount = round((float) $invoice->due_amount + (float) $payment->amount, 2);
            }
            $payment->update(['status' => 'Cancelled', 'cancel_reason' => $data['reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
            $settlement->saveStatus($invoice);
            $accounts->reallocateInvoiceReceipts($invoice);
            $replacement = $settlement->receive($invoice, $row, Carbon::parse($data['paid_at'])->startOfDay(), allowCashChange: true);
            PaymentCorrection::create([
                'job_card_id' => $job->id, 'invoice_id' => $invoice->id, 'payment_id' => $payment->id,
                'replacement_payment_id' => $replacement->id, 'before' => $before, 'after' => $this->snapshot($replacement),
                'reason' => $data['reason'], 'changed_by' => auth()->id(),
            ]);

            return $replacement;
        }, 3);
    }

    public function snapshot(Payment $payment): array
    {
        return [
            'number' => $payment->number, 'invoice_number' => $payment->invoice->number,
            'method' => $payment->method, 'amount' => (float) $payment->amount,
            'received_amount' => (float) ($payment->received_amount ?? $payment->amount), 'change_amount' => (float) $payment->change_amount,
            'paid_at' => $payment->paid_at->toDateString(), 'account_id' => $payment->account_id, 'account_name' => $payment->account?->name,
            'status' => $payment->status, 'cheque_id' => $payment->cheque_id,
            'cheque_date' => $payment->cheque?->cheque_date->toDateString(), 'cheque_number' => $payment->cheque?->cheque_number,
            'bank_name' => $payment->cheque?->bank_name,
        ];
    }
}
