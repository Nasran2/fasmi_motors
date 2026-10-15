<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\FinancialCategory;
use App\Models\Invoice;
use App\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PartsSaleEditService
{
    public function __construct(private WorkshopService $stock, private AccountService $accounts) {}

    /** Build the same locked calculation for the confirmation popup and the final save. */
    public function plan(Invoice $invoice, array $data): array
    {
        $oldItems = $invoice->items()->with('product')->lockForUpdate()->get()->keyBy('id');
        $products = Product::whereIn('id', collect($data['items'] ?? [])->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
        $newItems = [];
        $remaining = [];
        $seen = [];
        $parts = 0;
        foreach ($data['items'] ?? [] as $index => $line) {
            $old = $oldItems->get($line['id'] ?? null);
            if (! empty($line['id']) && (! $old || isset($seen[$line['id']]))) {
                throw ValidationException::withMessages(['items' => 'Invalid or duplicate sale line.']);
            }
            $seen[$line['id'] ?? "new-{$index}"] = true;
            $product = $products->get($line['product_id']);
            if (! $product || (! $product->active && (! $old || $old->product_id != $product->id))) {
                throw ValidationException::withMessages(['items' => 'Select a product from the current shop.']);
            }
            $sameProduct = $old && $old->product_id == $product->id;
            $total = round($line['quantity'] * $line['unit_price'] - $line['discount'], 2);
            if ($total < 0) {
                throw ValidationException::withMessages(["items.{$index}.discount" => 'Discount exceeds the line amount.']);
            }
            if (! auth()->user()->canAccess('sales.apply_discount') && ($line['unit_price'] < ($sameProduct ? $old->unit_price : $product->selling_price) || $line['discount'] > ($sameProduct ? $old->discount : 0) || $data['discount'] > $invoice->discount)) {
                abort(403, 'You do not have permission to reduce selling prices or apply discounts.');
            }
            if ($sameProduct) {
                $remaining[$old->id] = (float) $line['quantity'];
            }
            $cost = $sameProduct ? (float) $old->cost_price : (float) $product->cost_price;
            $newItems[] = ['product_id' => $product->id, 'description' => $sameProduct ? $old->description : null, 'store_id' => $invoice->store_id, 'stock_batch_id' => $sameProduct ? $old->stock_batch_id : null, 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount' => $line['discount'], 'total' => $total, 'cost_price' => $cost, 'profit' => round($total - $cost * $line['quantity'], 2)];
            $parts += $total;
        }
        $customerId = $data['customer_id'] ?? null;
        $customer = $customerId ? Customer::whereKey($customerId)->first() : null;
        if ($customerId && ! $customer) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer from the current shop.']);
        }
        if ($invoice->customer_id != $customerId && $invoice->payments()->whereNotNull('cheque_id')->whereIn('status', ['Hold', 'Completed'])->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'The customer cannot change while a cheque is attached to this sale.']);
        }
        $subtotal = round($parts + $invoice->charges()->sum('amount'), 2);
        $total = round($subtotal - $data['discount'] + $data['tax'], 2);
        if ($data['discount'] > $subtotal || ! is_finite($total) || $total > 999999999999.99) {
            throw ValidationException::withMessages(['discount' => 'The discount cannot exceed the subtotal. Enter a valid sale total.']);
        }
        if ($total < (float) $invoice->hold_amount) {
            throw ValidationException::withMessages(['sale' => 'Resolve held cheques before reducing the sale below their held amount.']);
        }
        $removed = [];
        foreach ($oldItems as $item) {
            $quantity = round(max(0, (float) $item->quantity - ($remaining[$item->id] ?? 0)), 2);
            if ($quantity > 0) {
                $removed[] = ['item_id' => $item->id, 'name' => $item->description ?: ($item->product->name ?? 'Deleted product'), 'quantity' => $quantity, 'cost' => round($quantity * (float) $item->cost_price, 2)];
            }
        }
        $payments = $invoice->payments()->orderByDesc('id')->lockForUpdate()->get();
        $refundable = $payments->where('status', 'Completed')->whereIn('method', ['Cash', 'Card', 'Bank Transfer', 'QR'])->where('amount', '>', 0)->filter(fn ($payment) => ! $payment->cheque_id);
        $refund = max(0, round((float) $invoice->paid_amount + (float) $invoice->hold_amount - $total, 2));
        if ($refund > round((float) $refundable->sum('amount'), 2)) {
            throw ValidationException::withMessages(['sale' => 'Resolve cheque, salary, or other non-refundable payments first. Only cash, card, bank transfer, and QR receipts can be refunded here.']);
        }
        $dueInvoices = $customer ? Invoice::customerAccount($customer->id)->whereKeyNot($invoice->id)
            ->where('status', '!=', 'Cancelled')->where('invoice_type', '!=', 'Quotation')->where('due_amount', '>', 0)
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->get() : collect();
        $otherDue = round((float) $dueInvoices->sum('due_amount'), 2);
        $summary = [
            'removed' => $removed, 'old_total' => (float) $invoice->total, 'new_total' => $total,
            'paid_amount' => (float) $invoice->paid_amount, 'hold_amount' => (float) $invoice->hold_amount,
            'new_due' => max(0, round($total - (float) $invoice->paid_amount - (float) $invoice->hold_amount, 2)),
            'sale_due_reduction' => round(min(max(0, (float) $invoice->due_amount), max(0, (float) $invoice->total - $total)), 2),
            'refund' => $refund, 'other_due' => $otherDue, 'customer_id' => $customer?->id, 'customer_name' => $customer?->name,
            'can_expense' => auth()->user()->canAccess('expense.add'),
            'can_refund' => auth()->user()->canAccess('sales.cancel_invoice'),
            'can_offset_due' => auth()->user()->canAccess('sales.receive_payments'),
        ];
        $signature = hash_hmac('sha256', json_encode([
            $invoice->getAttributes(), $oldItems->map->getAttributes()->all(), $payments->map->getAttributes()->all(),
            $dueInvoices->map->getAttributes()->all(), $newItems, $customerId, $data['discount'], $data['tax'],
        ], JSON_THROW_ON_ERROR), config('app.key'));
        $summary['confirmation'] = $signature;

        return compact('oldItems', 'newItems', 'refundable', 'dueInvoices', 'parts', 'subtotal', 'total', 'summary');
    }

    /** Called inside the controller transaction, after the invoice has been locked. */
    public function apply(Invoice $invoice, array $data, array $plan): array
    {
        $summary = $plan['summary'];
        $confirmation = $data['revision_confirmation'] ?? '';
        if (($summary['removed'] || $summary['refund'] > 0 || $confirmation !== '') && ! hash_equals($summary['confirmation'], $confirmation)) {
            throw ValidationException::withMessages(['sale' => 'Review the stock and refund popup before saving. The sale or customer balance may have changed; review it again.']);
        }
        $dispositions = $data['dispositions'] ?? [];
        $removedIds = array_column($summary['removed'], 'item_id');
        foreach ($dispositions as $id => $choice) {
            if (! in_array((int) $id, $removedIds, true)) {
                throw ValidationException::withMessages(['dispositions' => 'A selected stock action no longer matches the removed items. Review the changes again.']);
            }
            if ($choice === 'expense') {
                abort_unless(auth()->user()->canAccess('expense.add'), 403, 'You do not have permission to record stock expenses.');
            }
        }
        if ($summary['refund'] > 0) {
            abort_unless(auth()->user()->canAccess('sales.cancel_invoice'), 403, 'You do not have permission to refund sale payments.');
        }
        $offset = ($data['refund_action'] ?? 'refund') === 'offset_due';
        if ($offset && (! $summary['customer_id'] || $summary['refund'] <= 0 || $summary['other_due'] <= 0)) {
            throw ValidationException::withMessages(['refund_action' => 'Select a customer with outstanding dues and an available refund before applying credit.']);
        }
        if ($offset) {
            abort_unless(auth()->user()->canAccess('sales.receive_payments'), 403, 'You do not have permission to apply credit to customer dues.');
        }
        $dueCredit = $offset ? min($summary['refund'], $summary['other_due']) : 0;
        $oldTotal = (float) $invoice->total;
        foreach ($plan['oldItems'] as $item) {
            $this->stock->adjustStock($item->store_id, $item->product_id, (float) $item->quantity, 'sale_edit_reverse', $invoice, $data['reason'], $item->stock_batch_id, (float) $item->cost_price, (float) $item->unit_price);
        }
        $invoice->items()->delete();
        foreach ($plan['newItems'] as $line) {
            $invoice->items()->create($line);
            $this->stock->adjustStock($invoice->store_id, $line['product_id'], -(float) $line['quantity'], 'sale_edit', $invoice, $data['reason'], $line['stock_batch_id'], $line['cost_price'], (float) $line['unit_price']);
        }
        $removed = $summary['removed'];
        foreach ($removed as &$line) {
            $line['disposition'] = $dispositions[$line['item_id']] ?? 'restock';
            if ($line['disposition'] !== 'expense') {
                continue;
            }
            $item = $plan['oldItems'][$line['item_id']];
            $this->stock->adjustStock($item->store_id, $item->product_id, -$line['quantity'], 'sale_edit_writeoff', $invoice, $data['reason'], $item->stock_batch_id, (float) $item->cost_price, (float) $item->unit_price);
            // Inventory cost is written off; this is not another cash/bank payment.
            FinancialCategory::firstOrCreate(['module' => 'expense', 'name' => 'Sale Stock Write-off'], ['active' => true]);
            $expense = Expense::create([
                'number' => 'EXP-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'expense_date' => today(),
                'category' => 'Sale Stock Write-off', 'account_id' => Account::systemAccount('spare_parts')->id,
                'amount' => $line['cost'], 'payment_method' => 'Non-cash', 'reference_number' => $invoice->number,
                'description' => "{$line['name']} × {$line['quantity']} removed from {$invoice->number}; not restocked",
                'note' => $data['reason'], 'created_by' => auth()->id(),
            ]);
            $line['expense_id'] = $expense->id;
        }
        unset($line);
        $refundRows = [];
        $remaining = $summary['refund'];
        foreach ($plan['refundable'] as $payment) {
            if ($remaining <= 0) {
                break;
            }
            $amount = min($remaining, (float) $payment->amount);
            AccountTransaction::where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)
                ->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()
                ->each(fn ($entry) => $this->accounts->reverseTransaction($entry, 'Sale edit: '.$data['reason']));
            $payment->update(['amount' => round((float) $payment->amount - $amount, 2), 'refunded_amount' => round((float) $payment->refunded_amount + $amount, 2)]);
            $refundRows[] = ['payment_id' => $payment->id, 'number' => $payment->number, 'method' => $payment->method, 'account_id' => $payment->account_id, 'amount' => $amount];
            $remaining = round($remaining - $amount, 2);
        }
        $invoice->update([
            'customer_id' => $data['customer_id'] ?? null, 'internal_note' => $data['internal_note'] ?? null, 'customer_note' => $data['customer_note'] ?? null,
            'parts_amount' => round($plan['parts'], 2), 'subtotal' => $plan['subtotal'], 'discount' => $data['discount'], 'tax' => $data['tax'], 'total' => $plan['total'],
            'paid_amount' => round((float) $invoice->paid_amount - $summary['refund'], 2), 'due_amount' => $summary['new_due'],
        ]);
        foreach ($refundRows as $row) {
            $payment = $invoice->payments()->findOrFail($row['payment_id']);
            if ((float) $payment->amount > 0) {
                $this->accounts->receiveInvoicePayment($invoice, $payment, settledAt: now());
            }
        }
        $allocations = [];
        $creditRemaining = $dueCredit;
        foreach ($refundRows as $row) {
            $fromPayment = min($creditRemaining, $row['amount']);
            foreach ($plan['dueInvoices'] as $target) {
                $target->refresh();
                $amount = min($fromPayment, (float) $target->due_amount);
                if ($amount <= 0) {
                    continue;
                }
                if (! $target->customer_id) {
                    $target->update(['customer_id' => $summary['customer_id']]);
                }
                $payment = app(SettlementService::class)->receive($target, [
                    'method' => $row['method'], 'amount' => $amount, 'account_id' => $row['account_id'],
                    'reference' => "sale-edit-credit:{$invoice->id}:{$row['payment_id']}", 'note' => "Credit transferred from {$invoice->number}: {$data['reason']}",
                ], now());
                $allocations[] = ['invoice_id' => $target->id, 'invoice_number' => $target->number, 'payment_id' => $payment->id, 'amount' => $amount];
                $fromPayment = round($fromPayment - $amount, 2);
                $creditRemaining = round($creditRemaining - $amount, 2);
                if ($fromPayment <= 0) {
                    break;
                }
            }
        }
        app(SettlementService::class)->saveStatus($invoice);
        $this->accounts->reallocateInvoiceReceipts($invoice);
        $result = ['reason' => $data['reason'], 'old_total' => $oldTotal, 'new_total' => (float) $invoice->total,
            'removed' => $removed, 'refund' => $summary['refund'], 'customer_refund' => round($summary['refund'] - $dueCredit, 2),
            'due_credit' => $dueCredit, 'due_allocations' => $allocations, 'refunds' => $refundRows];
        AuditLog::create(['user_id' => auth()->id(), 'event' => 'sale_edited', 'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id,
            'old_values' => ['total' => $oldTotal, 'items' => $plan['oldItems']->map->getAttributes()->values()->all()], 'new_values' => $result,
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);

        return $result;
    }
}
