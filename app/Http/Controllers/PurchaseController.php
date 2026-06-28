<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Services\WorkshopService;
use App\Services\AccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::with(['supplier', 'store'])->withCount('items')->where('status', 'Completed')
            ->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhere('invoice_number', 'like', "%{$term}%")->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$term}%"))->orWhereHas('items.product', fn (Builder $p) => $p->where('name', 'like', "%{$term}%"))))
            ->when($request->supplier_id, fn (Builder $q, string $id) => $q->where('supplier_id', $id))
            ->when($request->store_id, fn (Builder $q, string $id) => $q->where('store_id', $id))
            ->when($request->payment_status, fn (Builder $q, string $status) => $q->where('payment_status', $status))
            ->when($request->from, fn (Builder $q, string $date) => $q->whereDate('purchase_date', '>=', $date))
            ->when($request->to, fn (Builder $q, string $date) => $q->whereDate('purchase_date', '<=', $date));

        return view('purchases.index', [
            'purchases' => $query->latest('purchase_date')->paginate(15)->withQueryString(),
            'suppliers' => Supplier::where('active', true)->orderBy('name')->get(),
            'stores' => Store::where('active', true)->orderBy('name')->get(),
            'stats' => [
                'total' => Purchase::where('status', 'Completed')->sum('total_amount'),
                'month' => Purchase::where('status', 'Completed')->whereBetween('purchase_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_amount'),
                'paid' => Purchase::where('status', 'Completed')->sum('paid_amount'),
                'due' => Purchase::where('status', 'Completed')->sum('due_amount'),
                'returns' => PurchaseReturn::where('status', 'Approved')->sum('return_value'),
            ],
        ]);
    }

    public function create()
    {
        return view('purchases.create', $this->formData());
    }

    public function store(Request $request, WorkshopService $service)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'exists:suppliers,id'], 'invoice_number' => ['required', 'max:100', Rule::unique('purchases', 'invoice_number')->where('supplier_id', $request->input('supplier_id'))],
            'purchase_date' => ['required', 'date'], 'due_date' => ['nullable', 'date', 'after_or_equal:purchase_date'],
            'store_id' => ['required', 'exists:stores,id'], 'payment_method' => ['nullable', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Card'])],
            'account_id' => ['nullable', 'exists:accounts,id'], 'paid_amount' => ['required', 'numeric', 'min:0'],
            'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'other_cost' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'max:1000'], 'update_prices' => ['nullable', 'boolean'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'distinct', 'exists:products,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.cost_price' => ['required', 'numeric', 'min:0'], 'items.*.selling_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ]);
        if ((float) $data['paid_amount'] > 0 && (empty($data['account_id']) || empty($data['payment_method']))) {
            throw ValidationException::withMessages(['paid_amount' => 'Select a payment method and paid-from account.']);
        }

        $purchase = DB::transaction(function () use ($data, $request, $service) {
            $products = Product::whereIn('id', collect($data['items'])->pluck('product_id'))->lockForUpdate()->get()->keyBy('id');
            $subtotal = collect($data['items'])->sum(fn (array $item) => (float) $item['quantity'] * (float) $item['cost_price'] - (float) ($item['discount'] ?? 0));
            $total = max(0, $subtotal - (float) ($data['discount'] ?? 0) + (float) ($data['tax'] ?? 0) + (float) ($data['other_cost'] ?? 0));
            $paid = (float) $data['paid_amount'];
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed grand total.']);
            }
            $purchase = Purchase::create([
                'number' => 'PUR-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'invoice_number' => $data['invoice_number'],
                'supplier_id' => $data['supplier_id'], 'store_id' => $data['store_id'], 'purchase_date' => $data['purchase_date'],
                'due_date' => $paid < $total ? ($data['due_date'] ?? now()->addDays(30)->toDateString()) : null,
                'subtotal' => $subtotal, 'discount' => $data['discount'] ?? 0, 'tax' => $data['tax'] ?? 0, 'other_cost' => $data['other_cost'] ?? 0,
                'total_amount' => $total, 'paid_amount' => $paid, 'due_amount' => $total - $paid,
                'payment_status' => $paid >= $total ? 'Paid' : ($paid > 0 ? 'Partial' : 'Due'),
                'payment_method' => $data['payment_method'] ?? null, 'note' => $data['note'] ?? null, 'created_by' => auth()->id(),
            ]);
            foreach ($data['items'] as $item) {
                $lineTotal = (float) $item['quantity'] * (float) $item['cost_price'] - (float) ($item['discount'] ?? 0);

                $batch = StockBatch::firstOrCreate([
                    'product_id' => $item['product_id'],
                    'store_id' => $data['store_id'],
                    'cost_price' => $item['cost_price'],
                    'selling_price' => $item['selling_price'],
                    'status' => 'active',
                ], [
                    'supplier_id' => $data['supplier_id'],
                    'purchase_id' => $purchase->id,
                    'batch_no' => 'B-'.now()->format('ymd').'-'.Str::upper(Str::random(6)),
                    'purchased_qty' => 0,
                    'available_qty' => 0,
                    'purchase_date' => $data['purchase_date'],
                    'created_by' => auth()->id(),
                ]);

                $batch->increment('purchased_qty', (float) $item['quantity']);

                $purchaseItem = $purchase->items()->create(['product_id' => $item['product_id'], 'stock_batch_id' => $batch->id, 'quantity' => $item['quantity'], 'cost_price' => $item['cost_price'], 'selling_price' => $item['selling_price'], 'discount' => $item['discount'] ?? 0, 'line_total' => $lineTotal]);
                if ($request->boolean('update_prices')) {
                    $products[$item['product_id']]->update(['cost_price' => $item['cost_price'], 'selling_price' => $item['selling_price']]);
                }
                $service->adjustStock((int) $data['store_id'], (int) $item['product_id'], (float) $item['quantity'], 'purchase', $purchase, "Purchase {$purchase->number}", $batch->id, $item['cost_price'], $item['selling_price']);

            }
            if ($paid > 0) {
                $account = Account::findOrFail($data['account_id']);
                $payment = SupplierPayment::create(['number' => 'SPY-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'supplier_id' => $data['supplier_id'], 'purchase_id' => $purchase->id, 'account_id' => $account->id, 'payment_date' => $data['purchase_date'], 'amount' => $paid, 'balance_after' => Supplier::findOrFail($data['supplier_id'])->balance, 'payment_method' => $data['payment_method'], 'status' => 'Completed', 'note' => 'Initial purchase payment', 'created_by' => auth()->id()]);
                
                $accountService = app(AccountService::class);
                if (in_array($data['payment_method'], ['Cash'])) {
                    $accountService->recordTransaction('cash', 'expense', $paid, "Payment for purchase {$purchase->number}", $payment, $data['payment_method'], null, $account->id);
                } else {
                    $accountService->recordTransaction('bank', 'expense', $paid, "Payment for purchase {$purchase->number}", $payment, $data['payment_method'], null, $account->id);
                }
            }

            return $purchase;
        });

        if ($request->input('action') === 'new') {
            return redirect()->route('purchases.create')->with('success', "{$purchase->number} saved. Add another purchase.");
        }
        if ($request->input('action') === 'print') {
            return redirect()->route('purchases.show', $purchase)->with('print', true)->with('success', 'Purchase saved and stock updated.');
        }

        return redirect()->route('purchases.show', $purchase)->with('success', 'Purchase saved and stock updated.');
    }

    public function show(Purchase $purchase)
    {
        return view('purchases.show', ['purchase' => $purchase->load(['supplier', 'store', 'items.product', 'payments.account', 'returns.item.product'])]);
    }

    public function cancel(Request $request, Purchase $purchase, WorkshopService $service)
    {
        $data = $request->validate(['reason' => ['required', 'min:5', 'max:500']]);
        if ($purchase->status !== 'Completed') {
            return back()->with('error', 'This purchase is already cancelled.');
        }
        DB::transaction(function () use ($purchase, $data, $service) {
            $purchase->load(['items', 'payments.account', 'returns']);
            foreach ($purchase->items as $item) {
                $remaining = (float) $item->quantity - (float) $item->returned_quantity;
                if ($remaining > 0) {
                    $service->adjustStock($purchase->store_id, $item->product_id, -$remaining, 'purchase_cancel', $purchase, $data['reason'], $item->stock_batch_id, $item->cost_price, $item->selling_price);
                }
            }
            $cashRefunds = (float) $purchase->returns->where('status', 'Approved')->where('refund_type', 'Cash refund')->sum('return_value');
            $accountService = app(AccountService::class);
            foreach ($purchase->payments->where('status', 'Completed') as $payment) {
                $reversal = min((float) $payment->amount, max(0, (float) $payment->amount - $cashRefunds));
                if ($reversal > 0) {
                    if (in_array($payment->payment_method, ['Cash'])) {
                        $accountService->recordTransaction('cash', 'income', $reversal, "Reversal of {$purchase->number}", $purchase, $payment->payment_method, null, $payment->account_id);
                    } else {
                        $accountService->recordTransaction('bank', 'income', $reversal, "Reversal of {$purchase->number}", $purchase, $payment->payment_method, null, $payment->account_id);
                    }
                }
                $cashRefunds = max(0, $cashRefunds - (float) $payment->amount);
                $payment->update(['status' => 'Cancelled', 'cancel_reason' => $data['reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
            }
            $purchase->update(['status' => 'Cancelled', 'due_amount' => 0, 'payment_status' => 'Cancelled', 'cancel_reason' => $data['reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        });

        return redirect()->route('purchases.index')->with('success', 'Purchase cancelled with stock and ledger reversal.');
    }

    public function returns(Request $request)
    {
        $returns = PurchaseReturn::with(['purchase', 'supplier', 'store', 'item.product'])
            ->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('purchase', fn (Builder $p) => $p->where('invoice_number', 'like', "%{$term}%"))->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$term}%"))))
            ->when($request->status, fn (Builder $q, string $status) => $q->where('status', $status))->latest('return_date')->paginate(15)->withQueryString();
        $purchases = Purchase::with(['supplier', 'store', 'items.product'])->where('status', 'Completed')->latest()->get();

        return view('purchases.returns', [
            'returns' => $returns,
            'purchases' => $purchases,
            'accounts' => Account::where('active', true)->get(),
            'purchaseData' => $purchases->map(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'supplier' => $purchase->supplier->name,
                'store' => $purchase->store->name,
                'items' => $purchase->items->map(fn (PurchaseItem $item) => [
                    'id' => $item->id,
                    'label' => $item->product->name.' — available '.number_format((float) $item->quantity - (float) $item->returned_quantity, 2),
                ])->values(),
            ])->values(),
        ]);
    }

    public function returnStore(Request $request)
    {
        $data = $request->validate(['purchase_id' => ['required', 'exists:purchases,id'], 'purchase_item_id' => ['required', 'exists:purchase_items,id'], 'quantity' => ['required', 'numeric', 'gt:0'], 'refund_type' => ['required', Rule::in(['Cash refund', 'Supplier credit', 'Replacement'])], 'account_id' => ['nullable', 'exists:accounts,id'], 'return_date' => ['required', 'date'], 'reason' => ['required', 'max:255'], 'notes' => ['nullable', 'max:1000']]);
        $item = PurchaseItem::with('purchase')->findOrFail($data['purchase_item_id']);
        if ($item->purchase_id !== (int) $data['purchase_id']) {
            throw ValidationException::withMessages(['purchase_item_id' => 'The selected product does not belong to this purchase.']);
        }
        if ((float) $data['quantity'] > (float) $item->quantity - (float) $item->returned_quantity) {
            throw ValidationException::withMessages(['quantity' => 'Return quantity exceeds the available purchased quantity.']);
        }
        if ($data['refund_type'] === 'Cash refund' && empty($data['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Select the account receiving the supplier refund.']);
        }
        PurchaseReturn::create(['number' => 'PRN-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'purchase_id' => $item->purchase_id, 'purchase_item_id' => $item->id, 'supplier_id' => $item->purchase->supplier_id, 'store_id' => $item->purchase->store_id, 'quantity' => $data['quantity'], 'return_value' => (float) $data['quantity'] * (float) $item->cost_price, 'refund_type' => $data['refund_type'], 'return_date' => $data['return_date'], 'reason' => $data['reason'], 'notes' => $data['notes'] ?? null, 'account_id' => $data['account_id'] ?? null, 'created_by' => auth()->id()]);

        return back()->with('success', 'Purchase return created and is awaiting approval.');
    }

    public function approveReturn(PurchaseReturn $return, WorkshopService $service)
    {
        if ($return->status !== 'Pending') {
            return back()->with('error', 'This return is already processed.');
        }
        DB::transaction(function () use ($return, $service) {
            $return->load(['item', 'purchase']);
            $item = PurchaseItem::lockForUpdate()->findOrFail($return->purchase_item_id);
            if ((float) $return->quantity > (float) $item->quantity - (float) $item->returned_quantity) {
                throw ValidationException::withMessages(['quantity' => 'Available return quantity has changed.']);
            }
            $service->adjustStock($return->store_id, $item->product_id, -(float) $return->quantity, 'purchase_return', $return, $return->reason, $item->stock_batch_id, $item->cost_price, $item->selling_price);
            $item->increment('returned_quantity', (float) $return->quantity);
            $purchase = Purchase::lockForUpdate()->findOrFail($return->purchase_id);
            if ($return->refund_type !== 'Replacement') {
                $purchase->increment('returned_amount', (float) $return->return_value);
                $purchase->due_amount = max(0, (float) $purchase->total_amount - (float) $purchase->returned_amount - (float) $purchase->paid_amount);
                $purchase->payment_status = $purchase->due_amount <= 0 ? 'Paid' : ((float) $purchase->paid_amount > 0 ? 'Partial' : 'Due');
                $purchase->save();
            }
            if ($return->refund_type === 'Cash refund') {
                $accountService = app(AccountService::class);
                // Currently refund to Cash or Bank depending on method, default to Cash since we don't have payment method for return directly. We'll assume Cash or let the user see it in cash book.
                // Assuming it's cash based on "Cash refund"
                $accountService->recordTransaction('cash', 'income', (float) $return->return_value, "Supplier refund {$return->number}", $return, 'Cash', null, $return->account_id);
            }
            $return->update(['status' => 'Approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);
        });

        return back()->with('success', 'Return approved; stock, supplier balance and ledger updated.');
    }

    public function suppliers(Request $request)
    {
        $suppliers = Supplier::withCount('purchases')->withSum(['purchases as purchase_total' => fn (Builder $q) => $q->where('status', 'Completed')], 'total_amount')->withSum(['purchases as paid_total' => fn (Builder $q) => $q->where('status', 'Completed')], 'paid_amount')->withSum(['purchases as due_total' => fn (Builder $q) => $q->where('status', 'Completed')], 'due_amount')->withMax('purchases as last_purchase', 'purchase_date')
            ->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('name', 'like', "%{$term}%")->orWhere('company_name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%")))
            ->when($request->status !== null && $request->status !== '', fn (Builder $q) => $q->where('active', $request->status === 'active'))
            ->when($request->due === 'yes', fn (Builder $q) => $q->whereHas('purchases', fn (Builder $p) => $p->where('due_amount', '>', 0)))->latest()->paginate(15)->withQueryString();

        return view('purchases.suppliers', ['suppliers' => $suppliers, 'stats' => ['total' => Supplier::count(), 'active' => Supplier::where('active', true)->count(), 'due_count' => Purchase::where('status', 'Completed')->where('due_amount', '>', 0)->distinct('supplier_id')->count('supplier_id'), 'due' => Purchase::where('status', 'Completed')->sum('due_amount') + Supplier::sum(DB::raw('opening_due-opening_advance'))]]);
    }

    public function supplierCreate()
    {
        return view('purchases.supplier-create');
    }

    public function supplierStore(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'max:150'], 'company_name' => ['nullable', 'max:150'], 'phone' => ['required', 'max:30', 'unique:suppliers,phone'], 'alternative_phone' => ['nullable', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'address' => ['nullable', 'max:1000'], 'contact_person' => ['nullable', 'max:150'], 'supplier_type' => ['required', Rule::in(['Spare Parts Supplier', 'Oil Supplier', 'Accessories Supplier', 'Service Supplier'])], 'active' => ['required', 'boolean'], 'opening_due' => ['nullable', 'numeric', 'min:0'], 'opening_advance' => ['nullable', 'numeric', 'min:0'], 'opening_balance_date' => ['nullable', 'date'], 'opening_note' => ['nullable', 'max:1000']]);
        $supplier = Supplier::create($data);

        return $request->input('action') === 'purchase' ? redirect()->route('purchases.create', ['supplier' => $supplier->id])->with('success', 'Supplier saved. Create the first purchase.') : redirect()->route('purchases.suppliers.show', $supplier)->with('success', 'Supplier profile created.');
    }

    public function supplierShow(Supplier $supplier)
    {
        return view('purchases.supplier-show', ['supplier' => $supplier->load(['purchases' => fn ($q) => $q->latest('purchase_date'), 'payments' => fn ($q) => $q->with('account')->latest('payment_date'), 'returns' => fn ($q) => $q->with('item.product')->latest('return_date')])]);
    }

    public function supplierDeactivate(Request $request, Supplier $supplier)
    {
        $request->validate(['reason' => ['required', 'min:5', 'max:500']]);
        $supplier->update(['active' => false]);

        return back()->with('success', 'Supplier deactivated; transaction history was preserved.');
    }

    public function payments(Request $request)
    {
        $payments = SupplierPayment::with(['supplier', 'purchase', 'account'])->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$term}%"))->orWhereHas('purchase', fn (Builder $p) => $p->where('invoice_number', 'like', "%{$term}%"))))->when($request->method, fn (Builder $q, string $method) => $q->where('payment_method', $method))->when($request->account_id, fn (Builder $q, string $id) => $q->where('account_id', $id))->latest('payment_date')->paginate(15)->withQueryString();

        return view('purchases.payments', ['payments' => $payments, 'suppliers' => Supplier::where('active', true)->orderBy('name')->get(), 'purchases' => Purchase::where('status', 'Completed')->where('due_amount', '>', 0)->with('supplier')->orderBy('due_date')->get(), 'accounts' => Account::where('active', true)->get(), 'stats' => ['today' => SupplierPayment::where('status', 'Completed')->whereDate('payment_date', today())->sum('amount'), 'month' => SupplierPayment::where('status', 'Completed')->whereBetween('payment_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'), 'due' => Purchase::where('status', 'Completed')->sum('due_amount'), 'cheques' => SupplierPayment::where('status', 'Completed')->where('payment_method', 'Cheque')->count()]]);
    }

    public function paymentStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate(['supplier_id' => ['required', 'exists:suppliers,id'], 'purchase_id' => ['nullable', 'exists:purchases,id'], 'account_id' => ['required', 'exists:accounts,id'], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'payment_method' => ['required', Rule::in(['Cash', 'Bank Transfer', 'Cheque', 'Card'])], 'reference' => ['nullable', 'max:150'], 'note' => ['nullable', 'max:1000']]);
        DB::transaction(function () use ($data, $service) {
            $supplier = Supplier::lockForUpdate()->findOrFail($data['supplier_id']);
            $amountLeft = (float) $data['amount'];
            $targets = Purchase::where('supplier_id', $supplier->id)->where('status', 'Completed')->where('due_amount', '>', 0)->when($data['purchase_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('due_date')->lockForUpdate()->get();
            if ($amountLeft > (float) $targets->sum('due_amount') + max(0, (float) $supplier->opening_due - (float) $supplier->opening_advance)) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the current supplier due.']);
            }
            foreach ($targets as $purchase) {
                $applied = min($amountLeft, (float) $purchase->due_amount);
                $purchase->increment('paid_amount', $applied);
                $purchase->decrement('due_amount', $applied);
                $purchase->refresh();
                $purchase->update(['payment_status' => (float) $purchase->due_amount <= 0 ? 'Paid' : 'Partial']);
                $amountLeft -= $applied;
                if ($amountLeft <= 0) {
                    break;
                }
            }
            if ($amountLeft > 0) {
                $supplier->update(['opening_due' => max(0, (float) $supplier->opening_due - $amountLeft)]);
            }
            $payment = SupplierPayment::create(['number' => 'SPY-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'supplier_id' => $supplier->id, 'purchase_id' => $data['purchase_id'] ?? null, 'account_id' => $data['account_id'], 'payment_date' => $data['payment_date'], 'amount' => $data['amount'], 'balance_after' => $supplier->fresh()->balance, 'payment_method' => $data['payment_method'], 'reference' => $data['reference'] ?? null, 'note' => $data['note'] ?? null, 'status' => 'Completed', 'created_by' => auth()->id()]);
            
            $accountService = app(AccountService::class);
            if (in_array($data['payment_method'], ['Cash'])) {
                $accountService->recordTransaction('cash', 'expense', (float) $data['amount'], "Supplier payment {$payment->number} to {$supplier->name}", $payment, $data['payment_method'], null, $data['account_id']);
            } else {
                $accountService->recordTransaction('bank', 'expense', (float) $data['amount'], "Supplier payment {$payment->number} to {$supplier->name}", $payment, $data['payment_method'], null, $data['account_id']);
            }
        });

        return back()->with('success', 'Supplier payment saved; due and account ledger updated.');
    }

    public function dues(Request $request)
    {
        $dues = Purchase::with('supplier')->where('status', 'Completed')->where('due_amount', '>', 0)->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('invoice_number', 'like', "%{$term}%")->orWhereHas('supplier', fn (Builder $s) => $s->where('name', 'like', "%{$term}%"))))->when($request->overdue === 'yes', fn (Builder $q) => $q->whereDate('due_date', '<', today()))->when($request->min, fn (Builder $q, string $amount) => $q->where('due_amount', '>=', $amount))->when($request->max, fn (Builder $q, string $amount) => $q->where('due_amount', '<=', $amount))->orderBy('due_date')->paginate(15)->withQueryString();

        return view('purchases.dues', ['dues' => $dues, 'topSuppliers' => Supplier::withSum(['purchases as due_total' => fn (Builder $q) => $q->where('status', 'Completed')], 'due_amount')->orderByDesc('due_total')->limit(5)->get(), 'stats' => ['total' => Purchase::where('status', 'Completed')->sum('due_amount'), 'suppliers' => Purchase::where('status', 'Completed')->where('due_amount', '>', 0)->distinct('supplier_id')->count('supplier_id'), 'overdue' => Purchase::where('status', 'Completed')->where('due_amount', '>', 0)->whereDate('due_date', '<', today())->count(), 'month' => Purchase::where('status', 'Completed')->where('due_amount', '>', 0)->whereBetween('due_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('due_amount')]]);
    }

    private function formData(): array
    {
        $products = Product::withSum('stocks', 'quantity')->with(['stockBatches' => fn ($q) => $q->where('status', 'active')])->where('active', true)->orderBy('name')->get();

        return ['suppliers' => Supplier::where('active', true)->orderBy('name')->get(), 'stores' => Store::where('active', true)->orderByDesc('is_default')->get(), 'accounts' => Account::where('active', true)->get(), 'products' => $products, 'productOptions' => $products->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->name.' — '.$p->code.($p->barcode ? ' — '.$p->barcode : ''), 'sku' => $p->code, 'barcode' => $p->barcode, 'stock' => (float) ($p->stocks_sum_quantity ?? 0), 'cost' => (float) $p->cost_price, 'selling' => (float) $p->selling_price, 'batches' => $p->stockBatches->map(fn ($b) => ['cost' => (float) $b->cost_price, 'selling' => (float) $b->selling_price, 'store_id' => $b->store_id])])->values()];
    }
}
