<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Models\ExtraIncome;
use App\Models\Invoice;
use App\Models\JobAdvance;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\SupplierPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SettlementService
{
    public function rows(array $input, bool $purchase = false): array
    {
        $rows = $input['payments'] ?? [[
            'method' => $input['method'] ?? $input['payment_method'] ?? 'Cash',
            'amount' => $input['amount'] ?? $input['paid_amount'] ?? 0,
            'account_id' => $input['account_id'] ?? null,
            'receiving_account' => $input['receiving_account'] ?? null,
            'cheque_date' => $input['cheque_date'] ?? null,
            'cheque_number' => $input['cheque_number'] ?? null,
            'bank_name' => $input['bank_name'] ?? null,
            'party_cheque_id' => $input['party_cheque_id'] ?? null,
            'reference' => $input['reference'] ?? null,
            'note' => $input['note'] ?? null,
        ]];
        if (is_array($rows)) {
            $rows = array_map(fn ($row) => is_array($row) ? $row + ['reference' => $input['reference'] ?? null, 'note' => $input['note'] ?? null] : $row, $rows);
        }
        $methods = $purchase ? 'Cash,Card,Bank Transfer,Cheque,Party Cheque' : 'Cash,Card,Bank Transfer,Cheque,QR,Credit/Due';
        $v = Validator::make(['payments' => $rows], [
            'payments' => 'present|array|max:30',
            'payments.*' => 'required|array',
            'payments.*.method' => 'required|in:'.$methods,
            'payments.*.amount' => 'required|numeric|min:0|decimal:0,2',
            'payments.*.account_id' => 'nullable|integer',
            'payments.*.receiving_account' => ['nullable', Rule::in(array_keys(Payment::RECEIVING_ACCOUNTS))],
            'payments.*.source' => ['nullable', Rule::in(array_keys(OutgoingFundingService::SOURCES))],
            'payments.*.cheque_date' => 'nullable|date_format:Y-m-d',
            'payments.*.cheque_number' => 'nullable|string|max:80',
            'payments.*.bank_name' => 'nullable|string|max:120',
            'payments.*.party_cheque_id' => 'nullable|integer',
            'payments.*.reference' => 'nullable|string|max:150',
            'payments.*.note' => 'nullable|string|max:1000',
        ])->validate();
        foreach ($v['payments'] as $i => $row) {
            if (! $purchase && ! empty($row['receiving_account'])) {
                $destination = $row['receiving_account'];
                if (($destination === 'bank') !== ($row['method'] !== 'Cash')) {
                    throw ValidationException::withMessages(["payments.$i.receiving_account" => 'Choose a cash account for Cash or Bank Account for bank, card, QR or cheque payments.']);
                }
                $row['account_id'] = Account::systemAccount(app(OutgoingFundingService::class)->type($destination))->id;
                $v['payments'][$i] = $row;
            }
            if ($purchase && $row['method'] !== 'Party Cheque') {
                $row['source'] ??= $row['method'] === 'Cash' ? 'spare_parts' : 'bank';
                if ($row['method'] !== 'Cash' && $row['source'] !== 'bank') {
                    throw ValidationException::withMessages(["payments.$i.source" => 'Non-cash purchase payments must use Bank Account.']);
                }
                $row['account_id'] = Account::systemAccount(app(OutgoingFundingService::class)->type($row['source']))->id;
                $v['payments'][$i] = $row;
            }

            if ($row['method'] === 'Credit/Due' && (float) $row['amount'] > 0) {
                throw ValidationException::withMessages(["payments.$i.amount" => 'Credit/Due must have zero payment.']);
            }
            if ((float) $row['amount'] <= 0) {
                continue;
            }
            if ($row['method'] === 'Cheque') {
                foreach (['cheque_date', 'cheque_number', 'bank_name'] as $field) {
                    if (empty($row[$field])) {
                        throw ValidationException::withMessages(["payments.$i.$field" => 'Enter the cheque date, number and bank.']);
                    }
                }
            }
            if ($row['method'] === 'Party Cheque') {
                if (empty($row['party_cheque_id'])) {
                    throw ValidationException::withMessages(["payments.$i.party_cheque_id" => 'Select a customer cheque.']);
                }
            } elseif (empty($row['account_id']) || ! Account::whereKey($row['account_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(["payments.$i.account_id" => 'Select an active account.']);
            }
        }

        return array_values(array_filter($v['payments'], fn ($r) => (float) $r['amount'] > 0));
    }

    public function total(array $rows): float
    {
        return round(array_sum(array_column($rows, 'amount')), 2);
    }

    public function receive(Invoice $invoice, array $row, $date = null, ?Cheque $cheque = null, bool $allowCashChange = false): Payment
    {
        return DB::transaction(function () use ($invoice, $row, $date, $cheque, $allowCashChange) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $received = round((float) $row['amount'], 2);
            $change = 0;
            if ($allowCashChange && $row['method'] === 'Cash' && $received > 0) {
                $row['amount'] = min($received, max(0, round((float) $invoice->due_amount, 2)));
                $change = round($received - $row['amount'], 2);
            }
            if ($change <= 0 || (float) $row['amount'] > 0) {
                $this->checkAmount($row, (float) $invoice->due_amount);
            }
            if ($row['method'] === 'Cheque') {
                if (! $invoice->customer_id) {
                    throw ValidationException::withMessages(['customer_id' => 'Select a customer when receiving a cheque.']);
                }
                $cheque ??= $this->newCheque($invoice, $row, 'Received');
            }
            $effectiveDate = now();
            if ($date) {
                $parsed = Carbon::parse($date);
                $effectiveDate = $parsed->format('H:i:s') === '00:00:00' ? $parsed->setTimeFrom(now()) : $parsed;
            }
            $payment = $invoice->payments()->create([
                'number' => 'RCPT-'.Str::upper(Str::random(12)), 'amount' => $row['amount'], 'method' => $row['method'],
                'received_amount' => $received, 'change_amount' => $change,
                'account_id' => $row['account_id'] ?? null, 'cheque_id' => $cheque?->id,
                'receiving_account' => $row['receiving_account'] ?? null,
                'job_advance_id' => $row['job_advance_id'] ?? null,
                'paid_at' => $effectiveDate, 'status' => $cheque ? 'Hold' : 'Completed',
                'balance_after' => round((float) $invoice->due_amount - (float) $row['amount'], 2),
                'reference' => $row['reference'] ?? null, 'note' => $row['note'] ?? null, 'created_by' => auth()->id(),
            ]);
            $this->move($invoice, (float) $payment->amount, $cheque ? 'hold_amount' : 'paid_amount', 1);
            if (! $cheque && (float) $payment->amount > 0) {
                app(AccountService::class)->receiveInvoicePayment($invoice->fresh(), $payment);
            }

            return $payment;
        });
    }

    public function pay(Purchase $purchase, array $row, $date = null, ?Cheque $cheque = null): SupplierPayment
    {
        return DB::transaction(function () use ($purchase, $row, $date, $cheque) {
            app(CashierService::class)->lock();
            $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
            $this->checkAmount($row, (float) $purchase->due_amount);
            if ($row['method'] === 'Party Cheque' && ! $cheque) {
                $cheque = Cheque::lockForUpdate()->findOrFail($row['party_cheque_id']);
                if ($cheque->cheque_type !== 'Received' || $cheque->status !== 'Pending' || $cheque->forwarded_supplier_id || ! $cheque->payments()->where('status', 'Hold')->exists() || round((float) $cheque->amount, 2) !== round((float) $row['amount'], 2)) {
                    throw ValidationException::withMessages(['payments' => 'Choose an available customer cheque and use its full amount.']);
                }
                $cheque->update(['forwarded_supplier_id' => $purchase->supplier_id, 'updated_by' => auth()->id()]);
            } elseif ($row['method'] === 'Cheque') {
                $cheque ??= $this->newCheque($purchase, $row, 'Issued');
            }
            if (($row['source'] ?? null) === 'bank' && $cheque && app(OutgoingFundingService::class)->balances(today()->toDateString(), true)['bank'] < (float) $row['amount']) {
                throw ValidationException::withMessages(['payments' => 'Bank Account does not have enough unreserved funds for this cheque.']);
            }
            $payment = $purchase->payments()->create([
                'number' => 'SPY-'.Str::upper(Str::random(12)), 'supplier_id' => $purchase->supplier_id, 'funding_source' => $row['method'] === 'Party Cheque' ? null : ($row['source'] ?? null),
                'account_id' => $row['account_id'] ?? null, 'amount' => $row['amount'], 'payment_method' => $row['method'],
                'cheque_id' => $cheque?->id, 'payment_date' => $date ?? today(), 'status' => $cheque ? 'Hold' : 'Completed',
                'balance_after' => round((float) $purchase->due_amount - (float) $row['amount'], 2),
                'reference' => $row['reference'] ?? null, 'note' => $row['note'] ?? null, 'created_by' => auth()->id(),
            ]);
            $this->move($purchase, (float) $payment->amount, $cheque ? 'hold_amount' : 'paid_amount', 1);
            if (! $cheque) {
                app(OutgoingFundingService::class)->post([['source' => $row['source'] ?? 'spare_parts', 'amount' => $payment->amount]], (float) $payment->amount, "Payment for {$purchase->number}", $payment, $payment->payment_method, $payment->payment_date, 'purchase');
            }

            return $payment;
        });
    }

    private function checkAmount(array $row, float $due): void
    {
        if ((float) $row['amount'] <= 0 || round((float) $row['amount'], 2) > round($due, 2)) {
            throw ValidationException::withMessages(['payments' => 'Payment amounts cannot exceed the remaining due.']);
        }
    }

    public function prepareCheque(Invoice|Purchase $document, array $row): ?Cheque
    {
        if ($row['method'] === 'Party Cheque') {
            $cheque = Cheque::lockForUpdate()->findOrFail($row['party_cheque_id']);
            if ($cheque->cheque_type !== 'Received' || $cheque->status !== 'Pending' || $cheque->forwarded_supplier_id || ! $cheque->payments()->where('status', 'Hold')->exists() || round((float) $cheque->amount, 2) !== round((float) $row['amount'], 2)) {
                throw ValidationException::withMessages(['payments' => 'Choose an available customer cheque and use its full amount.']);
            }
            $cheque->update(['forwarded_supplier_id' => $document->supplier_id, 'updated_by' => auth()->id()]);

            return $cheque;
        }

        return $row['method'] === 'Cheque' ? $this->newCheque($document, $row, $document instanceof Invoice ? 'Received' : 'Issued') : null;
    }

    private function newCheque(Invoice|Purchase $document, array $row, string $type): Cheque
    {
        $party = $document instanceof Invoice ? $document->customer : $document->supplier;

        return Cheque::create([
            'cheque_type' => $type, 'party_type' => $party->getMorphClass(), 'party_id' => $party->id,
            'party_name' => $party->name, 'reference_type' => $document->getMorphClass(), 'reference_id' => $document->id,
            'related_reference' => $document->number, 'cheque_number' => $row['cheque_number'], 'bank_name' => $row['bank_name'],
            'cheque_date' => $row['cheque_date'], 'amount' => $row['amount'], 'bank_account_id' => $row['account_id'] ?? null,
            'status' => 'Pending', 'created_by' => auth()->id(), 'updated_by' => auth()->id(),
        ]);
    }

    private function move(Invoice|Purchase $document, float $amount, string $column, int $direction): void
    {
        $document->$column = round((float) $document->$column + $direction * $amount, 2);
        $document->due_amount = round((float) $document->due_amount - $direction * $amount, 2);
        $this->saveStatus($document);
    }

    public function saveStatus(Invoice|Purchase $document): void
    {
        $hold = (float) $document->hold_amount > 0;
        $paid = (float) $document->paid_amount > 0;
        if ($document instanceof Invoice) {
            $document->status = $hold ? ((float) $document->due_amount > 0 ? 'Partially Paid / Hold' : 'Hold') : ((float) $document->due_amount <= 0 ? 'Paid' : ($paid ? 'Partially Paid' : 'Unpaid'));
        } else {
            $document->payment_status = $hold ? ((float) $document->due_amount > 0 ? 'Partial / Hold' : 'Hold') : ((float) $document->due_amount <= 0 ? 'Paid' : ($paid ? 'Partial' : 'Due'));
        }
        $methodColumn = $document instanceof Invoice ? 'method' : 'payment_method';
        $methods = $document->payments()->whereIn('status', ['Hold', 'Completed'])->distinct()->pluck($methodColumn);
        if ($methods->isNotEmpty()) {
            $document->payment_method = $methods->count() > 1 ? 'Mixed' : $methods->first();
        }
        $document->save();
    }

    public function resolve(Cheque $cheque, string $status, ?string $date = null, ?string $reason = null): void
    {
        DB::transaction(function () use ($cheque, $status, $date, $reason) {
            $cheque = Cheque::lockForUpdate()->findOrFail($cheque->id);
            $old = $cheque->status;
            if ($old === $status) {
                return;
            }
            if (in_array($old, ['Returned', 'Cancelled']) || ($old === 'Cleared' && ! in_array($status, ['Returned', 'Cancelled']))) {
                throw ValidationException::withMessages(['status' => 'This cheque has already been settled.']);
            }
            if ($status === 'Cleared' && ($cheque->cheque_date->isFuture() || ($date && Carbon::parse($date)->startOfDay()->lt($cheque->cheque_date)))) {
                throw ValidationException::withMessages(['status' => 'A cheque can only pass on or after its cheque date.']);
            }
            $service = app(AccountService::class);
            $payments = $cheque->payments()->lockForUpdate()->get();
            $supplierPayments = $cheque->supplierPayments()->lockForUpdate()->get();
            $advances = JobAdvance::where('cheque_id', $cheque->id)->whereNull('payment_id')->lockForUpdate()->get();
            $extraIncomes = ExtraIncome::where('cheque_id', $cheque->id)->lockForUpdate()->get();
            foreach ($extraIncomes as $extraIncome) {
                if (! in_array($extraIncome->status, ['Hold', 'Completed'], true)) {
                    continue;
                }
                if ($status === 'Cleared') {
                    $extraIncome->update(['status' => 'Completed']);
                    app(ExtraIncomeService::class)->postLedgerIncome($extraIncome, $date ?? today()->toDateString());
                } elseif (in_array($status, ['Returned', 'Cancelled'], true)) {
                    AccountTransaction::where('reference_type', $extraIncome->getMorphClass())
                        ->where('reference_id', $extraIncome->id)
                        ->where('status', 'active')
                        ->where('transaction_type', '!=', 'reversal')
                        ->get()
                        ->each(fn ($entry) => $service->reverseTransaction($entry, $reason ?? 'Extra money cheque '.strtolower($status)));
                    $extraIncome->update(['status' => $status]);
                }
            }
            foreach ($advances as $advance) {
                if (! in_array($advance->status, ['Hold', 'Completed'], true)) {
                    continue;
                }
                if ($status === 'Cleared') {
                    $advance->update(['status' => 'Completed', 'spare_parts_to_bank' => ! $cheque->forwarded_supplier_id]);
                    app(JobAdvanceService::class)->postReceipt($advance, $date ?? today()->toDateString(), ! $cheque->forwarded_supplier_id);
                } elseif (in_array($status, ['Returned', 'Cancelled'], true)) {
                    AccountTransaction::where('reference_type', $advance->getMorphClass())->where('reference_id', $advance->id)
                        ->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()
                        ->each(fn ($entry) => $service->reverseTransaction($entry, $reason ?? 'Advance cheque returned'));
                    app(PaymentChargeService::class)->reverseForRecord($advance, $reason ?? 'Advance cheque returned');
                    $advance->update(['status' => $status]);
                }
            }
            foreach ($payments->concat($supplierPayments) as $payment) {
                if (! in_array($payment->status, ['Hold', 'Completed'])) {
                    continue;
                }
                $document = $payment instanceof Payment ? Invoice::lockForUpdate()->findOrFail($payment->invoice_id) : Purchase::lockForUpdate()->findOrFail($payment->purchase_id);
                if ($document->status === 'Cancelled') {
                    continue;
                }
                $amount = (float) $payment->amount;
                if ($status === 'Cleared') {
                    $document->hold_amount = round((float) $document->hold_amount - $amount, 2);
                    $document->paid_amount = round((float) $document->paid_amount + $amount, 2);
                    $payment->update(['status' => 'Completed']);
                    if ($payment instanceof Payment) {
                        $service->receiveInvoicePayment($document, $payment, ! $cheque->forwarded_supplier_id, $date ?? today());
                    } else {
                        if ($payment->funding_source) {
                            app(OutgoingFundingService::class)->post([['source' => $payment->funding_source, 'amount' => $amount]], $amount, "Cheque {$cheque->cheque_number} passed", $payment, $payment->payment_method, $date ?? today(), 'purchase');
                        } else {
                            $service->outgoing('spare_parts', $amount, "Cheque {$cheque->cheque_number} passed", $payment, $payment->payment_method, $date ?? today(), 'purchase', ! $cheque->forwarded_supplier_id);
                        }
                    }
                    $this->saveStatus($document);
                } elseif (in_array($status, ['Returned', 'Cancelled'])) {
                    $column = $payment->status === 'Hold' ? 'hold_amount' : 'paid_amount';
                    $document->due_date ??= $cheque->cheque_date;
                    $this->move($document, $amount, $column, -1);
                    AccountTransaction::where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()->each(fn ($t) => $service->reverseTransaction($t, $reason ?? 'Cheque returned'));
                    if ($payment instanceof Payment) {
                        app(PaymentChargeService::class)->reverseForRecord($payment, $reason ?? 'Cheque returned');
                    }
                    $payment->update(['status' => $status]);
                    if ($document instanceof Invoice) {
                        $service->reallocateInvoiceReceipts($document);
                    }
                }
            }
            if ($payments->isEmpty() && $supplierPayments->isEmpty() && $advances->isEmpty() && $extraIncomes->isEmpty()) {
                if ($status === 'Cleared') {
                    $service->recordTransaction('bank', $cheque->cheque_type === 'Received' ? 'income' : 'expense', (float) $cheque->amount, "Cheque {$cheque->cheque_number} passed", $cheque, 'Cheque', $cheque->note, $cheque->bank_account_id, $date ?? today(), 'cheque');
                } elseif ($old === 'Cleared') {
                    AccountTransaction::where('reference_type', $cheque->getMorphClass())->where('reference_id', $cheque->id)->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()->each(fn ($t) => $service->reverseTransaction($t, $reason ?? 'Cheque returned'));
                }
            }
            $cheque->update(['status' => $status, 'realized_date' => $status === 'Cleared' ? ($date ?? today()) : $cheque->realized_date, 'status_reason' => $reason, 'updated_by' => auth()->id()]);
        });
    }
}
