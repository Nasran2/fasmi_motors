<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Technician;
use App\Services\AccountService;
use App\Services\Sms\TextitSmsService;
use App\Services\WorkshopService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalesBillingController extends Controller
{
    public function jobBilling(Request $request)
    {
        $jobs = JobCard::with(['intake.bike.customer', 'technician', 'invoice'])->withSum('parts', 'total')
            ->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('intake.bike', fn (Builder $b) => $b->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('phone', 'like', "%{$term}%")))))
            ->when($request->technician_id, fn (Builder $q, string $id) => $q->where('main_technician_id', $id))
            ->when($request->status, fn (Builder $q, string $status) => $q->where('status', $status))->latest()->paginate(15)->withQueryString();

        return view('sales.job-billing', ['jobs' => $jobs, 'technicians' => Technician::where('active', true)->orderBy('name')->get(), 'accounts' => Account::where('active', true)->get(), 'selectedJob' => $request->job ? JobCard::with(['intake.bike.customer', 'technician', 'parts.product', 'services'])->find($request->job) : null, 'stats' => ['ready' => JobCard::whereIn('status', ['Repair Ready', 'Completed'])->doesntHave('invoice')->count(), 'today' => Invoice::where('invoice_type', 'Job Card Bill')->whereDate('created_at', today())->sum('total'), 'service' => Invoice::where('invoice_type', 'Job Card Bill')->whereDate('created_at', today())->sum(DB::raw('service_amount+labor_amount+washing_amount+other_amount')), 'pending' => Invoice::where('invoice_type', 'Job Card Bill')->where('due_amount', '>', 0)->count(), 'due' => Invoice::where('invoice_type', 'Job Card Bill')->sum('due_amount')]]);
    }

    public function billJob(Request $request, JobCard $job, WorkshopService $service)
    {
        $data = $request->validate(['service_total' => ['nullable', 'numeric', 'min:0'], 'labor_total' => ['nullable', 'numeric', 'min:0'], 'washing_total' => ['nullable', 'numeric', 'min:0'], 'other_total' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'paid_amount' => ['required', 'numeric', 'min:0'], 'payment_method' => ['required', Rule::in(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR', 'Credit/Due'])], 'account_id' => ['nullable', 'exists:accounts,id']]);
        if ((float) $data['paid_amount'] > 0 && empty($data['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Select the account receiving this payment.']);
        }
        $job->update(['service_total' => $data['service_total'] ?? 0, 'labor_total' => $data['labor_total'] ?? 0, 'washing_total' => $data['washing_total'] ?? 0, 'other_total' => $data['other_total'] ?? 0, 'discount' => $data['discount'] ?? 0, 'tax' => $data['tax'] ?? 0]);
        $invoice = $service->invoice($job->fresh(), (float) $data['paid_amount'], $data['payment_method'], $data['account_id'] ? (int) $data['account_id'] : null);

        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Job card invoice generated with account split and secure public link.');
    }

    public function partsSales()
    {
        $shopMode = session('shop_mode', 'workshop');
        Store::firstOrCreate(
            ['name' => $shopMode === 'helmet' ? 'Helmet Store' : 'Main Store'],
            ['is_default' => true, 'active' => true],
        );
        Account::ensureSystemAccounts($shopMode);

        $categories = ProductCategory::orderBy('name')->get();
        $products = Product::with(['stocks.store', 'stockBatches' => fn ($q) => $q->where('status', 'active')->where('available_qty', '>', 0)])->where('active', true)->orderBy('name')->get();

        return view('sales.parts-sales', ['customers' => Customer::where('active', true)->orderBy('name')->get(), 'stores' => Store::where('active', true)->orderByDesc('is_default')->get(), 'accounts' => Account::where('active', true)->get(), 'categories' => $categories, 'products' => $products, 'productOptions' => $products->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->name.' — '.$p->code.($p->barcode ? ' — '.$p->barcode : ''), 'name' => $p->name, 'sku' => $p->code, 'barcode' => $p->barcode, 'price' => (float) $p->selling_price, 'unit' => $p->unit, 'category_id' => $p->category_id, 'stocks' => $p->stocks->map(fn ($stock) => ['store_id' => $stock->store_id, 'quantity' => (float) $stock->quantity])->values(), 'batches' => $p->stockBatches->map(fn ($b) => ['id' => $b->id, 'store_id' => $b->store_id, 'batch_no' => $b->batch_no, 'cost' => (float) $b->cost_price, 'selling' => (float) $b->selling_price, 'qty' => (float) $b->available_qty, 'date' => $b->purchase_date?->format('d/m/Y') ?? 'N/A'])->values()])->values(), 'recent' => Invoice::with('customer')->where('invoice_type', 'Parts Sale')->latest()->limit(5)->get(), 'stats' => ['today' => Invoice::where('invoice_type', 'Parts Sale')->whereDate('created_at', today())->sum('total'), 'items' => InvoiceItem::whereHas('invoice', fn ($query) => $query->whereDate('created_at', today()))->sum('quantity'), 'income' => Account::systemAccount('spare_parts')?->balance ?? 0, 'due' => Invoice::where('invoice_type', 'Parts Sale')->sum('due_amount'), 'low' => Product::whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id=products.id)<=products.minimum_stock')->count()]]);
    }

    public function partsStore(Request $request, WorkshopService $service)
    {
        $v = $request->validate([
            'customer_mode' => ['nullable', Rule::in(['existing', 'new', 'walkin'])],
            'customer_id' => 'nullable|exists:customers,id',
            'customer_name' => 'nullable|string|max:255',
            'customer_phone' => 'nullable|string|max:255',
            'store_id' => 'required|exists:stores,id',
            'discount' => 'nullable|numeric|min:0',
            'tax' => 'nullable|numeric|min:0',
            'paid_amount' => 'required|numeric|min:0',
            'payment_method' => ['nullable', Rule::in(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR', 'Credit/Due'])],
            'account_id' => 'nullable|exists:accounts,id',
            'invoice_type' => 'nullable|string|in:Parts Sale,Quotation',
            'items' => 'required|array|min:1|max:100',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.stock_batch_id' => 'nullable|exists:stock_batches,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'action' => 'nullable|string',
        ]);

        $invType = $v['invoice_type'] ?? 'Parts Sale';
        $invoice = DB::transaction(function () use ($v, $invType, $service, $request) {
            $items = collect($v['items']);
            $products = Product::whereIn('id', $items->pluck('product_id')->unique())->lockForUpdate()->get()->keyBy('id');

            if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
                throw ValidationException::withMessages(['items' => 'One or more products are unavailable in the active shop.']);
            }

            Store::lockForUpdate()->findOrFail($v['store_id']);
            $usesDiscount = (float) ($v['discount'] ?? 0) > 0 || $items->contains(fn ($item) => (float) ($item['discount'] ?? 0) > 0);
            $usesLowerPrice = $items->contains(fn ($item) => (float) $item['unit_price'] < (float) $products[$item['product_id']]->selling_price);
            if (($usesDiscount || $usesLowerPrice) && ! $request->user()->canAccess('sales.apply_discount')) {
                abort(403, 'You do not have permission to apply discounts or reduce selling prices.');
            }

            foreach ($items as $index => $item) {
                if ((float) ($item['discount'] ?? 0) > (float) $item['quantity'] * (float) $item['unit_price']) {
                    throw ValidationException::withMessages(["items.{$index}.discount" => 'Item discount cannot exceed the line amount.']);
                }
            }

            $subtotal = (float) $items->sum(fn ($item) => ((float) $item['quantity'] * (float) $item['unit_price']) - (float) ($item['discount'] ?? 0));
            $total = $subtotal - (float) ($v['discount'] ?? 0) + (float) ($v['tax'] ?? 0);
            if ($total < 0) {
                throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the sale amount.']);
            }

            $paid = $invType === 'Quotation' ? 0.0 : (float) ($v['paid_amount'] ?? 0);
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the invoice total.']);
            }
            if ($invType !== 'Quotation' && empty($v['payment_method'])) {
                throw ValidationException::withMessages(['payment_method' => 'Select a payment method.']);
            }
            if ($paid > 0 && empty($v['account_id'])) {
                throw ValidationException::withMessages(['account_id' => 'Select the account receiving this payment.']);
            }
            if ($paid > 0) {
                Account::lockForUpdate()->findOrFail($v['account_id']);
            }

            $customerId = $v['customer_id'] ?? null;
            if (($v['customer_mode'] ?? '') === 'new' && ! empty($v['customer_name'])) {
                $customerId = Customer::create(['name' => $v['customer_name'], 'phone' => $v['customer_phone'] ?? null, 'active' => true])->id;
            }

            $invoice = Invoice::create([
                'number' => 'INV-'.now()->format('ymd').'-'.Str::upper(Str::random(10)),
                'invoice_type' => $invType,
                'status' => $invType === 'Quotation' ? 'Draft' : ($paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid')),
                'customer_id' => $customerId,
                'store_id' => $v['store_id'],
                'subtotal' => $subtotal,
                'discount' => $v['discount'] ?? 0,
                'tax' => $v['tax'] ?? 0,
                'parts_amount' => $total,
                'payment_method' => $v['payment_method'] ?? null,
                'total' => $total,
                'paid_amount' => $paid,
                'due_amount' => $total - $paid,
                'public_token' => Str::random(48),
                'link_expires_at' => now()->addDays(90),
                'created_by' => auth()->id(),
            ]);

            foreach ($items as $index => $item) {
                $product = $products[$item['product_id']];
                $batchId = $item['stock_batch_id'] ?? null;
                $costPrice = (float) $product->cost_price;
                if ($batchId) {
                    $batch = StockBatch::lockForUpdate()->find($batchId);
                    if (! $batch || (int) $batch->product_id !== (int) $product->id || (int) $batch->store_id !== (int) $v['store_id']) {
                        throw ValidationException::withMessages(["items.{$index}.stock_batch_id" => 'The selected stock batch does not belong to this product and store.']);
                    }
                    if ($invType !== 'Quotation' && (float) $batch->available_qty < (float) $item['quantity'] && ! filter_var(Setting::valueFor('negative_stock', false), FILTER_VALIDATE_BOOL)) {
                        throw ValidationException::withMessages(["items.{$index}.quantity" => 'Insufficient quantity in the selected stock batch.']);
                    }
                    $costPrice = (float) $batch->cost_price;
                }

                $lineTotal = ((float) $item['quantity'] * (float) $item['unit_price']) - (float) ($item['discount'] ?? 0);
                $invoice->items()->create([
                    'product_id' => $product->id,
                    'store_id' => $v['store_id'],
                    'stock_batch_id' => $batchId,
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'discount' => $item['discount'] ?? 0,
                    'total' => $lineTotal,
                    'cost_price' => $costPrice,
                    'profit' => (((float) $item['unit_price'] - $costPrice) * (float) $item['quantity']) - (float) ($item['discount'] ?? 0),
                ]);

                if ($invType !== 'Quotation') {
                    $service->adjustStock((int) $v['store_id'], (int) $product->id, -(float) $item['quantity'], 'sale', $invoice, "Parts Sale #{$invoice->number}", $batchId, $costPrice, (float) $item['unit_price']);
                }
            }

            if ($paid > 0 && $invType !== 'Quotation') {
                $payment = $invoice->payments()->create(['number' => 'RCPT-'.now()->format('ymd').'-'.Str::upper(Str::random(8)), 'amount' => $paid, 'method' => $v['payment_method'], 'paid_at' => now(), 'account_id' => $v['account_id'], 'balance_after' => $total - $paid, 'status' => 'Completed', 'created_by' => auth()->id()]);
                $accountService = app(AccountService::class);
                $accountService->receiveInvoicePayment($invoice, $payment);
            }

            return $invoice;
        });

        return response()->json([
            'success' => true,
            'invoice_id' => $invoice->id,
            'public_token' => $invoice->public_token,
            'message' => $invType === 'Quotation' ? 'Quotation saved successfully.' : 'Sale completed successfully.',
        ]);
    }

    public function invoices(Request $request)
    {
        $invoices = Invoice::with(['customer', 'bike', 'jobCard.intake.bike', 'payments'])->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('phone', 'like', "%{$term}%"))->orWhereHas('jobCard', fn (Builder $j) => $j->where('number', 'like', "%{$term}%")->orWhereHas('intake.bike', fn (Builder $b) => $b->where('number', 'like', "%{$term}%")))))
            ->when($request->invoice_type, fn (Builder $q, string $type) => $q->where('invoice_type', $type))->when($request->status, fn (Builder $q, string $status) => $q->where('status', $status))->when($request->method, fn (Builder $q, string $method) => $q->where('payment_method', $method))->when($request->from, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))->when($request->to, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))->latest()->paginate(15)->withQueryString();

        return view('sales.invoices', ['invoices' => $invoices, 'stats' => ['total' => Invoice::count(), 'today' => Invoice::whereDate('created_at', today())->count(), 'paid' => Invoice::where('status', 'Paid')->count(), 'due' => Invoice::where('due_amount', '>', 0)->count(), 'cancelled' => Invoice::where('status', 'Cancelled')->count()]]);
    }

    public function invoiceShow(Invoice $invoice)
    {
        return view('sales.invoice-show', ['invoice' => $invoice->load(['customer', 'bike', 'store', 'jobCard.intake.bike.customer', 'jobCard.technician', 'jobCard.parts.product', 'jobCard.services', 'items.product', 'payments.account'])]);
    }

    public function cancelInvoice(Request $request, Invoice $invoice, WorkshopService $service)
    {
        $data = $request->validate(['reason' => ['required', 'min:5', 'max:500']]);
        if ($invoice->status === 'Cancelled') {
            return back()->with('error', 'Invoice is already cancelled.');
        }
        DB::transaction(function () use ($invoice, $data, $service) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'Cancelled') {
                return;
            }
            if ($invoice->payments()->where('status', 'Completed')->where('method', 'Salary Deduction')->exists()) {
                throw ValidationException::withMessages(['reason' => 'Reopen the staff salary recovery before cancelling this invoice.']);
            }
            $invoice->load(['items', 'payments']);
            foreach ($invoice->payments->where('status', 'Completed') as $payment) {
                $this->reversePayment($payment, $data['reason'], $service);
            }
            if ($invoice->invoice_type === 'Parts Sale') {
                foreach ($invoice->items as $item) {
                    $service->adjustStock($item->store_id, $item->product_id, (float) $item->quantity, 'invoice_cancel', $invoice, $data['reason'], $item->stock_batch_id, (float) $item->cost_price, (float) $item->unit_price);
                }
            }
            $invoice->update(['status' => 'Cancelled', 'paid_amount' => 0, 'due_amount' => 0, 'link_enabled' => false, 'cancel_reason' => $data['reason'], 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
            $invoice->jobCard?->update(['status' => 'Completed']);
        });

        return back()->with('success', 'Invoice cancelled with stock and ledger reversals.');
    }

    public function payments(Request $request)
    {
        return view('sales.payments', $this->paymentPageData($request, false));
    }

    public function customerPayments(Request $request)
    {
        return view('sales.customer-payments', $this->paymentPageData($request, true));
    }

    public function paymentStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate(['customer_id' => ['required', 'exists:customers,id'], 'invoice_id' => ['nullable', 'exists:invoices,id'], 'amount' => ['required', 'numeric', 'gt:0'], 'method' => ['required', Rule::in(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR'])], 'account_id' => ['required', 'exists:accounts,id'], 'paid_at' => ['required', 'date'], 'reference' => ['nullable', 'max:150'], 'note' => ['nullable', 'max:1000']]);
        DB::transaction(function () use ($data, $service) {
            $remaining = (float) $data['amount'];
            $invoices = Invoice::where('customer_id', $data['customer_id'])->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->when($data['invoice_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('due_date')->lockForUpdate()->get();
            if ($remaining > (float) $invoices->sum('due_amount')) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the selected customer due.']);
            }
            foreach ($invoices as $invoice) {
                $applied = min($remaining, (float) $invoice->due_amount);
                $this->createPayment($invoice, $applied, $data['method'], (int) $data['account_id'], $data['paid_at'], $data['note'] ?? null, $service, $data['reference'] ?? null);
                $remaining -= $applied;
                if ($remaining <= 0) {
                    break;
                }
            }
        });

        return back()->with('success', 'Customer payment received; invoice due and ledgers updated.');
    }

    public function cancelPayment(Request $request, Payment $payment, WorkshopService $service)
    {
        if ($payment->method === 'Salary Deduction') {
            throw ValidationException::withMessages(['reason' => 'Reopen the originating salary sheet to reverse this repair recovery.']);
        }
        $data = $request->validate(['reason' => ['required', 'min:5', 'max:500']]);
        if ($payment->status !== 'Completed') {
            return back()->with('error', 'Payment is already cancelled.');
        }
        DB::transaction(fn () => $this->reversePayment($payment, $data['reason'], $service));

        return back()->with('success', 'Payment cancelled with reversal entries.');
    }

    public function dues(Request $request)
    {
        $query = Invoice::with(['customer', 'bike', 'jobCard.intake.bike'])->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))->orWhereHas('jobCard.intake.bike', fn (Builder $b) => $b->where('number', 'like', "%{$term}%"))))->when($request->overdue === 'yes', fn (Builder $q) => $q->whereDate('due_date', '<', today()))->when($request->min, fn (Builder $q, string $value) => $q->where('due_amount', '>=', $value))->when($request->max, fn (Builder $q, string $value) => $q->where('due_amount', '<=', $value));

        return view('sales.dues', ['dues' => $query->orderBy('due_date')->paginate(15)->withQueryString(), 'topCustomers' => Customer::withSum(['invoices as due_total' => fn (Builder $q) => $q->where('status', '!=', 'Cancelled')], 'due_amount')->orderByDesc('due_total')->limit(5)->get(), 'stats' => ['total' => Invoice::where('status', '!=', 'Cancelled')->sum('due_amount'), 'count' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->count(), 'overdue' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->whereDate('due_date', '<', today())->count(), 'today' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereDate('paid_at', today())->sum('amount'), 'customers' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->distinct('customer_id')->count('customer_id')]]);
    }

    public function publicLinks(Request $request)
    {
        $links = Invoice::with(['customer', 'bike', 'jobCard.intake.bike'])->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")->orWhere('phone', 'like', "%{$term}%"))))->when($request->status === 'active', fn (Builder $q) => $q->where('link_enabled', true)->where(fn (Builder $x) => $x->whereNull('link_expires_at')->orWhere('link_expires_at', '>', now())))->when($request->status === 'expired', fn (Builder $q) => $q->where(fn (Builder $x) => $x->where('link_enabled', false)->orWhere('link_expires_at', '<=', now())))->latest()->paginate(15)->withQueryString();

        return view('sales.public-links', ['links' => $links, 'stats' => ['total' => Invoice::count(), 'active' => Invoice::where('link_enabled', true)->where(fn (Builder $q) => $q->whereNull('link_expires_at')->orWhere('link_expires_at', '>', now()))->count(), 'expired' => Invoice::where(fn (Builder $q) => $q->where('link_enabled', false)->orWhere('link_expires_at', '<=', now()))->count(), 'sms' => Invoice::whereNotNull('sms_sent_at')->count(), 'opens' => DB::table('public_bill_accesses')->whereDate('opened_at', today())->count()]]);
    }

    public function regenerateLink(Invoice $invoice)
    {
        $invoice->update(['public_token' => Str::random(48), 'link_enabled' => true, 'link_expires_at' => now()->addDays(90)]);

        return back()->with('success', 'Secure public bill link regenerated.');
    }

    public function toggleLink(Invoice $invoice)
    {
        $invoice->update(['link_enabled' => ! $invoice->link_enabled]);

        return back()->with('success', $invoice->link_enabled ? 'Public bill link enabled.' : 'Public bill link disabled.');
    }

    public function sendBillSms(Invoice $invoice)
    {
        $this->queueBillSms($invoice);

        return back()->with('success', 'Bill SMS was added to the SMS queue.');
    }

    private function paymentPageData(Request $request, bool $customerMode): array
    {
        $payments = Payment::with(['invoice.customer', 'invoice.jobCard.intake.bike', 'account'])->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('invoice', fn (Builder $i) => $i->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")))))->when($request->method, fn (Builder $q, string $method) => $q->where('method', $method))->when($request->account_id, fn (Builder $q, string $id) => $q->where('account_id', $id))->latest('paid_at')->paginate(15)->withQueryString();

        return ['payments' => $payments, 'customers' => Customer::where('active', true)->withSum(['invoices as due_total' => fn (Builder $q) => $q->where('status', '!=', 'Cancelled')], 'due_amount')->orderBy('name')->get(), 'invoices' => Invoice::with('customer')->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->orderBy('due_date')->get(), 'accounts' => Account::where('active', true)->get(), 'customerMode' => $customerMode, 'stats' => ['today' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereDate('paid_at', today())->sum('amount'), 'month' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'), 'cash' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->where('method', 'Cash')->sum('amount'), 'bank' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereIn('method', ['Card', 'Bank Transfer', 'QR'])->sum('amount'), 'cheque' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->where('method', 'Cheque')->sum('amount'), 'due' => Invoice::where('status', '!=', 'Cancelled')->sum('due_amount')]];
    }

    private function createPayment(Invoice $invoice, float $amount, string $method, int $accountId, mixed $paidAt, ?string $note, WorkshopService $service, ?string $reference = null): Payment
    {
        $invoice->refresh();
        if ($amount > (float) $invoice->due_amount) {
            throw ValidationException::withMessages(['amount' => 'Payment exceeds invoice due.']);
        }
        $invoice->increment('paid_amount', $amount);
        $invoice->decrement('due_amount', $amount);
        $invoice->refresh();
        $invoice->update(['status' => (float) $invoice->due_amount <= 0 ? 'Paid' : 'Partially Paid', 'payment_method' => $method]);
        $payment = $invoice->payments()->create(['number' => 'RCPT-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'account_id' => $accountId, 'amount' => $amount, 'balance_after' => $invoice->due_amount, 'method' => $method, 'reference' => $reference, 'paid_at' => $paidAt, 'note' => $note, 'status' => 'Completed', 'created_by' => auth()->id()]);

        $accountService = app(AccountService::class);
        $accountService->receiveInvoicePayment($invoice, $payment);

        return $payment;
    }

    private function reversePayment(Payment $payment, string $reason, WorkshopService $service): void
    {
        $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
        $payment = Payment::lockForUpdate()->findOrFail($payment->id);
        if ($payment->status !== 'Completed') {
            return;
        }
        $payment->setRelation('invoice', $invoice);
        $accountService = app(AccountService::class);
        AccountTransaction::where('reference_type', $payment->getMorphClass())
            ->where('reference_id', $payment->id)->where('status', 'active')
            ->where('transaction_type', '!=', 'reversal')->get()
            ->each(fn ($entry) => $accountService->reverseTransaction($entry, $reason));
        $payment->invoice->decrement('paid_amount', (float) $payment->amount);
        $payment->invoice->increment('due_amount', (float) $payment->amount);
        $payment->invoice->refresh();
        $payment->invoice->update(['status' => (float) $payment->invoice->paid_amount > 0 ? 'Partially Paid' : 'Unpaid']);
        $payment->update(['status' => 'Cancelled', 'cancel_reason' => $reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        $accountService->reallocateInvoiceReceipts($payment->invoice);
    }

    private function queueBillSms(Invoice $invoice): void
    {
        $invoice->loadMissing('customer');
        if ($invoice->customer && $invoice->customer->phone) {
            $smsService = app(TextitSmsService::class);
            $smsService->send($invoice->customer->phone, "Fasmir Motors bill {$invoice->number}: Rs. ".number_format((float) $invoice->total, 2).'. '.route('invoices.public', $invoice->public_token), [
                'module' => 'Billing',
                'message_type' => 'Bill SMS',
                'recipient_name' => $invoice->customer->name,
                'recipient_type' => 'customer',
                'reference_no' => $invoice->number,
                'related_type' => $invoice->getMorphClass(),
                'related_id' => $invoice->id,
            ]);
        }
        $invoice->update(['sms_sent_at' => now()]);
    }
}
