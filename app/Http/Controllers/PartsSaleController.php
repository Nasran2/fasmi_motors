<?php

namespace App\Http\Controllers;

use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Services\AccountService;
use App\Services\SettlementService;
use App\Services\WorkshopService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PartsSaleController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|string|max:40', 'from' => 'nullable|date', 'to' => 'nullable|date']);
        $sales = Invoice::where('invoice_type', 'Parts Sale')->with(['customer', 'store'])->withCount('returns')
            ->when($request->status === 'Deleted', fn ($q) => $q->whereNotNull('parts_deleted_at'), fn ($q) => $q->whereNull('parts_deleted_at'))
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))
            ->when(($filters['status'] ?? null) && $request->status !== 'Deleted', fn ($q) => $q->where('status', $request->status))
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->latest()->paginate(20)->withQueryString();

        return view('sales.parts-list', compact('sales'));
    }

    public function edit(Invoice $invoice)
    {
        $this->editable($invoice);

        return view('sales.parts-edit', ['invoice' => $invoice->load(['items.product', 'charges', 'store']), 'customers' => Customer::where('active', true)->orderBy('name')->get(), 'products' => Product::where(fn ($query) => $query->where('active', true)->orWhereIn('id', $invoice->items()->pluck('product_id')))->orderBy('name')->get(['id', 'name', 'selling_price'])]);
    }

    private function editable(Invoice $invoice): void
    {
        abort_unless($invoice->invoice_type === 'Parts Sale', 404);
        if ($invoice->status === 'Cancelled' || $invoice->parts_deleted_at || $invoice->returns()->exists()) {
            throw ValidationException::withMessages(['sale' => 'Deleted, cancelled, or returned sales cannot be edited.']);
        }
        if ($invoice->payments()->where('method', 'Salary Deduction')->where('status', 'Completed')->exists()) {
            throw ValidationException::withMessages(['sale' => 'Reopen the staff salary recovery before editing this sale.']);
        }
    }

    public function update(Request $request, Invoice $invoice, WorkshopService $stock, AccountService $accounts)
    {
        abort_unless($invoice->invoice_type === 'Parts Sale', 404);
        $data = $request->validate([
            'customer_id' => 'nullable|exists:customers,id', 'internal_note' => 'nullable|string|max:2000', 'customer_note' => 'nullable|string|max:2000',
            'discount' => 'required|numeric|decimal:0,2|min:0|max:99999999.99', 'tax' => 'required|numeric|decimal:0,2|min:0|max:99999999.99', 'reason' => 'required|string|min:5|max:500',
            'items' => 'nullable|array|max:100', 'items.*.product_id' => 'required|integer|exists:products,id',
            'items.*.id' => 'nullable|integer', 'items.*.quantity' => 'required|numeric|decimal:0,2|min:0.01',
            'items.*.unit_price' => 'required|numeric|decimal:0,2|min:0', 'items.*.discount' => 'required|numeric|decimal:0,2|min:0',
        ]);
        DB::transaction(function () use ($request, $invoice, $data, $stock, $accounts) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            $this->editable($invoice);
            $data['items'] ??= [];
            if (! $data['items'] && ! $invoice->charges()->exists()) {
                throw ValidationException::withMessages(['items' => 'Add at least one product.']);
            }
            $oldItems = $invoice->items()->lockForUpdate()->get()->keyBy('id');
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
            $newItems = [];
            $parts = 0;
            $seen = [];
            foreach ($data['items'] as $index => $line) {
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
                if (! $request->user()->canAccess('sales.apply_discount') && ($line['unit_price'] < ($sameProduct ? $old->unit_price : $product->selling_price) || $line['discount'] > ($sameProduct ? $old->discount : 0) || $data['discount'] > $invoice->discount)) {
                    abort(403, 'You do not have permission to reduce selling prices or apply discounts.');
                }
                $cost = $sameProduct ? (float) $old->cost_price : (float) $product->cost_price;
                $newItems[] = ['product_id' => $product->id, 'description' => $sameProduct ? $old->description : null, 'store_id' => $invoice->store_id, 'stock_batch_id' => $sameProduct ? $old->stock_batch_id : null, 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'discount' => $line['discount'], 'total' => $total, 'cost_price' => $cost, 'profit' => round($total - $cost * $line['quantity'], 2)];
                $parts += $total;
            }
            if (! empty($data['customer_id']) && ! Customer::whereKey($data['customer_id'])->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'Select a customer from the current shop.']);
            }
            $subtotal = round($parts + $invoice->charges()->sum('amount'), 2);
            $total = round($subtotal - $data['discount'] + $data['tax'], 2);
            if ($data['discount'] > $subtotal || $total < (float) $invoice->paid_amount + (float) $invoice->hold_amount) {
                throw ValidationException::withMessages(['discount' => 'The new total must cover existing paid and held amounts. Use Return to refund sold parts.']);
            }
            foreach ($oldItems as $item) {
                $stock->adjustStock($item->store_id, $item->product_id, (float) $item->quantity, 'sale_edit_reverse', $invoice, $data['reason'], $item->stock_batch_id, (float) $item->cost_price, (float) $item->unit_price);
            }
            $invoice->items()->delete();
            foreach ($newItems as $line) {
                $invoice->items()->create($line);
                $stock->adjustStock($invoice->store_id, $line['product_id'], -(float) $line['quantity'], 'sale_edit', $invoice, $data['reason'], $line['stock_batch_id'], $line['cost_price'], (float) $line['unit_price']);
            }
            if ($invoice->customer_id != ($data['customer_id'] ?? null) && $invoice->payments()->whereNotNull('cheque_id')->whereIn('status', ['Hold', 'Completed'])->exists()) {
                throw ValidationException::withMessages(['customer_id' => 'The customer cannot change while a cheque is attached to this sale.']);
            }
            $invoice->update(['customer_id' => $data['customer_id'] ?? null, 'internal_note' => $data['internal_note'] ?? null, 'customer_note' => $data['customer_note'] ?? null, 'parts_amount' => round($parts, 2), 'subtotal' => $subtotal, 'discount' => $data['discount'], 'tax' => $data['tax'], 'total' => $total, 'due_amount' => round($total - $invoice->paid_amount - $invoice->hold_amount, 2)]);
            app(SettlementService::class)->saveStatus($invoice);
            $accounts->reallocateInvoiceReceipts($invoice);
            $this->audit($invoice, 'sale_edited', ['reason' => $data['reason']]);
        });

        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Parts sale updated. Stock, totals, and payment allocations have been updated.');
    }

    public function returnForm(Invoice $invoice)
    {
        abort_unless($invoice->invoice_type === 'Parts Sale', 404);
        abort_if($invoice->status === 'Cancelled', 422, 'This sale has been cancelled.');

        return view('sales.parts-return', ['invoice' => $invoice->load(['items.product', 'returns'])]);
    }

    public function returnStore(Request $request, Invoice $invoice, WorkshopService $stock, AccountService $accounts)
    {
        abort_unless($invoice->invoice_type === 'Parts Sale', 404);
        $data = $request->validate(['reason' => 'required|string|min:5|max:500', 'quantities' => 'required|array|max:100', 'quantities.*' => 'nullable|numeric|decimal:0,2|min:0']);
        DB::transaction(function () use ($invoice, $data, $stock, $accounts) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'Cancelled') {
                throw ValidationException::withMessages(['sale' => 'This sale has been cancelled.']);
            }
            $items = $invoice->items()->lockForUpdate()->get()->keyBy('id');
            $returned = [];
            foreach ($data['quantities'] as $id => $quantity) {
                $quantity = (float) $quantity;
                if ($quantity <= 0) {
                    continue;
                }
                $item = $items->get($id);
                if (! $item || $quantity > round((float) $item->quantity - (float) $item->returned_quantity, 2)) {
                    throw ValidationException::withMessages(['quantities' => 'Return quantities cannot exceed the remaining quantity on this sale.']);
                }
                $amount = round((float) $item->total * ((float) $item->returned_quantity + $quantity) / (float) $item->quantity, 2) - round((float) $item->total * (float) $item->returned_quantity / (float) $item->quantity, 2);
                $returned[] = ['item_id' => $item->id, 'name' => $item->description ?: $item->product?->name, 'quantity' => $quantity, 'line_amount' => $amount];
            }
            if (! $returned) {
                throw ValidationException::withMessages(['quantities' => 'Enter at least one return quantity.']);
            }
            $firstReturn = $invoice->returns()->reorder()->oldest('id')->first();
            $original = $firstReturn?->items[0]['invoice_totals'] ?? ['subtotal' => (float) $invoice->subtotal, 'discount' => (float) $invoice->discount, 'tax' => (float) $invoice->tax];
            foreach ($returned as &$line) {
                $line['invoice_totals'] = $original;
            }
            unset($line);
            $quantities = collect($returned)->keyBy('item_id');
            $remainingParts = round((float) $items->sum(fn ($item) => (float) $item->total - round((float) $item->total * ((float) $item->returned_quantity + (float) ($quantities->get($item->id)['quantity'] ?? 0)) / (float) $item->quantity, 2)), 2);
            $newSubtotal = round($remainingParts + (float) $invoice->charges()->sum('amount'), 2);
            $discount = $original['subtotal'] > 0 ? round($original['discount'] * $newSubtotal / $original['subtotal'], 2) : 0;
            $tax = $original['subtotal'] > 0 ? round($original['tax'] * $newSubtotal / $original['subtotal'], 2) : 0;
            $newTotal = max(0, round($newSubtotal - $discount + $tax, 2));
            $credit = round((float) $invoice->total - $newTotal, 2);
            if ($newTotal < (float) $invoice->hold_amount) {
                throw ValidationException::withMessages(['quantities' => 'Resolve the held cheques before returning this amount.']);
            }
            $refund = max(0, round((float) $invoice->paid_amount + (float) $invoice->hold_amount - $newTotal, 2));
            $payments = $invoice->payments()->where('status', 'Completed')->where('amount', '>', 0)->orderByDesc('id')->lockForUpdate()->get();
            $refundable = $payments->whereIn('method', ['Cash', 'Card', 'Bank Transfer', 'QR']);
            if ($refund > round((float) $refundable->sum('amount'), 2)) {
                throw ValidationException::withMessages(['quantities' => 'Resolve the cheque or salary payment first. Only cash, card, bank, and QR payments can be refunded here.']);
            }
            $refundRows = [];
            $remainingRefund = $refund;
            foreach ($refundable as $payment) {
                if ($remainingRefund <= 0) {
                    break;
                }
                $amount = min($remainingRefund, (float) $payment->amount);
                AccountTransaction::where('reference_type', $payment->getMorphClass())->where('reference_id', $payment->id)->where('status', 'active')->where('transaction_type', '!=', 'reversal')->get()->each(fn ($entry) => $accounts->reverseTransaction($entry, 'Parts return: '.$data['reason']));
                $payment->update(['amount' => round((float) $payment->amount - $amount, 2), 'refunded_amount' => round((float) $payment->refunded_amount + $amount, 2)]);
                $refundRows[] = ['payment_id' => $payment->id, 'number' => $payment->number, 'method' => $payment->method, 'amount' => $amount];
                $remainingRefund = round($remainingRefund - $amount, 2);
            }
            $record = $invoice->returns()->create(['amount' => $credit, 'refund_amount' => $refund, 'items' => $returned, 'refunds' => $refundRows, 'reason' => $data['reason'], 'created_by' => auth()->id()]);
            foreach ($returned as $line) {
                $item = $items[$line['item_id']];
                $item->increment('returned_quantity', $line['quantity']);
                $stock->adjustStock($item->store_id, $item->product_id, $line['quantity'], 'sale_return', $record, $data['reason'], $item->stock_batch_id, (float) $item->cost_price, (float) $item->unit_price);
            }
            $invoice->update(['parts_amount' => $remainingParts, 'subtotal' => $newSubtotal, 'discount' => $discount, 'tax' => $tax, 'total' => $newTotal, 'return_amount' => round((float) $invoice->return_amount + $credit, 2), 'paid_amount' => round((float) $invoice->paid_amount - $refund, 2), 'due_amount' => max(0, round($newTotal - ((float) $invoice->paid_amount - $refund) - (float) $invoice->hold_amount, 2))]);
            foreach ($refundRows as $row) {
                $payment = $invoice->payments()->findOrFail($row['payment_id']);
                if ($payment->amount > 0) {
                    $accounts->receiveInvoicePayment($invoice, $payment, settledAt: now());
                }
            }
            $accounts->reallocateInvoiceReceipts($invoice);
            app(SettlementService::class)->saveStatus($invoice);
            $this->audit($invoice, 'sale_returned', ['return_id' => $record->id, 'credit' => $credit, 'refund' => $refund, 'reason' => $data['reason']]);
        });

        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Return recorded and stock restored. Any refund is recorded against the original payment methods.');
    }

    public function destroy(Request $request, Invoice $invoice, WorkshopService $stock)
    {
        abort_unless($invoice->invoice_type === 'Parts Sale', 404);
        $request->validate(['reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($request, $invoice, $stock) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status !== 'Cancelled') {
                app(SalesBillingController::class)->cancelInvoice($request, $invoice, $stock);
                if ($invoice->fresh()->status !== 'Cancelled') {
                    throw ValidationException::withMessages(['reason' => 'This sale could not be deleted.']);
                }
            }
            $invoice->update(['parts_deleted_at' => now()]);
            $this->audit($invoice, 'sale_deleted', ['reason' => $request->reason]);
        });

        return redirect()->route('sales.parts.index')->with('success', 'Parts sale deleted with stock and payment reversals. Its history remains in the Deleted filter.');
    }

    private function audit(Invoice $invoice, string $event, array $values): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'event' => $event, 'auditable_type' => $invoice->getMorphClass(), 'auditable_id' => $invoice->id, 'old_values' => [], 'new_values' => $values, 'ip_address' => request()->ip(), 'user_agent' => request()->userAgent()]);
    }
}
