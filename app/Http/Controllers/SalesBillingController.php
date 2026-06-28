<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JobCard;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Technician;
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
        $products = Product::with(['stocks.store', 'stockBatches' => fn ($q) => $q->where('status', 'active')->where('available_qty', '>', 0)])->where('active', true)->orderBy('name')->get();

        return view('sales.parts-sales', ['customers' => Customer::where('active', true)->orderBy('name')->get(), 'stores' => Store::where('active', true)->orderByDesc('is_default')->get(), 'accounts' => Account::where('active', true)->get(), 'products' => $products, 'productOptions' => $products->map(fn (Product $p) => ['id' => $p->id, 'label' => $p->name.' — '.$p->code.($p->barcode ? ' — '.$p->barcode : ''), 'sku' => $p->code, 'barcode' => $p->barcode, 'price' => (float) $p->selling_price, 'stocks' => $p->stocks->map(fn ($stock) => ['store_id' => $stock->store_id, 'quantity' => (float) $stock->quantity])->values(), 'batches' => $p->stockBatches->map(fn ($b) => ['id' => $b->id, 'store_id' => $b->store_id, 'batch_no' => $b->batch_no, 'cost' => (float) $b->cost_price, 'selling' => (float) $b->selling_price, 'qty' => (float) $b->available_qty])->values()])->values(), 'recent' => Invoice::with('customer')->where('invoice_type', 'Parts Sale')->latest()->limit(5)->get(), 'stats' => ['today' => Invoice::where('invoice_type', 'Parts Sale')->whereDate('created_at', today())->sum('total'), 'items' => DB::table('invoice_items')->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')->whereDate('invoices.created_at', today())->sum('invoice_items.quantity'), 'income' => Account::where('code', 'SPARE')->first()?->balance ?? 0, 'due' => Invoice::where('invoice_type', 'Parts Sale')->sum('due_amount'), 'low' => Product::whereRaw('(select coalesce(sum(quantity),0) from store_stocks where store_stocks.product_id=products.id)<=products.minimum_stock')->count()]]);
    }

    public function partsStore(Request $request, WorkshopService $service)
    {
        $data = $request->validate(['customer_mode' => ['required', Rule::in(['walkin', 'existing', 'new'])], 'customer_id' => ['nullable', 'exists:customers,id'], 'customer_name' => ['nullable', 'max:150'], 'customer_phone' => ['nullable', 'max:30'], 'store_id' => ['required', 'exists:stores,id'], 'discount' => ['nullable', 'numeric', 'min:0'], 'tax' => ['nullable', 'numeric', 'min:0'], 'paid_amount' => ['required', 'numeric', 'min:0'], 'payment_method' => ['required', Rule::in(['Cash', 'Card', 'Bank Transfer', 'Cheque', 'QR', 'Credit/Due'])], 'account_id' => ['nullable', 'exists:accounts,id'], 'items' => ['required', 'array', 'min:1', 'max:100'], 'items.*.product_id' => ['required', 'distinct', 'exists:products,id'], 'items.*.quantity' => ['required', 'numeric', 'gt:0'], 'items.*.unit_price' => ['required', 'numeric', 'min:0'], 'items.*.discount' => ['nullable', 'numeric', 'min:0'], 'items.*.stock_batch_id' => ['nullable', 'exists:stock_batches,id']]);
        if ((float) $data['paid_amount'] > 0 && empty($data['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Select the account receiving this payment.']);
        }
        $invoice = DB::transaction(function () use ($data, $request, $service) {
            $customer = match ($data['customer_mode']) {
                'existing' => Customer::findOrFail($data['customer_id']),
                'new' => Customer::create(['name' => $data['customer_name'] ?: 'Parts Customer', 'phone' => $data['customer_phone'] ?: 'TEMP-'.Str::upper(Str::random(8)), 'customer_type' => 'Parts Customer', 'active' => true]),
                default => Customer::firstOrCreate(['phone' => 'WALK-IN'], ['name' => 'Walk-in Customer', 'customer_type' => 'Walk-in', 'active' => true]),
            };
            $subtotal = collect($data['items'])->sum(fn (array $item) => (float) $item['quantity'] * (float) $item['unit_price'] - (float) ($item['discount'] ?? 0));
            $total = max(0, $subtotal - (float) ($data['discount'] ?? 0) + (float) ($data['tax'] ?? 0));
            $paid = (float) $data['paid_amount'];
            if ($paid > $total) {
                throw ValidationException::withMessages(['paid_amount' => 'Paid amount cannot exceed the sale total.']);
            }
            $invoice = Invoice::create(['number' => 'INV-'.now()->format('ymd').'-'.Str::upper(Str::random(6)), 'invoice_type' => 'Parts Sale', 'customer_id' => $customer->id, 'store_id' => $data['store_id'], 'subtotal' => $subtotal, 'parts_amount' => $subtotal, 'discount' => $data['discount'] ?? 0, 'tax' => $data['tax'] ?? 0, 'total' => $total, 'paid_amount' => 0, 'due_amount' => $total, 'status' => 'Unpaid', 'payment_method' => $data['payment_method'], 'due_date' => $paid < $total ? today()->addDays(30) : null, 'public_token' => Str::random(48), 'link_expires_at' => now()->addDays(90), 'created_by' => auth()->id()]);
            foreach ($data['items'] as $item) {
                $line = (float) $item['quantity'] * (float) $item['unit_price'] - (float) ($item['discount'] ?? 0);

                $batchId = $item['stock_batch_id'] ?? null;
                $costPrice = 0;
                if (! $batchId) {
                    $batch = StockBatch::where('product_id', $item['product_id'])->where('store_id', $data['store_id'])->where('status', 'active')->where('available_qty', '>', 0)->orderBy('purchase_date', 'asc')->first();
                    if ($batch) {
                        $batchId = $batch->id;
                    }
                } else {
                    $batch = StockBatch::find($batchId);
                }

                if ($batch) {
                    $costPrice = (float) $batch->cost_price;
                } else {
                    $product = Product::find($item['product_id']);
                    $costPrice = (float) $product->cost_price;
                }

                $profit = $line - ((float) $item['quantity'] * $costPrice);

                $invoiceItem = $invoice->items()->create(['product_id' => $item['product_id'], 'store_id' => $data['store_id'], 'stock_batch_id' => $batchId, 'quantity' => $item['quantity'], 'cost_price' => $costPrice, 'selling_price' => $item['unit_price'], 'unit_price' => $item['unit_price'], 'discount' => $item['discount'] ?? 0, 'total' => $line, 'profit' => $profit]);
                $service->adjustStock((int) $data['store_id'], (int) $item['product_id'], -(float) $item['quantity'], 'parts_sale', $invoice, "Parts sale {$invoice->number}", $batchId, $costPrice, $item['unit_price']);
            }
            if ($paid > 0) {
                $this->createPayment($invoice, $paid, $data['payment_method'], (int) $data['account_id'], now(), 'Initial parts sale payment', $service);
            }
            if ($request->input('action') === 'sms') {
                $this->queueBillSms($invoice);
            }

            return $invoice;
        });

        return redirect()->route('sales.invoices.show', $invoice)->with('success', 'Parts sale saved; stock, due, ledger and public bill were updated.');
    }

    public function invoices(Request $request)
    {
        $invoices = Invoice::with(['customer', 'bike', 'jobCard.intake.bike', 'payments'])->when($request->q, fn (Builder $q, string $term) => $q->where(fn (Builder $x) => $x->where('number', 'like', "%{$term}%")->orWhereHas('customer', fn (Builder $c) => $c->where('phone', 'like', "%{$term}%"))->orWhereHas('jobCard', fn (Builder $j) => $j->where('number', 'like', "%{$term}%")->orWhereHas('intake.bike', fn (Builder $b) => $b->where('number', 'like', "%{$term}%")))))
            ->when($request->invoice_type, fn (Builder $q, string $type) => $q->where('invoice_type', $type))->when($request->status, fn (Builder $q, string $status) => $q->where('status', $status))->when($request->method, fn (Builder $q, string $method) => $q->where('payment_method', $method))->when($request->from, fn (Builder $q, string $date) => $q->whereDate('created_at', '>=', $date))->when($request->to, fn (Builder $q, string $date) => $q->whereDate('created_at', '<=', $date))->latest()->paginate(15)->withQueryString();

        return view('sales.invoices', ['invoices' => $invoices, 'stats' => ['total' => Invoice::count(), 'today' => Invoice::whereDate('created_at', today())->count(), 'paid' => Invoice::where('status', 'Paid')->count(), 'due' => Invoice::where('due_amount', '>', 0)->count(), 'cancelled' => Invoice::where('status', 'Cancelled')->count()]]);
    }

    public function invoiceShow(Invoice $invoice)
    {
        return view('sales.invoice-show', ['invoice' => $invoice->load(['customer', 'bike', 'store', 'jobCard.intake.bike.customer', 'jobCard.parts.product', 'jobCard.services', 'items.product', 'payments.account'])]);
    }

    public function cancelInvoice(Request $request, Invoice $invoice, WorkshopService $service)
    {
        $data = $request->validate(['reason' => ['required', 'min:5', 'max:500']]);
        if ($invoice->status === 'Cancelled') {
            return back()->with('error', 'Invoice is already cancelled.');
        }
        DB::transaction(function () use ($invoice, $data, $service) {
            $invoice->load(['items', 'payments']);
            foreach ($invoice->payments->where('status', 'Completed') as $payment) {
                $this->reversePayment($payment, $data['reason'], $service);
            }
            if ($invoice->invoice_type === 'Parts Sale') {
                foreach ($invoice->items as $item) {
                    $service->adjustStock($item->store_id, $item->product_id, (float) $item->quantity, 'invoice_cancel', $invoice, $data['reason']);
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

        return view('sales.dues', ['dues' => $query->orderBy('due_date')->paginate(15)->withQueryString(), 'topCustomers' => Customer::withSum(['invoices as due_total' => fn (Builder $q) => $q->where('status', '!=', 'Cancelled')], 'due_amount')->orderByDesc('due_total')->limit(5)->get(), 'stats' => ['total' => Invoice::where('status', '!=', 'Cancelled')->sum('due_amount'), 'count' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->count(), 'overdue' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->whereDate('due_date', '<', today())->count(), 'today' => Payment::where('status', 'Completed')->whereDate('paid_at', today())->sum('amount'), 'customers' => Invoice::where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->distinct('customer_id')->count('customer_id')]]);
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

        return ['payments' => $payments, 'customers' => Customer::where('active', true)->withSum(['invoices as due_total' => fn (Builder $q) => $q->where('status', '!=', 'Cancelled')], 'due_amount')->orderBy('name')->get(), 'invoices' => Invoice::with('customer')->where('status', '!=', 'Cancelled')->where('due_amount', '>', 0)->orderBy('due_date')->get(), 'accounts' => Account::where('active', true)->get(), 'customerMode' => $customerMode, 'stats' => ['today' => Payment::where('status', 'Completed')->whereDate('paid_at', today())->sum('amount'), 'month' => Payment::where('status', 'Completed')->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])->sum('amount'), 'cash' => Payment::where('status', 'Completed')->where('method', 'Cash')->sum('amount'), 'bank' => Payment::where('status', 'Completed')->whereIn('method', ['Card', 'Bank Transfer', 'QR'])->sum('amount'), 'cheque' => Payment::where('status', 'Completed')->where('method', 'Cheque')->sum('amount'), 'due' => Invoice::where('status', '!=', 'Cancelled')->sum('due_amount')]];
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
        $this->postIncomeLedgers($invoice, $payment, $amount, $method, $service, false);

        return $payment;
    }

    private function postIncomeLedgers(Invoice $invoice, Payment $payment, float $amount, string $method, WorkshopService $service, bool $reversal): void
    {
        $partsShare = (float) $invoice->total > 0 ? min($amount, $amount * ((float) $invoice->parts_amount / (float) $invoice->total)) : 0;
        $mainShare = max(0, $amount - $partsShare);
        $type = $reversal ? 'refund' : 'income';
        if ($partsShare > 0) {
            $service->ledger(Account::where('code', 'SPARE')->firstOrFail(), $type, $partsShare, ($reversal ? 'Reversal ' : '')."parts income {$invoice->number}", $payment, 'spare_parts');
        }
        if ($mainShare > 0) {
            $service->ledger(Account::where('code', 'MAIN')->firstOrFail(), $type, $mainShare, ($reversal ? 'Reversal ' : '')."service income {$invoice->number}", $payment, 'service');
        }
        if (in_array($method, ['Card', 'Bank Transfer', 'Cheque', 'QR'], true)) {
            $service->ledger(Account::where('code', 'BANK')->firstOrFail(), $reversal ? 'refund' : 'payment', $amount, ($reversal ? 'Reversal ' : '')."bank receipt {$invoice->number}", $payment, 'payment_location');
        }
    }

    private function reversePayment(Payment $payment, string $reason, WorkshopService $service): void
    {
        $payment->load('invoice');
        $this->postIncomeLedgers($payment->invoice, $payment, (float) $payment->amount, $payment->method, $service, true);
        $payment->invoice->decrement('paid_amount', (float) $payment->amount);
        $payment->invoice->increment('due_amount', (float) $payment->amount);
        $payment->invoice->refresh();
        $payment->invoice->update(['status' => (float) $payment->invoice->paid_amount > 0 ? 'Partially Paid' : 'Unpaid']);
        $payment->update(['status' => 'Cancelled', 'cancel_reason' => $reason, 'cancelled_by' => auth()->id(), 'cancelled_at' => now()]);
    }

    private function queueBillSms(Invoice $invoice): void
    {
        $invoice->loadMissing('customer');
        DB::table('sms_logs')->insert(['phone' => $invoice->customer->phone, 'message' => "Fasmir Motors bill {$invoice->number}: Rs. ".number_format((float) $invoice->total, 2).'. '.route('invoices.public', $invoice->public_token), 'status' => 'Queued', 'reference_type' => $invoice->getMorphClass(), 'reference_id' => $invoice->id, 'created_at' => now(), 'updated_at' => now()]);
        $invoice->update(['sms_sent_at' => now()]);
    }
}
