<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Cheque;
use App\Models\ExtraIncome;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\PaymentCorrection;
use App\Models\SmsLog;
use App\Support\PreviousCustomerBalance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
            $row['job_advance_id'] = $payment->job_advance_id;
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
                app(PaymentChargeService::class)->reverseForRecord($receipt, $data['reason']);
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

    public function delete(JobCard $job, Payment $payment): void
    {
        DB::transaction(function () use ($job, $payment) {
            app(CashierService::class)->lock();
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = $invoice->payments()->lockForUpdate()->findOrFail($payment->id);
            $job->loadMissing('invoice', 'intake.bike');

            $related = $invoice->job_card_id === $job->id || PreviousCustomerBalance::forJob($job, includeSettled: true)->contains('invoice_id', $invoice->id);
            abort_unless($related, 404);

            $receipts = $payment->receipt_group_id
                ? Payment::where('receipt_group_id', $payment->receipt_group_id)->orderBy('id')->lockForUpdate()->get()
                : collect([$payment]);

            $allowed = PreviousCustomerBalance::forJob($job, includeSettled: true)->pluck('invoice_id')->push($job->invoice?->id);
            foreach ($receipts as $receipt) {
                abort_unless($allowed->contains($receipt->invoice_id), 404);
                $document = Invoice::lockForUpdate()->findOrFail($receipt->invoice_id);
                $receipt->setRelation('invoice', $document);
                if ($document->status === 'Cancelled' || $document->invoice_type === 'Quotation') {
                    throw ValidationException::withMessages(['payment' => 'Payments on cancelled bills or quotations cannot be deleted.']);
                }
                if ($receipt->cheque_id) {
                    $receipt->setRelation('cheque', Cheque::withTrashed()->lockForUpdate()->findOrFail($receipt->cheque_id));
                }
                if ($reason = $receipt->correction_block_reason) {
                    throw ValidationException::withMessages(['payment' => $reason]);
                }
            }

            // Walk backwards through any PaymentCorrection history chain pointing to these receipts
            // so foreign keys never block deletion and zero correction/reversal artifacts remain.
            $allPayments = $receipts->keyBy('id');
            $correctionIds = [];
            $frontierIds = $receipts->pluck('id')->all();

            while (! empty($frontierIds)) {
                $corrections = PaymentCorrection::whereIn('replacement_payment_id', $frontierIds)
                    ->orWhereIn('payment_id', $frontierIds)
                    ->lockForUpdate()
                    ->get();

                $newCorrections = $corrections->reject(fn (PaymentCorrection $c) => in_array($c->id, $correctionIds, true));
                if ($newCorrections->isEmpty()) {
                    break;
                }

                $correctionIds = array_merge($correctionIds, $newCorrections->pluck('id')->all());
                $candidateIds = $newCorrections->pluck('payment_id')
                    ->merge($newCorrections->pluck('replacement_payment_id'))
                    ->unique()
                    ->reject(fn ($id) => $allPayments->has($id))
                    ->values()
                    ->all();

                if (empty($candidateIds)) {
                    break;
                }

                $ancestorBases = Payment::whereIn('id', $candidateIds)->lockForUpdate()->get();
                $ancestorGroupIds = $ancestorBases->pluck('receipt_group_id')->filter()->unique()->values()->all();
                $groupSiblings = $ancestorGroupIds
                    ? Payment::whereIn('receipt_group_id', $ancestorGroupIds)->where('status', 'Cancelled')->lockForUpdate()->get()
                    : collect();

                $discovered = $ancestorBases->concat($groupSiblings)->where('status', 'Cancelled');
                $frontierIds = [];
                foreach ($discovered as $item) {
                    if (! $allPayments->has($item->id)) {
                        $allPayments->put($item->id, $item);
                        $frontierIds[] = $item->id;
                    }
                }
            }

            if (! empty($correctionIds)) {
                PaymentCorrection::whereIn('id', array_unique($correctionIds))->delete();
            }

            $allPaymentIds = $allPayments->keys()->values()->all();
            $chargeExpenseIdsToPurge = $allPayments->pluck('charge_expense_id')->filter()->unique()->values()->all();
            $chequeIdsToClean = $allPayments->pluck('cheque_id')->filter()->unique()->values()->all();
            $advanceIds = $allPayments->pluck('job_advance_id')->filter()->unique()->values();
            $linkedAdvances = JobAdvance::whereIn('payment_id', $allPaymentIds)
                ->when($advanceIds->isNotEmpty(), fn ($q) => $q->orWhereIn('id', $advanceIds))
                ->lockForUpdate()
                ->get();
            $chargeExpenseIdsToPurge = array_values(array_unique(array_merge(
                $chargeExpenseIdsToPurge,
                $linkedAdvances->pluck('charge_expense_id')->filter()->all(),
            )));

            if ($linkedAdvances->isNotEmpty()) {
                JobAdvance::whereIn('payment_id', $allPaymentIds)->update(['payment_id' => null]);
            }

            // Collect and hard-delete all ledger entries referencing any of the deleted payments.
            $paymentMorph = (new Payment)->getMorphClass();
            $txMorph = (new AccountTransaction)->getMorphClass();
            $txEntries = AccountTransaction::where('reference_type', $paymentMorph)
                ->whereIn('reference_id', $allPaymentIds)
                ->lockForUpdate()
                ->get();

            $affectedAccountIds = $txEntries->pluck('account_id')->filter()->unique()->values()->all();
            if ($txEntries->isNotEmpty()) {
                $txIds = $txEntries->pluck('id')->all();
                if (Schema::hasTable('audit_logs')) {
                    AuditLog::where('auditable_type', $txMorph)->whereIn('auditable_id', $txIds)->delete();
                }
                AccountTransaction::whereIn('id', $txIds)->delete();
            }

            // Adjust invoice paid_amount / hold_amount / due_amount for each active receipt being removed.
            $affectedInvoiceIds = [];
            foreach ($receipts as $receipt) {
                $document = Invoice::lockForUpdate()->findOrFail($receipt->invoice_id);
                $affectedInvoiceIds[] = $document->id;
                $amount = round((float) $receipt->amount, 2);
                if ($receipt->status === 'Completed') {
                    $document->paid_amount = max(0.0, round((float) $document->paid_amount - $amount, 2));
                    $document->due_amount = round((float) $document->due_amount + $amount, 2);
                } elseif ($receipt->status === 'Hold') {
                    $document->hold_amount = max(0.0, round((float) $document->hold_amount - $amount, 2));
                    $document->due_amount = round((float) $document->due_amount + $amount, 2);
                }
                if ((float) $document->due_amount > 0 && ! $document->due_date) {
                    $document->due_date = ($document->bill_date ?? $document->created_at ?? today())->copy()->addDays(30);
                }
                $document->saveQuietly();
            }

            // Purge SMS logs and Audit logs referencing the deleted payments, then hard-delete the payments.
            if (Schema::hasTable('sms_logs')) {
                SmsLog::where('reference_type', $paymentMorph)->whereIn('reference_id', $allPaymentIds)->delete();
            }
            if (Schema::hasTable('audit_logs')) {
                AuditLog::where('auditable_type', $paymentMorph)->whereIn('auditable_id', $allPaymentIds)->delete();
            }
            Payment::whereIn('id', $allPaymentIds)->delete();

            // Clean up any linked JobAdvance if no remaining payment uses it.
            if ($linkedAdvances->isNotEmpty()) {
                $advanceMorph = (new JobAdvance)->getMorphClass();
                foreach ($linkedAdvances as $advance) {
                    $remainingAdvancePayment = Payment::where('job_advance_id', $advance->id)
                        ->whereIn('status', ['Completed', 'Hold'])
                        ->orderBy('id')
                        ->first();
                    if ($remainingAdvancePayment) {
                        $advance->updateQuietly(['payment_id' => $remainingAdvancePayment->id]);

                        continue;
                    }

                    $advTx = AccountTransaction::where('reference_type', $advanceMorph)
                        ->where('reference_id', $advance->id)
                        ->lockForUpdate()
                        ->get();
                    if ($advTx->isNotEmpty()) {
                        $affectedAccountIds = array_merge($affectedAccountIds, $advTx->pluck('account_id')->all());
                        if (Schema::hasTable('audit_logs')) {
                            AuditLog::where('auditable_type', $txMorph)->whereIn('auditable_id', $advTx->pluck('id')->all())->delete();
                        }
                        AccountTransaction::whereIn('id', $advTx->pluck('id')->all())->delete();
                    }
                    if ($advance->cheque_id) {
                        $chequeIdsToClean[] = $advance->cheque_id;
                    }
                    DB::table('job_card_status_logs')
                        ->where('job_card_id', $advance->job_card_id)
                        ->where('note', 'like', '%'.$advance->number.'%')
                        ->delete();
                    if (Schema::hasTable('sms_logs')) {
                        SmsLog::where('reference_type', $advanceMorph)->where('reference_id', $advance->id)->delete();
                    }
                    if (Schema::hasTable('audit_logs')) {
                        AuditLog::where('auditable_type', $advanceMorph)->where('auditable_id', $advance->id)->delete();
                    }
                    JobAdvance::whereKey($advance->id)->delete();
                }
            }

            if (! empty($chargeExpenseIdsToPurge)) {
                $purgedAccountIds = app(PaymentChargeService::class)->purgeExpenses($chargeExpenseIdsToPurge);
                $affectedAccountIds = array_merge($affectedAccountIds, $purgedAccountIds);
            }

            // Hard-delete any unreferenced cheques associated with the deleted receipts.
            if (! empty($chequeIdsToClean)) {
                $chequeMorph = (new Cheque)->getMorphClass();
                foreach (array_unique($chequeIdsToClean) as $chequeId) {
                    $cheque = Cheque::withTrashed()->lockForUpdate()->find($chequeId);
                    if (! $cheque || $cheque->forwarded_supplier_id) {
                        continue;
                    }
                    if ($cheque->payments()->exists()
                        || $cheque->supplierPayments()->exists()
                        || JobAdvance::where('cheque_id', $cheque->id)->exists()
                        || ExtraIncome::where('cheque_id', $cheque->id)->exists()) {
                        continue;
                    }
                    $chequeTx = AccountTransaction::where('reference_type', $chequeMorph)
                        ->where('reference_id', $cheque->id)
                        ->lockForUpdate()
                        ->get();
                    if ($chequeTx->isNotEmpty()) {
                        $affectedAccountIds = array_merge($affectedAccountIds, $chequeTx->pluck('account_id')->all());
                        if (Schema::hasTable('audit_logs')) {
                            AuditLog::where('auditable_type', $txMorph)->whereIn('auditable_id', $chequeTx->pluck('id')->all())->delete();
                        }
                        AccountTransaction::whereIn('id', $chequeTx->pluck('id')->all())->delete();
                    }
                    if (Schema::hasTable('audit_logs')) {
                        AuditLog::where('auditable_type', $chequeMorph)->where('auditable_id', $cheque->id)->delete();
                    }
                    Cheque::withTrashed()->whereKey($cheque->id)->forceDelete();
                }
            }

            // Refresh invoice status, payment_method, remaining payments' balance_after, and clean receipt allocations.
            $settlement = app(SettlementService::class);
            $accounts = app(AccountService::class);
            foreach (array_unique($affectedInvoiceIds) as $invoiceId) {
                $document = Invoice::lockForUpdate()->findOrFail($invoiceId);
                $remainingPayments = $document->payments()
                    ->whereIn('status', ['Completed', 'Hold'])
                    ->orderBy('paid_at')
                    ->orderBy('id')
                    ->get();

                $runningRemaining = max(0.0, round((float) $document->total - (float) $document->return_amount, 2));
                foreach ($remainingPayments as $rem) {
                    $runningRemaining = max(0.0, round($runningRemaining - (float) $rem->amount, 2));
                    if (round((float) $rem->balance_after, 2) !== $runningRemaining) {
                        $rem->updateQuietly(['balance_after' => $runningRemaining]);
                    }
                }

                $settlement->saveStatus($document);
                if ($remainingPayments->isEmpty() && $document->payment_method !== 'Credit/Due') {
                    $document->payment_method = 'Credit/Due';
                    $document->saveQuietly();
                }

                $rebuiltIds = $accounts->rebuildInvoiceReceipts($document->fresh());
                $affectedAccountIds = array_merge($affectedAccountIds, $rebuiltIds);
            }

            $systemAccountIds = collect(array_keys(CashierService::ACCOUNTS))
                ->map(fn (string $type) => Account::systemAccount($type)?->id)
                ->filter()
                ->all();
            $allAccountIds = array_values(array_unique(array_merge($affectedAccountIds, $systemAccountIds)));

            foreach (Account::whereIn('id', $allAccountIds)->orderBy('id')->lockForUpdate()->get() as $account) {
                $accounts->recalculateBalances($account);
            }
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
