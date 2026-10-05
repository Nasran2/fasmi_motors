<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Technician;
use App\Services\AccountService;
use App\Services\SettlementService;
use App\Services\Sms\WorkshopSmsService;
use App\Services\WorkshopService;
use App\Support\InvoiceDocument;
use App\Support\InvoiceNumber;
use Barryvdh\DomPDF\Facade\Pdf;
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
        $settlement = app(SettlementService::class);
        $rows = $settlement->rows($request->all());
        $request->merge(['paid_amount' => $settlement->total($rows), 'payment_method' => count($rows) > 1 ? 'Cash' : ($rows[0]['method'] ?? 'Credit/Due')]);
        $data = $request->validate(['service_total' => ['nullable', 'numeric', 'min:0'], 'labor_total' => ['nullable', 'numeric', 'min:0'], 'washing_total' => ['nullable', 'numeric', 'min:0'], 'other_total' => ['nullable', 'numeric', 'min:0'], 'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'paid_amount' => ['required', 'numeric', 'min:0'], 'payment_method' => ['required', Rule::in(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR', 'Credit/Due'])], 'account_id' => ['nullable', 'exists:accounts,id']]);
        $invoice = DB::transaction(function () use ($job, $data, $service, $rows) {
            $job = JobCard::lockForUpdate()->findOrFail($job->id);
            $job->update(['service_total' => $data['service_total'] ?? 0, 'labor_total' => $data['labor_total'] ?? 0, 'washing_total' => $data['washing_total'] ?? 0, 'other_total' => $data['other_total'] ?? 0, 'discount' => $data['discount'] ?? 0, 'tax' => $data['tax'] ?? 0]);

            return $service->invoice($job->fresh(), (float) $data['paid_amount'], $data['payment_method'], ($data['account_id'] ?? null) ? (int) $data['account_id'] : null, $rows);
        });

        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Job card invoice generated with account split and secure public link.');
    }

    public function partsSales(Request $request)
    {
        $shopMode = session('shop_mode', 'workshop');
        Store::firstOrCreate(
            ['name' => $shopMode === 'helmet' ? 'Helmet Store' : 'Main Store'],
            ['is_default' => true, 'active' => true],
        );
        Account::ensureSystemAccounts($shopMode);

        $categories = ProductCategory::query()->orderBy('name')->get(['id', 'name']);
        $products = Product::query()
            ->select(['id', 'name', 'code', 'barcode', 'selling_price', 'cost_price', 'unit', 'category_id', 'pos_pinned', 'pos_pin_order'])
            ->with([
                'stocks:id,product_id,store_id,quantity',
                'stockBatches' => fn ($query) => $query
                    ->where('status', 'active')
                    ->where('available_qty', '>', 0)
                    ->select(['id', 'product_id', 'store_id', 'batch_no', 'cost_price', 'selling_price', 'available_qty', 'purchase_date']),
            ])
            ->where('active', true)
            ->orderByDesc('pos_pinned')
            ->orderBy('pos_pin_order')
            ->orderBy('name')
            ->get();

        $productOptions = $products->map(fn (Product $product) => [
            'id' => $product->id,
            'name' => $product->name,
            'sku' => $product->code,
            'barcode' => $product->barcode,
            'price' => (float) $product->selling_price,
            'cost_price' => (float) $product->cost_price,
            'unit' => $product->unit,
            'category_id' => $product->category_id,
            'posPinned' => $product->pos_pinned,
            'posPinOrder' => $product->pos_pin_order,
            'stocks' => $product->stocks->map(fn ($stock) => [
                'store_id' => $stock->store_id,
                'quantity' => (float) $stock->quantity,
            ])->values(),
            'batches' => $product->stockBatches->map(fn ($batch) => [
                'id' => $batch->id,
                'store_id' => $batch->store_id,
                'batch_no' => $batch->batch_no,
                'cost' => (float) $batch->cost_price,
                'selling' => (float) $batch->selling_price,
                'qty' => (float) $batch->available_qty,
                'date' => $batch->purchase_date?->format('d/m/Y') ?? 'N/A',
            ])->values(),
        ])->values();

        $importQuotation = null;
        if ($request->filled('quotation')) {
            $quotation = Invoice::query()->where('invoice_type', 'Quotation')
                ->with(['items.product', 'charges', 'customer', 'bike', 'store'])
                ->findOrFail($request->integer('quotation'));
            if ($quotation->status !== 'Draft') {
                return redirect()->route('sales.quotations')->with('error', 'Only draft quotations can be opened in POS.');
            }
            if ($quotation->is_accident_quotation) {
                return redirect()->route('sales.quotations')->with('error', 'Accident bike quotations cannot be opened in POS.');
            }
            if (! $quotation->store?->active || ($quotation->customer && ! $quotation->customer->active) || $quotation->items->contains(fn ($item) => ! $item->product?->active)) {
                return redirect()->route('sales.quotations')->with('error', 'A quotation product, customer, or store is no longer active. Update it before checkout.');
            }
            $importQuotation = [
                'id' => $quotation->id,
                'number' => $quotation->number,
                'customer_id' => $quotation->customer_id,
                'bike' => $quotation->bike?->number,
                'store_id' => $quotation->store_id,
                'internal_note' => $quotation->internal_note,
                'customer_note' => $quotation->customer_note,
                'discount' => (float) $quotation->discount,
                'tax' => (float) $quotation->tax,
                'items' => $quotation->items->map(fn ($item) => [
                    'product_id' => $item->product_id,
                    'stock_batch_id' => $item->stock_batch_id,
                    'description' => $item->description,
                    'quantity' => (float) $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'discount' => (float) $item->discount,
                ])->values(),
                'charges' => $quotation->charges->map(fn ($charge) => [
                    'type' => $charge->type,
                    'description' => $charge->description,
                    'amount' => (float) $charge->amount,
                ])->values(),
            ];
        }

        return view('sales.parts-sales', [
            'customers' => Customer::where('active', true)->orderBy('name')->get(['id', 'name', 'phone']),
            'stores' => Store::where('active', true)->orderByDesc('is_default')->get(['id', 'name']),
            'accounts' => Account::where('active', true)->get(['id', 'name', 'code', 'type']),
            'categories' => $categories,
            'products' => $products,
            'productOptions' => $productOptions,
            'importQuotation' => $importQuotation,
        ]);
    }

    public function partsStore(Request $request, WorkshopService $service)
    {
        $settlement = app(SettlementService::class);
        $rows = $settlement->rows($request->all(), false);
        $request->merge(['paid_amount' => $settlement->total($rows)]);
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
            'source_quotation_id' => 'nullable|integer|exists:invoices,id',
            'internal_note' => 'nullable|string|max:2000',
            'customer_note' => 'nullable|string|max:2000',
            'items' => 'nullable|array|max:100',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.stock_batch_id' => 'nullable|exists:stock_batches,id',
            'items.*.description' => 'nullable|string|max:255',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.discount' => 'nullable|numeric|min:0',
            'charges' => 'nullable|array|max:100',
            'charges.*.type' => ['required', Rule::in(['service', 'labor', 'washing', 'other'])],
            'charges.*.description' => 'required|string|max:255',
            'charges.*.amount' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'action' => 'nullable|string',
        ]);

        $invType = $v['invoice_type'] ?? 'Parts Sale';
        if (empty($v['items']) && empty($v['charges'])) {
            throw ValidationException::withMessages(['items' => 'Add at least one product or charge.']);
        }
        if (! empty($v['source_quotation_id']) && $invType !== 'Parts Sale') {
            throw ValidationException::withMessages(['source_quotation_id' => 'Open this quotation as a sale in POS.']);
        }
        if (($v['customer_mode'] ?? null) === 'new' && blank($v['customer_name'] ?? null)) {
            throw ValidationException::withMessages(['customer_name' => 'Enter a name for the new customer.']);
        }
        if (($v['customer_mode'] ?? null) === 'new' && blank($v['customer_phone'] ?? null)) {
            throw ValidationException::withMessages(['customer_phone' => 'Enter a phone number for the new customer.']);
        }
        $invoice = DB::transaction(function () use ($v, $invType, $service, $request, $rows, $settlement) {
            $sourceQuotation = null;
            if (! empty($v['source_quotation_id'])) {
                $sourceQuotation = Invoice::query()->where('invoice_type', 'Quotation')
                    ->lockForUpdate()->find($v['source_quotation_id']);
                if (! $sourceQuotation || $sourceQuotation->status !== 'Draft' || $sourceQuotation->is_accident_quotation) {
                    throw ValidationException::withMessages(['source_quotation_id' => 'This quotation is no longer available for checkout.']);
                }
                if ((int) $sourceQuotation->store_id !== (int) $v['store_id']) {
                    throw ValidationException::withMessages(['store_id' => 'Use the quotation store for checkout.']);
                }
            }
            $items = collect($v['items'] ?? []);
            $charges = collect($v['charges'] ?? []);
            $products = Product::whereIn('id', $items->pluck('product_id')->unique())->lockForUpdate()->get()->keyBy('id');

            if ($products->count() !== $items->pluck('product_id')->unique()->count()) {
                throw ValidationException::withMessages(['items' => 'One or more products are unavailable in the active shop.']);
            }

            Store::lockForUpdate()->findOrFail($v['store_id']);
            $usesDiscount = (float) ($v['discount'] ?? 0) > 0 || $items->contains(fn ($item) => (float) ($item['discount'] ?? 0) > 0);
            $batchPrices = StockBatch::whereIn('id', $items->pluck('stock_batch_id')->filter()->unique())->get()->keyBy('id');
            $usesLowerPrice = $items->contains(function ($item) use ($products, $batchPrices) {
                $batch = $batchPrices->get($item['stock_batch_id'] ?? null);
                $listedPrice = $batch && (int) $batch->product_id === (int) $item['product_id']
                    ? (float) $batch->selling_price
                    : (float) $products[$item['product_id']]->selling_price;

                return (float) $item['unit_price'] < $listedPrice;
            });
            if (($usesDiscount || $usesLowerPrice) && ! $request->user()->canAccess('sales.apply_discount')) {
                abort(403, 'You do not have permission to apply discounts or reduce selling prices.');
            }

            foreach ($items as $index => $item) {
                if ((float) ($item['discount'] ?? 0) > (float) $item['quantity'] * (float) $item['unit_price']) {
                    throw ValidationException::withMessages(["items.{$index}.discount" => 'Item discount cannot exceed the line amount.']);
                }
            }

            $partsSubtotal = (float) $items->sum(fn ($item) => ((float) $item['quantity'] * (float) $item['unit_price']) - (float) ($item['discount'] ?? 0));
            $chargeTotals = $charges->groupBy('type')->map(fn ($rows) => (float) $rows->sum('amount'));
            $subtotal = round($partsSubtotal + (float) $charges->sum('amount'), 2);
            $total = $subtotal - (float) ($v['discount'] ?? 0) + (float) ($v['tax'] ?? 0);
            if ($total < 0) {
                throw ValidationException::withMessages(['discount' => 'Discount cannot exceed the sale amount.']);
            }

            $paid = $invType === 'Quotation' ? 0.0 : (float) ($v['paid_amount'] ?? 0);
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the invoice total.']);
            }
            $customerId = $v['customer_id'] ?? null;
            if (($v['customer_mode'] ?? '') === 'new' && ! empty($v['customer_name'])) {
                $customerId = Customer::create(['name' => $v['customer_name'], 'phone' => $v['customer_phone'] ?? null, 'active' => true])->id;
            }

            $invoice = Invoice::create([
                'number' => InvoiceNumber::next($invType),
                'invoice_type' => $invType,
                'status' => $invType === 'Quotation' ? 'Draft' : ($paid >= $total ? 'Paid' : ($paid > 0 ? 'Partially Paid' : 'Unpaid')),
                'customer_id' => $customerId,
                'bike_id' => $sourceQuotation && (int) $sourceQuotation->customer_id === (int) $customerId ? $sourceQuotation->bike_id : null,
                'store_id' => $v['store_id'],
                'source_quotation_id' => $sourceQuotation?->id,
                'subtotal' => $subtotal,
                'discount' => $v['discount'] ?? 0,
                'tax' => $v['tax'] ?? 0,
                'parts_amount' => $partsSubtotal,
                'service_amount' => $chargeTotals->get('service', 0),
                'labor_amount' => $chargeTotals->get('labor', 0),
                'washing_amount' => $chargeTotals->get('washing', 0),
                'other_amount' => $chargeTotals->get('other', 0),
                'payment_method' => $invType === 'Quotation' ? null : (count($rows) > 1 ? 'Mixed' : ($rows[0]['method'] ?? 'Credit/Due')),
                'internal_note' => $v['internal_note'] ?? null,
                'customer_note' => $v['customer_note'] ?? null,
                'total' => $total,
                'paid_amount' => 0,
                'due_amount' => $invType === 'Quotation' ? 0 : $total,
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
                    'description' => trim((string) ($item['description'] ?? '')) ?: null,
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

            foreach ($charges as $index => $charge) {
                $invoice->charges()->create([
                    'type' => $charge['type'],
                    'description' => trim($charge['description']),
                    'amount' => $charge['amount'],
                    'sort_order' => $index + 1,
                ]);
            }

            if ($invType !== 'Quotation') {
                foreach ($rows as $row) {
                    $settlement->receive($invoice, $row);
                }
                $invoice->refresh();
                $settlement->saveStatus($invoice);
            }

            $sourceQuotation?->update(['status' => 'Converted']);

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

    public function quotations(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(['accident', 'normal'])],
            'status' => ['nullable', Rule::in(['Draft', 'Converted', 'Cancelled'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $quotations = Invoice::query()
            ->where('invoice_type', 'Quotation')
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('is_accident_quotation', $type === 'accident'))
            ->with(['customer:id,name,phone', 'bike:id,number,model', 'store:id,name', 'convertedInvoice:id,source_quotation_id,number'])
            ->withCount(['items', 'charges'])
            ->when($filters['q'] ?? null, function (Builder $query, string $term) {
                $query->where(function (Builder $query) use ($term) {
                    $query->where('number', 'like', "%{$term}%")
                        ->orWhereHas('customer', fn (Builder $customer) => $customer
                            ->where('name', 'like', "%{$term}%")
                            ->orWhere('phone', 'like', "%{$term}%"))
                        ->orWhereHas('bike', fn (Builder $bike) => $bike->where('number', 'like', "%{$term}%"));
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return view('sales.quotations', compact('quotations'));
    }

    public function invoiceShow(Invoice $invoice)
    {
        return view('sales.invoice-show', ['invoice' => $invoice->load(['customer', 'bike', 'store', 'jobCard.intake.bike.customer', 'jobCard.technician', 'jobCard.parts.product', 'jobCard.services', 'items.product', 'charges', 'payments.account', 'payments.cheque.forwardedSupplier'])]);
    }

    public function invoicePdf(Invoice $invoice)
    {
        abort_unless(auth()->user()->canAccess('sales.view_invoices') || (auth()->user()->canAccess('sales.parts_sales') && (int) $invoice->created_by === (int) auth()->id()), 403);
        $filename = ($invoice->invoice_type === 'Quotation' ? 'Quotation-' : 'Bill-')
            .preg_replace('/[^A-Za-z0-9_-]/', '-', $invoice->number).'.pdf';

        return Pdf::loadView('invoices.public', InvoiceDocument::data($invoice, pdfMode: true))
            ->setPaper('a4')->download($filename);
    }

    public function cancelInvoice(Request $request, Invoice $invoice, WorkshopService $service)
    {
        $data = $request->validate(['reason' => ['required', 'min:5', 'max:500']]);
        if ($invoice->invoice_type === 'Quotation' && $invoice->status === 'Converted') {
            return back()->with('error', 'A converted quotation cannot be cancelled. Cancel the resulting invoice if needed.');
        }
        if ($invoice->status === 'Cancelled') {
            return back()->with('error', $invoice->invoice_type === 'Quotation' ? 'Quotation is already cancelled.' : 'Invoice is already cancelled.');
        }
        DB::transaction(function () use ($invoice, $data, $service) {
            $invoice = Invoice::lockForUpdate()->findOrFail($invoice->id);
            if ($invoice->status === 'Cancelled') {
                return;
            }
            if ($invoice->invoice_type === 'Quotation' && $invoice->status === 'Converted') {
                throw ValidationException::withMessages(['reason' => 'This quotation has already been converted to an invoice.']);
            }
            if ($invoice->payments()->where('status', 'Completed')->where('method', 'Salary Deduction')->exists()) {
                throw ValidationException::withMessages(['reason' => 'Reopen the staff salary recovery before cancelling this invoice.']);
            }
            $invoice->load(['items', 'payments']);
            $linkedCheques = Cheque::whereHas('payments', fn ($q) => $q->where('invoice_id', $invoice->id))->whereIn('status', ['Pending', 'Deposited', 'Cleared'])->get();
            if ($linkedCheques->contains(fn ($c) => (bool) $c->forwarded_supplier_id)) {
                throw ValidationException::withMessages(['reason' => 'Resolve the forwarded customer cheque before cancelling this document.']);
            }
            foreach ($linkedCheques->whereIn('status', ['Pending', 'Deposited']) as $cheque) {
                app(SettlementService::class)->resolve($cheque, 'Cancelled', null, $data['reason']);
            }
            $invoice->refresh()->load('payments');
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

        return back()->with('success', $invoice->invoice_type === 'Quotation' ? 'Quotation cancelled.' : 'Invoice cancelled with stock and ledger reversals.');
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
        $data = $request->validate(['customer_id' => 'required|exists:customers,id', 'invoice_id' => 'nullable|exists:invoices,id', 'paid_at' => 'required|date']);
        $settlement = app(SettlementService::class);
        $rows = $settlement->rows($request->all());
        if (! $rows) {
            throw ValidationException::withMessages(['payments' => 'Enter a payment amount.']);
        }
        DB::transaction(function () use ($data, $rows, $settlement) {
            $invoices = Invoice::where('customer_id', $data['customer_id'])->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->when($data['invoice_id'] ?? null, fn ($q, $id) => $q->whereKey($id))->orderBy('due_date')->lockForUpdate()->get();
            if ($settlement->total($rows) > (float) $invoices->sum('due_amount')) {
                throw ValidationException::withMessages(['amount' => 'Payment cannot exceed the selected customer due.']);
            }
            foreach ($rows as $row) {
                $remaining = (float) $row['amount'];
                $first = $invoices->first(fn ($i) => (float) $i->fresh()->due_amount > 0);
                $cheque = $settlement->prepareCheque($first, $row);
                foreach ($invoices as $invoice) {
                    $invoice->refresh();
                    $applied = min($remaining, (float) $invoice->due_amount);
                    if ($applied > 0) {
                        $settlement->receive($invoice, array_replace($row, ['amount' => $applied]), $data['paid_at'], $cheque);
                    }
                    $remaining = round($remaining - $applied, 2);
                    if ($remaining <= 0) {
                        break;
                    }
                }
            }
        });

        return back()->with('success', 'Customer payments saved. Cheques remain on hold until passed.');
    }

    public function cancelPayment(Request $request, Payment $payment, WorkshopService $service)
    {
        if ($payment->cheque_id) {
            throw ValidationException::withMessages(['payment' => 'Return or cancel this cheque in the cheque register to update all linked balances.']);
        }
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
        $result = app(WorkshopSmsService::class)->send('billed', $invoice, true);

        return back()->with($result['success'] ? 'success' : 'error', $result['success'] ? 'Bill SMS accepted by the provider.' : $result['error']);
    }

    private function paymentPageData(Request $request, bool $customerMode): array
    {
        $payments = Payment::with(['invoice.customer', 'invoice.jobCard.intake.bike', 'account', 'cheque.forwardedSupplier'])->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('invoice', fn (Builder $i) => $i->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('name', 'like', "%{$term}%")))))->when($request->method, fn (Builder $q, string $method) => $q->where('method', $method))->when($request->account_id, fn (Builder $q, string $id) => $q->where('account_id', $id))->latest('paid_at')->paginate(15)->withQueryString();

        return ['payments' => $payments, 'customers' => Customer::where('active', true)->withSum(['invoices as due_total' => fn (Builder $q) => $q->where('status', '!=', 'Cancelled')], 'due_amount')->orderBy('name')->get(), 'invoices' => Invoice::with('customer')->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->orderBy('due_date')->get(), 'accounts' => Account::where('active', true)->get(), 'customerMode' => $customerMode, 'stats' => ['today' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereDate('paid_at', today())->sum('amount'), 'month' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'), 'cash' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->where('method', 'Cash')->sum('amount'), 'bank' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->whereIn('method', ['Card', 'Bank Transfer', 'QR'])->sum('amount'), 'cheque' => Payment::where('method', '!=', 'Salary Deduction')->where('status', 'Completed')->where('method', 'Cheque')->sum('amount'), 'due' => Invoice::where('status', '!=', 'Cancelled')->sum('due_amount')]];
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
        app(SettlementService::class)->saveStatus($payment->invoice);
        $payment->update(['status' => 'Cancelled', 'cancel_reason' => $reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
        $accountService->reallocateInvoiceReceipts($payment->invoice);
    }
}
